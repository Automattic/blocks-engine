<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeJsonProjection;

// Neutral two-stylesheet fixture: the asset inventory is not load order.
$assets = array();
foreach (array('winner' => 1, 'early' => 0) as $name => $order) {
    $assets[] = array(
        'kind' => 'css',
        'source_path' => $name . '.css',
        'content' => '@font-face{font-family:Neutral;font-style:normal;font-weight:500;src:url(' . $name . '.woff2)}body{font-family:Neutral}',
        'stylesheet_instances' => array(array('source_path' => 'index.html', 'order' => $order, 'media' => '', 'type' => '')),
    );
    $assets[] = array('kind' => 'font', 'source_path' => $name . '.woff2', 'target_path' => 'assets/' . $name . '.woff2', 'mime_type' => 'font/woff2');
}
$projection = (new ThemeJsonProjection())->project($assets, array('index.html'));
$projectedFaces = $projection['theme']['settings']['typography']['fontFamilies'][0]['fontFace'] ?? array();
if (array('file:./assets/early.woff2', 'file:./assets/winner.woff2') !== array_column($projectedFaces, 'src')) {
    fwrite(STDERR, 'FAIL: two-stylesheet fixture must preserve source cascade order: ' . json_encode($projectedFaces) . PHP_EOL);
    exit(1);
}

// A repeated stylesheet is a later cascade occurrence, not just a resource.
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="later.css"><link rel="stylesheet" href="early.css"><link rel="stylesheet" href="later.css"></head><body><main><p>Neutral font cascade</p></main></body></html>',
        'later.css' => '@font-face{font-family:Neutral;font-style:normal;font-weight:500;src:url(winner.woff2)}body{font-family:Neutral;font-weight:500}',
        'early.css' => '@font-face{font-family:Neutral;font-style:normal;font-weight:500;src:url(early.woff2)}',
        'early.woff2' => 'early font payload',
        'winner.woff2' => 'winning font payload',
    ),
))->toArray();
$plan = (new WordPressSitePlan())->fromCompilerResult($result);
$theme = array();
foreach ($plan['writes'] as $write) {
    if ('theme.json' === $write['target_path']) $theme = $write['payload']['data'];
}
if (is_string($theme)) $theme = json_decode($theme, true, 512, JSON_THROW_ON_ERROR);
$faces = array();
foreach ($theme['settings']['typography']['fontFamilies'] ?? array() as $family) {
    foreach ($family['fontFace'] ?? array() as $face) {
        if ('Neutral' === $face['family']) $faces[] = $face['src'];
    }
}
$winner = '';
foreach ($plan['assets'] as $asset) {
    if ('winner.woff2' === $asset['source_path']) $winner = 'file:./' . $asset['target_path'];
}
if ('' === $winner || array() === $faces || $winner !== end($faces)) {
    fwrite(STDERR, 'FAIL: later stylesheet font must win in theme.json: ' . json_encode($faces) . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "Theme.json font-face source order passed\n");

<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$cases = array(
    'unauthored medium' => array('body{font-family:system-ui}', null, '16px'),
    'body size' => array('body{font-size:18px}', 'var:preset|font-size|medium', '18px'),
    'html size' => array('html{font-size:18px}', 'var:preset|font-size|medium', '1rem'),
    'root size' => array(':root{font-size:18px}', 'var:preset|font-size|medium', '18px'),
    'font shorthand' => array('body{font:18px system-ui}', 'var:preset|font-size|medium', '18px'),
    'descendant size only' => array('body{font-family:system-ui}p{font-size:18px}', null, '18px'),
);
foreach ($cases as $name => [$css, $expected, $presetSize]) {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
        'index.html' => '<!doctype html><html><head><title>Typography</title><style id="global-styles-inline-css">' . $css . '</style></head><body><main><p>Body text with an inline <code>code</code> run.</p></main></body></html>',
    )))->toArray();
    $plan = (new WordPressSitePlan())->fromCompilerResult($result);
    $theme = array();
    foreach ($plan['writes'] as $write) {
        if ('theme.json' === $write['target_path']) $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
    }
    $actual = $theme['styles']['typography']['fontSize'] ?? null;
    $presets = array_column($theme['settings']['typography']['fontSizes'] ?? array(), 'size', 'slug');
    if ($expected !== $actual || $presetSize !== ($presets['medium'] ?? null)) {
        fwrite(STDERR, "FAIL: {$name}: " . json_encode(array($actual, $presets)) . "\n");
        exit(1);
    }
}
// #3920: class-sized notes outweigh the inheriting lead, but do not own
// the root's typography (the issue's reduced source/import table).
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><style>body{font-family:Georgia,serif;font-size:16px;margin:0} .notes p{font-size:.95rem;line-height:1.5} .lead{color:#123456}</style></head><body>'
        . '<main><h1>Root size</h1><p class="lead">Inherited body text.</p><div class="notes">'
        . str_repeat('<p>Lorem ipsum …</p>', 4) . '</div></main></body></html>',
)))->toArray();
$plan = (new WordPressSitePlan())->fromCompilerResult($result);
$theme = array();
foreach ($plan['writes'] as $write) {
    if ('theme.json' === $write['target_path']) $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
}
$typography = $theme['styles']['typography'] ?? array();
$size = $typography['fontSize'] ?? '';
$presets = array_column($theme['settings']['typography']['fontSizes'] ?? array(), 'size', 'slug');
if (str_starts_with($size, 'var:preset|font-size|')) $size = $presets[substr($size, strlen('var:preset|font-size|'))] ?? '';
if ('16px' !== $size || 'normal' !== ($typography['lineHeight'] ?? null)) {
    fwrite(STDERR, 'FAIL: inheriting lead keeps root size and leading, not the notes tuple: ' . json_encode(array($size, $typography)) . "\n");
    exit(1);
}
$css = implode('', array_column(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === $asset['kind']), 'content'));
if (!str_contains($css, ',.95rem)') || !str_contains($css, 'line-height:1.5')) {
    fwrite(STDERR, "FAIL: notes retain their own size and leading\n");
    exit(1);
}
fwrite(STDOUT, "Root font-size authorship passed\n");

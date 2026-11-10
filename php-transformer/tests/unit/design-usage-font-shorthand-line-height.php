<?php
declare(strict_types=1);

// Issue #5415: pending font substitutions must contribute their leading to
// the public site plan, without overriding a cascade-winning longhand.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$cases = array(
    'variable family' => array('body{margin:0;font:16px/1.65 var(--sans)}', '1.65'),
    'literal family' => array('body{margin:0;font:16px/1.65 Arial,sans-serif}', '1.65'),
    'following longhand' => array('body{font:16px/1.65 var(--sans);line-height:1.8}', '1.8'),
    'following longhand rule' => array('body{font:16px/1.65 var(--sans)}body{line-height:1.8}', '1.8'),
    'later family does not mask leading' => array('body{font:16px/1.65 var(--sans);font-family:Georgia,serif}', '1.65'),
    'preceding longhand' => array('body{line-height:1.8;font:16px/1.65 var(--sans)}', '1.65'),
    'shorthand resets omitted leading' => array('body{line-height:1.8;font:16px var(--sans)}', 'normal'),
    'heading more specific longhand' => array('body{font:16px/1.65 var(--sans)}main h1{line-height:1.2}h1{font:500 40px/1.07 var(--serif)}', '1.65', '1.2'),
    'heading more specific shorthand' => array('body{font:16px/1.65 var(--sans)}main h1{font:500 40px/1.07 var(--serif)}h1{line-height:1.2}', '1.65', '1.07'),
    'important longhand' => array('body{line-height:1.8!important;font:16px/1.65 var(--sans)}', '1.8'),
    'heading variable family' => array('body{font:16px/1.65 var(--sans)}h1{font:500 40px/1.07 var(--serif)}', '1.65', '1.07'),
    'heading literal family' => array('body{font:16px/1.65 Arial,sans-serif}h1{font:500 40px/1.07 Georgia,serif}', '1.65', '1.07'),
    'heading following longhand' => array('body{font:16px/1.65 var(--sans)}h1{font:500 40px/1.07 var(--serif);line-height:1.2}', '1.65', '1.2'),
);
foreach ($cases as $name => $case) {
    [$css, $expected] = $case;
    $html = '<!doctype html><html><head><style>:root{--sans:Arial,sans-serif;--serif:Georgia,serif}' . $css
        . '</style></head><body><main><h1>Title</h1><p>Body text.</p></main></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $theme = array();
    foreach ($result['source_reports']['wordpress_site_plan']['writes'] ?? array() as $write) {
        if ('theme.json' === $write['target_path']) $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
    }
    $actual = $theme['styles']['typography']['lineHeight'] ?? null;
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$name}: root expected {$expected}, got " . json_encode($actual) . "\n");
        exit(1);
    }
    // Heading typography remains owned by the projected author stylesheet,
    // for literal and variable shorthands alike (not frozen block attributes).
    if (isset($case[2])) {
        $projected = implode("\n", array_column($result['source_reports']['wordpress_site_plan']['assets'], 'content'));
        if (!str_contains($projected, $case[2])) {
            fwrite(STDERR, "FAIL: {$name}: missing projected heading leading {$case[2]}\n");
            exit(1);
        }
    }
}
echo "Design usage font shorthand line height passed\n";

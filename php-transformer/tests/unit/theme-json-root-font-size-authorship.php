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
fwrite(STDOUT, "Root font-size authorship passed\n");

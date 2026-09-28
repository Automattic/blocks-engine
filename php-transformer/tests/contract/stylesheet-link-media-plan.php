<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$artifact = array(
    'entrypoint' => 'index.html',
    'files' => array(
        array('path' => 'index.html', 'content' => '<!doctype html><html><head><title>Example</title></head><body><main><h1>Example</h1></main></body></html>'),
        array('path' => 'details.html', 'content' => '<!doctype html><html><head><title>Item 1</title><link rel="stylesheet" href="mobile.css" media="(max-width:767px)"><link rel="stylesheet" href="desktop.css" media="(min-width:768px)"></head><body><main><h1>Item 1</h1></main></body></html>'),
        array('path' => 'mobile.css', 'content' => '.title{--item-size:28px}'),
        array('path' => 'desktop.css', 'content' => '.title{--item-size:60px}'),
    ),
);

$plan = (new ArtifactCompiler())->compile($artifact)->toWordPressSitePlanView()['wordpress_site_plan'] ?? array();
$mediaByPath = array();
foreach ( $plan['assets'] ?? array() as $asset ) {
    $path = (string) ($asset['path'] ?? $asset['source_path'] ?? '');
    if ( in_array(basename($path), array('mobile.css', 'desktop.css'), true) ) $mediaByPath[basename($path)] = $asset['media'] ?? '';
}
$bootstrap = (string) (array_column($plan['writes'] ?? array(), null, 'target_path')['functions.php']['payload']['data'] ?? '');

if ( '(max-width:767px)' !== ($mediaByPath['mobile.css'] ?? null) || '(min-width:768px)' !== ($mediaByPath['desktop.css'] ?? null) ) {
    fwrite(STDERR, 'FAIL: route-only stylesheet media reaches plan assets - ' . json_encode($mediaByPath) . "\n");
    exit(1);
}
if ( ! str_contains($bootstrap, "'(max-width:767px)'") || ! str_contains($bootstrap, "'(min-width:768px)'") ) {
    fwrite(STDERR, "FAIL: generated enqueue retains stylesheet media\n");
    exit(1);
}
echo "stylesheet link media plan passed: route-only media retained and enqueued\n";

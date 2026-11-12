<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$rows = array(
    'zero box' => array('width="0" height="0"', '', false),
    'absolute' => array('style="position:absolute;top:-1000px"', '', false),
    'fixed' => array('style="position:fixed;top:-1000px"', '', false),
    'in flow' => array('', '', true),
    'referenced off-screen' => array('style="position:absolute;top:-1000px"', '<div style="clip-path:url(#mask-stars);width:240px;height:46px;background:gold">★</div>', true),
);
foreach ($rows as $name => list($attributes, $consumer, $expectedKept)) {
    $svg = '<svg viewBox="0 0 1200 228" ' . $attributes . '><defs><clipPath id="mask-stars"><path d="M46,228L120,0l74,228L0,87h240"></path></clipPath></defs></svg>';
    $html = '<!doctype html><html><head><title>Reviews</title></head><body><main><h1>Reviews</h1>' . $svg . $consumer . '<p>Five stars.</p></main></body></html>';
    $result = (new ArtifactCompiler())->compile(array('files' => array(array('path' => 'index.html', 'kind' => 'html', 'content' => $html))))->toArray();
    if (str_contains($result['serialized_blocks'], 'mask-stars') !== $expectedKept) {
        throw new RuntimeException($name . ': expected definition carrier to be ' . ($expectedKept ? 'kept' : 'dropped'));
    }
}
echo "Unreferenced SVG footprint contracts passed\n";

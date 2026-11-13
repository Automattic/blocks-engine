<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<html><head><link rel="stylesheet" href="style.css"></head><body><main><img class="spaced" src="photo.jpg" alt="Product"><p class="spaced">Description</p></main></body></html>',
        'style.css' => '.spaced{margin-bottom:1rem!important}',
    ),
))->toArray();
$css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
$found = false;
preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
foreach ($rules as $rule) {
    [$unused, $part, $body] = $rule;
    if (!str_contains($body, 'margin-bottom') || !str_contains($part, '.spaced')) continue;
    foreach (explode(',', $part) as $selector) {
        if (!str_contains($selector, '.spaced')) continue;
        $found = true;
        // The author class moves to the figure; only guarded ordinary arms
        // and descendant image arms may retain its margin declaration.
        if (!str_contains($selector, 'blocks-engine-synthetic-image-figure') && !str_contains($selector, '> img') && !str_contains($selector, '>img')) {
            throw new RuntimeException('Image margin also targets its synthetic figure: ' . $selector);
        }
    }
}
if (!$found) throw new RuntimeException('Missing projected spacing rule');
echo "image-margin-single-owner: ok\n";

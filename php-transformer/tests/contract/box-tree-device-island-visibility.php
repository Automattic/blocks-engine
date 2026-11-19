<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Issue #5859 (sdnunca /about/): a capture assembled from device documents
// keeps both device islands of a box, the desktop one carrying the box-tree
// evidence (observed visible at every capture) and the mobile one none. The
// capture's own visibility sheet shows each island only on its device. The
// route's author sheets are retired for carriers (ADR 0005), but carriers
// cannot hide an island the evidence observed visible, so that sheet must
// still be delivered: otherwise both copies render and push the page down.
$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$visibility = '@media(min-width:1024px){.data-blocks-engine-capture-mobile-only{display:none!important}}@media(max-width:1023px){.data-blocks-engine-capture-desktop-only{display:none!important}}';
$source = str_replace(
    array('</head>', '<h1 data-blocks-engine-node-id="n6">Hello</h1>'),
    array('<link rel="stylesheet" href="/assets/css/capture-visibility.css"><link rel="stylesheet" href="/assets/css/site.css"></head>', '<h1 class="data-blocks-engine-capture-desktop-only" data-blocks-engine-node-id="n6">Hello</h1><h1 class="data-blocks-engine-capture-mobile-only" data-blocks-engine-node-id="mobile:n6">Hello</h1>'),
    $html
);
$tree['source_hash'] = hash('sha256', $source);
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $source,
    'assets/css/capture-visibility.css' => $visibility,
    'assets/css/site.css' => 'h1{color:red}',
    'box-tree/index.json' => json_encode($tree),
)))->toWordPressSitePlanView()['wordpress_site_plan'];

$markup = implode('', array_column($result['pages'], 'canonical_block_markup'));
$css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? '') && 'editor' !== ($asset['stylesheet_target'] ?? 'both')), 'content'));
$assert = static function (bool $ok, string $message, string $detail): void {
    if (!$ok) throw new RuntimeException($message . "\n" . $detail);
    echo 'PASS: ' . $message . "\n";
};
$assert(str_contains($markup, 'data-blocks-engine-capture-desktop-only') && str_contains($markup, 'data-blocks-engine-capture-mobile-only'), 'Both device islands are emitted.', $markup);
$assert(!str_contains($css, 'color:red'), 'The resting author sheet is retired on the evidence route.', $css);
$assert(1 === preg_match('/@media\s*\(max-width:1023px\)\s*\{[^{}]*\.data-blocks-engine-capture-desktop-only[^{}]*\{display:none!important\}\}/', $css), 'The desktop island stays hidden below the capture breakpoint.', $css);
$assert(1 === preg_match('/@media\s*\(min-width:1024px\)\s*\{[^{}]*\.data-blocks-engine-capture-mobile-only[^{}]*\{display:none!important\}\}/', $css), 'The mobile island stays hidden above the capture breakpoint.', $css);

echo "Box-tree device island visibility passed\n";

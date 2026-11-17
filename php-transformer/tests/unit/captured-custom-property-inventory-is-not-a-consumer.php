<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// The capture's css-variables.json sidecar records every custom property the
// source defines. It is the stylesheet's own inventory, not a reader, so it
// must not keep unread framework tokens in the published theme CSS.
$inventory = json_encode(array('version' => 1, 'sampledUrls' => 1, 'variables' => array(
    array('name' => '--unread-token', 'value' => '#0d6efd', 'isColor' => true, 'urls' => 1),
    array('name' => '--read-token', 'value' => 'teal', 'isColor' => true, 'urls' => 1),
    array('name' => '--captured-subject', 'value' => 'pink', 'isColor' => true, 'urls' => 1),
    array('name' => '--unreachable-reader-token', 'value' => 'purple', 'isColor' => true, 'urls' => 1),
)));
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><p class="paint">Hello</p></main></body></html>',
    'site.css' => ':root{--unread-token:#0d6efd;--read-token:teal;--unreachable-reader-token:purple}.paint{color:var(--read-token)}.--captured-subject{outline-color:orange}.absent-subject{color:var(--unreachable-reader-token)}',
    'css-variables.json' => $inventory,
)))->toArray();
$css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
if (str_contains($css, '--unread-token')) throw new RuntimeException('A property only the capture inventory names must not ship: ' . $css);
if (str_contains($css, '--unreachable-reader-token')) throw new RuntimeException('Neither inventory names nor unreachable readers must keep a token alive: ' . $css);
if (!str_contains($css, '--read-token:teal')) throw new RuntimeException('A property the stylesheet reads must survive: ' . $css);
// Inventory definitions are not evidence that an element has a class either.
if (str_contains($css, 'outline-color:orange')) throw new RuntimeException('Inventory definitions must not keep absent selectors alive: ' . $css);
echo "Captured custom-property inventory is not consumer evidence\n";

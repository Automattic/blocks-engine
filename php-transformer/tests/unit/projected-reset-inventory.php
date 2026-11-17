<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Native images make universal source subjects expand to block wrappers,
// image children and pseudo-elements. Prune inventories before that expansion,
// while retaining readers in other routes and inline author sheets.
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><figure><img src="photo.svg" alt="Photo"><figcaption>Caption</figcaption></figure></main></body></html>',
    'other.html' => '<!doctype html><html><head><style>p{padding:var(--cross-route)}</style></head><body><p>Other</p></body></html>',
    'site.css' => '*,:before,:after{--unused-reset:12345px;--cross-route:7px;box-sizing:border-box}img{max-width:100%;height:auto}',
    'photo.svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><rect width="100" height="100" fill="red"/></svg>',
)))->toArray();
$css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
if (str_contains($css, '--unused-reset')) throw new RuntimeException('Projected universal inventories must not retain unread properties');
if (!str_contains($css, '--cross-route:')) throw new RuntimeException('Inline cross-route readers must retain their reset values');
if (!str_contains($css, 'box-sizing')) throw new RuntimeException('Ordinary reset declarations must survive');
echo "Projected reset inventory pruning passed\n";

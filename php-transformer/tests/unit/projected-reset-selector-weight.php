<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

// A framework reset's margins stay off a lowered image's synthetic figure.
// Its generated-content arms are never that wrapper element: guarding them
// only ships the guard again per arm, and a pseudo-class after a
// pseudo-element is invalid, voiding the whole reset in some engines.
$html = '<!doctype html><html><head><link rel="stylesheet" href="style.css"></head><body><main><section class="hero"><h1>Title</h1><p>Copy text</p><img src="photo.png" alt="Photo" width="200" height="100"></section></main></body></html>';
$files = array(
    'index.html' => $html,
    'style.css' => '*,:after,:before,::backdrop{box-sizing:border-box;margin:0}.hero{padding:20px}',
    'photo.png' => (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='),
);
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
$css = implode("\n", array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), array_filter($result['assets'], static fn(array $asset): bool => 'files' === ($asset['source'] ?? null))));
$guard = ':not(:where(.blocks-engine-synthetic-image-figure))';
if (!str_contains($css, '*' . $guard)) throw new RuntimeException('The element reset lost its synthetic-figure guard: ' . $css);
if (1 === preg_match('/(?::after|:before|::backdrop)' . preg_quote($guard, '/') . '/', $css)) throw new RuntimeException('A pseudo-element arm carries the synthetic-figure guard: ' . $css);

// A style rule projection emptied declares nothing and ships only its selector.
$compact = (new CssStylesheetTransformer())->compactStylesheet('@layer base{.a{color:red}.b:not([style*="font"]){}}.c{ }@media print{.d{}}/* keep */.e{}@layer empty{.f{}}');
if ('@layer base{.a{color:red}}/* keep */.e{}@layer empty{.f{}}' !== $compact) throw new RuntimeException('Empty style rules survived compaction: ' . $compact);
echo "Projected reset selector weight passed\n";

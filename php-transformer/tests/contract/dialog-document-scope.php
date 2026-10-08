<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$fixture = require dirname(__DIR__) . '/fixtures/dialog-document-scope.php';
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$flatten = static function (array $blocks) use (&$flatten): array {
    $out = array();
    foreach ($blocks as $block) { $out[] = $block; array_push($out, ...$flatten($block['innerBlocks'] ?? array())); }
    return $out;
};
foreach (array('desktop', 'mobile') as $variant) {
    $result = (new ArtifactCompiler())->compile($fixture($variant))->toArray();
    $blocks = $flatten($result['blocks']);
    $names = array_column($blocks, 'blockName');
    $assert(!in_array('core/html', $names, true), 'A document-scope lookup and deeply nested dialog do not turn the page into core/html: ' . $variant);
    $assert(in_array('core/heading', $names, true) && in_array('core/paragraph', $names, true), 'Unrelated main/footer remain native editable blocks.');
    $assert(str_contains($result['serialized_blocks'], 'data-dla-document-scope') && str_contains($result['serialized_blocks'], 'data-dla-dialog-ancestor-unverified'), 'Document identity and unverified ancestor proof remain reviewable in emitted markup.');
    $assert(str_contains($result['serialized_blocks'], 'data-dla-dialog-panel="gallery-' . $variant . '"') && str_contains($result['serialized_blocks'], 'Captured image caption.'), 'The wired popup remains editable in its declared source document scope.');
    $assert('pass' === (new Runtime())->validateBlockSerialization($result['blocks'])['status'], 'The document and popup serialize as valid editable blocks.');
}
$unknown = (new ArtifactCompiler())->compile($fixture('desktop', true))->toArray();
$html = array_values(array_filter($flatten($unknown['blocks']), static fn(array $block): bool => 'core/html' === $block['blockName']));
$assert(array() !== $html && !str_contains($html[0]['innerHTML'], 'Unrelated main copy.'), 'A real descendant application keeps its bounded unsupported behavior without swallowing the document.');
$assert(array() !== array_filter($unknown['source_reports']['runtime_islands'] ?? array(), static fn(array $island): bool => 'app_shell' === ($island['kind'] ?? null) && '#workspace' === ($island['selector'] ?? null)), 'The application preservation diagnostic is truthful and source-owned.');

$static = $fixture();
$static['files']['index.html'] = str_replace(array('<main>', '</main>'), array('<div id="page-frame"><main>', '</main><canvas></canvas></div>'), $static['files']['index.html']);
$static['files']['index.html'] = str_replace('data-liberation-desktop-document', 'site-document-variant-portrait', $static['files']['index.html']);
$result = (new ArtifactCompiler())->compile($static)->toArray();
foreach (array_filter($flatten($result['blocks']), static fn(array $block): bool => 'core/html' === $block['blockName']) as $block) {
    $assert(!str_contains($block['innerHTML'], 'Unrelated main copy.'), 'An unrelated canvas does not move a page-wide fallback to the next static ancestor.');
}
$assert(in_array('core/heading', array_column($flatten($result['blocks']), 'blockName'), true), 'The generic declared variant contract remains editable.');

$routes = array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><title>Home</title><style id="source-style">body{margin:0}</style><script type="application/ld+json">{"@type":"LocalBusiness","name":"Neutral"}</script></head><body><h1>Home</h1><a href="about.html">About</a></body></html>',
    'about.html' => '<!doctype html><html><head><title>About</title><style id="about-style">body{margin:0}</style><script>window.aboutLoaded=true;</script></head><body><h1>About</h1></body></html>',
));
$result = (new ArtifactCompiler())->compile($routes)->toArray();
$assert('failed' !== $result['status'], 'Another route script occurrence cannot overwrite homepage inline-data loading identity.');
$pages = $result['source_reports']['wordpress_site_plan']['pages'];
$assert(2 === count($pages), 'Both source-owned routes compile through the real plan validation gate.');
foreach ($pages as $page) {
    $script = $page['document_metadata']['scripts'][0];
    if ('index.html' === $page['source_path']) {
        $assert('inline' === ($script['source_kind'] ?? null) && 'application/ld+json' === ($script['type'] ?? null), 'Homepage structured data retains its inline loading contract.');
    } else {
        $assert(isset($script['asset_reference']), 'The other route retains its executable script asset.');
    }
}

echo "Dialog document scope contract passed.\n";

<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\AssetReferenceCanonicalizer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\EditorStaticStateAssets;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\EmptyStylesheets;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\StylesheetScopes;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeAssetWrites;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

$shared = array('.paint{color:red}', '.paint{color:blue}');
$pages = $assets = array();
foreach (array('index.html', 'page-1.html') as $i => $source) {
    $scope = array('kind' => 'page', 'source_path' => $source, 'post_type' => 'page', 'slug' => 'page-' . $i, 'reconciliation_identity' => 'page:' . $source, 'route_path' => '/' . $i);
    $pages[] = array(
        'source_path' => $source, 'post_type' => 'page', 'slug' => 'page-' . $i,
        'reconciliation_identity' => 'page:' . $source, 'route' => array('path' => '/' . $i),
        'canonical_block_markup' => '<p class="paint">Page</p>',
        'document_metadata' => array('links' => array()),
    );
    $path = 'assets/css/editor-static-state-' . $i . '.css';
    $assets[] = array(
        'kind' => 'css', 'source' => 'editor-static-state', 'source_path' => $path, 'target_path' => $path,
        'token' => 'asset-' . $i, 'reconciliation_identity' => 'asset:' . $path, 'scopes' => array($scope),
        'content' => $shared[0] . (1 === $i ? '.paint{padding:3px}' : '.paint{margin:4px}') . $shared[1],
        'stylesheet_target' => 'editor', 'stylesheet_placement' => 'after-author', 'media' => 'screen',
        'stylesheet_instances' => array(array('source_path' => $source, 'order' => 0, 'media' => 'screen')),
    );
}
$references = new AssetReferenceCanonicalizer(array());
$split = EditorStaticStateAssets::share($assets, $references);
$owner = null;
foreach ($split as $asset) foreach ($asset['stylesheet_replays'] ?? array() as $replay) {
    if ('asset-1' === $replay['token']) $owner = $replay;
}
if (null === $owner || 3 !== count($owner['stylesheet_parts'])) throw new RuntimeException('Fixture must produce three real ordered parts.');
$paths = $owner['stylesheet_parts'];
$documents = StylesheetScopes::documentsBySource($pages, array());
$folded = StylesheetScopes::foldSupport($split, $documents, StylesheetScopes::canonicalDocuments($documents));
if ($split !== $folded) throw new RuntimeException('Support folding must not empty editor static state.');
// Simulate a page-specific part becoming empty after CSS normalization.
$retired = $folded;
foreach ($retired as &$asset) {
    if ($asset['target_path'] === $paths[1]) $asset['content'] = '';
}
unset($asset);
$byTarget = array_column($retired, null, 'target_path');
if (!EmptyStylesheets::isEmpty($byTarget[$paths[1]])) throw new RuntimeException('The page-specific part must be empty.');
$writes = (new ThemeAssetWrites())->writes($retired, $references);
$result = EmptyStylesheets::elide($retired, array('pages' => $pages), $pages, array(), '', $writes);
$kept = array_column($result['assets'], null, 'target_path');
$writes = array_values(array_filter($writes, static fn(array $write): bool => isset($kept[$write['target_path']])));
if (0 !== EmptyStylesheets::countWrites($result['assets'], $writes)) throw new RuntimeException('An emptied static-state part must not be written.');
if (isset($kept[$paths[1]])) throw new RuntimeException('The empty resource must be elided.');
$written = array_column(array_column($writes, 'payload'), 'data');
foreach ($retired as $asset) {
    if ($asset['target_path'] === $paths[1]) continue;
    if (!isset($kept[$asset['target_path']]) || !in_array($asset['content'], $written, true)) throw new RuntimeException('Every non-empty part must remain written.');
}
$survivors = array($paths[0], $paths[2]);
foreach ($result['assets'] as $asset) foreach ($asset['stylesheet_replays'] ?? array() as $replay) {
    if ('asset-1' === $replay['token'] && $survivors !== $replay['stylesheet_parts']) throw new RuntimeException('Replay parts must close ranks without reordering.');
}
$plan = array('assets' => $result['assets'], 'pages' => $pages);
$delivered = array_column(ThemeBootstrap::documentStylesheets($plan, 'page-1.html'), 'target_path');
if ($survivors !== $delivered) throw new RuntimeException('Only surviving parts must be enqueued, in order: ' . json_encode($delivered));
echo "Empty static-state parts passed\n";

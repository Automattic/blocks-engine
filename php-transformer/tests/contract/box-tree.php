<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactNormalizer;
use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
};
$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$compile = static function (?array $evidence) use ($html): array {
    $files = array('index.html' => $html);
    if (null !== $evidence) $files['box-tree/index.json'] = json_encode($evidence, JSON_THROW_ON_ERROR);
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
};
$pseudo = $tree['nodes']['n5']['viewports']['desktop'];
$pseudo['content'] = '""';
$tree['nodes']['n5']['viewports']['desktop']['pseudo']['before'] = $pseudo;
$baseline = $compile(null);
$result = $compile($tree);
$assert('blocks-engine/box-tree/v2' === $tree['schema'], 'Contract fixture uses v2.');
$assert('paints' === $result['source_reports']['box_tree']['index.html']['classification']['nodes']['n5']['criterion'], 'A rendering pseudo-element paints even with empty content.');
$assert(1.0 === (float) $result['source_reports']['box_tree']['index.html']['coverage'], 'Fixture route has complete box-tree coverage.');
$assert($baseline['serialized_blocks'] !== $result['serialized_blocks'], 'Accepted browser observations restyle converted blocks.');
$assert(!array_filter($result['diagnostics'], static fn(array $row): bool => str_starts_with($row['code'], 'box_tree_')), 'Valid evidence produces no rejection diagnostics.');
$normalized = (new ArtifactNormalizer())->normalize(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))));
$source = array_values(array_filter($normalized['files'], static fn(array $f): bool => 'index.html' === $f['path']))[0];
$element = HtmlSerialization::parseDocument($html)->getElementsByTagName('h1')->item(0);
$assert(isset(BoxTree::forElement($source['box_tree'], $element)['mobile']['used_styles']['display']), 'Evidence is addressable by exact source element at each viewport.');
$cases = array(
    'box_tree_schema_invalid' => static function (array &$t): void { $t['schema'] = 'blocks-engine/box-tree/v1'; },
    'box_tree_identity_stale' => static function (array &$t): void { $t['source_hash'] = str_repeat('0', 64); },
    'box_tree_bounds_invalid' => static function (array &$t): void { $t['viewports'] = array(); },
    'box_tree_geometry_invalid' => static function (array &$t): void { $t['nodes']['n6']['viewports']['mobile']['border_box']['width'] = -1; },
    'box_tree_styles_invalid' => static function (array &$t): void { unset($t['nodes']['n6']['viewports']['desktop']['used_styles']['display']); },
    'box_tree_pseudo_invalid' => static function (array &$t): void { $t['nodes']['n5']['viewports']['desktop']['pseudo']['before']['content'] = 'none'; },
    'box_tree_viewport_invalid' => static function (array &$t): void { unset($t['nodes']['n6']['viewports']['mobile']); },
);
foreach ($cases as $code => $mutate) {
    $bad = $tree;
    $mutate($bad);
    $rejected = $compile($bad);
    $assert(in_array($code, array_column($rejected['diagnostics'], 'code'), true), "$code is reported.");
    $assert(0 === $rejected['source_reports']['box_tree']['index.html']['nodes_with_evidence'] && $baseline['serialized_blocks'] === $rejected['serialized_blocks'], 'Rejected evidence is discarded without affecting output.');
}
foreach (array(
    static function (array &$p): void { $p['content'] = 'normal'; },
    static function (array &$p): void { unset($p['content']); },
    static function (array &$p): void { $p['border_box']['height'] = -1; },
    static function (array &$p): void { unset($p['used_styles']['font-size']); },
    static function (array &$p): void { $p['used_styles']['content'] = '"bad"'; },
) as $mutate) {
    $bad = $tree;
    $mutate($bad['nodes']['n5']['viewports']['desktop']['pseudo']['before']);
    $rejected = $compile($bad);
    $assert(0 === $rejected['source_reports']['box_tree']['index.html']['nodes_with_evidence'], 'Malformed pseudo evidence rejects the whole sidecar.');
}
$bad = $tree;
$bad['nodes']['n5']['viewports']['desktop']['pseudo']['marker'] = $pseudo;
$assert(0 === $compile($bad)['source_reports']['box_tree']['index.html']['nodes_with_evidence'], 'Only before and after pseudo-elements are accepted.');
$schema = json_decode(file_get_contents(__DIR__ . '/../../resources/box-tree.schema.json'), true, 32, JSON_THROW_ON_ERROR);
$assert(BoxTree::PROPERTIES === $schema['properties']['nodes']['additionalProperties']['properties']['viewports']['additionalProperties']['properties']['used_styles']['required'], 'Published producer schema and intake use one property vocabulary.');

$compiler = new ArtifactCompiler();
$artifact = array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree)));
$shared = $compiler->prepareShared($artifact);
$pages = $compiler->preparePages($artifact, $shared);
$receipts = $compiler->compilePreparedPages($shared, array_values($pages));
$staged = $compiler->compose($shared, array_values($receipts))->toArray();
$assert($staged['serialized_blocks'] === $result['serialized_blocks'], 'Staged transport preserves captured-style conversion.');

$other = $tree;
$other['source_path'] = 'other.html';
$artifact['files']['other.html'] = $html;
$artifact['files']['box-tree/other.json'] = json_encode($other);
$whole = (new ArtifactCompiler())->compile($artifact)->toArray();
$assert(2 === count($whole['source_reports']['box_tree']) && 1.0 === (float) $whole['source_reports']['box_tree']['other.html']['coverage'], 'Every route receives its own complete evidence.');
$shared = $compiler->prepareShared($artifact);
$pages = $compiler->preparePages($artifact, $shared);
$receipts = $compiler->compilePreparedPages($shared, array_values($pages));
$staged = $compiler->compose($shared, array_values($receipts))->toArray();
$assert(!array_filter($staged['diagnostics'], static fn(array $row): bool => str_starts_with($row['code'], 'box_tree_')), 'Staged multi-route intake does not leak sidecars between page workers.');
$assert($whole['source_reports']['box_tree'] === $staged['source_reports']['box_tree'], 'Staged and monolithic route classifications and projections agree.');

// Export may insert wrappers without browser observations.
$exportHtml = str_replace('<h1 ', '<div class="export-wrapper"><h1 ', str_replace('</h1>', '</h1></div>', $html));
$exportTree = $tree;
$exportTree['source_path'] = 'website/index.html';
$exportTree['source_hash'] = hash('sha256', $exportHtml);
$exportArtifact = array('entrypoint' => 'website/index.html', 'files' => array(
    'website/index.html' => $exportHtml,
    'website/box-tree/index.json' => json_encode($exportTree),
    'website/box-tree/invalid.json' => '{',
));
$export = (new ArtifactCompiler())->compile($exportArtifact)->toArray();
$report = $export['source_reports']['box_tree']['website/index.html'];
$assert(count($tree['nodes']) === $report['nodes_with_evidence'] && $report['coverage'] < 1, 'Exported route accepts nearest observed children through an inserted wrapper.');
$assert(!isset($report['classification']['nodes']['']), 'Unobserved wrappers are never classified neutral.');
$assert(str_contains($export['serialized_blocks'], 'export-wrapper'), 'Inserted wrapper survives conversion.');
$normalizedExport = (new ArtifactNormalizer())->normalize($exportArtifact);
foreach ($normalizedExport['files'] as $file) {
    if (BoxTree::isSidecarPath($file['path'])) $assert(ArtifactNormalizer::CAPTURE_REPORT_ROLE === $file['role'], 'Sidecars are evidence even without a reports declaration.');
}
$assert(!str_contains(json_encode($export['assets']), 'box-tree/'), 'Valid and rejected sidecars never enter the asset manifest.');
$exportCompiler = new ArtifactCompiler();
$exportShared = $exportCompiler->prepareShared($exportArtifact);
$exportPages = $exportCompiler->preparePages($exportArtifact, $exportShared);
$exportReceipts = $exportCompiler->compilePreparedPages($exportShared, array_values($exportPages));
$exportStaged = $exportCompiler->compose($exportShared, array_values($exportReceipts))->toArray();
$assert($exportStaged['source_reports']['box_tree'] === $export['source_reports']['box_tree'], 'Staged export intake preserves partial evidence coverage and classification.');
$assert(!str_contains(json_encode($exportStaged['assets']), 'box-tree/'), 'Staged delivery excludes sidecars too.');
foreach (array('missing', 'duplicate', 'hash', 'topology') as $mode) {
    $invalidArtifact = $exportArtifact;
    unset($invalidArtifact['files']['website/box-tree/invalid.json']);
    $invalidHtml = match ($mode) {
        'missing' => str_replace('data-blocks-engine-node-id="n6"', '', $exportHtml),
        'duplicate' => str_replace('class="export-wrapper"', 'data-blocks-engine-node-id="n6"', $exportHtml),
        'empty' => str_replace('class="export-wrapper"', 'data-blocks-engine-node-id=""', $exportHtml),
        default => $exportHtml,
    };
    $invalidTree = $exportTree;
    if ('hash' !== $mode) $invalidTree['source_hash'] = hash('sha256', $invalidHtml);
    else $invalidTree['source_hash'] = str_repeat('0', 64);
    if ('topology' === $mode) $invalidTree['nodes']['n5']['children'] = array('n7', 'n6');
    $invalidArtifact['files']['website/index.html'] = $invalidHtml;
    $invalidArtifact['files']['website/box-tree/index.json'] = json_encode($invalidTree);
    $rejected = (new ArtifactCompiler())->compile($invalidArtifact)->toArray();
    $diagnostic = array_values(array_filter($rejected['diagnostics'], static fn(array $row): bool => 'box_tree_identity_stale' === $row['code']))[0] ?? array();
    $assert('website/index.html' === ($diagnostic['context']['source_path'] ?? null), "$mode rejection identifies the exported route.");
}

$bad = $tree;
$bad['nodes']['n5']['children'] = array('n7', 'n6');
$assert(in_array('box_tree_identity_stale', array_column($compile($bad)['diagnostics'], 'code'), true), 'Stale DOM topology is rejected.');

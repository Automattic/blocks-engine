<?php
declare(strict_types=1);

// Exercises box-tree decisions, which are opt-in (#7352).
putenv('BLOCKS_ENGINE_BOX_TREE_INTAKE=1');

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactNormalizer;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
};
$template = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$html = '<!doctype html><html><head></head><body data-blocks-engine-node-id="root"><!--#include virtual="/parts/header.html" --><div data-blocks-engine-node-id="duplicate"><p data-blocks-engine-node-id="child">Hello</p></div></body></html>';
$header = '<header data-blocks-engine-node-id="foreign"><div data-blocks-engine-node-id="duplicate"><!--#include virtual="/parts/inner.html" --></div></header>';
$inner = '<div data-blocks-engine-node-id=""><p data-blocks-engine-node-id="included">Included</p></div>';
$tree = array(
    'schema' => $template['schema'], 'source_path' => 'website/index.html', 'source_hash' => hash('sha256', $html),
    'includes' => array('website/parts/header.html' => hash('sha256', $header), 'website/parts/inner.html' => hash('sha256', $inner)),
    'viewports' => $template['viewports'], 'nodes' => array(),
);
foreach (array('root' => array('included', 'child'), 'included' => array(), 'child' => array()) as $id => $children) {
    $tree['nodes'][$id] = array('children' => $children, 'viewports' => $template['nodes']['n6']['viewports']);
}
$artifact = array('entrypoint' => 'website/index.html', 'files' => array(
    'website/index.html' => $html, 'website/parts/header.html' => $header, 'website/parts/inner.html' => $inner,
    'website/box-tree/index.json' => json_encode($tree),
));
$normalize = static fn(array $input): array => (new ArtifactNormalizer())->normalize($input);
$accepted = $normalize($artifact);
$assert(!in_array('box_tree_identity_stale', array_column($accepted['diagnostics'], 'code'), true), 'Include-resolved topology accepts foreign and duplicated IDs as unobserved.');
$route = array_values(array_filter($accepted['files'], static fn(array $file): bool => 'website/index.html' === $file['path']))[0];
$assert(3 === count($route['box_tree']['nodes']) && str_contains($route['content'], 'Included'), 'Raw page and nested part hashes bind evidence on the resolved document.');
foreach (array('part', 'nested-part', 'missing-hash', 'extra-hash', 'duplicate-record', 'missing-node', 'topology', 'page') as $mode) {
    $bad = $artifact;
    $badTree = $tree;
    if ('part' === $mode) $bad['files']['website/parts/header.html'] .= ' ';
    if ('nested-part' === $mode) $bad['files']['website/parts/inner.html'] .= ' ';
    if ('missing-hash' === $mode) unset($badTree['includes']['website/parts/inner.html']);
    if ('extra-hash' === $mode) $badTree['includes']['website/parts/unused.html'] = hash('sha256', '');
    if ('duplicate-record' === $mode) $badTree['nodes']['duplicate'] = $badTree['nodes']['child'];
    if ('missing-node' === $mode) $badTree['nodes']['absent'] = $badTree['nodes']['child'];
    if ('topology' === $mode) $badTree['nodes']['root']['children'] = array('child', 'included');
    if ('page' === $mode) $bad['files']['website/index.html'] .= ' ';
    $bad['files']['website/box-tree/index.json'] = json_encode($badTree);
    $assert(in_array('box_tree_identity_stale', array_column($normalize($bad)['diagnostics'], 'code'), true), "$mode rejects stale evidence atomically.");
}
$compiler = new ArtifactCompiler();
$whole = $compiler->compile($artifact)->toArray();
$shared = $compiler->prepareShared($artifact);
$pages = $compiler->preparePages($artifact, $shared);
$receipts = $compiler->compilePreparedPages($shared, array_values($pages));
$staged = $compiler->compose($shared, array_values($receipts))->toArray();
$assert(3 === $whole['source_reports']['box_tree']['website/index.html']['nodes_with_evidence'], 'Compiled route retains partial evidence from included content.');
$assert($whole['source_reports']['box_tree'] === $staged['source_reports']['box_tree'], 'Staged and monolithic include-resolved evidence agree.');

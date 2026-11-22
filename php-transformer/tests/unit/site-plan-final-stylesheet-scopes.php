<?php
declare(strict_types=1);

// Exercises box-tree decisions, which are opt-in (#7352).
putenv('BLOCKS_ENGINE_BOX_TREE_INTAKE=1');
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\StylesheetScopes;

// Discovered by tests/run.php with the other unit fixtures. A mixed capture
// site retires a global author sheet while archive captures are still working
// pages. Its final owner is the native archive root, not that temporary page.
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$files = array('site.css' => '.item-copy{width:602px;padding:14px}.all-copy{width:601px;padding:13px}');
$add = static function (string $path, string $route, string $body, array $metadata = array()) use (&$files): void {
    $files[] = array('path' => $path, 'content' => '<html><head><link rel="stylesheet" href="/site.css"></head><body><main><div class="all-copy">' . $body . '</div></main></body></html>', 'metadata' => $metadata + array('route_path' => $route));
};
$add('index.html', '/', '<h1>Home</h1>');
$add('journal/index.html', '/journal', '<h1>Journal</h1><article><h2><a href="/journal/first">First</a></h2><p>First summary.</p></article><article><h2><a href="/journal/second">Second</a></h2><p>Second summary.</p></article>');
foreach (array('first', 'second') as $slug) {
    $add('journal/' . $slug . '.html', '/journal/' . $slug, '<h1>' . ucfirst($slug) . '</h1><div class="item-copy"><p>Full story ' . $slug . '.</p></div><a href="/category/notes">Notes</a>', array('post_type' => 'post'));
}
$add('category/notes.html', '/category/notes', '<h1>Notes</h1><article><h2><a href="/journal/first">First</a></h2><p>First summary.</p></article><article><h2><a href="/journal/second">Second</a></h2><p>Second summary.</p></article>');
$template = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 64, JSON_THROW_ON_ERROR);
foreach ($files as &$file) {
    if (!is_array($file) || 'index.html' !== $file['path']) continue;
    $nodes = $stack = array();
    $count = 0;
    $file['content'] = preg_replace_callback('/<(\/?)([a-z][a-z0-9]*)([^>]*)>/i', static function (array $match) use (&$nodes, &$stack, &$count): string {
        if ('/' === $match[1]) { array_pop($stack); return $match[0]; }
        $id = 'n' . ++$count;
        $nodes[$id] = array('children' => array());
        if (array() !== $stack) $nodes[end($stack)]['children'][] = $id;
        if ('link' !== strtolower($match[2])) $stack[] = $id;
        return '<' . $match[2] . ' data-blocks-engine-node-id="' . $id . '"' . $match[3] . '>';
    }, $file['content']);
    foreach ($nodes as &$node) $node['viewports'] = $template['nodes']['n7']['viewports'];
    unset($node);
    $files['box-tree/index.json'] = json_encode(array('schema' => $template['schema'], 'source_path' => 'index.html', 'source_hash' => hash('sha256', $file['content']), 'viewports' => $template['viewports'], 'nodes' => $nodes), JSON_THROW_ON_ERROR);
}
unset($file);
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
if (!isset($result['source_reports']['wordpress_site_plan'])) throw new RuntimeException(json_encode($result['diagnostics']));
$plan = $result['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$documents = StylesheetScopes::documentsBySource($plan['pages'], $plan['theme']['document_roots'] ?? array());
StylesheetScopes::assertOwned($plan['assets'], $documents);
$assert(2 === count(array_filter($plan['pages'], static fn(array $page): bool => 'post' === $page['post_type'])), 'Collection items materialize as posts.');
$assert(1 === count($plan['taxonomy_entities']), 'The captured category becomes a native archive.');
$archiveScopes = $sharedSupport = array();
foreach ($plan['assets'] as $asset) {
    foreach ($asset['scopes'] ?? array() as $scope) if ('category/notes.html' === ($scope['source_path'] ?? null)) $archiveScopes[] = $scope;
    if ('engine-support' === $asset['source'] && array('journal/first.html', 'journal/second.html') === array_column($asset['scopes'], 'source_path')) $sharedSupport[] = $asset;
}
$assert(array() !== $sharedSupport, 'Collection items share route-specific support resources.');
$assert(array() !== $archiveScopes, 'The native archive retains stylesheet ownership.');
foreach ($archiveScopes as $scope) $assert('archive' === $scope['kind'], 'No temporary archive page scope ships.');
// Validation still rejects stale and missing owners, with enough context to
// diagnose either case without instrumenting the importer.
$stale = array('kind' => 'page', 'source_path' => 'category/notes.html', 'route_path' => 'category/notes', 'reconciliation_identity' => hash('sha256', 'stale'), 'front_page' => false);
foreach (array($documents, array()) as $owners) {
    try {
        StylesheetScopes::assertOwned(array(array('scopes' => array($stale))), $owners);
        throw new RuntimeException('Stale scope was accepted.');
    } catch (InvalidArgumentException $exception) {
        $message = $exception->getMessage();
        foreach (array('"kind":"page"', '"route_path":"category/notes"', '"source_path":"category/notes.html"', array() === $owners ? 'missing' : '"kind":"archive"') as $detail) $assert(str_contains($message, $detail), 'Exception includes ' . $detail);
    }
}
echo "Final stylesheet scopes passed.\n";

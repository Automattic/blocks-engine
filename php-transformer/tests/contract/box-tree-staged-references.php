<?php
declare(strict_types=1);

// Exercises box-tree decisions, which are opt-in (#7352).
putenv('BLOCKS_ENGINE_BOX_TREE_INTAKE=1');

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\PayloadReader;

// A real import sends every file as a payload reference through the staged
// transport (shared -> pages -> serialized page plan -> worker). Stage
// projections rewrite route state in the source HTML (here: a current
// fragment link loses aria-current), so the page plan must keep the box tree
// the whole-artifact normalization accepted against the captured bytes.
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
};
$fixture = __DIR__ . '/../fixtures/box-tree';
$html = str_replace('<h1 ', '<a href="#top" aria-current="true">Top</a><h1 ', file_get_contents($fixture . '/index.html'));
$tree = json_decode(file_get_contents($fixture . '/index.json'), true, 32, JSON_THROW_ON_ERROR);
$files = array();
foreach (array('index', 'about') as $route) {
    $routeTree = $tree;
    $routeTree['source_path'] = "website/$route.html";
    $routeTree['source_hash'] = hash('sha256', $html);
    $files["website/$route.html"] = $html;
    $files["website/box-tree/$route.json"] = json_encode($routeTree, JSON_THROW_ON_ERROR);
}
$reader = new class implements PayloadReader {
    public array $payloads = array();
    public function read(array $reference): string { return $this->payloads[$reference['id']]; }
};
$artifact = array('entrypoint' => 'website/index.html', 'files' => array());
foreach ($files as $path => $bytes) {
    $reader->payloads[$path] = $bytes;
    $artifact['files'][] = array('path' => $path, 'payload_reference' => array(
        'schema' => 'blocks-engine/payload-reference/v1',
        'id' => $path, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
    ));
}

$inline = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $files))->toArray();
$assert(count($tree['nodes']) === $inline['source_reports']['box_tree']['website/index.html']['nodes_with_evidence'], 'Inline compilation accepts the route evidence.');

$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact, $reader);
$plans = $compiler->preparePages($artifact, $shared, $reader);
foreach ($plans as $pageId => $plan) {
    $source = array_values(array_filter($plan['artifact']['files'], static fn(array $file): bool => $pageId === $file['path']))[0];
    $assert(count($tree['nodes']) === count($source['box_tree']['nodes'] ?? array()), "Page plan $pageId carries its box tree.");
    $assert(!array_filter($plan['diagnostics'], static fn(array $row): bool => str_starts_with($row['code'], 'box_tree_')), "Page plan $pageId does not reject accepted evidence.");
    $assert($plan === $compiler->preparePage($artifact, $shared, $pageId, $reader), "Single-page preparation of $pageId matches the batch.");
}

// A separate worker receives only serialized plans.
$shared = json_decode(json_encode($shared, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
$plans = json_decode(json_encode($plans, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
$worker = new ArtifactCompiler();
$receipts = $worker->compilePreparedPages($shared, array_values($plans), $reader);
$staged = $worker->compose($shared, array_values($receipts), $reader)->toArray();
foreach (array('website/index.html', 'website/about.html') as $path) {
    $assert(count($tree['nodes']) === $staged['source_reports']['box_tree'][$path]['nodes_with_evidence'], "Staged import of $path reports its box-tree evidence.");
}
$assert($inline['source_reports']['box_tree'] === $staged['source_reports']['box_tree'], 'Staged and inline box-tree reports agree.');
$assert($inline['serialized_blocks'] === $staged['serialized_blocks'], 'Staged import applies the evidence exactly as inline compilation does.');

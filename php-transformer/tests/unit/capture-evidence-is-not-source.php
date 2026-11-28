<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Sidecars remain evidence even when intake is requested by an older caller.
putenv('BLOCKS_ENGINE_BOX_TREE_INTAKE=1');
$html = '<html><head><link rel="stylesheet" href="site.css"></head><body><main><h1 data-blocks-engine-node-id="n1">Hello</h1><p>Authored content.</p></main></body></html>';
$files = array('index.html' => $html, 'site.css' => 'h1{color:purple}');
$compile = static fn(array $files): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$plain = $compile($files);
$withEvidence = $compile($files + array('box-tree/index.json' => '{"schema":"irrelevant","nodes":{}}'));
foreach (array('pages', 'templates', 'template_parts', 'assets', 'theme') as $key) {
    if ($plain[$key] !== $withEvidence[$key]) throw new RuntimeException('Capture evidence changed ' . $key);
}
foreach ($withEvidence['pages'] as $page) {
    if (str_contains($page['canonical_block_markup'], 'data-blocks-engine-node-id')) throw new RuntimeException('Capture identity leaked into blocks.');
}
foreach ($withEvidence['assets'] as $asset) {
    if (str_contains($asset['source_path'] ?? '', 'box-tree/')) throw new RuntimeException('Capture evidence became an asset.');
}
// Fragment callers do not pass through document-envelope serialization. Both
// structured label attributes and serialized HTML parts must see a clean DOM,
// even without a usable sidecar.
$fragment = '<form><button name="category" style="border-radius:20px"><span class="caption">All</span></button><button name="icon" style="border-radius:20px"><svg viewBox="0 0 10 10"><path d="M0 0h10v10z"></path></svg>Choose</button></form><vendor-panel><p>Raw content</p></vendor-panel>';
$transform = static fn(string $source): array => (new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer())->transform($source)->toArray();
foreach (array($fragment, '<html><body>' . $fragment . '</body></html>') as $source) {
    $id = 0;
    $marked = preg_replace_callback('/<[a-z][a-z0-9-]*\b/i', static function (array $match) use (&$id): string {
        return $match[0] . ' data-blocks-engine-node-id="n' . ++$id . '"';
    }, $source);
    $clean = $transform($source);
    $captured = $transform($marked);
    $blocks = json_encode($captured['blocks'], JSON_THROW_ON_ERROR);
    if (!str_contains($blocks, 'contentParts') || !str_contains($blocks, '<svg')) {
        throw new RuntimeException('Fixture must copy source HTML into a block attribute.');
    }
    if (str_contains($blocks, 'data-blocks-engine-node-id') || str_contains($captured['serialized_blocks'], 'data-blocks-engine-node-id')) {
        throw new RuntimeException('Capture identity leaked through copied source markup.');
    }
    if ($clean['blocks'] !== $captured['blocks'] || $clean['serialized_blocks'] !== $captured['serialized_blocks']) {
        throw new RuntimeException('Removing capture identity changed other block bytes.');
    }
}
echo "Capture evidence is not source: passed\n";

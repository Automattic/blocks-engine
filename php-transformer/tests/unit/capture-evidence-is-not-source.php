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
echo "Capture evidence is not source: passed\n";

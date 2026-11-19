<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Distinct captured carriers can share base declarations even though their
// breakpoint overrides differ. Their doubled class is a specificity shim,
// not a structural selector excluded from declaration folding.
$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 512, JSON_THROW_ON_ERROR);
$html = str_replace(array('<p ', '</p>'), array('<h1 ', '</h1>'), $html);
$tree['source_hash'] = hash('sha256', $html);
$tree['nodes']['n7']['viewports']['mobile']['used_styles']['transform'] = 'translateX(20px)';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html,
    'box-tree/index.json' => json_encode($tree, JSON_THROW_ON_ERROR),
)))->toArray();
$css = '';
foreach ($result['source_reports']['wordpress_site_plan']['assets'] as $asset) {
    if ('engine-support' === ($asset['source'] ?? '')) $css .= $asset['content'];
}
if (1 !== preg_match('/(\.be-inline-geometry-[a-f0-9]+)\1,(\.be-inline-geometry-[a-f0-9]+)\2\{[^}]*display:block/', $css)) {
    throw new RuntimeException('Identical base declarations should share a body without changing the doubled carrier specificity: ' . $css);
}
if (!str_contains($css, 'translateX(20px)')) {
    throw new RuntimeException('Distinct breakpoint geometry must remain intact.');
}
echo "Box-tree support carrier compaction: ok\n";

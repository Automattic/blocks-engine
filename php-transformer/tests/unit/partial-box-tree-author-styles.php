<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 512, JSON_THROW_ON_ERROR);
// Exported wrappers may have no unique capture identity. The tree still
// validly describes the nearest observed children, but not the wrapper's box.
$html = str_replace('<h1 ', '<div class="export-frame"><h1 ', str_replace('</h1>', '</h1></div>', $html));
$html = str_replace('</head>', '<link rel="stylesheet" href="author.css"></head>', $html);
$tree['source_hash'] = hash('sha256', $html);
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html,
    'author.css' => '.export-frame{position:relative;height:200px}',
    'box-tree/index.json' => json_encode($tree),
)));
$compiled = $result->toArray();
$plan = $result->toWordPressSitePlanView()['wordpress_site_plan'];
if (empty($compiled['source_reports']['box_tree']['index.html']['nodes_with_evidence'])) {
    throw new RuntimeException('Partial box-tree evidence must remain accepted.');
}
$assets = array_column($plan['assets'], null, 'target_path');
$delivered = '';
foreach (ThemeBootstrap::documentStylesheets($plan, 'index.html') as $row) {
    $delivered .= $assets[$row['target_path']]['content'] ?? '';
}
if (!str_contains($delivered, 'position:relative') || !str_contains($delivered, 'height:200px')) {
    throw new RuntimeException('Unobserved export boxes still need their author layout rules.');
}
if (!empty($plan['pages'][0]['document_metadata']['engine_owned_styles'])) {
    throw new RuntimeException('Partial evidence cannot claim exclusive stylesheet ownership.');
}
echo "Partial box-tree author styles regression passed\n";

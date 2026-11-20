<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$html = str_replace('</head>', '<style>:root{--title:36px}h1{font-size:var(--title)}@media(min-width:768px){h1{font-size:60px}}</style></head>', $html);
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 512, JSON_THROW_ON_ERROR);
$tree['source_hash'] = hash('sha256', $html);
foreach ($tree['nodes']['n6']['viewports'] as $key => &$viewport) {
    $viewport['used_styles']['font-size'] = 'desktop' === $key ? '60px' : '36px';
}
unset($viewport);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html,
    'box-tree/index.json' => json_encode($tree, JSON_THROW_ON_ERROR),
)))->toWordPressSitePlanView()['wordpress_site_plan'];
$markup = $plan['pages'][0]['canonical_block_markup'];
if (str_contains($markup, 'blocks-engine-responsive-typography-')) throw new RuntimeException('Captured typography must not acquire a competing author rescue.');
if (!str_contains($markup, 'be-inline-geometry-')) throw new RuntimeException('Captured typography must retain its carrier.');
echo "PASS: Captured typography retains one owner.\n";

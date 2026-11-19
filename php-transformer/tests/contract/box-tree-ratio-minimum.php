<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A ratio-sized non-replaced box has a content-based automatic minimum.
// Dropping the captured zero minimum lets overflowing content enlarge it.
$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$html = str_replace('<h1 data-blocks-engine-node-id="n6">Hello</h1>', '<div class="ratio-box" data-blocks-engine-node-id="n6">Overflowing content</div>', $html);
$html = str_replace('</head>', '<link rel="stylesheet" href="site.css"></head>', $html);
$tree['source_hash'] = hash('sha256', $html);
foreach ($tree['nodes']['n6']['viewports'] as &$viewport) {
    $viewport['used_styles']['aspect-ratio'] = '5 / 1';
    $viewport['used_styles']['min-height'] = '0px';
}
unset($viewport);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html,
    'site.css' => '.ratio-box{aspect-ratio:5 / 1;min-height:0}',
    'box-tree/index.json' => json_encode($tree),
)))->toWordPressSitePlanView()['wordpress_site_plan'];
$css = implode("\n", array_column(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
if (!preg_match('/\{[^{}]*aspect-ratio:5 \/ 1[^{}]*\}/', $css, $rule)) throw new RuntimeException('Missing ratio carrier');
if (!str_contains($rule[0], 'min-height:0px')) throw new RuntimeException('Ratio carrier lost its explicit zero minimum: ' . $rule[0]);
echo "PASS: Public compiler preserves a ratio box's zero content minimum.\n";

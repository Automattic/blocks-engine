<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Computed file-link paint must bind even when a geometry carrier owns it.
$html = file_get_contents(dirname(__DIR__) . '/fixtures/box-tree/index.html');
$html = str_replace('<p data-blocks-engine-node-id="n7">Evidence only.</p>', '<a class="file-button" href="https://example.com/report.pdf" data-blocks-engine-node-id="n7">Report</a>', $html);
$tree = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/box-tree/index.json'), true, 512, JSON_THROW_ON_ERROR);
$html = str_replace('</head>', '<style>.file-button{background-color:#e1e4e6}</style></head>', $html);
$tree['source_hash'] = hash('sha256', $html);
foreach (array('desktop', 'mobile') as $viewport) {
    $styles = &$tree['nodes']['n7']['viewports'][$viewport]['used_styles'];
    $styles['display'] = 'inline-block';
    $styles['background-color'] = 'rgb(225, 228, 230)';
    unset($styles);
}
$artifact = array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html,
    'box-tree/index.json' => json_encode($tree, JSON_THROW_ON_ERROR),
));
$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact);
$pages = $compiler->preparePages($artifact, $shared);
// Workers receive JSON-serialized stage plans rather than live compiler state.
$shared = json_decode(json_encode($shared, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
$pages = json_decode(json_encode($pages, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
$worker = new ArtifactCompiler();
$receipts = $worker->compilePreparedPages($shared, $pages);
$result = $worker->compose($shared, $receipts);
$plan = $result->toWordPressSitePlanView()['wordpress_site_plan'];
$markup = $plan['pages'][0]['canonical_block_markup'];
if (1 !== preg_match('/<!-- wp:file (\{.*?\}) -->/', $markup, $match)) throw new RuntimeException('The file link remains core/file.');
$attrs = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
if (!str_contains($attrs['className'] ?? '', 'be-inline-geometry-')) throw new RuntimeException('The file carries box-tree geometry.');
if (0 !== $plan['theme']['design_system']['metrics']['literal_preset_color_count']) throw new RuntimeException('Observed file colour must bind, not remain a palette-matching literal.');
$theme = json_decode(array_column($plan['writes'], null, 'target_path')['theme.json']['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
$palette = array_column($theme['settings']['color']['palette'], 'slug', 'color');
$slug = $palette['#e1e4e6'] ?? null;
if (null === $slug || 'var:preset|color|' . $slug !== ($attrs['style']['color']['background'] ?? null)) throw new RuntimeException('The observed background names its exact palette preset.');
if (!str_contains($markup, 'background-color:var(--wp--preset--color--' . $slug . ')')) throw new RuntimeException('The rendered background reads the same colour inline.');
if (isset($attrs['backgroundColor']) || str_contains($markup, 'has-' . $slug . '-background-color')) throw new RuntimeException('Binding in place does not add an important preset class.');
echo "Staged file colour presets contract passed.\n";

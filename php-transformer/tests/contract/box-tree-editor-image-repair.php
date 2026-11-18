<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

// Issue #5920: a captured route with box-tree evidence ships only engine-owned
// styles, and site planning retires every author stylesheet it delivers. The
// editor repair that gives a hidden-at-rest icon image (a builder button's
// `display:none` arrow) a selectable box names only engine markup, so the
// evidence route must still deliver it to the editor.
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};

$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$html = str_replace('</head>', '<link data-blocks-engine-node-id="styles" rel="stylesheet" href="author.css"></head>', $html);
$html = str_replace('</main>', '<a data-blocks-engine-node-id="n8" class="button" href="/about/">Read more<div data-blocks-engine-node-id="n9" class="icon"><svg data-blocks-engine-node-id="n10" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16"><path data-blocks-engine-node-id="n11" d="M0 8h16"/></svg></div></a></main>', $html);
$tree['source_hash'] = hash('sha256', $html);
$tree['nodes']['n2']['children'][] = 'styles';
$tree['nodes']['styles'] = array('children' => array(), 'viewports' => $tree['nodes']['n3']['viewports']);
$tree['nodes']['n5']['children'][] = 'n8';
$tree['nodes']['n8'] = array('children' => array('n9'), 'viewports' => $tree['nodes']['n7']['viewports']);
$hidden = $tree['nodes']['n7']['viewports'];
foreach ($hidden as &$viewport) {
    $viewport['used_styles']['display'] = 'none';
    $viewport['border_box'] = array('x' => 0, 'y' => 0, 'width' => 0, 'height' => 0);
}
unset($viewport);
$tree['nodes']['n9'] = array('children' => array('n10'), 'viewports' => $hidden);
$tree['nodes']['n10'] = array('children' => array('n11'), 'viewports' => $hidden);
$tree['nodes']['n11'] = array('children' => array(), 'viewports' => $hidden);

$files = array(
    'index.html' => $html,
    'author.css' => '.button{display:flex;gap:8px}.icon{display:none;width:16px;height:16px}.icon svg{display:block;width:16px;height:16px}',
    'box-tree/index.json' => json_encode($tree),
);
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files));
$compiled = $result->toArray();
$plan = $result->toWordPressSitePlanView()['wordpress_site_plan'];
$assert(!empty($plan['pages'][0]['document_metadata']['engine_owned_styles']), 'The route ships engine-owned styles.');
$assert(str_contains($compiled['serialized_blocks'], 'blocks-engine-synthetic-image-figure'), 'The hidden icon is a native, synthetic image block.');

$repair = 'figure.blocks-engine-synthetic-image-figure.wp-block-image{visibility:visible!important}';
$assets = array_column($plan['assets'], null, 'target_path');
$editor = '';
$frontend = '';
foreach (ThemeBootstrap::documentStylesheets($plan, 'index.html') as $row) {
    $content = (string) ($assets[$row['target_path']]['content'] ?? '');
    if ('frontend' !== $row['stylesheet_target']) $editor .= $content;
    if ('editor' !== $row['stylesheet_target']) $frontend .= $content;
}
$assert(str_contains($editor, $repair) && str_contains($editor, '.wp-block-group:has(>figure.blocks-engine-synthetic-image-figure.wp-block-image){display:block!important'), 'The evidence route delivers the editor repair that gives the hidden image a selectable box.');
$assert(!str_contains($frontend, $repair), 'The repair never reaches the front end.');
$assert(!str_contains($editor . $frontend, '.icon{display:none'), 'Author selector bytes stay retired on the evidence route.');

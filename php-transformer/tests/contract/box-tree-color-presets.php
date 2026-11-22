<?php
declare(strict_types=1);

// Exercises box-tree decisions, which are opt-in (#7352).
putenv('BLOCKS_ENGINE_BOX_TREE_INTAKE=1');

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A block can carry the colour the browser rendered for its source node
// (box-tree evidence the static cascade never saw, serialized as computed
// `rgb()` or `color(srgb …)`). It is painted all the same, so it needs an
// exact editable palette token, bound in place so nothing else changes.
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$html = file_get_contents(dirname(__DIR__) . '/fixtures/box-tree/index.html');
$tree = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/box-tree/index.json'), true, 512, JSON_THROW_ON_ERROR);
foreach (array('desktop', 'mobile') as $viewport) {
    $styles = &$tree['nodes']['n5']['viewports'][$viewport]['used_styles'];
    $styles['color'] = 'rgb(0, 0, 238)';
    $styles['background-color'] = 'color(srgb 0.545098 0.396078 0.133333)';
    unset($styles);
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html,
    'box-tree/index.json' => json_encode($tree, JSON_THROW_ON_ERROR),
)))->toWordPressSitePlanView()['wordpress_site_plan'];
$themeWrite = array_column($plan['writes'], null, 'target_path')['theme.json'];
$theme = json_decode($themeWrite['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
$palette = array_column($theme['settings']['color']['palette'] ?? array(), 'slug', 'color');
$markup = $plan['pages'][0]['canonical_block_markup'];
$assert(1 === preg_match('/<!-- wp:group (\{.*?\}) -->\s*<main([^>]*)>/', $markup, $match), 'The observed container remains a native group.');
$attrs = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
foreach (array('text' => array('#0000ee', 'color'), 'background' => array('#8b6522', 'background-color')) as $key => [$hex, $property]) {
    $assert(isset($palette[$hex]), "The observed {$key} colour gets an exact editable palette token.");
    $slug = $palette[$hex];
    $assert('var:preset|color|' . $slug === ($attrs['style']['color'][$key] ?? null), "The observed {$key} colour binds to its exact token.");
    // In place, not as an !important preset class: a state or media rule
    // still resolves against the same inline declaration.
    $assert(str_contains($match[2], $property . ':var(--wp--preset--color--' . $slug . ')'), "The {$key} declaration stays inline, reading the token.");
    $assert(!str_contains($match[2], 'has-' . $slug . '-'), "The {$key} token adds no preset class.");
}
$assert(!isset($attrs['textColor']) && !isset($attrs['backgroundColor']), 'Block-only colours do not become preset attributes.');
echo "Box-tree colour presets contract passed.\n";

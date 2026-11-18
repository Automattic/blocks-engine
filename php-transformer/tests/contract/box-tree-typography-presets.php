<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$html = file_get_contents(dirname(__DIR__) . '/fixtures/box-tree/index.html');
$tree = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/box-tree/index.json'), true, 512, JSON_THROW_ON_ERROR);
foreach (array('n6' => array('Display Face v2', '111.375px'), 'n7' => array('Text Face v2', '15.75px')) as $node => [$family, $size]) {
    foreach (array('desktop', 'mobile') as $viewport) {
        $styles = &$tree['nodes'][$node]['viewports'][$viewport]['used_styles'];
        $styles['font-family'] = $family;
        $styles['font-size'] = 'mobile' === $viewport ? '12px' : $size;
        unset($styles);
    }
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html,
    'box-tree/index.json' => json_encode($tree, JSON_THROW_ON_ERROR),
)))->toWordPressSitePlanView()['wordpress_site_plan'];
$themeWrite = array_column($plan['writes'], null, 'target_path')['theme.json'];
$theme = json_decode($themeWrite['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
$families = array_column($theme['settings']['typography']['fontFamilies'] ?? array(), 'slug', 'fontFamily');
$sizes = array_column($theme['settings']['typography']['fontSizes'] ?? array(), 'slug', 'size');
$markup = $plan['pages'][0]['canonical_block_markup'];
foreach (array('heading' => 'Display Face v2', 'paragraph' => 'Text Face v2') as $block => $family) {
    $assert(isset($families[$family]), 'Observed font families absent from static CSS get exact editable presets.');
    $assert(1 === preg_match('/<!-- wp:' . $block . ' (\{.*?\}) -->/', $markup, $match), 'Observed text remains a native block.');
    $attrs = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
    $assert('var:preset|font-family|' . $families[$family] === ($attrs['style']['typography']['fontFamily'] ?? null), 'Observed font family binds inline to its exact preset.');
    // A core preset size class is important and would freeze the captured
    // responsive typography, which stays on its generated carrier.
    $assert(!isset($attrs['fontSize']), 'Responsive sizes must not acquire an important preset class.');
}
$css = implode('\n', array_column($plan['assets'], 'content'));
$assert(str_contains($css, '@media (max-width:390px)') && str_contains($css, 'font-size:12px'), 'The captured mobile typography remains active.');
// Binding names values; it never rewrites the captured carrier rules.
$assert(!str_contains($css, 'font-size:12px !important') && !str_contains($css, 'font-size:111.375px !important'), 'Captured carrier sizes keep their source priority.');
echo "Box-tree typography presets contract passed.\n";

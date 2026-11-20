<?php
declare(strict_types=1);

/**
 * The extra viewport one route sampled leaves a responsive projection hook on
 * that route's header only.
 *
 * The cart route's sidecar adds a `mobile` viewport, and its capture tags the
 * header's nav wrapper with a projection hook naming its width-scoped rule.
 * The other routes carry no hook, so the cart's header reads as a different
 * source group. Its box tree records every route as a peer of the header and
 * agrees on the viewport they share, so the cart joins the one shared header
 * and is never excluded as non-equivalent. The same hook without box-tree
 * peer evidence may select distinct responsive rules and keeps its own part.
 */

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$styles = static function (array $overrides = array()): array {
    $styles = array();
    foreach (BoxTree::PROPERTIES as $property) $styles[$property] = 'auto';
    return array_replace($styles, array(
        'display' => 'block', 'position' => 'static', 'box-sizing' => 'content-box', 'overflow-x' => 'visible', 'overflow-y' => 'visible',
        'padding-top' => '0px', 'padding-right' => '0px', 'padding-bottom' => '0px', 'padding-left' => '0px',
        'margin-top' => '0px', 'margin-right' => '0px', 'margin-bottom' => '0px', 'margin-left' => '0px',
        'row-gap' => 'normal', 'column-gap' => 'normal', 'flex-direction' => 'row', 'flex-wrap' => 'nowrap',
        'justify-content' => 'normal', 'align-items' => 'normal', 'align-content' => 'normal', 'flex-grow' => '0', 'flex-shrink' => '1', 'order' => '0',
        'grid-template-columns' => 'none', 'grid-template-rows' => 'none', 'grid-auto-flow' => 'row', 'justify-items' => 'normal',
        'background-color' => 'rgba(0, 0, 0, 0)', 'background-image' => 'none',
        'border-top' => '0px none rgb(0, 0, 0)', 'border-right' => '0px none rgb(0, 0, 0)', 'border-bottom' => '0px none rgb(0, 0, 0)', 'border-left' => '0px none rgb(0, 0, 0)',
        'border-radius' => '0px', 'outline' => 'rgb(0, 0, 0) none 0px', 'box-shadow' => 'none', 'mask-image' => 'none', 'mask-mode' => 'match-source',
        'filter' => 'none', 'opacity' => '1', 'mix-blend-mode' => 'normal', 'transform' => 'none', 'max-width' => 'none', 'max-height' => 'none',
        'min-width' => '0px', 'min-height' => '0px', 'color' => 'rgb(0, 0, 0)', 'font-family' => 'sans-serif', 'font-size' => '16px', 'font-weight' => '400',
        'font-style' => 'normal', 'line-height' => 'normal', 'letter-spacing' => 'normal', 'word-spacing' => '0px', 'text-align' => 'start',
        'text-transform' => 'none', 'text-indent' => '0px', 'text-decoration-line' => 'none', 'text-decoration-color' => 'rgb(0, 0, 0)',
        'text-decoration-style' => 'solid', 'text-shadow' => 'none', 'white-space' => 'normal', 'word-break' => 'normal', 'overflow-wrap' => 'normal',
        'text-overflow' => 'clip', 'vertical-align' => 'baseline', 'list-style-type' => 'disc', 'list-style-position' => 'outside',
        'float' => 'none', 'clear' => 'none', 'visibility' => 'visible', 'object-fit' => 'fill', 'object-position' => '50% 50%',
        'transform-origin' => '0px 0px', 'clip-path' => 'none', 'backdrop-filter' => 'none', 'background-position-x' => '0%',
        'background-position-y' => '0%', 'background-repeat' => 'repeat', 'background-clip' => 'border-box', 'background-origin' => 'padding-box',
        'background-attachment' => 'scroll', 'background-blend-mode' => 'normal', 'transition-duration' => '0s', 'transition-property' => 'all', 'animation-name' => 'none',
    ), $overrides);
};
$observe = static fn(float $x, float $y, float $width, float $height, array $overrides = array()): array => array(
    'border_box' => array('x' => $x, 'y' => $y, 'width' => $width, 'height' => $height),
    'used_styles' => $styles($overrides + array('width' => $width . 'px', 'height' => $height . 'px')),
);

$hook = 'data-blocks-engine-capture-responsive-0a1b2c3d4e5f';
$routes = array(
    'index.html' => array('/', 'Home'),
    'services/index.html' => array('/services/', 'Services'),
    'team/index.html' => array('/team/', 'Team'),
    'cart/index.html' => array('/cart/', 'Cart'),
);
$build = static function (bool $withBoxTrees) use ($routes, $hook, $observe): array {
    $files = array(
        array('path' => 'site.css', 'content' => '.site-header{background:#0c2238}.header-inner{display:flex;justify-content:space-between}@media (max-width:390px){.' . $hook . '{padding-top:20px;background:#f0f0f0}}'),
    );
    foreach ($routes as $path => [$url, $title]) {
        $mobile = 'cart/index.html' === $path;
        $items = '';
        foreach ($routes as [$href, $label]) $items .= '<li><a href="' . $href . '"' . ($href === $url ? ' aria-current="page"' : '') . '>' . $label . '</a></li>';
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $title . ' | Salon</title><link rel="stylesheet" href="/site.css"></head><body data-blocks-engine-node-id="b">'
            . '<header id="header" class="site-header" data-blocks-engine-node-id="h"><div class="header-inner" data-blocks-engine-node-id="hi"><a class="brand" href="/">Salon</a>'
            . '<div class="header-nav-wrapper' . ($mobile ? ' ' . $hook : '') . '" data-blocks-engine-node-id="hw"><nav class="header-nav" data-blocks-engine-node-id="hn"><ul>' . $items . '</ul></nav></div></div></header>'
            . '<main data-blocks-engine-node-id="m"><div class="page-body" data-blocks-engine-node-id="pb"><h1>' . $title . '</h1><p>Body text for ' . $title . '.</p></div></main>'
            . '<footer class="site-footer"><p>30 Main Street | Open Tuesday to Saturday</p></footer></body></html>';
        $files[] = array('path' => $path, 'content' => $html);
        if (!$withBoxTrees) continue;
        $desktop = array(
            'b' => $observe(0, 0, 1440, 900),
            'h' => $observe(0, 0, 1440, 80, array('background-color' => 'rgb(12, 34, 56)')),
            'hi' => $observe(0, 0, 1440, 80, array('display' => 'flex', 'justify-content' => 'space-between')),
            'hw' => $observe(800, 20, 640, 40),
            'hn' => $observe(800, 20, 640, 40),
            'm' => $observe(0, 80, 1440, 400),
            'pb' => $observe(0, 80, 1440, 400),
        );
        $phone = array(
            'b' => $observe(0, 0, 390, 844),
            'h' => $observe(0, 0, 390, 120, array('background-color' => 'rgb(12, 34, 56)')),
            'hi' => $observe(0, 0, 390, 120, array('display' => 'flex', 'flex-direction' => 'column')),
            'hw' => $observe(0, 40, 390, 80, array('padding-top' => '20px', 'background-color' => 'rgb(240, 240, 240)')),
            'hn' => $observe(0, 60, 390, 60),
            'm' => $observe(0, 120, 390, 600),
            'pb' => $observe(0, 120, 390, 600),
        );
        $children = array('b' => array('h', 'm'), 'h' => array('hi'), 'hi' => array('hw'), 'hw' => array('hn'), 'hn' => array(), 'm' => array('pb'), 'pb' => array());
        $nodes = array();
        foreach ($children as $id => $ids) $nodes[$id] = array('children' => $ids, 'viewports' => array('desktop' => $desktop[$id]) + ($mobile ? array('mobile' => $phone[$id]) : array()));
        $tree = array('schema' => BoxTree::SCHEMA, 'source_path' => $path, 'source_hash' => hash('sha256', $html), 'viewports' => array('desktop' => array('width' => 1440, 'height' => 900)) + ($mobile ? array('mobile' => array('width' => 390, 'height' => 844)) : array()), 'nodes' => $nodes);
        $files[] = array('path' => 'box-tree/' . ('index.html' === $path ? 'home' : str_replace('/index.html', '', $path)) . '.json', 'content' => json_encode($tree, JSON_THROW_ON_ERROR));
    }
    return $files;
};
$compile = static function (array $files) use ($assert): array {
    $compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'provenance' => array('source_url' => 'https://example.test/'), 'files' => $files));
    $assert(!array_filter($compiled->toArray()['diagnostics'], static fn(array $d): bool => str_starts_with((string) $d['code'], 'box_tree_')), 'The sidecars are accepted by the compiler.');
    $plan = $compiled->toWordPressSitePlanView()['wordpress_site_plan'];
    $headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
    return array($plan, $headers);
};

// With peer evidence the cart joins the one shared header.
[$plan, $headers] = $compile($build(true));
$assert(1 === count($headers), 'One header part serves every route: ' . implode(',', array_column($plan['template_parts'], 'slug')));
foreach (array_keys($routes) as $path) $assert(isset($headers[0]['provenance']['sources'][$path]), 'The shared header serves ' . $path . ': ' . json_encode($headers[0]['provenance']));
foreach ($plan['diagnostics'] as $diagnostic) {
    if ('header' !== ($diagnostic['area'] ?? null)) continue;
    foreach ($diagnostic['exclusions'] ?? array() as $exclusion) $assert('non_equivalent' !== ($exclusion['reason'] ?? null), 'No route is excluded from the shared header: ' . json_encode($diagnostic));
}
$count = static fn(array $plan, string $slug): int => count(array_filter($plan['templates'], static fn(array $template): bool => str_contains((string) $template['canonical_block_markup'], '"slug":"' . $slug . '"')));
$assert($count($plan, $headers[0]['slug']) > 0 && $count($plan, $headers[0]['slug']) === $count($plan, 'footer'), 'Every template referencing the footer references the shared header.');
foreach ($plan['pages'] as $page) $assert(!str_contains((string) $page['canonical_block_markup'], 'wp:template-part'), 'Page content carries no template-part block: ' . $page['source_path']);

// Without peer evidence the hook may select distinct responsive rules: the
// cart keeps its own header rather than joining the shared part.
[$plan, $headers] = $compile($build(false));
$assert(1 === count($headers) && !isset($headers[0]['provenance']['sources']['cart/index.html']), 'Without peer evidence the hooked header does not join the shared part: ' . json_encode(array_column($headers, 'provenance')));
$excluded = array();
foreach ($plan['diagnostics'] as $diagnostic) {
    if ('header' !== ($diagnostic['area'] ?? null)) continue;
    foreach ($diagnostic['exclusions'] ?? array() as $exclusion) $excluded[$exclusion['source_path'] ?? ''] = $exclusion['reason'] ?? null;
}
$assert(array('cart/index.html' => 'non_equivalent') === $excluded, 'Without peer evidence the hooked header stays distinct: ' . json_encode($excluded));
echo "Shared header with a route-only projection hook: passed\n";

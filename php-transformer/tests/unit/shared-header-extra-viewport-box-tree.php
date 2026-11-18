<?php
declare(strict_types=1);

/**
 * One route's box tree samples an extra viewport the other routes lack.
 *
 * Producers can sample mobile HTML per template cluster, so one route's
 * sidecar adds a `mobile` viewport while the others observe `desktop` only.
 * The header is identical on every route and agrees on the viewport they all
 * observed. The mobile observation alone (a nav wrapper that pads and paints
 * around its nav at 390px) must not fork the header into a route-local part,
 * nor its generated `be-inline-geometry-*` classes: every
 * route shares one header part, templates reference it, and no page content
 * carries template-part blocks. The route's page content keeps its mobile
 * evidence. The route also loads other landmark stylesheets; its agreeing
 * observations still admit it to the isolated shared header, so it is never
 * excluded as non-equivalent and the part carries the mobile geometry.
 */

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\BoxTreeReport;
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

$routes = array(
    'index.html' => array('/', 'Home'),
    'services/index.html' => array('/services/', 'Services'),
    'team/index.html' => array('/team/', 'Team'),
    'cart/index.html' => array('/cart/', 'Cart'),
);
// A component stylesheet that names the header loads on every route but the
// cart, which loads another build of it, so the header's booking control
// compiles route-seeded markers. Only the cart's observed agreement proves
// its header renders alike.
$files = array(
    array('path' => 'components.css', 'content' => '#header .component-badge{outline:1px solid red}#header .btn:hover{opacity:.8}'),
    array('path' => 'components-cart.css', 'content' => '#header .component-badge{outline:1px solid red}#header .btn:hover{opacity:.85}'),
    array('path' => 'site.css', 'content' => '.btn{display:inline-block;padding:10px 20px;border-radius:30px;background:#000;color:#fff}'),
);
foreach ($routes as $path => [$url, $title]) {
    $mobile = 'cart/index.html' === $path;
    $items = '';
    foreach ($routes as [$href, $label]) $items .= '<li><a href="' . $href . '"' . ($href === $url ? ' aria-current="page"' : '') . '>' . $label . '</a></li>';
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $title . ' | Salon</title>' . ($mobile ? '<link rel="stylesheet" href="/components-cart.css">' : '<link rel="stylesheet" href="/components.css">') . '<link rel="stylesheet" href="/site.css">' . '</head><body data-blocks-engine-node-id="b"><div id="siteWrapper" class="site-wrapper" data-blocks-engine-node-id="sw">'
        . '<div class="utility" data-blocks-engine-node-id="u"><a href="/cart/">Basket</a></div>'
        . '<header id="header" class="site-header" data-blocks-engine-node-id="h"><div class="header-inner" data-blocks-engine-node-id="hi"><a class="brand" href="/">Salon</a><a class="btn" href="/book/">Book</a>'
        . '<div class="header-nav-wrapper" data-blocks-engine-node-id="hw"><nav class="header-nav" data-blocks-engine-node-id="hn"><ul>' . $items . '</ul></nav></div></div></header>'
        . '<main data-blocks-engine-node-id="m"><div class="page-body" data-blocks-engine-node-id="pb"><h1>' . $title . '</h1><p>Body text for ' . $title . '.</p></div></main>'
        . '<footer class="site-footer"><p>30 Main Street | Open Tuesday to Saturday</p></footer></div></body></html>';
    $files[] = array('path' => $path, 'content' => $html);
    // Every route observes the same desktop geometry. The cart route alone
    // also samples mobile, where the nav wrapper pads and paints around its nav.
    $desktop = array(
        'b' => $observe(0, 0, 1440, 900),
        'sw' => $observe(0, 0, 1440, 900),
        'u' => $observe(1400, 0, 40, 40, array('position' => 'fixed')),
        'h' => $observe(0, 0, 1440, 80, array('background-color' => 'rgb(12, 34, 56)', 'padding-top' => '20px', 'padding-bottom' => '20px')),
        'hi' => $observe(0, 20, 1440, 40, array('display' => 'flex', 'justify-content' => 'space-between')),
        'hw' => $observe(800, 20, 640, 40),
        'hn' => $observe(800, 20, 640, 40),
        'm' => $observe(0, 80, 1440, 400),
        'pb' => $observe(0, 80, 1440, 400, array('padding-top' => '40px')),
    );
    $phone = array(
        'b' => $observe(0, 0, 390, 844),
        'sw' => $observe(0, 0, 390, 844),
        'u' => $observe(350, 0, 40, 40, array('position' => 'fixed')),
        'h' => $observe(0, 0, 390, 120, array('background-color' => 'rgb(12, 34, 56)', 'padding-top' => '20px', 'padding-bottom' => '20px')),
        'hi' => $observe(0, 20, 390, 80, array('display' => 'flex', 'flex-direction' => 'column')),
        'hw' => $observe(0, 40, 390, 60, array('padding-top' => '20px', 'background-color' => 'rgb(240, 240, 240)')),
        'hn' => $observe(0, 60, 390, 40),
        'm' => $observe(0, 120, 390, 600),
        'pb' => $observe(0, 120, 390, 600, array('padding-top' => '16px')),
    );
    $children = array('b' => array('sw'), 'sw' => array('u', 'h', 'm'), 'u' => array(), 'h' => array('hi'), 'hi' => array('hw'), 'hw' => array('hn'), 'hn' => array(), 'm' => array('pb'), 'pb' => array());
    $nodes = array();
    foreach ($children as $id => $ids) $nodes[$id] = array('children' => $ids, 'viewports' => array('desktop' => $desktop[$id]) + ($mobile ? array('mobile' => $phone[$id]) : array()));
    $tree = array('schema' => BoxTree::SCHEMA, 'source_path' => $path, 'source_hash' => hash('sha256', $html), 'viewports' => array('desktop' => array('width' => 1440, 'height' => 900)) + ($mobile ? array('mobile' => array('width' => 390, 'height' => 844)) : array()), 'nodes' => $nodes);
    $files[] = array('path' => 'box-tree/' . str_replace('/index.html', '', $path === 'index.html' ? 'home' : $path) . '.json', 'content' => json_encode($tree, JSON_THROW_ON_ERROR));
}

// Intake keeps the cart route's mobile evidence for its page content and
// shares its header's extra viewport with the other routes.
$intake = BoxTreeReport::read(array_map(static fn(array $file): array => $file + array('kind' => str_ends_with($file['path'], '.html') ? 'html' : 'json'), array_slice($files, 1)));
$assert(array() === $intake['diagnostics'], 'Every sidecar is accepted: ' . json_encode($intake['diagnostics']));
$cart = $intake['trees']['cart/index.html'];
$assert(isset($cart['viewports']['mobile'], $cart['nodes']['m']['viewports']['mobile'], $cart['nodes']['pb']['viewports']['mobile']), 'Page content keeps its mobile evidence.');
foreach ($intake['trees'] as $path => $tree) {
    $assert(array_keys($routes) === array_values(array_intersect(array_keys($routes), $tree['chrome_peers']['h'] ?? array())), 'Every route is an observed peer of the header on ' . $path);
    foreach (array('u', 'h', 'hi', 'hw', 'hn') as $id) $assert(isset($tree['nodes'][$id]['viewports']['mobile']), 'Shared header keeps mobile evidence on ' . $path);
    if ('cart/index.html' !== $path) $assert(!isset($tree['nodes']['pb']['viewports']['mobile']), 'Other page content does not inherit mobile evidence.');
}

$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'provenance' => array('source_url' => 'https://example.test/'), 'files' => $files));
$assert(!array_filter($compiled->toArray()['diagnostics'], static fn(array $d): bool => str_starts_with((string) $d['code'], 'box_tree_')), 'The sidecars are accepted by the compiler.');
$geometry = array();
foreach ($compiled->toArray()['source_reports']['compiled_site']['pages'] as $page) {
    $header = preg_match('/<header\b.*<\/header>/s', (string) $page['block_markup'], $match) ? $match[0] : '';
    preg_match_all('/be-inline-geometry-[a-f0-9-]+/', $header, $classes);
    $geometry[$page['source_path']] = array_values(array_unique($classes[0]));
}
$assert(array() !== $geometry['index.html'] && 1 === count(array_unique(array_map('serialize', $geometry))), 'Every route compiles its header with the same geometry classes: ' . json_encode($geometry));
// Its agreeing observations admit the cart to the isolated shared header
// compile, though it loads another build of the header's stylesheet.
$isolated = array_values(array_filter($compiled->toArray()['source_reports']['compiled_site']['inline_shell_artifacts'] ?? array(), static fn(array $artifact): bool => 'header' === ($artifact['area'] ?? null)));
$assert(1 === count($isolated) && in_array('cart/index.html', $isolated[0]['placement']['source_paths'] ?? array(), true), 'The isolated shared header serves the cart: ' . json_encode(array_column($isolated, 'placement')));
$plan = $compiled->toWordPressSitePlanView()['wordpress_site_plan'];
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers), 'One header part serves every route: ' . implode(',', array_column($plan['template_parts'], 'slug')));
$assert(isset($headers[0]['provenance']['sources']['cart/index.html']), 'The route with the extra viewport joins the shared header: ' . json_encode($headers[0]['provenance']));
foreach ($plan['diagnostics'] as $diagnostic) {
    if ('header' !== ($diagnostic['area'] ?? null)) continue;
    foreach ($diagnostic['exclusions'] ?? array() as $exclusion) $assert('non_equivalent' !== ($exclusion['reason'] ?? null), 'No route is excluded from the shared header: ' . json_encode($diagnostic));
}
$referencing = 0;
foreach ($plan['templates'] as $template) if (str_contains((string) $template['canonical_block_markup'], '"slug":"' . $headers[0]['slug'] . '"')) ++$referencing;
$footerReferences = 0;
foreach ($plan['templates'] as $template) if (str_contains((string) $template['canonical_block_markup'], '"slug":"footer"')) ++$footerReferences;
$assert($referencing > 0 && $referencing === $footerReferences, 'Every template referencing the footer references the shared header.');
preg_match_all('/be-inline-geometry-[a-f0-9-]+/', $headers[0]['canonical_block_markup'], $headerClasses);
$css = implode("\n", array_column($plan['assets'], 'content'));
$mobileRules = false;
foreach (array_unique($headerClasses[0]) as $class) {
    if (preg_match('/@media[^{}]*max-width:\\s*390px[^{}]*\\{[^@]*\\.' . preg_quote($class, '/') . '\\s*\\{/s', $css)) $mobileRules = true;
}
$assert($mobileRules, 'The shared header geometry has mobile media-query rules.');
foreach ($plan['pages'] as $page) {
    $assert(!str_contains((string) $page['canonical_block_markup'], 'wp:template-part'), 'Page content carries no template-part block: ' . $page['source_path']);
}
echo "Shared header with an extra route viewport: passed\n";

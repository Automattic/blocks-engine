<?php
declare(strict_types=1);

/**
 * Capture stamps every observed node, inline SVG icons included, with a
 * route-local box-tree node id. A footer icon drawn identically on every
 * route therefore carries a different id on each one. The id styles nothing
 * inside a standalone image, so it must not enter the materialized asset's
 * content address: otherwise each route gets its own icon file and token, and
 * the footers around it read as different shells and split into several parts.
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

// The icon's node ids differ per route, as a capture assigns them in document order.
$routes = array('index.html' => array('Home', 229), 'about/index.html' => array('About', 116), 'menu/index.html' => array('Menu', 318));
$files = array(array('path' => 'site.css', 'content' => 'footer{color:rgb(240,235,225)}.h-4{height:1rem}.w-4{width:1rem}svg{display:block}'));
foreach ($routes as $path => [$title, $icon]) {
    $svg = 'n' . $icon;
    $shape = 'n' . ($icon + 1);
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $title . '</title><link rel="stylesheet" href="/site.css"></head><body data-blocks-engine-node-id="b">'
        . '<main data-blocks-engine-node-id="m"><h1>' . $title . '</h1><p>Body text for ' . $title . '.</p></main>'
        . '<footer class="site-footer" data-blocks-engine-node-id="f"><p>Ready to plan your event?</p><a href="https://facebook.example/">'
        . '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="lucide lucide-facebook h-4 w-4" data-blocks-engine-node-id="' . $svg . '">'
        . '<path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z" data-blocks-engine-node-id="' . $shape . '"></path></svg></a>'
        . '<p>Copyright Example</p></footer></body></html>';
    $files[] = array('path' => $path, 'content' => $html);
    $nodes = array(
        'b' => array('children' => array('m', 'f'), 'viewports' => array('desktop' => $observe(0, 0, 1440, 900))),
        'm' => array('children' => array(), 'viewports' => array('desktop' => $observe(0, 0, 1440, 600))),
        'f' => array('children' => array($svg), 'viewports' => array('desktop' => $observe(0, 600, 1440, 200, array('color' => 'rgb(240, 235, 225)')))),
        $svg => array('children' => array($shape), 'viewports' => array('desktop' => $observe(0, 650, 16, 16, array('color' => 'rgb(240, 235, 225)')))),
        $shape => array('children' => array(), 'viewports' => array('desktop' => $observe(0, 650, 16, 16, array('color' => 'rgb(240, 235, 225)')))),
    );
    $tree = array('schema' => BoxTree::SCHEMA, 'source_path' => $path, 'source_hash' => hash('sha256', $html), 'viewports' => array('desktop' => array('width' => 1440, 'height' => 900)), 'nodes' => $nodes);
    $files[] = array('path' => 'box-tree/' . ('index.html' === $path ? 'home' : dirname($path)) . '.json', 'content' => json_encode($tree, JSON_THROW_ON_ERROR));
}

$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'provenance' => array('source_url' => 'https://example.test/'), 'files' => $files));
$assert(!array_filter($compiled->toArray()['diagnostics'], static fn(array $d): bool => str_starts_with((string) $d['code'], 'box_tree_')), 'The sidecars are accepted by the compiler.');
$plan = $compiled->toWordPressSitePlanView()['wordpress_site_plan'];
$icons = array_values(array_filter($plan['assets'], static fn(array $asset): bool => 'inline-svg' === ($asset['source'] ?? null)));
$assert(1 === count($icons), 'One icon drawn on every route is one materialized SVG asset: ' . json_encode(array_column($icons, 'source_path')));
foreach ($icons as $asset) $assert(!str_contains((string) ($asset['content'] ?? ''), BoxTree::NODE_ATTRIBUTE), 'A materialized SVG asset carries no route-local box-tree node id.');
$footers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'footer' === ($part['area'] ?? null) || 'footer' === ($part['slug'] ?? null)));
$assert(1 === count($footers), 'One footer part serves every route: ' . implode(',', array_column($plan['template_parts'], 'slug')));
foreach ($plan['diagnostics'] as $diagnostic) {
    if ('footer' !== ($diagnostic['area'] ?? null)) continue;
    foreach ($diagnostic['exclusions'] ?? array() as $exclusion) $assert('non_equivalent' !== ($exclusion['reason'] ?? null), 'No route is excluded from the shared footer: ' . json_encode($diagnostic));
}
foreach ($plan['templates'] as $template) {
    $assert(str_contains((string) $template['canonical_block_markup'], '"slug":"' . $footers[0]['slug'] . '"'), 'Template ' . $template['slug'] . ' references the shared footer.');
}
foreach ($plan['pages'] as $page) {
    $assert(!str_contains((string) $page['canonical_block_markup'], 'Copyright Example'), 'Route ' . $page['source_path'] . ' uses the shared footer rather than a route-local copy.');
}
echo "Shared footer with route-local inline SVG node ids: passed\n";

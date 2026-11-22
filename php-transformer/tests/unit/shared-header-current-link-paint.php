<?php
declare(strict_types=1);

// Exercises box-tree decisions, which are opt-in (#7352).
putenv('BLOCKS_ENGINE_BOX_TREE_INTAKE=1');

/**
 * A shared header's current-link paint is route state. Each route marks its
 * own link current and paints it with an underline; the one header part that
 * serves every route must not pin the compiled route's underline on its link.
 * The underline projects under WordPress's per-request `aria-current`.
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

$underline = array('background-image' => 'linear-gradient(rgb(54, 48, 42), rgb(54, 48, 42))', 'background-size' => '1px 1px', 'background-repeat' => 'repeat-x', 'background-position-y' => 'calc(100% - 2px)');
$routes = array('index.html' => '/', 'services/index.html' => '/services/', 'team/index.html' => '/team/');
$labels = array('/' => 'Home', '/services/' => 'Services', '/team/' => 'Team');
$files = array(array('path' => 'site.css', 'content' => '.site-header{display:flex}.nav{display:flex}.nav .item{margin:0 6px}.nav a{display:block;padding-top:2px}.nav a[aria-current]{background-image:linear-gradient(#36302a,#36302a);background-size:1px 1px;background-repeat:repeat-x;background-position:0 calc(100% - 2px)}'));
foreach ($routes as $path => $url) {
    $links = '';
    $index = 0;
    foreach ($labels as $href => $label) {
        $index++;
        $links .= '<div class="item" data-blocks-engine-node-id="i' . $index . '"><a href="' . $href . '" data-blocks-engine-node-id="a' . $index . '"' . ($href === $url ? ' aria-current="page"' : '') . '>' . $label . '</a></div>';
    }
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $labels[$url] . ' | Salon</title><link rel="stylesheet" href="/site.css"></head><body data-blocks-engine-node-id="b">'
        . '<header class="site-header" data-blocks-engine-node-id="h"><nav class="nav" data-blocks-engine-node-id="n">' . $links . '</nav></header>'
        . '<main data-blocks-engine-node-id="m"><h1>' . $labels[$url] . '</h1><p>Body text for ' . $labels[$url] . '.</p></main>'
        . '<footer class="site-footer"><p>30 Main Street</p></footer></body></html>';
    $files[] = array('path' => $path, 'content' => $html);
    $viewports = array(
        'b' => $observe(0, 0, 1440, 900),
        'h' => $observe(0, 0, 1440, 60, array('display' => 'flex')),
        'n' => $observe(500, 18, 300, 24, array('display' => 'flex')),
        'm' => $observe(0, 60, 1440, 400),
    );
    $index = 0;
    foreach ($labels as $href => $label) {
        $index++;
        $viewports['i' . $index] = $observe(500 + 100 * ($index - 1), 18, 80, 24, array('margin-left' => '6px', 'margin-right' => '6px'));
        $viewports['a' . $index] = $observe(500 + 100 * ($index - 1), 18, 80, 24, array('padding-top' => '2px') + ($href === $url ? $underline : array()));
    }
    $children = array('b' => array('h', 'm'), 'h' => array('n'), 'n' => array('i1', 'i2', 'i3'), 'i1' => array('a1'), 'i2' => array('a2'), 'i3' => array('a3'), 'a1' => array(), 'a2' => array(), 'a3' => array(), 'm' => array());
    $nodes = array();
    foreach ($children as $id => $ids) $nodes[$id] = array('children' => $ids, 'viewports' => array('desktop' => $viewports[$id]));
    $tree = array('schema' => BoxTree::SCHEMA, 'source_path' => $path, 'source_hash' => hash('sha256', $html), 'viewports' => array('desktop' => array('width' => 1440, 'height' => 900)), 'nodes' => $nodes);
    $files[] = array('path' => 'box-tree/' . ('index.html' === $path ? 'home' : str_replace('/index.html', '', $path)) . '.json', 'content' => json_encode($tree, JSON_THROW_ON_ERROR));
}
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'provenance' => array('source_url' => 'https://example.test/'), 'files' => $files));
$assert(!array_filter($compiled->toArray()['diagnostics'], static fn(array $d): bool => str_starts_with((string) $d['code'], 'box_tree_')), 'The sidecars are accepted by the compiler.');
$plan = $compiled->toWordPressSitePlanView()['wordpress_site_plan'];
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers), 'One header part serves every route.');
$output = stripslashes(json_encode($plan, JSON_UNESCAPED_SLASHES));
preg_match_all('/\\bbe-inline-geometry-[\\w-]+/', $output, $carriers);
$assert(array() !== $carriers[0], 'The header links carry observed geometry.');
$current = 0;
foreach (array_unique($carriers[0]) as $carrier) {
    preg_match_all('/([^{}]*\\.' . preg_quote($carrier, '/') . '(?![\\w-])[^{}]*)\\{([^{}]*)\\}/', $output, $rules, PREG_SET_ORDER);
    foreach ($rules as [, $selector, $body]) {
        if (!str_contains($body, 'linear-gradient')) continue;
        $assert(str_contains($selector, '[aria-current="page"]'), 'Current-link paint projects only under aria-current: ' . trim($selector) . '{' . $body . '}');
        $current++;
    }
}
$assert($current > 0, 'The current-link underline projects under aria-current.');
echo "Shared header current-link paint passed\n";

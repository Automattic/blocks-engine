<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
foreach (array(
    array('pseudo' => ':after', 'base' => 'item', 'current' => 'data-selected', 'selector' => '.item[data-selected] .lbl'),
    array('pseudo' => ':after', 'base' => 'item', 'current' => 'is-current', 'selector' => '.item.is-current .lbl'),
    array('pseudo' => '::before', 'base' => 'item', 'current' => 'data-selected', 'selector' => '.item[data-selected] .lbl'),
    array('pseudo' => '::before', 'base' => 'item', 'current' => 'is-current', 'selector' => '.item.is-current .lbl'),
    array('base' => 'it', 'current' => 'data-selected', 'selector' => '[data-selected]'),
    array('base' => 'item', 'current' => 'is-sel', 'selector' => '.item.is-sel'),
    array('base' => 'ptLEUT eP1KVV x0UOau wixui-dropdown-menu__item', 'current' => 'WB5Q35', 'selector' => '.ptLEUT.WB5Q35,.ptLEUT[data-preview=selected]'),
) as $fixture) {
$files = array();
foreach (array('index.html' => 'Home', 'about/index.html' => 'About', 'contact/index.html' => 'Contact') as $path => $current) {
    $link = static fn(string $label, string $url): string => '<li' . ('WB5Q35' === $fixture['current'] ? ' class="' . $fixture['base'] . ($current === $label ? ' ' . $fixture['current'] : '') . '"' : '') . '><a class="' . $fixture['base'] . ($current === $label ? ' ' . $fixture['current'] . '" aria-current="page' : '') . '" href="' . $url . '">' . $label . '</a>' . '</li>';
    $files[$path] = '<!doctype html><html><head><title>' . $current . '</title><style>.menu{display:flex;gap:16px;list-style:none}.' . explode(' ', $fixture['base'])[0] . '{color:#07658b}' . $fixture['selector'] . '{color:#c00;font-weight:700}.eP1KVV{padding:2px}.x0UOau{margin:1px}.wixui-dropdown-menu__item{text-decoration:none}.dd{list-style:none;padding:8px}</style></head><body><header class="top"><nav class="main"><ul class="menu">'
        . $link('Home', '/') . $link('Pages', '#') . '</ul></nav><div class="dropbox"><nav class="sub"><ul class="dd">'
        . $link('About', '/about/') . $link('Contact', '/contact/') . '</ul></nav></div></header><main><h1>' . $current . '</h1><p>Content for ' . $current . '.</p></main></body></html>';
}
if ('data-selected' === $fixture['current']) {
    $files = array('assets/site.css' => '.menu{display:flex;gap:16px;list-style:none}.it{border-radius:var(--r,0);padding:4px}.it[data-selected]{border-radius:var(--rs,6px);background:#eef}.lbl{color:#000}[data-selected] .lbl,.lbl[data-selected]{color:#1a6aff}');
    foreach (array('index.html' => 'Home', 'about/index.html' => 'About', 'contact/index.html' => 'Contact') as $path => $current) {
        $link = static function (string $label, string $url, bool $main) use ($current): string {
            $selected = $current === $label;
            $marker = $selected ? ' data-selected="true"' : '';
            return '<li>' . ($main ? '<div class="it"' . $marker . '>' : '')
                . '<a' . ($main ? '' : ' class="dl"') . ' href="' . $url . '"' . ($selected ? ' aria-current="page"' : '') . '><span class="lbl"' . ($main ? '' : $marker) . '>' . $label . '</span></a>' . ($main ? '</div>' : '') . '</li>';
        };
        $files[$path] = '<!doctype html><html><head><title>' . $current . '</title><link rel="stylesheet" href="/assets/site.css"></head><body><header class="top"><nav class="main"><ul class="menu">'
            . $link('Home', '/', true) . $link('About', '/about/', true) . '</ul></nav><nav class="sub"><ul class="menu">'
            . $link('About', '/about/', false) . $link('Contact', '/contact/', false) . '</ul></nav></header><main><h1>' . $current . '</h1></main></body></html>';
    }
}
if (isset($fixture['pseudo'])) {
    $files = array('assets/site.css' => '.menu{display:flex;list-style:none}.lbl{position:relative}.lbl' . $fixture['pseudo'] . '{content:"";display:none}' . $fixture['selector'] . $fixture['pseudo'] . '{display:block}');
    foreach (array('index.html' => 'Home', 'services/index.html' => 'Services', 'services/a/index.html' => 'Service A', 'services/b/index.html' => 'Service B') as $path => $current) {
        $selected = 'Services' === $current;
        $marker = 'data-selected' === $fixture['current'] ? ($selected ? ' data-selected="true"' : '') : '';
        $parent = '<div class="item' . ($selected && '' === $marker ? ' is-current' : '') . '"' . $marker . '><a href="/services/"' . ($selected ? ' aria-current="page"' : '') . '><div class="lbl">Services</div></a></div>';
        $children = '';
        foreach (array('a' => 'Service A', 'b' => 'Service B') as $slug => $label) $children .= '<li><a href="/services/' . $slug . '/"' . ($current === $label ? ' aria-current="page"' : '') . '>' . $label . '</a></li>';
        $files[$path] = '<!doctype html><html><head><title>' . $current . '</title><link rel="stylesheet" href="/assets/site.css"></head><body><header><nav><ul class="menu"><li>' . $parent . '<div><ul>' . $children . '</ul></div></li></ul></nav></header><main><h1>' . $current . '</h1></main><footer>Footer</footer></body></html>';
    }
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers), 'All routes share one header: ' . $fixture['selector']);
$headerMarkup = $headers[0]['canonical_block_markup'];
$assert(!str_contains($headerMarkup, $fixture['current']), 'Captured current classes must not pin the shared header to one route.');
if ('WB5Q35' === $fixture['current']) {
    $menuMarkup = implode('', array_column($plan['menus'], 'block_markup'));
    $assert(!str_contains($menuMarkup, $fixture['current']), 'Shared menus do not retain the captured current class.');
    preg_match_all('/<!-- wp:navigation-link (\{.*?\}) \/?-->/s', $menuMarkup, $links);
    $assert(array() !== $links[1], 'The multi-class anchors lower to navigation-link blocks.');
    foreach ($links[1] as $attributes) {
        $attrs = json_decode($attributes, true, 512, JSON_THROW_ON_ERROR);
        foreach (explode(' ', $fixture['base']) as $class) {
            $assert(in_array($class, explode(' ', $attrs['className'] ?? ''), true), 'Authored anchor classes stay on the block className: ' . $class);
        }
    }
}
$sources = array_keys($headers[0]['provenance']['sources']);
sort($sources);
$expected = array_values(array_filter(array_keys($files), static fn(string $path): bool => str_ends_with($path, '.html')));
sort($expected);
$assert($expected === $sources, 'The shared part covers every source route.');
foreach ($plan['pages'] as $page) {
    $assert(!str_contains($page['canonical_block_markup'], '<header'), $page['source_path'] . ' must not retain an inline header.');
}
foreach ($plan['templates'] as $template) {
    $assert(str_contains($template['canonical_block_markup'], '"slug":"' . $headers[0]['slug'] . '"'), 'Every template uses the shared header.');
}
$css = implode('\n', array_column($plan['assets'], 'content'));
preg_match_all('/([^{}]+)\{([^{}]+)\}/', $css, $rules, PREG_SET_ORDER);
if (isset($fixture['pseudo'])) {
    $selected = array();
    foreach ($rules as [, $selector, $declarations]) {
        if ('display:block' !== trim($declarations, ';') || !str_contains($selector, 'blocks-engine-navigation-')) continue;
        $assert(str_contains($selector, $fixture['pseudo']), 'Selected generated content retains its pseudo-element.');
        $assert(str_contains($selector, '[aria-current="page"]') || str_contains($selector, '.current-menu-item'), 'Selected pseudo paint requires a runtime current item: ' . $selector);
        $assert(str_contains($selector, '.wp-block-navigation-item__label'), 'Selected pseudo paint addresses the native label.');
        $assert(!str_contains($selector, ':nth-child('), 'Selected pseudo paint is not frozen to a captured position.');
        $selected[] = $selector;
    }
    $assert(array() !== $selected, 'Selected pseudo-element paint survives extraction.');
    $menus = implode('', array_column($plan['menus'], 'block_markup'));
    $assert(str_contains($menus, 'navigation-submenu') && str_contains($menus, 'lbl'), 'The submenu parent retains its authored label wrapper.');
    preg_match('/blocks-engine-navigation-instance-[a-f0-9]+-\d+/', $headerMarkup, $instance);
    $assert(isset($instance[0]), 'The shared menu has a stable runtime instance.');
    preg_match_all('/\.((?:be-r-)[a-f0-9]+)/', implode('', $selected), $rootClasses);
    foreach (array('/', '/services/', '/services/a/', '/services/b/') as $route) {
        $dom = new DOMDocument();
        $dom->loadHTML('<html class="' . implode(' ', array_unique($rootClasses[1])) . '"><body><nav class="wp-block-navigation ' . $instance[0] . '"><ul class="wp-block-navigation__container"><li class="wp-block-navigation-item wp-block-navigation-submenu' . ('/services/' === $route ? ' current-menu-item' : '') . '"><a class="wp-block-navigation-item__content"' . ('/services/' === $route ? ' aria-current="page"' : '') . '><span class="wp-block-navigation-item__label"><span id="label" class="lbl">Services</span></span></a></li></ul></nav></body></html>');
        $applies = false;
        foreach ($selected as $selectorList) foreach (CssStylesheetTransformer::splitSelectorList($selectorList) ?? array() as $selector) {
            $host = CssSelectorMatcher::pseudoElementHost($selector);
            $assert(null !== $host, 'Selected rule has a matchable pseudo-element host.');
            $match = CssSelectorMatcher::matches($dom->getElementById('label'), $host['parsed']);
            $assert($match['supported'], 'Runtime selected host is supported by the matcher.');
            $applies = $applies || $match['matches'];
        }
        $assert(('/services/' === $route) === $applies, 'Selected pseudo paint follows the current Services label, not its resting copy: ' . $route);
    }
    continue;
}
$currentSelectors = '';
$selectedColor = ''; $selectedRadius = '';
foreach ($rules as [, $selector, $declarations]) {
    if ('data-selected' === $fixture['current']) {
        if (!str_contains($declarations, '#1a6aff') && !str_contains($declarations, '--rs')) continue;
    } elseif (!str_contains($declarations, '#c00') || !str_contains($declarations, 'font-weight:700')) continue;
    // Retained authored rules may coexist with their runtime projection.
    if (!str_contains($selector, 'blocks-engine-navigation-')) continue;
    $assert(str_contains($selector, '[aria-current="page"]') || str_contains($selector, '.current-menu-item'), 'Current paint follows the runtime current item, not its captured position: ' . $selector);
    $assert(!str_contains($selector, ':nth-child('), 'Current paint is not pinned to Home or a dropdown position.');
    $currentSelectors .= $selector;
    if (str_contains($declarations, '#1a6aff')) $selectedColor .= $selector;
    if (str_contains($declarations, '--rs')) $selectedRadius .= $selector;
}
$assert('' !== $currentSelectors, 'The current color and weight survive extraction.');
if ('data-selected' === $fixture['current']) {
    $menus = implode('', array_column($plan['menus'], 'block_markup'));
    $assert(!str_contains($menus, '--rs') && !str_contains($menus, '#1a6aff'), 'Selected paint is not frozen into menu attributes.');
    $assert(!str_contains($menus, '\\u003cspan'), 'Uniform navigation labels are lifted.');
    $assert(str_contains($currentSelectors, '.sub'), 'Lifted submenu labels restore runtime current paint.');
    $assert(str_contains($currentSelectors, 'blocks-engine-navigation-instance-'), 'The main menu restores runtime current paint even on Contact.');
    $assert(str_contains($selectedRadius, '[aria-current="page"]'), 'Selected radius follows the runtime current item.');
    $assert(str_contains($selectedColor, '.sub') && str_contains($selectedColor, 'blocks-engine-navigation-instance-'), 'Selected label color follows the current item in both menus.');
    continue;
}
preg_match_all('/blocks-engine-navigation-instance-[a-f0-9]+-\d+/', $headers[0]['canonical_block_markup'], $instances);
$assert(2 === count(array_unique($instances[0])), 'The header retains both menus.');
foreach (array_unique($instances[0]) as $instance) {
    $assert(str_contains($currentSelectors, '.' . $instance), 'Both menus restore current paint, including the menu without a current item on the entry route.');
}
}
echo "shared-shell-multiple-menu-current: ok\n";

<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
foreach (array(
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
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers), 'All three routes share one header.');
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
$expected = array_keys($files);
sort($expected);
$assert($expected === $sources, 'The shared part covers all three source routes.');
foreach ($plan['pages'] as $page) {
    $assert(!str_contains($page['canonical_block_markup'], '<header'), $page['source_path'] . ' must not retain an inline header.');
}
foreach ($plan['templates'] as $template) {
    $assert(str_contains($template['canonical_block_markup'], '"slug":"' . $headers[0]['slug'] . '"'), 'Every template uses the shared header.');
}
$css = implode('\n', array_column($plan['assets'], 'content'));
preg_match_all('/([^{}]+)\{([^{}]+)\}/', $css, $rules, PREG_SET_ORDER);
$currentSelectors = '';
foreach ($rules as [, $selector, $declarations]) {
    if (!str_contains($declarations, '#c00') || !str_contains($declarations, 'font-weight:700')) continue;
    // Retained authored rules may coexist with their runtime projection.
    if (!str_contains($selector, 'blocks-engine-navigation-')) continue;
    $assert(str_contains($selector, '[aria-current="page"]') || str_contains($selector, '.current-menu-item'), 'Current paint follows the runtime current item, not its captured position: ' . $selector);
    $assert(!str_contains($selector, ':nth-child('), 'Current paint is not pinned to Home or a dropdown position.');
    $currentSelectors .= $selector;
}
$assert('' !== $currentSelectors, 'The current color and weight survive extraction.');
preg_match_all('/blocks-engine-navigation-instance-[a-f0-9]+-\d+/', $headers[0]['canonical_block_markup'], $instances);
$assert(2 === count(array_unique($instances[0])), 'The header retains both menus.');
foreach (array_unique($instances[0]) as $instance) {
    $assert(str_contains($currentSelectors, '.' . $instance), 'Both menus restore current paint, including the menu without a current item on the entry route.');
}
}
echo "shared-shell-multiple-menu-current: ok\n";

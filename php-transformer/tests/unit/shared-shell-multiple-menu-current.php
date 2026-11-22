<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$files = array();
foreach (array('index.html' => 'Home', 'about/index.html' => 'About', 'contact/index.html' => 'Contact') as $path => $current) {
    $link = static fn(string $label, string $url): string => '<li><a class="item' . ($current === $label ? ' is-sel" aria-current="page' : '') . '" href="' . $url . '">' . $label . '</a></li>';
    $files[$path] = '<!doctype html><html><head><title>' . $current . '</title><style>.menu{display:flex;gap:16px;list-style:none}.item{color:#07658b}.item.is-sel{color:#c00;font-weight:700}.dd{list-style:none;padding:8px}</style></head><body><header class="top"><nav class="main"><ul class="menu">'
        . $link('Home', '/') . $link('Pages', '#') . '</ul></nav><div class="dropbox"><nav class="sub"><ul class="dd">'
        . $link('About', '/about/') . $link('Contact', '/contact/') . '</ul></nav></div></header><main><h1>' . $current . '</h1><p>Content for ' . $current . '.</p></main></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers), 'All three routes share one header.');
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
    $assert(str_contains($selector, '[aria-current="page"]'), 'Current paint follows the runtime current item, not its captured position.');
    $assert(!str_contains($selector, ':nth-child('), 'Current paint is not pinned to Home or a dropdown position.');
    $currentSelectors .= $selector;
}
$assert('' !== $currentSelectors, 'The current color and weight survive extraction.');
preg_match_all('/blocks-engine-navigation-instance-[a-f0-9]+-\d+/', $headers[0]['canonical_block_markup'], $instances);
$assert(2 === count(array_unique($instances[0])), 'The header retains both menus.');
foreach (array_unique($instances[0]) as $instance) {
    $assert(str_contains($currentSelectors, '.' . $instance), 'Both menus restore current paint, including the menu without a current item on the entry route.');
}
echo "shared-shell-multiple-menu-current: ok\n";

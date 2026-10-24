<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$routes = array('index' => 'Home', 'about' => 'About', 'visit' => 'Visit');
$files = array();
foreach ($routes as $current => $title) {
    $links = '';
    foreach ($routes as $slug => $label) {
        $links .= '<li class="block-header-item"><label class="block-header-item__label"><div class="item-content-wrapper' . ($slug === $current ? ' item-content-wrapper--active' : '') . ' block-header-item__item" data-qa="navigation-item-' . $slug . '"><a class="item-content" href="' . $slug . '.html" data-v-x data-qa="navigationblock-page-' . ($slug === $current ? 'active-' : '') . $slug . '">' . $label . '</a></div></label></li>';
    }
    $files[$current . '.html'] = '<!doctype html><html><head><title>' . $title . '</title><style>'
        . '.primary{color:#123456}.item-content[data-v-x]{display:block;color:#123456}.item-content-wrapper--active .item-content[data-v-x],.item-content-wrapper:hover .item-content[data-v-x],.item-content-wrapper:focus .item-content[data-v-x]{color:#abcdef;text-underline-offset:7px;text-decoration:underline}'
        . '@media(max-width:600px){.item-content-wrapper--active .item-content[data-v-x],.item-content-wrapper:hover .item-content[data-v-x],.item-content-wrapper:focus .item-content[data-v-x]{color:#fedcba;text-decoration:underline}}'
        . '</style></head><body><header><a href="index.html">Harbor</a><nav class="primary"><ul>' . $links . '</ul></nav></header>'
        . '<main><h1>' . $title . '</h1><p>Page ' . $title . '</p></main></body></html>';
}
// Compile each entry route too: shell reconciliation must not hide a
// captured current link by selecting the first route's menu as canonical.
$menuShapes = array();
foreach (array_keys($files) as $entrypoint) {
    $routePlan = (new ArtifactCompiler())->compile(array('entrypoint' => $entrypoint, 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
    $menuShapes[] = $routePlan['menus'][0]['block_markup'];
}
$assert(1 === count(array_unique($menuShapes)), 'Active scoped attribute and anchor markers must not change menu identity across entry routes.');
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$assert(1 === count($plan['menus']), 'Route-only active classes must not fork wp_navigation posts.');
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']));
$assert(1 === count($headers), 'Route-only active classes must not fork header parts.');
$assert(str_contains($headers[0]['canonical_block_markup'], '{{wordpress-site-plan:navigation:' . $plan['menus'][0]['token'] . '}}'), 'The shared header references the shared menu.');
$assert(3 === count($plan['pages']), 'All source routes are retained.');
foreach ($plan['pages'] as $page) {
    $markup = $page['canonical_block_markup'];
    $templateSlug = $page['entrypoint'] ? 'front-page' : 'page';
    $template = array_column($plan['templates'], 'canonical_block_markup', 'slug')[$templateSlug] ?? '';
    $assert(str_contains($template . $markup, 'wp:template-part {"slug":"' . $headers[0]['slug'] . '"'), 'Every page references the shared header through its native template.');
    $assert(!str_contains($markup, '<header') && !str_contains($markup, 'wp:navigation '), 'No page embeds an inline header copy.');
}
$css = '';
array_walk_recursive($plan, static function ($value) use (&$css): void {
    if (is_string($value) && (str_contains($value, '#abcdef') || str_contains($value, '#fedcba'))) $css .= $value . "\n";
});
foreach (array('#abcdef', '#fedcba') as $color) {
    preg_match_all('/([^{}]+)\{[^{}]*color:[^;{}]*' . $color . '[^{}]*\}/i', $css, $rules, PREG_SET_ORDER);
    $runtime = array_filter($rules, static fn(array $rule): bool => str_contains($rule[1], 'wp-block-navigation') && (str_contains($rule[1], '[aria-current') || str_contains($rule[1], 'current-menu-item')));
    $assert(array() !== $runtime, 'Desktop and mobile active colour must follow the runtime current item: ' . $color);
}
$assert(!preg_match('/(?:aria-current|current-menu-item)[^{}]*blocks-engine-navigation-anchor-/', $css), 'Runtime current paint is not constrained to the captured anchor.');
$assert(str_contains($css, '.item-content-wrapper:hover') && str_contains($css, '.item-content-wrapper:focus'), 'Sibling hover and focus selectors retain their existing projection.');
$assert((bool) preg_match('/[^{}]*(?:aria-current|current-menu-item)[^{}]*\{[^{}]*text-decoration:\s*underline/', $css), 'Active underline follows the runtime current item.');
$assert(!str_contains($plan['menus'][0]['block_markup'], 'item-content-wrapper--active'), 'The menu does not freeze a source active class.');
echo "navigation-current-item-shared-menu: ok\n";

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
    $tag = 'index' === $current ? 'h1' : 'p';
    $links = '';
    foreach ($routes as $slug => $label) {
        $links .= '<li><a class="menu-link' . ($slug === $current ? ' is-active-menu-item' : '') . '" href="' . $slug . '.html">' . $label . '</a></li>';
    }
    $files[$current . '.html'] = '<!doctype html><html><head><title>' . $title . '</title><style>.site-title{font-size:24px;font-weight:700;margin:0}.menu-link{color:#123456}.is-active-menu-item{color:#abcdef;text-decoration:underline}</style></head><body><header><' . $tag . ' class="site-title"><a href="index.html">Garden Studio</a></' . $tag . '><nav><ul>' . $links . '</ul></nav></header><main><h2>' . $title . '</h2><p>Content for ' . $title . '</p></main></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']));
$assert(1 === count($headers), 'Title level and current item must yield one shared header.');
$header = $headers[0]['canonical_block_markup'];
$assert(str_contains($header, 'wp:navigation '), 'The shared header contains native navigation.');
$assert(1 === count($plan['menus']), 'The header uses one shared menu.');
$assert(str_contains($header, '{{wordpress-site-plan:navigation:' . $plan['menus'][0]['token'] . '}}'), 'The header references the shared menu contract.');
$assert(str_contains($header, '<h1') && str_contains($header, 'Garden Studio'), 'The shared title preserves the home heading.');
$assert(!str_contains($plan['menus'][0]['block_markup'], 'is-active-menu-item'), 'The shared menu does not freeze the captured selection.');
$css = implode("\n", array_column(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
$assert((bool) preg_match('/[^{}]*(?:aria-current|current-menu-item)[^{}]*\{[^{}]*#abcdef/', $css), 'Current-item colour follows Core runtime selection.');
foreach ($plan['pages'] as $page) {
    $assert(!str_contains($page['canonical_block_markup'], '<header') && !str_contains($page['canonical_block_markup'], 'Garden Studio'), 'Page content does not repeat header markup.');
    $template = array_column($plan['templates'], 'canonical_block_markup', 'slug')[$page['route_template'] ?? ($page['entrypoint'] ? 'front-page' : 'page')] ?? '';
    $assert(str_contains($template . $page['canonical_block_markup'], 'wp:template-part {"slug":"' . $headers[0]['slug'] . '"'), 'Every route references the shared header.');
}
// A different wordmark is different chrome, not another title level.
$distinctFiles = $files;
$distinctFiles['index.html'] = str_replace('Garden Studio', 'Meadow Studio', $distinctFiles['index.html']);
$distinct = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $distinctFiles))->toWordPressSitePlanView()['wordpress_site_plan'];
$assert(2 === count(array_filter($distinct['template_parts'], static fn(array $part): bool => 'header' === $part['area'])), 'Different title content must not merge.');
$section = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<html><body><main><article><header><h1>Article title</h1></header><p>Article body</p></article></main></body></html>')))->toWordPressSitePlanView()['wordpress_site_plan'];
$assert(str_contains($section['pages'][0]['canonical_block_markup'], '<header'), 'A single-page article header stays in content.');
$assert(array() === array_filter($section['template_parts'], static fn(array $part): bool => 'header' === $part['area']), 'An article header is not site chrome.');
echo "shared-header-title-level: ok\n";

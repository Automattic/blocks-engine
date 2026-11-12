<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
// With WordPress available, also exercise Core's real dynamic title renderer.
if ($bootstrap = getenv('SHARED_TITLE_WORDPRESS_BOOTSTRAP')) {
    require $bootstrap;
    add_filter('pre_option_show_on_front', static fn() => 'posts');
}

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeTemplates;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
if (!function_exists('is_front_page')) {
    function is_front_page(): bool { return $GLOBALS['wordmarkFrontPage']; }
}
$routes = array('index' => 'Home', 'about' => 'About', 'visit' => 'Visit');
foreach (array(false, true) as $homeHeading) {
foreach (array('', ';color:#333;text-decoration:none') as $titlePresentation) {
foreach (array('site-title', 'brand-x') as $titleClass) {
foreach (array(true, false) as $homeLinked) {
foreach (array(true, false) as $paragraph) {
$cell = 'home heading=' . (int) $homeHeading . " / $titleClass$titlePresentation / home linked=" . (int) $homeLinked . " / paragraph=" . (int) $paragraph;
$files = array();
foreach ($routes as $current => $title) {
    $wordmark = 'Garden Studio';
    if (!$homeHeading || 'index' !== $current || $homeLinked) $wordmark = '<a' . (!$homeHeading || (!$paragraph && 'index' !== $current) ? ' class="' . $titleClass . '"' : '') . ' href="index.html">' . $wordmark . '</a>';
    if ($homeHeading && 'index' === $current) $wordmark = '<h1 class="' . $titleClass . '">' . $wordmark . '</h1>';
    elseif ($homeHeading && $paragraph) $wordmark = '<p class="' . $titleClass . '">' . $wordmark . '</p>';
    $links = '';
    foreach ($routes as $slug => $label) {
        $links .= '<li><a class="menu-link' . ($slug === $current ? ' is-active-menu-item' : '') . '" href="' . $slug . '.html">' . $label . '</a></li>';
    }
    $files[$current . '.html'] = '<!doctype html><html><head><title>' . $title . '</title><style>.' . $titleClass . '{font-size:24px;font-family:Georgia;font-weight:700;letter-spacing:.06em;line-height:1;text-transform:uppercase;margin:0' . $titlePresentation . '}.menu-link{color:#123456}.is-active-menu-item{color:#abcdef;text-decoration:underline}</style></head><body><header><div class="logo">' . $wordmark . '</div><nav><ul>' . $links . '</ul></nav></header><main><' . ($homeHeading ? 'h2' : 'h1') . '>' . $title . '</' . ($homeHeading ? 'h2' : 'h1') . '><p>Content for ' . $title . '</p></main></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']));
$assert(1 === count($headers), $cell . ': Title level and current item must yield one shared header.');
$header = $headers[0]['canonical_block_markup'];
$assert(str_contains($header, 'wp:navigation '), 'The shared header contains native navigation.');
$assert(1 === count($plan['menus']), 'The header uses one shared menu.');
$assert(str_contains($header, '{{wordpress-site-plan:navigation:' . $plan['menus'][0]['token'] . '}}'), 'The header references the shared menu contract.');
$assert(str_contains($header, 'wp:site-title ') && !str_contains($header, '<h1'), $cell . ': The shared wordmark is a native, non-heading site title.');
preg_match('/<!-- wp:site-title (\{.*?\}) \/-->/', $header, $titleMatch);
$titleAttrs = json_decode($titleMatch[1], true);
$assert(0 === $titleAttrs['level'] && true === $titleAttrs['isLink'], $cell . ': The shared title links home without a heading.');
$assert('24px' === $titleAttrs['style']['typography']['fontSize'], $cell . ': Title size survives lowering.');
$assert(str_contains($titleAttrs['style']['typography']['fontFamily'], 'Georgia'), $cell . ': Title font survives lowering.');
$assert(array('700', '.06em', '1', 'uppercase') === array($titleAttrs['style']['typography']['fontWeight'] ?? null, $titleAttrs['style']['typography']['letterSpacing'] ?? null, $titleAttrs['style']['typography']['lineHeight'] ?? null, $titleAttrs['style']['typography']['textTransform'] ?? null), $cell . ': Title weight, tracking, leading and case survive lowering.');
$assert('inherit' === $titleAttrs['style']['elements']['link']['color']['text'], $cell . ': The home link uses wordmark colour, not generic theme link colour.');
if ('' !== $titlePresentation) {
    $assert('rgba(51,51,51,1)' === $titleAttrs['style']['color']['text'], $cell . ': Title colour survives lowering.');
    $assert('none' === $titleAttrs['style']['typography']['textDecoration'], $cell . ': Title decoration survives lowering.');
}
$assert(!str_contains($plan['menus'][0]['block_markup'], 'is-active-menu-item'), 'The shared menu does not freeze the captured selection.');
$css = implode("\n", array_column(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
$assert((bool) preg_match('/[^{}]*(?:aria-current|current-menu-item)[^{}]*\{[^{}]*#abcdef/', $css), 'Current-item colour follows Core runtime selection.');
$bootstrap = '';
foreach ($plan['writes'] as $write) if ('functions.php' === ($write['path'] ?? $write['target_path'] ?? '')) $bootstrap = (string) $write['payload']['data'];
$marker = strpos($bootstrap, "'core/site-title' ===");
$assert(false !== $marker, $cell . ': The generated theme resolves title route state.');
$start = strrpos(substr($bootstrap, 0, $marker), 'static function');
$end = strpos($bootstrap, '}, 10, 1 );', $marker);
$resolveTitle = eval('return ' . substr($bootstrap, $start, $end + 1 - $start) . ';');
foreach ($plan['pages'] as $page) {
    $GLOBALS['wordmarkFrontPage'] = (bool) $page['entrypoint'];
    if (function_exists('render_block_core_site_title')) {
        $GLOBALS['wp_query'] = new WP_Query();
        $GLOBALS['wp_query']->is_home = (bool) $page['entrypoint'];
    }
    $block = array('blockName' => 'core/site-title', 'attrs' => $titleAttrs);
    $resolved = $resolveTitle($block);
    $heading = $homeHeading && $page['entrypoint'];
    $assert(($heading ? 1 : 0) === $resolved['attrs']['level'], $cell . ': Only a source heading on the entrypoint renders a heading.');
    $titleHtml = $heading ? '<h1>Garden Studio</h1>' : '<p>Garden Studio</p>';
    $assert(true === $resolved['attrs']['isLink'], $cell . ': Inner routes use Core site-title\'s live home link.');
    if (function_exists('render_block_core_site_title')) {
        $html = render_block_core_site_title($resolved['attrs']);
        $titleHtml = $html;
        $assert(str_starts_with($html, $heading ? '<h1 ' : '<p '), 'Core renders the route-dependent title tag.');
        $assert(str_contains($html, '<a href="' . esc_url(home_url()) . '"'), 'Core links the title to home on inner routes.');
    }
    if (!$homeHeading) {
        $rendered = $titleHtml . $page['canonical_block_markup'];
        $assert(1 === preg_match_all('/<h1\\b/i', $rendered), $cell . ': Link-only branding leaves exactly the page heading as h1.');
        $assert(str_starts_with($titleHtml, '<p'), $cell . ': Link-only branding renders as p on every route.');
    }
    $ordinary = array('blockName' => 'core/site-title', 'attrs' => array('level' => 3));
    $assert($ordinary === $resolveTitle($ordinary), 'Unmarked site titles retain authored levels.');
    $assert(!str_contains($page['canonical_block_markup'], '<header') && !str_contains($page['canonical_block_markup'], 'Garden Studio'), $cell . ': Page content does not repeat header markup.');
    $template = ThemeTemplates::rendered($plan)[$page['route_template'] ?? ($page['entrypoint'] ? 'front-page' : 'page')] ?? '';
    $assert(str_contains($template . $page['canonical_block_markup'], 'wp:template-part {"slug":"' . $headers[0]['slug'] . '"'), $cell . ': Every route references the shared header.');
}
}
}
}
}
}
// A different wordmark is different chrome, not another title level.
$distinctFiles = $files;
$distinctFiles['index.html'] = str_replace('Garden Studio', 'Meadow Studio', $distinctFiles['index.html']);
$distinct = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $distinctFiles))->toWordPressSitePlanView()['wordpress_site_plan'];
$distinctHeaders = array_values(array_filter($distinct['template_parts'], static fn(array $part): bool => 'header' === $part['area']));
$assert(1 === count($distinctHeaders) && !str_contains($distinctHeaders[0]['canonical_block_markup'], 'Meadow Studio'), 'The unique wordmark must not merge or become a single-use part.');
foreach (array('color:#933', 'font-size:32px') as $difference) {
    $differentFiles = $files;
    $differentFiles['index.html'] = str_replace('</style>', 'h1.' . $titleClass . '{' . $difference . '}</style>', $differentFiles['index.html']);
    $different = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $differentFiles))->toWordPressSitePlanView()['wordpress_site_plan'];
    $differentHeaders = array_values(array_filter($different['template_parts'], static fn(array $part): bool => 'header' === $part['area']));
    $assert(1 === count($differentHeaders) && !str_contains($differentHeaders[0]['canonical_block_markup'], '<h1'), 'Different title presentation must not merge: ' . $difference);
    $home = array_values(array_filter($different['pages'], static fn(array $page): bool => !empty($page['entrypoint'])))[0];
    $assert(str_contains($home['canonical_block_markup'], '<header'), 'Different home presentation stays in content: ' . $difference);
}
$section = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<html><body><main><article><header><h1>Article title</h1></header><p>Article body</p></article></main></body></html>')))->toWordPressSitePlanView()['wordpress_site_plan'];
$assert(str_contains($section['pages'][0]['canonical_block_markup'], '<header'), 'A single-page article header stays in content.');
$assert(array() === array_filter($section['template_parts'], static fn(array $part): bool => 'header' === $part['area']), 'An article header is not site chrome.');
echo "shared-header-title-level: ok\n";

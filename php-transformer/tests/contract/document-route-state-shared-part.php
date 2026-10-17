<?php
declare(strict_types=1);

/**
 * A client router marks every header link current on `/`: the brand link and
 * each in-page section link carry `active`, `data-status="active"` and
 * `aria-current="page"`. With no resting peer in the menu, route state is
 * recognised against the document's resting links, and section links are
 * never the current page. `/` therefore shares the header part and its one
 * navigation with every other route.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RouteStateProjector;

$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if (! $condition) {
        throw new RuntimeException($message . ('' === $detail ? '' : "\n" . substr($detail, 0, 2000)));
    }
};

$header = static function (bool $home): string {
    $state = $home ? ' active" data-status="active" aria-current="page' : '';
    $link = static fn(string $href, string $label): string => '<a class="text-sm hover:text-white' . $state . '" href="' . $href . '">' . $label . '</a>';
    return '<header class="site-header"><a class="flex gap-3 items-center' . $state . '" href="/index.html"><img src="/logo.svg" alt="Studio" width="32" height="32"><span>Studio</span></a>'
        . '<nav>' . $link('/index.html#services', 'Services') . $link('/index.html#work', 'Work') . $link('/index.html#contact', 'Contact') . '</nav></header>';
};
$head = '<link rel="stylesheet" href="/style.css">';
$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'style.css' => '.site-header{display:flex;justify-content:space-between;padding:16px} .active{color:#fff} .text-sm{font-size:14px}',
        'logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"/>',
        'index.html' => $head . $header(true) . '<main><h1>Home</h1><section id="services"><p>Services.</p></section><section id="work"><p>Work. <a href="/before-after/index.html">Before and after</a></p></section><section id="contact"><p>Contact.</p></section></main>',
        'before-after/index.html' => $head . $header(false) . '<main><h1>Before and after</h1><p>Results.</p></main>',
        'blog/index.html' => $head . $header(false) . '<main><h1>Blog</h1><p>Posts.</p></main>',
    ),
))->toArray()['source_reports']['wordpress_site_plan'];

$headers = array_values(array_filter($plan['template_parts'] ?? array(), static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers), 'every route, including the one the router marked current, shares one header part', json_encode(array_column($headers, 'slug')));
foreach ($plan['pages'] as $page) {
    $assert(! str_contains((string) ($page['canonical_block_markup'] ?? ''), '<!-- wp:navigation'), $page['source_path'] . ' renders its header from the shared part', (string) ($page['canonical_block_markup'] ?? ''));
}
$navigations = array_unique(array_map(static fn(array $menu): string => (string) ($menu['slug'] ?? ''), array_filter($plan['menus'] ?? array(), static fn(array $menu): bool => '' !== (string) ($menu['block_markup'] ?? ''))));
$assert(1 === count($navigations), 'the shared header owns one navigation', json_encode(array_values($navigations)));
$assert(! str_contains((string) $headers[0]['canonical_block_markup'], 'active'), 'the shared part freezes no route state', (string) $headers[0]['canonical_block_markup']);

// Without a resting baseline, only section links lose their current signal.
$alone = RouteStateProjector::normalize('<nav><a class="active" aria-current="page" href="/">Home</a><a class="active" aria-current="page" href="/#a">A</a></nav>');
$assert(2 === substr_count($alone, 'class="active"') && 1 === substr_count($alone, 'aria-current'), 'without a resting link only section links lose their current signal', $alone);
// A token a resting link also carries is presentation, not route state.
$shared = RouteStateProjector::normalize('<nav><a class="pill active" aria-current="page" href="/">Home</a><a class="pill" href="/about/">About</a></nav>');
$assert(str_contains($shared, '<a class="pill" aria-current="page" href="/">'), 'route state removes only the tokens absent from resting links and keeps a menu link\'s aria-current', $shared);
$brand = RouteStateProjector::normalize('<header><a class="logo" aria-current="page" href="/">Brand</a><nav><a href="/about/">About</a></nav></header>');
$assert(str_contains($brand, '<a class="logo" href="/">'), 'a current link outside a menu loses its aria-current', $brand);

fwrite(STDOUT, "document route state shared part contract passed\n");

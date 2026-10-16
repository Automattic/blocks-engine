<?php
declare(strict_types=1);

/**
 * Route state is recognised against the document, not only against siblings.
 *
 * A router that marks every same-page link current on the home route (here
 * the brand link and six `/#section` links, each `active` with
 * data-status="active" and aria-current="page") leaves no current-vs-sibling
 * contrast inside the menu. The non-current `/before-after` link elsewhere in
 * the document still shows that `active` is route state, so the home header
 * must be the same shared part, with the same single wp_navigation, as the
 * routes where nothing is marked.
 *
 * A route with exactly one current link keeps its existing behaviour.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Support\RouteState;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ShellExtraction;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

$sections = array('services' => 'Services', 'process' => 'Process', 'work' => 'Work', 'about' => 'About', 'faq' => 'FAQ', 'contact' => 'Contact');
$styles = '<style>.site-header{display:flex;justify-content:space-between;padding:16px;background:#0f172a}.flex{display:flex}.gap-3{gap:12px}.flex-col{flex-direction:column}'
    . '.logo{width:36px;height:36px;border-radius:8px;background:#06b6d4}.font-semibold{font-weight:600}.text-xs{font-size:12px}.text-sm{font-size:14px}.text-slate-300{color:#cbd5e1}'
    . 'nav{display:flex;gap:24px}.cta{background:#06b6d4;color:#020617;padding:8px 16px;border-radius:9999px}footer{background:#020617;color:#fff;padding:24px}</style>';
$modus = static function (string $route, string $title, string $chrome = 'header') use ($sections, $styles): string {
    $home = '/' === $route;
    $state = static fn(string $class): string => $home ? ' class="' . $class . ' active" data-status="active" aria-current="page"' : ' class="' . $class . '"';
    $links = '';
    foreach ($sections as $id => $label) $links .= '<a href="/#' . $id . '"' . $state('text-sm text-slate-300 hover:text-white') . '>' . $label . '</a>';
    return '<!doctype html><html><head><title>' . $title . '</title>' . $styles . '</head><body><div class="site">'
        . '<div class="topbar"><a href="tel:+15550100">555-0100</a></div>'
        . '<' . $chrome . ' class="site-header"><a href="/"' . $state('flex gap-3') . '><div class="logo"></div><div class="flex flex-col"><span class="font-semibold">Modus Digital</span><span class="text-xs">Renovation studio</span></div></a>'
        . '<nav>' . $links . '<a href="/before-after" class="text-sm text-slate-300 hover:text-white">Before &amp; After</a></nav></' . $chrome . '>'
        . '<main><section><h1>' . $title . '</h1><p>' . $title . ' body copy.</p></section></main>'
        . '<footer><p>Modus Digital, Ltd.</p></footer></div></body></html>';
};

// The rule itself: tokens on every current link and on no resting link.
$homeState = RouteState::ofHtml($modus('/', 'Home'));
$assert(true === $homeState['applies'], 'A document with current and resting links carries route state.');
$assert(array('active') === array_values(array_unique(array_merge(...array_column($homeState['carriers'], 'tokens')))), 'Only the class every current link and no resting link carries is route state: ' . json_encode($homeState));
$allCurrent = '<header><a href="/" class="brand active" aria-current="page">Modus</a><nav><a href="/#work" class="text-sm active" aria-current="page">Work</a><a href="/#faq" class="text-sm active" aria-current="page">FAQ</a></nav></header>';
$assert(empty(RouteState::ofHtml($allCurrent)['applies']), 'Without a resting link nothing proves the classes are route state.');
$assert(empty(RouteState::ofHtml($modus('/privacy/', 'Privacy'))['applies']), 'A document with nothing marked carries no route state.');

foreach (array('div', 'header') as $chrome) {
    $files = array(
        'website/index.html' => $modus('/', 'Home', $chrome),
        'website/before-after/index.html' => $modus('/before-after/', 'Before and after', $chrome),
        'website/privacy/index.html' => $modus('/privacy/', 'Privacy', $chrome),
    );
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => array_map(static fn(string $path, string $content): array => array('path' => $path, 'content' => $content), array_keys($files), $files)))->toArray()['source_reports']['wordpress_site_plan'];
    WordPressSitePlan::assertValid($plan);
    $headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
    $assert(1 === count($headers), "<{$chrome}> chrome: the three routes share one header part: " . json_encode(array_column($plan['template_parts'], 'slug')) . ' ' . json_encode(array_column($plan['diagnostics'], 'message')));
    $assert('route_edge' === ($headers[0]['provenance']['reason'] ?? null), "<{$chrome}> chrome: the header is shared through the route-edge pass: " . json_encode($headers[0]['provenance'] ?? null));
    $sources = array_keys($headers[0]['provenance']['sources'] ?? array());
    sort($sources);
    $assert(array('website/before-after/index.html', 'website/index.html', 'website/privacy/index.html') === $sources, "<{$chrome}> chrome: the home route joins the shared header: " . json_encode($sources));
    $assert(1 === count($plan['menus']), "<{$chrome}> chrome: one wp_navigation serves every route: " . count($plan['menus']));
    $part = (string) $headers[0]['canonical_block_markup'];
    $assert(!preg_match('/\bactive\b|aria-current|data-status/', $part . implode('', array_column($plan['menus'], 'block_markup'))), "<{$chrome}> chrome: route state does not leak into the shared header: {$part}");
    foreach ($plan['pages'] as $row) $assert(!str_contains((string) $row['canonical_block_markup'], 'wp:navigation'), "<{$chrome}> chrome: {$row['source_path']} keeps no header of its own.");
}

// Exactly one current link: the sibling contrast already identifies the state
// and the document-level rule changes nothing.
$single = static function (string $current) use ($sections): string {
    $links = '';
    foreach ($sections as $id => $label) $links .= '<a href="/' . $id . '/" class="text-sm' . ($id === $current ? ' active" aria-current="page' : '') . '">' . $label . '</a>';
    return '<!doctype html><html><head><title>' . $current . '</title><style>.text-sm{font-size:14px}.active{color:#e8501c}</style></head><body><header class="site-header"><a class="brand" href="/">Modus</a><nav>' . $links . '</nav></header><main><p>' . $current . '</p></main></body></html>';
};
$singleState = RouteState::ofHtml($single('work'));
$assert(true === $singleState['applies'] && array('active') === array_values(array_unique(array_merge(...array_column($singleState['carriers'], 'tokens')))), 'One current link: its own class is route state: ' . json_encode($singleState));
$block = '<!-- wp:navigation {"className":"site-nav"} --><!-- wp:navigation-link {"label":"About","url":"/about/","kind":"custom","className":"blocks-engine-navigation-link-color-abc123"} /-->'
    . '<!-- wp:navigation-link {"label":"Work","url":"/work/","kind":"custom","className":"blocks-engine-current-navigation-item blocks-engine-navigation-current-color-abc123"} /-->'
    . '<!-- wp:navigation-link {"label":"FAQ","url":"/faq/","kind":"custom","className":"blocks-engine-navigation-link-color-abc123"} /--><!-- /wp:navigation -->';
foreach (array(false, true) as $semantic) {
    $assert(ShellExtraction::withoutCurrentNavigationState($block, $semantic) === ShellExtraction::withoutCurrentNavigationState($block, $semantic, $singleState), 'One current link: block identity is unchanged by the document rule (' . ($semantic ? 'semantic' : 'part') . ').');
}
$singleFiles = array();
foreach (array_keys($sections) as $id) $singleFiles[] = array('path' => 'website/' . $id . '/index.html', 'content' => $single($id));
$singleFiles[] = array('path' => 'website/index.html', 'content' => $single(''));
$singlePlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $singleFiles))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($singlePlan);
$singleHeaders = array_values(array_filter($singlePlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($singleHeaders) && 1 === count($singlePlan['menus']), 'One current link per route: the header stays one shared part with one wp_navigation: ' . json_encode(array_column($singlePlan['diagnostics'], 'message')));
$assert(!preg_match('/\bactive\b|aria-current/', (string) $singleHeaders[0]['canonical_block_markup']), 'One current link per route: the shared header keeps no route state.');

fwrite(STDOUT, "Route state document recognition contract passed.\n");

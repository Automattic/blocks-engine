<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// Site chrome is what repeats at the edges of many routes, whatever its
// landmarks. Here the header is a utility bar plus a banner holding the menu,
// with no <header> element, and each route marks its own menu item current.
// Inner routes open with a page-title band of their own after the chrome.
$routes = array('index' => 'Home', 'about' => 'About', 'visit' => 'Visit', 'events' => 'Events');
$page = static function (string $current) use ($routes): string {
    $menu = '';
    foreach ($routes as $slug => $label) $menu .= '<a href="' . $slug . '.html"' . ($slug === $current ? ' class="active" aria-current="page"' : '') . '>' . $label . '</a>';
    $title = 'index' === $current ? '' : '<section class="page-title"><h1>' . $routes[$current] . '</h1><p>About this ' . $routes[$current] . ' page.</p></section>';
    return '<!doctype html><html><head><title>' . $routes[$current] . '</title><style>.utility{background:#123;color:#fff;font-size:12px}.banner{display:flex;justify-content:space-between;padding:16px}.banner nav a{margin-left:12px}.page-title{padding:40px}footer{background:#eee}</style></head><body>'
        . '<div class="site"><div class="utility"><a href="tel:+15550100">555-0100</a> · <a href="mailto:office@example.test">office@example.test</a></div>'
        . '<div class="banner"><a class="brand" href="index.html">Harbor Parish</a><nav>' . $menu . '</nav></div>'
        . $title
        . '<main><section><h2>' . $routes[$current] . ' welcome</h2><p>' . $routes[$current] . ' body copy.</p></section></main>'
        . '<footer><p>Harbor Parish, 1 Shore Road</p></footer></div></body></html>';
};
$files = array();
foreach (array_keys($routes) as $slug) $files['website/' . $slug . '.html'] = $page($slug);
// A route with a different banner stays page-owned.
$files['website/legacy.html'] = str_replace('Harbor Parish</a>', 'Old Parish</a>', $page('visit'));

$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$codes = array_column($plan['diagnostics'], 'code');
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers), 'The repeated utility bar and banner are one shared header part: ' . json_encode($codes));
$header = (string) $headers[0]['canonical_block_markup'];
$assert(str_contains($header, '555-0100') && str_contains($header, 'Harbor Parish') && str_contains($header, '{{wordpress-site-plan:navigation:'), 'The header part holds both the utility bar and the banner with its menu.');
$assert('route_edge' === ($headers[0]['provenance']['reason'] ?? null), 'The part records that the route edge, not a landmark, found it: ' . json_encode($headers[0]['provenance'] ?? null));

$templates = array_column($plan['templates'] ?? array(), 'canonical_block_markup', 'slug');
$pages = array_column($plan['pages'], null, 'source_path');
foreach ($routes as $slug => $label) {
    $markup = (string) ($pages['website/' . $slug . '.html']['canonical_block_markup'] ?? '');
    $template = (string) ($templates['index' === $slug ? 'front-page' : 'page-' . $slug] ?? $templates['page'] ?? '');
    $assert(!str_contains($markup, '555-0100') && !str_contains($markup, 'Harbor Parish</a>') && !str_contains($markup, '<!-- wp:navigation '), "{$slug}.html no longer carries the header blocks.");
    $assert(1 === substr_count($markup . $template, '"slug":"' . $headers[0]['slug'] . '"'), "{$slug}.html renders the shared header once.");
    $assert(str_contains($markup, $label . ' body copy.'), "{$slug}.html keeps its content.");
    if ('index' !== $slug) $assert(str_contains($markup, 'About this ' . $label . ' page.'), "{$slug}.html keeps its own title band, which is content, not chrome.");
}
$legacy = (string) ($pages['website/legacy.html']['canonical_block_markup'] ?? '');
$assert(str_contains($legacy, 'Old Parish') && str_contains($legacy, '<!-- wp:navigation '), 'A route with a different banner keeps its own header blocks.');
$assert(!str_contains($header, 'aria-current') && !str_contains($header, '"className":"active"'), 'The shared header freezes no route selection.');

// With landmarks, the edge is primary: a utility bar before every <header>
// and a notice after every <footer> travel with them, so no route keeps a
// copy. The title band between the header and main stays page content.
$landmarkPage = static function (string $title): string {
    return '<!doctype html><html><head><title>' . $title . '</title></head><body>'
        . '<div class="utility"><a href="tel:+15550100">555-0100</a></div>'
        . '<header class="masthead"><a href="index.html">Harbor Parish</a><nav><a href="index.html">Home</a><a href="about.html">About</a><a href="visit.html">Visit</a></nav></header>'
        . '<section class="page-title"><h1>' . $title . ' title</h1></section>'
        . '<main><p>' . $title . ' body copy.</p></main>'
        . '<footer class="colophon"><p>1 Shore Road</p></footer>'
        . '<div class="notice"><p>We use no cookies.</p></div></body></html>';
};
$landmarkPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $landmarkPage('Home'), 'about.html' => $landmarkPage('About'), 'visit.html' => $landmarkPage('Visit'))))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($landmarkPlan);
$landmarkParts = array_column($landmarkPlan['template_parts'], null, 'area');
$assert(str_contains((string) ($landmarkParts['header']['canonical_block_markup'] ?? ''), '555-0100') && str_contains((string) $landmarkParts['header']['canonical_block_markup'], 'masthead') && !str_contains((string) $landmarkParts['header']['canonical_block_markup'], 'title</h1>'), 'The header part holds the utility bar with the landmark, and no title band.');
$assert(str_contains((string) ($landmarkParts['footer']['canonical_block_markup'] ?? ''), 'We use no cookies.') && str_contains((string) $landmarkParts['footer']['canonical_block_markup'], 'colophon'), 'The footer part holds the trailing notice with the landmark.');
foreach (array_column($landmarkPlan['pages'], null, 'source_path') as $source => $row) {
    $markup = (string) $row['canonical_block_markup'];
    $assert(!str_contains($markup, '555-0100') && !str_contains($markup, 'We use no cookies.') && str_contains($markup, 'title</h1>') && str_contains($markup, 'body copy.'), "{$source} keeps its title band and content and no chrome residue: {$markup}");
}


// A page-title <header> unique to each route is page content: it neither
// becomes the shared header nor stops the repeated edge above it.
$titleHeaderFiles = array_map(static fn(string $html): string => str_replace(array('<section class="page-title">', '</p></section>'), array('<header class="page-title">', '</p></header>'), $html), $files);
$titleHeaderPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $titleHeaderFiles))->toArray()['source_reports']['wordpress_site_plan'];
$titleHeaders = array_values(array_filter($titleHeaderPlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$titleHeaderPages = array_column($titleHeaderPlan['pages'], null, 'source_path');
$assert(1 === count($titleHeaders) && str_contains((string) $titleHeaders[0]['canonical_block_markup'], '555-0100') && !str_contains((string) $titleHeaders[0]['canonical_block_markup'], 'About this'), 'The repeated edge, not a page-title header, is the shared header.');
foreach (array('about', 'visit', 'events') as $slug) $assert(!str_contains((string) $titleHeaderPages['website/' . $slug . '.html']['canonical_block_markup'], '555-0100') && str_contains((string) $titleHeaderPages['website/' . $slug . '.html']['canonical_block_markup'], 'About this'), "{$slug}.html keeps its page-title header and drops the chrome.");

// A route whose shell candidate cannot be located in its page (here its
// header compiled with route-specific styles beside a second, content header)
// keeps its header page-owned; the other routes still share theirs.
$siteHeader = '<header class="site-header"><a href="index.html">Harbor Parish</a><nav><a href="index.html">Home</a><a href="about.html">About</a></nav></header>';
$unremovableFiles = array();
foreach (array('index', 'about', 'visit', 'events') as $slug) $unremovableFiles[$slug . '.html'] = '<!doctype html><html><body>' . $siteHeader . '<main><h1>' . ucfirst($slug) . '</h1></main></body></html>';
$unremovableResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $unremovableFiles))->toArray();
foreach ($unremovableResult['source_reports']['compiled_site']['pages'] as &$unremovablePage) {
    if ('events.html' !== $unremovablePage['source_path']) continue;
    $styled = '<!-- wp:group {"className":"site-header","style":{"border":{"bottom":{"width":"1px","style":"solid"}}},"tagName":"header"} --><header class="wp-block-group site-header" style="border-bottom-style:solid;border-bottom-width:1px"><!-- wp:paragraph --><p><a href="index.html">Harbor Parish</a></p><!-- /wp:paragraph --></header><!-- /wp:group -->';
    $unremovablePage['block_markup'] = $styled . '<!-- wp:group {"className":"page-head","tagName":"header"} --><header class="wp-block-group page-head"><!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Events</h1><!-- /wp:heading --></header><!-- /wp:group --><!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:paragraph --><p>Events body.</p><!-- /wp:paragraph --></main><!-- /wp:group -->';
}
unset($unremovablePage);
$unremovablePlan = (new WordPressSitePlan())->fromResult($unremovableResult);
$unremovablePages = array_column($unremovablePlan['pages'], null, 'source_path');
$unremovableHeaders = array_values(array_filter($unremovablePlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$unremovableSources = array_keys($unremovableHeaders[0]['provenance']['sources'] ?? array()); sort($unremovableSources);
$assert(1 === count($unremovableHeaders) && array('about.html', 'index.html', 'visit.html') === $unremovableSources && array('page-events') === ($unremovableHeaders[0]['placement']['excluded_template_slugs'] ?? null), 'The routes whose header is located share one header part: ' . json_encode(array_column($unremovablePlan['diagnostics'], 'message')));
foreach (array('index.html', 'about.html', 'visit.html') as $source) $assert(!str_contains((string) $unremovablePages[$source]['canonical_block_markup'], 'Harbor Parish'), "{$source} no longer carries the shared header.");
$assert(str_contains((string) $unremovablePages['events.html']['canonical_block_markup'], 'Harbor Parish') && str_contains((string) $unremovablePages['events.html']['canonical_block_markup'], 'page-head'), 'The route whose header cannot be located keeps both its headers.');
$assert(in_array('wordpress_site_plan_shell_retained_ambiguous', array_column(array_filter($unremovablePlan['diagnostics'], static fn(array $row): bool => 'events.html' === ($row['source_path'] ?? null)), 'code'), true), 'The retained route is reported.');

fwrite(STDOUT, "Route edge shared shell contract passed.\n");

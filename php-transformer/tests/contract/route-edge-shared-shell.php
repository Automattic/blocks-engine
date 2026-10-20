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

// Two chrome designs, each repeated and neither a landmark: there is no
// single site chrome, so each design is its own editable variant part at its
// routes' source position, header and footer alike.
$variantPage = static function (string $design, string $title): string {
    return '<!doctype html><html><body><div class="banner ' . $design . '"><a href="index.html">Harbor ' . $design . '</a><a href="about.html">About</a><a href="visit.html">Visit</a></div>'
        . '<main><h1>' . $title . '</h1></main>'
        . '<div class="colophon ' . $design . '"><a href="index.html">Home</a><a href="visit.html">Visit ' . $design . '</a></div></body></html>';
};
$variantEdgePlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $variantPage('light', 'Home'), 'about.html' => $variantPage('light', 'About'),
    'visit.html' => $variantPage('dark', 'Visit'), 'events.html' => $variantPage('dark', 'Events'),
)))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($variantEdgePlan);
foreach (array('header' => 'Harbor ', 'footer' => 'Visit ') as $area => $text) {
    $areaParts = array_values(array_filter($variantEdgePlan['template_parts'], static fn(array $part): bool => $area === ($part['area'] ?? null)));
    $assert(2 === count($areaParts) && array('inline_shared_shell') === array_values(array_unique(array_map(static fn(array $part): string => (string) ($part['placement']['kind'] ?? ''), $areaParts))) && array('route_variant') === array_values(array_unique(array_map(static fn(array $part): string => (string) ($part['provenance']['reason'] ?? ''), $areaParts))), "Each repeated {$area} design is one inline variant part: " . json_encode(array_column($variantEdgePlan['diagnostics'], 'message')));
    foreach (array('light', 'dark') as $design) $assert(1 === count(array_filter($areaParts, static fn(array $part): bool => str_contains((string) $part['canonical_block_markup'], $text . $design))), "The {$design} {$area} keeps its own design.");
}
$variantTemplates = array_column($variantEdgePlan['templates'], 'canonical_block_markup', 'slug');
foreach (array_column($variantEdgePlan['pages'], null, 'source_path') as $source => $row) {
    $markup = (string) $row['canonical_block_markup'];
    $slug = pathinfo($source, PATHINFO_FILENAME);
    $rendered = $markup . ($variantTemplates['index' === $slug ? 'front-page' : 'page-' . $slug] ?? $variantTemplates['page'] ?? '');
    $assert(!str_contains($markup, 'Harbor ') && !str_contains($markup, 'Visit light') && !str_contains($markup, 'Visit dark') && str_contains($markup, '</h1>'), "{$source} keeps its content and no chrome: {$markup}");
    $assert(2 === substr_count($rendered, '<!-- wp:template-part'), "{$source} renders one header and one footer variant: {$rendered}");
    preg_match_all('/wp:template-part \{"slug":"([^"]+)"/', $rendered, $referenced);
    foreach ($referenced[1] as $partSlug) $assert(in_array($source, array_column($variantEdgePlan['template_parts'], null, 'slug')[$partSlug]['placement']['source_paths'] ?? array(), true), "{$source} renders the variant its own design repeats ({$partSlug}).");
}

// The variant most routes use is the area's own part, titled after the area;
// each other variant is titled after the first route (in plan order) that
// uses it, so the
// editor never lists anonymous "Variant N" parts.
$titledPage = static fn(string $design, string $title): string => str_replace('<html><body>', '<html><head><title>' . $title . '</title></head><body>', $variantPage($design, $title));
$titledPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $titledPage('light', 'Home'), 'about.html' => $titledPage('light', 'About'), 'contact.html' => $titledPage('light', 'Contact'),
    'visit.html' => $titledPage('dark', 'Plan a Visit'), 'events.html' => $titledPage('dark', 'Events'),
)))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($titledPlan);
foreach (array('header' => 'Harbor ', 'footer' => 'Visit ') as $area => $text) {
    $titles = array();
    foreach ($titledPlan['template_parts'] as $part) {
        if ($area !== ($part['area'] ?? null)) continue;
        $titles[str_contains((string) $part['canonical_block_markup'], $text . 'light') ? 'light' : 'dark'] = $part['title'];
    }
    $label = ucfirst($area);
    $assert(array('light' => $label, 'dark' => $label . ' (Events)') === array('light' => $titles['light'] ?? null, 'dark' => $titles['dark'] ?? null), "The main {$area} variant is titled {$label} and the other after its first route: " . json_encode($titles));
}

// Once an area is settled by extracted parts, a route left with its own
// header/footer landmark gets it as a route-variant part instead of keeping
// site chrome in its post content. Three routes share a header and footer;
// the entrypoint has the same nav in a header with different classes and a
// longer footer, so the landmark clusters exclude it as non_equivalent.
$soleNav = '<nav><a href="index.html">Home</a><a href="about.html">About</a><a href="visit.html">Visit</a><a href="events.html">Events</a></nav>';
$solePage = static function (string $title, bool $home) use ($soleNav): string {
    $header = '<header class="masthead' . ($home ? ' masthead--overlay' : '') . '"><a class="brand" href="index.html">Harbor</a>' . $soleNav . '</header>';
    $footer = $home
        ? '<footer class="colophon colophon--wide"><p>Harbor, 1 Shore Road</p><a href="about.html">About us</a><a href="visit.html">Plan a visit</a><p>Open daily.</p></footer>'
        : '<footer class="colophon"><p>Harbor, 1 Shore Road</p><a href="about.html">About</a><a href="visit.html">Visit</a></footer>';
    return '<!doctype html><html><head><title>' . $title . '</title><style>.masthead{display:flex}.masthead--overlay{position:absolute;background:transparent}.colophon{background:#eee}</style></head><body>'
        . $header . '<main><h1>' . $title . '</h1><p>' . $title . ' body copy.</p></main>' . $footer . '</body></html>';
};
$solePlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $solePage('Home', true), 'about.html' => $solePage('About', false), 'visit.html' => $solePage('Visit', false), 'events.html' => $solePage('Events', false),
)))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($solePlan);
$soleParts = array_column($solePlan['template_parts'], null, 'slug');
$soleTemplates = array_column($solePlan['templates'], 'canonical_block_markup', 'slug');
foreach (array_column($solePlan['pages'], null, 'source_path') as $source => $row) {
    $markup = (string) $row['canonical_block_markup'];
    $slug = pathinfo($source, PATHINFO_FILENAME);
    $template = (string) ($soleTemplates['index' === $slug ? 'front-page' : 'page-' . $slug] ?? $soleTemplates['page'] ?? '');
    $assert(!preg_match('/"tagName":"(?:header|footer)"/', $markup) && str_contains($markup, 'body copy.'), "{$source} keeps its content and no header/footer landmark group: {$markup}");
    preg_match_all('/wp:template-part \{"slug":"([^"]+)"/', $template, $referenced);
    $areas = array_map(static fn(string $partSlug): ?string => $soleParts[$partSlug]['area'] ?? null, $referenced[1]);
    sort($areas);
    $assert(array('footer', 'header') === $areas, "{$source} renders through a template referencing one header part and one footer part: {$template}");
}
foreach (array('header', 'footer') as $area) {
    $variant = array_values(array_filter($soleParts, static fn(array $part): bool => $area === $part['area'] && array('index.html') === ($part['placement']['source_paths'] ?? null)));
    $assert(1 === count($variant) && ucfirst($area) . ' (Home)' === $variant[0]['title'] && 'route_variant' === ($variant[0]['provenance']['reason'] ?? null), "The home {$area} is its own route-variant part: " . json_encode(array_column($solePlan['template_parts'], 'title', 'slug')));
}
$assert(str_contains((string) $soleTemplates['front-page'], 'wp:post-content') && str_contains((string) $soleParts[array_key_first(array_filter($soleParts, static fn(array $part): bool => 'Footer (Home)' === $part['title']))]['canonical_block_markup'], 'Open daily.'), 'The front page keeps its post content and its own footer copy.');

// When repeated edge designs settle the area as variants, the entrypoint's
// own edge chrome is one more variant; its hero beside the chrome stays
// page content.
$soleVariantPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => str_replace('</div><main>', '</div><section class="hero"><h2>Big hero</h2><p>Welcome aboard.</p></section><main>', $titledPage('home', 'Home')),
    'about.html' => $titledPage('light', 'About'), 'contact.html' => $titledPage('light', 'Contact'),
    'visit.html' => $titledPage('dark', 'Visit'), 'events.html' => $titledPage('dark', 'Events'),
)))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($soleVariantPlan);
$soleVariantHome = (string) array_column($soleVariantPlan['pages'], null, 'source_path')['index.html']['canonical_block_markup'];
$soleVariantFront = (string) (array_column($soleVariantPlan['templates'], 'canonical_block_markup', 'slug')['front-page'] ?? '');
$assert(!str_contains($soleVariantHome, 'Harbor home') && !str_contains($soleVariantHome, 'Visit home') && str_contains($soleVariantHome, 'Big hero'), "The home edge chrome leaves the page and its hero stays: {$soleVariantHome}");
$assert(2 === substr_count($soleVariantHome . $soleVariantFront, '<!-- wp:template-part'), "The front page renders its own header and footer variants: {$soleVariantFront}");
$assert(array('Footer (Home)', 'Header (Home)') === array_values(array_intersect(array('Footer (Home)', 'Header (Home)'), array_column($soleVariantPlan['template_parts'], 'title'))), 'The home variants are named after the route: ' . json_encode(array_column($soleVariantPlan['template_parts'], 'title')));

fwrite(STDOUT, "Route edge shared shell contract passed.\n");

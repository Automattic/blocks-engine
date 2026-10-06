<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\ContentRoundTripReporter;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };
$pages = static function (array $plan): array { $rows = array(); foreach ($plan['pages'] as $page) $rows[$page['source_path']] = $page; return $rows; };
$writes = static function (array $plan): array { $rows = array(); foreach ($plan['writes'] as $write) $rows[$write['target_path']] = $write; return $rows; };

$sharedHeader = static function (string $home, string $about): string {
    return '<header id="site-chrome" class="site-header" style="border-top:3px solid #111"><nav><a href="' . $home . '">Home</a><a href="' . $about . '">About</a></nav></header>';
};
$artifact = array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><style>.site-header{background:#111;color:#fff}</style></head><body><a class="skip-link" href="#content">Skip to content</a>' . $sharedHeader('index.html', 'guides/about.html') . '<main id="content"><h1>Home</h1><article><header><p>Home article header</p></header><footer><p>Home article footer</p></footer></article></main><footer><p>Home footer</p></footer></body></html>',
    'guides/about.html' => '<!doctype html><html><body><a class="skip-link" href="#content">Skip to content</a><div id="site-chrome" class="site-header" style="border-top:3px solid #111" role="banner"><nav><a href="../index.html">Home</a><a href="about.html">About</a></nav></div><main id="content"><h1>About</h1><article><header><p>About article header</p></header><footer><p>About article footer</p></footer></article></main><footer><p>About footer</p></footer></body></html>',
    'guides/team.html' => '<!doctype html><html><body><a class="skip-link" href="#content">Skip to content</a>' . $sharedHeader('../index.html', 'about.html') . '<main id="content"><h1>Team</h1><article><header><p>Team article header</p></header><footer><p>Team article footer</p></footer></article></main><footer><p>Team footer</p></footer></body></html>',
));
$plan = (new ArtifactCompiler())->compile($artifact)->toArray()['source_reports']['wordpress_site_plan'];
$documents = $pages($plan); $declaredWrites = $writes($plan);
$header = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)))[0] ?? array();
$diagnostics = array_column($plan['diagnostics'], 'code');

$assert('header' === ($header['slug'] ?? null) && 1 === count(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null))) && 'extracted' === ($header['provenance']['decision'] ?? null) && 'canonical' === ($header['provenance']['reason'] ?? null), 'One canonical header template part is generated with accepted extraction provenance for semantically equivalent source shells.');
$assert(!array_filter($plan['template_parts'], static fn(array $part): bool => 'footer' === ($part['area'] ?? null)), 'Differing document footers remain page-local rather than becoming an ambiguous shared part.');
$assert(str_contains($header['canonical_block_markup'] ?? '', '{{wordpress-site-plan:navigation:' . $plan['menus'][0]['token'] . '}}') && str_contains($plan['menus'][0]['block_markup'], '"url":"/guides/about"') && str_contains($plan['menus'][0]['block_markup'], '"url":"/"'), 'The shared header references navigation whose route-relative destinations are canonicalized before shell identity comparison.');
$assert(str_contains($header['canonical_block_markup'] ?? '', '"anchor":"site-chrome"') && str_contains($header['canonical_block_markup'] ?? '', 'site-header') && str_contains($header['canonical_block_markup'] ?? '', 'border'), 'Shared shell preserves its canonical landmark wrapper anchor, class, and style attributes.');
foreach (array('index.html', 'guides/about.html', 'guides/team.html') as $source) {
    $markup = $documents[$source]['canonical_block_markup'] ?? '';
    $assert(1 === substr_count($markup, '"tagName":"header"') && str_contains($markup, 'Skip to content') && str_contains($markup, 'article header') && str_contains($markup, 'article footer') && 2 === substr_count($markup, '"tagName":"footer"'), "{$source} removes only the shared header and retains skip links, article landmarks, and its footer: {$markup}");
}
$assert(1 === substr_count($declaredWrites['templates/front-page.html']['payload']['data'], '"slug":"header"') && 1 === substr_count($declaredWrites['templates/page.html']['payload']['data'], '"slug":"header"') && 1 === substr_count($declaredWrites['templates/index.html']['payload']['data'], '"slug":"header"'), 'All base templates bind the shared header exactly once.');
$assert(1 === substr_count($declaredWrites['templates/search.html']['payload']['data'] ?? '', '"slug":"header","area":"header"'), 'The search template binds the shared header wherever index is bound, so search results do not render headerless.');
$assert(array() !== array_filter($plan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null) && str_contains((string) ($asset['content'] ?? ''), '.site-header')), 'Scoped shell styling is represented as a normal declared CSS asset write.');
$assert(in_array('wordpress_site_plan_shell_extracted', $diagnostics, true) && in_array('wordpress_site_plan_shell_retained_ambiguous', $diagnostics, true), 'Shell planning emits bounded extracted and retained reason-coded diagnostics.');
$assert(array_values(array_unique($diagnostics)) === array_values(array_unique($plan['reporting']['diagnostic_codes'])), 'Every shell diagnostic is linked through plan reporting.');

$nearMatch = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<header class="site-header"><nav><a href="index.html">Home</a></nav></header><main>Home</main>',
    'about.html' => '<header class="site-header"><nav><a href="index.html">Home</a></nav><a href="#contact">Contact us</a></header><main>About</main>',
)))->toArray()['source_reports']['wordpress_site_plan'];
$nearPages = $pages($nearMatch);
$nearDiagnostic = current(array_filter($nearMatch['diagnostics'], static fn(array $diagnostic): bool => 'wordpress_site_plan_shell_retained_ambiguous' === ($diagnostic['code'] ?? null) && 'header' === ($diagnostic['area'] ?? null)));
$assert(str_contains($nearPages['index.html']['canonical_block_markup'] ?? '', 'Home') && str_contains($nearPages['about.html']['canonical_block_markup'] ?? '', 'Contact us') && 1 === substr_count($nearPages['index.html']['canonical_block_markup'] ?? '', '"tagName":"header"') && 1 === substr_count($nearPages['about.html']['canonical_block_markup'] ?? '', '"tagName":"header"') && !array_filter($nearMatch['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)) && 'retained' === ($nearDiagnostic['provenance']['decision'] ?? null) && 'non_equivalent' === ($nearDiagnostic['provenance']['reason'] ?? null) && array('about.html', 'index.html') === array_keys($nearDiagnostic['provenance']['sources'] ?? array()), 'Ambiguous multipage headers remain exactly once per page with generic retained-extraction provenance.');

$variantHeader = static fn(string $appearance, string $title): string => '<header class="site-header ' . $appearance . '"><p>Company brand</p><nav><a href="/">Home</a><a href="/about">About</a></nav></header><main><h1>' . $title . '</h1></main>';
$variantPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $variantHeader('light', 'Homepage'),
    'team.html' => $variantHeader('light', 'Team'),
    'about.html' => $variantHeader('dark', 'About'),
    'events.html' => $variantHeader('dark', 'Events'),
)))->toArray()['source_reports']['wordpress_site_plan'];
$variantParts = array_values(array_filter($variantPlan['template_parts'] ?? array(), static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$variantPages = $pages($variantPlan);
$assert(2 === count($variantParts) && count(array_unique(array_column($variantParts, 'slug'))) === 2 && 2 === count(array_filter($variantParts, static fn(array $part): bool => 'inline_shared_shell' === ($part['placement']['kind'] ?? null))), 'Two distinct, repeated source header appearances become two editable shared variants.');
foreach (array('index.html' => 'Homepage', 'team.html' => 'Team', 'about.html' => 'About', 'events.html' => 'Events') as $source => $title) {
    $markup = $variantPages[$source]['canonical_block_markup'] ?? '';
    $assert(1 === substr_count($markup, '<!-- wp:template-part') && !str_contains($markup, 'Company brand') && str_contains($markup, $title), "{$source} binds only its corresponding header variant and keeps page content.");
}
$assert(1 === count(array_filter($variantParts, static fn(array $part): bool => str_contains((string) ($part['canonical_block_markup'] ?? ''), 'site-header light'))) && 1 === count(array_filter($variantParts, static fn(array $part): bool => str_contains((string) ($part['canonical_block_markup'] ?? ''), 'site-header dark'))), 'Route variants retain their independently authored appearance.');
WordPressSitePlan::assertValid($variantPlan);
$variantBootstrap = $writes($variantPlan)['functions.php']['payload']['data'] ?? '';
$assert(!str_contains($variantBootstrap, "    \$slugs = array (") && 2 === count(array_filter($variantPlan['template_parts'], static fn(array $part): bool => 'route_variant' === ($part['provenance']['reason'] ?? null))), 'Route variants retain Core template-part semantic header wrappers instead of stripping them at render time.');

$singleResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<header id="solo-shell" class="solo" style="border-top:2px solid #111"><p>Solo</p></header><main>Home</main><footer>Solo footer</footer>')))->toArray();
$single = $singleResult['source_reports']['wordpress_site_plan'];
$singleWrites = $writes($single);
$assert(2 === count(array_filter($single['template_parts'], static fn(array $part): bool => 'entry_shell' === ($part['placement']['kind'] ?? null))) && str_contains($singleWrites['templates/front-page.html']['payload']['data'], '"slug":"header","area":"header","tagName":"header"') && str_contains($singleWrites['templates/front-page.html']['payload']['data'], '"slug":"footer","area":"footer","tagName":"footer"') && !str_contains($singleWrites['templates/page.html']['payload']['data'], '"slug":"header"') && !str_contains($singleWrites['templates/index.html']['payload']['data'], '"slug":"header"'), 'A single-page artifact binds semantic entry-shell template parts only to front-page.');
$singleHeader = array_values(array_filter($single['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)))[0] ?? array();
$assert(!str_contains($singleHeader['canonical_block_markup'] ?? '', '"tagName":"header"') && !str_contains($singleHeader['canonical_block_markup'] ?? '', '<header') && str_contains($singleHeader['canonical_block_markup'] ?? '', '"className":"solo"') && str_contains($singleHeader['canonical_block_markup'] ?? '', '"anchor":"solo-shell"') && str_contains($singleHeader['canonical_block_markup'] ?? '', 'border-top:2px solid #111') && str_contains($singleHeader['canonical_block_markup'] ?? '', 'Solo') && !str_contains($pages($single)['index.html']['canonical_block_markup'] ?? '', 'Solo</p>'), 'Single-page entry chrome preserves source presentation without nesting a landmark inside the semantic template-part wrapper.');
$compiledHeader = array_values(array_filter($singleResult['source_reports']['compiled_site']['template_parts'] ?? array(), static fn(array $part): bool => 'header' === ($part['area'] ?? null)))[0] ?? array();
$compiledPage = $singleResult['source_reports']['compiled_site']['pages'][0] ?? array();
$assert('entry_shell' === ($compiledHeader['placement']['kind'] ?? null) && !str_contains($compiledHeader['block_markup'] ?? '', '"tagName":"header"') && !str_contains($compiledHeader['block_markup'] ?? '', '<header') && str_contains($compiledHeader['block_markup'] ?? '', '"className":"solo"') && str_contains($compiledHeader['block_markup'] ?? '', '"anchor":"solo-shell"') && str_contains($compiledHeader['block_markup'] ?? '', 'border-top:2px solid #111') && str_contains($compiledHeader['block_markup'] ?? '', 'Solo') && 1 === substr_count($compiledPage['block_markup'] ?? '', 'Solo</p>'), 'The compiled-site compatibility report retains exactly one entry shell until the plan accepts extraction.');
$assert(str_contains($singleHeader['canonical_block_markup'] ?? '', '"className":"solo"') && str_contains($singleHeader['canonical_block_markup'] ?? '', '"anchor":"solo-shell"') && str_contains($singleHeader['canonical_block_markup'] ?? '', 'border-top:2px solid #111') && str_contains($singleHeader['canonical_block_markup'] ?? '', 'Solo') && !str_contains($singleHeader['canonical_block_markup'] ?? '', '"tagName":"header"') && !str_contains($singleHeader['canonical_block_markup'] ?? '', '<header'), 'The canonical plan retains source presentation without nesting a header landmark inside the template-part wrapper.');

$responsiveFooter = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<!doctype html><html><head><style>@layer utilities{.mt-8{margin-top:32px}@media(min-width:48rem){.md\\:mt-16{margin-top:64px}}}</style></head><body><main>Home</main><footer class="mt-8 md:mt-16"><p>Colophon</p></footer></body></html>')))->toArray()['source_reports']['wordpress_site_plan'];
$responsiveFooterPart = array_values(array_filter($responsiveFooter['template_parts'] ?? array(), static fn(array $part): bool => 'footer' === ($part['area'] ?? null)))[0] ?? array();
$responsiveFooterMarkup = (string) ($responsiveFooterPart['canonical_block_markup'] ?? '');
$assert(str_contains($responsiveFooterMarkup, 'blocks-engine-responsive-margin-top-') && !str_contains($responsiveFooterMarkup, '"tagName":"footer"') && !str_contains($responsiveFooterMarkup, '<footer'), 'The extracted template-part root retains its layered responsive margin support without nesting the source landmark.');

// CSS-owned wrappers must continue to contain their shared editable shell parts.
$nestedViewportHtml = '<!doctype html><html><head><style>.viewport-shell{display:flex;flex-direction:column;height:100vh}.viewport-shell main{flex:1}</style></head><body><div class="viewport-shell"><header class="site-header"><h1>Brand</h1></header><main><p>Middle</p></main><section><p>Details</p></section><footer class="site-footer"><p>Colophon</p></footer></div></body></html>';
$nestedViewportPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $nestedViewportHtml)))->toArray()['source_reports']['wordpress_site_plan'];
$nestedViewportPage = $pages($nestedViewportPlan)['index.html']['canonical_block_markup'] ?? '';
$nestedViewportParts = array_column($nestedViewportPlan['template_parts'], null, 'slug');
$nestedViewportFront = $writes($nestedViewportPlan)['templates/front-page.html']['payload']['data'] ?? '';
$headerRef = strpos($nestedViewportPage, '"slug":"header"');
$main = strpos($nestedViewportPage, '>Middle</p>');
$footerRef = strpos($nestedViewportPage, '"slug":"footer"');
$assert(isset($nestedViewportParts['header'], $nestedViewportParts['footer']) && false !== $headerRef && false !== $main && false !== $footerRef && $headerRef < $main && $main < $footerRef && $footerRef < strrpos($nestedViewportPage, '</div><!-- /wp:group -->') && 1 === substr_count($nestedViewportPage, 'className":"viewport-shell') && 1 === substr_count($nestedViewportPage, 'class="wp-block-group viewport-shell') && str_contains($nestedViewportPage, 'wp:template-part') && !str_contains($nestedViewportFront, 'wp:template-part'), 'A viewport-height CSS-owned wrapper contains the two editable template parts in source order, without duplicating its wrapper in the front-page template.');
WordPressSitePlan::assertValid($nestedViewportPlan);

$heroResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<header class="hero"><h1>Editable hero</h1><p>Page introduction</p></header><main><p>Content</p></main><footer>Footer</footer>')))->toArray();
$heroPlan = $heroResult['source_reports']['wordpress_site_plan'];
$heroPage = $pages($heroPlan)['index.html'] ?? array();
$assert(!array_filter($heroPlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)) && str_contains($heroPage['canonical_block_markup'] ?? '', 'Editable hero') && str_contains($heroPage['canonical_block_markup'] ?? '', '"tagName":"header"'), 'A top-level header carrying the document heading is page-owned hero content rather than a template part.');

$siteTitleResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<header><h1>Site title</h1><nav><a href="/">Home</a></nav></header><main>Home</main>', 'about.html' => '<header><h1>Site title</h1><nav><a href="/">Home</a></nav></header><main>About</main>')))->toArray();
$siteTitlePlan = $siteTitleResult['source_reports']['wordpress_site_plan'];
$assert(1 === count(array_filter($siteTitlePlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null))) && !str_contains(($pages($siteTitlePlan)['index.html']['canonical_block_markup'] ?? ''), 'Site title'), 'A repeated header with both a site title and navigation remains eligible shared chrome.');

$documentHeader = static fn(string $hero): string => '<header class="site-header"><h1>Lake Country Players</h1><nav><a href="/">Home</a><a href="/about">About</a></nav></header><main><header class="hero"><h2>' . $hero . '</h2></header><p>Page content</p></main>';
$documentHeaderResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $documentHeader('Home production'),
    'about.html' => $documentHeader('About the company'),
)))->toArray()['source_reports']['wordpress_site_plan'];
$documentHeaderPages = $pages($documentHeaderResult);
$documentHeaderPart = current(array_filter($documentHeaderResult['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(is_array($documentHeaderPart) && str_contains($documentHeaderPart['canonical_block_markup'] ?? '', 'Lake Country Players') && str_contains($documentHeaderPart['canonical_block_markup'] ?? '', 'wp:navigation'), 'An equivalent root-level site header becomes one editable shared template part with navigation.');
foreach (array('index.html' => 'Home production', 'about.html' => 'About the company') as $source => $hero) {
    $markup = $documentHeaderPages[$source]['canonical_block_markup'] ?? '';
    $assert(!str_contains($markup, 'Lake Country Players') && !str_contains($markup, 'wp:navigation') && str_contains($markup, $hero), "{$source} leaves shared header markup out of page content while retaining its page-owned hero.");
}
$documentHeaderWrites = $writes($documentHeaderResult);
$assert(1 === substr_count($documentHeaderWrites['templates/front-page.html']['payload']['data'] ?? '', '"slug":"header"') && 1 === substr_count($documentHeaderWrites['templates/page.html']['payload']['data'] ?? '', '"slug":"header"'), 'Front-page and page templates each bind the editable shared header once.');

$boundForm = '<form method="post"><input type="email" name="email"><button type="submit">Join</button></form>';
$duplicateBoundFormResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<div id="siteWrapper" class="site-wrapper"><header><nav><a href="/">Home</a><a href="/about">About</a></nav></header><main><h1>Home</h1>' . $boundForm . '</main><footer>' . $boundForm . '</footer></div>',
    'about.html' => '<div id="siteWrapper" class="site-wrapper"><header><nav><a href="/">Home</a><a href="/about">About</a></nav></header><main><h1>About</h1>' . $boundForm . '</main><footer>' . $boundForm . '</footer></div>',
)))->toArray()['source_reports']['wordpress_site_plan'];
$duplicateBoundFormPages = $pages($duplicateBoundFormResult);
$assert(1 === count(array_filter($duplicateBoundFormResult['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null))) && !array_filter($duplicateBoundFormResult['template_parts'], static fn(array $part): bool => 'footer' === ($part['area'] ?? null)), 'A duplicate runtime-form anchor in an otherwise shared footer keeps that footer page-owned while the independent shared header extracts.');
foreach (array('index.html' => 'Home', 'about.html' => 'About') as $source => $title) {
    $markup = $duplicateBoundFormPages[$source]['canonical_block_markup'] ?? '';
    $assert(2 === substr_count($markup, 'Join') && str_contains($markup, $title), "{$source} retains both runtime form anchors and its unique page body after shared-header extraction.");
}

$incomplete = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<header>Shared</header><main>Home</main><footer>Shared footer</footer>', 'about.html' => '<header>Shared</header><main>About</main><footer>Shared footer</footer>', 'contact.html' => '<main>Contact</main><footer>Shared footer</footer>', 'services.html' => '<header>Services</header><main>Services</main><footer>Shared footer</footer>')))->toArray()['source_reports']['wordpress_site_plan'];
$multiple = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<header>One</header><header>Two</header><main>Home</main>', 'about.html' => '<header>One</header><header>Two</header><main>About</main>')))->toArray()['source_reports']['wordpress_site_plan'];
$incompletePages = $pages($incomplete); $incompleteWrites = $writes($incomplete);
$incompleteHeader = current(array_filter($incomplete['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$incompleteDiagnostic = current(array_filter($incomplete['diagnostics'], static fn(array $diagnostic): bool => 'wordpress_site_plan_shell_extracted' === ($diagnostic['code'] ?? null) && 'header' === ($diagnostic['area'] ?? null)));
$assert('header' === ($incompleteHeader['slug'] ?? null) && !str_contains($incompletePages['index.html']['canonical_block_markup'] ?? '', 'Shared') && !str_contains($incompletePages['about.html']['canonical_block_markup'] ?? '', 'Shared') && str_contains($incompletePages['contact.html']['canonical_block_markup'] ?? '', 'Contact') && str_contains($incompletePages['services.html']['canonical_block_markup'] ?? '', 'Services') && 1 === substr_count($incompleteWrites['templates/page-contact.html']['payload']['data'] ?? '', '"slug":"footer"') && !str_contains($incompleteWrites['templates/page-contact.html']['payload']['data'] ?? '', '"slug":"header"') && 1 === substr_count($incompleteWrites['templates/page-services.html']['payload']['data'] ?? '', '"slug":"footer"') && !str_contains($incompleteWrites['templates/page-services.html']['payload']['data'] ?? '', '"slug":"header"') && 2 === ($incompleteDiagnostic['page_count'] ?? null) && 4 === ($incompleteDiagnostic['applicable_page_count'] ?? null) && array(array('source_path' => 'contact.html', 'reason' => 'missing'), array('source_path' => 'services.html', 'reason' => 'non_equivalent')) === ($incompleteDiagnostic['exclusions'] ?? null), 'A dominant shell cluster extracts only for proven routes while missing and divergent shells remain local through deterministic route templates.');
$postCluster = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<header>Shared</header><main>Home</main>', 'notes/first.html' => '<header>Shared</header><main><article><time itemprop="datePublished" datetime="2026-08-01">First</time></article></main>', 'notes/second.html' => '<header>Shared</header><main><article><time itemprop="datePublished" datetime="2026-08-02">Second</time></article></main>', 'notes/variant.html' => '<header>Variant</header><main><article><time itemprop="datePublished" datetime="2026-08-03">Variant</time></article></main>')))->toArray()['source_reports']['wordpress_site_plan'];
$postWrites = $writes($postCluster); $postPages = $pages($postCluster);
$assert('post' === ($postPages['notes/first.html']['post_type'] ?? null) && 'post' === ($postPages['notes/second.html']['post_type'] ?? null) && 'post' === ($postPages['notes/variant.html']['post_type'] ?? null) && !str_contains($postWrites['templates/single-post-variant.html']['payload']['data'] ?? '', '"slug":"header"') && str_contains($postWrites['templates/index.html']['payload']['data'] ?? '', '"slug":"header"') && !isset($postWrites['templates/single-variant.html']) && !isset($postWrites['templates/single-post.html']), 'A divergent standard post receives the WordPress-specific single-post-{post_name} exclusion template without suppressing the shared shell for the clustered posts.');
$mixedShells = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<header>Shared header</header><main>Home</main><footer>Shared footer</footer>', 'about.html' => '<header>Shared header</header><main>About</main><footer>Shared footer</footer>', 'contact.html' => '<header>Shared header</header><main>Contact</main><footer>Contact footer</footer>')))->toArray()['source_reports']['wordpress_site_plan'];
$mixedWrites = $writes($mixedShells);
$assert(str_contains($mixedWrites['templates/page-contact.html']['payload']['data'] ?? '', '"slug":"header"') && !str_contains($mixedWrites['templates/page-contact.html']['payload']['data'] ?? '', '"slug":"footer"') && str_contains($mixedWrites['templates/page.html']['payload']['data'] ?? '', '"slug":"header"') && str_contains($mixedWrites['templates/page.html']['payload']['data'] ?? '', '"slug":"footer"'), 'A footer-only exclusion creates a route template that retains the globally shared header and excludes only the divergent footer.');
$assert(str_contains($mixedWrites['templates/search.html']['payload']['data'] ?? '', '"slug":"header"') && str_contains($mixedWrites['templates/search.html']['payload']['data'] ?? '', '"slug":"footer"'), 'The search template rides along with index for both the shared header and the shared footer, not only the header.');
$assert(!array_filter($multiple['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)) && in_array('wordpress_site_plan_shell_retained_incomplete', array_column($multiple['diagnostics'], 'code'), true), 'Multiple shell candidates remain page-local with bounded incomplete diagnostics.');

// Authored visibility belongs around each route's shared part reference, not
// inside the owner-editable part (where it would affect unrelated routes).
$footerPage = static fn(string $title, string $wrapper): string => '<!doctype html><html><body><main><h1>' . $title . '</h1></main>' . str_replace('%s', '<footer class="site-footer"><p>Shared colophon</p></footer>', $wrapper) . '</body></html>';
$footerPlanFor = static function (array $routes) use ($footerPage): array {
    $files = array();
    foreach ($routes as $source => $wrapper) $files[$source] = $footerPage(ucfirst(pathinfo($source, PATHINFO_FILENAME)), $wrapper);
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
    // Supply the authored block tree at the plan boundary: the HTML converter
    // may fold a sole ancestor into its footer group before planning begins.
    foreach ($result['source_reports']['compiled_site']['pages'] as &$page) {
        $title = ucfirst(pathinfo($page['source_path'], PATHINFO_FILENAME));
        $footer = '<!-- wp:group {"className":"site-footer","tagName":"footer"} --><footer class="wp-block-group site-footer"><!-- wp:paragraph --><p>Shared colophon</p><!-- /wp:paragraph --></footer><!-- /wp:group -->';
        $wrapper = $routes[$page['source_path']];
        preg_match('/class="([^"]+)"/', $wrapper, $class);
        $wrapped = '%s' === $wrapper ? $footer : '<!-- wp:group {"className":"' . $class[1] . '"} --><div class="wp-block-group ' . $class[1] . '">' . $footer . '</div><!-- /wp:group -->';
        $page['block_markup'] = '<!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:heading --><h2 class="wp-block-heading">' . $title . '</h2><!-- /wp:heading --></main><!-- /wp:group -->' . $wrapped;
    }
    unset($page);
    return (new WordPressSitePlan())->fromResult($result);
};
$footerPlan = $footerPlanFor(array(
    'index.html' => '<div class="hidden lg:block">%s</div>',
    'about.html' => '<div class="hidden lg:block">%s</div>',
    'team.html' => '<div class="hidden lg:block">%s</div>',
));
$footerTemplates = array_column($footerPlan['templates'], 'canonical_block_markup', 'slug');
$footerParts = array_values(array_filter($footerPlan['template_parts'], static fn(array $part): bool => 'footer' === ($part['slug'] ?? null)));
$assert(1 === count($footerParts) && 'inline_shared_shell' === ($footerParts[0]['placement']['kind'] ?? null) && in_array('hidden', $footerParts[0]['ancestor_context']['classes'] ?? array(), true) && in_array('lg:block', $footerParts[0]['ancestor_context']['classes'] ?? array(), true), 'The repeated footer has one shared owner and retains authored ancestor evidence.');
foreach (array('index.html', 'about.html', 'team.html') as $source) {
    $markup = $pages($footerPlan)[$source]['canonical_block_markup'] ?? '';
    $assert(1 === preg_match('/<!-- wp:group [^>]*"className":"hidden lg:block"[^>]*--><div class="wp-block-group hidden lg:block">\s*<!-- wp:template-part \{"slug":"footer"[^>]*\/-->\s*<\/div><!-- \/wp:group -->/', $markup), "{$source} wraps its one editable footer reference in the authored responsive group: {$markup}");
}
$assert(!str_contains($footerParts[0]['canonical_block_markup'], 'hidden lg:block') && str_contains($pages($footerPlan)['about.html']['canonical_block_markup'] ?? '', 'hidden lg:block'), 'The visibility wrapper stays route-owned around its reference rather than becoming global part content.');
WordPressSitePlan::assertValid($footerPlan);

$variantFooterPlan = $footerPlanFor(array(
    'index.html' => '<div class="hidden lg:block">%s</div>',
    'about.html' => '<div class="hidden lg:block">%s</div>',
    'contact.html' => '<div class="lg:hidden">%s</div>',
));
$variantTemplates = array_column($variantFooterPlan['templates'], 'canonical_block_markup', 'slug');
$variantPages = $pages($variantFooterPlan);
$assert(1 === substr_count($variantPages['about.html']['canonical_block_markup'], '"slug":"footer"') && str_contains($variantPages['about.html']['canonical_block_markup'], 'hidden lg:block') && 1 === substr_count($variantPages['contact.html']['canonical_block_markup'], '"slug":"footer"') && str_contains($variantPages['contact.html']['canonical_block_markup'], 'lg:hidden') && !str_contains($variantPages['contact.html']['canonical_block_markup'], 'hidden lg:block') && !str_contains($variantTemplates['page'] ?? '', 'hidden lg:block') && 1 === count(array_filter($variantFooterPlan['template_parts'], static fn(array $part): bool => 'footer' === ($part['slug'] ?? null))), 'Distinct authored visibility wrappers vary by route while the footer remains one shared editable part.');
WordPressSitePlan::assertValid($variantFooterPlan);

$responsiveLandmark = static function (string $area, string $id, string $class, string $content): string {
    return '<!-- wp:group {"anchor":"' . $id . '","className":"site-' . $area . ' ' . $class . '","tagName":"' . $area . '"} --><' . $area . ' id="' . $id . '" class="wp-block-group site-' . $area . ' ' . $class . '"><!-- wp:paragraph --><p>' . $content . '</p><!-- /wp:paragraph --></' . $area . '><!-- /wp:group -->';
};
$responsiveMarkup = static function (string $title, string $mobileHeader = 'Mobile header') use ($responsiveLandmark): string {
    $document = static function (string $variant, string $title, string $header, string $footer) use ($responsiveLandmark): string {
        return '<!-- wp:group {"className":"' . $variant . '-document"} --><div class="wp-block-group ' . $variant . '-document">'
            . $responsiveLandmark('header', $variant . '-header', $variant . '-header', $header)
            . '<!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:heading --><h2 class="wp-block-heading">' . $title . '</h2><!-- /wp:heading --></main><!-- /wp:group -->'
            . $responsiveLandmark('footer', $variant . '-footer', $variant . '-footer', $footer)
            . '</div><!-- /wp:group -->';
    };
    return $document('desktop', $title, 'Desktop header', 'Desktop footer') . $document('mobile', $title . ' mobile', $mobileHeader, 'Mobile footer');
};
$responsiveResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<main><h1>Home</h1></main>', 'about.html' => '<main><h1>About</h1></main>')))->toArray();
foreach ($responsiveResult['source_reports']['compiled_site']['pages'] as &$responsivePage) $responsivePage['block_markup'] = $responsiveMarkup('index.html' === $responsivePage['source_path'] ? 'Home' : 'About'); unset($responsivePage);
$responsive = (new WordPressSitePlan())->fromResult($responsiveResult);
$responsivePages = $pages($responsive); $responsiveWrites = $writes($responsive);
$responsiveParts = array_column($responsive['template_parts'], null, 'slug');
$assert(array('header-1', 'header-2', 'footer-1', 'footer-2') === array_keys($responsiveParts) && array() === array_filter($responsiveParts, static fn(array $part): bool => 'inline_shared_shell' !== ($part['placement']['kind'] ?? null)), 'Nested desktop and mobile landmarks become distinct inline shared template parts.');
foreach ($responsivePages as $source => $page) {
    $markup = $page['canonical_block_markup'] ?? '';
    $assert(1 === substr_count($markup, '"slug":"header-1"') && 1 === substr_count($markup, '"slug":"header-2"') && 1 === substr_count($markup, '"slug":"footer-1"') && 1 === substr_count($markup, '"slug":"footer-2"') && !str_contains($markup, 'desktop-header') && !str_contains($markup, 'mobile-header') && str_contains($markup, $source === 'index.html' ? '>Home</h2>' : '>About</h2>'), "{$source} retains route content and exact inline shell reference cardinality.");
}
$assert(str_contains($responsiveParts['header-1']['canonical_block_markup'] ?? '', 'desktop-header') && str_contains($responsiveParts['header-2']['canonical_block_markup'] ?? '', 'mobile-header') && str_contains($responsiveParts['footer-1']['canonical_block_markup'] ?? '', 'desktop-footer') && str_contains($responsiveParts['footer-2']['canonical_block_markup'] ?? '', 'mobile-footer'), 'Every responsive template part retains its authored landmark wrapper and presentation hooks.');
$assert(array() === array_filter($responsive['templates'], static fn(array $template): bool => str_contains($template['canonical_block_markup'] ?? '', '"slug":"header-1"')) && str_contains($responsiveWrites['functions.php']['payload']['data'] ?? '', "render_block_core/template-part") && str_contains($responsiveWrites['functions.php']['payload']['data'] ?? '', "'header-1'"), 'Inline shell references remain page-positioned while generated bootstrap removes only the Core transport wrapper.');
$responsiveTheme = json_decode($responsiveWrites['theme.json']['payload']['data'] ?? '', true);
$assert(array('header-1', 'header-2', 'footer-1', 'footer-2') === array_column($responsiveTheme['templateParts'] ?? array(), 'name') && array('header', 'header', 'footer', 'footer') === array_column($responsiveTheme['templateParts'] ?? array(), 'area'), 'Generated theme metadata exposes responsive shell parts in their Site Editor header and footer areas.');
$responsiveDiagnostic = current(array_filter($responsive['diagnostics'], static fn(array $diagnostic): bool => 'wordpress_site_plan_shell_inline_extracted' === ($diagnostic['code'] ?? null) && 'header' === ($diagnostic['area'] ?? null)));
$assert(2 === ($responsiveDiagnostic['variant_count'] ?? null) && 2 === ($responsiveDiagnostic['page_count'] ?? null) && array('about.html', 'index.html') === array_keys($responsiveParts['header-1']['provenance']['sources'] ?? array()), 'Inline extraction reports bounded variant counts and non-empty source provenance.');

$sourceResponsiveHtml = static fn(string $title): string => '<div class="desktop-document"><header class="desktop-header"><nav><a href="/">Home</a></nav></header><main><h1>' . $title . '</h1></main><footer class="desktop-footer">Desktop footer</footer></div><div class="mobile-document"><header class="mobile-header">Mobile header</header><main><h1>' . $title . ' mobile</h1></main><footer class="mobile-footer">Mobile footer</footer></div>';
$sourceResponsiveResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $sourceResponsiveHtml('Home'), 'about.html' => $sourceResponsiveHtml('About'))))->toArray();
$sourceResponsiveArtifacts = $sourceResponsiveResult['source_reports']['compiled_site']['inline_shell_artifacts'] ?? array();
$assert(array('header-1', 'header-2', 'footer-1', 'footer-2') === array_column($sourceResponsiveArtifacts, 'slug'), 'Source-identical nested shell variants are compiled once before route-specific page projection.');
$assert(array() === array_filter($sourceResponsiveArtifacts, static fn(array $artifact): bool => array('index.html', 'about.html') !== ($artifact['source_paths'] ?? array())), 'Canonical source-level shell artifacts retain every contributing route.');

// A runtime form inside responsive shared chrome moves with the chrome into
// the shared part as one entity, while each page-local form keeps its own
// binding. Every binding resolves exactly once in the document it names.
$bindingChrome = static function (string $title): string {
    return '<div class="desktop-shell"><header class="desktop-header"><nav><a href="/">Home</a></nav><form method="post" id="shared-signup" action="/signup"><input type="email" name="email"><button type="submit">Join</button></form></header><main><h1>' . $title . '</h1><form method="post" id="local-' . strtolower($title) . '" action="/contact"><input type="email" name="message"><button type="submit">Contact</button></form></main></div><div class="mobile-shell"><header class="mobile-header"><nav>Mobile</nav></header><main><p>' . $title . ' mobile</p></main></div>';
};
$bindingResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $bindingChrome('Home'),
    'about.html' => $bindingChrome('About'),
)))->toArray();
$bindingPlan = $bindingResult['source_reports']['wordpress_site_plan'] ?? array();
$bindingDocuments = array_column(array_merge($bindingPlan['pages'] ?? array(), $bindingPlan['template_parts'] ?? array()), null, 'source_path');
$bindingAnchors = array();
foreach ($bindingPlan['runtime_declarations'] ?? array() as $declaration) foreach ($declaration['payload']['entities'] ?? array() as $entity) foreach ($entity['bindings'] ?? array() as $binding) {
    $source = $binding['source_path'] ?? '';
    $markup = $bindingDocuments[$source]['canonical_block_markup'] ?? '';
    $search = $binding['search_block_markup'] ?? '';
    $bindingAnchors[] = is_string($search) && '' !== $search && 1 === substr_count($markup, $search);
}
$bindingHeaders = array_column(array_filter($bindingPlan['template_parts'] ?? array(), static fn(array $part): bool => 'header' === ($part['area'] ?? null)), null, 'slug');
$assert(array('header-1', 'header-2') === array_keys($bindingHeaders) && 'inline_shared_shell' === ($bindingHeaders['header-1']['placement']['kind'] ?? null) && str_contains((string) ($bindingHeaders['header-1']['canonical_block_markup'] ?? ''), 'shared-signup') && !str_contains((string) ($bindingHeaders['header-2']['canonical_block_markup'] ?? ''), 'shared-signup'), 'Responsive shared chrome whose form is the same on every page extracts each viewport header, with the form in its own variant.');
$assert(3 === count($bindingAnchors) && array() === array_filter($bindingAnchors, static fn(bool $resolved): bool => !$resolved), 'The shared form resolves once in the part and each page-local form once on its page.');
WordPressSitePlan::assertValid($bindingPlan);

$styledShellHtml = static fn(string $title): string => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><div><header><p>Shared header</p></header><main><h1>' . $title . '</h1></main><footer><p>Shared footer</p></footer></div></body></html>';
$styledShellResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $styledShellHtml('Home'),
    'about.html' => $styledShellHtml('About'),
    'site.css' => 'p{margin:0}',
)))->toArray();
$styledShellParts = $styledShellResult['source_reports']['compiled_site']['inline_shell_artifacts'] ?? array();
$styledShellClasses = array();
foreach ($styledShellParts as $part) {
    if (preg_match_all('/blocks-engine-source-p-[a-f0-9]+-\d+/', (string) ($part['block_markup'] ?? ''), $matches)) $styledShellClasses = array_merge($styledShellClasses, $matches[0]);
}
$styledShellCss = implode("\n", array_map(static fn(array $asset): string => 'css' === ($asset['kind'] ?? null) ? (string) ($asset['content'] ?? '') : '', $styledShellResult['source_reports']['wordpress_site_plan']['assets'] ?? array()));
$assert(array() !== $styledShellClasses && array() === array_filter(array_unique($styledShellClasses), static fn(string $class): bool => !str_contains($styledShellCss, '.' . $class)), 'Shared-shell selector projections are materialized for the native classes emitted inside extracted template parts.');
$sourceDivergentResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $sourceResponsiveHtml('Home'), 'about.html' => str_replace('Mobile header', 'Different mobile header', $sourceResponsiveHtml('About')))))->toArray();
$sourceDivergentArtifacts = $sourceDivergentResult['source_reports']['compiled_site']['inline_shell_artifacts'] ?? array();
$assert(!array_filter($sourceDivergentArtifacts, static fn(array $artifact): bool => 'header' === ($artifact['area'] ?? null)) && 2 === count(array_filter($sourceDivergentArtifacts, static fn(array $artifact): bool => 'footer' === ($artifact['area'] ?? null))), 'A divergent source variant rejects the complete area bundle while an independently shared area remains canonical.');

$unicodeResponsiveHtml = static function (string $title, string $current): string {
    $link = static function (string $name, string $label, string $current): string {
        return '<a class="site-link' . ($name === $current ? ' current" aria-current="page' : '') . '" href="/' . $name . '">' . $label . '</a>';
    };
    return '<div class="desktop-document"><header id="κεφαλίδα-🧭" class="desktop-header"><nav>' . $link('home', 'Αρχική σελίδα', $current) . $link('about', 'Σχετικά', $current) . '</nav></header><main><h1>' . $title . '</h1></main><footer id="υποσέλιδο-©" class="desktop-footer"><p>© 2026 Café ☕</p></footer></div>'
        . '<div class="mobile-document"><header id="μενού-📱" class="mobile-header"><p>Μενού 📱</p></header><main><h1>' . $title . ' mobile</h1></main><footer id="δικαιώματα-😀" class="mobile-footer"><p>Δικαιώματα © 😀</p></footer></div>';
};
$unicodeArtifact = array('entrypoint' => 'index.html', 'files' => array('index.html' => $unicodeResponsiveHtml('Home', 'home'), 'about.html' => $unicodeResponsiveHtml('About', 'about')));
$unicodeResult = (new ArtifactCompiler())->compile($unicodeArtifact)->toArray();
$unicodeArtifacts = array_column($unicodeResult['source_reports']['compiled_site']['inline_shell_artifacts'] ?? array(), null, 'slug');
$unicodeRepeatArtifacts = array_column((new ArtifactCompiler())->compile($unicodeArtifact)->toArray()['source_reports']['compiled_site']['inline_shell_artifacts'] ?? array(), null, 'slug');
$assert(array('header-1', 'header-2', 'footer-1', 'footer-2') === array_keys($unicodeArtifacts) && array_column($unicodeArtifacts, 'source_hash') === array_column($unicodeRepeatArtifacts, 'source_hash'), 'Unicode responsive shells retain deterministic identity across routes and current-navigation variants.');
$unicodeExpected = array(
    'header-1' => array('Αρχική σελίδα', 'Σχετικά', '"anchor":"κεφαλίδα-🧭"'),
    'header-2' => array('Μενού 📱', '"anchor":"μενού-📱"'),
    'footer-1' => array('© 2026 Café ☕', '"anchor":"υποσέλιδο-©"'),
    'footer-2' => array('Δικαιώματα © 😀', '"anchor":"δικαιώματα-😀"'),
);
$unicodeSource = $unicodeResponsiveHtml('Home', 'home');
$unicodeRoundTrip = new ContentRoundTripReporter();
foreach ($unicodeExpected as $slug => $fragments) {
    $markup = (string) ($unicodeArtifacts[$slug]['block_markup'] ?? '');
    $roundTrip = $unicodeRoundTrip->report($markup, $unicodeSource);
    $assert(1 === preg_match('//u', $markup) && 1 === preg_match('/^[a-f0-9]{64}$/', (string) ($unicodeArtifacts[$slug]['source_hash'] ?? '')) && !str_contains($markup, 'Îœ') && !str_contains($markup, 'Â©') && 'pass' === ($roundTrip['status'] ?? null) && array() === array_filter($fragments, static fn(string $fragment): bool => !str_contains($markup, $fragment)), "{$slug} preserves Unicode text, non-breaking spaces, emoji, symbols, and landmark attributes through shared-shell compilation: {$markup}");
}
$assert(!str_contains($unicodeArtifacts['header-1']['block_markup'] ?? '', 'aria-current') && !str_contains($unicodeArtifacts['header-1']['block_markup'] ?? '', ' current'), 'Unicode shell identity normalization removes only route-current navigation state.');
foreach ($unicodeResult['source_reports']['compiled_site']['pages'] ?? array() as $unicodeCompiledPage) {
    $markup = (string) ($unicodeCompiledPage['block_markup'] ?? '');
    $assert(1 === preg_match('//u', $markup) && !str_contains($markup, 'Îœ') && !str_contains($markup, 'Â©'), ($unicodeCompiledPage['source_path'] ?? 'page') . ' keeps page-owned UTF-8 after shared-shell extraction.');
}

foreach ($unicodeResult['source_reports']['compiled_site']['pages'] as &$unicodePage) {
    $title = 'index.html' === ($unicodePage['source_path'] ?? null) ? 'Home' : 'About';
    $unicodePage['block_markup'] = '<!-- wp:group {"className":"desktop-document"} --><div class="wp-block-group desktop-document">' . $unicodeArtifacts['header-1']['block_markup'] . '<!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:heading --><h2 class="wp-block-heading">' . $title . '</h2><!-- /wp:heading --></main><!-- /wp:group -->' . $unicodeArtifacts['footer-1']['block_markup'] . '</div><!-- /wp:group -->'
        . '<!-- wp:group {"className":"mobile-document"} --><div class="wp-block-group mobile-document">' . $unicodeArtifacts['header-2']['block_markup'] . '<!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:heading --><h2 class="wp-block-heading">' . $title . ' mobile</h2><!-- /wp:heading --></main><!-- /wp:group -->' . $unicodeArtifacts['footer-2']['block_markup'] . '</div><!-- /wp:group -->';
}
unset($unicodePage);
$unicodePlan = (new WordPressSitePlan())->fromResult($unicodeResult);
$unicodeParts = array_column($unicodePlan['template_parts'] ?? array(), null, 'slug');
$unicodeWrites = $writes($unicodePlan);
$assert(array_keys($unicodeArtifacts) === array_keys($unicodeParts), 'Every compiled Unicode responsive shell materializes as a template part.');
foreach ($unicodeParts as $slug => $part) {
    $content = (string) ($unicodeWrites['parts/' . $slug . '.html']['payload']['data'] ?? '');
    $assert(1 === preg_match('//u', $content) && !str_contains($content, 'Îœ') && !str_contains($content, 'Â©') && ($part['canonical_block_markup'] ?? null) === $content && hash('sha256', $content) === ($unicodeWrites['parts/' . $slug . '.html']['payload_hash'] ?? null) && 'pass' === ($unicodeRoundTrip->report($content, $unicodeSource)['status'] ?? null), "{$slug} remains byte-identical and valid UTF-8 through template-part materialization.");
    $ownedContent = $content;
    foreach ($unicodePlan['menus'] as $menu) if (str_contains($content, '{{wordpress-site-plan:navigation:' . $menu['token'] . '}}')) $ownedContent .= $menu['block_markup'];
    foreach ($unicodeExpected[$slug] as $fragment) $assert(str_contains($ownedContent, $fragment), "{$slug} materialization preserves {$fragment} through its owned navigation entity.");
}

$styledSvg = '<svg viewBox="0 0 16 16"><path fill="#123456" d="M1 1h14v14H1z"/></svg>';
$styledSvgDocument = static fn(string $title): string => '<style>.logo svg{width:100%;height:100%}</style><div class="desktop-document"><header class="desktop-header"><div class="logo">' . $styledSvg . '</div></header><main><h1>' . $title . '</h1></main></div><div class="mobile-document"><header class="mobile-header"><div class="logo">' . $styledSvg . '</div></header><main><h1>' . $title . ' mobile</h1></main></div>';
$styledSvgResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $styledSvgDocument('Home'), 'about.html' => $styledSvgDocument('About'))))->toArray();
$styledSvgCompiled = $styledSvgResult['source_reports']['compiled_site'] ?? array();
$styledSvgAssetPaths = array_column(array_filter($styledSvgCompiled['assets'] ?? array(), static fn(array $asset): bool => 'inline-svg' === ($asset['source'] ?? null)), 'path');
$styledSvgShellPaths = array();
foreach ($styledSvgCompiled['inline_shell_artifacts'] ?? array() as $artifact) if (preg_match_all('@assets/materialized-svg/[^" ]+@', $artifact['block_markup'] ?? '', $matches)) $styledSvgShellPaths = array_merge($styledSvgShellPaths, $matches[0]);
$assert('failed' !== ($styledSvgResult['status'] ?? null) && 2 === count($styledSvgAssetPaths) && array() === array_diff($styledSvgShellPaths, $styledSvgAssetPaths), 'Shared shell compilation declares its CSS-context-specific materialized SVG asset instead of leaving an unresolved local browser reference.');

$responsiveDivergentResult = $responsiveResult;
foreach ($responsiveDivergentResult['source_reports']['compiled_site']['pages'] as &$responsivePage) $responsivePage['block_markup'] = $responsiveMarkup('index.html' === $responsivePage['source_path'] ? 'Home' : 'About', 'about.html' === $responsivePage['source_path'] ? 'Different mobile header' : 'Mobile header'); unset($responsivePage);
$responsiveDivergent = (new WordPressSitePlan())->fromResult($responsiveDivergentResult);
$assert(!array_filter($responsiveDivergent['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)) && str_contains($pages($responsiveDivergent)['index.html']['canonical_block_markup'] ?? '', 'desktop-header') && str_contains($pages($responsiveDivergent)['about.html']['canonical_block_markup'] ?? '', 'Different mobile header'), 'A divergent responsive variant keeps every header variant page-owned instead of partially extracting the bundle.');

$waveShell = static function (string $identity, string $current, string $content): string {
    $sourceClass = 'blocks-engine-source-div-' . $identity . '-4';
    return '<input class="nav-trigger" type="checkbox" id="navTrigger"><div id="wrapper" class="site-frame ' . $sourceClass . '"><div id="header-wrapper-sticky-wrapper" class="' . $sourceClass . '"><div id="header-wrapper"><div class="logo">Brand</div><nav><a class="' . ('home' === $current ? 'current' : '') . '" href="index.html">Home</a><a class="' . ('about' === $current ? 'current' : '') . '" href="about.html">About</a><a class="' . ('contact' === $current ? 'current' : '') . '" href="contact.html">Contact</a></nav></div></div><div id="main-container"><main><h1>' . $content . '</h1></main></div></div>';
};
$waveResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<style>.nav-trigger{appearance:none;border:1px solid}.current{text-decoration:underline}</style>' . $waveShell('a1b2c3d4', 'home', 'Home'),
    'about.html' => '<style>.nav-trigger{appearance:none;border:1px solid}.current{text-decoration:underline}</style>' . $waveShell('b2c3d4e5', 'about', 'About'),
    'contact.html' => '<style>.nav-trigger{appearance:none;border:1px solid}.current{text-decoration:underline}</style>' . $waveShell('c3d4e5f6', 'contact', 'Contact'),
)))->toArray();
$wavePlan = $waveResult['source_reports']['wordpress_site_plan']; $wavePages = $pages($wavePlan); $waveWrites = $writes($wavePlan);
$waveHeader = array_values(array_filter($wavePlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)))[0] ?? array();
$assert(1 === count(array_filter($wavePlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null))) && str_contains($waveHeader['canonical_block_markup'] ?? '', 'wp:navigation') && str_contains($waveHeader['canonical_block_markup'] ?? '', 'Brand') && !str_contains($waveHeader['canonical_block_markup'] ?? '', 'authored-input') && !str_contains($waveHeader['canonical_block_markup'] ?? '', 'blocks-engine-current-navigation-item'), 'A repeated generic wrapper with nested navigation extracts one chrome part while core/navigation supersedes its preceding checkbox toggle and route-current source state.');
foreach (array('index.html' => 'Home', 'about.html' => 'About', 'contact.html' => 'Contact') as $source => $title) $assert(!str_contains($wavePages[$source]['canonical_block_markup'] ?? '', 'header-wrapper') && !str_contains($wavePages[$source]['canonical_block_markup'] ?? '', 'navTrigger') && str_contains($wavePages[$source]['canonical_block_markup'] ?? '', '>' . $title . '</h1>'), "{$source} retains only its page-content subtree after generic nested chrome extraction.");
$indexMarkup = $waveWrites['templates/index.html']['payload']['data'] ?? '';
$assert(1 === substr_count($indexMarkup, '"slug":"header"') && str_contains($indexMarkup, 'wp:query') && str_contains($indexMarkup, 'wp:post-template') && !str_contains($indexMarkup, 'wp:post-content') && str_contains($indexMarkup, '"anchor":"wrapper"'), 'Index restores the shared wrapper context around the header part and native Query Loop.');
foreach (array('templates/page.html', 'templates/front-page.html') as $target) $assert(1 === substr_count($waveWrites[$target]['payload']['data'] ?? '', '"slug":"header"') && 1 === substr_count($waveWrites[$target]['payload']['data'] ?? '', 'wp:post-content') && str_contains($waveWrites[$target]['payload']['data'] ?? '', '"anchor":"wrapper"'), "{$target} restores the shared wrapper context around the header part and singular post content.");

$statefulWaveShell = static function (string $current, string $content, bool $color = false): string {
    $link = static function (string $name, string $label, string $current): string {
        $active = $name === $current;
        return '<a class="site-link' . ($active ? ' current" id="' . $name . '-source" style="font-weight:700" aria-current="page"' : '"') . ' href="https://example.test/' . $name . '">' . $label . '</a>';
    };
    if ($color) $link = static function (string $name, string $label, string $current): string {
        $active = $name === $current;
        return '<a class="site-link' . ($active ? ' current" id="' . $name . '-source" style="color:#aa1100;font-weight:700" aria-current="page"' : '"') . ' href="https://example.test/' . $name . '">' . $label . '</a>';
    };
    return '<input class="nav-trigger" type="checkbox" id="navTrigger"><div id="wrapper" class="site-frame blocks-engine-source-div-a1b2c3d4-4"><div id="header-wrapper-sticky-wrapper" class="blocks-engine-source-div-a1b2c3d4-4"><div id="header-wrapper"><div class="logo">Brand</div><nav>' . $link('home', 'Home', $current) . $link('about', 'About', $current) . $link('services', 'Services', $current) . '</nav></div></div><div id="main-container"><main><h1>' . $content . '</h1></main></div></div>';
};
$statefulResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<style>.nav-trigger{appearance:none;border:1px solid}.current{text-decoration:underline}</style>' . $statefulWaveShell('home', 'Home', true),
    'about.html' => '<style>.nav-trigger{appearance:none;border:1px solid}.current{text-decoration:underline}</style>' . $statefulWaveShell('about', 'About', true),
    'services.html' => '<style>.nav-trigger{appearance:none;border:1px solid}.current{text-decoration:underline}</style>' . $statefulWaveShell('services', 'Services', true),
    // This responsive route has no extractable nested chrome candidate and stays page-owned.
    'contact.html' => '<div class="responsive-contact"><main><h1>Contact</h1></main></div>',
)))->toArray();
$statefulPlan = $statefulResult['source_reports']['wordpress_site_plan']; $statefulPages = $pages($statefulPlan);
$statefulHeader = array_values(array_filter($statefulPlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)))[0] ?? array();
$statefulDiagnostic = current(array_filter($statefulPlan['diagnostics'], static fn(array $diagnostic): bool => 'wordpress_site_plan_shell_extracted' === ($diagnostic['code'] ?? null) && 'header' === ($diagnostic['area'] ?? null)));
$statefulMarkup = (string) ($statefulHeader['canonical_block_markup'] ?? '') . implode('', array_column($statefulPlan['menus'], 'block_markup'));
$assert(1 === count(array_filter($statefulPlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null))) && !str_contains($statefulPages['index.html']['canonical_block_markup'] ?? '', 'header-wrapper') && !str_contains($statefulPages['about.html']['canonical_block_markup'] ?? '', 'header-wrapper') && !str_contains($statefulPages['services.html']['canonical_block_markup'] ?? '', 'header-wrapper'), 'Nested headers differing only by route-current navigation state produce one shared header and leave page content without its shell.');
$assert(!str_contains($statefulMarkup, 'blocks-engine-current-navigation-item') && 1 === preg_match_all('/blocks-engine-navigation-current-color-[a-f0-9]{64}/', $statefulMarkup) && str_contains($statefulMarkup, 'blocks-engine-navigation-link-color-states-0') && !str_contains($statefulMarkup, 'blocks-engine-navigation--color-') && !str_contains($statefulMarkup, '"anchor":"home-source"') && str_contains($statefulMarkup, 'site-link'), 'The emitted header keeps its navigation-root current-color and link-state carriers while removing child route state without corrupting tokens or non-state presentation.');
$assert(str_contains($statefulPages['contact.html']['canonical_block_markup'] ?? '', 'Contact') && !str_contains($statefulPages['contact.html']['canonical_block_markup'] ?? '', 'header-wrapper') && array(array('source_path' => 'contact.html', 'reason' => 'missing')) === ($statefulDiagnostic['exclusions'] ?? null), 'A responsive route without an equivalent nested header candidate remains explicitly page-owned.');

$variantShell = static function (string $current, bool $variant): string {
    $link = static function (string $name, string $current, bool $variant): string {
        $style = $variant && 'services' === $name ? ' style="letter-spacing:3px"' : '';
        return '<a class="site-link' . ($name === $current ? ' current' : '') . '"' . $style . ' href="https://example.test/' . $name . '">' . ucfirst($name) . '</a>';
    };
    return '<div id="wrapper"><div id="header-wrapper-sticky-wrapper"><div id="header-wrapper"><nav>' . $link('home', $current, $variant) . $link('about', $current, $variant) . $link('services', $current, $variant) . '</nav></div></div><div id="main-container"><main><h1>Content</h1></main></div></div>';
};
$variantResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<style>.current{text-decoration:underline}</style>' . $variantShell('home', false),
    'about.html' => '<style>.current{text-decoration:underline}</style>' . $variantShell('about', true),
)))->toArray();
$variantPlan = $variantResult['source_reports']['wordpress_site_plan'];
$variantDiagnostic = current(array_filter($variantPlan['diagnostics'], static fn(array $diagnostic): bool => 'wordpress_site_plan_shell_retained_ambiguous' === ($diagnostic['code'] ?? null) && 'header' === ($diagnostic['area'] ?? null)));
$assert(array() === array_values(array_filter($variantPlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null))) && 'non_equivalent' === ($variantDiagnostic['provenance']['reason'] ?? null), 'A non-current navigation presentation difference prevents false shared-header equivalence.');

// Shared preparation must see the same linked stylesheet occurrences as page
// workers, including repeated links with distinct media conditions.
$linkedShellDocument = static fn(string $title): string => '<!doctype html><html><head><link rel="stylesheet" href="assets/site.css"><link rel="stylesheet" href="assets/site.css" media="print"></head><body><div class="site"><main><h1>' . $title . '</h1></main><footer class="site-footer"><div class="grid"><div><p>Contact</p></div><div><p>Social links</p></div></div></footer></div></body></html>';
$linkedShellArtifact = array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => $linkedShellDocument('Home')),
    array('path' => 'about.html', 'content' => $linkedShellDocument('About')),
    array('path' => 'assets/site.css', 'content' => '.grid{display:grid;gap:12px}', 'metadata' => array('compilation' => array('scope' => 'shared'))),
));
$nestedLinkedShellFiles = array_map(static function (array $file): array {
    $file['path'] = 'site/' . $file['path'];
    return $file;
}, $linkedShellArtifact['files']);
foreach (array(
    $linkedShellArtifact,
    array('files' => $nestedLinkedShellFiles),
    array('entrypoint' => 'missing.html', 'files' => $nestedLinkedShellFiles),
) as $linkedVariant) {
    $linkedShellCompiler = new ArtifactCompiler();
    $linkedShellWhole = $linkedShellCompiler->compile($linkedVariant)->toArray()['source_reports']['compiled_site'];
    $linkedShellShared = $linkedShellCompiler->prepareShared($linkedVariant);
    $linkedShellPages = $linkedShellCompiler->preparePages($linkedVariant, $linkedShellShared);
    $linkedShellReceipts = $linkedShellCompiler->compilePreparedPages($linkedShellShared, $linkedShellPages);
    $linkedShellStaged = $linkedShellCompiler->compose($linkedShellShared, $linkedShellReceipts)->toArray()['source_reports']['compiled_site'];
    $linkedShellFooter = static fn(array $compiled): string => (string) ((array_values(array_filter($compiled['inline_shell_artifacts'], static fn(array $part): bool => 'footer' === ($part['area'] ?? null)))[0] ?? array())['block_markup'] ?? '');
    $linkedWholeFooter = $linkedShellFooter($linkedShellWhole);
    $linkedStagedFooter = $linkedShellFooter($linkedShellStaged);
    $assert(str_contains($linkedWholeFooter, 'blocks-engine-css-owned-grid') && !str_contains($linkedWholeFooter, '"layout":{"type":"grid"}'), 'A linked one-column source grid stays CSS-owned instead of acquiring WordPress automatic columns.');
    $assert($linkedWholeFooter === $linkedStagedFooter, 'Staged shared-shell compilation must resolve linked stylesheets before classifying footer layout, matching whole compilation.');
}

$nestedThemeHeader = static function (string $title, string $docHash): string {
    return '<!-- wp:group {"className":"site-root"} --><div class="wp-block-group site-root">'
        . '<!-- wp:group {"anchor":"SITE_HEADER","className":"SITE_HEADER blocks-engine-attribute-' . $docHash . '-15","tagName":"header"} --><header id="SITE_HEADER" class="wp-block-group SITE_HEADER blocks-engine-attribute-' . $docHash . '-15"><!-- wp:paragraph --><p>Brand</p><!-- /wp:paragraph --><!-- wp:paragraph --><p><a href="/">Work</a> <a href="/about">About</a></p><!-- /wp:paragraph --></header><!-- /wp:group -->'
        . '<!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:heading --><h2 class="wp-block-heading">' . $title . '</h2><!-- /wp:heading --></main><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';
};
$nestedThemeResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<main><h1>Home</h1></main>', 'about.html' => '<main><h1>About</h1></main>')))->toArray();
foreach ($nestedThemeResult['source_reports']['compiled_site']['pages'] as &$nestedThemePage) {
    $nestedThemePage['block_markup'] = $nestedThemeHeader('index.html' === $nestedThemePage['source_path'] ? 'Home' : 'About', 'index.html' === $nestedThemePage['source_path'] ? '62a405cae06c' : 'd27302898fe6');
}
unset($nestedThemePage);
$nestedThemePlan = (new WordPressSitePlan())->fromResult($nestedThemeResult);
$nestedThemePages = $pages($nestedThemePlan);
$nestedThemeWrites = $writes($nestedThemePlan);
$nestedThemeHeaderPart = array_values(array_filter($nestedThemePlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)))[0] ?? array();
$assert('inline_shared_shell' === ($nestedThemeHeaderPart['placement']['kind'] ?? null) && 1 === count(array_filter($nestedThemePlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null))), 'A repeated nested header has one shared owner with source-position references.');
$assert(str_contains($nestedThemeHeaderPart['canonical_block_markup'] ?? '', 'Brand') && str_contains($nestedThemeHeaderPart['canonical_block_markup'] ?? '', 'SITE_HEADER') && 1 === substr_count($nestedThemeHeaderPart['canonical_block_markup'] ?? '', '<header'), 'The shared part retains its authored landmark and presentation beneath an unwrapped transport reference.');
foreach (array('index.html' => 'Home', 'about.html' => 'About') as $source => $title) {
    $markup = $nestedThemePages[$source]['canonical_block_markup'] ?? '';
    $assert(!str_contains($markup, 'SITE_HEADER') && !str_contains($markup, 'Brand') && 1 === substr_count($markup, '"slug":"header"') && str_contains($markup, '>' . $title . '</h2>'), "{$source} replaces duplicated header content with one reference at the source position.");
}
$assert(!str_contains($nestedThemeWrites['templates/front-page.html']['payload']['data'] ?? '', '"slug":"header"') && !str_contains($nestedThemeWrites['templates/page.html']['payload']['data'] ?? '', '"slug":"header"'), 'Singular templates do not duplicate nested shared chrome outside its source layout.');

$nestedThemeDivergentResult = $nestedThemeResult;
foreach ($nestedThemeDivergentResult['source_reports']['compiled_site']['pages'] as &$nestedThemePage) {
    $nestedThemePage['block_markup'] = str_replace('Brand', 'index.html' === $nestedThemePage['source_path'] ? 'Brand' : 'Other brand', $nestedThemeHeader('index.html' === $nestedThemePage['source_path'] ? 'Home' : 'About', 'aaaaaa111111'));
}
unset($nestedThemePage);
$nestedThemeDivergent = (new WordPressSitePlan())->fromResult($nestedThemeDivergentResult);
$assert(array() === array_values(array_filter($nestedThemeDivergent['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null))) && str_contains($pages($nestedThemeDivergent)['index.html']['canonical_block_markup'] ?? '', 'Brand') && str_contains($pages($nestedThemeDivergent)['about.html']['canonical_block_markup'] ?? '', 'Other brand'), 'Nested headers that differ in authored content remain page-owned.');

$styleNormalizedShell = static fn(string $title, string $none): string => '<div class="desktop-document"><header class="desktop-header"><nav><a href="/">Home</a></nav></header><main><h1>' . $title . '</h1></main></div><div class="mobile-document"><header class="mobile-header"><ul aria-hidden="true" style="' . $none . '"></ul></header><main><h1>' . $title . ' mobile</h1></main></div>';
$styleNormalizedArtifacts = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $styleNormalizedShell('Home', 'display:none'),
    'about.html' => $styleNormalizedShell('About', 'display: none;'),
)))->toArray()['source_reports']['compiled_site']['inline_shell_artifacts'] ?? array();
$assert(array('header-1', 'header-2') === array_column($styleNormalizedArtifacts, 'slug'), 'Equivalent nested headers that differ only by style whitespace still compile as canonical shared shells.');

// A nested shell candidate's block-tree position must be authoritative for
// removal. Regression for https://github.com/Automattic/blocks-engine/issues/1861:
// a page whose shared header fragment also happens to repeat byte-for-byte
// elsewhere on the page (here, duplicated inside <main>, which disqualifies
// it as a candidate but leaves its bytes in the page) must still extract the
// single legitimate candidate deterministically instead of a string search
// finding two byte-identical matches and bailing as "ambiguous".
$duplicateFragmentShell = static fn(string $title): string => '<div class="wrap"><header id="chrome" class="site-header"><nav><a href="index.html">Home</a></nav></header><main><h1>' . $title . '</h1><header id="chrome" class="site-header"><nav><a href="index.html">Home</a></nav></header></main></div>';
$duplicateFragmentResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><body>' . $duplicateFragmentShell('Home') . '</body></html>',
    'about.html' => '<!doctype html><html><body>' . $duplicateFragmentShell('About') . '</body></html>',
)))->toArray();
$duplicateFragmentPlan = $duplicateFragmentResult['source_reports']['wordpress_site_plan'];
$duplicateFragmentPages = $pages($duplicateFragmentPlan);
$duplicateFragmentHeaders = array_values(array_filter($duplicateFragmentPlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$duplicateFragmentDiagnostic = current(array_filter($duplicateFragmentPlan['diagnostics'], static fn(array $diagnostic): bool => 'header' === ($diagnostic['area'] ?? null)));
$assert(1 === count($duplicateFragmentHeaders), 'A page whose shared header fragment repeats byte-for-byte elsewhere on the page still extracts exactly one template part.');
$assert('wordpress_site_plan_shell_extracted' === ($duplicateFragmentDiagnostic['code'] ?? null), 'The duplicate-fragment page extracts its shared header rather than retaining it as ambiguous.');
foreach (array('index.html', 'about.html') as $source) {
    $markup = $duplicateFragmentPages[$source]['canonical_block_markup'] ?? '';
    $assert(1 === substr_count($markup, '"tagName":"header"'), "{$source} retains only its unextracted duplicate header fragment inside main content, not the extracted shared shell instance.");
}

$multiColorNav = static function (string $current): string {
    $link = static function (string $name, string $label, string $current, string $class): string {
        $active = $name === $current && 'menu-link' === $class;
        return '<a class="' . $class . ($active ? ' current" aria-current="page"' : '"') . ' href="' . $name . '.html">' . $label . '</a>';
    };
    return '<header class="site-header"><a class="brand" href="index.html">Brand</a><nav>'
        . $link('index', 'Home', $current, 'menu-link')
        . $link('about', 'About', $current, 'menu-link')
        . $link('blog', 'Blog', $current, 'menu-link')
        . $link('index', 'Home', $current, 'overlay-link')
        . $link('about', 'About', $current, 'overlay-link')
        . $link('blog', 'Blog', $current, 'overlay-link')
        . '</nav></header>';
};
$multiColorStyle = '<style>.brand{color:#ec4899}.menu-link{color:#111111}.overlay-link{color:#ffffff}.current{text-decoration:underline}</style>';
$multiColorPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $multiColorStyle . $multiColorNav('') . '<main><h1>Home</h1></main><footer>Shared footer</footer>',
    'about.html' => $multiColorStyle . $multiColorNav('about') . '<main><h1>About</h1></main><footer>Shared footer</footer>',
    'blog.html' => $multiColorStyle . $multiColorNav('blog') . '<main><h1>Blog archive</h1></main><footer>Blog footer</footer>',
)))->toArray()['source_reports']['wordpress_site_plan'];
$multiColorWrites = $writes($multiColorPlan);
$multiColorPages = $pages($multiColorPlan);
$multiColorHeader = array_values(array_filter($multiColorPlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)))[0] ?? array();
$assert('header' === ($multiColorHeader['slug'] ?? null) && 'shared_shell' === ($multiColorHeader['placement']['kind'] ?? null), 'A shared header with brand, menu, and overlay link colors still extracts as one template part when pages differ only by current navigation state.');
foreach (array('index.html', 'about.html', 'blog.html') as $source) {
    $markup = $multiColorPages[$source]['canonical_block_markup'] ?? '';
    $assert(!str_contains($markup, 'wp:navigation') && str_contains($markup, $source === 'blog.html' ? 'Blog archive' : ($source === 'about.html' ? 'About' : 'Home')), "{$source} keeps its content region and does not carry navigation after shared-header extraction.");
}
$assert(str_contains($multiColorWrites['templates/page.html']['payload']['data'] ?? '', '"slug":"header"') && str_contains($multiColorWrites['templates/front-page.html']['payload']['data'] ?? '', '"slug":"header"'), 'Generic templates keep the shared header part.');
$assert(str_contains($multiColorWrites['templates/page-blog.html']['payload']['data'] ?? '', '"slug":"header"') && !str_contains($multiColorWrites['templates/page-blog.html']['payload']['data'] ?? '', '"slug":"footer"'), 'A page-specific template created for a divergent footer still references the shared header and carries no navigation of its own.');

$nestedMultiColorShell = static function (string $current, string $title): string {
    $link = static function (string $name, string $label, string $current, string $class): string {
        $active = $name === $current && 'menu-link' === $class;
        return '<a class="' . $class . ($active ? ' current" aria-current="page"' : '"') . ' href="https://example.test/' . $name . '">' . $label . '</a>';
    };
    return '<input class="nav-trigger" type="checkbox" id="navTrigger"><div id="wrapper" class="site-frame blocks-engine-source-div-a1b2c3d4-4"><div id="header-wrapper-sticky-wrapper" class="blocks-engine-source-div-a1b2c3d4-4"><div id="header-wrapper"><a class="brand" href="https://example.test/home">Brand</a><nav>'
        . $link('home', 'Home', $current, 'menu-link')
        . $link('about', 'About', $current, 'menu-link')
        . $link('blog', 'Blog', $current, 'menu-link')
        . $link('home', 'Home', $current, 'overlay-link')
        . $link('about', 'About', $current, 'overlay-link')
        . $link('blog', 'Blog', $current, 'overlay-link')
        . '</nav></div></div><div id="main-container"><main><h1>' . $title . '</h1></main></div></div>';
};
$nestedMultiColorStyle = '<style>.nav-trigger{appearance:none;border:1px solid}.brand{color:#ec4899}.menu-link{color:#111111}.overlay-link{color:#ffffff}.current{text-decoration:underline}</style>';
$nestedMultiColorPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $nestedMultiColorStyle . $nestedMultiColorShell('home', 'Home'),
    'about.html' => $nestedMultiColorStyle . $nestedMultiColorShell('about', 'About'),
    'blog.html' => $nestedMultiColorStyle . $nestedMultiColorShell('blog', 'Blog'),
)))->toArray()['source_reports']['wordpress_site_plan'];
$nestedMultiColorWrites = $writes($nestedMultiColorPlan);
$nestedMultiColorPages = $pages($nestedMultiColorPlan);
$assert(1 === count(array_filter($nestedMultiColorPlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null))), 'Nested chrome whose links use more than one resting color still extracts one shared header when pages differ only by current navigation state.');
foreach (array('index.html' => 'Home', 'about.html' => 'About', 'blog.html' => 'Blog') as $source => $title) {
    $markup = $nestedMultiColorPages[$source]['canonical_block_markup'] ?? '';
    $assert(!str_contains($markup, 'wp:navigation') && str_contains($markup, $title) && !isset($nestedMultiColorWrites['templates/page-' . basename($source, '.html') . '.html']), "{$source} nested chrome stays in the shared header rather than a page-specific template, and page content has no navigation.");
}
$assert(str_contains($nestedMultiColorWrites['templates/page.html']['payload']['data'] ?? '', '"slug":"header"') && str_contains($nestedMultiColorWrites['templates/front-page.html']['payload']['data'] ?? '', '"slug":"header"'), 'Nested multi-color chrome binds the shared header on generic templates.');

$responsiveDuplicateShell = static function (string $title, bool $dual, bool $correspondence): string {
    $link = static function (string $href, string $label) use ($correspondence): string {
        $attrs = $correspondence
            ? ' class="nav-link data-liberation-responsive-counterpart-f0edc2abe43b" data-dla-responsive-source="root:a:1"'
            : ' class="nav-link"';
        return '<a' . $attrs . ' href="' . $href . '">' . $label . '</a>';
    };
    $header = '<header class="site-header"><p>Brand</p><p>' . $link('/', 'Home') . $link('/about', 'About') . '</p></header>';
    $footer = '<footer class="site-footer"><p>Ticker</p></footer>';
    $body = $header . '<main><h1>' . $title . '</h1></main>' . $footer;
    if (!$dual) return '<div id="root"><div class="page">' . $body . '</div></div>';
    $document = static function (string $variant) use ($body): string {
        return '<div class="data-liberation-' . $variant . '-document"><div id="root"><div class="page">' . $body . '</div></div></div>';
    };
    return $document('desktop') . $document('mobile');
};
$responsiveDuplicatePlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $responsiveDuplicateShell('Home', true, true),
    'about.html' => $responsiveDuplicateShell('About', false, false),
)))->toArray()['source_reports']['wordpress_site_plan'];
$responsiveDuplicateWrites = $writes($responsiveDuplicatePlan);
$responsiveDuplicatePages = $pages($responsiveDuplicatePlan);
$responsiveDuplicateParts = array_column($responsiveDuplicatePlan['template_parts'], null, 'slug');
$assert(isset($responsiveDuplicateParts['header'], $responsiveDuplicateParts['footer']) && !isset($responsiveDuplicateParts['footer-content']) && 'inline_shared_shell' === ($responsiveDuplicateParts['header']['placement']['kind'] ?? null) && 'inline_shared_shell' === ($responsiveDuplicateParts['footer']['placement']['kind'] ?? null), 'Identical nested responsive chrome has one shared owner per region without redundant footer indirection.');
$assert(str_contains($responsiveDuplicateWrites['parts/header.html']['payload']['data'] ?? '', 'site-header') && str_contains($responsiveDuplicateWrites['parts/footer.html']['payload']['data'] ?? '', 'Ticker'), 'Shared footer copy remains editable in its one owner.');
foreach (array('index.html' => 'Home', 'about.html' => 'About') as $source => $title) {
    $markup = $responsiveDuplicatePages[$source]['canonical_block_markup'] ?? '';
    $assert(!str_contains($markup, 'site-header') && !str_contains($markup, 'Ticker') && str_contains($markup, '"slug":"header"') && str_contains($markup, '"slug":"footer"') && str_contains($markup, '>' . $title . '</h1>'), "{$source} replaces duplicated chrome with source-position references and keeps its page content.");
}
$assert(!str_contains($responsiveDuplicateWrites['templates/front-page.html']['payload']['data'] ?? '', 'wp:template-part') && !str_contains($responsiveDuplicateWrites['templates/page.html']['payload']['data'] ?? '', 'wp:template-part'), 'Generic templates do not duplicate source-position shared chrome.');
$responsiveDuplicateTheme = json_decode($responsiveDuplicateWrites['theme.json']['payload']['data'] ?? '', true);
$responsiveDuplicatePartNames = array_column($responsiveDuplicateTheme['templateParts'] ?? array(), 'name');
sort($responsiveDuplicatePartNames, SORT_STRING);
$assert(array('footer', 'header') === $responsiveDuplicatePartNames, 'Generated theme metadata exposes one shared owner per region.');

$responsiveMismatchPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => str_replace('Ticker', 'Desktop ticker', $responsiveDuplicateShell('Home', true, false)),
    'about.html' => $responsiveDuplicateShell('About', false, false),
)))->toArray()['source_reports']['wordpress_site_plan'];
$assert(!array_filter($responsiveMismatchPlan['template_parts'], static fn(array $part): bool => 'footer' === ($part['area'] ?? null)) && str_contains($pages($responsiveMismatchPlan)['index.html']['canonical_block_markup'] ?? '', 'Desktop ticker') && str_contains($pages($responsiveMismatchPlan)['about.html']['canonical_block_markup'] ?? '', 'Ticker'), 'Divergent nested footers across a dual-document page and a single-document page stay page-owned.');

$unlabeledChrome = static function (string $title, bool $dual): string {
    $frame = '<div class="frame"><div class="masthead"><p class="brand">Acme</p><nav><a href="/">Home</a><a href="/about">About</a></nav></div><main><h1>' . $title . '</h1></main><div class="colophon"><p>© 2026 Acme</p></div></div>';
    if (!$dual) return $frame;
    return '<div class="site-document-variant-default">' . $frame . '</div><div class="site-document-variant-mobile">' . $frame . '</div>';
};
$unlabeledChromePlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $unlabeledChrome('Home', true),
    'about.html' => $unlabeledChrome('About', true),
)))->toArray()['source_reports']['wordpress_site_plan'];
$unlabeledChromeWrites = $writes($unlabeledChromePlan);
$unlabeledChromePages = $pages($unlabeledChromePlan);
$unlabeledChromeParts = array_column($unlabeledChromePlan['template_parts'], null, 'slug');
$assert(isset($unlabeledChromeParts['header'], $unlabeledChromeParts['footer']) && 'inline_shared_shell' === ($unlabeledChromeParts['header']['placement']['kind'] ?? null) && 'inline_shared_shell' === ($unlabeledChromeParts['footer']['placement']['kind'] ?? null), 'Identical unlabeled responsive chrome shares content while retaining placement.');
$assert(str_contains($unlabeledChromeWrites['parts/header.html']['payload']['data'] ?? '', 'Acme') && str_contains($unlabeledChromeWrites['parts/header.html']['payload']['data'] ?? '', 'wp:navigation') && str_contains($unlabeledChromeWrites['parts/footer.html']['payload']['data'] ?? '', '© 2026 Acme'), 'The copyright has one editable shared footer owner.');
foreach (array('index.html' => 'Home', 'about.html' => 'About') as $source => $title) {
    $markup = $unlabeledChromePages[$source]['canonical_block_markup'] ?? '';
    $assert(!str_contains($markup, 'masthead') && !str_contains($markup, 'colophon') && !str_contains($markup, 'wp:navigation') && str_contains($markup, '"slug":"header"') && str_contains($markup, '"slug":"footer"') && str_contains($markup, '>' . $title . '</h1>'), "{$source} replaces duplicate dual-document content with shared references and keeps its title.");
}
$assert(!str_contains($unlabeledChromeWrites['templates/front-page.html']['payload']['data'] ?? '', 'wp:template-part') && !str_contains($unlabeledChromeWrites['templates/page.html']['payload']['data'] ?? '', 'wp:template-part'), 'Generic templates do not move unlabeled chrome away from its source ancestors.');
$unlabeledSinglePlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $unlabeledChrome('Home', false),
    'about.html' => $unlabeledChrome('About', false),
)))->toArray()['source_reports']['wordpress_site_plan'];
$unlabeledSingleParts = array_column($unlabeledSinglePlan['template_parts'], null, 'slug');
$assert(isset($unlabeledSingleParts['header'], $unlabeledSingleParts['footer']) && 'inline_shared_shell' === ($unlabeledSingleParts['header']['placement']['kind'] ?? null), 'Identical unlabeled chrome without variants still shares header and footer content in place.');
foreach (array('index.html' => 'Home', 'about.html' => 'About') as $source => $title) {
    $markup = $pages($unlabeledSinglePlan)[$source]['canonical_block_markup'] ?? '';
    $assert(!str_contains($markup, 'wp:navigation') && str_contains($markup, '>' . $title . '</h1>'), "{$source} single-document unlabeled content loses the shared masthead navigation.");
}
$unlabeledDivergentPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => str_replace('Acme', 'Home brand', $unlabeledChrome('Home', true)),
    'about.html' => $unlabeledChrome('About', true),
)))->toArray()['source_reports']['wordpress_site_plan'];
$assert(!array_filter($unlabeledDivergentPlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)) && str_contains($pages($unlabeledDivergentPlan)['index.html']['canonical_block_markup'] ?? '', 'Home brand') && str_contains($pages($unlabeledDivergentPlan)['about.html']['canonical_block_markup'] ?? '', 'Acme'), 'Divergent unlabeled mastheads stay page-owned instead of becoming a false shared header.');

$unlabeledSoloPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $unlabeledChrome('Home', false),
)))->toArray()['source_reports']['wordpress_site_plan'];
$assert(!array_filter($unlabeledSoloPlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)) && str_contains($pages($unlabeledSoloPlan)['index.html']['canonical_block_markup'] ?? '', 'wp:navigation'), 'A single-page unlabeled masthead stays page-owned so the in-content current-navigation marker still renders.');

$unlabeledClusterChrome = static function (string $title, string $current, bool $deep): string {
    $link = static function (string $name, string $label, string $current): string {
        $active = $name === $current;
        return '<a class="site-link' . ($active ? ' current" aria-current="page"' : '"') . ' href="/' . $name . '">' . $label . '</a>';
    };
    $masthead = '<div class="masthead"><p class="brand">Acme</p><nav>'
        . $link('index', 'Home', $current)
        . $link('about', 'About', $current)
        . $link('blog', 'Blog', $current)
        . '</nav></div>';
    $body = $deep
        ? '<main><article><time itemprop="datePublished" datetime="2026-08-01">' . $title . '</time><h1>' . $title . '</h1></article></main>'
        : '<main><h1>' . $title . '</h1></main>';
    $frame = $masthead . $body . '<div class="colophon"><p>© 2026 Acme</p></div>';
    if (!$deep) {
        return '<div class="frame">' . $frame . '</div>';
    }
    return '<div class="page-shell"><div class="article-frame">' . $frame . '</div></div>';
};
$unlabeledClusterResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $unlabeledClusterChrome('Home', 'index', false),
    'about.html' => $unlabeledClusterChrome('About', 'about', false),
    'blog/index.html' => $unlabeledClusterChrome('Blog', 'blog', true),
    'blog/first.html' => $unlabeledClusterChrome('First post', 'blog', true),
)))->toArray();
$assert(isset($unlabeledClusterResult['source_reports']['wordpress_site_plan']), 'Mixed page/post chrome produces a valid plan: ' . json_encode($unlabeledClusterResult['diagnostics']));
$unlabeledClusterPlan = $unlabeledClusterResult['source_reports']['wordpress_site_plan'];
$unlabeledClusterWrites = $writes($unlabeledClusterPlan);
$unlabeledClusterPages = $pages($unlabeledClusterPlan);
$unlabeledClusterParts = array_column($unlabeledClusterPlan['template_parts'], null, 'slug');
$assert(isset($unlabeledClusterParts['header']) && 'inline_shared_shell' === ($unlabeledClusterParts['header']['placement']['kind'] ?? null) && 1 === count(array_filter($unlabeledClusterPlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null))), 'Post-like pages whose unlabeled chrome sits behind extra wrappers still join the shared header cluster.');
foreach (array('index.html' => 'Home', 'about.html' => 'About', 'blog/index.html' => 'Blog', 'blog/first.html' => 'First post') as $source => $title) {
    $markup = $unlabeledClusterPages[$source]['canonical_block_markup'] ?? '';
    $assert(!str_contains($markup, 'wp:navigation') && str_contains($markup, '>' . $title . '</h1>'), "{$source} loses shared unlabeled chrome across wrapper-depth clusters and keeps its title.");
}
$assert(!str_contains($unlabeledClusterWrites['templates/front-page.html']['payload']['data'] ?? '', '"slug":"header"') && !str_contains($unlabeledClusterWrites['templates/page.html']['payload']['data'] ?? '', '"slug":"header"') && !str_contains($unlabeledClusterWrites['templates/single.html']['payload']['data'] ?? '', '"slug":"header"'), 'Page and post templates retain occurrence-owned references inside their source layouts.');

$transparentHeader = static function (string $title, bool $deep): string {
    $header = '<!-- wp:group {"className":"masthead"} --><div class="wp-block-group masthead"><!-- wp:navigation --><!-- wp:navigation-link {"label":"Home","url":"/"} /--><!-- wp:navigation-link {"label":"Blog","url":"/blog"} /--><!-- /wp:navigation --></div><!-- /wp:group -->';
    if ($deep) {
        $header = '<!-- wp:custom/layout-shell {"wrappers":[{"tagName":"div","attributes":{"class":"extra-depth"}}]} -->' . $header . '<!-- /wp:custom/layout-shell -->';
    }
    return $header
        . '<!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:heading --><h2 class="wp-block-heading">' . $title . '</h2><!-- /wp:heading --></main><!-- /wp:group -->'
        . '<!-- wp:group {"className":"colophon"} --><div class="wp-block-group colophon"><!-- wp:paragraph --><p>© 2026 Acme</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
};
$transparentResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<main><h1>Home</h1></main>', 'blog.html' => '<main><h1>Blog</h1></main>')))->toArray();
foreach ($transparentResult['source_reports']['compiled_site']['pages'] as &$transparentPage) {
    $transparentPage['block_markup'] = $transparentHeader('index.html' === $transparentPage['source_path'] ? 'Home' : 'Blog', 'blog.html' === $transparentPage['source_path']);
}
unset($transparentPage);
$transparentPlan = (new WordPressSitePlan())->fromResult($transparentResult);
$transparentPages = $pages($transparentPlan);
$assert(1 === count(array_filter($transparentPlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null))) && !str_contains($transparentPages['index.html']['canonical_block_markup'] ?? '', 'wp:navigation') && !str_contains($transparentPages['blog.html']['canonical_block_markup'] ?? '', 'wp:navigation'), 'Layout-transparent extra wrappers around the same unlabeled header still extract one shared part.');

$nestedEmptyChrome = static function (string $title): string {
    return '<div class="frame"><div class="masthead"><p class="brand">Acme</p><nav><a href="/">Home</a><a href="/blog">Blog</a></nav></div><main><h1>' . $title . '</h1><div class="blocks-engine-empty-visual-group"></div></main><div class="colophon"><p>© 2026 Acme</p></div></div>';
};
$nestedEmptyPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $nestedEmptyChrome('Home'),
    'blog.html' => $nestedEmptyChrome('Blog'),
)))->toArray()['source_reports']['wordpress_site_plan'];
$nestedEmptyPages = $pages($nestedEmptyPlan);
$assert(isset(array_column($nestedEmptyPlan['template_parts'], null, 'slug')['header']) && !str_contains($nestedEmptyPages['index.html']['canonical_block_markup'] ?? '', 'wp:navigation') && !str_contains($nestedEmptyPages['blog.html']['canonical_block_markup'] ?? '', 'wp:navigation'), 'An empty visual group inside page content does not hide unlabeled shared chrome.');

$engineCarrierHeader = static function (string $title, bool $scroll): string {
    $header = '<!-- wp:group {"className":"masthead"} --><div class="wp-block-group masthead"><!-- wp:navigation --><!-- wp:navigation-link {"label":"Home","url":"/"} /--><!-- wp:navigation-link {"label":"Blog","url":"/blog"} /--><!-- /wp:navigation --></div><!-- /wp:group -->';
    $header = $scroll
        ? '<!-- wp:custom/scroll-state {"className":"birdseye-header","config":"{\u0022thresholdPx\u0022:2}"} -->' . $header . '<!-- /wp:custom/scroll-state -->'
        : '<!-- wp:custom/layout-shell {"wrappers":[{"tagName":"div","attributes":{"class":"birdseye-header"}}]} -->' . $header . '<!-- /wp:custom/layout-shell -->';
    return $header
        . '<!-- wp:group {"className":"main-wrap"} --><div class="wp-block-group main-wrap"><!-- wp:heading --><h2 class="wp-block-heading">' . $title . '</h2><!-- /wp:heading --><!-- wp:group {"className":"blocks-engine-empty-visual-group"} --><div class="wp-block-group blocks-engine-empty-visual-group"></div><!-- /wp:group --></div><!-- /wp:group -->';
};
$engineCarrierResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<main><h1>Home</h1></main>', 'blog.html' => '<main><h1>Blog</h1></main>')))->toArray();
foreach ($engineCarrierResult['source_reports']['compiled_site']['pages'] as &$engineCarrierPage) {
    $engineCarrierPage['block_markup'] = $engineCarrierHeader('index.html' === $engineCarrierPage['source_path'] ? 'Home' : 'Blog', 'index.html' === $engineCarrierPage['source_path']);
}
unset($engineCarrierPage);
$engineCarrierPlan = (new WordPressSitePlan())->fromResult($engineCarrierResult);
$engineCarrierPages = $pages($engineCarrierPlan);
$assert(1 === count(array_filter($engineCarrierPlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null))) && !str_contains($engineCarrierPages['index.html']['canonical_block_markup'] ?? '', 'wp:navigation') && !str_contains($engineCarrierPages['blog.html']['canonical_block_markup'] ?? '', 'wp:navigation'), 'Scroll-state and layout-shell carriers around the same unlabeled header still share one part, even when content contains an empty visual group.');

// A menu authored as plain list links marks the served route with
// aria-current="page", so the same nested header differs on every route only by
// that page-scoped state. It must still cluster, and the shared part must not
// freeze any one route's selection.
$listMenu = static function (string $current): string {
    // A dropdown whose project links sit in a nested list beside a toggle: the
    // served project is marked current inside that nested list.
    $links = '';
    foreach (array('about.html' => 'About', 'team.html' => 'Team') as $href => $label) {
        $links .= '<li><a href="' . $href . '"' . ($href === $current ? ' aria-current="page"' : '') . '>' . $label . '</a></li>';
    }
    return '<div class="site"><header class="site-top"><div class="brand"><p>Studio Name</p></div><nav class="menu"><ul><li><a href="index.html"><div><p>Home</p></div></a></li><li><a href="index.html#work"><div><p>Work</p></div></a><button aria-label="More"><svg width="10" height="10" viewBox="0 0 16 11"><path d="M8 10.5L16 1.9 14.7.5 8 7.8 1.3.5 0 1.9z"></path></svg></button><ul>' . $links . '</ul></li></ul></nav></header><main><h1>' . $current . '</h1><p>Body for ' . $current . '</p></main></div>';
};
$listMenuPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $listMenu('index.html'),
    'about.html' => $listMenu('about.html'),
    'team.html' => $listMenu('team.html'),
)))->toArray()['source_reports']['wordpress_site_plan'];
$listMenuHeader = array_values(array_filter($listMenuPlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)))[0] ?? array();
$listMenuPages = $pages($listMenuPlan);
$assert('inline_shared_shell' === ($listMenuHeader['placement']['kind'] ?? null) && str_contains($listMenuHeader['canonical_block_markup'] ?? '', 'Studio Name'), 'A nested header whose menu marks each route current still has one shared owner in place: ' . json_encode(array_column($listMenuPlan['diagnostics'], 'code')));
$assert(!str_contains($listMenuHeader['canonical_block_markup'] ?? '', 'aria-current'), 'The shared part does not freeze one route\'s aria-current selection.');
foreach (array('index.html', 'about.html', 'team.html') as $source) {
    $assert(!str_contains($listMenuPages[$source]['canonical_block_markup'] ?? '', 'Studio Name') && str_contains($listMenuPages[$source]['canonical_block_markup'] ?? '', 'Body for ' . $source), "{$source} keeps only its own content once the header is shared.");
}

// Hoisted into a template part, a nested footer leaves its page ancestors
// behind. An author rule that reached it through them keeps applying through
// the part wrapper, so the part keeps the containing block its layers rely on;
// a rule naming an ancestor the footer never sat under is not re-anchored.
$framed = static function (string $title): string {
    return '<!doctype html><html><head><style>#frame.mesh #site-foot{position:relative}.elsewhere #site-foot{color:red}#frame.mesh #content{padding:1px}</style></head><body>'
        . '<div id="frame" class="mesh"><header class="top"><nav><a href="index.html">Home</a><a href="about.html">About</a></nav></header>'
        . '<main id="content"><h1>' . $title . '</h1></main>'
        . '<footer id="site-foot"><div class="layer" style="position:absolute;inset:0;background:#333"></div><p>Shared footer</p></footer></div></body></html>';
};
$framedPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $framed('Home'),
    'about.html' => $framed('About'),
    'team.html' => $framed('Team'),
)))->toArray()['source_reports']['wordpress_site_plan'];
$framedFooter = array_values(array_filter($framedPlan['template_parts'], static fn(array $part): bool => 'footer' === ($part['area'] ?? null)))[0] ?? array();
$contextCss = implode("\n", array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), array_filter($framedPlan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null) && str_contains((string) ($asset['path'] ?? ''), 'shared-chrome-'))));
$assert('inline_shared_shell' === ($framedFooter['placement']['kind'] ?? null), 'The nested footer has a shared owner at its source position: ' . json_encode(array_column($framedPlan['diagnostics'], 'code')));
$assert(str_contains($contextCss, '#frame.mesh #site-foot{position:relative}') && !str_contains($contextCss, ':has(> #site-foot)'), 'Ancestor selectors keep their original meaning without detached-context compensation: ' . $contextCss);
$assert(!str_contains($contextCss, 'color:red') && !str_contains($contextCss, 'padding:1px'), 'Rules through ancestors the footer never sat under, or targeting other elements, are not re-anchored: ' . $contextCss);

// A fixed page background that preceded the header in the source now renders
// after the header part, so the part restores the source paint order at zero
// specificity. A header that was first in its page needs no such rule.
$layered = static function (string $title, bool $backgroundFirst): string {
    $background = '<div class="page-bg" style="position:fixed;inset:0;background:#eee"></div>';
    return '<!doctype html><html><head><style>#frame #site-top{position:relative}</style></head><body><div id="frame">'
        . ($backgroundFirst ? $background : '')
        . '<header id="site-top"><nav><a href="index.html">Home</a><a href="about.html">About</a></nav></header>'
        . '<main id="content"><h1>' . $title . '</h1></main></div></body></html>';
};
$contextCssFor = static function (array $plan): string {
    return implode("\n", array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), array_filter($plan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null) && str_contains((string) ($asset['path'] ?? ''), 'shared-chrome-context'))));
};
foreach (array(true, false) as $backgroundFirst) {
    $layeredPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
        'index.html' => $layered('Home', $backgroundFirst),
        'about.html' => $layered('About', $backgroundFirst),
    )))->toArray()['source_reports']['wordpress_site_plan'];
    $layeredHeader = array_values(array_filter($layeredPlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)))[0] ?? array();
    $layeredCss = $contextCssFor($layeredPlan);
    $assert('inline_shared_shell' === ($layeredHeader['placement']['kind'] ?? null), 'The layered header has one shared owner at the source position.');
    $assert(!str_contains($layeredCss, ':where(#site-top){z-index:1}'), 'Source paint order is retained without synthetic z-index compensation: ' . $layeredCss);
}

// Shared chrome whose title size is restated by an id-targeted desktop media
// query. Each page's projection rewrites that rule onto a document-namespaced
// rich-text marker; identity strips those markers so the pages still cluster,
// and the last writer silently freezes the static 24px into the shared part.
// The part must keep the size each page actually rendered (23px at the
// desktop reference viewport), via stylesheet ownership rather than a frozen
// inline base.
$titleChrome = static function (string $title): string {
    return '<!doctype html><html><head><style>'
        . '.birdseye-header .logo a{font-size:24px;font-weight:600}'
        . '.birdseye-header .logo #site-title{display:block;max-width:400px;font-size:24px;font-weight:600}'
        . '@media screen and (min-width:767px){#site-title{font-size:23px !important}}'
        . '</style></head><body><div class="birdseye-header"><div class="nav-wrap"><div class="container">'
        . '<div class="logo"><a href="/index.html"><span id="site-title">Studio Name</span></a></div>'
        . '<div class="nav"><ul><li><a href="/index.html">Home</a></li><li><a href="/about.html">About</a></li></ul></div>'
        . '</div></div></div><main><h1>' . $title . '</h1><p>Body for ' . $title . '</p></main></body></html>';
};
$titlePlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $titleChrome('Home'),
    'about.html' => $titleChrome('About'),
    'contact.html' => $titleChrome('Contact'),
)))->toArray()['source_reports']['wordpress_site_plan'];
$titleHeader = array_values(array_filter($titlePlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)))[0] ?? array();
$titleMarkup = (string) ($titleHeader['canonical_block_markup'] ?? '');
$assert('shared_shell' === ($titleHeader['placement']['kind'] ?? null) && str_contains($titleMarkup, 'Studio Name'), 'A repeated header whose title size is restated by a desktop media query still extracts as one shared part.');
$assert(!preg_match('/font-size:24px/i', $titleMarkup), 'The shared part does not freeze the static 24px title size: ' . $titleMarkup);
preg_match('/blocks-engine-richtext-[a-f0-9]+-\d+/', $titleMarkup, $titleMarker);
$titleMarker = $titleMarker[0] ?? '';
$titleGlobalCss = implode("\n", array_map(
    static fn(array $asset): string => (string) ($asset['content'] ?? ''),
    array_filter($titlePlan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null) && in_array('global', array_column($asset['scopes'] ?? array(), 'kind'), true))
));
$assert('' !== $titleMarker && str_contains($titleGlobalCss, $titleMarker) && str_contains($titleGlobalCss, '23px'), 'The desktop-rendered 23px title rule is global and still addresses the shared part\'s rich-text marker: marker=' . $titleMarker . ' css=' . $titleGlobalCss);
foreach (array('index.html', 'about.html', 'contact.html') as $source) {
    $assert(!str_contains($pages($titlePlan)[$source]['canonical_block_markup'] ?? '', 'Studio Name'), "{$source} keeps only its own content once the header is shared.");
}

$variantLinks = '<a href="/">Home</a><a href="/about">About</a><a href="/shop">Shop</a>';
$desktopVariantHeader = '<header id="site-header" class="site-header"><p class="brand">Example Band</p><details class="menu"><summary>Menu</summary>' . $variantLinks . '</details></header>';
$mobileVariantHeader = '<header id="site-header" class="site-header"><p class="brand">Example Band</p><nav aria-label="Site">' . $variantLinks . '</nav></header>';
$desktopVariantFooter = '<footer id="site-footer" class="site-footer"><p>Desktop colophon</p></footer>';
$mobileVariantFooter = '<footer id="site-footer" class="site-footer"><p>Mobile colophon</p></footer>';
$variantDocument = static function (string $title) use ($desktopVariantHeader, $mobileVariantHeader, $desktopVariantFooter, $mobileVariantFooter): string {
    $main = '<main><h1>' . $title . '</h1></main>';
    return '<div class="data-liberation-desktop-document"><div class="frame">' . $desktopVariantHeader . $main . $desktopVariantFooter . '</div></div>'
        . '<div class="data-liberation-mobile-document"><div class="frame">' . $mobileVariantHeader . $main . $mobileVariantFooter . '</div></div>';
};
$variantPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $variantDocument('Home'),
    'about.html' => $variantDocument('About'),
    'shop.html' => $variantDocument('Shop'),
    'notes.html' => '<main><h1>Notes</h1><p>Item 1</p></main>',
)))->toArray()['source_reports']['wordpress_site_plan'];
$variantWrites = $writes($variantPlan);
$variantPages = $pages($variantPlan);
$variantHeaderParts = array_values(array_filter($variantPlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null) && in_array($part['placement']['kind'] ?? null, array('shared_shell', 'entry_shell'), true)));
foreach (array('index.html' => 'templates/front-page.html', 'about.html' => 'templates/page.html', 'shop.html' => 'templates/page.html') as $source => $template) {
    $markup = $variantPages[$source]['canonical_block_markup'] ?? '';
    $templateMarkup = $variantWrites[$template]['payload']['data'] ?? '';
    $desktopAt = strpos($markup, 'data-liberation-desktop-document');
    $mobileAt = strpos($markup, 'data-liberation-mobile-document');
    $desktopMarkup = false !== $desktopAt ? substr($markup, $desktopAt, (false !== $mobileAt ? $mobileAt : strlen($markup)) - $desktopAt) : '';
    $mobileMarkup = false !== $mobileAt ? substr($markup, $mobileAt) : '';
    $templateBindsHeader = str_contains($templateMarkup, '"slug":"header"');
    $contentHeaderLandmarks = substr_count($markup, '"tagName":"header"') + substr_count($markup, '"area":"header"');
    $desktopHeaders = substr_count($desktopMarkup, '"tagName":"header"');
    $mobileHeaders = substr_count($mobileMarkup, '"tagName":"header"');
    $assert($templateBindsHeader && 0 === $contentHeaderLandmarks, "{$source} hoists viewport-partitioned header chrome into the template and leaves no header landmark in page content.");
    $assert(0 === $desktopHeaders && 0 === $mobileHeaders, "{$source} strips both variant header landmarks from page content.");
    $assert(str_contains($markup, '>' . ('index.html' === $source ? 'Home' : ('about.html' === $source ? 'About' : 'Shop')) . '</h1>'), "{$source} keeps its page title after chrome placement.");
}
$variantHeader = $variantHeaderParts[0] ?? array();
$variantHeaderMarkup = (string) ($variantHeader['canonical_block_markup'] ?? '');
$assert(1 === count($variantHeaderParts) && 'shared_shell' === ($variantHeader['placement']['kind'] ?? null) && 'responsive_variant_partition' === ($variantHeader['provenance']['reason'] ?? null), 'Divergent desktop and mobile headers that match across routes become one template-bound header part.');
$assert(str_contains($variantHeaderMarkup, 'data-liberation-desktop-document') && str_contains($variantHeaderMarkup, 'data-liberation-mobile-document') && str_contains($variantHeaderMarkup, 'Menu') && str_contains($variantHeaderMarkup, 'Example Band'), 'The shared header part keeps each viewport\'s chrome behind the existing variant visibility class: ' . $variantHeaderMarkup);
$assert(1 === substr_count($variantWrites['templates/front-page.html']['payload']['data'] ?? '', '"slug":"header"') && 1 === substr_count($variantWrites['templates/page.html']['payload']['data'] ?? '', '"slug":"header"') && !str_contains($variantWrites['templates/page-notes.html']['payload']['data'] ?? '', '"slug":"header"'), 'Templates reference the shared header, and a route without that chrome stays excluded.');
$assert(!str_contains($variantPages['notes.html']['canonical_block_markup'] ?? '', '"tagName":"header"'), 'A route without header chrome does not gain one.');
$variantFooterParts = array_values(array_filter($variantPlan['template_parts'], static fn(array $part): bool => 'footer' === ($part['area'] ?? null) && in_array($part['placement']['kind'] ?? null, array('shared_shell', 'entry_shell'), true)));
$variantFooterMarkup = (string) (($variantFooterParts[0]['canonical_block_markup'] ?? ''));
$assert(1 === count($variantFooterParts) && 'responsive_variant_partition' === ($variantFooterParts[0]['provenance']['reason'] ?? null) && str_contains($variantFooterMarkup, 'Desktop colophon') && str_contains($variantFooterMarkup, 'Mobile colophon') && str_contains($variantFooterMarkup, 'data-liberation-desktop-document') && str_contains($variantFooterMarkup, 'data-liberation-mobile-document'), 'Divergent desktop and mobile footers hoist into one visibility-partitioned footer part: ' . $variantFooterMarkup);
foreach (array('index.html', 'about.html', 'shop.html') as $source) {
    $markup = $variantPages[$source]['canonical_block_markup'] ?? '';
    $assert(!str_contains($markup, '"tagName":"footer"') && !str_contains($markup, '"area":"footer"'), "{$source} keeps footer landmarks out of page content.");
}
$assert(1 === substr_count($variantWrites['templates/page.html']['payload']['data'] ?? '', '"slug":"footer"') && !str_contains($variantWrites['templates/page-notes.html']['payload']['data'] ?? '', '"slug":"footer"'), 'The page template references the shared footer and the excluded route does not.');

$partitionItem = static function (string $label, string $href, bool $paragraph): string {
    $inner = $paragraph ? '<div class="pad"><p class="label">' . $label . '</p></div>' : $label;
    return '<li class="item"><a href="' . $href . '">' . $inner . '</a></li>';
};
$partitionDocument = static function (string $title) use ($partitionItem): string {
    $desktop = '<header id="site-header" class="site-header"><p class="brand">Example Band</p><site-menu id="desktop-menu" class="desktop-menu"><nav aria-label="Site"><ul>'
        . $partitionItem('Home', '/', true) . $partitionItem('Journal', '/journal', true) . $partitionItem('Shop', '/shop#catalog', true)
        . '</ul></nav></site-menu></header>';
    $mobile = '<header id="site-header" class="site-header"><p class="brand">Example Band</p><nav class="mobile" aria-label="Site"><ul>'
        . $partitionItem('Home', '/', false) . $partitionItem('Journal', '/journal', false) . $partitionItem('Shop', '/shop', false)
        . '</ul></nav></header>';
    $main = '<main><h1>' . $title . '</h1></main>';
    return '<div class="data-liberation-desktop-document"><div class="frame">' . $desktop . $main . '<footer id="site-footer" class="site-footer"><p>Desktop colophon</p></footer></div></div>'
        . '<div class="data-liberation-mobile-document"><div class="frame">' . $mobile . $main . '<footer id="site-footer" class="site-footer"><p>Mobile colophon</p></footer></div></div>';
};
$partitionPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $partitionDocument('Home'),
    'journal.html' => $partitionDocument('Journal'),
    'shop.html' => $partitionDocument('Shop'),
)))->toArray()['source_reports']['wordpress_site_plan'];
$partitionParts = array();
foreach ($partitionPlan['template_parts'] as $part) $partitionParts[$part['area'] ?? ''] = $part;
$partitionHeader = $partitionParts['header'] ?? array();
$partitionFooter = $partitionParts['footer'] ?? array();
$partitionHeaderMarkup = (string) ($partitionHeader['canonical_block_markup'] ?? '');
$partitionMenus = array_values(array_filter($partitionPlan['menus'] ?? array(), static fn(array $menu): bool => is_string($menu['token'] ?? null) && is_string($menu['block_markup'] ?? null)));
$assert('header' === ($partitionHeader['slug'] ?? null) && 'footer' === ($partitionFooter['slug'] ?? null) && 'responsive_variant_partition' === ($partitionHeader['provenance']['reason'] ?? null) && 'responsive_variant_partition' === ($partitionFooter['provenance']['reason'] ?? null), 'Viewport-partitioned chrome still extracts one shared header and footer when both viewports contain navigation: ' . json_encode(array_map(static fn(array $part): string => ($part['slug'] ?? '') . ':' . ($part['provenance']['reason'] ?? ''), $partitionPlan['template_parts'])));
$assert(str_contains($partitionHeaderMarkup, 'data-liberation-desktop-document') && str_contains($partitionHeaderMarkup, 'data-liberation-mobile-document') && 2 <= substr_count($partitionHeaderMarkup, '<!-- wp:navigation '), 'The shared header keeps both viewport navigations.');
$assert(2 === count($partitionMenus) && str_contains((string) ($partitionMenus[0]['block_markup'] ?? ''), '"label":"Home"') && str_contains((string) ($partitionMenus[0]['block_markup'] ?? ''), '"label":"Shop"'), 'Viewport menus preserve their distinct fragment destinations in separate navigation entities.');
foreach ($partitionMenus as $menu) $assert(str_contains($partitionHeaderMarkup, '{{wordpress-site-plan:navigation:' . $menu['token'] . '}}'), 'Each viewport navigation binds its own declared content.');
foreach (array('index.html', 'journal.html', 'shop.html') as $source) {
    $markup = $pages($partitionPlan)[$source]['canonical_block_markup'] ?? '';
    $assert(!str_contains($markup, '"tagName":"header"') && !str_contains($markup, '"tagName":"footer"'), "{$source} does not keep viewport chrome in page content.");
}
$partitionWrites = $writes($partitionPlan);
$assert(1 === substr_count($partitionWrites['templates/front-page.html']['payload']['data'] ?? '', '"slug":"header"') && 1 === substr_count($partitionWrites['templates/page.html']['payload']['data'] ?? '', '"slug":"footer"'), 'Templates bind the shared header and footer parts.');
$equivalentDocument = static function (string $title, string $current, ?string $config, bool $markedLayer, string $brand = 'Acme'): string {
    $item = static function (string $href, string $label) use ($current): string {
        $classes = 'nav-item' . ('/' === $href ? ' nav-item--home' : '') . ($href === $current ? ' nav-item--active' : '');
        return '<a class="' . $classes . '" href="' . $href . '"' . ($href === $current ? ' aria-current="page"' : '') . '>' . $label . '</a>';
    };
    $layer = '<div class="header-dropshadow" style="box-shadow:0px 19px 48px 41px"></div>';
    if ($markedLayer && null !== $config) $layer = '<div class="header-dropshadow" style="box-shadow:0px 19px 48px 41px" data-blocks-engine-scroll-state="true" data-blocks-engine-scroll-state-config="' . htmlspecialchars($config, ENT_QUOTES) . '"></div>';
    $header = '<header id="header" class="site-header">' . $layer . '<a class="brand" href="/">' . $brand . '</a><nav class="header-nav-list">' . $item('/', 'Home') . $item('/services', 'Services') . $item('/contact', 'Contact') . '</nav><button class="burger" aria-label="Open Menu">Open Menu</button></header>';
    if (null !== $config) $header = str_replace('<header id="header" class="site-header">', '<header id="header" class="site-header" data-blocks-engine-scroll-state="true" data-blocks-engine-scroll-state-config="' . htmlspecialchars($config, ENT_QUOTES) . '">', $header);
    return '<!doctype html><html><body><div id="siteWrapper" class="site">' . $header . '<main><h1>' . $title . '</h1><p>Body for ' . $title . '</p></main><footer class="site-footer"><p>Shared colophon</p></footer></div></body></html>';
};
$equivalentConfig = static fn(string $height): string => '{"thresholdPx":2,"addClasses":["shrink"],"removeClasses":[],"styleTargets":[{"selector":":scope","properties":{"height":{"rest":"' . $height . '"}}}]}';
$equivalentPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $equivalentDocument('Home', '/', $equivalentConfig('161px'), true),
    'services.html' => $equivalentDocument('Services', '/services', $equivalentConfig('160px'), false),
    'contact.html' => $equivalentDocument('Contact', '/contact', null, false),
    'about.html' => $equivalentDocument('About', '/about', $equivalentConfig('161px'), false, 'Other brand'),
)))->toArray()['source_reports']['wordpress_site_plan'];
$equivalentWrites = $writes($equivalentPlan);
$equivalentPages = $pages($equivalentPlan);
$equivalentHeader = array_values(array_filter($equivalentPlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)))[0] ?? array();
$equivalentFooter = array_values(array_filter($equivalentPlan['template_parts'], static fn(array $part): bool => 'footer' === ($part['area'] ?? null)))[0] ?? array();
$equivalentMenus = $equivalentPlan['menus'] ?? array();
$equivalentHeaderMarkup = (string) ($equivalentHeader['canonical_block_markup'] ?? '');
foreach ($equivalentMenus as $menu) if (str_contains($equivalentHeaderMarkup, '{{wordpress-site-plan:navigation:' . $menu['token'] . '}}')) $equivalentHeaderMarkup .= $menu['block_markup'];
$assert('inline_shared_shell' === ($equivalentHeader['placement']['kind'] ?? null) && 'inline_shared_shell' === ($equivalentFooter['placement']['kind'] ?? null), 'An equivalent-document capture shares nested header and footer content in place even when a landmark is a scroll-state carrier on only some routes: ' . json_encode(array_column(array_filter($equivalentPlan['diagnostics'], static fn(array $diagnostic): bool => 'header' === ($diagnostic['area'] ?? null)), 'message')));
$assert(str_contains($equivalentHeaderMarkup, 'Acme') && str_contains($equivalentHeaderMarkup, 'Open Menu') && str_contains($equivalentHeaderMarkup, 'nav-item--home') && !str_contains($equivalentHeaderMarkup, 'nav-item--active') && !str_contains($equivalentHeaderMarkup, 'Other brand'), 'The shared header keeps the repeated brand, stable home marker, and menu toggle, not a divergent route\'s brand or one page\'s current flag.');
$assert(1 === count($equivalentMenus) && str_contains((string) ($equivalentMenus[0]['block_markup'] ?? ''), '"label":"Home"') && str_contains((string) ($equivalentMenus[0]['block_markup'] ?? ''), '"url":"/services"'), 'Repeated equivalent header content becomes one shared navigation entity.');
foreach (array('index.html' => 'Home', 'services.html' => 'Services', 'contact.html' => 'Contact') as $source => $title) {
    $markup = $equivalentPages[$source]['canonical_block_markup'] ?? '';
    $assert(!str_contains($markup, 'wp:navigation') && !str_contains($markup, 'Open Menu') && str_contains($markup, '>' . $title . '</h1>'), "{$source} keeps its content and does not duplicate the shared header navigation.");
}
$assert(str_contains($equivalentPages['about.html']['canonical_block_markup'] ?? '', 'Other brand') && !str_contains($equivalentWrites['templates/page-about.html']['payload']['data'] ?? '', 'wp:navigation'), 'A route whose header content differs stays page-owned and does not receive the shared navigation in its exclusion template.');
$assert(!str_contains($equivalentWrites['templates/front-page.html']['payload']['data'] ?? '', 'wp:template-part') && !str_contains($equivalentWrites['templates/page.html']['payload']['data'] ?? '', 'wp:template-part'), 'Generic templates do not duplicate source-position chrome.');
$assert(!str_contains($equivalentWrites['templates/front-page.html']['payload']['data'] ?? '', 'data-liberation-desktop-document'), 'Equivalent-document extraction does not invent a responsive-variant partition.');

// Footer copy is repeated inside two different authored wrappers. Keep both
// wrapper geometries in their source position while sharing only the identical
// paragraph; a genuinely different footer paragraph stays route-owned.
$footerVariant = static function (string $title, string $copy, bool $padded): string {
    $paragraph = '<!-- wp:paragraph {"style":{"spacing":{"margin":{"top":"0","right":"0","bottom":"0","left":"0"}}}} --><p style="margin-top:0;margin-right:0;margin-bottom:0;margin-left:0">' . $copy . '</p><!-- /wp:paragraph -->';
    $footerContent = $padded
        ? '<!-- wp:group {"tagName":"section","className":"footer-padding","style":{"spacing":{"padding":{"top":"32px","bottom":"32px"}}}} --><section class="wp-block-group footer-padding" style="padding-top:32px;padding-bottom:32px">' . $paragraph . '</section><!-- /wp:group -->'
        : '<!-- wp:group {"tagName":"section","className":"footer-standard"} --><section class="wp-block-group footer-standard">' . $paragraph . '</section><!-- /wp:group -->';
    return '<!-- wp:group --><div class="wp-block-group"><main><h1>' . $title . '</h1></main><!-- wp:consumer/layout-shell {"wrappers":[{"tagName":"div","attributes":{"class":"widget-footer"}},{"tagName":"footer","attributes":{"class":"site-footer"}}]} -->'
        . '<div class="widget-footer"><footer class="site-footer">' . $footerContent . '</footer></div><!-- /wp:consumer/layout-shell --></div><!-- /wp:group -->';
};
$footerFactorResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<main><h1>Home</h1></main>',
    'about.html' => '<main><h1>About</h1></main>',
    'projects.html' => '<main><h1>Projects</h1></main>',
    'distinct.html' => '<main><h1>Distinct</h1></main>',
)))->toArray();
foreach ($footerFactorResult['source_reports']['compiled_site']['pages'] as &$page) {
    $page['block_markup'] = match ($page['source_path'] ?? '') {
        'index.html' => $footerVariant('Home', 'Shared legal copy.', false),
        'about.html' => $footerVariant('About', 'Shared legal copy.', false),
        'projects.html' => $footerVariant('Projects', 'Shared legal copy.', true),
        default => $footerVariant('Distinct', 'A genuinely different footer.', true),
    };
}
unset($page);
$footerFactorPlan = (new WordPressSitePlan())->fromResult($footerFactorResult);
$footerFactorPages = $pages($footerFactorPlan);
$footerFactorPart = array_values(array_filter($footerFactorPlan['template_parts'], static fn(array $part): bool => 'footer-content' === ($part['slug'] ?? null)))[0] ?? array();
$assert('inline_shared_shell' === ($footerFactorPart['placement']['kind'] ?? null) && 1 === substr_count($footerFactorPart['canonical_block_markup'] ?? '', 'Shared legal copy.'), 'One nested footer-content part owns the identical text across wrapper variants.');
$assert(array() === array_filter(array('index.html', 'about.html', 'projects.html'), static fn(string $source): bool => !str_contains($footerFactorPages[$source]['canonical_block_markup'] ?? '', '"slug":"footer-content"')), 'Both shared-shell and page-owned footer wrappers reference the same editable inner part.');
$assert(str_contains($footerFactorPages['projects.html']['canonical_block_markup'] ?? '', 'widget-footer') && str_contains($footerFactorPages['projects.html']['canonical_block_markup'] ?? '', 'site-footer'), 'Route-specific footer wrapper markup remains in page content around the shared part.');
$assert(str_contains($footerFactorPages['projects.html']['canonical_block_markup'] ?? '', 'footer-padding') && str_contains($footerFactorPages['projects.html']['canonical_block_markup'] ?? '', 'padding-top:32px') && str_contains($footerFactorPages['projects.html']['canonical_block_markup'] ?? '', 'padding-bottom:32px'), 'Project footer wrapper retains its authored 32px vertical padding.');
$assert(str_contains($footerFactorPages['distinct.html']['canonical_block_markup'] ?? '', 'A genuinely different footer.') && !str_contains($footerFactorPages['distinct.html']['canonical_block_markup'] ?? '', '"slug":"footer-content"'), 'A genuinely distinct footer remains page-owned.');
WordPressSitePlan::assertValid($footerFactorPlan);

// The combined SSI import can project one responsive footer into multiple
// wrapper-contract blocks in the same page document. Factor each matching
// inner paragraph while leaving those authored wrappers in place.
$combinedFooterArea = static function (string $copy, bool $padded): string {
    $paragraph = '<!-- wp:paragraph {"style":{"spacing":{"margin":{"top":"0","right":"0","bottom":"0","left":"0"}}}} --><p style="margin-top:0;margin-right:0;margin-bottom:0;margin-left:0">' . $copy . '</p><!-- /wp:paragraph -->';
    $inner = $padded
        ? '<!-- wp:group {"tagName":"section","className":"footer-padding","style":{"spacing":{"padding":{"top":"32px","bottom":"32px"}}}} --><section class="wp-block-group footer-padding" style="padding-top:32px;padding-bottom:32px">' . $paragraph . '</section><!-- /wp:group -->'
        : '<!-- wp:group {"tagName":"section","className":"footer-standard"} --><section class="wp-block-group footer-standard">' . $paragraph . '</section><!-- /wp:group -->';
    return '<!-- wp:ssi-stadimax-loop10/layout-shell {"wrappers":[{"tagName":"div","attributes":{"class":"widget widget-footer"}},{"tagName":"footer","attributes":{"class":"site-footer"}},{"tagName":"section","attributes":{"class":"footer-inner"}}]} -->'
        . '<div class="widget widget-footer"><footer class="site-footer"><section class="footer-inner">' . $inner . '</section></footer></div><!-- /wp:ssi-stadimax-loop10/layout-shell -->';
};
$combinedFooterResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<main><h1>Home</h1></main>',
    'about.html' => '<main><h1>About</h1></main>',
    'projects.html' => '<main><h1>Projects</h1></main>',
    'distinct.html' => '<main><h1>Distinct</h1></main>',
)))->toArray();
foreach ($combinedFooterResult['source_reports']['compiled_site']['pages'] as &$page) {
    $page['block_markup'] = '<!-- wp:group --><div class="wp-block-group"><main><h1>' . ($page['title'] ?? 'Page') . '</h1></main>'
        . $combinedFooterArea('index.html' === ($page['source_path'] ?? '') || 'about.html' === ($page['source_path'] ?? '') || 'projects.html' === ($page['source_path'] ?? '') ? 'Shared responsive copy.' : 'A different responsive footer.', 'projects.html' === ($page['source_path'] ?? ''))
        . $combinedFooterArea('index.html' === ($page['source_path'] ?? '') || 'about.html' === ($page['source_path'] ?? '') || 'projects.html' === ($page['source_path'] ?? '') ? 'Shared responsive copy.' : 'A different responsive footer.', 'projects.html' === ($page['source_path'] ?? ''))
        . '</div><!-- /wp:group -->';
}
unset($page);
$combinedFooterPlan = (new WordPressSitePlan())->fromResult($combinedFooterResult);
$combinedFooterPages = $pages($combinedFooterPlan);
$combinedFooterPart = array_values(array_filter($combinedFooterPlan['template_parts'], static fn(array $part): bool => 'footer-content' === ($part['slug'] ?? null)))[0] ?? array();
$assert('inline_shared_shell' === ($combinedFooterPart['placement']['kind'] ?? null) && 'Shared responsive copy.' === trim(strip_tags($combinedFooterPart['canonical_block_markup'] ?? '')), 'Repeated responsive wrapper-contract regions share one editable inner-copy part.');
foreach (array('index.html', 'about.html', 'projects.html') as $source) {
    $markup = $combinedFooterPages[$source]['canonical_block_markup'] ?? '';
    $assert(2 === substr_count($markup, '"slug":"footer-content"'), "{$source} replaces both equivalent responsive footer copies.");
}
$assert(str_contains($combinedFooterPages['projects.html']['canonical_block_markup'] ?? '', 'footer-padding') && str_contains($combinedFooterPages['projects.html']['canonical_block_markup'] ?? '', 'padding-top:32px') && str_contains($combinedFooterPages['projects.html']['canonical_block_markup'] ?? '', 'padding-bottom:32px'), 'Combined responsive factoring preserves project-specific footer section padding.');
$assert(str_contains($combinedFooterPages['distinct.html']['canonical_block_markup'] ?? '', 'A different responsive footer.') && !str_contains($combinedFooterPages['distinct.html']['canonical_block_markup'] ?? '', '"slug":"footer-content"'), 'Distinct copy in repeated responsive footer regions remains page-owned.');
WordPressSitePlan::assertValid($combinedFooterPlan);

// A footer whose only child is a wrapper chain stays a footer group block:
// wrapper coalescing must not fold the landmark into a layout shell's wrapper
// list, where it loses its block identity and can no longer be shared.
$foldedFooterPage = static fn(string $title): string => '<div id="site-root"><main><h1>' . $title . '</h1></main><footer id="page-footer" class="page-footer"><section class="footer-band"><div class="footer-inner"><p>Shared colophon.</p><p>Second line.</p></div></section></footer></div>';
$foldedFooterResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $foldedFooterPage('Home'), 'about.html' => $foldedFooterPage('About'), 'projects.html' => $foldedFooterPage('Projects'))))->toArray();
$foldedFooterPlan = $foldedFooterResult['source_reports']['wordpress_site_plan'];
$foldedFooterPart = array_values(array_filter($foldedFooterPlan['template_parts'], static fn(array $part): bool => 'footer' === ($part['area'] ?? null) && 'inline_shared_shell' === ($part['placement']['kind'] ?? null)))[0] ?? array();
$assert(array() !== $foldedFooterPart && str_contains($foldedFooterPart['canonical_block_markup'] ?? '', 'footer-band'), 'A footer landmark wrapping a wrapper chain is extracted as the shared footer part.');
foreach ($foldedFooterResult['source_reports']['compiled_site']['pages'] as $page) $assert(str_contains($page['block_markup'], '"anchor":"page-footer"') && str_contains($page['block_markup'], '"tagName":"footer"'), "{$page['source_path']} keeps its footer as a group block rather than a folded layout-shell wrapper.");
foreach ($pages($foldedFooterPlan) as $source => $row) $assert(!str_contains($row['canonical_block_markup'] ?? '', 'Shared colophon.') && str_contains($row['canonical_block_markup'] ?? '', 'site-root'), "{$source} hands its footer to the shared part and keeps its own wrappers.");
WordPressSitePlan::assertValid($foldedFooterPlan);

// Chrome that only the entry page renders (the other routes are bare legal
// pages) still becomes header and footer template parts for the front page.
$entryOnlyResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><body><div id="root"><div class="app"><header id="entry-head" class="entry-head" style="border-top:2px solid #111"><nav><a href="#top">Brand</a><a href="#story">Story</a><a href="notes/index.html">Notes</a></nav></header><main><h1>Entry home</h1><p>Entry body copy.</p></main><footer id="entry-foot" class="entry-foot" style="border-top:1px solid #333"><p>Entry footer copy.</p></footer></div></div></body></html>',
    'notes/index.html' => '<!doctype html><html><body><div id="root"><section><h2>Notes</h2><p>Bare notes page.</p></section></div></body></html>',
    'legal/index.html' => '<!doctype html><html><body><div id="root"><section><h2>Legal</h2><p>Bare legal page.</p></section></div></body></html>',
)))->toArray();
$entryOnlyPlan = $entryOnlyResult['source_reports']['wordpress_site_plan'];
$entryOnlyPages = $pages($entryOnlyPlan);
$entryOnlyParts = array_values(array_filter($entryOnlyPlan['template_parts'], static fn(array $part): bool => in_array($part['area'] ?? null, array('header', 'footer'), true)));
$assert(2 === count($entryOnlyParts), 'Entry-only header and footer chrome becomes two template parts.');
$assert(2 === count(array_filter($entryOnlyParts, static fn(array $part): bool => 'inline_shared_shell' === ($part['placement']['kind'] ?? null))), 'The entry page is nested in a root wrapper, so both parts are bound at their source position.');
$entryPageMarkup = $entryOnlyPages['index.html']['canonical_block_markup'] ?? '';
$assert(str_contains($entryPageMarkup, '"slug":"header"') && str_contains($entryPageMarkup, '"slug":"footer"') && str_contains($entryPageMarkup, 'Entry body copy.') && !str_contains($entryPageMarkup, 'Entry footer copy.') && !str_contains($entryPageMarkup, 'Brand'), 'The entry page references the header and footer parts and keeps only the main content.');
foreach ($entryOnlyParts as $part) $assert(str_contains($part['canonical_block_markup'] ?? '', 'entry-head') || str_contains($part['canonical_block_markup'] ?? '', 'Entry footer copy.'), 'Each extracted part holds the chrome that left the page.');
foreach (array('notes/index.html', 'legal/index.html') as $barePath) $assert(!str_contains($entryOnlyPages[$barePath]['canonical_block_markup'] ?? '', 'wp:template-part'), "{$barePath} does not get chrome it never had.");
WordPressSitePlan::assertValid($entryOnlyPlan);

fwrite(STDOUT, "shared-shell-plan contract passed\n");

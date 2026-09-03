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
$assert(str_contains($header['canonical_block_markup'] ?? '', '"url":"/guides/about"') && str_contains($header['canonical_block_markup'] ?? '', '"url":"/"'), 'Route-relative navigation destinations are canonicalized before shell identity comparison.');
$assert(str_contains($header['canonical_block_markup'] ?? '', '"anchor":"site-chrome"') && str_contains($header['canonical_block_markup'] ?? '', 'site-header') && str_contains($header['canonical_block_markup'] ?? '', 'border'), 'Shared shell preserves its canonical landmark wrapper anchor, class, and style attributes.');
foreach (array('index.html', 'guides/about.html', 'guides/team.html') as $source) {
    $markup = $documents[$source]['canonical_block_markup'] ?? '';
    $assert(1 === substr_count($markup, '"tagName":"header"') && str_contains($markup, 'Skip to content') && str_contains($markup, 'article header') && str_contains($markup, 'article footer') && 2 === substr_count($markup, '"tagName":"footer"'), "{$source} removes only the shared header and retains skip links, article landmarks, and its footer: {$markup}");
}
$assert(1 === substr_count($declaredWrites['templates/front-page.html']['payload']['data'], '"slug":"header"') && 1 === substr_count($declaredWrites['templates/page.html']['payload']['data'], '"slug":"header"') && 1 === substr_count($declaredWrites['templates/index.html']['payload']['data'], '"slug":"header"'), 'All base templates bind the shared header exactly once.');
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

$incomplete = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<header>Shared</header><main>Home</main><footer>Shared footer</footer>', 'about.html' => '<header>Shared</header><main>About</main><footer>Shared footer</footer>', 'contact.html' => '<main>Contact</main><footer>Shared footer</footer>', 'services.html' => '<header>Services</header><main>Services</main><footer>Shared footer</footer>')))->toArray()['source_reports']['wordpress_site_plan'];
$multiple = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<header>One</header><header>Two</header><main>Home</main>', 'about.html' => '<header>One</header><header>Two</header><main>About</main>')))->toArray()['source_reports']['wordpress_site_plan'];
$incompletePages = $pages($incomplete); $incompleteWrites = $writes($incomplete);
$incompleteHeader = current(array_filter($incomplete['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$incompleteDiagnostic = current(array_filter($incomplete['diagnostics'], static fn(array $diagnostic): bool => 'wordpress_site_plan_shell_extracted' === ($diagnostic['code'] ?? null) && 'header' === ($diagnostic['area'] ?? null)));
$assert('header' === ($incompleteHeader['slug'] ?? null) && !str_contains($incompletePages['index.html']['canonical_block_markup'] ?? '', 'Shared') && !str_contains($incompletePages['about.html']['canonical_block_markup'] ?? '', 'Shared') && str_contains($incompletePages['contact.html']['canonical_block_markup'] ?? '', 'Contact') && str_contains($incompletePages['services.html']['canonical_block_markup'] ?? '', 'Services') && 1 === substr_count($incompleteWrites['templates/page-contact.html']['payload']['data'] ?? '', '"slug":"footer"') && !str_contains($incompleteWrites['templates/page-contact.html']['payload']['data'] ?? '', '"slug":"header"') && 1 === substr_count($incompleteWrites['templates/page-services.html']['payload']['data'] ?? '', '"slug":"footer"') && !str_contains($incompleteWrites['templates/page-services.html']['payload']['data'] ?? '', '"slug":"header"') && 2 === ($incompleteDiagnostic['page_count'] ?? null) && 4 === ($incompleteDiagnostic['applicable_page_count'] ?? null) && array(array('source_path' => 'contact.html', 'reason' => 'missing'), array('source_path' => 'services.html', 'reason' => 'non_equivalent')) === ($incompleteDiagnostic['exclusions'] ?? null), 'A dominant shell cluster extracts only for proven routes while missing and divergent shells remain local through deterministic route templates.');
$postCluster = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<header>Shared</header><main>Home</main>', 'notes/first.html' => '<header>Shared</header><main><article><time datetime="2026-08-01">First</time></article></main>', 'notes/second.html' => '<header>Shared</header><main><article><time datetime="2026-08-02">Second</time></article></main>', 'notes/variant.html' => '<header>Variant</header><main><article><time datetime="2026-08-03">Variant</time></article></main>')))->toArray()['source_reports']['wordpress_site_plan'];
$postWrites = $writes($postCluster); $postPages = $pages($postCluster);
$assert('post' === ($postPages['notes/first.html']['post_type'] ?? null) && 'post' === ($postPages['notes/second.html']['post_type'] ?? null) && 'post' === ($postPages['notes/variant.html']['post_type'] ?? null) && !str_contains($postWrites['templates/single-post-variant.html']['payload']['data'] ?? '', '"slug":"header"') && str_contains($postWrites['templates/index.html']['payload']['data'] ?? '', '"slug":"header"') && !isset($postWrites['templates/single-variant.html']) && !isset($postWrites['templates/single-post.html']), 'A divergent standard post receives the WordPress-specific single-post-{post_name} exclusion template without suppressing the shared shell for the clustered posts.');
$mixedShells = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<header>Shared header</header><main>Home</main><footer>Shared footer</footer>', 'about.html' => '<header>Shared header</header><main>About</main><footer>Shared footer</footer>', 'contact.html' => '<header>Shared header</header><main>Contact</main><footer>Contact footer</footer>')))->toArray()['source_reports']['wordpress_site_plan'];
$mixedWrites = $writes($mixedShells);
$assert(str_contains($mixedWrites['templates/page-contact.html']['payload']['data'] ?? '', '"slug":"header"') && !str_contains($mixedWrites['templates/page-contact.html']['payload']['data'] ?? '', '"slug":"footer"') && str_contains($mixedWrites['templates/page.html']['payload']['data'] ?? '', '"slug":"header"') && str_contains($mixedWrites['templates/page.html']['payload']['data'] ?? '', '"slug":"footer"'), 'A footer-only exclusion creates a route template that retains the globally shared header and excludes only the divergent footer.');
$assert(!array_filter($multiple['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)) && in_array('wordpress_site_plan_shell_retained_incomplete', array_column($multiple['diagnostics'], 'code'), true), 'Multiple shell candidates remain page-local with bounded incomplete diagnostics.');

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
    foreach ($unicodeExpected[$slug] as $fragment) $assert(str_contains($content, $fragment), "{$slug} materialization preserves {$fragment}.");
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
$statefulMarkup = (string) ($statefulHeader['canonical_block_markup'] ?? '');
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

fwrite(STDOUT, "shared-shell-plan contract passed\n");

<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

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

$assert('header' === ($header['slug'] ?? null) && 1 === count(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null))), 'One canonical header template part is generated for semantically equivalent source shells.');
$assert(!array_filter($plan['template_parts'], static fn(array $part): bool => 'footer' === ($part['area'] ?? null)), 'Differing document footers remain page-local rather than becoming an ambiguous shared part.');
$assert(str_contains($header['canonical_block_markup'] ?? '', '"url":"/guides/about"') && str_contains($header['canonical_block_markup'] ?? '', '"url":"/"'), 'Route-relative navigation destinations are canonicalized before shell identity comparison.');
$assert(str_contains($header['canonical_block_markup'] ?? '', '"anchor":"site-chrome"') && str_contains($header['canonical_block_markup'] ?? '', 'site-header') && str_contains($header['canonical_block_markup'] ?? '', 'border'), 'Shared shell preserves its canonical landmark wrapper anchor, class, and style attributes.');
$expectedFooters = array('index.html' => 'Home footer', 'guides/about.html' => 'About footer', 'guides/team.html' => 'Team footer');
foreach ($expectedFooters as $source => $expectedFooter) {
    $markup = $documents[$source]['canonical_block_markup'] ?? '';
    $assert(1 === substr_count($markup, '"tagName":"header"') && str_contains($markup, 'Skip to content') && str_contains($markup, 'article header') && str_contains($markup, 'article footer') && str_contains($markup, $expectedFooter) && 2 === substr_count($markup, '"tagName":"footer"'), "{$source} removes only the shared header and retains skip links, article landmarks, and its complete footer: {$markup}");
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
$assert(str_contains($nearPages['index.html']['canonical_block_markup'] ?? '', 'Home') && str_contains($nearPages['about.html']['canonical_block_markup'] ?? '', 'Contact us') && !array_filter($nearMatch['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)), 'Route-specific header actions are retained as a near-match instead of being deduplicated.');

$singleResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<header class="solo"><p>Solo</p></header><main>Home</main><footer>Solo footer</footer>')))->toArray();
$single = $singleResult['source_reports']['wordpress_site_plan'];
$singleWrites = $writes($single);
$assert(2 === count(array_filter($single['template_parts'], static fn(array $part): bool => 'entry_shell' === ($part['placement']['kind'] ?? null))) && str_contains($singleWrites['templates/front-page.html']['payload']['data'], '"slug":"header"') && !str_contains($singleWrites['templates/page.html']['payload']['data'], '"slug":"header"') && !str_contains($singleWrites['templates/index.html']['payload']['data'], '"slug":"header"'), 'A single-page artifact retains entry-shell behavior and binds chrome only to front-page.');
$singleHeader = array_values(array_filter($single['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)))[0] ?? array();
$assert(str_contains($singleHeader['canonical_block_markup'] ?? '', '"tagName":"header"') && str_contains($singleHeader['canonical_block_markup'] ?? '', 'solo') && str_contains($singleHeader['canonical_block_markup'] ?? '', 'Solo') && !str_contains($pages($single)['index.html']['canonical_block_markup'] ?? '', 'Solo</p>'), 'Single-page entry chrome preserves its semantic landmark and source presentation while removing it from post content.');
$compiledHeader = array_values(array_filter($singleResult['source_reports']['compiled_site']['template_parts'] ?? array(), static fn(array $part): bool => 'header' === ($part['area'] ?? null)))[0] ?? array();
$compiledPage = $singleResult['source_reports']['compiled_site']['pages'][0] ?? array();
$assert('entry_shell' === ($compiledHeader['placement']['kind'] ?? null) && !str_contains($compiledHeader['block_markup'] ?? '', '"tagName":"header"') && str_contains($compiledHeader['block_markup'] ?? '', '"className":"solo"') && str_contains($compiledHeader['block_markup'] ?? '', 'Solo') && !str_contains($compiledPage['block_markup'] ?? '', 'Solo</p>'), 'The compiled-site compatibility report retains source shell classes on a non-landmark wrapper without duplicate page chrome.');
$materializedHeader = array_values(array_filter($singleResult['source_reports']['materialization_plan']['template_part_writes'] ?? array(), static fn(array $write): bool => 'header' === ($write['area'] ?? null)))[0] ?? array();
$assert(str_contains($materializedHeader['content'] ?? '', '"className":"solo"') && !str_contains($materializedHeader['content'] ?? '', '"tagName":"header"'), 'The v1 materialization projection carries source shell classes without nesting a header landmark inside the template-part wrapper.');

$incomplete = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<header>Shared</header><main>Home</main>', 'about.html' => '<header>Shared</header><main>About</main>', 'contact.html' => '<main>Contact</main>')))->toArray()['source_reports']['wordpress_site_plan'];
$multiple = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<header>One</header><header>Two</header><main>Home</main>', 'about.html' => '<header>One</header><header>Two</header><main>About</main>')))->toArray()['source_reports']['wordpress_site_plan'];
$assert(!array_filter($incomplete['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)) && !array_filter($multiple['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)) && in_array('wordpress_site_plan_shell_retained_incomplete', array_column($incomplete['diagnostics'], 'code'), true) && in_array('wordpress_site_plan_shell_retained_incomplete', array_column($multiple['diagnostics'], 'code'), true), 'Some-pages-only and multiple shell candidates remain in page content without generic template bindings.');

fwrite(STDOUT, "shared-shell-plan contract passed\n");

<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$compile = static fn (array $files, array $artifact = array()): array => ( new ArtifactCompiler() )->compile(array_merge(array('entrypoint' => 'website/index.html', 'files' => $files), $artifact))->toArray();
$links = static fn (array $result): array => $result['source_reports']['wordpress_site_plan']['pages'][0]['document_metadata']['links'] ?? array();
$scripts = static fn (array $result): array => $result['source_reports']['wordpress_site_plan']['pages'][0]['document_metadata']['scripts'] ?? array();
$omissions = static fn (array $result): array => array_values(array_filter(
    $result['source_reports']['wordpress_site_plan']['diagnostics'] ?? array(),
    static fn (array $diagnostic): bool => 'wordpress_site_plan_omitted_link_declaration' === ($diagnostic['code'] ?? null)
));

// A captured WordPress site. WordPress publishes its XML-RPC pingback endpoint,
// its RSD `EditURI` and its feed discovery links in every head. None of them is
// a captured asset and none can become an artifact route -- `/xmlrpc.php` has a
// dotted segment and `/feed/` a trailing slash -- so before this contract the
// first of them aborted the whole import with `unresolved_local_url`.
$wordpressHead = <<<HTML
<!doctype html><html lang="en"><head>
<title>Mawonga Magic</title>
<link rel="stylesheet" href="wp-content/themes/thegem/style.css">
<link rel="pingback" href="/xmlrpc.php">
<link rel="alternate" type="application/rss+xml" title="Feed" href="/feed/">
<link rel="alternate" type="application/rss+xml" title="Comments Feed" href="/comments/feed/">
<link rel="EditURI" type="application/rsd+xml" title="RSD" href="/xmlrpc.php?rsd">
<link rel="next" href="/about">
<link rel="author" href="https://other.example.test/author">
<script src="wp-content/themes/thegem/app.js"></script>
<script>window.theGemSettings = {};</script>
</head><body><main><h1>Mawonga Magic</h1><p>Captured home.</p></main></body></html>
HTML;
$wordpress = $compile(array(
    'website/index.html' => $wordpressHead,
    'website/about/index.html' => '<main><h1>About</h1><p>About us.</p></main>',
    'website/wp-content/themes/thegem/style.css' => 'body{color:#111}',
    'website/wp-content/themes/thegem/app.js' => 'window.app=1;',
));
$wordpressPlan = $wordpress['source_reports']['wordpress_site_plan'] ?? null;
$assert(is_array($wordpressPlan) && 'failed' !== $wordpress['status'] && !isset($wordpress['source_reports']['wordpress_site_plan_diagnostics']), 'A captured WordPress head no longer fails the whole site plan on its own discovery links.');
WordPressSitePlan::assertValid($wordpressPlan);
$assert(2 === count($wordpressPlan['pages']), 'Every captured page survives the omitted discovery links.');

$wordpressLinks = $links($wordpress);
$retained = array_map(static fn (array $row): string => (string) ($row['rel'] ?? ''), $wordpressLinks);
$assert(array('stylesheet', 'next', 'author') === $retained, 'Only the stylesheet, the route link and the explicit author link are declared.');
$assert(array(0, 1, 2) === array_column($wordpressLinks, 'order'), 'Link order stays contiguous after the omissions.');
$assert(str_starts_with((string) ($wordpressLinks[0]['asset_reference'] ?? ''), WordPressSitePlan::TOKEN_PREFIX) && !isset($wordpressLinks[0]['url']), 'A stylesheet with a resolved asset reference is still emitted.');
$assert('/about' === ($wordpressLinks[1]['url'] ?? null), 'A genuine route keeps its local URL.');
$assert('https://other.example.test/author' === ($wordpressLinks[2]['url'] ?? null), 'An absolute https link is left alone.');

$wordpressScripts = $scripts($wordpress);
$assert(2 === count($wordpressScripts) && array(0, 1) === array_column($wordpressScripts, 'order'), 'Both captured head scripts are declared with contiguous order.');
foreach ( $wordpressScripts as $script ) {
    $assert(is_string($script['asset_reference'] ?? null) || 'inline' === ($script['source_kind'] ?? null), 'Captured and inline scripts are untouched by link omission.');
}

$wordpressOmissions = $omissions($wordpress);
$assert(4 === count($wordpressOmissions), 'Every omitted link declaration is reported once.');
$omittedValues = array_column($wordpressOmissions, 'value');
sort($omittedValues);
$assert(array('/comments/feed/', '/feed/', '/xmlrpc.php', '/xmlrpc.php?rsd') === $omittedValues, 'The report names each omitted href.');
$pingback = array_values(array_filter($wordpressOmissions, static fn (array $diagnostic): bool => '/xmlrpc.php' === ($diagnostic['value'] ?? null)))[0] ?? array();
$assert('warning' === ($pingback['severity'] ?? null) && 'website/index.html' === ($pingback['source_path'] ?? null) && 'pingback' === ($pingback['rel'] ?? null) && 'unresolved_local_url' === ($pingback['reason_code'] ?? null) && str_contains((string) ($pingback['message'] ?? ''), '/xmlrpc.php'), 'The omission warning records the page, relation and reason.');
$assert(in_array('wordpress_site_plan_omitted_link_declaration', $wordpressPlan['reporting']['diagnostic_codes'], true), 'The omission warning is linked to plan reporting.');

// An inline script with no `src` stays inline, and an unresolved local
// `<script src>` keeps failing closed: a script names executable behavior the
// page needs, so there is no metadata-only script to omit.
$inlineOnly = $compile(array('website/index.html' => '<!doctype html><html><head><link rel="pingback" href="/xmlrpc.php"><script>window.inline=1;</script></head><body><main>Home</main></body></html>'));
$inlineScripts = $scripts($inlineOnly);
$assert(isset($inlineOnly['source_reports']['wordpress_site_plan']) && 1 === count($inlineScripts) && (is_string($inlineScripts[0]['asset_reference'] ?? null) || 'inline' === ($inlineScripts[0]['source_kind'] ?? null)) && array() === $links($inlineOnly), 'An inline script is unaffected while the pingback link is omitted.');
$missingScript = $compile(array('website/index.html' => '<!doctype html><html><head><link rel="pingback" href="/xmlrpc.php"><script src="/wp-includes/js/missing.js"></script></head><body><main>Home</main></body></html>'));
$missingScriptDiagnostic = $missingScript['source_reports']['wordpress_site_plan_diagnostics'][0] ?? array();
$assert(!isset($missingScript['source_reports']['wordpress_site_plan']) && 'script' === ($missingScriptDiagnostic['declaration_kind'] ?? null) && 'unresolved_local_url' === ($missingScriptDiagnostic['reason'] ?? null), 'An unresolved local script source still fails closed.');

// The omission is scoped to relations that name no subresource. A relation that
// mixes a rendered resource in keeps failing closed, and an explicit absolute
// endpoint is a truthful destination that stays declared.
$mixed = $compile(array('website/index.html' => '<!doctype html><html><head><link rel="pingback stylesheet" href="/xmlrpc.php"></head><body><main>Home</main></body></html>'));
$mixedDiagnostic = $mixed['source_reports']['wordpress_site_plan_diagnostics'][0] ?? array();
$assert(!isset($mixed['source_reports']['wordpress_site_plan']) && 'link' === ($mixedDiagnostic['declaration_kind'] ?? null) && 'unresolved_local_url' === ($mixedDiagnostic['reason'] ?? null), 'A relation that also names a rendered resource is not weakened by the omission.');
$absoluteEndpoint = $compile(array('website/index.html' => '<!doctype html><html><head><link rel="pingback" href="https://other.example.test/xmlrpc.php"></head><body><main>Home</main></body></html>'));
$absoluteLinks = $links($absoluteEndpoint);
$assert(isset($absoluteEndpoint['source_reports']['wordpress_site_plan']) && 1 === count($absoluteLinks) && 'https://other.example.test/xmlrpc.php' === ($absoluteLinks[0]['url'] ?? null) && array() === $omissions($absoluteEndpoint), 'An absolute discovery endpoint stays declared and unreported.');

echo "source-protocol-link-declarations contract passed\n";

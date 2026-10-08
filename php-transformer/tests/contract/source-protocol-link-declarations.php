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
<title>Captured Site</title>
<link rel="stylesheet" href="wp-content/themes/thegem/style.css">
<link rel="pingback" href="/xmlrpc.php">
<link rel="alternate" type="application/rss+xml" title="Feed" href="/feed/">
<link rel="alternate" type="application/rss+xml" title="Comments Feed" href="/comments/feed/">
<link rel="EditURI" type="application/rsd+xml" title="RSD" href="/xmlrpc.php?rsd">
<link rel="alternate" type="application/json+oembed" href="/wp-json/oembed/1.0/embed?url=https%3A%2F%2Fexample.test%2F">
<link rel="alternate" type="text/xml+oembed" href="/wp-json/oembed/1.0/embed?url=https%3A%2F%2Fexample.test%2F&amp;format=xml">
<link rel="next" href="/about">
<link rel="author" href="https://other.example.test/author">
<script src="wp-content/themes/thegem/app.js"></script>
<script>window.theGemSettings = {};</script>
</head><body><main><h1>Captured Site</h1><p>Captured home.</p></main></body></html>
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
$scriptToken = static fn (array $script): string => (string) ($script['asset_reference'] ?? '');
foreach ( $wordpressScripts as $index => $script ) {
    $assert(1 === preg_match('/^\{\{wordpress-site-plan:asset:[^}]+\}\}$/', $scriptToken($script)), "Head script {$index} keeps a declared asset token.");
}
$assert($scriptToken($wordpressScripts[0]) !== $scriptToken($wordpressScripts[1]), 'The external and inline head scripts keep distinct assets.');

$wordpressOmissions = $omissions($wordpress);
$assert(6 === count($wordpressOmissions), 'Every omitted link declaration is reported once.');
$omittedValues = array_column($wordpressOmissions, 'value');
sort($omittedValues);
$assert(array(
    '/comments/feed/',
    '/feed/',
    '/wp-json/oembed/1.0/embed?url=https%3A%2F%2Fexample.test%2F',
    '/wp-json/oembed/1.0/embed?url=https%3A%2F%2Fexample.test%2F&format=xml',
    '/xmlrpc.php',
    '/xmlrpc.php?rsd',
) === $omittedValues, 'The report names each omitted href.');
$pingback = array_values(array_filter($wordpressOmissions, static fn (array $diagnostic): bool => '/xmlrpc.php' === ($diagnostic['value'] ?? null)))[0] ?? array();
$assert('warning' === ($pingback['severity'] ?? null) && 'website/index.html' === ($pingback['source_path'] ?? null) && 'pingback' === ($pingback['rel'] ?? null) && 'unresolved_local_url' === ($pingback['reason_code'] ?? null) && str_contains((string) ($pingback['message'] ?? ''), '/xmlrpc.php'), 'The omission warning records the page, relation and reason.');
$assert(in_array('wordpress_site_plan_omitted_link_declaration', $wordpressPlan['reporting']['diagnostic_codes'], true), 'The omission warning is linked to plan reporting.');

// An inline script with no `src` stays inline, and an unresolved local
// `<script src>` keeps failing closed: a script names executable behavior the
// page needs, so there is no metadata-only script to omit.
$inlineOnly = $compile(array('website/index.html' => '<!doctype html><html><head><link rel="pingback" href="/xmlrpc.php"><script>window.inline=1;</script></head><body><main>Home</main></body></html>'));
$inlineScripts = $scripts($inlineOnly);
$assert(isset($inlineOnly['source_reports']['wordpress_site_plan']) && 1 === count($inlineScripts) && 1 === preg_match('/^\{\{wordpress-site-plan:asset:[^}]+\}\}$/', (string) ($inlineScripts[0]['asset_reference'] ?? '')) && array() === $links($inlineOnly), 'An inline script is unaffected while the pingback link is omitted.');
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

// WordPress core prints the oEmbed discovery pair on every singular page, and
// `oembed/1.0/` carries a dotted segment for the same reason `/xmlrpc.php` does.
// A consumer fetches these to embed the page elsewhere; nothing rendered needs
// them, so an unresolved one is omitted rather than aborting the import.
$oembed = $compile(array('website/index.html' => '<!doctype html><html><head>'
    . '<link rel="alternate" type="application/json+oembed" href="/wp-json/oembed/1.0/embed?url=https%3A%2F%2Fexample.test%2F">'
    . '<link rel="alternate" type="text/xml+oembed" href="/wp-json/oembed/1.0/embed?url=https%3A%2F%2Fexample.test%2F&format=xml">'
    . '</head><body><main>Home</main></body></html>'));
$oembedOmissions = $omissions($oembed);
$assert(isset($oembed['source_reports']['wordpress_site_plan']), 'An oEmbed discovery pair no longer aborts the whole plan.');
$assert(array() === $links($oembed), 'Both oEmbed discovery links are omitted.');
$assert(2 === count($oembedOmissions), 'Both oEmbed omissions are reported.');
$oembedMixed = $compile(array('website/index.html' => '<!doctype html><html><head><link rel="alternate stylesheet" type="application/json+oembed" href="/wp-json/oembed/1.0/embed"></head><body><main>Home</main></body></html>'));
$assert(!isset($oembedMixed['source_reports']['wordpress_site_plan']), 'An oEmbed type on a relation that also names a rendered resource still fails closed.');

// A diagnostic field is clipped to a byte budget, so a clip that split a UTF-8
// sequence would leave an invalid string -- and planIdentity() json_encode()s
// every diagnostic under JSON_THROW_ON_ERROR, which would discard the whole
// plan. That is the failure this omission exists to avoid, so sweep the
// boundary rather than pinning one offset.
foreach (range(248, 264) as $fill) {
    $href = '/' . str_repeat('a', $fill) . "\u{00e9}x";
    $long = $compile(array('website/index.html' => '<!doctype html><html><head><link rel="alternate" type="application/rss+xml" href="' . $href . '"></head><body><main>Home</main></body></html>'));
    $assert(isset($long['source_reports']['wordpress_site_plan']), "A {$fill}-byte non-ASCII feed href is omitted without discarding the plan.");
    foreach ($omissions($long) as $diagnostic) {
        foreach ($diagnostic as $field) {
            $assert(!is_string($field) || mb_check_encoding($field, 'UTF-8'), "Every omission diagnostic field stays valid UTF-8 at {$fill} bytes.");
        }
    }
    $assert(is_string(json_encode($long['source_reports']['wordpress_site_plan']['diagnostics'] ?? array(), JSON_THROW_ON_ERROR)), "Omission diagnostics stay serializable at {$fill} bytes.");
}

// The diagnostic list is bounded, so a head with more unresolved optional
// links than the cap reports the cap plus one truncation row carrying the
// remainder. Without this the bound can be deleted and the suite stays green.
$manyHints = '';
foreach (range(1, 61) as $index) {
    $manyHints .= '<link rel="preload" as="font" href="/fonts/missing-' . $index . '.woff2">';
}
$capped = $compile(array('website/index.html' => '<!doctype html><html><head>' . $manyHints . '</head><body><main>Home</main></body></html>'));
$cappedOmissions = $omissions($capped);
$truncation = array_values(array_filter($cappedOmissions, static fn (array $diagnostic): bool => 'truncated' === ($diagnostic['reason'] ?? null)));
$assert(isset($capped['source_reports']['wordpress_site_plan']), 'A head past the diagnostic cap still produces a plan.');
$assert(51 === count($cappedOmissions), 'The omission list is capped at 50 rows plus one truncation row.');
$assert(1 === count($truncation) && 11 === ($truncation[0]['omitted_count'] ?? null), 'The truncation row carries the remaining count.');

// The report is keyed on the declaration, not the page. WordPress prints the
// same discovery links in every head, so keying on the page would spend the
// whole budget restating one site's boilerplate.
$page = '<!doctype html><html><head>'
    . '<link rel="pingback" href="/xmlrpc.php">'
    . '<link rel="EditURI" type="application/rsd+xml" href="/xmlrpc.php?rsd">'
    . '<link rel="alternate" type="application/rss+xml" href="/feed/">'
    . '</head><body><main>Page</main></body></html>';
$manyPages = array('website/index.html' => $page);
foreach (range(1, 9) as $index) {
    $manyPages["website/page-{$index}/index.html"] = $page;
}
$repeated = $compile($manyPages);
$repeatedOmissions = $omissions($repeated);
$assert(isset($repeated['source_reports']['wordpress_site_plan']), 'A ten-page capture of one WordPress head still produces a plan.');
$assert(10 === count($repeated['source_reports']['wordpress_site_plan']['pages'] ?? array()), 'All ten pages materialize.');
$assert(3 === count($repeatedOmissions), 'Boilerplate repeated on every page collapses to one row per declaration.');
$repeatedCounts = array_column($repeatedOmissions, 'occurrences');
sort($repeatedCounts);
$assert(array(10, 10, 10) === $repeatedCounts, 'Each collapsed row counts every page it occurred on.');

// A media type may carry parameters and still be the same type. Missing one
// would cost the whole plan, which is the failure this contract exists to stop.
$parameterized = $compile(array('website/index.html' => '<!doctype html><html><head>'
    . '<link rel="alternate" type="application/json+oembed; charset=utf-8" href="/wp-json/oembed/1.0/embed?url=x">'
    . '<link rel="alternate" type="  TEXT/XML+OEMBED ; charset=UTF-8" href="/wp-json/oembed/1.0/embed?format=xml">'
    . '</head><body><main>Home</main></body></html>'));
$assert(isset($parameterized['source_reports']['wordpress_site_plan']), 'A parameterized oEmbed media type is still recognized.');
$assert(array() === $links($parameterized), 'Both parameterized oEmbed links are omitted.');
$assert(2 === count($omissions($parameterized)), 'Both parameterized omissions are reported.');
$notOembed = $compile(array('website/index.html' => '<!doctype html><html><head><link rel="alternate" type="application/json" href="/wp-json/oembed/1.0/embed"></head><body><main>Home</main></body></html>'));
$assert(!isset($notOembed['source_reports']['wordpress_site_plan']) || array() === $omissions($notOembed), 'A non-oEmbed media type is not swept up by the suffix match.');

// `occurrences` counts the pages that carried a declaration, not the tags that
// matched it: fullDocumentMetadata() keeps duplicates, so one head printing the
// same pingback twice is still one affected page.
$twice = '<!doctype html><html><head>'
    . '<link rel="pingback" href="/xmlrpc.php">'
    . '<link rel="pingback" href="/xmlrpc.php">'
    . '</head><body><main>Home</main></body></html>';
$duplicated = $compile(array('website/index.html' => $twice, 'website/second/index.html' => $twice));
$duplicatedOmissions = $omissions($duplicated);
$assert(isset($duplicated['source_reports']['wordpress_site_plan']), 'Duplicate declarations still produce a plan.');
$assert(1 === count($duplicatedOmissions), 'A declaration repeated within and across pages collapses to one row.');
$assert(2 === ($duplicatedOmissions[0]['occurrences'] ?? null), 'Two pages carrying it twice each count as two pages, not four tags.');
$once = $compile(array('website/index.html' => $twice));
$onceOmissions = $omissions($once);
$assert(1 === ($onceOmissions[0]['occurrences'] ?? null), 'One page printing it twice counts as one page.');

echo "source-protocol-link-declarations contract passed\n";

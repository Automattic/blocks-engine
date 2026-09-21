<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\MissingMediaRecovery;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$pagesOf = static fn (array $plan): array => array_column($plan['pages'] ?? array(), 'canonical_block_markup', 'source_path');
$linkDiagnostics = static fn (array $plan): array => array_values(array_filter($plan['diagnostics'] ?? array(), static fn (array $diagnostic): bool => 'wordpress_site_plan_unresolved_navigation_link' === ($diagnostic['code'] ?? null)));

// A captured multi-page site whose nested page links to a sibling file the
// capture never packaged. The link names a real page on the source site, so the
// plan points it there instead of rejecting every captured page.
$files = array(
    'website/index.html' => '<main><h1>Home</h1><p><a href="interactive/">Interactive</a></p></main>',
    'website/interactive/index.html' => '<main><h1>Interactive</h1><p><a href="./missing.zip?v=1#top">Download</a> or <a href="../">home</a> or <a href="../../outside.html">outside</a>.</p><p><a href="./missing.zip"><img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" alt="Zip"></a></p></main>',
);
$captured = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $files, 'provenance' => array('source_url' => 'https://example.test/course/')))->toArray();
$capturedPlan = $captured['source_reports']['wordpress_site_plan'] ?? null;
$assert(is_array($capturedPlan) && 'failed' !== $captured['status'] && !isset($captured['source_reports']['wordpress_site_plan_diagnostics']), 'An unresolved document-relative navigation link no longer fails the whole site plan.');
$assert(2 === count($capturedPlan['pages']), 'Every captured page survives an unresolved navigation link on one page.');
$interactive = (string) ($pagesOf($capturedPlan)['website/interactive/index.html'] ?? '');
$assert(str_contains($interactive, 'href="https://example.test/course/interactive/missing.zip?v=1#top"'), 'A document-relative link resolves against the page URL on the recorded source site, keeping its query and fragment.');
$assert(str_contains($interactive, 'href="https://example.test/outside.html"'), 'Traversal above the captured root resolves against the source URL rather than escaping the artifact.');
$assert(str_contains($interactive, 'href="/"') && !preg_match('~href=(?:"|\\\\u0022)\\.{1,2}/~', $interactive), 'Resolvable routes stay local and no document-relative navigation link remains.');
$assert(1 === substr_count($interactive, 'href=\\u0022https://example.test/course/interactive/missing.zip\\u0022'), 'Linked media companion content carries the same absolute source URL.');
$assert(str_contains((string) ($pagesOf($capturedPlan)['website/index.html'] ?? ''), 'href="/interactive"'), 'A directory link to a captured index document stays a local route.');
$diagnostics = $linkDiagnostics($capturedPlan);
$assert(3 === count($diagnostics), 'Each distinct unresolved navigation link on a page is reported once.');
$first = $diagnostics[0];
$assert('warning' === $first['severity'] && 'website/interactive/index.html' === $first['source_path'] && './missing.zip?v=1#top' === $first['value'] && 'source_url' === $first['resolution'] && 'https://example.test/course/interactive/missing.zip?v=1#top' === $first['resolved_url'], 'The diagnostic records the page, original link, and absolute source URL.');
$assert(in_array('wordpress_site_plan_unresolved_navigation_link', $capturedPlan['reporting']['diagnostic_codes'], true), 'The unresolved-link diagnostic is linked to plan reporting.');
WordPressSitePlan::assertValid($capturedPlan);

// Without a recorded source URL there is no truthful destination, so the link
// becomes a same-page fragment rather than a reference WordPress would resolve
// against the imported page's own URL.
$local = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<main><a href="about.html">About</a></main>', 'about.html' => '<main><p><a href="./missing.zip">Download</a></p></main>')))->toArray();
$localPlan = $local['source_reports']['wordpress_site_plan'] ?? null;
$assert(is_array($localPlan) && 2 === count($localPlan['pages']), 'A file-only artifact with an unresolved navigation link still produces a plan.');
$about = (string) ($pagesOf($localPlan)['about.html'] ?? '');
$assert(str_contains($about, '<a href="#">Download</a>') && !str_contains($about, 'missing.zip'), 'Without a source URL the unresolved link is neutralized to a same-page fragment.');
$localDiagnostics = $linkDiagnostics($localPlan);
$assert(1 === count($localDiagnostics) && 'neutralized' === $localDiagnostics[0]['resolution'] && './missing.zip' === $localDiagnostics[0]['value'] && !isset($localDiagnostics[0]['resolved_url']), 'The neutralized link is reported with its original value.');

// Media references stay on the declared-token path rather than the navigation
// path: a missing image resolves to the placeholder media token, never to a
// source URL or a neutralized fragment.
$missingImage = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<main><img src="missing.png" alt="Missing"></main>'), 'provenance' => array('source_url' => 'https://example.test/')))->toArray();
$missingImagePlan = $missingImage['source_reports']['wordpress_site_plan'] ?? array();
$missingImageMarkup = (string) ($missingImagePlan['pages'][0]['canonical_block_markup'] ?? '');
$assert(array() !== $missingImagePlan && str_contains($missingImageMarkup, MissingMediaRecovery::placeholderReference()) && !str_contains($missingImageMarkup, 'https://example.test/missing.png') && array() === $linkDiagnostics($missingImagePlan), 'Unresolved local media recovers through the placeholder token instead of the navigation link path.');

echo "unresolved-navigation-links contract passed\n";

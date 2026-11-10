<?php
/**
 * SVG 1.1 anchors resolve through the same route table as HTML anchors. The
 * foreignObject keeps this SVG out of the artwork block, so its markup stays in
 * the page where the site plan must resolve and validate the link itself.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$files = array(
    'index.html' => <<<'HTML'
<!doctype html>
<html><head><meta charset="utf-8"><title>Home</title></head>
<body>
<h1>Areas</h1>
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 100 50" width="200">
  <a xlink:href="/other/index.html"><circle cx="25" cy="25" r="20" fill="#2B9CE0"></circle></a>
  <foreignObject width="100" height="10"><p>Legend</p></foreignObject>
</svg>
<p><a href="/other/index.html">Other page</a></p>
</body></html>
HTML,
    'other/index.html' => '<!doctype html><html><head><meta charset="utf-8"><title>Other</title></head><body><h1>Other</h1><p>Second page.</p></body></html>',
);
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
$plan = $compiled['source_reports']['wordpress_site_plan'] ?? null;
if (!is_array($plan)) {
    fwrite(STDERR, 'FAIL: Reduced SVG page did not produce a valid site plan: ' . json_encode($compiled['diagnostics'] ?? $compiled) . "\n");
    exit(1);
}
WordPressSitePlan::assertValid($plan);
$resolved = (new WordPressSitePlanResolver())->resolve($plan, array('theme_uri' => 'https://example.test/wp-content/themes/svg-map'));

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) { ++$failures; fwrite(STDERR, "FAIL: {$message}\n"); }
};
foreach (array('canonical' => $plan, 'resolved' => $resolved) as $stage => $site) {
    $home = null;
    foreach ($site['pages'] as $page) if ('index.html' === $page['source_path']) $home = $page;
    // Linked SVG artwork keeps its markup in a JSON block attribute.
    $markup = str_replace(array('\\u003c', '\\u003e', '\\u0022'), array('<', '>', '"'), $home['resolved_block_markup'] ?? $home['canonical_block_markup'] ?? '');
    $assert(1 === preg_match('~<a\b[^>]*\bxlink:href="([^"]+)"~', $markup, $svg), "{$stage}: SVG anchor is retained.");
    $assert(1 === preg_match('~<a\b[^>]*(?<![:\w-])href="([^"]+)"[^>]*>Other page</a>~', $markup, $html), "{$stage}: sibling HTML anchor is retained.");
    $assert('/other' === ($svg[1] ?? null) && ($svg[1] ?? null) === ($html[1] ?? null), "{$stage}: SVG and HTML anchors resolve to the same WordPress route: " . json_encode(array($svg[1] ?? null, $html[1] ?? null)));
}

// Resource-bearing SVG elements must not acquire the anchor route exemption.
$invalid = $plan;
$invalid['pages'][0]['canonical_block_markup'] .= '<svg><use xlink:href="/missing.svg#icon"></use></svg>';
$invalid['pages'][0]['content_hash'] = WordPressSitePlan::contentHash($invalid['pages'][0]['canonical_block_markup']);
$rejected = false;
try { WordPressSitePlan::assertValid($invalid); } catch (InvalidArgumentException $error) {
    $rejected = str_contains($error->getMessage(), 'unresolved local browser reference /missing.svg#icon');
}
$assert($rejected, 'SVG resource references still require declared assets.');

if (0 !== $failures) exit(1);
echo "SVG anchor route: passed\n";

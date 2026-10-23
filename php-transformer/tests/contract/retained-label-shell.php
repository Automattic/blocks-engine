<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\AssetReferenceCanonicalizer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ShellExtraction;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// Investigation fixture for #3279, not a claim that sharing is implemented.
// Keep route state until a generic retained-label route-state contract exists.
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$files = array();
foreach (glob(__DIR__ . '/fixtures/retained-label-shell/*.html') as $file) $files[basename($file)] = file_get_contents($file);
$compile = static fn(array $files): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
$headers = static fn(array $plan): array => array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']));
$plan = $compile($files);
WordPressSitePlan::assertValid($plan);
$assert(3 === count($plan['pages']) && array() === $headers($plan), 'Three retained-label state variants currently produce no shared header.');
$extraction = new ShellExtraction(new WordPressSitePlan());
$identities = array();
foreach ($plan['pages'] as $page) {
    $markup = $page['canonical_block_markup'];
    $rows = $extraction->shellCandidates(array('source_path' => $page['source_path']), new AssetReferenceCanonicalizer(array()), array(), $markup);
    $rows = array_values(array_filter($rows, static fn(array $row): bool => 'header' === $row['area']));
    $assert(1 === count($rows), 'Landmark discovery finds exactly one header per page.');
    $identities[] = $rows[0]['identity_markup'];
    $assert(3 === substr_count($markup, '<!-- wp:custom/authored-input ') && str_contains($markup, '"labelMarkup":') && 1 === substr_count($markup, 'class="item item--active"'), 'Captured controls and the route-specific label state survive conversion.');
    $assert(str_contains($markup, 'Page body.'), 'Body content remains intact.');
}
$assert(3 === count(array_unique($identities)), 'The difference survives shell identity normalization.');
$diagnostic = current(array_filter($plan['diagnostics'], static fn(array $row): bool => 'header' === ($row['area'] ?? null) && 'non_equivalent' === ($row['provenance']['reason'] ?? null)));
$assert(is_array($diagnostic) && 3 === count($diagnostic['provenance']['sources']), 'The grouping decision reports non-equivalent candidates, not missing landmarks.');

// Control: changing only the captured state to resting yields one header.
$resting = array_map(static fn(string $html): string => str_replace(array(' item--active', 'data-key="active-'), array('', 'data-key="'), $html), $files);
$control = $compile($resting);
WordPressSitePlan::assertValid($control);
$assert(1 === count($headers($control)), 'Identical retained control labels can already share a header.');

// Separately reproduce the issue's hypothetical href-only gap. This is not
// the cause in the sampled capture: every source header there has the same
// href list. Sharing these targets needs a route-dependent value contract.
$links = array();
foreach (array('index', 'about', 'work') as $slug) $links[$slug . '.html'] = '<header><a href="/">Site</a><nav><a href="https://example.org/alternate/' . $slug . '">Alternate</a></nav></header><main><h1>' . $slug . '</h1><p>Page body.</p></main>';
$linkPlan = $compile($links);
WordPressSitePlan::assertValid($linkPlan);
$assert(array() === $headers($linkPlan), 'Unbound per-page targets must not freeze the first page\'s URL into a shared header.');

echo "retained-label-shell investigation contract passed\n";

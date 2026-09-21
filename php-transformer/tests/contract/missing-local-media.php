<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\MissingMediaRecovery;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ValidationException;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$placeholder = MissingMediaRecovery::placeholderReference();
$mediaDiagnostics = static fn (array $plan): array => array_values(array_filter($plan['diagnostics'] ?? array(), static fn (array $diagnostic): bool => MissingMediaRecovery::DIAGNOSTIC_CODE === ($diagnostic['code'] ?? null)));
$planOf = static fn (array $result): array => $result['source_reports']['wordpress_site_plan'] ?? array();
$markupOf = static fn (array $plan, int $index = 0): string => (string) ($plan['pages'][$index]['canonical_block_markup'] ?? '');

// Fixture 89 has referenced an image its capture never packaged since #817. The
// whole site must still import: the page, its heading, its alternative text and
// its authored image geometry are all materializable without that photograph.
$hearthHtml = '<main><h1>Hearth Bistro</h1><img src="images/hearth-fire.jpg" alt="Live oak fire in the open hearth" width="1200" height="800"><p>Wood-fired cooking.</p></main>';
$hearth = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => array('website/index.html' => $hearthHtml), 'provenance' => array('source_url' => 'https://hearth.test/')))->toArray();
$hearthPlan = $planOf($hearth);
$hearthMarkup = $markupOf($hearthPlan);
$assert(array() !== $hearthPlan && 'failed' !== $hearth['status'], 'A site whose only defect is missing local media still produces a site plan.');
$assert(str_contains($hearthMarkup, $placeholder) && !str_contains($hearthMarkup, 'images/hearth-fire.jpg'), 'The missing image reference is replaced by the declared placeholder media token.');
$assert(str_contains($hearthMarkup, '<!-- wp:image') && str_contains($hearthMarkup, 'alt="Live oak fire in the open hearth"') && str_contains($hearthMarkup, '1200px') && str_contains($hearthMarkup, '800px'), 'Recovered media keeps its editable image block, alternative text and authored geometry.');
$assert(str_contains($hearthMarkup, '<h1 class="wp-block-heading">Hearth Bistro</h1>') && str_contains($hearthMarkup, 'Wood-fired cooking.'), 'Every other block on the page survives the recovery.');
WordPressSitePlan::assertValid($hearthPlan);

// The warning identifies the source document, element and missing asset path.
$hearthDiagnostics = $mediaDiagnostics($hearthPlan);
$assert(1 === count($hearthDiagnostics), 'One missing media reference reports exactly one warning.');
$hearthDiagnostic = $hearthDiagnostics[0];
$assert('warning' === $hearthDiagnostic['severity'] && 'website/index.html' === $hearthDiagnostic['source_path'] && 'images/hearth-fire.jpg' === $hearthDiagnostic['value'] && 'img' === $hearthDiagnostic['element'] && 'src' === $hearthDiagnostic['attribute'] && 'placeholder_media' === $hearthDiagnostic['resolution'], 'The warning names the document, element, attribute and missing asset path.');
$assert(in_array(MissingMediaRecovery::DIAGNOSTIC_CODE, $hearthPlan['reporting']['diagnostic_codes'] ?? array(), true), 'The missing-media warning reaches plan reporting.');

// Import completion stays distinct from conversion quality: a recovered import
// completes, and the recovery is reported as a warning rather than a failure.
$assert(true === ($hearthPlan['quality']['pass'] ?? null) && 'failed' !== ($hearthPlan['quality']['status'] ?? null), 'Recovered media leaves import completion intact.');
$assert(array() === array_values(array_filter($mediaDiagnostics($hearthPlan), static fn (array $diagnostic): bool => 'warning' !== ($diagnostic['severity'] ?? null))), 'Missing media is reported only at warning severity.');

// The placeholder is one declared asset with one write, however many references
// and documents it recovers, and each document reports its own miss once.
$multi = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<main><h1>Home</h1><img src="a.png" alt="A"><img src="a.png" alt="A again"><img src="b.png" alt="B"></main>',
    'about.html' => '<main><h1>About</h1><img src="a.png" alt="A"></main>',
)))->toArray();
$multiPlan = $planOf($multi);
$multiAssets = array_values(array_filter($multiPlan['assets'] ?? array(), static fn (array $asset): bool => MissingMediaRecovery::TARGET_PATH === ($asset['target_path'] ?? null)));
$multiWrites = array_values(array_filter($multiPlan['writes'] ?? array(), static fn (array $write): bool => MissingMediaRecovery::TARGET_PATH === ($write['target_path'] ?? null)));
$multiTokens = array_values(array_filter($multiPlan['reference_tokens'] ?? array(), static fn (array $token): bool => MissingMediaRecovery::placeholderToken() === ($token['token'] ?? null)));
$assert(1 === count($multiAssets) && 1 === count($multiWrites) && 1 === count($multiTokens), 'Recovered media declares exactly one placeholder asset, token and write.');
$assert('image/svg+xml' === ($multiAssets[0]['mime_type'] ?? null) && 'utf8' === ($multiWrites[0]['payload']['encoding'] ?? null) && str_contains((string) ($multiWrites[0]['payload']['data'] ?? ''), '<svg'), 'The placeholder write materializes an inert SVG payload.');
$assert(3 === count($mediaDiagnostics($multiPlan)), 'Each distinct missing asset is reported once per source document.');
$assert(4 === substr_count((string) ($markupOf($multiPlan) . $markupOf($multiPlan, 1)), $placeholder), 'Every missing media reference resolves to the shared placeholder token.');
WordPressSitePlan::assertValid($multiPlan);

// Stylesheet urls and responsive candidate lists recover through the same token.
$breadth = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<link rel="stylesheet" href="site.css"><main><h1>Breadth</h1><img src="hero.png" srcset="hero.png 1x, hero@2x.png 2x" alt="Hero"><video poster="poster.jpg"><source src="clip.mp4"></video></main>',
    'site.css' => '.hero{background-image:url(backdrop.png)}',
)))->toArray();
$breadthPlan = $planOf($breadth);
$breadthCss = (string) (array_values(array_filter($breadthPlan['writes'] ?? array(), static fn (array $write): bool => str_ends_with((string) ($write['target_path'] ?? ''), 'site.css')))[0]['payload']['data'] ?? '');
$assert(array() !== $breadthPlan && str_contains($markupOf($breadthPlan), $placeholder), 'Missing media in element attributes recovers.');
$assert(str_contains($breadthCss, $placeholder) && !str_contains($breadthCss, 'backdrop.png'), 'Missing media behind a stylesheet url recovers through the same placeholder token.');
$assert(count($mediaDiagnostics($breadthPlan)) >= 2, 'Stylesheet and document misses are reported separately.');
WordPressSitePlan::assertValid($breadthPlan);

// Declared media is untouched: recovery never displaces a resolvable asset and
// never declares a placeholder that no reference needs.
$present = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<main><h1>Present</h1><img src="logo.svg" alt="Logo"></main>',
    'logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 2 2"><rect width="2" height="2"/></svg>',
)))->toArray();
$presentPlan = $planOf($present);
$assert(array() !== $presentPlan && !str_contains($markupOf($presentPlan), $placeholder) && array() === $mediaDiagnostics($presentPlan), 'A packaged image keeps its own declared token with no missing-media warning.');
$assert(array() === array_values(array_filter($presentPlan['assets'] ?? array(), static fn (array $asset): bool => MissingMediaRecovery::TARGET_PATH === ($asset['target_path'] ?? null))), 'No placeholder asset is declared when nothing is missing.');

// Negative references keep their existing handling: percent-encoded separators
// stay an intake protection, and a missing script is not recoverable media.
$encoded = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<main><h1>Encoded</h1><img src="..%2fsecret.png" alt="Encoded"></main>')))->toArray();
$assert(array() === $planOf($encoded) && 'unresolved_local_browser_reference' === ($encoded['source_reports']['wordpress_site_plan_diagnostics'][0]['reason'] ?? null), 'Percent-encoded separators are rejected rather than recovered.');
$script = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<main><h1>Script</h1><script src="missing.js"></script></main>')))->toArray();
$assert(array() === $planOf($script), 'A missing script is not recoverable media and still has no self-contained plan.');
$navigation = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<main><h1>Nav</h1><p><a href="missing.html">Missing page</a></p></main>')))->toArray();
$navigationMarkup = $markupOf($planOf($navigation));
$assert(!str_contains($navigationMarkup, $placeholder) && array() === $mediaDiagnostics($planOf($navigation)), 'Navigation links keep their own recovery and never resolve to placeholder media.');

// Strict fidelity remains available as an explicit opt-in.
$strictReason = null;
try {
    (new WordPressSitePlan(true))->fromResult($hearth);
} catch (ValidationException $exception) {
    $strictReason = $exception->diagnostic()['reason'] ?? null;
}
$assert('unresolved_local_browser_reference' === $strictReason, 'Strict missing-media plans still reject unresolved local media references.');

// Staged compilation composes the same recovery as whole-artifact compilation.
$stagedArtifact = array('entrypoints' => array('index.html'), 'files' => array(
    array('path' => 'index.html', 'content' => $hearthHtml),
    array('path' => 'about.html', 'content' => '<main><h1>About</h1><img src="images/hearth-fire.jpg" alt="Live oak fire in the open hearth"></main>'),
));
$stagedCompiler = new ArtifactCompiler();
$stagedShared = $stagedCompiler->prepareShared($stagedArtifact);
$stagedReceipts = array();
foreach ($stagedShared['analysis']['page_ids'] as $pageId) $stagedReceipts[] = $stagedCompiler->compilePage($stagedArtifact, $stagedShared, $pageId);
$stagedPlan = $planOf($stagedCompiler->compose($stagedShared, $stagedReceipts)->toArray());
$stagedMarkup = implode('', array_column($stagedPlan['pages'] ?? array(), 'canonical_block_markup'));
$assert(array() !== $stagedPlan && 2 === substr_count($stagedMarkup, $placeholder) && 2 === count($mediaDiagnostics($stagedPlan)), 'Staged compilation recovers missing media on every composed page.');
$assert(1 === count(array_values(array_filter($stagedPlan['assets'] ?? array(), static fn (array $asset): bool => MissingMediaRecovery::TARGET_PATH === ($asset['target_path'] ?? null)))), 'Staged composition declares the placeholder asset exactly once.');
WordPressSitePlan::assertValid($stagedPlan);

echo "missing-local-media contract passed\n";

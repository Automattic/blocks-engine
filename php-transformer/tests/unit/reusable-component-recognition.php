<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$recognition = static function (string $html): array {
    return (new HtmlTransformer())->transform($html)->toArray()['source_reports']['html']['reusable_components'] ?? array();
};

$exact = $recognition('<main><span class="icon"><svg class="wp-container-1"><path d="M0 0h1v1z"/></svg></span><span class="icon"><svg class="wp-container-2"><path d="M0 0h1v1z"/></svg></span></main>');
$assert(1 === count($exact['components'] ?? array()), 'Proven WordPress-generated classes normalize for exact semantic repeats.');
$assert('shared_core_image_asset' === ($exact['components'][0]['mapping'] ?? ''), 'Repeated vectors report the emitted shared core/image asset mapping.');

$authoredClasses = $recognition('<main><section class="css-brand"><p>One</p></section><section class="css-other"><p>Two</p></section></main>');
$assert(array() === ($authoredClasses['components'] ?? array()), 'Authored css-* classes remain a styling boundary without generator proof.');

$slots = $recognition('<main><section class="card"><a href="/one" aria-label="One"><img src="one.svg" alt="One">First</a></section><section class="card"><a href="/two" aria-label="Two"><img src="two.svg" alt="Two">Second</a></section></main>');
$assert(1 === count($slots['components'] ?? array()) && 2 === ($slots['components'][0]['occurrence_count'] ?? 0), 'Content, links, and accessibility values are content slots while their semantic attributes remain boundaries.');

$styles = $recognition('<main><section class="card" style="color:red"><p>One</p></section><section class="card" style="color:blue"><p>Two</p></section></main>');
$assert(array() === ($styles['components'] ?? array()) && 1 === count($styles['near_matches'] ?? array()), 'Style variation is rejected as a reusable mapping and emitted as bounded near-match evidence.');

$falsePositive = $recognition('<main><section class="card"><a href="/one">One</a></section><section class="card"><button type="button">Two</button></section></main>');
$assert(array() === ($falsePositive['components'] ?? array()) && array() === ($falsePositive['near_matches'] ?? array()), 'Different semantic controls never share a component fingerprint.');

$svgResult = (new HtmlTransformer())->transform('<main><span class="icon"><svg><path d="M0 0h1v1z"/></svg></span><span class="icon"><svg><path d="M0 0h1v1z"/></svg></span></main>')->toArray();
$svgAssets = array_values(array_filter($svgResult['assets'] ?? array(), static fn(array $asset): bool => 'inline-svg' === ($asset['source'] ?? '')));
$assert(1 === count($svgAssets), 'Repeated passive SVG payloads emit one shared generated asset.');

$ariaResult = (new HtmlTransformer())->transform('<main><span class="icon"><svg aria-label="One"><path d="M0 0h1v1z"/></svg></span><span class="icon"><svg aria-label="Two"><path d="M0 0h1v1z"/></svg></span></main>')->toArray();
$ariaAssets = array_values(array_filter($ariaResult['assets'] ?? array(), static fn(array $asset): bool => 'inline-svg' === ($asset['source'] ?? '')));
$assert(1 === count($ariaAssets) && 'One' === ($ariaResult['blocks'][0]['innerBlocks'][0]['innerBlocks'][0]['attrs']['alt'] ?? '') && 'Two' === ($ariaResult['blocks'][0]['innerBlocks'][1]['innerBlocks'][0]['attrs']['alt'] ?? ''), 'Accessibility variants share the visual asset while preserving per-instance accessible names.');

$site = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<main><span class="icon"><svg><path d="M0 0h1v1z"/></svg></span></main>', 'about.html' => '<main><span class="icon"><svg><path d="M0 0h1v1z"/></svg></span></main>')))->toArray();
$siteComponents = $site['source_reports']['reusable_components']['components'] ?? array();
$siteAssets = array_values(array_filter($site['assets'] ?? array(), static fn(array $asset): bool => 'inline-svg' === ($asset['source'] ?? '')));
$siteVector = array_values(array_filter($siteComponents, static fn(array $component): bool => 'shared_core_image_asset' === ($component['mapping'] ?? '')));
$assert(1 === count($siteVector) && 2 === ($siteVector[0]['occurrence_count'] ?? 0) && 2 === count($siteAssets[0]['component_occurrences'] ?? array()), 'Cross-document vector repeats aggregate evidence, emit one asset, and retain both selectors.');

$nested = (new HtmlTransformer())->transform('<main><span class="icon"><svg><path d="M0 0h1v1z"/></svg></span></main>', array('source' => 'nested/about.html'))->toArray();
$nestedAsset = array_values(array_filter($nested['assets'] ?? array(), static fn(array $asset): bool => 'inline-svg' === ($asset['source'] ?? '')))[0] ?? array();
$assert('assets/materialized-svg/inline-svg-5b3525e620052139.svg' === ($nestedAsset['path'] ?? '') && str_contains((string) ($nested['serialized_blocks'] ?? ''), 'src="../assets/materialized-svg/inline-svg-5b3525e620052139.svg"'), 'Nested documents use a global generated asset identity and source-relative block URL.');

$deepNested = (new HtmlTransformer())->transform('<main><span class="icon"><svg><path d="M0 0h1v1z"/></svg></span></main>', array('source' => 'one/two/about.html'))->toArray();
$assert(str_contains((string) ($deepNested['serialized_blocks'] ?? ''), 'src="../../assets/materialized-svg/inline-svg-5b3525e620052139.svg"'), 'Multi-level nested documents calculate source-relative SVG URLs segment by segment.');

$artifactNested = (new HtmlTransformer())->transform('<main><span class="icon"><svg><path d="M0 0h1v1z"/></svg></span></main>', array('source' => 'website/nested/about.html', 'generated_asset_root' => 'website'))->toArray();
$artifactDeepNested = (new HtmlTransformer())->transform('<main><span class="icon"><svg><path d="M0 0h1v1z"/></svg></span></main>', array('source' => 'website/one/two/about.html', 'generated_asset_root' => 'website'))->toArray();
$assert(str_contains((string) ($artifactNested['serialized_blocks'] ?? ''), 'src="../assets/materialized-svg/inline-svg-5b3525e620052139.svg"') && str_contains((string) ($artifactDeepNested['serialized_blocks'] ?? ''), 'src="../../assets/materialized-svg/inline-svg-5b3525e620052139.svg"'), 'Artifact-root documents calculate relative SVG URLs from each nested source directory.');

$nine = (new HtmlTransformer())->transform('<main>' . str_repeat('<span class="icon"><svg><path d="M0 0h1v1z"/></svg></span>', 9) . '</main>')->toArray();
$nineAsset = array_values(array_filter($nine['assets'] ?? array(), static fn(array $asset): bool => 'inline-svg' === ($asset['source'] ?? '')))[0] ?? array();
$nineComponent = array_values(array_filter($nine['source_reports']['html']['reusable_components']['components'] ?? array(), static fn(array $component): bool => 'shared_core_image_asset' === ($component['mapping'] ?? '')))[0] ?? array();
$assert(9 === ($nineComponent['mapped_asset_occurrence_count'] ?? 0) && 8 === ($nineComponent['retained_occurrence_count'] ?? 0) && 1 === ($nineComponent['omitted_occurrence_count'] ?? 0) && true === ($nineComponent['incomplete'] ?? false) && 8 === count($nineAsset['component_occurrences'] ?? array()) && 1 === ($nineAsset['component_occurrences_omitted'] ?? 0), 'Mapped occurrence counts remain complete after bounded document provenance samples truncate.');

$eight = (new HtmlTransformer())->transform('<main>' . str_repeat('<span class="icon"><svg><path d="M0 0h1v1z"/></svg></span>', 8) . '</main>')->toArray();
$eightAsset = array_values(array_filter($eight['assets'] ?? array(), static fn(array $asset): bool => 'inline-svg' === ($asset['source'] ?? '')))[0] ?? array();
$assert(8 === count($eightAsset['component_occurrences'] ?? array()) && 0 === ($eightAsset['component_occurrences_omitted'] ?? -1), 'Eight retained SVG provenance rows report zero omissions.');

$nineSite = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<main>' . str_repeat('<span class="icon"><svg><path d="M0 0h1v1z"/></svg></span>', 9) . '</main>')))->toArray()['source_reports']['reusable_components']['components'] ?? array();
$nineSiteVector = array_values(array_filter($nineSite, static fn(array $component): bool => 'shared_core_image_asset' === ($component['mapping'] ?? '')))[0] ?? array();
$assert(9 === ($nineSiteVector['occurrence_count'] ?? 0) && 8 === ($nineSiteVector['retained_occurrence_count'] ?? 0) && 1 === ($nineSiteVector['omitted_occurrence_count'] ?? 0) && true === ($nineSiteVector['incomplete'] ?? false), 'Cross-document component evidence reports bounded occurrence samples as incomplete.');

$nearMatchHtml = static function (int $count): string {
    $html = '<main>';
    for ($index = 0; $index < $count; ++$index) $html .= '<section data-shape-' . $index . '="one" style="color:red"><p>One</p></section><section data-shape-' . $index . '="two" style="color:blue"><p>Two</p></section>';
    return $html . '</main>';
};
$sixteenNearMatches = $recognition($nearMatchHtml(16));
$seventeenNearMatches = $recognition($nearMatchHtml(17));
$assert(16 === ($sixteenNearMatches['retained_near_match_count'] ?? 0) && 0 === ($sixteenNearMatches['omitted_near_match_count'] ?? -1) && false === ($sixteenNearMatches['incomplete'] ?? true), 'Sixteen near matches remain complete at the report boundary.');
$assert(16 === ($seventeenNearMatches['retained_near_match_count'] ?? 0) && 1 === ($seventeenNearMatches['omitted_near_match_count'] ?? 0) && 'max_near_matches' === ($seventeenNearMatches['near_match_truncation_reason'] ?? '') && true === ($seventeenNearMatches['incomplete'] ?? false) && in_array('max_near_matches', $seventeenNearMatches['truncated'] ?? array(), true), 'Seventeen near matches report explicit bounded truncation.');

$ariaSite = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<main><span class="icon"><svg aria-label="One" title="One"><path d="M0 0h1v1z"/></svg></span></main>', 'about.html' => '<main><span class="icon"><svg aria-label="Two" title="Two"><path d="M0 0h1v1z"/></svg></span></main>')))->toArray();
$ariaSiteAssets = array_values(array_filter($ariaSite['assets'] ?? array(), static fn(array $asset): bool => 'inline-svg' === ($asset['source'] ?? '')));
$assert(1 === count($ariaSiteAssets) && !str_contains((string) ($ariaSiteAssets[0]['content'] ?? ''), 'aria-label') && !str_contains((string) ($ariaSiteAssets[0]['content'] ?? ''), '<title>'), 'Cross-document accessibility variants share one canonical visual SVG payload and path.');

$deep = '<main>' . str_repeat('<section>', 40) . 'x' . str_repeat('</section>', 40) . '</main>';
$limited = $recognition($deep);
$assert(in_array('max_depth', $limited['truncated'] ?? array(), true), 'Recognition reports depth budget truncation rather than unbounded traversal.');

$wide = '<main>' . str_repeat('<section><p>x</p></section>', 300) . '</main>';
$wideLimited = $recognition($wide);
$assert(in_array('max_candidates', $wideLimited['truncated'] ?? array(), true), 'Recognition reports candidate budget truncation for wide repeated input.');

$incompleteSite = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<main>Entry</main>', 'about.html' => $wide)))->toArray()['source_reports']['reusable_components'] ?? array();
$assert(true === ($incompleteSite['incomplete'] ?? false) && in_array('max_candidates', $incompleteSite['truncated'] ?? array(), true) && 0 < ($incompleteSite['omitted_candidate_count'] ?? 0), 'Site-level evidence remains explicitly incomplete when a document reaches its candidate budget.');

$componentMarkup = '<main>';
for ($index = 0; $index < 33; ++$index) $componentMarkup .= '<section class="component-' . $index . '"><p>One</p></section><section class="component-' . $index . '"><p>Two</p></section>';
$componentMarkup .= '</main>';
$componentBound = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $componentMarkup)))->toArray()['source_reports']['reusable_components'] ?? array();
$assert(32 === ($componentBound['retained_component_count'] ?? 0) && 0 < ($componentBound['omitted_component_count'] ?? 0) && in_array('max_components', $componentBound['truncated'] ?? array(), true) && true === ($componentBound['incomplete'] ?? false), 'Site reports expose component cap omissions and incomplete evidence.');

$documentFiles = array('index.html' => '<main>Entry</main>');
for ($index = 0; $index < 64; ++$index) $documentFiles['document-' . $index . '.html'] = '<main>Document</main>';
$documentBound = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $documentFiles))->toArray()['source_reports']['reusable_components'] ?? array();
$assert(64 === ($documentBound['retained_document_count'] ?? 0) && 1 === ($documentBound['omitted_document_count'] ?? 0) && in_array('max_documents', $documentBound['truncated'] ?? array(), true) && true === ($documentBound['incomplete'] ?? false), 'Site reports expose document cap omissions and incomplete evidence.');

$largeAttribute = $recognition('<main><section data-payload="' . str_repeat('x', 9000) . '"><p>x</p></section></main>');
$assert(in_array('max_signature_bytes', $largeAttribute['truncated'] ?? array(), true), 'Recognition reports signature-byte truncation for oversized attributes.');

fwrite(STDOUT, "reusable component recognition passed\n");

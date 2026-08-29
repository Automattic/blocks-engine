<?php
declare(strict_types=1);

/**
 * Unit tests for the custom-block generator (issue #497).
 *
 * Plain-PHP test script in the style of tests/unit/subtree-classifier.php — no
 * PHPUnit. Covers the pure {@see CustomBlockGenerator} definition shape plus the
 * conservative generation gate / content-sensitive dedup wired through
 * {@see FallbackEmitter} and the {@see HtmlTransformer} core/html fallback path.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\CustomBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes   = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

// ---------------------------------------------------------------------------
// 1. Pure generator: block.json + static render + reference-attr shape.
// ---------------------------------------------------------------------------
$generator = new CustomBlockGenerator();
$blockJson = $generator->blockJson('ssi-acme/collection-1234abcd', 'Custom Collection');

$assert(($blockJson['apiVersion'] ?? null) === 3, '1: block.json declares apiVersion 3');
$assert(($blockJson['name'] ?? null) === 'ssi-acme/collection-1234abcd', '1: block.json carries the fully-qualified name');
$assert(($blockJson['render'] ?? null) === 'file:./render.php', '1: block.json points render at render.php');
$assert(($blockJson['editorScript'] ?? null) === 'file:./index.js', '1: block.json declares the generated editor script');
$assert(isset($blockJson['attributes']['content']['type']) && 'string' === $blockJson['attributes']['content']['type'], '1: content attribute is a string');
$assert(($blockJson['supports']['html'] ?? null) === false, '1: generated block disables raw-HTML support');

$render = $generator->render('<p>hello</p>');
$assert('<p>hello</p>' === $render, '2: render is the supplied sanitized static HTML');
$assert(! str_contains($render, '<?'), '2: render contains no server code');
$editorScript = $generator->assets('ssi-acme/collection-1234abcd')['index.js'] ?? '';
$assert(str_contains($editorScript, "registerBlockType( 'ssi-acme/collection-1234abcd'") && str_contains($editorScript, 'TextareaControl'), '2: editor asset registers the exact block with editable source content');

$refAttrs = $generator->referenceAttributes('<p>hello</p>');
$assert($refAttrs === array('content' => '<p>hello</p>'), '3: reference carries per-instance content only');

// ---------------------------------------------------------------------------
// 4. Gate (positive): high-confidence custom_block subtree -> generated ref.
// ---------------------------------------------------------------------------
$tiles = '<my-pricing>'
    . '<div class="tier"><h3>Basic</h3><p>$9</p></div>'
    . '<div class="tier"><h3>Pro</h3><p>$19</p></div>'
    . '<div class="tier"><h3>Max</h3><p>$49</p></div>'
    . '</my-pricing>';

$result = ( new HtmlTransformer() )->transform($tiles, array('generated_block_namespace' => 'ssi-acme'))->toArray();
$generated = $result['source_reports']['generated_blocks'] ?? array();

$assert('success' === ($result['status'] ?? ''), '4: qualifying transform succeeds');
$assert(count($result['blocks']) === 1, '4: one block emitted', json_encode($result['blocks']));
$assert(str_starts_with($result['blocks'][0]['blockName'] ?? '', 'ssi-acme/collection-'), '4: emits a namespaced generated block reference', $result['blocks'][0]['blockName'] ?? '');
$assert('' === ($result['blocks'][0]['innerHTML'] ?? 'x'), '4: reference is self-closing (no innerHTML)');
$assert(count($result['fallbacks']) === 0, '4: no core/html fallback emitted for the generated subtree');
$assert(count($generated) === 1, '4: one generated block type recorded');
$assert(($generated[0]['render'] ?? null) === ($result['blocks'][0]['attrs']['content'] ?? null), '4: generated type carries the reference sanitized content as static render');
$assert(isset($generated[0]['assets']['index.js']) && array('wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element') === ($generated[0]['script_dependencies']['index.js'] ?? null), '4: generated definition carries its editor asset and WordPress dependencies');

// ---------------------------------------------------------------------------
// 5. Dedup: identical sanitized content -> one type, two references.
// ---------------------------------------------------------------------------
$dedup = ( new HtmlTransformer() )->transform($tiles . $tiles)->toArray();
$assert(count($dedup['blocks']) === 2, '5: two references emitted for two identical subtrees');
$assert(($dedup['blocks'][0]['blockName'] ?? '') === ($dedup['blocks'][1]['blockName'] ?? ''), '5: both references point to the same generated type');
$assert(count($dedup['source_reports']['generated_blocks'] ?? array()) === 1, '5: only ONE block type is generated (no zoo)');

$differentTiles = str_replace(array('Basic', '$9', 'Pro', '$19', 'Max', '$49'), array('Team', '$99', 'Scale', '$199', 'Enterprise', '$499'), $tiles);
$distinct = ( new HtmlTransformer() )->transform($tiles . $differentTiles)->toArray();
$distinctGenerated = array_column($distinct['source_reports']['generated_blocks'] ?? array(), null, 'name');
$firstName = substr((string) ($distinct['blocks'][0]['blockName'] ?? ''), strlen('custom/'));
$secondName = substr((string) ($distinct['blocks'][1]['blockName'] ?? ''), strlen('custom/'));
$assert($firstName !== $secondName, '5: same-structure references with different sanitized content have distinct deterministic identities');
$assert(($distinctGenerated[$firstName]['render'] ?? null) === ($distinct['blocks'][0]['attrs']['content'] ?? null), '5: first reference resolves to its exact sanitized static render');
$assert(($distinctGenerated[$secondName]['render'] ?? null) === ($distinct['blocks'][1]['attrs']['content'] ?? null), '5: second reference resolves to its exact sanitized static render');
$assert(! str_contains((string) ($distinctGenerated[$firstName]['render'] ?? ''), '<?') && ! str_contains((string) ($distinctGenerated[$secondName]['render'] ?? ''), '<?'), '5: distinct generated renders remain static HTML');

$galleries = '<my-gallery><div><img src="one.jpg" alt="One" onerror="steal()"></div><div><img src="javascript:steal()" alt="Two"></div><script>steal()</script></my-gallery>'
    . '<my-gallery><div><img src="three.jpg" alt="Three"></div><div><img src="four.jpg" alt="Four"></div></my-gallery>';
$galleryResult = ( new HtmlTransformer() )->transform($galleries)->toArray();
$galleryDefinitions = array_column($galleryResult['source_reports']['generated_blocks'] ?? array(), null, 'name');
foreach ( $galleryResult['blocks'] as $galleryReference ) {
    $galleryName = substr((string) ($galleryReference['blockName'] ?? ''), strlen('custom/'));
    $galleryDefinition = $galleryDefinitions[$galleryName] ?? array();
    $assert('Custom Gallery' === ($galleryDefinition['block_json']['title'] ?? null), '5: generated gallery retains gallery semantics');
    $assert(($galleryReference['attrs']['content'] ?? null) === ($galleryDefinition['render'] ?? null), '5: each gallery reference resolves to its exact sanitized static render');
}
$galleryRenders = array_column($galleryDefinitions, 'render');
$assert(2 === count($galleryDefinitions) && 2 === count(array_unique(array_keys($galleryDefinitions))), '5: same-structure galleries with different content have distinct definitions');
$assert(! str_contains(implode('', $galleryRenders), '<script') && ! str_contains(implode('', $galleryRenders), 'onerror=') && ! str_contains(implode('', $galleryRenders), 'javascript:'), '5: gallery static renders preserve the existing security policy');
$galleryRepeat = ( new HtmlTransformer() )->transform($galleries)->toArray();
$assert(array_column($galleryResult['blocks'], 'blockName') === array_column($galleryRepeat['blocks'], 'blockName'), '5: gallery content-bound identities are deterministic across runs');

// ---------------------------------------------------------------------------
// 6. Deep repeatable components use one bounded companion block after native
//    recognizers decline, without capturing ordinary page shells.
// ---------------------------------------------------------------------------
$collection = '<div class="story-collection">'
    . '<article><h3>One</h3><p>First story</p></article>'
    . '<article><h3>Two</h3><p>Second story</p></article>'
    . '<article><h3>Three</h3><p>Third story</p></article>'
    . '</div>';
$deepCollection = $collection;
for ($depth = 0; $depth < 16; ++$depth) $deepCollection = '<div class="shell-' . $depth . '">' . $deepCollection . '</div>';
$deepResult = ( new HtmlTransformer() )->transform($deepCollection)->toArray();
$deepDefinitions = $deepResult['source_reports']['generated_blocks'] ?? array();
$deepMarkup = (string) ($deepResult['serialized_blocks'] ?? '');
$assert(1 === count($deepDefinitions), '6: one deep repeatable component produces one generated definition');
$assert(str_contains($deepMarkup, '<!-- wp:custom/collection-') && str_contains((string) ($deepDefinitions[0]['render'] ?? ''), '<div class="story-collection">') && !str_contains((string) ($deepDefinitions[0]['render'] ?? ''), 'shell-15'), '6: capture starts at the cohesive component root rather than the surrounding page shell');
$assert(20 >= ($deepResult['source_reports']['editability_report']['metrics']['max_nesting_depth'] ?? PHP_INT_MAX), '6: generated component keeps the resulting List View depth within policy');

$tagResetResult = ( new HtmlTransformer() )->transform('<style>p{margin:0}</style>' . $deepCollection)->toArray();
$tagResetDefinitions = $tagResetResult['source_reports']['generated_blocks'] ?? array();
$tagResetCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $tagResetResult['assets'] ?? array()));
$assert(str_contains((string) ($tagResetDefinitions[0]['render'] ?? ''), '<p class="blocks-engine-source-p-') && str_contains($tagResetCss, ':where(.blocks-engine-source-p-') && str_contains($tagResetCss, '{margin:0}'), '6: generated component descendants retain source tag projection markers');

$shallowResult = ( new HtmlTransformer() )->transform('<div><div>' . $collection . '</div></div>')->toArray();
$assert(array() === ($shallowResult['source_reports']['generated_blocks'] ?? array()), '6: shallow repeatable content remains native blocks');

$deepSection = str_replace('<div class="story-collection">', '<section class="story-collection">', str_replace('</div>', '</section>', $collection));
for ($depth = 0; $depth < 16; ++$depth) $deepSection = '<div>' . $deepSection . '</div>';
$sectionResult = ( new HtmlTransformer() )->transform($deepSection)->toArray();
$assert(array() === ($sectionResult['source_reports']['generated_blocks'] ?? array()), '6: semantic sections are not captured as opaque generated components');

$unsafeCollection = str_replace('class="story-collection"', 'class="story-collection" onclick="selectStory()"', $deepCollection);
$unsafeResult = ( new HtmlTransformer() )->transform($unsafeCollection)->toArray();
$assert(array() === ($unsafeResult['source_reports']['generated_blocks'] ?? array()), '6: inline behavior is never removed through static generated-component capture');

$customHost = '<fluid-card-grid><div><article><h3>One</h3></article><article><h3>Two</h3></article><article><h3>Three</h3></article></div></fluid-card-grid>';
for ($depth = 0; $depth < 16; ++$depth) $customHost = '<div>' . $customHost . '</div>';
$customHostResult = ( new HtmlTransformer() )->transform($customHost)->toArray();
$customHostDefinitions = array_values(array_filter($customHostResult['source_reports']['generated_blocks'] ?? array(), static fn (array $definition): bool => str_contains((string) ($definition['render'] ?? ''), '<fluid-card-grid>')));
$assert(1 === count($customHostDefinitions), '6: a deep safe custom-element host becomes one exact companion block');

$unsafeHost = str_replace('<div>', '<div><input value="unsafe">', $customHost);
$unsafeHostResult = ( new HtmlTransformer() )->transform($unsafeHost)->toArray();
$assert(array() === array_values(array_filter($unsafeHostResult['source_reports']['generated_blocks'] ?? array(), static fn (array $definition): bool => str_contains((string) ($definition['render'] ?? ''), '<fluid-card-grid>'))), '6: custom-element hosts with data-entry controls stay on application-aware conversion paths');

$unsafeUrls = '<object data="javascript:alert(1)"></object><video poster="vbscript:alert(1)"></video><a href="%6a%61vascript:alert(1)" formaction="data:text/html,unsafe">Unsafe</a><img src="safe.jpg" srcset="safe.jpg 1x, javascript:alert(1) 2x"><meta http-equiv="refresh" content="0; url=data:text/html,unsafe">';
$unsafeDeepCollection = preg_replace('/<article>/', '<article>' . $unsafeUrls, $deepCollection, 1) ?? $deepCollection;
$unsafeUrlResult = ( new HtmlTransformer() )->transform($unsafeDeepCollection)->toArray();
$unsafeRenders = implode('', array_map(static fn (array $definition): string => (string) ($definition['render'] ?? ''), $unsafeUrlResult['source_reports']['generated_blocks'] ?? array()));
$assert(!str_contains(strtolower($unsafeRenders), 'javascript:') && !str_contains(strtolower($unsafeRenders), 'vbscript:') && !str_contains(strtolower($unsafeRenders), 'data:text') && str_contains($unsafeRenders, 'src="safe.jpg"'), '6: generated component HTML uses the complete fallback URL sanitization policy');

$runtimeDeepCollection = preg_replace('/<article>/', '<article id="runtime-card">', $deepCollection, 1) ?? $deepCollection;
$runtimeComponent = ( new HtmlTransformer() )->transform($runtimeDeepCollection, array('runtime_dom_selectors' => array('#runtime-card')))->toArray();
$runtimeContracts = $runtimeComponent['source_reports']['runtime_dom_contracts'] ?? array();
$runtimeProvenance = $runtimeComponent['source_reports']['html']['source_provenance'] ?? array();
$assert(in_array('#runtime-card', array_column($runtimeContracts, 'selector'), true) && array_filter($runtimeProvenance, static fn (array $entry): bool => !empty($entry['editability_runtime_owned']) && str_starts_with((string) ($entry['block_name'] ?? ''), 'custom/')), '6: descendant runtime selectors remain attributed to the generated component block');

$nativeColumns = '<div class="wp-block-columns"><div class="wp-block-column"><p>One</p></div><div class="wp-block-column"><p>Two</p></div><div class="wp-block-column"><p>Three</p></div></div>';
for ($depth = 0; $depth < 16; ++$depth) $nativeColumns = '<div>' . $nativeColumns . '</div>';
$nativeColumnsResult = ( new HtmlTransformer() )->transform($nativeColumns)->toArray();
$assert(array() === ($nativeColumnsResult['source_reports']['generated_blocks'] ?? array()) && str_contains((string) ($nativeColumnsResult['serialized_blocks'] ?? ''), '<!-- wp:columns'), '6: native recognizers take precedence over generated component capture');

$projectedChain = '<p>Editable terminal content</p>';
for ($depth = 0; $depth < 8; ++$depth) $projectedChain = '<div id="shell-' . $depth . '" class="shell-' . $depth . ' blocks-engine-source-div-fixture-3">' . $projectedChain . '</div>';
$shellResult = ( new HtmlTransformer() )->transform($projectedChain)->toArray();
$shellBlock = $shellResult['blocks'][0] ?? array();
$shellDefinitions = array_values(array_filter($shellResult['source_reports']['generated_blocks'] ?? array(), static fn (array $definition): bool => 'Layout Shell' === ($definition['block_json']['title'] ?? null)));
$assert(str_ends_with((string) ($shellBlock['blockName'] ?? ''), '/layout-shell') && 8 === count($shellBlock['attrs']['wrappers'] ?? array()) && 'core/paragraph' === ($shellBlock['innerBlocks'][0]['blockName'] ?? null), '6: a projected wrapper chain becomes one layout-shell block around native editable content');
$shellScript = (string) ($shellDefinitions[0]['assets']['index.js'] ?? '');
$assert(1 === count($shellDefinitions) && str_contains($shellScript, 'InnerBlocks.Content'), '6: layout-shell emits one companion definition whose save path retains native inner blocks');
$assert(str_contains($shellScript, 'function wrappedContent( wrappers, content, outerProps )') && str_contains($shellScript, 'props = outerProps( props )') && str_contains($shellScript, 'wrappedContent( wrappers, content, useBlockProps )') && str_contains($shellScript, 'edit: edit,'), '6: layout-shell edit preserves the save wrapper chain and merges block props onto its outermost wrapper');
$assert(str_contains($shellScript, "wrappers.length ? wrappedContent( wrappers, content, useBlockProps ) : createElement( 'div', useBlockProps(), content )"), '6: layout-shell edit retains an editor wrapper for empty source chains');
$assert(2 === ($shellResult['source_reports']['editability_report']['metrics']['max_nesting_depth'] ?? PHP_INT_MAX) && 8 === substr_count((string) ($shellResult['serialized_blocks'] ?? ''), 'id="shell-'), '6: layout-shell collapses List View depth while preserving every rendered source wrapper');

$emptyShellResult = ( new HtmlTransformer() )->transform('<div id="empty-outer" class="blocks-engine-source-div-outer-3"><div id="empty-inner" class="blocks-engine-source-div-inner-3"></div></div>')->toArray();
$emptyShellBlock = $emptyShellResult['blocks'][0] ?? array();
$assert(str_ends_with((string) ($emptyShellBlock['blockName'] ?? ''), '/layout-shell') && 2 === count($emptyShellBlock['attrs']['wrappers'] ?? array()) && empty($emptyShellBlock['innerBlocks']), '6: layout-shell absorbs a projected empty Group endpoint without adding List View depth');
$assert(1 === ($emptyShellResult['source_reports']['editability_report']['metrics']['max_nesting_depth'] ?? PHP_INT_MAX) && str_contains((string) ($emptyShellResult['serialized_blocks'] ?? ''), 'id="empty-outer"') && str_contains((string) ($emptyShellResult['serialized_blocks'] ?? ''), 'id="empty-inner"'), '6: empty layout-shell serialization preserves both source wrappers exactly');

$branchShell = ( new HtmlTransformer() )->transform('<div id="branch-outer" class="blocks-engine-source-div-outer-3"><div id="branch-inner" class="blocks-engine-source-div-fixture-3"><section id="branch-section" class="blocks-engine-source-section-fixture-3"><p>First branch</p><p>Second branch</p></section></div></div>')->toArray();
$branchBlock = $branchShell['blocks'][0] ?? array();
$assert(str_ends_with((string) ($branchBlock['blockName'] ?? ''), '/layout-shell') && 3 === count($branchBlock['attrs']['wrappers'] ?? array()) && 2 === count($branchBlock['innerBlocks'] ?? array()), '6: layout-shell absorbs a final branching Group and exposes all ordered native children through InnerBlocks');
$branchMarkup = (string) ($branchShell['serialized_blocks'] ?? '');
$assert(str_contains($branchMarkup, '<section id="branch-section"') && 2 === substr_count($branchMarkup, '<!-- wp:paragraph') && strpos($branchMarkup, 'First branch') < strpos($branchMarkup, 'Second branch'), '6: branching layout-shell serialization preserves semantic wrappers and ordered native child blocks');

$depthPressureTransformer = new HtmlTransformer();
$twoWrapperBranch = $depthPressureTransformer->transform('<div id="depth-outer" class="blocks-engine-source-div-outer-3"><section id="depth-branch" class="blocks-engine-source-section-branch-3"><p>First branch</p><p>Second branch</p></section></div>')->toArray();
$depthCompressor = new ReflectionMethod(HtmlTransformer::class, 'compressProjectedGroupChains');
$depthCompressed = $depthCompressor->invoke($depthPressureTransformer, $twoWrapperBranch['blocks'] ?? array(), true);
$assert('core/group' === ($twoWrapperBranch['blocks'][0]['blockName'] ?? null) && str_ends_with((string) ($depthCompressed[0]['blockName'] ?? ''), '/layout-shell') && 2 === count($depthCompressed[0]['attrs']['wrappers'] ?? array()) && 2 === count($depthCompressed[0]['innerBlocks'] ?? array()), '6: depth pressure admits an exact two-wrapper branch shell while the normal threshold remains conservative');

$importantShell = ( new HtmlTransformer() )->transform('<div class="blocks-engine-source-div-outer-3" style="color:red ! important"><div class="blocks-engine-source-div-fixture-3"><p>Priority-sensitive content</p></div></div>')->toArray();
$assert(!str_ends_with((string) ($importantShell['blocks'][0]['blockName'] ?? ''), '/layout-shell'), '6: layout-shell does not rewrite wrapper chains carrying whitespace-variant !important declarations');

$styledShell = ( new HtmlTransformer() )->transform('<div id="styled-outer" class="blocks-engine-source-div-outer-3" style="margin-top:0"><div id="styled-inner" class="blocks-engine-source-div-fixture-3"><p>Style-sensitive content</p></div></div>')->toArray();
$styledShellBlock = $styledShell['blocks'][0] ?? array();
$styledShellDefinitions = array_values(array_filter($styledShell['source_reports']['generated_blocks'] ?? array(), static fn (array $definition): bool => 'Layout Shell' === ($definition['block_json']['title'] ?? null)));
$styledShellScript = (string) ($styledShellDefinitions[0]['assets']['index.js'] ?? '');
$assert(str_ends_with((string) ($styledShellBlock['blockName'] ?? ''), '/layout-shell') && 'margin-top:0' === ($styledShellBlock['attrs']['wrappers'][0]['attributes']['style'] ?? null), '6: layout-shell compresses canonical styled wrappers without changing their serialized declarations');
$assert(str_contains((string) ($styledShell['serialized_blocks'] ?? ''), 'style="margin-top:0"') && str_contains($styledShellScript, 'appendDeclaration( declaration )'), '6: layout-shell preserves unitless zero declarations through its React save path');

$normalizedStyleShell = ( new HtmlTransformer() )->transform('<div id="color-outer" class="blocks-engine-source-div-outer-3" style="color:#fff"><div id="color-inner" class="blocks-engine-source-div-fixture-3"><p>Color-sensitive content</p></div></div>')->toArray();
$normalizedStyleBlock = $normalizedStyleShell['blocks'][0] ?? array();
$assert(str_ends_with((string) ($normalizedStyleBlock['blockName'] ?? ''), '/layout-shell') && 'color:#fff' === ($normalizedStyleBlock['attrs']['wrappers'][0]['attributes']['style'] ?? null), '6: layout-shell bypasses CSSOM normalization and retains canonical color declarations');

// ---------------------------------------------------------------------------
// 7. Gate (negative): weak signals stay UNKNOWN -> unchanged fallback.
// ---------------------------------------------------------------------------
$weak = ( new HtmlTransformer() )->transform('<my-widget><span>hello there</span></my-widget>')->toArray();
$assert(count($weak['source_reports']['generated_blocks'] ?? array()) === 0, '7: low-confidence subtree generates nothing');
$assert(count($weak['blocks']) === 0, '7: low-confidence subtree emits no block');
$assert(count($weak['fallbacks']) === 1, '7: existing fallback behavior is preserved');
$assert(($weak['fallbacks'][0]['classification']['bucket'] ?? '') === 'unknown', '7: classifier verdict is unknown', json_encode($weak['fallbacks'][0]['classification'] ?? array()));

if ( $failures > 0 ) {
    fwrite(STDERR, PHP_EOL . "CustomBlockGenerator unit tests: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "CustomBlockGenerator unit tests: {$passes} passed" . PHP_EOL);

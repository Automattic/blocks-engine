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

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\CustomBlockGenerator;
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
$assert(isset($blockJson['attributes']['content']['type']) && 'string' === $blockJson['attributes']['content']['type'], '1: content attribute is a string');
$assert(($blockJson['supports']['html'] ?? null) === false, '1: generated block disables raw-HTML support');

$render = $generator->render('<p>hello</p>');
$assert('<p>hello</p>' === $render, '2: render is the supplied sanitized static HTML');
$assert(! str_contains($render, '<?'), '2: render contains no server code');

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
// 6. Gate (negative): weak signals stay UNKNOWN -> unchanged fallback.
// ---------------------------------------------------------------------------
$weak = ( new HtmlTransformer() )->transform('<my-widget><span>hello there</span></my-widget>')->toArray();
$assert(count($weak['source_reports']['generated_blocks'] ?? array()) === 0, '6: low-confidence subtree generates nothing');
$assert(count($weak['blocks']) === 0, '6: low-confidence subtree emits no block');
$assert(count($weak['fallbacks']) === 1, '6: existing fallback behavior is preserved');
$assert(($weak['fallbacks'][0]['classification']['bucket'] ?? '') === 'unknown', '6: classifier verdict is unknown', json_encode($weak['fallbacks'][0]['classification'] ?? array()));

if ( $failures > 0 ) {
    fwrite(STDERR, PHP_EOL . "CustomBlockGenerator unit tests: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "CustomBlockGenerator unit tests: {$passes} passed" . PHP_EOL);

<?php
declare(strict_types=1);

/**
 * Unit tests for the custom-block generator (issue #497).
 *
 * Plain-PHP test script in the style of tests/unit/subtree-classifier.php — no
 * PHPUnit. Covers the pure {@see CustomBlockGenerator} definition shape plus the
 * conservative generation gate / structural-signature dedup wired through
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
// 1. Pure generator: dynamic block.json + render + reference-attr shape.
// ---------------------------------------------------------------------------
$generator = new CustomBlockGenerator();
$blockJson = $generator->blockJson('ssi-acme/collection-1234abcd', 'Custom Collection');

$assert(($blockJson['apiVersion'] ?? null) === 3, '1: block.json declares apiVersion 3');
$assert(($blockJson['name'] ?? null) === 'ssi-acme/collection-1234abcd', '1: block.json carries the fully-qualified name');
$assert(($blockJson['render'] ?? null) === 'file:./render.php', '1: dynamic block.json points render at render.php');
$assert(isset($blockJson['attributes']['content']['type']) && 'string' === $blockJson['attributes']['content']['type'], '1: content attribute is a string');
$assert(($blockJson['supports']['html'] ?? null) === false, '1: generated block disables raw-HTML support');

$render = $generator->render();
$assert(str_starts_with($render, '<?php'), '2: render is a PHP file body');
$assert(str_contains($render, "\$attributes['content']"), '2: render reads the content attribute');
$assert(str_contains($render, 'wp_kses_post'), '2: render sanitizes content on output');

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
$assert(($result['blocks'][0]['blockName'] ?? '') === 'ssi-acme/collection-623e0f92', '4: emits a namespaced dynamic block reference', $result['blocks'][0]['blockName'] ?? '');
$assert('' === ($result['blocks'][0]['innerHTML'] ?? 'x'), '4: reference is self-closing (no innerHTML)');
$assert(count($result['fallbacks']) === 0, '4: no core/html fallback emitted for the generated subtree');
$assert(count($generated) === 1, '4: one generated block type recorded');
$assert(($generated[0]['name'] ?? '') === 'collection-623e0f92', '4: generated type name is structurally derived (generic)');

// ---------------------------------------------------------------------------
// 5. Dedup: two identical shapes -> one type, two references.
// ---------------------------------------------------------------------------
$dedup = ( new HtmlTransformer() )->transform($tiles . $tiles)->toArray();
$assert(count($dedup['blocks']) === 2, '5: two references emitted for two identical subtrees');
$assert(($dedup['blocks'][0]['blockName'] ?? '') === ($dedup['blocks'][1]['blockName'] ?? ''), '5: both references point to the same generated type');
$assert(count($dedup['source_reports']['generated_blocks'] ?? array()) === 1, '5: only ONE block type is generated (no zoo)');

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

<?php
declare(strict_types=1);

/**
 * Unit tests for the parenthesis-depth-aware CSS value splitter and the
 * StyleAttributeMapper validity guard that depends on it.
 *
 * Plain-PHP test script in the style of tests/unit/subtree-classifier.php — no
 * PHPUnit. The splitter must only treat `;`, `,`, and whitespace as delimiters
 * at paren depth 0 so functional notation (rgba(), clamp(), var(), gradients)
 * stays whole. The mapper must never store a truncated/unbalanced functional
 * value, so a block never carries a has-* support class without a matching,
 * renderable style.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleAttributeMapper;

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
// 1. Top-level comma split keeps commas inside rgba()/clamp()/var() intact.
// ---------------------------------------------------------------------------
$assert(
    CssValueSplitter::splitTopLevel('rgba(251, 247, 241, .95)', array( ',' )) === array( 'rgba(251, 247, 241, .95)' ),
    '1: rgba() is not split on its internal commas'
);
$assert(
    CssValueSplitter::splitTopLevel('clamp(3.5rem, 8vw, 6.5rem), 0', array( ',' )) === array( 'clamp(3.5rem, 8vw, 6.5rem)', '0' ),
    '1b: only the top-level comma after clamp() splits'
);
$assert(
    CssValueSplitter::splitTopLevel('var(--x, 0), var(--y, 1)', array( ',' )) === array( 'var(--x, 0)', 'var(--y, 1)' ),
    '1c: nested var() defaults stay whole, top-level comma splits'
);

// ---------------------------------------------------------------------------
// 2. Top-level whitespace split keeps function-internal spaces intact — the
//    box-shorthand expansion bug (`clamp(3.5rem, 8vw, 6.5rem) 0`).
// ---------------------------------------------------------------------------
$assert(
    CssValueSplitter::splitTopLevelWhitespace('clamp(3.5rem, 8vw, 6.5rem) 0') === array( 'clamp(3.5rem, 8vw, 6.5rem)', '0' ),
    '2: padding shorthand splits into clamp() + 0, clamp() stays whole'
);
$assert(
    CssValueSplitter::splitTopLevelWhitespace('1px solid rgba(0, 0, 0, .1)') === array( '1px', 'solid', 'rgba(0, 0, 0, .1)' ),
    '2b: border shorthand keeps rgba() whole'
);
$assert(
    CssValueSplitter::splitTopLevelWhitespace('  10px   20px  ') === array( '10px', '20px' ),
    '2c: collapses leading/trailing/repeated whitespace'
);

// ---------------------------------------------------------------------------
// 3. Top-level declaration split keeps `;` inside functions intact.
// ---------------------------------------------------------------------------
$assert(
    CssValueSplitter::splitTopLevel('color: red; background: rgba(1, 2, 3, .4)', array( ';' )) === array( 'color: red', 'background: rgba(1, 2, 3, .4)' ),
    '3: declaration list splits on top-level semicolons only'
);

// ---------------------------------------------------------------------------
// 4. Balanced-paren detection (validity guard primitive).
// ---------------------------------------------------------------------------
$assert(CssValueSplitter::hasBalancedParens('rgba(251, 247, 241, .95)'), '4: complete rgba() is balanced');
$assert(! CssValueSplitter::hasBalancedParens('rgba(251,'), '4b: truncated rgba() is unbalanced');
$assert(! CssValueSplitter::hasBalancedParens('foo)bar'), '4c: stray close paren is unbalanced');
$assert(CssValueSplitter::hasBalancedParens('linear-gradient(90deg, rgba(0,0,0,.5), #fff)'), '4d: nested functions are balanced');

// ---------------------------------------------------------------------------
// 5. Mapper: function values survive end-to-end and stay whole.
// ---------------------------------------------------------------------------
$mapper = new StyleAttributeMapper();
$mapped = $mapper->map(array(
    'background' => 'rgba(251, 247, 241, .95)',
    'padding'    => 'clamp(3.5rem, 8vw, 6.5rem) 0',
    'color'      => 'var(--accent, #c4a35a)',
));
$assert(($mapped['style']['color']['background'] ?? '') === 'rgba(251, 247, 241, .95)', '5: rgba() background preserved whole');
$assert(($mapped['style']['color']['text'] ?? '') === 'var(--accent, #c4a35a)', '5b: var() color preserved whole');
$padding = $mapped['style']['spacing']['padding'] ?? array();
$assert(
    ($padding['top'] ?? '') === 'clamp(3.5rem, 8vw, 6.5rem)' && ($padding['right'] ?? '') === '0'
        && ($padding['bottom'] ?? '') === 'clamp(3.5rem, 8vw, 6.5rem)' && ($padding['left'] ?? '') === '0',
    '5c: clamp()/0 two-value shorthand maps to correct sides',
    json_encode($padding)
);

// ---------------------------------------------------------------------------
// 6. Validity guard: a genuinely invalid/truncated value is dropped, and the
//    has-* class is NEVER emitted without a matching renderable style.
// ---------------------------------------------------------------------------
$invalid = $mapper->map(array( 'background' => 'rgba(251,' ));
$assert(! isset($invalid['style']['color']['background']), '6: truncated background is not stored');
$serialized = $mapper->serialize($invalid['style']);
$assert(! str_contains($serialized['classes'], 'has-background'), '6b: no has-background class without a valid style');
$assert('' === $serialized['style'], '6c: no inline style emitted for the invalid value');

// A valid value still pairs class + declaration.
$valid = $mapper->serialize($mapper->map(array( 'background' => 'rgba(1, 2, 3, .4)' ))['style']);
$assert(str_contains($valid['classes'], 'has-background') && str_contains($valid['style'], 'background-color:rgba(1, 2, 3, .4)'), '6d: valid background pairs class + style');

if ( $failures > 0 ) {
    fwrite(STDERR, "CssValueSplitter unit tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "CssValueSplitter unit tests: {$passes} passed\n");

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

use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\LayoutParticipation;
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
$assert(
    CssValueSplitter::splitTopLevel('content: "a;b"; color: red', array( ';' )) === array( 'content: "a;b"', 'color: red' ),
    '3b: quoted semicolons stay in their declaration'
);
$assert(
    CssValueSplitter::splitTopLevel('"A,B", serif', array( ',' )) === array( '"A,B"', 'serif' ),
    '3c: quoted commas stay in their value'
);
$assert(
    CssValueSplitter::splitTopLevel('foo\\,bar,baz', array( ',' )) === array( 'foo\\,bar', 'baz' ),
    '3d: escaped delimiters stay in their value'
);
$assert(
    CssValueSplitter::splitTopLevel('"A\\", B", serif', array( ',' )) === array( '"A\\", B"', 'serif' ),
    '3e: escaped quotes do not end a quoted value'
);
$assert(
    CssValueSplitter::splitTopLevelWhitespace('"A B" serif') === array( '"A B"', 'serif' ),
    '3f: quoted whitespace stays in its value'
);
$assert(
    CssValueSplitter::splitTopLevelWhitespace('A\\ B serif') === array( 'A\\ B', 'serif' ),
    '3g: escaped whitespace stays in its value'
);

// ---------------------------------------------------------------------------
// 4. Balanced-paren detection (validity guard primitive).
// ---------------------------------------------------------------------------
$assert(CssValueSplitter::hasBalancedParens('rgba(251, 247, 241, .95)'), '4: complete rgba() is balanced');
$assert(! CssValueSplitter::hasBalancedParens('rgba(251,'), '4b: truncated rgba() is unbalanced');
$assert(! CssValueSplitter::hasBalancedParens('foo)bar'), '4c: stray close paren is unbalanced');
$assert(CssValueSplitter::hasBalancedParens('linear-gradient(90deg, rgba(0,0,0,.5), #fff)'), '4d: nested functions are balanced');
$assert(CssValueSplitter::hasBalancedParens('url("data:image/svg+xml,<svg>)</svg>")'), '4e: quoted parentheses do not affect balance');
$assert(CssValueSplitter::hasBalancedParens('foo\\)bar'), '4f: escaped parentheses do not affect balance');

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

// ---------------------------------------------------------------------------
// 7. Mapper: Gutenberg-supported wrapper CSS becomes native support attrs/style.
// ---------------------------------------------------------------------------
$support = $mapper->map(array(
    'background'      => 'var(--wp--preset--color--base)',
    'color'           => 'var(--wp--preset--color--contrast)',
    'gap'             => '1.25rem',
    'display'         => 'flex',
    'align-items'     => 'center',
    'justify-content' => 'space-between',
    'box-shadow'      => '0 12px 30px rgba(0,0,0,.12)',
));
$assert(($support['attrs']['backgroundColor'] ?? '') === 'base', '7: preset background CSS variable maps to backgroundColor attr');
$assert(($support['attrs']['textColor'] ?? '') === 'contrast', '7b: preset text CSS variable maps to textColor attr');
$assert(($support['style']['spacing']['blockGap'] ?? '') === '1.25rem', '7c: gap maps to spacing.blockGap');
$assert(! isset($support['leftover']['display']) && ! isset($support['leftover']['align-items']) && ! isset($support['leftover']['justify-content']), '7d: layout declarations are not left as raw styles');
$assert(($support['style']['shadow'] ?? '') === '0 12px 30px rgba(0,0,0,.12)' && ! isset($support['leftover']['box-shadow']), '7e: box-shadow maps to the native shadow support candidate');
$serializedGap = $mapper->serialize($support['style']);
$assert(str_contains($serializedGap['style'], 'gap:1.25rem'), '7f: blockGap serializes to the wrapper gap declaration');

// ---------------------------------------------------------------------------
// 8. Comments are not syntax. A `/* … */` run is one lexical unit: delimiters
//    inside it never split, and the comment itself is dropped (it reads as a
//    space, so `a/**/b` stays two tokens). This is what kept a disabled
//    declaration `/*border: 5px solid red;*/` from being re-emitted as the
//    property `/*border` with its `*/` thrown away.
// ---------------------------------------------------------------------------
$assert(
    CssValueSplitter::splitTopLevel('width: 900px; /*border: 5px solid red;*/ color: red', array( ';' )) === array( 'width: 900px', 'color: red' ),
    '8: a disabled declaration (comment with `;`) is dropped whole, not split at its `;`',
    json_encode(CssValueSplitter::splitTopLevel('width: 900px; /*border: 5px solid red;*/ color: red', array( ';' )))
);
$assert(
    CssValueSplitter::splitTopLevel('/* lead: {x}; */ width: 1px; /* mid: {y}; */ height: 2px /* tail; */', array( ';' )) === array( 'width: 1px', 'height: 2px' ),
    '8b: comments at the start, middle and end of a block, with `:` `;` `{` `}` inside, leave only the declarations',
    json_encode(CssValueSplitter::splitTopLevel('/* lead: {x}; */ width: 1px; /* mid: {y}; */ height: 2px /* tail; */', array( ';' )))
);
$assert(
    CssValueSplitter::splitTopLevel('background: #96b79f /* url(images/bg.png) */; color: red', array( ';' )) === array( 'background: #96b79f', 'color: red' ),
    '8c: a comment holding url() and parentheses does not change paren depth or split',
    json_encode(CssValueSplitter::splitTopLevel('background: #96b79f /* url(images/bg.png) */; color: red', array( ';' )))
);
$assert(
    CssValueSplitter::splitTopLevel('/* ** star * heavy ** */ color: red', array( ';' )) === array( 'color: red' ),
    '8d: extra stars inside a comment do not end it early'
);
$assert(
    CssValueSplitter::splitTopLevel("color: red; /* it's \"quoted\" */ margin: 0", array( ';' )) === array( 'color: red', 'margin: 0' ),
    '8e: a quote character inside a comment does not open a string',
    json_encode(CssValueSplitter::splitTopLevel("color: red; /* it's \"quoted\" */ margin: 0", array( ';' )))
);
$assert(
    CssValueSplitter::splitTopLevel('content: "/* not a comment */"; color: red', array( ';' )) === array( 'content: "/* not a comment */"', 'color: red' ),
    '8f: a comment-looking string stays a string'
);
$assert(
    CssValueSplitter::splitTopLevel('color: red; /* unterminated ; comment', array( ';' )) === array( 'color: red' ),
    '8g: an unterminated comment runs to the end of the input, as in a browser',
    json_encode(CssValueSplitter::splitTopLevel('color: red; /* unterminated ; comment', array( ';' )))
);
$assert(
    CssValueSplitter::splitTopLevel('/* only a comment */', array( ';' )) === array(),
    '8h: a comment-only list has no declarations'
);
$assert(
    CssValueSplitter::splitTopLevel('red/* , */blue, green', array( ',' )) === array( 'red blue', 'green' ),
    '8i: a comma hidden in a comment is not a separator; the comment reads as one space',
    json_encode(CssValueSplitter::splitTopLevel('red/* , */blue, green', array( ',' )))
);
$assert(
    CssValueSplitter::splitTopLevelWhitespace('1px/**/solid /* c */ red') === array( '1px', 'solid', 'red' ),
    '8j: comments separate tokens in a whitespace split and never become tokens',
    json_encode(CssValueSplitter::splitTopLevelWhitespace('1px/**/solid /* c */ red'))
);
$assert(CssValueSplitter::hasBalancedParens('calc(100% - 2px) /* (sidebar */'), '8k: a paren inside a comment does not unbalance a value');
$assert(! CssValueSplitter::hasBalancedParens('rgba(1, /* ) */ 2'), '8l: a close paren inside a comment does not balance a truncated value');

// ---------------------------------------------------------------------------
// 9. An unquoted url() is one token (CSS Syntax "consume a url token"): a `/*`
//    or `(` inside it is part of the URL, not a comment opener or a group.
//    Quoted urls, comments holding `;` or parens, and escaped quotes keep
//    their own lexical meaning.
// ---------------------------------------------------------------------------
$split = static fn (string $input): array => CssValueSplitter::splitTopLevel($input, array( ';' ));
$assert(
    $split('background: url(a/*b.png); color: red') === array( 'background: url(a/*b.png)', 'color: red' ),
    '9a: `/*` inside an unquoted url() does not open a comment',
    json_encode($split('background: url(a/*b.png); color: red'))
);
$assert(
    $split('background: URL( a/*b(.png ); color: red') === array( 'background: URL( a/*b(.png )', 'color: red' ),
    '9b: an unquoted url() token is case-insensitive and treats `(` as a literal byte',
    json_encode($split('background: URL( a/*b(.png ); color: red'))
);
$assert(
    $split('background: url("a/*b.png"); color: red') === array( 'background: url("a/*b.png")', 'color: red' ),
    '9c: `/*` inside a quoted url() stays inside the string',
    json_encode($split('background: url("a/*b.png"); color: red'))
);
$assert(
    $split('background: myurl(a /* ; */ b); color: red') === array( 'background: myurl(a   b)', 'color: red' ),
    '9d: only a standalone `url(` is a url token; `myurl(` is an ordinary function whose comment is dropped',
    json_encode($split('background: myurl(a /* ; */ b); color: red'))
);
$assert(
    $split('width: 1px; /* a; b; */ height: 2px') === array( 'width: 1px', 'height: 2px' ),
    '9e: a comment holding `;` never splits'
);
$assert(
    $split('width: calc(1px /* ) */ + 2px); height: 2px') === array( 'width: calc(1px   + 2px)', 'height: 2px' ),
    '9f: a `)` inside a comment does not close the enclosing function',
    json_encode($split('width: calc(1px /* ) */ + 2px); height: 2px'))
);
$assert(
    $split('width: 1px /* ( */; height: 2px') === array( 'width: 1px', 'height: 2px' ),
    '9g: a `(` inside a comment does not open a group that swallows the next `;`',
    json_encode($split('width: 1px /* ( */; height: 2px'))
);
$assert(
    $split('content: "a\\";b"; color: red') === array( 'content: "a\\";b"', 'color: red' ),
    '9h: an escaped double quote does not close the string',
    json_encode($split('content: "a\\";b"; color: red'))
);
$assert(
    $split("content: 'a\\';b'; color: red") === array( "content: 'a\\';b'", 'color: red' ),
    '9i: an escaped single quote does not close the string',
    json_encode($split("content: 'a\\';b'; color: red"))
);
$assert(CssValueSplitter::hasBalancedParens('url(a/*b.png)'), '9j: an unquoted url() holding `/*` is balanced');
$assert(CssValueSplitter::hasBalancedParens('url(a(b.png)'), '9k: a `(` inside an unquoted url() is not a group');
$assert(CssValueSplitter::hasBalancedParens('url("a/*b.png")'), '9l: a quoted url() holding `/*` is balanced');
$assert(! CssValueSplitter::hasBalancedParens('url(a/*b.png'), '9m: an unterminated unquoted url() is unbalanced');
$assert(CssValueSplitter::hasBalancedParens('calc(1px /* ) */ + 2px)'), '9n: a `)` inside a comment does not unbalance a value');
$assert(CssValueSplitter::hasBalancedParens('"a\\")" (b)'), '9o: an escaped quote keeps the string open over its `)`');

// ---------------------------------------------------------------------------
// 10. Declaration readers outside the splitter read through it too, so a
//     comment cannot split a declaration they key on.
// ---------------------------------------------------------------------------
$assert(
    100 === LayoutParticipation::widthPreset('/* width: 50%; */ width: 100%'),
    '10a: the width preset reader sees the declaration after a disabled one',
    var_export(LayoutParticipation::widthPreset('/* width: 50%; */ width: 100%'), true)
);

if ( $failures > 0 ) {
    fwrite(STDERR, "CssValueSplitter unit tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "CssValueSplitter unit tests: {$passes} passed\n");

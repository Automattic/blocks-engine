<?php
declare(strict_types=1);

/**
 * Regression coverage for headings (and paragraphs) collapsing to 16px at the
 * WordPress runtime when their authored size is responsive.
 *
 * Observed on https://eloisacalvinato.lovable.app/: the source H1 is Cormorant
 * 72px / 158px tall, authored as `class="font-serif text-5xl leading-[1.1]
 * text-cream md:text-6xl lg:text-7xl"`. After import the same heading rendered
 * at the UA-default 16px / 35px tall, and page height fell 5260 -> 4688.
 *
 * Root cause, proven at runtime on the imported site:
 *  - the custom property survived (`--text-5xl` resolves to 3rem on the h1)
 *    and `.text-5xl{font-size:var(--text-5xl)}` is present in the enqueued
 *    Tailwind stylesheet — so the CSS was carried, but never applied;
 *  - matching font-size rules on the h1 showed only `h1 => inherit`: the
 *    projected theme ships unlayered `h1{font-size:inherit}` /
 *    `.wp-block-heading` defaults, and an UNLAYERED declaration outranks every
 *    `@layer` no matter the class specificity. Tailwind v4 emits its utilities
 *    in `@layer utilities`, so the carried class-owned rule always loses;
 *  - the one authored value that beats an unlayered rule — an inline style —
 *    was never emitted, because `StyleResolver::classOwnedResponsiveDeclarations()`
 *    strips the static `font-size` from presentation attributes whenever ANY
 *    conditional rule (`.md:text-6xl`, `.lg:text-7xl`) touches the `font-size`
 *    family, and nothing re-baked the cascade winner.
 *
 * The fix bakes the cascade-winning authored font-size onto text blocks that
 * support typography.fontSize: the conditional (desktop) winner when a media
 * rule states one, otherwise the static value. Driven by the CSS cascade, not
 * by any utility-class vocabulary.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
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

/** @param array<int, array<string, mixed>> $blocks @return array<string, mixed>|null */
$findFirst = static function (array $blocks, string $name) use (&$findFirst): ?array {
    foreach ( $blocks as $block ) {
        if ( ! is_array($block) ) {
            continue;
        }
        if ( $name === ($block['blockName'] ?? '') ) {
            return $block;
        }
        $found = $findFirst(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array(), $name);
        if ( null !== $found ) {
            return $found;
        }
    }

    return null;
};

$fontSizeOf = static function (array $block): string {
    $fontSize = $block['attrs']['style']['typography']['fontSize'] ?? '';

    return is_scalar($fontSize) ? (string) $fontSize : '';
};

// ---------------------------------------------------------------------
// 1. The reported shape: static base utility plus a media-conditional
//    winner. The heading must carry the lg winner as an inline
//    typography fontSize, not lose its authored size entirely.
// ---------------------------------------------------------------------
$css     = ':root{--text-5xl:3rem;--text-7xl:4.5rem}'
    . '.text-5xl{font-size:var(--text-5xl)}'
    . '@media (min-width:1024px){.lg\:text-7xl{font-size:var(--text-7xl)}}';
$html    = '<style>' . $css . '</style><h1 class="text-5xl lg:text-7xl">Hi</h1>';
$result  = ( new HtmlTransformer() )->transform($html, array())->toArray();
$heading = $findFirst($result['blocks'] ?? array(), 'core/heading');

$assert(null !== $heading, 'the responsive heading converts to core/heading');
$assert(
    'var(--text-7xl)' === $fontSizeOf($heading ?? array()),
    'the heading bakes the conditional (lg) font-size winner as typography.fontSize — THE BUG: this was previously absent and WP rendered 16px',
    'got: ' . ('' === $fontSizeOf($heading ?? array()) ? '(missing)' : $fontSizeOf($heading ?? array()))
);
$serialized = ( new StyleAttributeMapper() )->serialize(is_array(($heading['attrs']['style'] ?? null)) ? $heading['attrs']['style'] : array());
$assert(
    str_contains((string) $serialized['style'], 'font-size:var(--text-7xl)'),
    'the baked value serializes as an inline font-size declaration, the only authored value that beats unlayered WP heading rules',
    (string) $serialized['style']
);

// ---------------------------------------------------------------------
// 2. Same cascade with ordinary, hand-authored class names: the fix is
//    driven by the cascade, not by sniffing a utility vocabulary.
// ---------------------------------------------------------------------
$semanticCss = '.hero-title{font-size:3rem}'
    . '@media (min-width:1024px){.hero-title{font-size:4.5rem}}';
$semanticHtml = '<style>' . $semanticCss . '</style><h1 class="hero-title">Hi</h1>';
$semantic     = $findFirst(( new HtmlTransformer() )->transform($semanticHtml, array())->toArray()['blocks'] ?? array(), 'core/heading');
$assert(
    '4.5rem' === $fontSizeOf($semantic ?? array()),
    'a plainly-named heading with a min-width cascade winner bakes the conditional value',
    'got: ' . ('' === $fontSizeOf($semantic ?? array()) ? '(missing)' : $fontSizeOf($semantic ?? array()))
);

// ---------------------------------------------------------------------
// 3. Cascade-layer path: a static-only utility inside `@layer utilities`
//    has no responsive variant, so the responsive strip never fires — but
//    the layered declaration still loses to the unlayered WP heading
//    rules at runtime. It must be baked too.
// ---------------------------------------------------------------------
$layeredHtml = '<style>@layer utilities{:root{--text-4xl:2.25rem}.text-4xl{font-size:var(--text-4xl)}}</style>'
    . '<h2 class="text-4xl">Section</h2>';
$layered = $findFirst(( new HtmlTransformer() )->transform($layeredHtml, array())->toArray()['blocks'] ?? array(), 'core/heading');
$assert(
    'var(--text-4xl)' === $fontSizeOf($layered ?? array()),
    'a layered static-only utility heading is baked for the same reason — @layer loses to unlayered WP heading rules',
    'got: ' . ('' === $fontSizeOf($layered ?? array()) ? '(missing)' : $fontSizeOf($layered ?? array()))
);

// ---------------------------------------------------------------------
// 4. Unlayered ownership control: an author rule outside any @layer
//    beats the projected element defaults on specificity and wins the
//    source cascade against layered declarations, so class ownership
//    keeps working and nothing is baked on top of it.
// ---------------------------------------------------------------------
$staticCss = '.text-4xl{font-size:var(--text-4xl)}:root{--text-4xl:2.25rem}';
$unlayered = $findFirst(( new HtmlTransformer() )->transform('<style>' . $staticCss . '</style><h2 class="text-4xl">Section</h2>', array())->toArray()['blocks'] ?? array(), 'core/heading');
$assert(
    '' === $fontSizeOf($unlayered ?? array()),
    'an unlayered static utility heading keeps author-stylesheet ownership (unchanged behavior)',
    'got: ' . ('' === $fontSizeOf($unlayered ?? array()) ? '(missing)' : $fontSizeOf($unlayered ?? array()))
);

// ---------------------------------------------------------------------
// 5. Inline priority: an authored inline font-size keeps its normal
//    cascade priority; the conditional winner must not replace it.
// ---------------------------------------------------------------------
$inlineHtml = '<style>' . $css . '</style><h1 class="text-5xl lg:text-7xl" style="font-size:2rem">Hi</h1>';
$inline     = $findFirst(( new HtmlTransformer() )->transform($inlineHtml, array())->toArray()['blocks'] ?? array(), 'core/heading');
$assert(
    '2rem' === $fontSizeOf($inline ?? array()),
    'an authored inline font-size is never overridden by the conditional winner',
    'got: ' . ('' === $fontSizeOf($inline ?? array()) ? '(missing)' : $fontSizeOf($inline ?? array()))
);

// ---------------------------------------------------------------------
// 6. No authored size, nothing to bake: the heading must not grow a
//    font-size it never declared.
// ---------------------------------------------------------------------
$plain = $findFirst(( new HtmlTransformer() )->transform('<style>' . $css . '</style><h3>Plain</h3>', array())->toArray()['blocks'] ?? array(), 'core/heading');
$assert(
    '' === $fontSizeOf($plain ?? array()),
    'a heading with no authored font-size gets nothing baked'
);

// ---------------------------------------------------------------------
// 7. The same mechanism covers the other rich-text block that supports
//    typography.fontSize.
// ---------------------------------------------------------------------
$paragraphHtml = '<style>:root{--text-lg:1.125rem}.text-base{font-size:1rem}'
    . '@media (min-width:768px){.md\:text-lg{font-size:var(--text-lg)}}</style>'
    . '<p class="text-base md:text-lg">Intro</p>';
$paragraph     = $findFirst(( new HtmlTransformer() )->transform($paragraphHtml, array())->toArray()['blocks'] ?? array(), 'core/paragraph');
$assert(
    'var(--text-lg)' === $fontSizeOf($paragraph ?? array()),
    'a responsive paragraph bakes the conditional font-size winner the same way',
    'got: ' . ('' === $fontSizeOf($paragraph ?? array()) ? '(missing)' : $fontSizeOf($paragraph ?? array()))
);

if ( 0 < $failures ) {
    fwrite(STDERR, "Heading responsive font-size contract failed ({$failures} failing, {$passes} passing)\n");
    exit(1);
}

fwrite(STDOUT, "Heading responsive font-size contract passed: {$passes} assertions\n");

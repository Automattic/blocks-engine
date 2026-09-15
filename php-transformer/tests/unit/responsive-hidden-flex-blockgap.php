<?php
declare(strict_types=1);

/**
 * Regression coverage for a responsive-hidden flex/grid container silently
 * losing its authored `gap`.
 *
 * Root cause: `StyleResolver::classOwnedResponsiveDeclarations()` keeps a
 * static (unconditional) declaration under author-stylesheet ownership
 * whenever ANY conditional (media-scoped) rule matching the same element
 * touches a property in the same `responsivePropertyFamily()` bucket. That
 * bucket used to fold `gap`/`row-gap`/`column-gap` into the same `layout`
 * family as `display`/`justify-content`/`align-*`/`flex-*`/`grid-*`. A
 * container authored as `display:none` at rest and `display:flex` only at a
 * breakpoint (e.g. Tailwind's `hidden md:flex`) has a CONDITIONAL rule for
 * `display`, so the entire `layout` family — including an UNCONDITIONAL
 * `gap` declaration that has no responsive variant at all — was stripped
 * before block-attribute mapping ever saw it. No `style.spacing.blockGap`
 * was emitted, and WordPress's zero-specificity default
 * `:where(.is-layout-flex){gap:0.5em}` won instead: an authored 32px gap
 * rendered at 7px (0.5em) on the real page that surfaced this bug
 * (https://eloisacalvinato.lovable.app/).
 *
 * This is a bug in the shared, pattern-agnostic style-resolution layer
 * (`StyleResolver`), not in navigation. `presentationAttributes()` — the
 * function `classOwnedResponsiveDeclarations()` filters for — is the same
 * generic context closure every pattern consumes (see e.g.
 * ColumnsPattern::recognize() and ButtonsPattern::recognize()), so any
 * consumer that must bake a resolved `gap` into block attributes rather than
 * relying on class-owned CSS passthrough is equally exposed. Navigation is
 * exposed here because core/navigation's real flex container is its
 * generated `.wp-block-navigation__container`, not the source element the
 * author's `gap` rule targets — so it MUST resolve and bake the winning gap
 * value rather than rely on the retained class continuing to match the
 * actual flex box.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;

$failures = 0;
$passes = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

/** @param array<int, array<string, mixed>> $blocks @return array<int, array<string, mixed>> */
$findBlocks = static function (array $blocks, string $name) use (&$findBlocks): array {
    $found = array();
    foreach ( $blocks as $block ) {
        if ( ! is_array($block) ) {
            continue;
        }
        if ( $name === ($block['blockName'] ?? '') ) {
            $found[] = $block;
        }
        $found = array_merge($found, $findBlocks(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array(), $name));
    }

    return $found;
};

// ---------------------------------------------------------------------
// 1. Generic layer: `gap` must not share a responsive family with
//    `display`/`justify-content`/`align-*`/`flex-*`/`grid-*`. This is a
//    pure classification contract on the shared StyleResolver — it does
//    not depend on navigation, or on any particular class-naming scheme.
// ---------------------------------------------------------------------
$resolver = ( new ReflectionClass(StyleResolver::class) )->newInstanceWithoutConstructor();
$family = static fn (string $property): string => $resolver->responsivePropertyFamily($property);

$assert('gap' !== $family('display'), 'display does not share gap\'s responsive family', $family('display'));
$assert($family('gap') === $family('row-gap') && $family('gap') === $family('column-gap'), 'gap/row-gap/column-gap remain one family', $family('gap') . '/' . $family('row-gap') . '/' . $family('column-gap'));
$assert($family('display') === $family('justify-content')
    && $family('display') === $family('align-items')
    && $family('display') === $family('flex-direction')
    && $family('display') === $family('grid-template-columns'), 'display/justify-content/align-items/flex-*/grid-* remain one family (unchanged behavior)');
$assert($family('gap') !== $family('display'), 'gap is isolated from the display-driven layout family');

// ---------------------------------------------------------------------
// 2. Real-world contrast: the eloisacalvinato.lovable.app desktop/mobile
//    navigation pair. Same authored `gap`-bearing markup, differing only
//    in whether `display` itself has a responsive (conditional) variant.
// ---------------------------------------------------------------------
$css = '.hidden{display:none}.items-center{align-items:center}.gap-8{gap:calc(var(--spacing) * 8)}'
    . '.flex{display:flex}.flex-col{flex-direction:column}.gap-4{gap:calc(var(--spacing) * 4)}'
    . '@media (width>=48rem){.md\:flex{display:flex}}';

$html = '<style>' . $css . '</style>'
    . '<header><div class="container-site flex h-20 items-center justify-between">'
    . '<a href="#inicio" class="group flex flex-col leading-tight"><span>Brand</span></a>'
    . '<nav class="hidden items-center gap-8 md:flex"><a href="#a">Inicio</a><a href="#b">Sobre</a><a href="#c">Terapia</a></nav>'
    . '</div></header>'
    . '<div class="dla-dialog"><nav class="flex flex-col gap-4"><a href="#a">Inicio</a><a href="#b">Sobre</a><a href="#c">Terapia</a></nav></div>';

$result = ( new HtmlTransformer() )->transform($html, array())->toArray();
$navs = $findBlocks($result['blocks'] ?? array(), 'core/navigation');

$assert(2 === count($navs), 'both the desktop (responsive-hidden) and mobile navigations are emitted', (string) count($navs));

$desktopNav = null;
$mobileNav = null;
foreach ( $navs as $nav ) {
    $className = (string) (($nav['attrs'] ?? array())['className'] ?? '');
    if ( str_contains($className, 'gap-8') ) {
        $desktopNav = $nav;
    }
    if ( str_contains($className, 'gap-4') ) {
        $mobileNav = $nav;
    }
}

$assert(null !== $desktopNav, 'the responsive-hidden desktop navigation is found by its className');
$assert(null !== $mobileNav, 'the always-visible mobile navigation is found by its className');

$desktopGap = (string) ( ($desktopNav['attrs']['style']['spacing']['blockGap'] ?? null) ?? '' );
$mobileGap = (string) ( ($mobileNav['attrs']['style']['spacing']['blockGap'] ?? null) ?? '' );

$assert(
    'calc(var(--spacing) * 8)' === $desktopGap,
    'the responsive-hidden (display:none base, md:flex breakpoint) navigation keeps its unconditional gap-8 as blockGap — THE BUG: this was previously silently absent',
    'got: ' . ('' === $desktopGap ? '(missing)' : $desktopGap)
);
$assert(
    'calc(var(--spacing) * 4)' === $mobileGap,
    'the control navigation (visible at rest, no responsive display variant) keeps its blockGap as before',
    'got: ' . ('' === $mobileGap ? '(missing)' : $mobileGap)
);

// ---------------------------------------------------------------------
// 3. Non-Tailwind control: the same cascade shape with ordinary
//    hand-authored class names, proving the fix is driven by CSS
//    cascade/family semantics and not by sniffing any utility-class
//    vocabulary (no `hidden`/`md:`/`gap-N` tokens involved).
// ---------------------------------------------------------------------
$semanticCss = '.primary-nav{display:none;align-items:center;gap:32px}'
    . '@media (min-width:768px){.primary-nav{display:flex}}';
$semanticHtml = '<style>' . $semanticCss . '</style>'
    . '<nav class="primary-nav"><a href="/">Home</a><a href="/about">About</a><a href="/contact">Contact</a></nav>';
$semanticResult = ( new HtmlTransformer() )->transform($semanticHtml, array())->toArray();
$semanticNavs = $findBlocks($semanticResult['blocks'] ?? array(), 'core/navigation');
$semanticGap = (string) ( ($semanticNavs[0]['attrs']['style']['spacing']['blockGap'] ?? null) ?? '' );

$assert(1 === count($semanticNavs), 'the semantic (non-Tailwind) responsive-hidden nav is still recognized as core/navigation');
$assert('32px' === $semanticGap, 'a plainly-named responsive-hidden flex container keeps its authored gap', 'got: ' . ('' === $semanticGap ? '(missing)' : $semanticGap));

if ( 0 < $failures ) {
    fwrite(STDERR, "Responsive hidden flex blockGap contract failed ({$failures} failing, {$passes} passing)\n");
    exit(1);
}

fwrite(STDOUT, "Responsive hidden flex blockGap contract passed: {$passes} assertions\n");

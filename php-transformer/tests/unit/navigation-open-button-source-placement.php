<?php
declare(strict_types=1);

/**
 * When the transformer drops a source menu toggle for core/navigation's
 * native overlay control, core's open button renders inside the nav host,
 * which the generated toggle host rule pins `position:relative` and sizes to
 * the button. A source toggle the author placed absolutely at the collapsed
 * viewport (`.bar button{position:absolute;right:0;top:50%;
 * transform:translateY(-50%)}` under a max-width query) therefore lost its
 * placement: the open button stayed in the header's flex flow at the nav's
 * slot, over the logo, and the collapsed panel's border landed on the host
 * around it.
 *
 * The toggle's collapsed-viewport placement (position, offsets, transform,
 * margins, stacking) is now restated on core's open button, the host becomes
 * `position:static` so the same ancestor is the containing block as in the
 * source, and the host drops the panel's border. The transform stays on the
 * button only: a transformed host would become the containing block of
 * core's fixed overlay. All of it sits inside the collapsed-viewport media
 * block, so desktop output is unchanged. A toggle with no author placement,
 * and one whose containing block differs from the navigation's, are left as
 * before.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$baseCss = '.bar{display:flex;align-items:center;position:relative;min-height:72px}'
    . '.bar nav{display:flex;align-items:center;gap:22px}'
    . '.bar nav a{font-size:18px;font-weight:700;text-decoration:none;color:rgba(255,255,255,.86)}'
    . '.bar button{display:none;border:0;background:none;font-size:27px;color:#fff}';
$collapsedPanel = '.bar nav{display:none;position:absolute;left:0;right:0;top:100%;transform:none;background:#061b38;padding:20px;flex-direction:column;border:1px solid rgba(255,255,255,.14);z-index:50}'
    . '.bar nav.open{display:flex}';
// The comment before `@media` is deliberate: real stylesheets label their
// breakpoints, and the author analysis keeps that comment in the at-rule
// condition it records.
$placedToggleCss = $baseCss
    . "/* =====\n   TABLET\n   ===== */\n\n@media(max-width:1000px){"
    . '.bar button{position:absolute;right:0;top:50%;transform:translateY(-50%);margin:0;padding:8px;display:flex;align-items:center;justify-content:center}'
    . $collapsedPanel
    . '}';
$flowToggleCss = $baseCss
    . '@media(max-width:1000px){'
    . '.bar button{display:flex;padding:8px}'
    . $collapsedPanel
    . '}';
$toggle = '<button aria-controls="site-menu" aria-expanded="false" aria-label="Menu"><svg viewBox="0 0 24 24" width="24" height="24"><path d="M3 6h18M3 12h18M3 18h18"/></svg></button>';
$nav = '<nav id="site-menu"><a href="#home">Home</a><a href="#about">About</a><a href="#work">Work</a><a href="#news">News</a><a href="#contact">Contact</a><a href="/fr.html">FR</a></nav>';
$header = static fn (string $controls): string => '<header><div class="bar"><a class="brand" href="#home">Brand</a>' . $controls . '</div></header>';

/** @return array{0: string, 1: string} serialized blocks, every generated CSS asset */
$transform = static function (string $css, string $body): array {
    $result = ( new HtmlTransformer() )->transform(
        '<style>' . $css . '</style>' . $body . '<main><section id="home"><h1>Hello</h1></section></main>',
        array()
    )->toArray();
    $generatedCss = '';
    foreach ( ($result['assets'] ?? array()) as $asset ) {
        $path = (string) ($asset['path'] ?? '');
        if ( 'css' === ($asset['kind'] ?? '') || str_ends_with($path, '.css') || str_starts_with($path, 'inline-style') ) {
            $generatedCss .= (string) ($asset['content'] ?? '') . "\n";
        }
    }

    return array( (string) ($result['serialized_blocks'] ?? ''), $generatedCss );
};

/**
 * Every style rule in the CSS as [media stack, selector, body], with the
 * conditional at-rules it sits inside.
 *
 * @return list<array{0: list<string>, 1: string, 2: string}>
 */
$rules = static function (string $css): array {
    $out = array();
    $stack = array();
    $buffer = '';
    $length = strlen($css);
    for ( $i = 0; $i < $length; ++$i ) {
        $char = $css[$i];
        if ( '{' === $char ) {
            $prelude = trim($buffer);
            $buffer = '';
            if ( 1 === preg_match('/^@(media|supports|layer|container)\b/i', $prelude) ) {
                $stack[] = $prelude;
                continue;
            }
            $end = strpos($css, '}', $i);
            if ( false === $end ) {
                break;
            }
            $out[] = array( $stack, $prelude, trim(substr($css, $i + 1, $end - $i - 1)) );
            $i = $end;
            continue;
        }
        if ( '}' === $char ) {
            array_pop($stack);
            $buffer = '';
            continue;
        }
        $buffer .= $char;
    }

    return $out;
};
$toggleMarker = static function (string $blocks): string {
    return preg_match('/blocks-engine-native-navigation-toggle-[0-9a-f]+/', $blocks, $match) ? $match[0] : '';
};
$declares = static fn (string $body, string $declaration): bool => str_contains(str_replace(' ', '', $body), $declaration);
// Any upper-bounded width query: the marker's own `max-width` block, or the
// range block the source-breakpoint fix emits between core's 600px and it.
$isCollapsedMedia = static fn (array $stack): bool => array() !== $stack && 1 === preg_match('/^@media\b.*max-width/i', (string) end($stack));

// --- Absolutely placed toggle: the placement moves onto core's open button. ---

[ $blocks, $css ] = $transform($placedToggleCss, $header($toggle . $nav));
$assert(str_contains($blocks, '"overlayMenu":"mobile"'), 'placed toggle: navigation emits the native mobile overlay', $blocks);
$marker = $toggleMarker($blocks);
$assert('' !== $marker, 'placed toggle: the navigation carries a toggle marker', $blocks);
$hostRules = array();
$openRules = array();
$otherMarkerRules = array();
foreach ( $rules($css) as [ $stack, $selector, $body ] ) {
    if ( ! str_contains($selector, $marker) ) {
        continue;
    }
    if ( str_ends_with($selector, '.' . $marker) ) {
        $hostRules[] = array( $stack, $body );
    } elseif ( str_ends_with($selector, '>.wp-block-navigation__responsive-container-open') ) {
        $openRules[] = array( $stack, $body );
    } else {
        $otherMarkerRules[] = array( $stack, $selector, $body );
    }
}
$assert(array() !== $openRules, 'placed toggle: an open-button rule is generated', $css);
$placementSeen = false;
foreach ( $openRules as [ $stack, $body ] ) {
    if ( ! $declares($body, 'position:absolute!important') ) {
        continue;
    }
    $placementSeen = true;
    $assert($isCollapsedMedia($stack), 'placed toggle: the placement rule sits inside the collapsed-viewport media block', implode(' / ', $stack) . ' ' . $body);
    foreach ( array( 'right:0!important', 'top:50%!important', 'transform:translateY(-50%)!important', 'margin:0!important' ) as $declaration ) {
        $assert($declares($body, $declaration), 'placed toggle: the open button restates ' . $declaration, $body);
    }
    // The panel's offsets belong to the host's source nav, never to the button.
    $assert(! $declares($body, 'left:0!important') && ! $declares($body, 'top:100%!important'), 'placed toggle: the collapsed panel\'s own placement is not mistaken for the toggle\'s', $body);
}
$assert($placementSeen, 'placed toggle: core\'s open button is positioned absolutely like the source toggle', $css);
$assert(array() !== $hostRules, 'placed toggle: a host rule is generated', $css);
// The marker's host rule states the host's position; a range rule the
// source-breakpoint fix emits between 600px and the boundary only restates
// `display`, so the position is asserted across the set, not per rule.
$hostStatic = false;
$hostBorderReset = false;
foreach ( $hostRules as [ $stack, $body ] ) {
    $assert($isCollapsedMedia($stack), 'placed toggle: the host rule sits inside a collapsed-viewport media block', implode(' / ', $stack) . ' ' . $body);
    $hostStatic = $hostStatic || $declares($body, 'position:static!important');
    $hostBorderReset = $hostBorderReset || $declares($body, 'border:0!important');
    $assert(! $declares($body, 'position:relative'), 'placed toggle: the host is no longer its own containing block', $body);
    $assert(1 !== preg_match('/(?:^|;)\s*(transform|translate|rotate|scale|filter|perspective|will-change|contain)\s*:\s*(?!none\b)/', $body), 'placed toggle: the host carries no transform that would trap core\'s fixed overlay', $body);
}
$assert($hostStatic, 'placed toggle: the host is static so the source containing block places the open button', $css);
$assert($hostBorderReset, 'placed toggle: the host drops the collapsed panel\'s border', $css);
// The block editor gives every block wrapper `position:relative`, so the
// placement is undone there and the canvas keeps its in-flow button.
$editorReset = array_values(array_filter(
    $rules($css),
    static fn (array $rule): bool => str_starts_with($rule[1], ':root .editor-styles-wrapper ') && str_contains($rule[1], $marker) && str_ends_with($rule[1], '>.wp-block-navigation__responsive-container-open')
));
$assert(1 === count($editorReset), 'placed toggle: one editor-scoped reset rule is emitted for the open button', $css);
foreach ( $editorReset as [ $stack, $selector, $body ] ) {
    $assert($isCollapsedMedia($stack), 'placed toggle: the editor reset sits in the collapsed-viewport media block', implode(' / ', $stack));
    foreach ( array( 'position:static!important', 'inset:auto!important', 'transform:none!important', 'margin:0!important' ) as $declaration ) {
        $assert($declares($body, $declaration), 'placed toggle: the editor reset restates ' . $declaration, $body);
    }
}
foreach ( $otherMarkerRules as [ $stack, $selector, $body ] ) {
    if ( str_contains($selector, 'responsive-container-open') ) {
        continue;
    }
    $assert(! $declares($body, 'position:absolute') && 1 !== preg_match('/(?:^|;)\s*(transform|translate)\s*:\s*(?!none\b)/', $body), 'placed toggle: no other generated rule under the marker moves or transforms an overlay ancestor', $selector . '{' . $body . '}');
}
// The host itself is only restated inside collapsed-viewport media blocks,
// so desktop output is unchanged. (Descendant rules under the marker, such as
// the inner-list placement reset, are other mechanisms.)
foreach ( $rules($css) as [ $stack, $selector, $body ] ) {
    if ( str_ends_with($selector, '.' . $marker) ) {
        $assert($isCollapsedMedia($stack), 'placed toggle: every host rule under the marker is viewport-conditional', implode(' / ', $stack) . ' ' . $selector);
    }
}
// The author's own toggle rule still goes nowhere (the never-present marker from the type-rule fix).
$assert(! preg_match('/(?:^|[,{}])\s*\.bar button\s*(?:\{|,)/', $css), 'placed toggle: no bare `.bar button` selector survives', $css);

// --- Toggle in flow: nothing is placed, the host keeps its containing block. ---

[ $blocks, $css ] = $transform($flowToggleCss, $header($toggle . $nav));
$assert(str_contains($blocks, '"overlayMenu":"mobile"'), 'flow toggle: navigation emits the native mobile overlay', $blocks);
$marker = $toggleMarker($blocks);
$assert('' !== $marker, 'flow toggle: the navigation carries a toggle marker', $blocks);
$hostRelative = false;
foreach ( $rules($css) as [ $stack, $selector, $body ] ) {
    if ( ! str_contains($selector, $marker) ) {
        continue;
    }
    if ( str_ends_with($selector, '>.wp-block-navigation__responsive-container-open') ) {
        $assert(! str_contains($body, 'position:') && ! str_contains($body, 'transform:') && ! str_contains($body, 'right:'), 'flow toggle: the open button gets no placement', $body);
        $assert(! str_starts_with($selector, ':root .editor-styles-wrapper'), 'flow toggle: no editor reset is emitted when nothing is placed', $selector);
    } elseif ( str_ends_with($selector, '.' . $marker) ) {
        $hostRelative = $hostRelative || $declares($body, 'position:relative!important');
        $assert(! $declares($body, 'position:static'), 'flow toggle: the host is not made static', $body);
    }
}
$assert($hostRelative, 'flow toggle: the host stays its own containing block', $css);

// --- Toggle whose containing block is not the navigation's: left as before. ---

$separateContainingBlockCss = $baseCss
    . '.controls{position:relative;margin-left:auto}'
    . '@media(max-width:1000px){'
    . '.bar button{position:absolute;right:0;top:50%;transform:translateY(-50%);display:flex;padding:8px}'
    . $collapsedPanel
    . '}';
[ $blocks, $css ] = $transform($separateContainingBlockCss, $header('<div class="controls">' . $toggle . '</div>' . $nav));
$marker = $toggleMarker($blocks);
$assert(str_contains($blocks, '"overlayMenu":"mobile"') && '' !== $marker, 'separate containing block: the fixture is still a dropped toggle', $blocks);
$hostRelative = false;
foreach ( $rules($css) as [ $stack, $selector, $body ] ) {
    if ( ! str_contains($selector, $marker) ) {
        continue;
    }
    if ( str_ends_with($selector, '>.wp-block-navigation__responsive-container-open') ) {
        $assert(! str_contains($body, 'position:absolute'), 'separate containing block: the placement is not carried onto the open button', $body);
    } elseif ( str_ends_with($selector, '.' . $marker) ) {
        $hostRelative = $hostRelative || $declares($body, 'position:relative!important');
        $assert(! $declares($body, 'position:static'), 'separate containing block: the host is not made static', $body);
    }
}
$assert($hostRelative, 'separate containing block: the host keeps position:relative', $css);

// --- No toggle: author rules are left alone. ---

[ $blocks, $css ] = $transform($placedToggleCss, $header($nav));
$assert('' === $toggleMarker($blocks), 'no toggle: no toggle marker is generated', $blocks);
$assert(str_contains($css, '.bar button{'), 'no toggle: the bare `.bar button` rule survives unchanged', $css);

if ( 0 < $failures ) {
    fwrite(STDERR, "navigation open button source placement FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "navigation open button source placement passed: {$passes} assertions\n";

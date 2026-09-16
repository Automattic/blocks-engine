<?php
declare(strict_types=1);

/**
 * A closed disclosure keeps its fixed-position overlay panel as its own inner
 * block, and the closed state survives conversion.
 *
 * Imported https://eloisacalvinato.lovable.app/ produced a WordPress page
 * whose CLOSED mobile-menu overlay intercepted pointer events on desktop:
 * Playwright's FAQ `<details>` clicks failed with
 * `<div class="wp-block-group dla-dialog ..."> from <header id="inicio"
 * class="wp-block-group fixed ..."> subtree intercepts pointer events`.
 *
 * A closed `<details>` is inert — even `position:fixed` descendants that are
 * actual DOM children of the closed details do not hit-test — so interception
 * is only possible when conversion stops keeping the panel inside the
 * details. The hamburger-redundancy logic read a native disclosure's own
 * collapsible panel (`<nav>` or dialog markup inside the same `<details>`) as
 * the "associated navigation menu" its icon-only summary merely opened: the
 * `details` was dropped as redundant toggle chrome and the panel was
 * suppressed as a projected overlay target, so the disclosure and its panel
 * were lost — or, in sibling shapes, the panel surfaced outside the closed
 * details where the fixed-position box hit-tests over the page.
 *
 * The structural rule: a `<details>` disclosure carries its panel as its own
 * content. `core/details` preserves toggle and panel natively and closed, so
 * the disclosure boundary must win over hamburger-chrome association —
 * recognized from the native details/summary structure, never a class string.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

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

$transform = static fn (string $html): string => (string) ( ( new HtmlTransformer() )->transform($html, array())->toArray()['serialized_blocks'] ?? '' );

/**
 * The panel counts as kept only when its serialized markup sits between the
 * emitted `<details ...>` opening tag and its `</details>` closing tag —
 * an inner block of the disclosure, not a sibling hoisted out of it.
 */
$panelInsideClosedDetails = static function (string $blocks, string $panelNeedle) use ($assert, &$failures): bool {
    $detailsStart = strpos($blocks, '<details');
    $detailsEnd = strpos($blocks, '</details>');
    $panelStart = strpos($blocks, $panelNeedle);
    if ( false === $detailsStart || false === $detailsEnd || false === $panelStart ) {
        $assert(false, 'the disclosure and its panel are both emitted', $blocks);
        return false;
    }
    return $detailsStart < $panelStart && $panelStart < $detailsEnd;
};

$closedDetails = static function (string $blocks) use ($assert): void {
    $assert(
        ! preg_match('/<details[^>]*\sopen[\s>]/', $blocks),
        'the emitted details keeps the source-closed state',
        $blocks
    );
};

// -- A closed details wrapping a position:fixed panel keeps the panel inside,
//    with the fixed positioning intact on the panel markup.
$fixed = $transform(
    '<style>.hamburger-bars{width:16px;height:16px}</style>'
    . '<details class="menu-disclosure"><summary aria-label="Abrir menu">'
    . '<svg class="hamburger-bars" viewBox="0 0 24 24"><path d="M4 5h16"/></svg></summary>'
    . '<div class="overlay-panel" style="position:fixed;inset:0;z-index:50;background:#111">'
    . '<nav><a href="#a">A</a><a href="#b">B</a></nav></div></details>'
);

$assert(
    str_contains($fixed, '<!-- wp:details'),
    'a closed details wrapping a position:fixed panel converts to core/details',
    $fixed
);
$closedDetails($fixed);
$assert(
    $panelInsideClosedDetails($fixed, 'overlay-panel'),
    'the position:fixed panel remains an inner block of the closed details, not a sibling',
    $fixed
);
$assert(
    str_contains($fixed, 'overlay-panel'),
    'the panel keeps its source class hook so author rules still address the fixed box',
    $fixed
);

// -- The same disclosure with a `role="dialog"` overlay panel (the captured
//    disclosure shape) stays a details with the dialog markup inside.
$dialog = $transform(
    '<style>.hamburger-bars{width:16px;height:16px}.fixed{position:fixed;inset:0}</style>'
    . '<details class="menu-disclosure"><summary aria-label="Abrir menu">'
    . '<svg class="hamburger-bars" viewBox="0 0 24 24"><path d="M4 5h16"/></svg></summary>'
    . '<div class="overlay-panel" role="dialog" aria-modal="true"><div class="fixed">'
    . '<nav><a href="#a">A</a><a href="#b">B</a></nav></div></div></details>'
);

$closedDetails($dialog);
$assert(
    $panelInsideClosedDetails($dialog, 'overlay-panel'),
    'a dialog-markup overlay panel stays an inner block of the closed details',
    $dialog
);

// -- A nav panel directly inside a details with an icon-only menu summary is
//    the disclosure's own collapsible content: the hamburger association must
//    not read it as an external menu and drop the disclosure wholesale.
$navPanel = $transform(
    '<style>.hamburger-bars{width:16px;height:16px}.overlay-panel{position:fixed;inset:0}</style>'
    . '<details class="menu-disclosure"><summary aria-label="Abrir menu">'
    . '<svg class="hamburger-bars" viewBox="0 0 24 24"><path d="M4 5h16"/></svg></summary>'
    . '<nav class="overlay-panel"><a href="#a">A</a><a href="#b">B</a></nav></details>'
);

$assert(
    '' !== trim($navPanel) && str_contains($navPanel, '<!-- wp:details'),
    'an icon-only menu summary with a nav panel still emits the disclosure (no silent content loss)',
    $navPanel
);
$closedDetails($navPanel);
$assert(
    $panelInsideClosedDetails($navPanel, 'wp:navigation'),
    'the nav panel is preserved inside the closed details',
    $navPanel
);
$assert(
    str_contains($navPanel, 'overlayMenu":"never"'),
    'a nav panel inside a disclosure is not itself turned into a responsive overlay',
    $navPanel
);

// -- The hamburger-redundancy drop still applies when the associated menu is
//    OUTSIDE the toggle's disclosure: an ARIA-wired hamburger pointing at an
//    external menu is still redundant chrome, and the panel-less disclosure
//    hosting it is still dropped (no content to lose).
$external = $transform(
    '<style>.hamburger-bars{width:16px;height:16px}.menu{display:none}</style>'
    . '<header><details><summary aria-label="Menu" aria-controls="site-menu" aria-expanded="false">'
    . '<svg class="hamburger-bars" viewBox="0 0 24 24"><path d="M4 5h16"/></svg></summary></details>'
    . '<nav class="menu" id="site-menu"><a href="#a">A</a><a href="#b">B</a></nav></header>'
);

$assert(
    ! str_contains($external, 'wp:details'),
    'a panel-less disclosure hamburger associated with an EXTERNAL menu is still dropped as redundant chrome',
    $external
);
$assert(
    str_contains($external, 'wp:navigation'),
    'the external menu still converts to core/navigation',
    $external
);

if ( $failures > 0 ) {
    fwrite(STDERR, "Closed details fixed-overlay contract failed ({$failures} failing, {$passes} passing)\n");
    exit(1);
}

fwrite(STDOUT, "Closed details fixed-overlay contract passed: {$passes} assertions\n");

<?php
declare(strict_types=1);

/**
 * A hamburger menu toggle the transformer drops in favour of Core's native
 * overlay control leaves its author rules behind. When those rules address
 * the toggle by element type (`.nav button{position:absolute;right:0;…}`),
 * the projected stylesheet kept the bare type selector, and the only
 * `button`s left inside the converted header are the ones Core renders: the
 * overlay's open and close buttons. The toggle's placement rule then moved
 * Core's open button out of the header (its containing block is the
 * toggle-sized nav host), and a `display:none` the source used to hide the
 * toggle on desktop could hide Core's open button at phone width.
 *
 * A type selector whose only source subjects were dropped toggles now
 * matches nothing (a never-present marker, through the same rightmost
 * insertion that excludes projected controls). A selector that also matched
 * other source elements keeps those matches but is excluded from Core's
 * open and close buttons. Labelled buttons, which convert to core/button,
 * and headers without a toggle are unchanged.
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

$css = '.bar{display:flex;align-items:center;position:relative}'
    . '.bar nav{position:absolute;right:0;top:50%;transform:translateY(-50%);display:flex;align-items:center;gap:22px}'
    . '.bar nav a{font-size:18px;font-weight:700;text-decoration:none;color:rgba(255,255,255,.86)}'
    . '.bar button{display:none;border:0;background:none;font-size:27px;color:#fff}'
    . '.bar button svg{width:24px;height:24px}'
    . '@media(max-width:1300px){'
    . '.bar button{position:absolute;right:0;top:50%;transform:translateY(-50%);margin:0;padding:8px;display:flex;align-items:center;justify-content:center}'
    . '.bar nav{display:none;position:absolute;left:0;right:0;top:100%;transform:none;background:#061b38;padding:20px;flex-direction:column;align-items:flex-start;z-index:50}'
    . '.bar nav.open{display:flex}'
    . '}';
$toggle = '<button aria-controls="site-menu" aria-expanded="false" aria-label="Menu"><svg viewBox="0 0 24 24" width="24" height="24"><path d="M3 6h18M3 12h18M3 18h18"/></svg></button>';
$nav = '<nav id="site-menu"><a href="#home">Home</a><a href="#about">About</a><a href="#work">Work</a><a href="#news">News</a><a href="#contact">Contact</a><a href="/fr.html">FR</a></nav>';
$header = static fn (string $controls): string => '<header><div class="bar"><a class="brand" href="#home">Brand</a>' . $controls . '</div></header>';

/** @return array{0: string, 1: string} serialized blocks, projected author CSS */
$transform = static function (string $css, string $body): array {
    $result = ( new HtmlTransformer() )->transform(
        '<style>' . $css . '</style>' . $body . '<main><section id="home"><h1>Hello</h1></section></main>',
        array()
    )->toArray();
    $authorCss = '';
    foreach ( ($result['assets'] ?? array()) as $asset ) {
        if ( str_starts_with((string) ($asset['path'] ?? ''), 'inline-style') ) {
            $authorCss .= (string) ($asset['content'] ?? '');
        }
    }

    return array( (string) ($result['serialized_blocks'] ?? ''), $authorCss );
};

/**
 * Every projected selector (flattened out of media blocks) whose rule declares
 * the given property.
 *
 * @return list<string>
 */
$splitSelectorList = static function (string $prelude): array {
    $selectors = array();
    $depth = 0;
    $current = '';
    foreach ( str_split($prelude) as $char ) {
        if ( '(' === $char ) {
            ++$depth;
        } elseif ( ')' === $char ) {
            --$depth;
        }
        if ( ',' === $char && 0 === $depth ) {
            $selectors[] = trim($current);
            $current = '';
            continue;
        }
        $current .= $char;
    }
    $selectors[] = trim($current);

    return array_values(array_filter($selectors, static fn (string $selector): bool => '' !== $selector));
};
$selectorsDeclaring = static function (string $authorCss, string $property) use ($splitSelectorList): array {
    $selectors = array();
    $flat = preg_replace('/@media[^{]*\{/', '', $authorCss) ?? $authorCss;
    if ( preg_match_all('/(?:^|(?<=\}))\s*([^{}@][^{}]*)\{([^{}]*)\}/', $flat, $matches, PREG_SET_ORDER) ) {
        foreach ( $matches as $match ) {
            if ( preg_match('/(?:^|;)\s*' . preg_quote($property, '/') . '\s*:/i', $match[2]) ) {
                array_push($selectors, ...$splitSelectorList($match[1]));
            }
        }
    }

    return array_values(array_unique($selectors));
};

$neverMatch = ':where(.blocks-engine-superseded-menu-toggle)';
$chromeExclusion = ':not(:where(.wp-block-navigation__responsive-container-open,.wp-block-navigation__responsive-container-open *,.wp-block-navigation__responsive-container-close,.wp-block-navigation__responsive-container-close *))';

// --- The toggle is dropped: its type-selector rules cannot reach Core's chrome. ---

[ $blocks, $authorCss ] = $transform($css, $header($toggle . $nav));
$assert(str_contains($blocks, '"overlayMenu":"mobile"'), 'dropped toggle: navigation emits the native mobile overlay', $blocks);
$assert(! str_contains($blocks, '<!-- wp:button'), 'dropped toggle: the toggle is not emitted as a core/button', $blocks);
foreach ( array( 'position', 'display', 'padding', 'margin', 'color' ) as $property ) {
    foreach ( $selectorsDeclaring($authorCss, $property) as $selector ) {
        if ( ! str_contains($selector, 'button') ) {
            continue;
        }
        $assert(
            str_contains($selector, $neverMatch),
            'dropped toggle: a `button` rule declaring ' . $property . ' is bound to the never-present toggle marker',
            $selector
        );
    }
}
$assert(count(array_filter($selectorsDeclaring($authorCss, 'position'), static fn (string $selector): bool => str_contains($selector, 'button') && str_contains($selector, $neverMatch))) >= 1, 'dropped toggle: the placement rule is still emitted, bound to the marker', $authorCss);
$assert(! preg_match('/(?:^|[,{}])\s*\.bar button\s*(?:\{|,)/', $authorCss), 'dropped toggle: no bare `.bar button` selector survives', $authorCss);
$assert(! preg_match('/\.bar button svg\s*\{/', $authorCss), 'dropped toggle: the toggle icon rule does not reach Core\'s open-button icon', $authorCss);
$assert(str_contains($authorCss, 'svg' . $neverMatch) || str_contains($authorCss, 'button' . $neverMatch . ' svg'), 'dropped toggle: the toggle icon rule is bound to the never-present marker', $authorCss);
// The navigation's own rules are untouched by the toggle handling.
$assert(str_contains($authorCss, 'blocks-engine-source-nav-'), 'dropped toggle: the nav rules keep their source-nav projection', $authorCss);
$assert(! str_contains($authorCss, $chromeExclusion), 'dropped toggle: an all-superseded selector needs no chrome exclusion', $authorCss);

// --- The selector also matched a kept control: keep it, exclude Core's chrome. ---

$shared = '.bar button{display:none;border:0;background:none;font-size:27px;color:#fff}'
    . '@media(max-width:1300px){.bar button{position:absolute;right:0;top:50%;transform:translateY(-50%)}}'
    . 'header button{font-family:inherit;cursor:pointer}';
// The form sits beside the bar, so `header button` is shared with its submit
// button while `.bar button` still names only the toggle.
[ $blocks, $authorCss ] = $transform($shared, '<header><div class="bar"><a class="brand" href="#home">Brand</a>' . $toggle . $nav . '</div><form action="/search"><input type="search" name="q" aria-label="Search"><button type="submit">Go</button></form></header>');
$assert(str_contains($blocks, '"overlayMenu":"mobile"'), 'mixed: navigation emits the native mobile overlay', $blocks);
$headerButtonSelectors = array_filter($selectorsDeclaring($authorCss, 'cursor'), static fn (string $selector): bool => str_starts_with($selector, 'header button'));
$assert(array() !== $headerButtonSelectors, 'mixed: the shared `header button` rule survives for the kept control', $authorCss);
foreach ( $headerButtonSelectors as $selector ) {
    $assert(str_contains($selector, $chromeExclusion), 'mixed: the shared `header button` rule is excluded from Core\'s open/close buttons', $selector);
    $assert(! str_contains($selector, $neverMatch), 'mixed: the shared `header button` rule is not bound to the never-present marker', $selector);
}
foreach ( $selectorsDeclaring($authorCss, 'position') as $selector ) {
    if ( str_contains($selector, '.bar button') ) {
        $assert(str_contains($selector, $neverMatch), 'mixed: the toggle-only `.bar button` placement rule is bound to the never-present marker', $selector);
    }
}

// --- Not a dropped toggle: author `button` rules project as before. ---

[ $blocks, $authorCss ] = $transform($css, $header('<button>Menu</button>' . $nav));
$assert(str_contains($blocks, '<!-- wp:button'), 'labelled button: the control stays a core/button', $blocks);
$assert(! str_contains($authorCss, $neverMatch) && ! str_contains($authorCss, $chromeExclusion), 'labelled button: no toggle handling is applied', $authorCss);
$assert(str_contains($authorCss, '> :where(.wp-block-button__link)'), 'labelled button: the `.bar button` rule still projects onto the native button link', $authorCss);

[ $blocks, $authorCss ] = $transform($css, $header($nav));
$assert(! str_contains($authorCss, $neverMatch) && ! str_contains($authorCss, $chromeExclusion), 'no toggle: author `button` rules are left alone', $authorCss);
$assert(str_contains($authorCss, '.bar button{'), 'no toggle: the bare `.bar button` rule survives unchanged', $authorCss);

if ( 0 < $failures ) {
    fwrite(STDERR, "navigation dropped toggle author type rules FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "navigation dropped toggle author type rules passed: {$passes} assertions\n";

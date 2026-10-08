<?php
declare(strict_types=1);

/**
 * Core paints the open responsive overlay white with black text unless the
 * navigation block declares its own colours. A source menu usually declares
 * its collapsed-panel paint in a media rule on the nav itself
 * (`@media(max-width:…){.nav nav{background:var(--navy)}}`). That rule is
 * projected onto the nav HOST, which at phone width is the toggle-sized box,
 * so the fixed overlay the links actually open inside kept Core's white sheet
 * while the author's link colour stayed white: an unreadable menu.
 *
 * The collapsed-state background and text colour the source resolves for the
 * menu at the mobile reference viewport are now restated on Core's open
 * overlay through a per-navigation marker. A menu whose source paints nothing
 * in that state is left alone.
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

// A toggle trunk already recognizes (ARIA wiring + icon-only SVG) beside a
// desktop link row. Below the breakpoint the row collapses behind the toggle
// and, when open, paints the author's panel colour.
$baseCss = ':root{--navy:#061b38}'
    . '.bar{display:flex;align-items:center;position:relative}'
    . '.bar nav{position:absolute;right:0;top:50%;transform:translateY(-50%);display:flex;align-items:center;gap:22px}'
    . '.bar nav a{font-size:18px;font-weight:700;text-decoration:none;color:rgba(255,255,255,.86)}'
    . '.bar button{display:none;border:0;background:none;font-size:27px;color:#fff}'
    . '@media(max-width:1300px){'
    . '.bar button{position:absolute;right:0;top:50%;transform:translateY(-50%);margin:0;padding:8px;display:flex;align-items:center;justify-content:center}'
    . '.bar nav{display:none;position:absolute;left:0;right:0;top:100%;transform:none;padding:20px;flex-direction:column;align-items:flex-start;z-index:50;__PANEL__}'
    . '.bar nav.open{display:flex}'
    . '.bar nav a{font-size:20px}'
    . '}';
$toggle = '<button aria-controls="site-menu" aria-expanded="false" aria-label="Menu"><svg viewBox="0 0 24 24" width="24" height="24"><path d="M3 6h18M3 12h18M3 18h18"/></svg></button>';
$nav = '<nav id="site-menu"><a href="#home">Home</a><a href="#about">About</a><a href="#work">Work</a><a href="#news">News</a><a href="#contact">Contact</a><a href="/fr.html">FR</a></nav>';

$transform = static function (string $css, string $body): array {
    $result = ( new HtmlTransformer() )->transform(
        '<style>' . $css . '</style>' . $body . '<main><section id="home"><h1>Hello</h1></section></main>',
        array()
    )->toArray();
    $afterAuthorCss = '';
    foreach ( ($result['assets'] ?? array()) as $asset ) {
        if ( str_contains((string) ($asset['path'] ?? ''), 'engine-support-after-author') ) {
            $afterAuthorCss .= (string) ($asset['content'] ?? '');
        }
    }

    return array( (string) ($result['serialized_blocks'] ?? ''), $afterAuthorCss );
};
$header = static fn (string $controls): string => '<header><div class="bar"><a class="brand" href="#home">Brand</a>' . $controls . '</div></header>';
$collapsed = static fn (string $panelDeclarations): string => str_replace('__PANEL__', $panelDeclarations, $baseCss);

/** @return list<string> */
$overlayMarkers = static function (string $blocks): array {
    preg_match_all('/blocks-engine-navigation-overlay-[0-9a-f]{12}/', $blocks, $matches);

    return array_values(array_unique($matches[0]));
};

/** @return list<string> the declaration blocks of every open-overlay rule carrying the marker */
$overlayRules = static function (string $css, string $marker): array {
    preg_match_all(
        '/\.wp-block-navigation\.blocks-engine-native-responsive-navigation\.' . preg_quote($marker, '/') . ' \.wp-block-navigation__responsive-container\.is-menu-open:not\(\.disable-default-overlay\)\{([^}]*)\}/',
        $css,
        $matches
    );

    return $matches[1];
};

$expectOverlay = static function (string $label, string $css, string $expectedDeclarations) use ($assert, $transform, $header, $toggle, $nav, $overlayMarkers, $overlayRules): void {
    [ $blocks, $afterAuthorCss ] = $transform($css, $header($toggle . $nav));
    $assert(str_contains($blocks, '"overlayMenu":"mobile"'), $label . ': navigation emits the native mobile overlay', $blocks);
    $markers = $overlayMarkers($blocks);
    $assert(1 === count($markers), $label . ': the navigation carries exactly one overlay marker', $blocks);
    foreach ( $markers as $marker ) {
        $rules = $overlayRules($afterAuthorCss, $marker);
        $assert(array( $expectedDeclarations ) === $rules, $label . ': the open overlay restates the collapsed-state paint', json_encode($rules) . ' in ' . $afterAuthorCss);
    }
};

$expectNoOverlay = static function (string $label, string $css) use ($assert, $transform, $header, $toggle, $nav, $overlayMarkers): void {
    [ $blocks, $afterAuthorCss ] = $transform(str_replace('padding:20px;', '', $css), $header($toggle . $nav));
    $assert(str_contains($blocks, '"overlayMenu":"mobile"'), $label . ': navigation still emits the native mobile overlay', $blocks);
    $assert(array() === $overlayMarkers($blocks), $label . ': no overlay marker is added without source paint', $blocks);
    $assert(! str_contains($afterAuthorCss, 'blocks-engine-navigation-overlay-'), $label . ': no overlay rule is emitted without source paint', $afterAuthorCss);
};

// --- Source paints the collapsed panel: the overlay takes that paint. ---

$expectOverlay('panel background through a CSS variable', $collapsed('background:var(--navy)'), 'background:#061b38!important');
$expectOverlay('panel background-color longhand', $collapsed('background-color:#102a43'), 'background:#102a43!important');
$expectOverlay('panel background and text colour', $collapsed('background:var(--navy);color:#fff'), 'background:#061b38!important;color:#fff!important');
$expectOverlay('text colour alone', $collapsed('color:#fff'), 'color:#fff!important');
$expectOverlay('variable fallback when the property is undefined', $collapsed('background:var(--panel,#222)'), 'background:#222!important');
$expectOverlay('authored !important is restated once', $collapsed('background:#333 !important'), 'background:#333!important');
$expectOverlay('translucent panel keeps its alpha', $collapsed('background:rgba(6,27,56,.95)'), 'background:rgba(6,27,56,.95)!important');
$expectOverlay('gradient panel is carried through the shorthand', $collapsed('background:linear-gradient(#061b38,#000)'), 'background:linear-gradient(#061b38,#000)!important');
// A later shorthand resets an earlier longhand; the collapsed state is unpainted.
$expectNoOverlay('shorthand reset after a longhand', $collapsed('background-color:#fff;background:none'));
// Unless the longhand is `!important`: an ordinary later shorthand cannot displace it.
$expectOverlay('important longhand survives a later shorthand reset', $collapsed('background-color:#061b38!important;background:none'), 'background:#061b38!important');
// The panel paint is stated once, unconditionally: it holds while collapsed too.
$expectOverlay('unconditional nav background', $collapsed('') . '.bar nav{background:#0b1f3a}', 'background:#0b1f3a!important');
// A desktop-only override does not describe the collapsed panel.
$expectOverlay('desktop-only override is ignored', $collapsed('background:var(--navy)') . '@media(min-width:1301px){.bar nav{background:#fff}}', 'background:#061b38!important');
// The panel may be a wrapper the list sits in rather than the nav itself.
[ $blocks, $afterAuthorCss ] = $transform(
    $collapsed('') . '@media(max-width:1300px){.bar nav .panel{background:#14213d;color:#fff}}',
    $header($toggle . '<nav id="site-menu"><div class="panel"><ul><li><a href="#home">Home</a></li><li><a href="#about">About</a></li><li><a href="#work">Work</a></li></ul></div></nav>')
);
$markers = $overlayMarkers($blocks);
$assert(1 === count($markers), 'list wrapper panel: the navigation carries one overlay marker', $blocks);
foreach ( $markers as $marker ) {
    $assert(array( 'background:#14213d!important;color:#fff!important' ) === $overlayRules($afterAuthorCss, $marker), 'list wrapper panel: the wrapper paint reaches the overlay', $afterAuthorCss);
}

// --- Source paints nothing usable: the navigation is left alone. ---

$expectNoOverlay('no panel paint', $collapsed(''));
$expectNoOverlay('transparent panel', $collapsed('background:transparent'));
$expectNoOverlay('background none', $collapsed('background:none'));
$expectNoOverlay('zero-alpha panel', $collapsed('background:rgba(0,0,0,0)'));
$expectNoOverlay('unresolved variable', $collapsed('background:var(--missing)'));
$expectNoOverlay('image panel cannot be relocated', $collapsed('background:url(panel.png) center/cover'));
$expectNoOverlay('inherited text colour says nothing', $collapsed('color:inherit'));

// An unpainted source can still own its open list's content box. Padding must
// survive the native list reset without manufacturing panel background/colour.
[ $blocks, $afterAuthorCss ] = $transform($collapsed(''), $header($toggle . $nav));
$markers = $overlayMarkers($blocks);
$assert(1 === count($markers), 'unpainted padded panel retains its content box', $blocks);
foreach ( $markers as $marker ) {
    $assert(array() === $overlayRules($afterAuthorCss, $marker), 'padding does not manufacture overlay paint', $afterAuthorCss);
    $assert(str_contains($afterAuthorCss, $marker . ' .wp-block-navigation__responsive-container.is-menu-open:not(.disable-default-overlay) .wp-block-navigation__container{padding:20px!important}'), 'open list retains source padding', $afterAuthorCss);
}

// Two menus with different collapsed paint get their own markers and rules.
$twoMenus = $transform(
    $collapsed('background:var(--navy)') . '@media(max-width:1300px){.bar nav.secondary{background:#fff;color:#000}}',
    $header($toggle . $nav . '<button aria-controls="second-menu" aria-expanded="false" aria-label="More"><svg viewBox="0 0 24 24" width="24" height="24"><path d="M3 6h18M3 12h18M3 18h18"/></svg></button><nav id="second-menu" class="secondary"><a href="/a">Alpha</a><a href="/b">Beta</a><a href="/c">Gamma</a></nav>')
);
$twoMarkers = $overlayMarkers($twoMenus[0]);
$assert(2 === count($twoMarkers), 'two menus: each collapsed paint gets its own marker', $twoMenus[0]);
$twoRules = array();
foreach ( $twoMarkers as $marker ) {
    $twoRules = array_merge($twoRules, $overlayRules($twoMenus[1], $marker));
}
sort($twoRules);
$assert(array( 'background:#061b38!important', 'background:#fff!important;color:#000!important' ) === $twoRules, 'two menus: each overlay restates its own paint', json_encode($twoRules));

if ( 0 < $failures ) {
    fwrite(STDERR, "navigation overlay source colors FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "navigation overlay source colors passed: {$passes} assertions\n";

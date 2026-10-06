<?php
declare(strict_types=1);

/**
 * A source navigation that collapses behind its menu toggle at an authored
 * breakpoint wider than core's 600px must collapse at the SOURCE breakpoint
 * once it is core/navigation with the native mobile overlay.
 *
 * core/navigation hard-codes the overlay switch at 600px: below it the open
 * button shows and the menu content hides, above it the content shows inline
 * and the button is gone. A source whose author CSS hides the menu and shows
 * the toggle below, say, 1000px therefore renders wrong between 600px and
 * 1000px: no open button, and the menu panel either permanently open or
 * permanently hidden, decided by a cascade tie between the author's
 * `display:none` and core's layout rules.
 *
 * The generated toggle-marker CSS now carries the source boundary: the host /
 * open-button restatement is scoped to `max-width:<source>` and a range rule
 * between 600px and the source boundary keeps the host visible, shows core's
 * open button and hides the closed menu container. A source boundary at or
 * below 600px, a toggle with no media-conditional evidence, and a print-only
 * hide leave the output unchanged.
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

$transformResult = static fn (string $html): array => ( new HtmlTransformer() )->transform($html, array())->toArray();
$cssOf = static function (array $result): string {
    return implode(
        "\n",
        array_map(
            static fn (array $asset): string => (string) ($asset['content'] ?? ''),
            array_values(array_filter(
                is_array($result['assets'] ?? null) ? $result['assets'] : array(),
                static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')
            ))
        )
    );
};

const HOST = '.wp-block-navigation.blocks-engine-list-navigation.blocks-engine-native-responsive-navigation.';

/**
 * Marker classes `blocks-engine-native-navigation-toggle-<hash>` present on
 * emitted navigation blocks, in document order.
 *
 * @return list<string>
 */
$toggleMarkers = static function (string $serialized): array {
    preg_match_all('/blocks-engine-native-navigation-toggle-[0-9a-f]{12}/', $serialized, $matches);
    return array_values(array_unique($matches[0]));
};

/**
 * The single-feature `@media(...)` prelude(s) that scope a marker's host
 * restatement rule (the range rule's two-feature prelude is excluded).
 */
$mediaScopesOf = static function (string $css, string $marker): array {
    preg_match_all('/@media\(([^(){}]*)\)\{' . preg_quote(HOST . $marker, '/') . '\{/', $css, $matches);
    return $matches[1];
};

$rangeRuleFor = static fn (string $marker, string $boundary): string => '@media(min-width:600px) and (max-width:' . $boundary . '){'
    . HOST . $marker . '{display:flex!important}'
    . HOST . $marker . '>.wp-block-navigation__responsive-container-open{display:flex!important}'
    . HOST . $marker . ' .wp-block-navigation__responsive-container:not(.is-menu-open){display:none!important}'
    . '}';

// A neutral toggle shape trunk already recognizes: an ARIA-wired icon button
// beside the <nav> it controls. Nothing here depends on glyph recognition.
$toggle = static fn (string $id = 'site-menu'): string => '<button aria-controls="' . $id . '" aria-expanded="false" aria-label="Menu"><svg viewBox="0 0 24 24" width="24" height="24"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>';
$menu = static fn (string $id = 'site-menu'): string => '<nav id="' . $id . '"><a href="/">Home</a><a href="/about">About</a><a href="/work">Work</a><a href="/contact">Contact</a></nav>';
$page = static fn (string $css, string $header): string => '<style>' . $css . '</style>' . $header . '<main><h1>Hello</h1><p>Body</p></main>';
$baseCss = 'body{margin:0}.bar{display:flex;align-items:center;gap:16px}.bar>button{display:none;border:0;background:none;padding:8px}.bar nav{display:flex;gap:16px}';
$header = static fn (string $controls): string => '<header><div class="bar"><a class="brand" href="/">Brand</a>' . $controls . '</div></header>';

$isNativeOverlay = static function (string $serialized, string $label) use ($assert): void {
    $assert(str_contains($serialized, '"overlayMenu":"mobile"'), $label . ': navigation emits the native mobile overlay', $serialized);
    $assert(str_contains($serialized, 'blocks-engine-native-responsive-navigation'), $label . ': native overlay marker is present', $serialized);
    $assert(! str_contains($serialized, '<!-- wp:button'), $label . ': the toggle is not emitted as a dead core/button', $serialized);
};

$collapsesAt = static function (array $result, string $boundary, string $label) use ($assert, $cssOf, $toggleMarkers, $mediaScopesOf, $rangeRuleFor, $isNativeOverlay): void {
    $serialized = (string) ($result['serialized_blocks'] ?? '');
    $css = $cssOf($result);
    $isNativeOverlay($serialized, $label);
    $markers = $toggleMarkers($serialized);
    $assert(1 === count($markers), $label . ': exactly one toggle marker is emitted', implode(',', $markers));
    $marker = $markers[0] ?? '';
    $assert(array( 'max-width:' . $boundary ) === $mediaScopesOf($css, $marker), $label . ': the toggle host restatement is scoped to the source boundary ' . $boundary, json_encode($mediaScopesOf($css, $marker)));
    $assert(str_contains($css, $rangeRuleFor($marker, $boundary)), $label . ': a range rule between 600px and ' . $boundary . ' shows the open button and hides the closed menu', $css);
    $assert(
        str_contains($css, '@media(max-width:599px){' . HOST . 'blocks-engine-native-responsive-navigation{display:flex!important}}')
            || str_contains($css, '@media(max-width:599px){.wp-block-navigation.blocks-engine-list-navigation.blocks-engine-native-responsive-navigation{display:flex!important}}'),
        $label . ': the phone-width host bridge is still emitted',
        $css
    );
};

$unchanged = static function (array $result, string $label) use ($assert, $cssOf, $toggleMarkers, $mediaScopesOf, $isNativeOverlay): void {
    $serialized = (string) ($result['serialized_blocks'] ?? '');
    $css = $cssOf($result);
    $isNativeOverlay($serialized, $label);
    $markers = $toggleMarkers($serialized);
    $assert(1 === count($markers), $label . ': exactly one toggle marker is emitted', implode(',', $markers));
    $marker = $markers[0] ?? '';
    $assert(array( 'max-width:599px' ) === $mediaScopesOf($css, $marker), $label . ': the toggle host restatement keeps core\'s 599px scope', json_encode($mediaScopesOf($css, $marker)));
    $assert(! str_contains($css, '@media(min-width:600px) and (max-width:'), $label . ': no range rule is emitted', $css);
};

// --- Source boundary wider than core's: max-width query hides the nav and shows the toggle. ---

$maxWidth = $transformResult($page(
    $baseCss . '@media(max-width:1000px){.bar>button{display:flex}.bar nav{display:none}.bar nav.open{display:flex}}',
    $header($toggle() . $menu())
));
$collapsesAt($maxWidth, '1000px', 'max-width:1000px');

// Mobile-first: the nav is hidden by default and shown from a min-width up.
$minWidth = $transformResult($page(
    'body{margin:0}.bar{display:flex;align-items:center;gap:16px}.bar>button{display:flex;border:0;background:none;padding:8px}.bar nav{display:none}'
    . '@media(min-width:1024px){.bar>button{display:none}.bar nav{display:flex;gap:16px}}',
    $header($toggle() . $menu())
));
$collapsesAt($minWidth, '1023px', 'min-width:1024px');

// em units resolve against the 16px media-query base.
$emUnits = $transformResult($page(
    $baseCss . '@media screen and (max-width:62.5em){.bar>button{display:flex}.bar nav{display:none}}',
    $header($toggle() . $menu())
));
$collapsesAt($emUnits, '1000px', 'max-width:62.5em');

// The hide lands on a wrapper around the menu, not on the menu element itself.
$wrapperHidden = $transformResult($page(
    'body{margin:0}.bar{display:flex;align-items:center;gap:16px}.bar>button{display:none;border:0;background:none;padding:8px}.menu-wrap{display:block}.menu-wrap nav{display:flex;gap:16px}'
    . '@media(max-width:900px){.bar>button{display:flex}.menu-wrap{display:none}}',
    $header($toggle() . '<div class="menu-wrap">' . $menu() . '</div>')
));
$collapsesAt($wrapperHidden, '900px', 'hidden wrapper');

// Two boundaries hide the nav (a tablet one and a phone one restating it);
// the widest one is where the source collapses.
$twoBoundaries = $transformResult($page(
    $baseCss . '@media(max-width:1300px){.bar>button{display:flex}.bar nav{display:none}}@media(max-width:720px){.bar>button{display:flex}.bar nav{display:none;flex-direction:column}}',
    $header($toggle() . $menu())
));
$collapsesAt($twoBoundaries, '1300px', 'two hiding boundaries');

// Two navigations on one page collapse at their own boundaries.
$twoNavs = $transformResult($page(
    'body{margin:0}.bar,.foot{display:flex;align-items:center;gap:16px}.bar>button,.foot>button{display:none;border:0;background:none;padding:8px}.bar nav,.foot nav{display:flex;gap:16px}'
    . '@media(max-width:1300px){.bar>button{display:flex}.bar nav{display:none}}'
    . '@media(max-width:900px){.foot>button{display:flex}.foot nav{display:none}}',
    $header($toggle() . $menu()) . '<footer><div class="foot"><a class="brand" href="/">Brand</a>' . $toggle('foot-menu') . '<nav id="foot-menu"><a href="/terms">Terms</a><a href="/privacy">Privacy</a><a href="/jobs">Jobs</a></nav></div></footer>'
));
$twoNavsSerialized = (string) ($twoNavs['serialized_blocks'] ?? '');
$twoNavsCss = $cssOf($twoNavs);
$twoNavsMarkers = $toggleMarkers($twoNavsSerialized);
$assert(2 === count($twoNavsMarkers), 'two navigations: two distinct toggle markers are emitted', implode(',', $twoNavsMarkers));
if ( 2 === count($twoNavsMarkers) ) {
    $scopes = array_map(static fn (string $marker): array => $mediaScopesOf($twoNavsCss, $marker), $twoNavsMarkers);
    sort($scopes);
    $assert(array( array( 'max-width:1300px' ), array( 'max-width:900px' ) ) === $scopes || array( array( 'max-width:900px' ), array( 'max-width:1300px' ) ) === $scopes, 'two navigations: each toggle restatement is scoped to its own boundary', json_encode($scopes));
    $assert(str_contains($twoNavsCss, '@media(min-width:600px) and (max-width:1300px){'), 'two navigations: the 1300px range rule is emitted', $twoNavsCss);
    $assert(str_contains($twoNavsCss, '@media(min-width:600px) and (max-width:900px){'), 'two navigations: the 900px range rule is emitted', $twoNavsCss);
}

// --- Unchanged: the source boundary is at or below core's, or there is no boundary. ---

$unchanged($transformResult($page(
    $baseCss . '@media(max-width:600px){.bar>button{display:flex}.bar nav{display:none}}',
    $header($toggle() . $menu())
)), 'max-width:600px');

$unchanged($transformResult($page(
    $baseCss . '@media(max-width:480px){.bar>button{display:flex}.bar nav{display:none}}',
    $header($toggle() . $menu())
)), 'max-width:480px');

$unchanged($transformResult($page(
    'body{margin:0}.bar{display:flex;align-items:center;gap:16px}.bar>button{display:flex;border:0;background:none;padding:8px}.bar nav{display:none}'
    . '@media(min-width:600px){.bar>button{display:none}.bar nav{display:flex;gap:16px}}',
    $header($toggle() . $menu())
)), 'min-width:600px');

$unchanged($transformResult($page(
    'body{margin:0}.bar{display:flex;align-items:center;gap:16px}.bar>button{display:flex;border:0;background:none;padding:8px}.bar nav{display:flex;gap:16px}',
    $header($toggle() . $menu())
)), 'no media rule');

$unchanged($transformResult($page(
    'body{margin:0}.bar{display:flex;align-items:center;gap:16px}.bar>button{display:flex;border:0;background:none;padding:8px}.bar nav{display:flex;gap:16px}@media print{.bar nav,.bar>button{display:none}}',
    $header($toggle() . $menu())
)), 'print-only hide');

$unchanged($transformResult($page(
    'body{margin:0}.bar{display:flex;align-items:center;gap:16px}.bar>button{display:flex;border:0;background:none;padding:8px}.bar nav{display:flex;gap:16px}@media(prefers-reduced-motion:reduce){.bar nav{transition:none}}@media(max-width:1000px){.bar{gap:8px}}',
    $header($toggle() . $menu())
)), 'media rules that never hide the menu');

if ( 0 < $failures ) {
    fwrite(STDERR, "navigation overlay source breakpoint FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "navigation overlay source breakpoint passed: {$passes} assertions\n";

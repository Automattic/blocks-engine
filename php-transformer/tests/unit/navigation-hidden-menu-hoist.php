<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$countBlocks = static function (array $blocks, string $name) use (&$countBlocks): int {
    $count = 0;
    foreach ( $blocks as $block ) {
        if ( ! is_array($block) ) {
            continue;
        }
        if ( $name === ($block['blockName'] ?? '') ) {
            ++$count;
        }
        $count += $countBlocks(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array(), $name);
    }

    return $count;
};

$projected = ( new HtmlTransformer() )->transform(
    '<style>.menu-toggle{width:42px!important;height:48px!important;padding:3px 0}</style>'
    . '<header><a href="/" class="brand">Northwind</a><button class="menu-toggle" aria-label="Menu" aria-expanded="false"><span></span><span></span></button><nav aria-label="Site" class="source-menu" style="display:none;font:normal 400 max(.5px,.0125 * (100cqw - 0px))/1.2 sans-serif"><a href="/">Home</a><a href="/work">Work</a></nav></header>'
)->toArray();
$projectedMarkup = (string) ($projected['serialized_blocks'] ?? '');
$projectedCss = implode("\n", array_map(
    static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '',
    is_array($projected['assets'] ?? null) ? $projected['assets'] : array()
));
$intrinsicProjected = ( new HtmlTransformer() )->transform(
    '<style>.menu-toggle{min-width:min-content!important;min-height:10px;padding:2px}</style>'
    . '<header><button class="menu-toggle" aria-label="Menu" aria-expanded="false"><span></span><span></span></button><nav aria-label="Site" style="display:none"><a href="/">Home</a></nav></header>'
)->toArray();
$intrinsicProjectedCss = implode("\n", array_map(
    static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '',
    is_array($intrinsicProjected['assets'] ?? null) ? $intrinsicProjected['assets'] : array()
));
$containerScaled = ( new HtmlTransformer() )->transform(
    '<style>.menu-label{font-size:max(.5px,.0410256 * (var(--scaling-factor) - var(--scrollbar-width)))}</style>'
    . '<nav class="menu"><div class="menu-label"><a href="/">Home</a></div><div class="menu-label"><a href="/work">Work</a></div></nav>'
)->toArray();
$containerScaledCss = implode("\n", array_map(
    static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '',
    is_array($containerScaled['assets'] ?? null) ? $containerScaled['assets'] : array()
));

$ambiguous = ( new HtmlTransformer() )->transform(
    '<header><button aria-label="Menu" aria-expanded="false"><span></span><span></span></button><nav aria-label="Main" style="display:none"><a href="/">Home</a><a href="/work">Work</a></nav><nav aria-label="Utility" style="display:none"><a href="/help">Help</a><a href="/contact">Contact</a></nav></header>'
)->toArray();
$ambiguousMarkup = (string) ($ambiguous['serialized_blocks'] ?? '');

$assertions = array(
    array(1 === $countBlocks($projected['blocks'] ?? array(), 'core/navigation'), 'a unique hidden navigation is emitted once at its visible menu control'),
    array(str_contains($projectedMarkup, '"overlayMenu":"mobile"'), 'a projected hidden navigation uses Core responsive overlay behavior'),
    array(1 === preg_match('/blocks-engine-native-navigation-toggle-[a-f0-9]{12}/', $projectedMarkup), 'a projected hidden navigation retains its native toggle geometry marker'),
    array(str_contains($projectedCss, 'width:42px!important;height:48px!important') && ! str_contains($projectedCss, '!important!important'), 'the visible source control box projects onto Core\'s responsive open button without duplicating source importance'),
    array(str_contains($intrinsicProjectedCss, 'min-width:44px!important') && str_contains($intrinsicProjectedCss, 'min-height:44px!important') && ! str_contains($intrinsicProjectedCss, '!important!important'), 'intrinsic source toggle dimensions receive a usable native control box without invalid declarations'),
    array(str_contains($projectedCss, '100vw'), 'container-relative inherited navigation typography is rebound after promotion'),
    array(1 === substr_count($containerScaledCss, 'font-size:max(.5px,.0410256 * (var(--scaling-factor) - var(--scrollbar-width)))'), 'container-scaled label typography is not replayed against a replacement navigation anchor'),
    array(! str_contains($projectedMarkup, '<!-- wp:button'), 'the superseded source menu control is not emitted as a dead button'),
    array(str_contains($projectedMarkup, 'Northwind') && str_contains($projectedMarkup, '"label":"Home"') && str_contains($projectedMarkup, '"label":"Work"'), 'projection preserves surrounding shell content and editable navigation destinations'),
    array(2 === $countBlocks($ambiguous['blocks'] ?? array(), 'core/navigation'), 'ambiguous hidden navigation candidates remain unprojected'),
    array(! str_contains($ambiguousMarkup, '"overlayMenu":"mobile"'), 'ambiguous candidates do not fabricate a responsive menu association'),
);

$capturedMenu = ( new HtmlTransformer() )->transform(
    '<header><a href="/">Northwind</a><button class="menu-toggle" id="menu-trigger" type="button" aria-label="Open menu" aria-controls="overlay" aria-expanded="false" data-blocks-engine-capture-disclosure-label="Open menu" data-blocks-engine-capture-dialog-trigger="overlay" aria-haspopup="dialog"><span></span><span></span><span></span></button>'
    . '<div class="data-blocks-engine-capture-dialog" role="dialog" aria-modal="true" hidden id="overlay" data-blocks-engine-capture-dialog-panel="overlay"><div class="menu is-open" id="menu"><button type="button" aria-label="Close menu">Close</button><nav aria-label="Site"><a href="https://example.test/social"><!-- destination note --><span>Social</span></a><a href="mailto:hello@example.test">Contact</a></nav></div></div><button type="button" hidden data-blocks-engine-capture-dialog-close="overlay">Close</button></header>'
    . '<dialog data-blocks-engine-captured-dialog="true" data-blocks-engine-triggers="menu-trigger"><nav><a href="/social">Social</a><a href="mailto:hello@example.test">Contact</a></nav></dialog>'
)->toArray();
$capturedMenuMarkup = (string) ($capturedMenu['serialized_blocks'] ?? '');
$assertions[] = array(str_contains($capturedMenuMarkup, '"overlayMenu":"always"') && str_contains($capturedMenuMarkup, 'Social') && str_contains($capturedMenuMarkup, 'Contact') && !str_contains($capturedMenuMarkup, '/captured-dialog') && !str_contains($capturedMenuMarkup, '<!-- wp:button'), 'a capture-wired CSS-bar trigger owns its hidden overlay navigation at every width');
$assertions[] = array(!str_contains($capturedMenuMarkup, '<!-- destination note -->') && !str_contains($capturedMenuMarkup, '&lt;!-- destination note'), 'navigation labels exclude HTML comments');
// A capture-wired trigger keeps its source viewport: one visible at every
// width opens an `always` overlay, while a mobile-first `md:hidden` trigger
// beside a `hidden md:flex` desktop menu stays a mobile-only overlay whose
// host leaves the layout from the source boundary up.
$capturedTriggerOverlay = static function (string $toggleClass, string $desktopNav): array {
    $result = ( new HtmlTransformer() )->transform(
        '<html><head><style>.hidden{display:none}.toggle{display:inline-flex;width:40px;height:40px}@media (min-width:48rem){.md\\:flex{display:flex}.md\\:hidden{display:none}}</style></head><body>'
        . '<header><a href="/">Northwind</a>' . $desktopNav
        . '<button type="button" class="' . $toggleClass . '" aria-label="Open menu" aria-controls="drawer" aria-expanded="false" aria-haspopup="menu" data-blocks-engine-capture-dialog-trigger="drawer"><span></span><span></span><span></span></button>'
        . '<nav id="drawer" aria-label="Mobile" class="md:hidden" hidden data-blocks-engine-capture-dialog-panel="drawer"><a href="/#services">Services</a><a href="/#contact">Contact</a><a href="tel:+15550100">Call</a></nav></header></body></html>'
    )->toArray();
    preg_match_all('/"overlayMenu":"(\w+)"/', (string) ($result['serialized_blocks'] ?? ''), $matches);
    $css = implode("\n", array_map(
        static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '',
        is_array($result['assets'] ?? null) ? $result['assets'] : array()
    ));

    return array( implode(',', $matches[1]), 1 === preg_match('/@media\(min-width:768px\)\{\.wp-block-navigation[^{}]*blocks-engine-native-navigation-toggle-[a-f0-9]{12}\{display:none!important\}\}/', $css) );
};
[ $alwaysOverlay, $alwaysHiddenAbove ] = $capturedTriggerOverlay('toggle', '');
$assertions[] = array('always' === $alwaysOverlay && ! $alwaysHiddenAbove, 'a capture-wired trigger visible at every width opens an always overlay: ' . $alwaysOverlay);
[ $mobileOnlyOverlay, $mobileOnlyHiddenAbove ] = $capturedTriggerOverlay('toggle md:hidden', '<nav aria-label="Main" class="hidden md:flex"><a href="/#services">Services</a><a href="/#contact">Contact</a></nav>');
$assertions[] = array(str_contains($mobileOnlyOverlay, 'mobile') && ! str_contains($mobileOnlyOverlay, 'always'), 'a mobile-only capture-wired trigger beside a visible desktop menu keeps a mobile overlay: ' . $mobileOnlyOverlay);
$assertions[] = array($mobileOnlyHiddenAbove, 'a mobile-only drawer leaves the layout from the source boundary up instead of laying its links out inline');

$capturedFallback = ( new HtmlTransformer() )->transform(
    '<header><button id="menu-trigger" type="button" aria-label="Open menu" aria-controls="overlay" aria-haspopup="dialog"><span></span><span></span><span></span></button><div id="overlay" hidden></div></header>'
    . '<dialog data-blocks-engine-captured-dialog="true" data-blocks-engine-triggers="menu-trigger"><nav><a href="/social">Social</a><a href="mailto:hello@example.test">Contact</a></nav></dialog>'
)->toArray();
$capturedFallbackMarkup = (string) ($capturedFallback['serialized_blocks'] ?? '');
$assertions[] = array(str_contains($capturedFallbackMarkup, '/captured-dialog') && str_contains($capturedFallbackMarkup, 'Social') && str_contains($capturedFallbackMarkup, 'Contact'), 'captured dialog content remains when no native navigation relationship is found');

$failures = array_map(
    static fn (array $assertion): string => $assertion[1],
    array_filter($assertions, static fn (array $assertion): bool => ! $assertion[0])
);
if ( array() !== $failures ) {
    fwrite(STDERR, "Navigation hidden menu hoist contract failed:\n" . implode("\n", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, 'Navigation hidden menu hoist contract passed: ' . count($assertions) . " assertions\n");

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
    '<style>.menu-toggle{width:42px;height:48px;padding:3px 0}</style>'
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

$ambiguous = ( new HtmlTransformer() )->transform(
    '<header><button aria-label="Menu" aria-expanded="false"><span></span><span></span></button><nav aria-label="Main" style="display:none"><a href="/">Home</a><a href="/work">Work</a></nav><nav aria-label="Utility" style="display:none"><a href="/help">Help</a><a href="/contact">Contact</a></nav></header>'
)->toArray();
$ambiguousMarkup = (string) ($ambiguous['serialized_blocks'] ?? '');

$assertions = array(
    array(1 === $countBlocks($projected['blocks'] ?? array(), 'core/navigation'), 'a unique hidden navigation is emitted once at its visible menu control'),
    array(str_contains($projectedMarkup, '"overlayMenu":"mobile"'), 'a projected hidden navigation uses Core responsive overlay behavior'),
    array(1 === preg_match('/blocks-engine-native-navigation-toggle-[a-f0-9]{12}/', $projectedMarkup), 'a projected hidden navigation retains its native toggle geometry marker'),
    array(str_contains($projectedCss, 'width:42px!important;height:48px!important'), 'the visible source control box projects onto Core\'s responsive open button'),
    array(str_contains($intrinsicProjectedCss, 'min-width:44px!important') && str_contains($intrinsicProjectedCss, 'min-height:44px!important') && ! str_contains($intrinsicProjectedCss, '!important!important'), 'intrinsic source toggle dimensions receive a usable native control box without invalid declarations'),
    array(str_contains($projectedCss, '100vw'), 'container-relative inherited navigation typography is rebound after promotion'),
    array(! str_contains($projectedMarkup, '<!-- wp:button'), 'the superseded source menu control is not emitted as a dead button'),
    array(str_contains($projectedMarkup, 'Northwind') && str_contains($projectedMarkup, '"label":"Home"') && str_contains($projectedMarkup, '"label":"Work"'), 'projection preserves surrounding shell content and editable navigation destinations'),
    array(2 === $countBlocks($ambiguous['blocks'] ?? array(), 'core/navigation'), 'ambiguous hidden navigation candidates remain unprojected'),
    array(! str_contains($ambiguousMarkup, '"overlayMenu":"mobile"'), 'ambiguous candidates do not fabricate a responsive menu association'),
);

$failures = array_map(
    static fn (array $assertion): string => $assertion[1],
    array_filter($assertions, static fn (array $assertion): bool => ! $assertion[0])
);
if ( array() !== $failures ) {
    fwrite(STDERR, "Navigation hidden menu hoist contract failed:\n" . implode("\n", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, 'Navigation hidden menu hoist contract passed: ' . count($assertions) . " assertions\n");

<?php
/**
 * A `<nav>` that holds its items as inline-block `<div>`s (Squarespace chrome:
 * `.nav-wrapper nav>div{display:inline-block}`) packs them with the
 * text-align it inherits, exactly like a list of inline `li`s. core/navigation
 * renders a flex row that defaults to flex-start, so the inherited packing
 * must travel as `layout.justifyContent` or a right-aligned header menu and a
 * centered footer link land at the leading edge (#5862).
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$navigations = static function (array $blocks) use (&$navigations): array {
    $found = array();
    foreach ( $blocks as $block ) {
        if ( 'core/navigation' === ($block['blockName'] ?? '') ) $found[] = $block;
        array_push($found, ...$navigations($block['innerBlocks'] ?? array()));
    }
    return $found;
};

foreach ( array( 'right' => 'right', 'center' => 'center', 'left' => null ) as $alignment => $expected ) {
    $html = '<!doctype html><html><head><meta charset="utf-8"><title>Div nav</title>'
        . '<style>.header-inner{display:table;width:100%}#logoWrapper,#headerNav{display:table-cell;vertical-align:middle}'
        . '#logoWrapper{width:140px}#headerNav{text-align:' . $alignment . '}.nav-wrapper nav>div{display:inline-block;vertical-align:middle}'
        . '.nav-wrapper nav>div>a{display:block;padding:.75em 1em}</style></head>'
        . '<body><header id="header"><div class="header-inner"><div id="logoWrapper"><h1><a href="/">Brand</a></h1></div>'
        . '<div id="headerNav"><div id="mainNavWrapper" class="nav-wrapper"><nav id="mainNavigation">'
        . '<div class="collection"><a href="/">Home</a></div><div class="collection"><a href="/news">News</a></div>'
        . '<div class="collection"><a href="/read-me">Read Me</a></div>'
        . '</nav></div></div></div></header><main><p>Body</p></main></body></html>';
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $found = $navigations($result['blocks']);
    if ( 1 !== count($found) || $expected !== ($found[0]['attrs']['layout']['justifyContent'] ?? null) ) {
        fwrite(STDERR, 'FAIL: inherited ' . $alignment . ' packing of a div-item navigation: ' . json_encode(array_map(static fn (array $block): array => $block['attrs'] ?? array(), $found)) . PHP_EOL);
        exit(1);
    }
}

// Block-level div items stack instead of forming an inline row, so the
// inherited text-align says nothing about their packing.
$stacked = str_replace('.nav-wrapper nav>div{display:inline-block;', '.nav-wrapper nav>div{display:block;', $html);
$stacked = str_replace('#headerNav{text-align:left}', '#headerNav{text-align:right}', $stacked);
$found = $navigations((new HtmlTransformer())->transform($stacked)->toArray()['blocks']);
if ( 1 !== count($found) || isset($found[0]['attrs']['layout']['justifyContent']) ) {
    fwrite(STDERR, 'FAIL: block div items took inherited packing: ' . json_encode(array_map(static fn (array $block): array => $block['attrs'] ?? array(), $found)) . PHP_EOL);
    exit(1);
}

// The desktop packing is media-scoped, and a nearer wrapper restates its own
// text-align under a different (tablet drawer) condition. That restatement
// covers only its own range: the desktop stack still packs right, and the
// nearer tablet rule is replayed after it so it wins where the two overlap.
$responsive = str_replace(
    '#headerNav{text-align:left}',
    '@media only screen and (min-width:641px){#headerNav{text-align:right}}'
        . '@media (min-width:768px) and (max-width:1023px){#mainNavWrapper{text-align:left}}',
    $html
);
$result = (new HtmlTransformer())->transform($responsive)->toArray();
$css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
$desktop = strpos($css, '@media only screen and (min-width:641px){.wp-block-navigation.blocks-engine-navigation-packing-');
$tablet = strpos($css, '@media (min-width:768px) and (max-width:1023px){.wp-block-navigation.blocks-engine-navigation-packing-');
if ( false === $desktop || false === $tablet || $tablet < $desktop
    || 1 !== preg_match('/@media only screen and \(min-width:641px\)\{[^{}]+\{justify-content:flex-end!important\}\}/', $css)
    || 1 !== preg_match('/@media \(min-width:768px\) and \(max-width:1023px\)\{[^{}]+\{justify-content:flex-start!important\}\}/', $css) ) {
    fwrite(STDERR, 'FAIL: media-scoped packing hidden by a nearer conditional restatement' . PHP_EOL);
    exit(1);
}

echo "Inherited div-item navigation alignment passed\n";

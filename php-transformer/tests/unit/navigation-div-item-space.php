<?php
/**
 * A menu of inline-block `<div>` items (Squarespace chrome:
 * `.nav-wrapper nav>div{display:inline-block}`) separates its items with the
 * whitespace between `</div>` and `<div>`, one space of the menu's font.
 * core/navigation renders them as flex items with nothing between, so in a
 * right-packed header every item drifted one space further right per item
 * after it (#5862). The space comes back on the rendered items the authored
 * rule reached through their native subject, even when that selector also
 * matches a copy of the menu that did not become core/navigation.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$page = static function (string $separator): string {
    $items = implode($separator, array(
        '<div class="collection"><a href="/">Home</a></div>',
        '<div class="collection"><a href="/news">News</a></div>',
        '<div class="collection"><a href="/read-me">Read Me</a></div>',
    ));
    return '<!doctype html><html><head><meta charset="utf-8"><title>Div nav</title>'
        . '<style>body{font:15px/1.5 serif}#headerNav{text-align:right;white-space:nowrap}'
        . 'body:not(.force-mobile-nav) .nav-wrapper nav>div{display:inline-block;vertical-align:middle;margin:0}'
        . 'body:not(.force-mobile-nav) .nav-wrapper nav>div>a{display:block;padding:.75em 1em}</style></head>'
        . '<body><header id="header"><div id="headerNav"><div id="mainNavWrapper" class="nav-wrapper"><nav id="mainNavigation">'
        . $items . '</nav></div></div></header><main><p>Body</p></main></body></html>';
};

$carrier = static function (string $html): array {
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
    preg_match('/(?:^|[}\n])([^{}]*\.wp-block-navigation-item[^{}]*:not\(:last-child\)::after)\{content:"\\\\a0"\}/', $css, $space);
    preg_match('/(?:^|[}\n])([^{}]*\.wp-block-navigation-item[^{}]*:not\(:last-child\))\{display:inline-flex\}/', $css, $display);
    preg_match('/blocks-engine-navigation-instance-[a-f0-9]{12}-[0-9]+/', (string) $result['serialized_blocks'], $instance);
    return array( $space[1] ?? '', $display[1] ?? '', $instance[0] ?? '' );
};

[ $space, $display, $instance ] = $carrier($page("\n  "));
if ( '' === $instance || ! str_contains($space, '.wp-block-navigation.' . $instance . ' ') || $display . '::after' !== $space ) {
    fwrite(STDERR, 'FAIL: space-separated div items lost the inter-item space: ' . json_encode(array( $space, $display, $instance )) . PHP_EOL);
    exit(1);
}

// `</div><div>` renders no space, so there is none to give back.
[ $space, $display ] = $carrier($page(''));
if ( '' !== $space || '' !== $display ) {
    fwrite(STDERR, 'FAIL: abutting div items gained a space: ' . $space . PHP_EOL);
    exit(1);
}

echo "Navigation div item space passed\n";

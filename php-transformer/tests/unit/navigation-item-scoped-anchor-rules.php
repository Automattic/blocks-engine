<?php
declare(strict_types=1);

/**
 * `li.item .link` rules follow the anchor class onto the rendered anchor.
 *
 * core/navigation-link puts the source anchor's class on its `<li>` next to
 * the source item's class, and renders its own
 * `.wp-block-navigation-item__content` anchor. A rule that reached the source
 * anchor through its own list item (`.navigation-item .item-name`) then needs
 * an `.item-name` descendant of `.navigation-item`, which no longer exists, so
 * the menu links lost their padding and ran together. The item compound folds
 * into the projected item selector.
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

$assets = static function (string $css, string $items): string {
    $result = ( new HtmlTransformer() )->transform(
        '<style>' . $css . '</style><header><nav class="navigation-body"><ul class="navigation-list">'
        . $items . '</ul></nav></header><main><p>Body</p></main>',
        array()
    )->toArray();

    return implode("\n", array_map(
        static fn (array $asset): string => (string) ($asset['content'] ?? ''),
        is_array($result['assets'] ?? null) ? $result['assets'] : array()
    ));
};

$items = '<li class="navigation-item"><a href="/" class="item-name">Home</a></li>'
    . '<li class="navigation-item"><a href="/about" class="item-name">About</a></li>'
    . '<li class="navigation-item"><a href="/contact" class="item-name">Contact</a></li>';

// BaseKit: the item-scoped rule beats a later bare anchor-class rule.
$basekit = $assets(
    '.navigation-item .item-name{display:block;padding:14px}'
    . '.navigation-item .item-name{padding:5px 15px}'
    . '.item-name{padding:16px 20px}',
    $items
);
$assert(
    str_contains($basekit, '.wp-block-navigation-item.navigation-item.item-name>.wp-block-navigation-item__content{padding:5px 15px}'),
    'the winning item-scoped padding lands on the rendered anchor',
    $basekit
);
$assert(
    str_contains($basekit, '.wp-block-navigation-item.navigation-item.item-name>.wp-block-navigation-item__content{display:block}'),
    'the item-scoped display lands on the rendered anchor',
    $basekit
);
$assert(
    ! str_contains($basekit, '__content{padding:14px}') && ! str_contains($basekit, '__content{padding:16px 20px}'),
    'losing padding declarations are not promoted over the source winner',
    $basekit
);

$hostScoped = $assets(
    '.navigation-body .navigation-item .item-name{padding:5px 15px}',
    $items
);
$assert(
    str_contains($hostScoped, '.navigation-body .wp-block-navigation-item.navigation-item.item-name>.wp-block-navigation-item__content{padding:5px 15px}'),
    'a navigation-host ancestor stays in front of the folded item compound',
    $hostScoped
);

$outerItem = $assets(
    '.navigation-item .item-name{padding:5px 15px}',
    '<li class="navigation-item"><a href="/" class="item-name">Home</a>'
    . '<ul><li class="sub-item"><a href="/team" class="item-name">Team</a></li></ul></li>'
    . '<li class="navigation-item"><a href="/about" class="item-name">About</a></li>'
);
$assert(
    ! str_contains($outerItem, '.navigation-item.item-name>.wp-block-navigation-item__content{padding'),
    'an item compound a nested anchor only reaches through its outer item is not folded',
    $outerItem
);

$foreignAncestor = $assets(
    '.site-footer .item-name{padding:5px 15px}',
    $items
);
$assert(
    ! str_contains($foreignAncestor, '.wp-block-navigation-item__content{padding:5px 15px}'),
    'an ancestor that is neither the item nor a navigation host still fails closed',
    $foreignAncestor
);

if ( 0 < $failures ) {
    fwrite(STDERR, "navigation item-scoped anchor rules FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "navigation item-scoped anchor rules passed: {$passes} assertions\n";

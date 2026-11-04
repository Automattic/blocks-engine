<?php
declare(strict_types=1);

/**
 * The WAI-ARIA menu pattern marks each menu item's `li` with `role="none"`
 * and its link with `role="menuitem"`. `none` removes only the `li`'s own
 * semantics; the item still holds its link, so the menu converts to one
 * core/navigation like the same list without roles, also where a parent's
 * CSS layout places it. A link-less `li` with `role="none"` stays
 * decoration.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if (! $condition) {
        throw new RuntimeException($message . ('' === $detail ? '' : "\n" . substr($detail, 0, 2000)));
    }
};

$item = static fn(string $href, string $label, string $submenu = ''): string => '<li role="none"><a href="' . $href . '" class="menu-item" role="menuitem"' . ('' === $submenu ? '' : ' aria-haspopup="true"') . '>' . $label . '</a>' . $submenu . '</li>';
$menu = '<div id="menu" aria-label="Main menu"><ul role="menu">'
    . $item('/', 'Home') . $item('/about/', 'About') . '<li role="none" class="divider"></li>'
    . $item('/models/', 'Models', '<ul role="menu">' . $item('/models/fiat/', 'Fiat') . $item('/models/ford/', 'Ford') . '</ul>')
    . '</ul></div>';
// A parent's CSS grid (a drawer placing the menu in a grid area) makes the
// menu an author-laid-out child; its ARIA roles still claim it as navigation.
$drawer = '<style>#drawer{display:grid;grid-template-areas:"close" "menu"}</style><div id="drawer"><button class="closer">Close</button>' . $menu . '</div>';
foreach (array('flow' => $menu, 'grid parent' => $drawer) as $layout => $html) {
    $markup = (new HtmlTransformer())->transform('<body><header>' . $html . '</header><main><p>Body.</p></main></body>')->toArray()['serialized_blocks'];
    $assert(1 === substr_count($markup, '<!-- wp:navigation '), "An ARIA menu converts to one navigation ($layout).", $markup);
    $assert(1 === substr_count($markup, '<!-- wp:navigation-submenu '), "Its nested ARIA menu converts to a submenu ($layout).", $markup);
    $assert(4 === substr_count($markup, '<!-- wp:navigation-link '), "Each menu item converts to a navigation link ($layout).", $markup);
    $assert(! str_contains($markup, 'wp:button {"className":"menu-item'), "Menu items do not fall back to buttons ($layout).", $markup);
}

echo "navigation-aria-menu-pattern contract passed\n";

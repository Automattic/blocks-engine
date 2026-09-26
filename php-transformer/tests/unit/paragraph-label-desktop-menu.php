<?php
declare(strict_types=1);

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
    fwrite(STDERR, 'FAIL: ' . $message . ('' === $detail ? '' : ' - ' . $detail) . PHP_EOL);
};

$items = '';
foreach ( array(
    array( 'Home', '/' ),
    array( 'Journal', '/journal' ),
    array( 'Shop', '/shop' ),
    array( 'Events', '/events' ),
    array( 'Contact', '/contact' ),
    array( 'About', '/about' ),
) as $item ) {
    $items .= '<li class="item"><a href="' . $item[1] . '"><div class="pad"><div class="label-wrap"><p class="label">' . $item[0] . '</p></div></div></a></li>';
}
$menu = '<site-menu id="desktop-menu" class="desktop-menu" style="visibility:inherit">'
    . '<nav aria-label="Site"><ul class="items">' . $items . '</ul><div class="more"><ul class="submenu"></ul></div></nav>'
    . '</site-menu>';
$layout = '<style>.menu-bar{display:flex;flex-direction:row;align-items:center}.items{display:flex;flex-direction:row;gap:12px;margin:0;padding:0}.label{margin:0;line-height:30px}</style>'
    . '<header class="site-header"><div class="menu-bar">' . $menu . '</div></header>';
$deep = $layout;
for ( $depth = 0; $depth < 16; ++$depth ) {
    $deep = '<div class="shell">' . $deep . '</div>';
}

$transform = static function (string $html): string {
    $result = ( new HtmlTransformer() )->transform($html)->toArray();

    return (string) ($result['serialized_blocks'] ?? '');
};

$shallow = $transform($layout);
$deepMarkup = $transform($deep);

foreach ( array( 'shallow layout wrapper' => $shallow, 'deep layout wrapper' => $deepMarkup ) as $name => $markup ) {
    $assert(1 <= substr_count($markup, '<!-- wp:navigation '), $name . ' materializes the paragraph-labelled menu as core/navigation', $markup);
    $assert(6 === substr_count($markup, 'wp:navigation-link'), $name . ' emits one navigation-link per menu item', $markup);
    $assert(str_contains($markup, '"label":"Home"') && str_contains($markup, '"label":"Journal"') && str_contains($markup, '"label":"About"'), $name . ' keeps paragraph-wrapped labels as navigation-link labels', $markup);
    $assert(str_contains($markup, 'id="desktop-menu"'), $name . ' keeps the menu host identity CSS can address', $markup);
    $assert(! str_contains($markup, '<p class="label">') && ! str_contains($markup, '<p class=\\"label\\">'), $name . ' does not leave menu labels as companion-attribute HTML', $markup);
}

$component = $transform(
    '<my-pricing><div class="tier"><h3>Basic</h3><p>$9</p></div><div class="tier"><h3>Pro</h3><p>$19</p></div><div class="tier"><h3>Max</h3><p>$49</p></div></my-pricing>'
);
$assert(! str_contains($component, '<!-- wp:navigation '), 'a custom element that is not a navigation host stays out of core/navigation', $component);

if ( 0 < $failures ) {
    fwrite(STDERR, "Paragraph-label desktop menu: {$failures} failed, {$passes} passed\n");
    exit(1);
}

echo "Paragraph-label desktop menu passed: {$passes} assertions\n";

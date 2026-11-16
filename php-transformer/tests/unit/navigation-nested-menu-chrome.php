<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// The menu is only one part of this landmark. Its responsive box must not
// disappear when the brand and call to action remain visible beside it.
$html = '<style>.nav-inner{display:flex;justify-content:space-between}.nav-links{display:flex;gap:24px}@media(max-width:800px){.nav-links{display:none}}</style>'
    . '<nav class="site-nav"><div class="nav-inner"><a class="brand" href="/">Example <em>Studio</em></a>'
    . '<div class="nav-links"><a href="/about">About</a><a href="/work">Work</a></div>'
    . '<a href="/contact">Contact us</a></div></nav>';
$result = (new HtmlTransformer())->transform($html)->toArray();
$markup = $result['serialized_blocks'];
if (!str_contains($markup, '"tagName":"nav"')
    || !preg_match('/<!-- wp:navigation .*?"className":"[^"]*nav-links/', $markup)
    || !str_contains($markup, 'nav-inner')) {
    throw new RuntimeException('Navigation chrome must retain the nested menu and its responsive layout owner: ' . $markup);
}
// A normal submenu also has fewer links than its landmark, but does not
// independently disappear at a breakpoint. It must retain native conversion.
$submenu = (new HtmlTransformer())->transform(
    '<nav><a href="/">Home</a><div><a href="/shop">Shop</a>'
    . '<ul><li><a href="/new">New</a></li><li><a href="/sale">Sale</a></li></ul>'
    . '</div><a href="/about">About</a></nav>'
)->toArray()['serialized_blocks'];
if ( ! str_contains($submenu, '<!-- wp:navigation-submenu ') ) {
    throw new RuntimeException('Ordinary submenus must not be mistaken for independent visibility owners: ' . $submenu);
}
// A landmark's direct menu is already part of native navigation projection.
// The nested-layout guard must not turn it (or its action links) into groups.
$direct = (new HtmlTransformer())->transform(
    '<style>.nav-links{display:none}@media(min-width:900px){.nav-links{display:flex}}</style>'
    . '<nav class="nav"><a class="brand" href="/">Studio</a>'
    . '<div class="nav-links"><a href="/about">About</a><a href="/work">Work</a></div>'
    . '<div class="nav-actions"><a href="/browse">Browse</a><a href="/install">Install</a></div></nav>'
)->toArray()['serialized_blocks'];
if ( ! str_contains($direct, '<!-- wp:navigation ')
    || str_contains($direct, '"tagName":"nav"') ) {
    throw new RuntimeException('Direct native menu projection must not be rejected by the nested-layout guard: ' . $direct);
}
echo "Nested navigation menu chrome passed\n";

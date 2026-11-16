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
echo "Nested navigation menu chrome passed\n";

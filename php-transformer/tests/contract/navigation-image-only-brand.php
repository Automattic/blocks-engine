<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$cases = array(
    'navbar-header logo' => array(
        '<header><div class="navbar-header"><a href="/index.html"><img alt="DIRTEC - Diretoria" src="/media/logo.png"></a></div></header>',
        0, 0, true,
    ),
    'empty alt logo' => array(
        '<header><div class="navbar-header"><a href="/index.html"><img alt="" src="/media/logo.png"></a></div></header>',
        0, 0, true,
    ),
    'image-only landmark' => array(
        '<nav><a href="/index.html"><picture><img alt="Logo" src="/media/logo.png"></picture></a><a href="/other/"><img alt="Other" src="/media/other.png"></a></nav>',
        0, 0, true,
    ),
    'navbar text menu' => array(
        '<div class="navbar"><a href="/">Home</a><a href="/one/">One</a></div>',
        1, 2, false,
    ),
    'centred logo menu (#3116)' => array(
        '<nav aria-label="Main"><ul class="root menu-picture"><li class="active"><a href="/">Home</a></li><li><a href="/one/">One</a></li><li class="item-picture"><a href="/" class="menu-picture-link"><picture><img src="/media/logo.png"></picture></a></li><li><a href="/two/">Two</a></li></ul></nav>',
        1, 4, false,
    ),
);

foreach ($cases as $name => [$html, $navigationCount, $linkCount, $linkedImage]) {
    $markup = (new HtmlTransformer())->transform($html)->toArray()['serialized_blocks'];
    $assert = static function (bool $condition, string $message) use ($name, $markup): void {
        if (! $condition) {
            throw new RuntimeException($name . ': ' . $message . "\n" . $markup);
        }
    };
    $assert($navigationCount === substr_count($markup, '<!-- wp:navigation '), 'Navigation ownership must depend on link content.');
    $assert($linkCount === substr_count($markup, '<!-- wp:navigation-link '), 'Keep the expected menu items.');
    if ($linkedImage) {
        $assert(str_contains($markup, '<!-- wp:image '), 'Keep a native image block.');
        $assert(1 === preg_match('~<a\b[^>]*href="/index.html"[^>]*>\s*<img\b[^>]*src="/media/logo.png"~', $markup), 'Keep the logo asset and its home link.');
    } elseif (str_contains($html, '<img')) {
        $assert(str_contains($markup, '\u003cimg src=\u0022/media/logo.png\u0022\u003e'), 'Keep the centred logo in the real menu.');
        foreach (array('Home', 'One', 'Two') as $label) {
            $assert(str_contains($markup, '"label":"' . $label . '"'), 'Keep text menu labels.');
        }
    }
}

echo "navigation-image-only-brand contract passed\n";

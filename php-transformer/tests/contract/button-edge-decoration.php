<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// RichText cannot hold a text-free element, so a painted indicator at the
// button's edge must keep its author box on the native link's pseudo-element.
$source = file_get_contents(dirname(__DIR__) . '/fixtures/button-edge-decoration.html');
$result = (new HtmlTransformer())->transform($source)->toArray();
$css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
if ( 1 !== preg_match('/class="wp-block-button[^"]*\b(blocks-engine-button-decoration-[a-f0-9]+)\b/', (string) ($result['serialized_blocks'] ?? ''), $marker) ) {
    throw new RuntimeException('The button carries its edge decoration marker.');
}
$link = preg_quote(':where(.wp-block-button.' . $marker[1] . ')>:where(.wp-block-button__link)', '/');
if ( 1 !== preg_match('/' . $link . '[^{,]*::after\{content:""/', $css)
    || 1 !== preg_match('/' . $link . '[^{,]*::after\{width:16px;height:16px;border:1px solid #94a3b8;border-radius:9999px/', $css) ) {
    throw new RuntimeException('The indicator paints the native link\'s ::after with its author box.');
}
// A carousel arrow's painted circle sits before its icon, with the source's
// formatting whitespace inside it; it is still a text-free decoration.
$arrow = (new HtmlTransformer())->transform('<style>.arrow{position:relative;width:60px;height:60px;border-radius:50%;overflow:hidden}.arrow-bg{position:absolute;inset:0;background-color:#1d2e4d}</style><button class="arrow" aria-label="Next"><div class="arrow-bg">
  </div><svg viewBox="0 0 44 18"><path d="M1 9h40" stroke="#fff"/></svg></button>')->toArray();
$arrowCss = implode("\n", array_column(array_filter($arrow['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
if ( 1 !== preg_match('/class="wp-block-button[^"]*\b(blocks-engine-button-decoration-[a-f0-9]+)\b/', (string) ($arrow['serialized_blocks'] ?? ''), $arrowMarker)
    || 1 !== preg_match('/' . preg_quote(':where(.wp-block-button.' . $arrowMarker[1] . ')>:where(.wp-block-button__link)', '/') . '[^{,]*::before\{[^}]*background-color:#1d2e4d/', $arrowCss) ) {
    throw new RuntimeException('A whitespace-formatted leading circle paints the native link\'s ::before.');
}
echo "Button edge decoration contract passed.\n";

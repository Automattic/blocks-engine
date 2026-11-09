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
echo "Button edge decoration contract passed.\n";

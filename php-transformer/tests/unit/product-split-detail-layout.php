<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A grid with a cover and one purchase panel is a product, but its cover is
// not part of the region a shop provider replaces with its product loop.
// Exercise the public compiler.
$collections = static function (string $html): array {
    $result = (new HtmlTransformer())->transform($html)->toArray();
    return array_values(array_filter($result['fallbacks'] ?? array(), static fn (array $finding): bool => 'commerce_product_grid_detected' === ($finding['reason'] ?? null)));
};
$html = '<main><div class="product-grid" style="display:grid"><div class="cover"><img src="/cover.jpg" alt="Book cover"></div><div class="details"><h2>A useful book</h2><span class="price">$8.99</span><button>Buy now</button></div></div></main>';
$found = $collections($html);
$region = (string) ($found[0]['binding']['search_block_markup'] ?? '');
if (1 !== count($found) || 1 !== count($found[0]['products'] ?? array())) {
    throw new RuntimeException('A split detail layout must still report its product.');
}
if (!str_contains($region, 'Buy now') || str_contains($region, 'cover.jpg')) {
    throw new RuntimeException('The provider-replaceable region must hold the purchase panel, not discard the sibling cover.');
}
// A neighboring editorial panel is not a product's unclaimed cover: the
// grid itself remains the collection region.
$editorial = str_replace('<div class="cover">', '<div class="cover"><p>Other news</p>', $html);
$found = $collections($editorial);
if (1 !== count($found) || !str_contains((string) ($found[0]['binding']['search_block_markup'] ?? ''), 'Other news')) {
    throw new RuntimeException('An editorial sibling must not narrow the product collection region.');
}
echo "product-split-detail-layout: ok\n";

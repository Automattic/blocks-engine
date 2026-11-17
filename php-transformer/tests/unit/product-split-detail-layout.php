<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A grid with a cover and one purchase panel is a product detail layout, not
// a collection: a provider loop in place of the grid's children would drop
// the cover. Exercise the public compiler.
$transform = static fn (string $html): array => (new HtmlTransformer())->transform($html)->toArray();
$collections = static fn (array $result): array => array_values(array_filter($result['fallbacks'] ?? array(), static fn (array $finding): bool => 'commerce_product_grid_detected' === ($finding['reason'] ?? null)));
$html = '<main><div class="product-grid" style="display:grid"><div class="cover"><img src="/cover.jpg" alt="Book cover"></div><div class="details"><h2>A useful book</h2><span class="price">$8.99</span><button>Buy now</button></div></div></main>';
$result = $transform($html);
if (array() !== $collections($result)) {
    throw new RuntimeException('A split detail layout must not be claimed as a replaceable product collection.');
}
if (!str_contains($result['serialized_blocks'], 'cover.jpg') || !str_contains($result['serialized_blocks'], 'Buy now')) {
    throw new RuntimeException('The split detail layout must keep its cover beside its purchase panel.');
}
// A neighboring editorial panel is not a product's unclaimed cover: the
// grid itself remains the collection region.
$editorial = str_replace('<div class="cover">', '<div class="cover"><p>Other news</p>', $html);
$found = $collections($transform($editorial));
if (1 !== count($found) || !str_contains((string) ($found[0]['binding']['search_block_markup'] ?? ''), 'Other news')) {
    throw new RuntimeException('An editorial sibling must not decline the product collection region.');
}
echo "product-split-detail-layout: ok\n";

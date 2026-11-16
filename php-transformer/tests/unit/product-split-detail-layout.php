<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A grid with a cover and a purchase panel is one detail layout, not a
// collection whose only item is the panel. Exercise the public compiler.
$html = '<main><div class="product-grid" style="display:grid"><div class="cover"><img src="/cover.jpg" alt="Book cover"></div><div class="details"><h2>A useful book</h2><span class="price">$8.99</span><button>Buy now</button></div></div></main>';
$result = (new HtmlTransformer())->transform($html)->toArray();
$found = false;
foreach ($result['fallbacks'] ?? array() as $diagnostic) {
    if ('commerce_product_grid_detected' !== ($diagnostic['reason'] ?? null)) {
        continue;
    }
    $found = true;
}
if ($found || !str_contains($result['serialized_blocks'], 'cover.jpg') || !str_contains($result['serialized_blocks'], 'Buy now')) {
    throw new RuntimeException('A split detail layout must retain its media and controls, not declare a partial product collection.');
}
// A neighboring editorial panel is not a product's unclaimed cover.
// Do not let an image somewhere in that panel suppress collection evidence.
$editorial = str_replace('<div class="cover">', '<div class="cover"><p>Other news</p>', $html);
$result = (new HtmlTransformer())->transform($editorial)->toArray();
$collections = array_filter($result['fallbacks'] ?? array(), static fn (array $finding): bool => 'commerce_product_grid_detected' === ($finding['reason'] ?? null));
if (array() === $collections) {
    throw new RuntimeException('An editorial sibling must not suppress product collection evidence.');
}
echo "product-split-detail-layout: ok\n";

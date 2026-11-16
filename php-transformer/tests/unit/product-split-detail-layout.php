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
    if (isset($diagnostic['item_template']) || isset($diagnostic['binding'])) {
        throw new RuntimeException('A partial item template must not replace the split detail layout.');
    }
    if (empty($diagnostic['products'][0]['binding'])) {
        throw new RuntimeException('The purchase control must retain its commerce binding.');
    }
}
if (!$found || !str_contains($result['serialized_blocks'], 'cover.jpg')) {
    throw new RuntimeException('Product evidence and sibling media must survive.');
}
echo "product-split-detail-layout: ok\n";

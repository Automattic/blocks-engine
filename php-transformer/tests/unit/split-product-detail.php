<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$result = (new HtmlTransformer())->transform('<section><div style="display:grid;grid-template-columns:1fr 1fr"><div><img src="cover.png" alt="Book cover"></div><div><h2>A book</h2><p>Book description</p><span>$8.99</span><button>Buy now</button></div></div></section>')->toArray();
$found = false;
foreach ($result['fallbacks'] as $fallback) {
    if ('html_product_grid_fallback' !== ($fallback['diagnostic_code'] ?? null)) continue;
    $found = true;
    $product = $fallback['products'][0] ?? array();
    if (($product['image']['src'] ?? null) !== 'cover.png'
        || ($fallback['container_selector'] ?? null) === ($product['source_selector'] ?? null)
        || isset($fallback['binding'])
        || empty($product['binding']['search_block_markup'])
        || !str_contains($result['serialized_blocks'], 'cover.png')) {
        fwrite(STDERR, "FAIL: split detail must retain artwork, product image data and purchase binding without a collection replacement\n");
        exit(1);
    }
}
if (!$found) {
    fwrite(STDERR, "FAIL: product detail must retain commerce materialization\n");
    exit(1);
}
echo "Split product detail passed\n";

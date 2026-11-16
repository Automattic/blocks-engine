<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$result = (new HtmlTransformer())->transform('<section><div style="display:grid;grid-template-columns:1fr 1fr"><div><img src="cover.png" alt="Book cover"></div><div><h2>A book</h2><p>Book description</p><span>$8.99</span><button>Buy now</button></div></div></section>')->toArray();
foreach ($result['fallbacks'] as $fallback) {
    if ('html_product_grid_fallback' === ($fallback['diagnostic_code'] ?? null)) {
        fwrite(STDERR, "FAIL: split product detail must not replace artwork with a purchase-column collection template\n");
        exit(1);
    }
}
if (!str_contains($result['serialized_blocks'], 'cover.png')) {
    fwrite(STDERR, "FAIL: detail artwork must remain editable\n");
    exit(1);
}
echo "Split product detail passed\n";

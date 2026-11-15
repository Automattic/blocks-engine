<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Category overlays and product cards need no editorial summary to be safe.
foreach (array(
    '<img src="category.jpg" alt=""><div class="shade"></div><p>Category</p>',
    '<img src="product.jpg" alt=""><div><p>Brand</p><p>Product</p><p>Price</p></div>',
    '<div><img src="product.jpg" alt=""></div><div><p>Brand</p><p>Product</p><p>Price</p></div>',
) as $content) {
    $out = (new HtmlTransformer())->transform('<main><a class="group" href="/destination/">' . $content . '</a></main>')->toArray();
    $markup = $out['serialized_blocks'];
    $dom = new DOMDocument();
    @$dom->loadHTML($markup);
    $xpath = new DOMXPath($dom);
    $carrier = str_contains($content, '<h3>') ? 'h3' : 'p';
    if (str_contains($markup, '/layout-shell')
        || 1 !== $xpath->query('//div[contains(@class,"blocks-engine-stretched-link")]')->length
        || 1 !== $xpath->query('//' . $carrier . '/a[@href="/destination/"]')->length
        || 1 !== $xpath->query('//a')->length) {
        throw new RuntimeException('Safe media/label cards need one native editable destination: ' . $markup);
    }
}
echo "Media stretched-link card contract passed.\n";

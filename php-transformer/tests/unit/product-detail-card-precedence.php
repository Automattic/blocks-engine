<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CommerceStructureRecognizer;

$failures = 0;
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) { ++$failures; fwrite(STDERR, "FAIL: $message\n"); }
};
$card = static fn (string $name, string $copy): string => '<div class="card" itemscope itemtype="https://schema.org/Product"><img src="https://cdn.example/card.jpg" alt="' . $name . '"><h3>' . $name . '</h3><span class="price">$' . (20 + strlen($name)) . '.00</span>' . $copy . '<button>Add to cart</button></div>';
$page = static fn (string $body): string => '<!doctype html><html><head><title>Catalog</title></head><body><main><h1>Catalog</h1>' . $body . '</main></body></html>';
$detail = array(
    '@context' => 'https://schema.org', '@type' => 'ProductGroup',
    'name' => 'Woven Cap', 'url' => 'https://shop.example/products/woven-cap',
    'description' => 'A soft woven cap for cool evenings.',
    'image' => array('https://cdn.example/detail.jpg', 'https://cdn.example/back.jpg'),
    'category' => 'Accessories', 'sku' => 'CAP-01',
    'offers' => array('@type' => 'Offer', 'price' => '25.00', 'priceCurrency' => 'USD', 'availability' => 'https://schema.org/OutOfStock'),
);
$files = array(
    'index.html' => $page('<div class="grid">'
        . $card('Woven Cap', '<ul><li class="c-product-label -out-of-stock"><p>Out of stock</p></li></ul><p>Short card copy.</p>')
        . $card('Plain Scarf', '<p>Restocked monthly, woven for warmth.</p>')
        . $card('Canvas Bag', '<p class="availability">Available soon</p>')
        . $card('Cotton Wrap', '<p>Light and airy.</p>') . '</div>'),
    'products/woven-cap.html' => $page('<script type="application/ld+json">' . json_encode($detail) . '</script>'),
    // Missing detail fields must not erase useful listing evidence.
    'products/plain-scarf.html' => $page('<script type="application/ld+json">' . json_encode(array(
        '@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'Plain Scarf',
        'url' => 'https://shop.example/products/plain-scarf',
        'offers' => array('@type' => 'Offer', 'price' => '20', 'priceCurrency' => 'USD'),
    )) . '</script>'),
);
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
$products = array();
foreach ($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration) {
    if ('entity_collection' === ($declaration['kind'] ?? null) && 'products' === ($declaration['type'] ?? null)) {
        $products = array_column($declaration['payload']['entities'], null, 'slug');
    }
}
$cap = $products['woven-cap'] ?? array();
$assert($detail['description'] === ($cap['description'] ?? null), 'Detail schema description replaces card copy, not the stock badge.');
$assert($detail['image'][0] === ($cap['image'] ?? null), 'Detail primary image replaces the card thumbnail.');
$assert($detail['image'] === array_column($cap['images'] ?? array(), 'src'), 'Detail gallery is retained.');
$assert('Accessories' === ($cap['category'] ?? null) && 'CAP-01' === ($cap['sku'] ?? null) && 'outofstock' === ($cap['stock_status'] ?? null), 'Detail descriptive metadata is retained.');
$assert('index.html' === ($cap['source_path'] ?? null) && !empty($cap['bindings']) && isset($cap['selector']), 'Card placement and bindings survive.');
$scarf = $products['plain-scarf'] ?? array();
$assert('Restocked monthly, woven for warmth.' === ($scarf['description'] ?? null) && 'https://cdn.example/card.jpg' === ($scarf['image'] ?? null), 'Card values fill missing detail fields; stock-related prose remains descriptive.');
$assert(empty($products['canvas-bag']['description']), 'An availability paragraph alone is not a description.');

// Exercise the badge source directly so detail precedence cannot mask extraction bugs.
$recognizer = new CommerceStructureRecognizer();
foreach (array(
    '<ul><li class="c-product-label -out-of-stock"><p>Out of stock</p></li></ul>',
    '<p class="stock-badge">Out of stock</p>',
    '<p itemprop="availability">Available soon</p>',
) as $badge) {
    $dom = new DOMDocument();
    $dom->loadHTML($card('Woven Cap', $badge . '<p>Actual card description.</p>'));
    $row = $recognizer->productCardData($dom->getElementsByTagName('div')->item(0));
    $assert('Actual card description.' === ($row['description'] ?? null), 'Badge-origin text is skipped in favor of genuine description.');
}

if ($failures) exit(1);
echo "product-detail-card-precedence: ok\n";

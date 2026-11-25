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
$editorial = str_replace('<div class="cover">', '<div class="cover"><p>Other news about upcoming events, community projects, and new releases from our contributors.</p>', $html);
$found = $collections($transform($editorial));
if (1 !== count($found) || !str_contains((string) ($found[0]['binding']['search_block_markup'] ?? ''), 'Other news')) {
    throw new RuntimeException('An editorial sibling must not decline the product collection region.');
}
// Reduced two-track detail layout: decoration must not turn the panel into a loop card.
$panel = '<div><p>Everyday essentials</p><h2>Sample vessel</h2><p>$25.99</p><button>Add to cart</button></div>';
foreach (array(
    'no badge' => array('', false),
    'overlay badge' => array('<div style="position:absolute;top:12px;right:12px">Limited edition</div>', false),
    'nested decoration' => array('<div><span>Fresh arrival</span></div>', false),
    'own heading' => array('<h3>Another vessel</h3>', true),
    'own semantic title' => array('<span itemprop="name">Another vessel</span>', true),
    'own accessible heading' => array('<div role="heading" aria-level="3">Another vessel</div>', true),
    'prose' => array('<p>Read more about our materials and the people who make these everyday objects.</p>', true),
    'own price' => array('<span>$12.00</span>', true),
    'own semantic price' => array('<span itemprop="price">12.00</span>', true),
    'own cart control' => array('<button>Add to cart</button>', true),
    'listing controls' => array('<div>Limited edition</div><select name="sort_by"><option value="price-ascending">Price: low to high</option></select>', true),
) as $case => [$decoration, $expectGrid]) {
    $source = '<main><div style="display:grid;grid-template-columns:1fr 1fr;gap:40px"><div style="position:relative"><img src="vessel.png" alt="Vessel" width="1024" height="1024">' . $decoration . '</div>' . $panel . '</div></main>';
    $result = $transform($source);
    $found = $collections($result);
    if ($expectGrid !== (array() !== $found)) {
        throw new RuntimeException($case . ': expected ' . ($expectGrid ? 'a collection' : 'no html_product_grid_fallback') . ', got ' . count($found) . ' finding(s).');
    }
    if (!$expectGrid && (!str_contains($result['serialized_blocks'], 'vessel.png') || !str_contains($result['serialized_blocks'], 'Add to cart'))) {
        throw new RuntimeException($case . ': keep the image and purchase panel.');
    }
}
echo "product-split-detail-layout: ok\n";

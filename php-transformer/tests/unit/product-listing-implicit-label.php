<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$entities = static function (array $result, string $type): array {
    foreach ($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration) {
        if ($type === ($declaration['type'] ?? null)) {
            return $declaration['payload']['entities'] ?? array();
        }
    }
    return array();
};

// The reduced page from #5413, with alternate label associations. Option text
// must never become part of a facet caption, and the source DOM must stay intact.
$stars = '<select id="star-filter"><option value="all">All categories</option><option value="3">3 star</option><option value="4">4 star</option></select>';
$nights = '<select id="night-filter"><option value="all">Any length</option><option value="7">7 nights</option><option value="8">8 nights</option></select>';
// Explicit layout and commerce evidence keep the reduced cards recognizable
// even when all controls are captionless.
$cards = '<div class="grid" style="display:grid">'
    . '<article class="card" itemscope itemtype="https://schema.org/Product" data-stars="3" data-nights="7"><h3>Plan A</h3><span class="price">From £645</span><a href="/a">Details</a></article>'
    . '<article class="card" itemscope itemtype="https://schema.org/Product" data-stars="4" data-nights="8"><h3>Plan B</h3><span class="price">From £850</span><a href="/b">Details</a></article></div>';
$cases = array(
    'implicit (issue reduction)' => array('<label>Hotel preference' . $stars . '</label>', '<label>Length of stay' . $nights . '</label>', true),
    'nested caption and control' => array('<label><span>Hotel preference</span><span>' . $stars . '</span></label>', '<label><span>' . $nights . '</span> Length of stay</label>', true),
    'explicit wins over implicit' => array('<label for="star-filter">Hotel preference</label><label>Wrong caption' . $stars . '</label>', '<label for="night-filter">Length of stay</label><label>Wrong caption' . $nights . '</label>', true),
    'aria captions' => array(str_replace('<select ', '<select aria-label="Hotel preference" ', $stars), str_replace('<select ', '<select aria-label="Length of stay" ', $nights), true),
    'name fallback' => array(str_replace('<select ', '<select name="hotel_preference" ', $stars), str_replace('<select ', '<select name="length_of_stay" ', $nights), true),
    'no caption' => array($stars, $nights, false),
    'empty implicit caption' => array('<label>' . $stars . '</label>', '<label> ' . $nights . '</label>', false),
);
foreach ($cases as $case => [$starControl, $nightControl, $named]) {
    $html = '<!doctype html><html><head><title>Plans</title></head><body><a href="/cart/">Cart</a><main>'
        . '<div class="filters" role="group" aria-label="Filter">' . $starControl . $nightControl . '</div>' . $cards . '</main></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'plans.html', 'files' => array('plans.html' => $html)))->toArray();
    $assert('failed' !== $result['status'] && !isset($result['source_reports']['wordpress_site_plan_diagnostics']), $case . ': compilation succeeds');
    $products = $entities($result, 'products');
    $assert(2 === count($products), $case . ': both products survive');
    $listings = $entities($result, 'product_listings');
    $controls = $listings[0]['controls'] ?? array();
    $assert(($named ? array('hotel_preference', 'length_of_stay') : array()) === array_column($controls, 'key'), $case . ': only named facets are declared: ' . json_encode($controls));
    $labels = 'name fallback' === $case ? array('hotel_preference', 'length_of_stay') : array('Hotel preference', 'Length of stay');
    if ($named) {
        $assert($labels === array_column($controls, 'label'), $case . ': captions exclude option text');
        $assert(array('All categories', '3 star', '4 star') === array_column($controls[0]['options'], 'label'), $case . ': reading captions preserves options');
    }
    foreach ($products as $index => $product) {
        $expected = $named ? array(
            array('key' => 'hotel_preference', 'label' => $labels[0], 'value' => 0 === $index ? '3' : '4', 'value_label' => 0 === $index ? '3 star' : '4 star'),
            array('key' => 'length_of_stay', 'label' => $labels[1], 'value' => 0 === $index ? '7' : '8', 'value_label' => 0 === $index ? '7 nights' : '8 nights'),
        ) : array();
        $assert($expected === ($product['attributes'] ?? array()), $case . ': valid compiled attributes: ' . json_encode($product['attributes'] ?? array()));
    }
}

echo "product-listing-implicit-label: ok\n";

<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

foreach (array('enquiry' => false, 'submit-purchase' => true) as $case => $hasPurchase) {
    $cards = '<main><h1>Packages</h1><div class="grid">';
    foreach (array('Coastal stay' => '645', 'Mountain stay' => '700', 'City stay' => '850') as $title => $price) {
        $cards .= '<article class="card"><img src="stay.jpg" alt=""><h2>' . $title . '</h2><p>From £' . $price . ' per person</p><a href="/request-a-quote/">Enquire</a>';
        $cards .= $hasPurchase ? '<input type="submit" value="Add to cart">' : '';
        $cards .= '</article>';
    }
    $cards .= '</div><form class="quote-form"><label>Check-in <input name="checkIn" type="date"></label><label>Check-out <input name="checkOut" type="date"></label><button type="submit">Send enquiry</button></form></main>';
    $schema = '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","name":"Packages","offers":{"@type":"Offer","price":"645","priceCurrency":"GBP"}}</script>';
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array('index.html' => '<!doctype html><html><head><title>Packages</title>' . $schema . '</head><body>' . $cards . '</body></html>'),
    ))->toArray();
    $plan = $result['source_reports']['wordpress_site_plan'] ?? array();
    $assert(array() !== $plan, $case . ': site plan exists');
    $types = array_column($plan['runtime_declarations'] ?? array(), 'type');
    if ($hasPurchase) {
        $assert(in_array('products', $types, true), $case . ': submit purchase controls still declare products');
    } else {
        $assert(! in_array('products', $types, true), $case . ': date fields must not declare products');
        $assert(! in_array('product_grids', $types, true), $case . ': date fields must not declare product grids');
    }
}

echo "enquiry-date-input-purchase-affordance: ok\n";

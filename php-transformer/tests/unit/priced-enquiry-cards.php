<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$cards = '<main><h1>Packages</h1><div class="grid">';
foreach (array(array('7 nights · 3 star', '645', '4', '3', 'a'), array('7 nights · 3 star', '700', '4', '3', 'a'), array('10 nights · 4 star', '850', '5', '5', 'b')) as [$title, $price, $cityA, $cityB, $image]) {
    $cards .= '<article class="card"><img src="' . $image . '.jpg" alt=""><h3>' . $title . '</h3><ul><li>City A · ' . $cityA . ' nights</li><li>City B · ' . $cityB . ' nights</li></ul><span class="price">From £' . $price . ' <small>per person</small></span><a href="/request-a-quote/">Enquire ↗</a></article>';
}
$cards .= '</div></main>';
$schema = '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","name":"Packages","offers":{"@type":"Offer","price":"645","priceCurrency":"GBP"}}</script>';
$assert = static function (bool $ok, string $message): void {
    if (! $ok) throw new RuntimeException($message);
};
// The extra catalog-sized row exercises the image-only recognition threshold too.
foreach (array('plain' => '', 'json-ld' => $schema, 'catalog-sized' => '') as $case => $head) {
    $sourceCards = 'catalog-sized' === $case ? str_replace('</div></main>', '<article class="card"><img src="c.jpg" alt=""><h3>Weekend</h3><span class="badge">Special</span><p>£200</p><a href="/details/">Details</a></article></div></main>', $cards) : $cards;
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<!doctype html><html><head><title>Packages</title>' . $head . '</head><body>' . $sourceCards . '</body></html>')))->toArray();
    $plan = $result['source_reports']['wordpress_site_plan'] ?? array();
    $assert(array() !== $plan, $case . ': site plan exists');
    foreach ($plan['runtime_declarations'] ?? array() as $declaration) {
        $assert(!in_array($declaration['type'] ?? '', array('products', 'product_grids'), true), $case . ': no commerce declaration');
    }
    $assert(!str_contains(strtolower(json_encode($plan)), 'woocommerce'), $case . ': no WooCommerce dependency');
    $assert(!str_contains(json_encode($result), 'html_product_grid_fallback'), $case . ': no product fallback');
    $markup = $plan['pages'][0]['canonical_block_markup'] ?? '';
    if ('catalog-sized' === $case) {
        $assert(str_contains($markup, 'Special') && str_contains($markup, '£200') && str_contains($markup, 'href="/details/"'), 'Extra card retains badge, price and details link');
    }
    $assert(!str_contains($markup, '<!-- wp:html') && str_contains($markup, '<!-- wp:'), $case . ': native editable blocks');
    foreach (array('7 nights · 3 star' => 2, '10 nights · 4 star' => 1, 'From £645' => 1, 'From £700' => 1, 'From £850' => 1, 'per person' => 3, 'City A · 4 nights' => 2, 'City B · 3 nights' => 2, 'City A · 5 nights' => 1, 'City B · 5 nights' => 1, 'href="/request-a-quote/"' => 3, 'Enquire ↗' => 3) as $text => $count) {
        $assert($count === substr_count(html_entity_decode(strip_tags($markup, '<a>'), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $text), $case . ': preserved ' . $text);
    }
}
echo "priced-enquiry-cards: ok\n";

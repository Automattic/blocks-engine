<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};
$cards = '';
foreach (array('Alpha', 'Beta', 'Gamma') as $name) {
    $cards .= '<article><a href="/products/' . $name . '"><img src="' . $name . '.jpg" alt="' . $name . '"></a><h2><a href="/products/' . $name . '">' . $name . '</a></h2><p>R$30,00</p><form method="post" action="/cart/add"><input type="hidden" name="id" value="' . $name . '"><button type="submit">Add to cart</button></form></article>';
}
$reduction = '<div class="h6">Preço</div><span class="filter-input-price-container"><label class="form-label">De</label><input type="number" name="min_price" step="1" min="0" class="form-control" placeholder="3.9"></span><span class="filter-input-price-container"><label class="form-label">Até</label><input type="number" name="max_price" step="1" min="0" class="form-control" placeholder="419.99"></span><button type="submit" class="btn">Aplicar</button>';
$rows = array(
    'issue reduction' => array($reduction, '', '', array('min', 'max'), 'filter_sort'),
    'explicit GET' => array($reduction, ' method="get"', '', array('min', 'max'), 'filter_sort'),
    'labels' => array('<label>Minimum price<input type="number" name="a"></label><label>Maximum price<input type="number" name="b"></label><button>Apply</button>', '', '', array('min', 'max'), 'filter_sort'),
    'numeric endpoints' => array('<input type="number" name="a" placeholder="3.9"><input type="number" name="b" placeholder="419.99"><button>Apply</button>', '', 'decimal', array('min', 'max'), 'filter_sort'),
    'comma decimal endpoints' => array('<input type="number" name="a" placeholder="3.9"><input type="number" name="b" placeholder="419.99"><button>Apply</button>', '', '', array('min', 'max'), 'filter_sort'),
    'unrelated numeric pair' => array('<label>Minimum quantity<input type="number" name="min_quantity" placeholder="1"></label><label>Maximum quantity<input type="number" name="max_quantity" placeholder="5000"></label><button>Calculate</button>', '', '', array(), 'unknown'),
    'quantity' => array('<label>Quantity<input type="number" name="quantity" min="1" max="5000"></label><button>Update</button>', '', '', array(), 'unknown'),
    'unrelated destination' => array($reduction, ' action="/calculate"', '', array(), null),
    'POST' => array($reduction, ' method="post"', '', array(), 'unknown'),
    'slider unchanged' => array('<label>From<input type="range" min="0" max="5000" name="price"></label>', '', '', array('min'), 'unknown'),
);
foreach ($rows as $case => [$inputs, $attributes, $prices, $expectedBounds, $expectedIntent]) {
    $grid = 'decimal' === $prices ? str_replace('R$30,00', '$30.00', $cards) : $cards;
    $html = '<!doctype html><html><head><title>Catalog</title></head><body><main><div style="display:flex"><aside><div class="price-filter-container"><form id="filter"' . $attributes . '>' . $inputs . '</form></div></aside><div style="display:grid;gap:20px">' . $grid . '</div></div></main></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'catalog.html', 'files' => array('catalog.html' => $html)))->toArray();
    $controls = array();
    foreach ($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration) {
        if ('product_listings' === ($declaration['type'] ?? null)) {
            foreach ($declaration['payload']['entities'] as $listing) {
                foreach ($listing['controls'] as $control) {
                    if ('range' === $control['kind']) $controls[] = $control;
                }
            }
        }
    }
    $assert($expectedBounds === array_column($controls, 'bound'), $case . ': listing bounds: ' . json_encode($controls));
    foreach ($controls as $control) {
        $assert('price' === $control['field'] && !empty($control['selector']), $case . ': price range retains its source anchor');
    }
    $forms = array_values(array_filter($result['fallbacks'] ?? array(), static fn (array $finding): bool => 'html_form_fallback' === ($finding['diagnostic_code'] ?? null) && str_contains($finding['source_selector'] ?? $finding['selector'] ?? '', 'aside')));
    $assert(null === $expectedIntent ? array() === $forms : (1 === count($forms) && $expectedIntent === ($forms[0]['form_intent'] ?? null)), $case . ': filter form intent: ' . json_encode($forms));
}
echo "product-listing-numeric-price-bounds: ok\n";

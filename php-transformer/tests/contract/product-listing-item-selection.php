<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        throw new RuntimeException($message);
    }
};

// Alternate views have different classes but contain the same products.
// Their per-card selection inputs must never anchor controls on either grid.
$html = file_get_contents(dirname(__DIR__) . '/fixtures/commerce/alternate-product-views.html');
foreach ( array('checkbox', 'radio') as $type ) {
    foreach ( array(false, true) as $withFacet ) {
        $page = str_replace('type="checkbox"', 'type="' . $type . '"', $html);
        if ( $withFacet ) {
            $page = str_replace('<form action="compare"', '<aside><fieldset><legend>Finish</legend><label><input type="checkbox" name="finish" value="matte">Matte</label><label><input type="checkbox" name="finish" value="gloss">Gloss</label></fieldset></aside><form action="compare"', $page);
        }
        $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $page)))->toArray();
        $diagnostics = json_encode($result['source_reports']['wordpress_site_plan_diagnostics'] ?? array());
        $assert('failed' !== $result['status'], 'Alternate product views compose: ' . $diagnostics);
        $assert(! str_contains($diagnostics, 'ambiguous canonical') && ! str_contains($diagnostics, 'wordpress_site_plan_not_self_contained'), 'No ambiguous-anchor diagnostic: ' . $diagnostics);
        $plan = $result['source_reports']['wordpress_site_plan'] ?? array();
        $entities = array();
        foreach ( $plan['runtime_declarations'] ?? array() as $declaration ) {
            if ( isset($declaration['type']) ) {
                $entities[$declaration['type']] = $declaration['payload']['entities'] ?? array();
            }
        }
        $assert(2 === count($entities['product_grids'] ?? array()), 'Both alternate product grids remain declared.');
        $controls = array();
        foreach ( $entities['product_listings'] ?? array() as $listing ) {
            foreach ( $listing['controls'] ?? array() as $control ) {
                $controls[] = $control;
                $assert('facet:finish' === $control['id'], 'Only the external facet is a listing control: ' . json_encode($control));
            }
        }
        $assert($withFacet ? count($controls) > 0 : array() === $controls, 'External checkbox facets remain recognized; item selections do not.');
        foreach ( $entities['products'] ?? array() as $product ) {
            foreach ( $product['attributes'] ?? array() as $attribute ) {
                $assert('products' !== $attribute['key'] && 'products[]' !== ($attribute['label'] ?? ''), 'Item selection does not become a product attribute.');
            }
        }
    }
}

echo "product-listing-item-selection: ok\n";

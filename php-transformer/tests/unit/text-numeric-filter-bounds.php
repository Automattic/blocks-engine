<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormRuntimeRequirementAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Neutral reduction of #6077: no vendor names or theme classes establish intent.
$inputs = '<price-range><label>From<input type="text" inputmode="decimal" name="filter.v.price.gte" placeholder="0" data-min="0" data-max="85.00"></label><label>To<input type="text" inputmode="decimal" name="filter.v.price.lte" placeholder="85.00" data-min="0" data-max="85.00"></label></price-range>';
$cases = array(
    'issue fixture' => array($inputs, true),
    'numeric mode and min/max suffixes' => array(str_replace(array('decimal', '.gte', '.lte'), array('numeric', '_min', '_max'), $inputs), true),
    'placeholder evidence only' => array(str_replace(' data-min="0" data-max="85.00"', '', $inputs), true),
    'data evidence only' => array(str_replace(array(' placeholder="0"', ' placeholder="85.00"'), '', $inputs), true),
    'no numeric mode' => array(str_replace(' inputmode="decimal"', '', $inputs), false),
    'no numeric evidence' => array(str_replace(array(' placeholder="0"', ' placeholder="85.00"', ' data-min="0" data-max="85.00"'), '', $inputs), false),
    'different fields' => array(str_replace('price.lte', 'weight.lte', $inputs), false),
    'not a pair' => array(str_replace('price.lte', 'price.gte', $inputs), false),
    'unrelated free text' => array($inputs . '<input name="reference">', false),
);
foreach ( $cases as $name => [$content, $accepted] ) {
    $html = '<main><aside><details open><summary>Filter and sort</summary><form><details open><summary>Price</summary>' . $content . '<button type="button">Apply</button></details><label><input type="checkbox" name="availability" value="1">In stock</label></form></details></aside></main>';
    $document = HtmlSerialization::parseDocument($html);
    $intent = FormRuntimeRequirementAnalyzer::formIntent($document->getElementsByTagName('form')->item(0));
    if ( ($accepted ? 'filter_sort' : 'unknown') !== $intent ) {
        throw new RuntimeException($name . ': unexpected intent ' . $intent);
    }
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $fallbacks = array_filter($result['fallbacks'] ?? array(), static fn (array $finding): bool => 'html_form_fallback' === ($finding['diagnostic_code'] ?? ''));
    if ( $accepted !== (array() === $fallbacks) ) {
        throw new RuntimeException($name . ': unexpected form fallback');
    }
    if ( $accepted ) {
        $markup = $result['serialized_blocks'] ?? '';
        if ( ! str_contains($markup, 'wp:custom/authored-native-form') || ! str_contains($markup, 'wp:custom/authored-input') || ! str_contains($markup, 'name="filter.v.price') ) {
            throw new RuntimeException($name . ': expected editable native form and original inputs');
        }
    }
}
// The same text endpoints must also be adopted by listing-control recognition.
$cards = '';
foreach ( array('Alpha', 'Beta', 'Gamma') as $product ) {
    $cards .= '<article><a href="/products/' . $product . '"><img src="' . $product . '.jpg" alt="' . $product . '"></a><h2><a href="/products/' . $product . '">' . $product . '</a></h2><p>$30.00</p><form method="post" action="/cart/add"><input type="hidden" name="id" value="' . $product . '"><button>Add to cart</button></form></article>';
}
$html = '<html><head><title>Catalog</title></head><body><main><aside><form>' . $inputs . '</form></aside><div style="display:grid">' . $cards . '</div></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$bounds = array();
foreach ( $result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration ) {
    if ( 'product_listings' !== ($declaration['type'] ?? '') ) continue;
    foreach ( $declaration['payload']['entities'] as $listing ) {
        foreach ( $listing['controls'] as $control ) {
            if ( 'range' === $control['kind'] ) $bounds[] = $control['bound'];
        }
    }
}
if ( array('min', 'max') !== $bounds ) {
    throw new RuntimeException('listing must adopt both text price endpoints: ' . json_encode($bounds));
}
if ( array_filter($result['fallbacks'] ?? array(), static fn (array $finding): bool => 'html_form_fallback' === ($finding['diagnostic_code'] ?? '')) ) {
    throw new RuntimeException('listing filter must not retain a form fallback');
}
echo "text-numeric-filter-bounds: ok\n";

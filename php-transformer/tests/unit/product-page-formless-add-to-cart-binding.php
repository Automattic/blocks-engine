<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$schema = '<script type="application/ld+json">{"@type":"ProductGroup","name":"Desk Lamp","hasVariant":[{"@type":"Product","name":"S","offers":{"price":"40.00"},"additionalProperty":[{"name":"Size","value":"S"}]}]}</script>';
$purchase = '<div class="buy"><label><input type="radio" name="Size" value="S">S</label><button type="button">Add to cart</button></div>';
$details = '<h1>Field Tee</h1><div class="price">$27.99</div><p class="description">A soft everyday tee. Relaxed fit.</p>';
$fields = '<div class="variants"><fieldset><legend>Color:</legend><select name="color"><option>Mauve</option></select></fieldset><fieldset><legend>Size:</legend><select name="size"><option>S</option></select></fieldset></div><div class="quantity"><input type="number" value="1" min="1"></div><div class="add-wrapper"><div role="button" tabindex="0">Add To Cart</div></div>';
$related = '<div style="display:grid"><article><h2>Tray</h2><p>$20.00</p>' . $purchase . '</article><article><h2>Vase</h2><p>$20.00</p>' . $purchase . '</article></div>';
foreach (array(
    'issue fixture' => array($schema, $purchase, 1),
    'reduced details column' => array($schema, '<section class="product"><div class="gallery"><img src="https://example.com/tee.jpg" alt="Field Tee front"></div><div class="details">' . $details . $fields . '</div></section>', 1),
    'dedicated purchase wrapper' => array($schema, '<div>' . $details . '<div>' . $fields . '</div></div>', 1),
    'direct sibling controls' => array($schema, '<div>' . $details . '<select name="size"><option>S</option></select><input type="number" value="1"><div role="button">Add To Cart</div></div>', 1),
    'nested wrappers' => array($schema, '<section><div>' . $purchase . '</div></section>', 1),
    'select options' => array($schema, str_replace('<label><input type="radio" name="Size" value="S">S</label>', '<select name="Size"><option>S</option></select>', $purchase), 1),
    'repeated placements' => array($schema, $purchase . $purchase, 2),
    'related cards' => array($schema, $purchase . $related, 1),
    'no schema' => array('', $purchase, 0),
    'ambiguous schema' => array($schema . '<script type="application/ld+json">{"@type":"Product","name":"Other","offers":{"price":"10.00"}}</script>', $purchase, 0),
    'button alone' => array($schema, '<div><button type="button">Add to cart</button></div>', 0),
    'no cart action' => array($schema, str_replace('Add to cart', 'Save preferences', $purchase), 0),
    'real form' => array($schema, '<form method="post">' . $purchase . '</form>', 1),
) as $case => [$jsonLd, $controls, $expected]) {
    if (str_contains($controls, 'Field Tee')) $jsonLd = '<script type="application/ld+json">{"@type":"Product","name":"Field Tee","description":"A soft everyday tee.","offers":{"@type":"AggregateOffer","lowPrice":27.99,"highPrice":27.99,"priceCurrency":"USD","offerCount":4}}</script>';
    $html = '<head>' . $jsonLd . '</head><main><h1>Desk Lamp</h1>' . $controls . '</main>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'products/desk-lamp.html', 'files' => array('products/desk-lamp.html' => $html)))->toArray();
    $assert('failed' !== $result['status'], $case . ': page compiles.');
    $plan = $result['source_reports']['wordpress_site_plan'] ?? array();
    $errors = array_filter($result['source_reports']['wordpress_site_plan_diagnostics'] ?? array(), static fn (array $diagnostic): bool => 'error' === ($diagnostic['severity'] ?? ''));
    $assert(array() === $errors, $case . ': no self-containment errors: ' . json_encode($errors));
    $rows = array();
    foreach ($plan['runtime_declarations'] ?? array() as $declaration) {
        if ('products' === ($declaration['type'] ?? null)) $rows = array_column($declaration['payload']['entities'], null, 'slug');
    }
    $bindings = array_values(array_filter($rows[str_contains($controls, 'Field Tee') ? 'field-tee' : 'desk-lamp']['bindings'] ?? array(), static fn (array $binding): bool => 'commerce_add_to_cart_form' === ($binding['role'] ?? null)));
    $assert($expected === count($bindings), $case . ': expected ' . $expected . ' purchase bindings, got ' . count($bindings) . '.');
    $anchors = array();
    foreach ($bindings as $binding) {
        $assert('products/desk-lamp.html' === $binding['source_path'], $case . ': binding names its page.');
        $assert('' !== trim($binding['search_block_markup']), $case . ': nonempty anchor.');
        $assert(isset($binding['projected_anchor']), $case . ': canonical projection.');
        $anchors[] = $binding['projected_anchor'];
        if (in_array($case, array('reduced details column', 'dedicated purchase wrapper', 'direct sibling controls'), true)) {
            foreach (array('Field Tee', '$27.99', 'A soft everyday tee. Relaxed fit.') as $text) {
                $assert(!str_contains($binding['search_block_markup'], $text), $case . ': purchase anchor excludes ' . $text);
            }
            foreach ($plan['pages'] as $page) {
                if ('products/desk-lamp.html' !== $page['source_path']) continue;
                foreach (array(array('heading', 'Field Tee'), array('paragraph', '$27.99'), array('paragraph', 'A soft everyday tee. Relaxed fit.')) as [$block, $text]) {
                    $assert(1 === preg_match('/<!-- wp:' . $block . '\\b[^>]*-->[^<]*<[^>]+>' . preg_quote($text, '/') . '</', $page['canonical_block_markup']), $case . ': editable core ' . $block . ' survives.');
                }
                $assert(str_contains($page['canonical_block_markup'], '$27.99'), $case . ': price survives.');
            }
        }
        foreach ($plan['pages'] as $page) {
            if ('products/desk-lamp.html' === $page['source_path']) $assert(str_contains($page['canonical_block_markup'], $binding['search_block_markup']), $case . ': anchor occurs in canonical markup.');
        }
    }
    if (2 === $expected) $assert($anchors[0] !== $anchors[1], $case . ': distinct projected placements.');
    foreach (array('tray', 'vase') as $slug) {
        $assert(!in_array('commerce_add_to_cart_form', array_column($rows[$slug]['bindings'] ?? array(), 'role'), true), $case . ': related cards retain card ownership.');
    }
}
echo "product-page-formless-add-to-cart-binding: ok\n";

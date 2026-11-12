<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$quantity = '<input type="number" name="quantity" value="1" min="1" aria-label="Quantidade">';
$variants = '<select name="variant"><option value="brass">Brass</option><option value="black">Black</option></select>';
foreach (array(
    'quantity' => array($quantity, false),
    'hidden quantity' => array($quantity, true),
    'quantity and variant' => array($quantity . $variants, false),
    'hidden quantity and variant' => array($quantity . $variants, true),
    'quantity-less' => array('', false),
) as $case => [$fields, $hidden]) {
    $cards = '';
    for ($i = 0; $i < 3; ++$i) {
        $form = '<form class="product-form" method="post" action="/comprar/">'
            . '<input type="hidden" name="add_to_cart" value="' . (100 + $i) . '">'
            . $fields . '<input type="submit" class="btn btn-primary cart" value="Comprar"></form>';
        $cards .= '<div class="col-grid item-product"><div class="item">'
            . '<a href="/produtos/lamp-' . $i . '/" class="item-image"><img src="p1.webp" width="1024" height="1024" alt="Lamp ' . $i . '"></a>'
            . '<div class="item-description"><a href="/produtos/lamp-' . $i . '/" class="item-link"><div class="item-name">Lamp ' . $i . '</div></a>'
            . '<div class="item-price-container"><span class="item-price">R$31,68</span></div>'
            . ($hidden ? '<div style="display:none">' . $form . '</div>' : $form)
            . '</div></div></div>';
    }
    $html = '<main><div class="grid" style="display:flex;gap:20px">' . $cards . '</div></main>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $assert('failed' !== $result['status'], "$case: compiles");
    $plan = $result['source_reports']['wordpress_site_plan'] ?? array();
    $markup = $plan['pages'][0]['canonical_block_markup'] ?? '';
    $assert('' !== $markup && !str_contains($markup, '<!-- wp:html'), "$case: no core/html runtime islands");
    foreach ($result['fallbacks'] ?? array() as $fallback) {
        $assert('unsupported_html_fallback' !== ($fallback['reason'] ?? null), "$case: no unsupported HTML fallback");
        $assert('html_form_fallback' !== ($fallback['diagnostic_code'] ?? null)
            && !('html_commerce_controls_fallback' === ($fallback['diagnostic_code'] ?? null) && isset($fallback['form_intent'])), "$case: no unresolved commerce form");
    }
    $declarations = array_column($plan['runtime_declarations'] ?? array(), 'payload', 'type');
    $products = $declarations['products']['entities'] ?? array();
    $assert(3 === count($products), "$case: all three card products declared");
    foreach ($products as $product) {
        $bindings = array_values(array_filter($product['bindings'] ?? array(), static fn (array $binding): bool => 'commerce_controls' === ($binding['role'] ?? null)));
        $assert(1 === count($bindings), "$case: each product owns its provider cart binding");
        $assert(str_contains($bindings[0]['search_block_markup'] ?? '', 'Comprar'), "$case: provider replaces purchase action");
    }
    $grid = $declarations['product_grids']['entities'][0] ?? array();
    $roles = array_column($grid['item_template']['slots'] ?? array(), 'role');
    $assert(in_array('add_to_cart', $roles, true), "$case: provider add-to-cart slot supersedes source controls");
    echo "$case: unsupported commerce/core_html 0/0\n";
}

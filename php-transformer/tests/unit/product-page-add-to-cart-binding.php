<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$purchase = '<form method="post"><p>Choose a finish</p><input type="hidden" name="variant_id" value="123"><div><input type="number" name="quantity" value="1" min="1"><button type="submit" style="padding:7px 30px">Add to cart</button></div><p>Ships today</p></form>';
$card = static fn (string $name): string => '<article><h2>' . $name . '</h2><p>$20.00</p><form method="post"><input type="hidden" name="variant_id" value="456"><input type="number" name="quantity" value="1"><button type="submit">Add to cart</button></form></article>';
$related = '<section><h2>Related products</h2><div style="display:grid">' . $card('Tray') . $card('Vase') . '</div></section>';
$schema = '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","name":"Desk Lamp","url":"https://example.test/products/desk-lamp","offers":{"@type":"Offer","price":"40.00","priceCurrency":"USD"}}</script>';
$compile = static function (string $forms, string $jsonLd) use ($related): array {
    $html = '<!doctype html><html><head><title>Desk Lamp</title>' . $jsonLd . '</head><body><main><h1>Desk Lamp</h1><p>$40.00</p>' . $forms . $related . '</main></body></html>';
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'products/desk-lamp.html', 'files' => array('products/desk-lamp.html' => $html)))->toArray();
};
$products = static function (array $result): array {
    foreach ($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration) {
        if ('products' === ($declaration['type'] ?? null)) return array_column($declaration['payload']['entities'], null, 'slug');
    }
    return array();
};
foreach (array(1, 2) as $placements) {
    $baseline = $products($compile(str_repeat($purchase, $placements), ''));
    $result = $compile(str_repeat($purchase, $placements), $schema);
    $assert('failed' !== $result['status'], 'Product page compiles.');
    $errors = array_filter($result['source_reports']['wordpress_site_plan_diagnostics'] ?? array(), static fn (array $diagnostic): bool => 'error' === ($diagnostic['severity'] ?? ''));
    $assert(array() === $errors, 'Plan has no self-containment errors: ' . json_encode($errors));
    $rows = $products($result);
    $bindings = array_values(array_filter($rows['desk-lamp']['bindings'] ?? array(), static fn (array $binding): bool => 'commerce_add_to_cart_form' === ($binding['role'] ?? null)));
    $assert($placements === count($bindings), 'Each page purchase form has exactly one product binding.');
    $markup = '';
    foreach ($result['source_reports']['wordpress_site_plan']['pages'] as $page) {
        if ('products/desk-lamp.html' === $page['source_path']) $markup = $page['canonical_block_markup'];
    }
    foreach ($bindings as $binding) {
        $assert('products/desk-lamp.html' === $binding['source_path'], 'Purchase binding names its page.');
        $assert(str_contains($binding['search_block_markup'], 'var:preset|spacing|'), 'Purchase anchor spacing was tokenized.');
        $assert(str_contains($markup, $binding['search_block_markup']), 'Purchase anchor occurs in canonical page markup.');
        $assert(isset($binding['projected_anchor']), 'Purchase binding uses canonical projection.');
    }
    if (2 === $placements) $assert($bindings[0]['projected_anchor'] !== $bindings[1]['projected_anchor'], 'Desktop/mobile forms have distinct projected placements.');
    foreach (array('tray', 'vase') as $slug) {
        $assert(isset($rows[$slug]) && $baseline[$slug] === $rows[$slug], 'Related card row is unchanged: ' . $slug);
        $assert(!in_array('commerce_add_to_cart_form', array_column($rows[$slug]['bindings'] ?? array(), 'role'), true), 'Related form is not a page purchase binding.');
    }
}
echo "product-page-add-to-cart-binding: ok\n";

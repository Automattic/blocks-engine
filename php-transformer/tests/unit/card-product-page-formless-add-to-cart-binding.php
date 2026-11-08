<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$card = static fn (string $name, string $url): string => '<article><a href="' . $url . '"><img src="https://example.com/' . strtolower($name) . '.jpg" alt="' . $name . '"><h2>' . $name . '</h2></a><p>$40.00</p><button type="button">Add to Cart</button></article>';
$purchase = '<div class="purchase"><label>Finish <select name="finish"><option>Brass</option><option>Black</option></select></label><label>Quantity <input type="number" name="quantity" min="1" value="1"></label><a href="#" role="button">Add to Cart</a></div>';
foreach (array(
    'relative URL' => array('index.html', 'p/lamp.html', 'p/chair.html', '', 1, 'lamp'),
    'root URL' => array('index.html', '/p/lamp.html', '/p/chair.html', '', 1, 'lamp'),
    'encoded URL' => array('index.html', 'p/%6Camp.html?view=full&amp;lang=en#buy', 'p/chair.html', '', 1, 'lamp'),
    'dot segments' => array('index.html', './p/../p/lamp.html', './p/chair.html', '', 1, 'lamp'),
    'ambiguous cards' => array('index.html', 'p/lamp.html', 'p/lamp.html', '', 0, 'lamp'),
    'uncompiled target' => array('index.html', 'p/other.html', 'p/chair.html', '', 0, 'lamp'),
    'schema authority' => array('index.html', 'p/lamp.html', 'p/chair.html', '<script type="application/ld+json">{"@type":"Product","name":"Schema Lamp","offers":{"price":"45.00"}}</script>', 1, 'schema-lamp'),
    'ambiguous schema' => array('index.html', 'p/lamp.html', 'p/chair.html', '<script type="application/ld+json">[{"@type":"Product","name":"First","offers":{"price":"45.00"}},{"@type":"Product","name":"Second","offers":{"price":"50.00"}}]</script>', 0, 'lamp'),
    'entry root' => array('website/index.html', '/p/lamp.html', '/p/chair.html', '', 1, 'lamp', 'website/p/lamp.html'),
    'encoded filename' => array('website/index.html', '/p/lamp%3A.html', '/p/chair.html', '', 1, 'lamp', 'website/p/lamp%3A.html'),
) as $case => $row) {
    [$gridPath, $lampUrl, $chairUrl, $schema, $expected, $owner] = $row;
    $detailPath = $row[6] ?? 'p/lamp.html';
    $grid = '<main><h1>Home furnishings</h1><div style="display:grid;grid-template-columns:repeat(2,1fr)">' . $card('Lamp', $lampUrl) . $card('Chair', $chairUrl) . '</div></main>';
    $detail = '<head>' . $schema . '</head><main><h1>Lamp</h1><p>A reading light.</p>' . $purchase . '</main>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => $gridPath, 'files' => array($gridPath => $grid, $detailPath => $detail)))->toArray();
    $assert('failed' !== $result['status'], $case . ': compiles');
    $grids = array_values(array_filter($result['fallbacks'] ?? array(), static fn (array $f): bool => 'html_product_grid_fallback' === ($f['diagnostic_code'] ?? null)));
    $assert(1 === count($grids), $case . ': trunk recognizes the product grid');
    $purchases = array_values(array_filter($result['fallbacks'] ?? array(), static fn (array $f): bool => 'html_commerce_controls_fallback' === ($f['diagnostic_code'] ?? null) && 'commerce_add_to_cart_form' === ($f['binding']['role'] ?? null) && $detailPath === ($f['source'] ?? null)));
    $assert(1 === count($purchases), $case . ': trunk recognizes the formless purchase control');
    $rows = array();
    $plan = $result['source_reports']['wordpress_site_plan'] ?? array();
    foreach ($plan['runtime_declarations'] ?? array() as $declaration) {
        if ('products' === ($declaration['type'] ?? null)) $rows = array_column($declaration['payload']['entities'], null, 'slug');
    }
    $assert(count($rows) >= 1, $case . ': declared card products');
    $bindings = array_values(array_filter($rows[$owner]['bindings'] ?? array(), static fn (array $b): bool => 'commerce_add_to_cart_form' === ($b['role'] ?? null)));
    $assert($expected === count($bindings), $case . ': expected ' . $expected . ' purchase binding, got ' . count($bindings));
    foreach ($rows as $slug => $row) {
        if ($slug === $owner) continue;
        $assert(!in_array('commerce_add_to_cart_form', array_column($row['bindings'] ?? array(), 'role'), true), $case . ': no other product owns purchase');
    }
    foreach ($bindings as $binding) {
        $assert($detailPath === $binding['source_path'], $case . ': purchase belongs to detail page');
        $assert(isset($binding['projected_anchor']), $case . ': canonical projection retained');
        foreach ($plan['pages'] as $page) {
            if ($detailPath === $page['source_path']) $assert(str_contains($page['canonical_block_markup'], $binding['search_block_markup']), $case . ': anchor exists in detail markup');
        }
        $assert($purchases[0]['controls'] == $rows[$owner]['controls'], $case . ': purchase controls retained');
        if (isset($purchases[0]['presentation_graph'])) $assert($purchases[0]['presentation_graph'] == ($rows[$owner]['presentation_graph'] ?? null), $case . ': presentation graph retained');
    }
}
echo "card-product-page-formless-add-to-cart-binding: ok\n";

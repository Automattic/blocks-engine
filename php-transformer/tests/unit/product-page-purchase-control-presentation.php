<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormPresentationGraphBuilder;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$cases = array(
    'form' => array('form method="post" action="/cart/add"', 'form', 'button', 'type="submit"', 'submit'),
    'formless' => array('div class="purchase"', 'div', 'button', 'type="button"', 'button'),
    'formless role button' => array('div class="purchase"', 'div', 'div', 'role="button" tabindex="0"', 'button'),
);
foreach ($cases as $case => [$opening, $closing, $buttonTag, $buttonAttributes, $buttonType]) {
    $html = '<!doctype html><html><head><title>Desk Lamp</title>'
        . '<link rel="stylesheet" href="/purchase.css">'
        . '<style>input[type=number]{padding:7px}</style>'
        . '<style>@import url("/purchase-mobile.css") (max-width:750px);</style>'
        . '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","name":"Desk Lamp","url":"https://example.test/products/desk-lamp","offers":{"@type":"Offer","price":"40.00","priceCurrency":"USD"}}</script>'
        . '</head><body><main><h1>Desk Lamp</h1><p>$40.00</p>'
        . '<' . $opening . '>'
        . '<label>Finish<select name="finish"><option>Brass</option><option>Black</option></select></label>'
        . '<label>Quantity<input type="number" name="quantity" min="1" value="1"></label>'
        . '<' . $buttonTag . ' ' . $buttonAttributes . ' class="cart" name="add">Add to cart</' . $buttonTag . '>'
        . '</' . $closing . '></main></body></html>';
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'products/desk-lamp.html',
        'files' => array(
            'products/desk-lamp.html' => $html,
            'products/purchase-mobile.css' => '.cart{background:#45a2e4}',
            'purchase.css' => ':root{--purchase-radius:13px;--purchase-border-color:black}select{border-radius:var(--purchase-radius)}.cart{border:2px solid var(--purchase-border-color)}',
        ),
    ))->toArray();
    $assert('failed' !== $result['status'], "$case: Product detail page compiles.");
    $product = array();
    foreach ($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration) {
        if ('products' !== ($declaration['type'] ?? null)) continue;
        foreach ($declaration['payload']['entities'] as $entity) {
            if ('desk-lamp' === ($entity['slug'] ?? null)) $product = $entity;
        }
    }
    $controls = $product['controls'] ?? array();
    $assert(array('select', 'input', $buttonTag) === array_column($controls, 'tag'), "$case: Product carries all purchase controls in source order.");
    $assert(array('finish', 'quantity', 'add') === array_column($controls, 'name'), "$case: Control names survive.");
    $assert('number' === ($controls[1]['type'] ?? null), "$case: Quantity remains a number input.");
    $assert($buttonType === ($controls[2]['type'] ?? null), "$case: Purchase button retains its type.");
    $graph = $product['presentation_graph'] ?? array();
    FormPresentationGraphBuilder::assertValid($graph);
    $assert(true !== ($graph['truncated'] ?? false), "$case: Product presentation graph is complete.");
    $styles = array();
    foreach ($graph['controls'] as $row) {
        $assert(isset($controls[$row['index']]), "$case: Presentation indexes address the product control list.");
        $styles[$row['index']] = $row['control']['styles'] ?? array();
    }
    $assert(3 === count($styles), "$case: All three controls carry presentation.");
    $assert(!str_contains($styles[0]['border_radius'] ?? '', 'var('), "$case: Select border radius resolves author custom properties.");
    $assert(!str_contains($styles[2]['border'] ?? '', 'var('), "$case: Cart button border resolves author custom properties.");
    $assert('13px' === ($styles[0]['border_radius'] ?? null), "$case: Select border radius survives the product handoff.");
    $assert('7px' === ($styles[1]['padding'] ?? null), "$case: Quantity padding survives the product handoff.");
    $assert('2px solid black' === ($styles[2]['border'] ?? null), "$case: Cart button border survives the product handoff.");
    $mobile = array_values(array_filter($graph['variants'] ?? array(), static fn (array $variant): bool => 2 === ($variant['index'] ?? null) && 'control' === ($variant['role'] ?? null)));
    $assert(array('kind' => 'media', 'query' => '(max-width:750px)') === ($mobile[0]['condition'] ?? null) && '#45a2e4' === ($mobile[0]['style_patch']['background'] ?? null), "$case: A root-relative @import in an inline style carries its media-gated cart button rule.");
}
echo "product-page-purchase-control-presentation: ok\n";

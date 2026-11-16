<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlCompilation;

// Both captured documents contain the same cart drawer and checkout control (#6213).
$drawer = '<div id="CartDrawer" data-blocks-engine-captured-dialog="true" data-blocks-engine-triggers="CartLink"><div class="drawer"><button type="submit" id="Checkout" name="checkout">Checkout</button></div></div>';
$html = '<!doctype html><html><body>';
foreach (array('desktop', 'mobile') as $device) {
    $html .= '<div class="data-blocks-engine-capture-' . $device . '-document"><header><h1>Shop</h1><a href="/account">Account</a><a id="CartLink" href="/cart" aria-controls="CartDrawer">Cart</a>' . $drawer . '</header></div>';
}
$html .= '</body></html>';
$compilation = new HtmlCompilation();
$result = $compilation->transform($html)->toArray();
// As in wrapper-shell-binding-identity.php, resolve explicit cart-link records
// against the converted tree independently of commerce reporting.
$finalizer = (new ReflectionProperty($compilation, 'fallbackBindings'))->getValue($compilation);
$session = (new ReflectionProperty($compilation, 'session'))->getValue($compilation);
$blocks = $result['blocks'];
$bindings = array();
$ids = array('Checkout', 'Checkout--data-blocks-engine-capture-mobile');
$anchor = static function (array &$siblings) use (&$anchor, &$bindings, $ids): void {
    foreach ($siblings as &$block) {
        foreach ($ids as $index => $id) {
            if (!str_contains($block['innerHTML'] ?? '', 'id="' . $id . '"')) continue;
            // Source selectors are distinct, but both retain the original ID.
            $selector = '.variant-' . $index . ' #Checkout';
            $block['_source_anchor_selector'] = $selector;
            $bindings[] = array('binding' => array('role' => 'commerce_cart_link', '_binding_anchor_selector' => $selector));
        }
        $anchor($block['innerBlocks']);
    }
};
$anchor($blocks);
if (2 !== count($bindings)) throw new RuntimeException('Both checkout copies must be emitted.');
$finalizer->finalize($bindings, $blocks, $result['serialized_blocks']);
foreach ($bindings as $index => $finding) {
    $binding = $finding['binding'];
    if (!str_contains($binding['search_block_markup'] ?? '', 'id="' . $ids[$index] . '"')) throw new RuntimeException('Each variant must resolve its own checkout binding.');
    $position = $binding['position'];
    if ($binding['search_block_markup'] !== substr($result['serialized_blocks'], $position['offset'], $position['length'])) throw new RuntimeException('Binding position must address its exact serialized block.');
}
foreach ($session->transformationEvidenceState()->conversionDiagnostics() as $diagnostic) {
    if ('runtime_binding_declined' === ($diagnostic['code'] ?? '')) throw new RuntimeException('Valid variant bindings must not be declined.');
}
echo "document-variant-binding-identity: ok\n";

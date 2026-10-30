<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};
$totals = '<span>Subtotal</span><span>$0.00</span><button name="checkout">Checkout</button>';
$cards = '<main><article><span>$10.00</span></article><article><span>$10.00</span></article></main>';
foreach (array(
    'issue fixture' => array('<div aria-hidden="true"><form action="/cart">' . $totals . '</form></div>', '', true, true),
    'hidden attribute' => array('<div hidden><form>' . $totals . '</form></div>', '', true, true),
    'runtime boundary' => array('<div aria-hidden="true" data-basket>' . $totals . '</div>', "document.querySelector('[data-basket]');", true, true),
    'page cart' => array('<main><form>' . $totals . '</form></main>', '', true, false),
    'main landmark role' => array('<div role="main"><div hidden><form>' . $totals . '</form></div></div>', '', true, false),
    'two checkout controls' => array('<div aria-hidden="true"><form>' . $totals . '<button>Checkout</button></form></div>', '', true, true),
    'landmark boundary' => array('<aside><button>Checkout</button></aside><span>Total</span><span>$0.00</span>', '', false, false),
    'no local totals' => array('<div aria-hidden="true"><form><button name="checkout">Checkout</button></form></div>', '', false, false),
    'unbounded checkout' => array('<button name="checkout">Checkout</button>', '', false, false),
) as $label => [$drawer, $script, $expected, $chrome]) {
    $html = '<!doctype html><html><head><title>Shop</title></head><body>' . $cards . $drawer . ($script ? '<script src="app.js"></script>' : '') . '</body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'app.js' => $script)))->toArray();
    $findings = array_values(array_filter($result['fallbacks'] ?? array(), static fn (array $finding): bool => 'html_cart_view_fallback' === ($finding['diagnostic_code'] ?? null)));
    $assert(($expected ? 1 : 0) === count($findings), $label . ': exactly one local cart, or none without local totals: ' . json_encode(array_column($findings, 'selector')));
    foreach ($findings as $finding) {
        $assert('body' !== ($finding['tag'] ?? '') && '' !== ($finding['selector'] ?? ''), $label . ': cart has a bounded selector');
        $binding = $finding['binding'] ?? array();
        $assert('commerce_cart' === ($binding['role'] ?? '') && '' !== ($binding['search_block_markup'] ?? ''), $label . ': cart is bindable');
        $assert($chrome === ('site_chrome' === ($finding['placement'] ?? '')), $label . ': only hidden carts outside main are site chrome');
    }
}
echo "hidden-cart-view-scope: ok\n";

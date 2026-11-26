<?php
declare(strict_types=1);

/**
 * A cart link the capture proves is never displayed is not purchase evidence.
 *
 * Some site builders put a cart widget on every page and hide it when the site
 * has no store. Without purchase evidence, priced cards with a photo (trips,
 * events, services with an estimated cost) must stay content, not become a
 * product catalog. The capture's box tree is what proves the widget is hidden:
 * the hiding rule lives in an external stylesheet.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/box-tree-evidence-fixture.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$card = static fn (int $n): string => '<li class="item"><img src="/media/photo-' . $n . '.jpg" alt="Trip ' . $n . '"><h3>Trip ' . $n . '</h3><p>A week of volunteer work.</p><p>Estimated cost: $1,2' . $n . '0</p><a class="button" href="#">Learn more</a></li>';
$html = '<html><head><title>Trips</title><link rel="stylesheet" href="s.css"></head><body>'
    . '<div id="cart-widget" class="cart-widget hidden" data-blocks-engine-node-id="w"><a href="/cart" data-blocks-engine-node-id="l">Cart</a></div>'
    . '<main><h1>Upcoming trips</h1><ul class="list">' . $card(1) . $card(2) . $card(3) . $card(4) . '</ul></main></body></html>';

$products = static function (array $widget) use ($html): int {
    $files = array(
        array('path' => 'index.html', 'kind' => 'html', 'content' => $html),
        array('path' => 's.css', 'kind' => 'css', 'content' => '.list{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}.hidden{display:none}'),
        box_tree_fixture_sidecar('index.html', $html, array('w' => $widget, 'l' => array())),
    );
    $result = (new ArtifactCompiler())->compile(array('files' => $files))->toArray();
    return substr_count((string) json_encode($result, JSON_UNESCAPED_SLASHES), '"html_product_grid_fallback"');
};

$hidden = array('box' => array(0, 0, 0, 0), 'styles' => array('display' => 'none'));
$assert(0 === $products($hidden), 'A cart link inside a widget hidden at every captured viewport is not purchase evidence, so priced photo cards stay content.');
$assert(0 < $products(array('box' => array(0, 0, 60, 40))), 'A displayed cart link is still purchase evidence for the same cards.');
$assert(0 < $products(array('box' => array(0, 0, 60, 40), 'viewports' => array('mobile' => array('box' => array(0, 0, 0, 0), 'styles' => array('display' => 'none'))))), 'A cart link hidden at only some viewports is still purchase evidence.');

echo "PASS hidden cart is not purchase evidence\n";

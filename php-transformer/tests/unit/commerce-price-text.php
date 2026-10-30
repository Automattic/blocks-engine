<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CommerceStructureRecognizer;

$recognizer = new CommerceStructureRecognizer();
foreach (array(
    array('$30.00 USD', true),
    array('€30,00', true),
    array("30,00\u{00a0}$ USD", true),
    array('30,00 $', true),
    array('30,00 €', true),
    array("30,00\u{00a0}$", true),
    array('30,00€', true),
    array("€\u{00a0}30,00", true),
    array("30.00\u{00a0}USD", true),
    array("1\u{00a0}234,56\u{00a0}¥", true),
    array('30.00 USD', true),
    array('30', false),
    array('2024', false),
    array('30,00', false),
    array('Desk Lamp', false),
    array('24 hours', false),
    array('€', false),
) as [$text, $expected]) {
    if ($expected !== $recognizer->looksLikePriceText($text)) {
        throw new RuntimeException('Unexpected price recognition for ' . json_encode($text));
    }
}
// Recognition must also yield usable card prices, not just classify the leaf.
foreach (array('30,00 $', '30,00 €', "30,00\u{00a0}$", "€\u{00a0}30,00") as $price) {
    $document = new DOMDocument();
    $document->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><article><h2>Desk Lamp</h2><p>' . $price . '</p><button class="add-to-cart">Add to cart</button></article></body></html>');
    $card = $document->getElementsByTagName('article')->item(0);
    $product = $recognizer->productCardData($card);
    if ($price !== ($product['price'] ?? null)) {
        throw new RuntimeException('Localized card price was not extracted: ' . json_encode($price));
    }
}
echo "commerce-price-text: ok\n";

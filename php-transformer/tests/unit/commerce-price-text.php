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
    array('$1,299+', true),
    // Aggregate statistics, not prices.
    array('$370M+', false),
    array('$2.5 billion', false),
    array('$5k', false),
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
// A stats row with a "Buy" navigation link is not a product card.
$document = new DOMDocument();
$document->loadHTML('<!DOCTYPE html><html><body><div><h2>Areas we serve</h2><p>$370M+ Total Sales Volume</p><img src="/team.webp" alt=""><a href="https://search.example.com/">Buy</a></div></body></html>');
if (null !== $recognizer->productCardData($document->getElementsByTagName('div')->item(0))) {
    throw new RuntimeException('An aggregate sales figure was recognized as a product price');
}
// A page whose header menu has a "Buy" link is not a product card either.
$document = new DOMDocument();
$document->loadHTML('<!DOCTYPE html><html><body><div><nav><a href="https://search.example.com/">Buy</a><a href="/sell">Sell</a></nav><h2>Learn more about the community</h2><p>$499,900</p></div></body></html>');
if (null !== $recognizer->productCardData($document->getElementsByTagName('div')->item(0))) {
    throw new RuntimeException('A navigation menu link was recognized as a cart control');
}
echo "commerce-price-text: ok\n";

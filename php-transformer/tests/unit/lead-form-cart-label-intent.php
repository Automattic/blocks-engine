<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormRuntimeRequirementAnalyzer;

// A submit label names an add-to-cart action, but only a form that carries the
// item is a cart form. A lead form asking for the visitor's email stays a
// contact form, so it reaches a form provider instead of an absent product.
$cases = array(
    'lead form with cart label' => array('<form><input name="fname"><input type="email"><textarea></textarea><button type="submit">Add to Cart or Get a Quote</button></form>', 'contact'),
    'product form with cart label' => array('<form><select name="size"><option>S</option></select><button type="submit">Add to Cart</button></form>', 'commerce_add_to_cart'),
);
$failures = array();
foreach ( $cases as $name => [$html, $expected] ) {
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8"?><html><body>' . $html . '</body></html>', LIBXML_NOERROR);
    $actual = FormRuntimeRequirementAnalyzer::formIntent($document->getElementsByTagName('form')->item(0));
    if ( $expected !== $actual ) $failures[] = "{$name}: expected {$expected}, got {$actual}";
}
if ( array() !== $failures ) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
echo "lead form cart label intent: ok\n";

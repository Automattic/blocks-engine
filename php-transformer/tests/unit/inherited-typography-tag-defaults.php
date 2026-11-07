<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Equal parent values are redundant only when the child actually inherits them.
$cases = array(
    'link color kept' => array( '<p style="color:#fff"><a href="#" style="color:#fff">x</a></p>', 'color:#fff', true ),
    'strong weight kept' => array( '<p style="font-weight:400"><strong style="font-weight:400">x</strong></p>', 'font-weight:400', true ),
    'span color still pruned' => array( '<p style="color:#fff"><span style="color:#fff">x</span></p>', 'color:#fff', false ),
);
$failures = array();
foreach ( $cases as $label => list( $html, $declaration, $kept ) ) {
    $markup = (new HtmlTransformer())->transform($html)->toArray()['serialized_blocks'];
    $document = new DOMDocument();
    $document->loadHTML($markup, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    // Inspect descendants, not the paragraph's own declaration. RichText may
    // carry the declaration on a core-native mark inside the semantic tag.
    $carriers = $xpath->query('//p//*[contains(@style,"' . $declaration . '")]');
    if ( $kept !== ($carriers->length > 0) ) {
        $failures[] = "FAIL: {$label}\n{$markup}";
    }
}
if ( array() !== $failures ) {
    throw new RuntimeException(implode("\n", $failures));
}

echo "Inherited typography tag defaults passed\n";

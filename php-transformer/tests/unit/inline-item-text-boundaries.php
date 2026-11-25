<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Adjacent inline contacts have no separator in the source. Independent
// paragraphs add innerText separators even when their display is inline.
foreach ( array( '.contact{display:inline}', '.contact{display:inline}@media(max-width:600px){.contact{display:block}}' ) as $css ) {
    $result = (new HtmlTransformer())->transform(
        '<style>' . $css . '</style><div><span class="contact">Address</span>'
        . '<span class="contact">Phone</span><a class="contact" href="mailto:a@b.test">Email</a></div>'
    )->toArray();
    $markup = $result['serialized_blocks'];
    if ( 1 !== substr_count($markup, '<!-- wp:paragraph ') ) {
        throw new RuntimeException('Inline items must share one paragraph: ' . $markup);
    }
    $text = strip_tags(preg_replace('/<!--.*?-->/s', '', $markup));
    if ( 'AddressPhoneEmail' !== $text ) {
        throw new RuntimeException('Inline adjacency changed: ' . $text);
    }
}

// Unspecified display and atomic items retain their independent carriers.
foreach ( array( '', '.contact{display:inline-block}' ) as $css ) {
    $result = (new HtmlTransformer())->transform(
        '<style>' . $css . '</style><div><span class="contact">Address</span><span class="contact">Phone</span></div>'
    )->toArray();
    if ( 2 !== substr_count($result['serialized_blocks'], '<!-- wp:paragraph ') ) {
        throw new RuntimeException('Unspecified or atomic items lost their independent carriers.');
    }
}
echo "PASS: inline item text boundaries\n";

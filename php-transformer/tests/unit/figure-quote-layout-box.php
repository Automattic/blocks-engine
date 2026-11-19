<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

$result = (new HtmlTransformer())->transform(
    '<style>.rail{display:flex}.card{flex:0 0 340px;min-height:112px;padding:24px 36px 20px;border-right:1px solid #ccc;margin:0}'
    . '.card blockquote{margin:0 0 12px;font-size:14px;line-height:1.45}'
    . '.card figcaption{font-size:9px;line-height:1.5}</style>'
    . '<div class="rail"><figure class="card"><blockquote>A quotation</blockquote><figcaption>Publication</figcaption></figure></div>'
)->toArray();
$card = $result['blocks'][0]['innerBlocks'][0] ?? array();
$quote = $card['innerBlocks'][0] ?? array();
$caption = $card['innerBlocks'][1] ?? array();
if ('core/group' !== ($card['blockName'] ?? '')
    || 'core/quote' !== ($quote['blockName'] ?? '')
    || 'core/paragraph' !== ($caption['blockName'] ?? '')
    || !str_contains($caption['attrs']['content'] ?? '', 'Publication')
    || '9px' !== ($caption['attrs']['style']['typography']['fontSize'] ?? '')
    || '1.5' !== ($caption['attrs']['style']['typography']['lineHeight'] ?? '')
    || '0' !== ($caption['attrs']['style']['spacing']['margin']['top'] ?? '')
    || '0' !== ($caption['attrs']['style']['spacing']['margin']['bottom'] ?? '')
    || isset($caption['attrs']['leftover'])
    || isset($caption['attrs']['attrs'])
    || !str_contains($result['serialized_blocks'], 'wp-block-quote')
    || !str_contains($card['attrs']['className'] ?? '', 'card')
    || str_contains($quote['attrs']['className'] ?? '', 'card')
    || 'pass' !== ((new BlockValidityValidator())->validateBlocks($result['blocks'])['status'] ?? '')
) {
    throw new RuntimeException('A laid-out figure must keep its box separate from its quotation and caption: ' . $result['serialized_blocks']);
}
// Ordinary styled figures still collapse to the established native quote.
// Neither padding alone nor flex sizing outside a flex parent creates the
// separate flex-item box this regression covers.
foreach ( array('padding:24px;border:1px solid #ccc', 'flex:0 0 340px') as $style ) {
    $ordinary = (new HtmlTransformer())->transform(
        '<figure style="' . $style . '"><blockquote>A quotation</blockquote><figcaption>Publication</figcaption></figure>'
    )->toArray();
    if ( 'core/quote' !== ($ordinary['blocks'][0]['blockName'] ?? '') ) {
        throw new RuntimeException('Unrelated styled figures must retain native quote lowering.');
    }
}

$generic = (new HtmlTransformer())->transform(
    '<figure><p>First line.</p><p>Second line.</p><figcaption>Combined notes</figcaption></figure>'
)->toArray();
if ( !str_contains($generic['serialized_blocks'], '<!-- wp:paragraph --><p>Combined notes</p><!-- /wp:paragraph -->') ) {
    throw new RuntimeException('Generic figure captions must retain their existing presentation.');
}
echo "Figure quote layout box passed\n";

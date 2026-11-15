<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\BlockFactory;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$result = (new HtmlTransformer())->transform(
    '<blockquote style="display:flex;flex-direction:column;gap:1.875rem"><p>A quiet observation.</p><p>Another thought.</p></blockquote>'
)->toArray();
$runtime = new Runtime();
$markup = (string) $result['serialized_blocks'];
$blocks = $runtime->parseBlocks($markup);
$quote = $blocks[0] ?? array();
if ( 'core/quote' !== ($quote['blockName'] ?? null) ) {
    throw new RuntimeException('The flex-column blockquote must become core/quote.');
}
if ( str_contains((string) $quote['innerHTML'], 'gap:') ) {
    throw new RuntimeException('Layout blockGap must never become an inline gap.');
}
if ( '1.875rem' !== ($quote['attrs']['style']['spacing']['blockGap'] ?? null) ) {
    throw new RuntimeException('The quote must retain its editable layout blockGap.');
}
// Rebuild save markup from parsed attributes, not the stored wrapper HTML.
$saved = (new BlockFactory())->create('core/quote', $quote['attrs'], $quote['innerBlocks']);
if ( $runtime->serializeBlocks(array($saved)) !== $markup ) {
    throw new RuntimeException('The stored quote must equal its attribute-derived core save shape.');
}
if ( 'pass' !== ($runtime->validateBlockSerialization($markup)['status'] ?? null) ) {
    throw new RuntimeException('The quote must pass block serialization validation.');
}
fwrite(STDOUT, "Quote block gap save validity passed.\n");

<?php
declare(strict_types=1);

/**
 * A figure that wraps a blockquote next to another element but has no
 * figcaption is a common testimonial shape. It must convert to a quote
 * instead of passing a null caption to DOMNode::isSameNode().
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        throw new RuntimeException($message);
    }
};

$withoutCaption = ( new HtmlTransformer() )->transform('<figure class="testimonial"><blockquote><p>Great service, would book again.</p></blockquote><div class="author">Jane Doe</div></figure>')->toArray();
$card = $withoutCaption['blocks'][0] ?? array();
$cardJson = (string) json_encode($card);
$assert('core/group' === ($card['blockName'] ?? null) && str_contains($cardJson, '"blockName":"core\\/quote"'), 'A captionless figure with a blockquote and a sibling element keeps the quote inside a group.');
$assert(str_contains($cardJson, 'Great service, would book again.'), 'The quote keeps its text.');
$assert(str_contains($cardJson, 'Jane Doe'), 'The sibling element is not dropped.');

$withCaption = ( new HtmlTransformer() )->transform('<figure><blockquote><p>Great service.</p></blockquote><div aria-hidden="true">Decor</div><figcaption>Jane Doe</figcaption></figure>')->toArray();
$captioned = $withCaption['blocks'][0] ?? array();
$assert('core/quote' === ($captioned['blockName'] ?? null) && 'Jane Doe' === ($captioned['attrs']['citation'] ?? null), 'A figcaption still becomes the quote citation.');
$assert(! str_contains((string) json_encode($captioned['innerBlocks'] ?? array()), 'Jane Doe'), 'The figcaption is not repeated as quote content.');

echo "figure-quote-without-caption passed\n";

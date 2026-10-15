<?php
declare(strict_types=1);

/**
 * A figure holding a blockquote next to other content (a rating row) and a
 * figcaption with block children is a card, not a quote. It must stay a
 * group whose children each survive as blocks: the rating row, a core/quote
 * carrying the blockquote's own classes, and the caption's paragraphs. Only a
 * figure holding just the blockquote and a phrasing caption collapses into
 * core/quote, and RichText citations never receive block content.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        throw new RuntimeException($message);
    }
};

$find = static function (array $blocks, callable $match) use (&$find): ?array {
    foreach ( $blocks as $block ) {
        if ( $match($block) ) {
            return $block;
        }
        $found = $find($block['innerBlocks'] ?? array(), $match);
        if ( null !== $found ) {
            return $found;
        }
    }
    return null;
};

$star = '<svg viewBox="0 0 24 24" width="16" height="16"><path d="M12 2l3 7h7l-6 4 2 7-6-4-6 4 2-7-6-4h7z"/></svg>';
$html = '<figure class="card-shell"><div class="rating-row" aria-label="5 out of 5 stars">' . str_repeat($star, 5) . '</div>'
    . '<blockquote class="quote-body">They fixed it the same day.</blockquote>'
    . '<figcaption class="attribution"><p class="author-name">Alex P.</p><p class="author-meta">Customer since 2019</p></figcaption></figure>';
$result = ( new HtmlTransformer() )->transform($html)->toArray();
$blocks = $result['blocks'];
$serialized = (string) $result['serialized_blocks'];

$card = $blocks[0] ?? array();
$assert('core/group' === ($card['blockName'] ?? null) && 'card-shell' === ($card['attrs']['className'] ?? null), 'A figure with a non-quote sibling stays a group carrying the figure classes.');

$rating = $find($blocks, static fn (array $block): bool => 'rating-row' === ($block['attrs']['className'] ?? null));
$assert(null !== $rating, 'The rating row survives as a block with its source class.');
$assert(5 === substr_count(json_encode($rating) ?: '', '"blockName":"core\/image"'), 'All five rating icons survive.');

$quote = $find($blocks, static fn (array $block): bool => 'core/quote' === ($block['blockName'] ?? null));
$assert(null !== $quote && 'quote-body' === ($quote['attrs']['className'] ?? null), 'The blockquote becomes a core/quote carrying its own classes.');
$assert(str_contains(json_encode($quote) ?: '', 'They fixed it the same day.'), 'The quote keeps its text.');
$assert(! isset($quote['attrs']['citation']), 'A caption with block content is not folded into the RichText citation.');

$attribution = $find($blocks, static fn (array $block): bool => 'attribution' === ($block['attrs']['className'] ?? null));
$assert(null !== $attribution && 'core/group' === ($attribution['blockName'] ?? null), 'The figcaption survives as a group carrying its classes.');
$names = array_map(static fn (array $block): string => (string) ($block['attrs']['className'] ?? ''), $attribution['innerBlocks'] ?? array());
$assert(array( 'author-name', 'author-meta' ) === $names, 'The caption paragraphs survive as paragraph blocks with their classes.');

$assert(! preg_match('#<(?:cite|span)\b[^>]*>(?:(?!</(?:cite|span)>).)*<p\b#s', $serialized), 'No paragraph is nested inside a cite or span.');

// The plain shape still collapses into core/quote with its citation.
$plain = ( new HtmlTransformer() )->transform('<figure><blockquote><p>Great service.</p></blockquote><figcaption>Jane <strong>Doe</strong></figcaption></figure>')->toArray();
$plainQuote = $plain['blocks'][0] ?? array();
$assert('core/quote' === ($plainQuote['blockName'] ?? null) && 'Jane <strong>Doe</strong>' === ($plainQuote['attrs']['citation'] ?? null), 'A figure with only a blockquote and a phrasing caption still collapses into core/quote.');

echo "figure-quote-mixed-children passed\n";

<?php
declare(strict_types=1);

/**
 * A `<figure><blockquote>…</blockquote><figcaption>` collapses into one
 * core/quote: the figure's box becomes the quote, the blockquote's text its
 * paragraph and the figcaption its `<cite>`. Author rules written for the
 * figure's descendants (`.quote-item blockquote`, `.quote-item figcaption`)
 * must keep styling those same boxes, or the quote text and attribution fall
 * back to body typography (size, weight, case) and the marquee they sit in
 * measures and shows different text.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        throw new RuntimeException($message);
    }
};

$result = ( new HtmlTransformer() )->transform(
    '<style>.quote-item{padding:24px}'
    . '.quote-item blockquote{margin:0 0 12px;font-size:14px;font-style:italic}'
    . '.quote-item figcaption{text-transform:uppercase;font-size:9px;font-weight:700}</style>'
    . '<section class="hero-quotes"><figure class="quote-item"><blockquote>“A rare thing.”</blockquote>'
    . '<figcaption>Publication</figcaption></figure></section>'
)->toArray();

$find = static function (array $blocks, string $name) use (&$find): ?array {
    foreach ( $blocks as $block ) {
        if ( $name === ($block['blockName'] ?? '') ) {
            return $block;
        }
        $found = $find($block['innerBlocks'] ?? array(), $name);
        if ( null !== $found ) {
            return $found;
        }
    }
    return null;
};
$css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$markup = $result['serialized_blocks'];
$quote = $find($result['blocks'], 'core/quote');
$assert(null !== $quote && 'Publication' === trim(strip_tags((string) ($quote['attrs']['citation'] ?? ''))), 'The figure still collapses into a cited quote: ' . $markup);
$paragraph = $find($quote['innerBlocks'] ?? array(), 'core/paragraph');
$assert(null !== $paragraph, 'The quote keeps its text paragraph: ' . $markup);

// Every rule body for a declaration, keyed by its selector.
preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
$classesReach = static function (string $selector, array $classes): bool {
    preg_match_all('/\.([A-Za-z0-9_-]+)/', preg_replace('/:not\([^)]*\)/', '', $selector) ?? $selector, $required);
    return array() !== $required[1] && array() === array_diff($required[1], $classes);
};
$styles = static function (array $classes, string $declaration, bool $cite) use ($rules, $classesReach): bool {
    foreach ( $rules as $rule ) {
        if ( ! str_contains($rule[2], $declaration) ) {
            continue;
        }
        foreach ( explode(',', $rule[1]) as $selector ) {
            if ( $cite === (bool) preg_match('/>\s*cite\b/', $selector) && $classesReach($selector, $classes) ) {
                return true;
            }
        }
    }
    return false;
};
$paragraphClasses = preg_split('/\s+/', (string) ($paragraph['attrs']['className'] ?? '')) ?: array();
$quoteClasses = array_merge(array( 'wp-block-quote' ), preg_split('/\s+/', (string) ($quote['attrs']['className'] ?? '')) ?: array());
foreach ( array( 'font-size:14px', 'font-style:italic', 'margin:0 0 12px' ) as $declaration ) {
    $assert($styles($paragraphClasses, $declaration, false), 'The blockquote rule (' . $declaration . ') reaches the quote paragraph: ' . $markup . "\n" . $css);
}
foreach ( array( 'text-transform:uppercase', 'font-size:9px', 'font-weight:700' ) as $declaration ) {
    $assert($styles($quoteClasses, $declaration, true), 'The figcaption rule (' . $declaration . ') reaches the quote cite: ' . $markup . "\n" . $css);
}
$assert('pass' === ((new BlockValidityValidator())->validateBlocks($result['blocks'])['status'] ?? ''), 'The quote stays block-valid: ' . $markup);

echo "figure-quote-descendant-author-rules passed\n";

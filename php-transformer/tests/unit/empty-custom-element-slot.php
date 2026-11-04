<?php
declare(strict_types=1);

/**
 * An empty custom-element slot renders nothing: no text, no media, no
 * controls, no runtime metadata, and authored CSS that only sets `display`.
 * It contributes no blocks and records no html_unsupported_element fallback,
 * while text-bearing and image-bearing hosts keep their existing conversions.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$result = ( new HtmlTransformer() )->transform((string) file_get_contents(__DIR__ . '/../fixtures/empty-custom-element-slot.html'))->toArray();
$blocks = (string) ($result['serialized_blocks'] ?? '');
$codes  = array_column($result['fallbacks'] ?? array(), 'diagnostic_code');
$tags   = array_column($result['fallbacks'] ?? array(), 'tag');

// The surrounding page still converts natively.
$assert(str_contains($blocks, '<!-- wp:heading') && str_contains($blocks, 'Storefront'), 'the heading stays a native heading block');
$assert(str_contains($blocks, '<!-- wp:paragraph') && str_contains($blocks, 'Every order ships within a day.'), 'the paragraph stays a native paragraph block');

// Empty slots render nothing, so they are omitted: no fallback is recorded
// and no block carries the slot or its placeholder children.
$assert(! in_array('html_unsupported_element', $codes, true), 'the empty slots record no html_unsupported_element fallback');
$assert(! in_array('x-slot', $tags, true), 'the empty slots record no finding of their own');
$assert(! str_contains($blocks, 'x-slot'), 'the empty slots produce no block');
$assert(! str_contains($blocks, 'data-hook'), 'the empty slot placeholders produce no block');

// A custom element carrying text keeps its content conversion.
$assert(str_contains($blocks, '<!-- wp:paragraph') && str_contains($blocks, 'Live availability note'), 'the text-bearing host keeps its text as a paragraph');

// A custom element carrying an image keeps its media conversion.
$assert(str_contains($blocks, 'x-media') && str_contains($blocks, '/media/hero.png'), 'the image-bearing host keeps its media markup');

if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", array_map(static fn (string $message): string => 'FAIL: ' . $message, $failures)) . "\n");
    exit(1);
}

echo "Empty custom element slot tests passed\n";

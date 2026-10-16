<?php
declare(strict_types=1);

/**
 * A text-only span that is its own layout item (a flex or grid child) becomes
 * the paragraph itself: the paragraph takes the span's class and presentation,
 * and author rules reach it through a block-level semantic marker at the
 * source selector's specificity. It used to be a boxless carrier paragraph
 * around the span, and an element with no box cannot take focus, so the
 * editor could not select or edit its text. A span inside a link keeps the
 * carrier, which pushes the link down around its text.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};
$css = static function (array $result): string {
    return implode("\n", array_map(static fn (array $asset): string => (string) ( $asset['content'] ?? '' ), (array) ( $result['assets'] ?? array() )));
};
$paragraph = static fn (string $class, string $text): string => '/<p class="' . ( '' === $class ? '' : preg_quote($class, '/') . ' ' ) . 'blocks-engine-synthetic-paragraph blocks-engine-semantic-[^"]*"[^>]*>' . preg_quote($text, '/') . '<\/p>/';

$row = ( new HtmlTransformer() )->transform(
    '<style>.row{display:flex;gap:14px;align-items:center}.row .a{font-weight:700;color:#a40}.row > span{letter-spacing:.1em}@media (max-width:600px){.row .a{color:#00a}}</style>'
    . '<div class="row"><span class="a">Alpha label</span><span>Beta meta</span></div>'
)->toArray();
$rowMarkup = (string) ( $row['serialized_blocks'] ?? '' );
$rowCss = $css($row);
$assert(1 === preg_match($paragraph('a', 'Alpha label'), $rowMarkup) && 1 === preg_match($paragraph('', 'Beta meta'), $rowMarkup), 'each text-only flex span is its own paragraph, keeping its class', $rowMarkup);
$assert(! str_contains($rowMarkup, 'blocks-engine-inline-layout-carrier') && ! str_contains($rowMarkup, '<span'), 'no boxless carrier is left around the span text');
$assert(1 === preg_match('/:where\(\.blocks-engine-semantic-[^)]*\)[^{]*\{font-weight:700;color:#a40\}/', $rowCss), 'a descendant class rule reaches the paragraph through its semantic marker', $rowCss);
$assert(1 === preg_match('/@media \(max-width:600px\)\{:where\(\.blocks-engine-semantic-[^)]*\)[^{]*\{color:#00a\}\}/', $rowCss), 'a conditional rule still reaches the paragraph');
$assert(1 === preg_match('/:where\(\.blocks-engine-semantic-[^)]*\)[^{]*\{letter-spacing:\.1em\}/', $rowCss), 'a child-combinator type rule reaches the retagged spans');
$assert(str_contains($rowCss, ':where(p.blocks-engine-synthetic-paragraph:not(.blocks-engine-source-box-paragraph)){display:inline}'), 'the paragraph has an inline box, which the flex parent blockifies like the span');
$assert('pass' === ( $row['source_reports']['wp_block_validity']['status'] ?? '' ), 'the paragraphs are editor-valid');

$linked = ( new HtmlTransformer() )->transform(
    '<style>.brand{display:flex;gap:8px}.brand .name{font-weight:700}</style>'
    . '<a class="brand" href="/"><span class="name">Studio</span><span class="tag">Since 1999</span></a>'
)->toArray();
$linkedMarkup = (string) ( $linked['serialized_blocks'] ?? '' );
$assert(str_contains($linkedMarkup, 'href="/"') && ! preg_match($paragraph('name', 'Studio'), $linkedMarkup), 'a span inside a link keeps its carrier so the link stays around its text', $linkedMarkup);

if ( $failures > 0 ) {
    fwrite(STDERR, "text span layout leaf paragraph: {$failures} failed, {$passes} passed\n");
    exit(1);
}
echo "text span layout leaf paragraph passed: {$passes} assertions\n";

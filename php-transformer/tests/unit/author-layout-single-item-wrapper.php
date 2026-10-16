<?php
declare(strict_types=1);

/**
 * A CSS-owned grid whose only child is an anonymous source wrapper keeps that
 * wrapper as its single grid item in the rendered markup. Flattening the
 * wrapper promoted its children to grid items, so a heading stacked under an
 * eyebrow moved into the next grid column.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures): void {
    if ( ! $ok ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
    }
};

$html = '<html><head><style>'
    . '.section-head{display:grid;grid-template-columns:1fr 1fr;gap:40px}'
    . '.eyebrow{text-transform:uppercase}'
    . '</style></head><body><main><section class="wrap"><div class="section-head">'
    . '<div><p class="eyebrow">01 · Portfolio</p><h2>Selected work</h2></div>'
    . '</div><p>Body copy that follows the section heading.</p></section></main></body></html>';
$markup = (string) (( new HtmlTransformer() )->transform($html, array())->toArray()['serialized_blocks'] ?? '');

$document = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$document->loadHTML('<?xml encoding="utf-8" ?><body>' . preg_replace('/<!--.*?-->/s', '', $markup) . '</body>');
libxml_use_internal_errors($previous);
$grid = ( new DOMXPath($document) )->query('//*[contains(concat(" ", normalize-space(@class), " "), " section-head ")]')->item(0);
$items = array();
foreach ( $grid?->childNodes ?? array() as $node ) {
    if ( $node instanceof DOMElement ) {
        $items[] = $node;
    }
}
$assert($grid instanceof DOMElement, 'The grid container renders.', $markup);
$assert(1 === count($items), 'The grid renders exactly one direct item, matching the source.', 'items=' . count($items));
$assert(
    1 === count($items) && 'div' === $items[0]->tagName
        && 1 === $items[0]->getElementsByTagName('p')->length
        && 1 === $items[0]->getElementsByTagName('h2')->length,
    'The eyebrow and heading stay stacked inside the one grid item.',
    $markup
);

if ( $failures > 0 ) {
    fwrite(STDERR, "author layout single-item wrapper failed ({$failures})\n");
    exit(1);
}
fwrite(STDOUT, "author layout single-item wrapper passed\n");

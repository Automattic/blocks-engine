<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

/**
 * A materialized inline SVG inside a flow-content anchor gets no link of its own.
 *
 * A link-styled button projects as the layout shell's `custom/layout-shell`
 * wrapper with tagName `a`, and every block the source anchor holds stays
 * inside that output anchor — including the synthetic paragraph that hosts a
 * materialized icon image. Materialization used to re-wrap that image in an
 * `<a href>` to the same destination, nesting anchor inside anchor. The HTML
 * parser closes the outer anchor when it reaches the inner one, so the button
 * rendered as an empty box and its label fell outside it, while the import
 * report still counted no content loss.
 */

$sprite = '<svg style="display:none"><symbol id="btn-icon" viewBox="0 0 24 24"><path d="M2 12h20M12 2v20" stroke="currentColor"/></symbol></svg>';
$button = '<a class="btn" href="/contact/" style="background-color:#1f6bff;border-radius:4px">%s<div><div><p>Start your project</p></div></div></a>';

$failures = array();

$check = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

/**
 * Parse serialized block markup and assert the anchor invariants: no anchor
 * nests inside another anchor, and the outer button anchor keeps both its
 * icon image and its label text.
 */
$assertSingleAnchor = static function (string $markup, string $label) use ($check): void {
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8"?>' . $markup, LIBXML_NOERROR);

    $anchors = $document->getElementsByTagName('a');
    $check(1 === $anchors->length, $label . ' serializes exactly one anchor: ' . $markup);

    foreach ( $anchors as $anchor ) {
        for ( $parent = $anchor->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
            $check('a' !== strtolower($parent->tagName), $label . ' does not nest an anchor inside an anchor: ' . $markup);
        }
    }

    $outerAnchor = $anchors->length > 0 ? $anchors->item(0) : null;
    if ( ! $outerAnchor instanceof DOMElement ) {
        return;
    }

    $check('/contact/' === $outerAnchor->getAttribute('href'), $label . ' keeps the source anchor as the outer clickable container: ' . $markup);
    $check('btn' === $outerAnchor->getAttribute('class'), $label . ' keeps the source anchor identity: ' . $markup);

    $icon = null;
    foreach ( $outerAnchor->getElementsByTagName('img') as $image ) {
        if ( str_contains((string) $image->getAttribute('src'), 'assets/materialized-svg/inline-svg-') ) {
            $icon = $image;
            break;
        }
    }
    $check(null !== $icon, $label . ' keeps the materialized icon image inside the outer anchor: ' . $markup);

    $check(str_contains($outerAnchor->textContent, 'Start your project'), $label . ' keeps the label text inside the outer anchor: ' . $markup);
};

foreach ( array(
    'sprite use icon' => sprintf($button, '<svg role="presentation" viewBox="0 0 24 24"><use href="#btn-icon"></use></svg>'),
    'inline path icon' => sprintf($button, '<svg role="presentation" viewBox="0 0 24 24"><path d="M2 12h20M12 2v20" stroke="currentColor"/></svg>'),
) as $label => $source ) {
    $markup = (string) ( new HtmlTransformer() )->transform($sprite . $source)->toArray()['serialized_blocks'] ?? '';
    $assertSingleAnchor($markup, $label);
}

if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Materialized SVG inside a flow-content anchor stays link-free passed.\n";

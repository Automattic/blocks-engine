<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Removes source comments from RichText content while keeping the text-run
 * boundaries they made.
 *
 * Empty comments are discarded without introducing a carrier. Frameworks
 * also split visible strings with whitespace comments (React writes
 * `"<!-- -->{text}<!-- -->"`). Browsers shape each text node on its own, so
 * kerning never crosses that boundary. Joining the runs
 * into one text node shapes the pairs at the seams differently and moves the
 * glyphs that follow.
 *
 * The editor keeps a comment as a visible, non-editable chip, so a comment
 * is not RichText content. An element edge is a boundary the editor keeps
 * invisibly: every other run is wrapped in the engine's neutral RichText
 * carrier (`<mark role="none">` with a transparent background and inherited
 * color). Author `span` rules never reached these runs in the source, and
 * they do not reach a `mark`.
 */
final class CommentTextRunBoundaries
{
    /** The engine's neutral RichText carrier: no highlight, no own color. */
    private const CARRIER_STYLE = 'background-color:transparent;color:inherit';

    /** Whether a mark is one of these neutral seam carriers, exactly as {@see apply()} writes it. */
    public static function isCarrier(DOMElement $mark): bool
    {
        return 'mark' === strtolower($mark->tagName)
            && 2 === $mark->attributes->length
            && 'none' === $mark->getAttribute('role')
            && self::CARRIER_STYLE === $mark->getAttribute('style');
    }

    public static function apply(string $content): string
    {
        if ( ! str_contains($content, '<!--') ) {
            return $content;
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded   = $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $content . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $loaded ? $document->getElementsByTagName('body')->item(0) : null;
        if ( ! $body instanceof DOMElement ) {
            return preg_replace('/<!--.*?-->/s', '', $content) ?? $content;
        }

        self::bound($body);

        $html = '';
        foreach ( $body->childNodes as $child ) {
            $html .= $document->saveHTML($child);
        }

        return $html;
    }

    private static function bound(DOMNode $parent): void
    {
        $run = array();
        foreach ( iterator_to_array($parent->childNodes) as $child ) {
            if ( $child instanceof DOMText || $child instanceof DOMComment ) {
                $run[] = $child;
                continue;
            }
            self::boundRun($run);
            $run = array();
            if ( $child instanceof DOMElement ) {
                self::bound($child);
            }
        }
        self::boundRun($run);
    }

    /**
     * @param list<DOMNode> $run Adjacent text and comment siblings.
     */
    private static function boundRun(array $run): void
    {
        $segments = array( array() );
        foreach ( $run as $node ) {
            if ( $node instanceof DOMComment ) {
                if ( '' !== $node->data ) {
                    $segments[] = array();
                }
                $node->parentNode?->removeChild($node);
                continue;
            }
            if ( '' !== ( $node->nodeValue ?? '' ) ) {
                $segments[ count($segments) - 1 ][] = $node;
            }
        }

        $segments = array_values(array_filter($segments, static fn (array $segment): bool => array() !== $segment));
        foreach ( $segments as $index => $segment ) {
            if ( 1 !== $index % 2 ) {
                continue;
            }
            $first = $segment[0];
            $carrier = $first->ownerDocument->createElement('mark');
            $carrier->setAttribute('style', self::CARRIER_STYLE);
            $carrier->setAttribute('role', 'none');
            $first->parentNode->insertBefore($carrier, $first);
            foreach ( $segment as $text ) {
                $carrier->appendChild($text);
            }
        }
    }
}

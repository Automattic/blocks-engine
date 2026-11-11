<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

use DOMComment;
use DOMDocument;
use DOMProcessingInstruction;

/**
 * Owns the UTF-8 HTML round trip through DOMDocument.
 *
 * DOMDocument::loadHTML() reads UTF-8 only behind an `<?xml encoding="UTF-8">`
 * hint. The hint is parser input, never page content: libxml2 2.9 keeps it as
 * an `xml` processing instruction and libxml2 2.14+ as a bogus comment
 * (`<!--?xml encoding="UTF-8"-->`). Parsing removes those nodes at once, so
 * every caller gets a clean tree and saveHTML() never writes the hint back.
 */
final class HtmlSerialization
{
    /** A UTF-8 document parsed from HTML, with the implied html, body and doctype libxml adds. */
    public static function parseDocument(string $html): DOMDocument
    {
        return self::parse($html, 0);
    }

    /** A UTF-8 document whose top-level nodes are the markup's own: no implied html, body or doctype. */
    public static function parseFragment(string $html): DOMDocument
    {
        return self::parse($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    }

    /**
     * loadHTML() never fails behind the hint, so the document is always
     * returned; parse warnings are discarded and the caller's libxml error
     * mode is left as it was.
     */
    private static function parse(string $html, int $options): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, $options | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        self::removeEncodingHints($document);

        return $document;
    }

    /**
     * Attributes as escaped ` name="value"` pairs. Empty values are omitted
     * unless their name is in `$includeEmpty`.
     *
     * @param array<string, string> $attributes
     * @param array<int, string> $includeEmpty
     */
    public static function attributes(array $attributes, array $includeEmpty = array()): string
    {
        $html = '';
        foreach ( $attributes as $name => $value ) {
            if ( '' === $value && ! in_array($name, $includeEmpty, true) ) {
                continue;
            }
            $html .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        return $html;
    }

    /**
     * One attribute's entity-decoded value read from a raw start tag or its
     * attribute text, quoted or not; '' when the tag does not carry it.
     */
    public static function tagAttribute(string $tag, string $name): string
    {
        if ( preg_match('/(?:^|\s)' . preg_quote($name, '/') . '\s*=\s*(["\'])(.*?)\1/is', $tag, $match) ) {
            return html_entity_decode((string) $match[2], ENT_QUOTES | ENT_HTML5);
        }

        if ( preg_match('/(?:^|\s)' . preg_quote($name, '/') . '\s*=\s*([^\s"\'>]+)/is', $tag, $match) ) {
            return html_entity_decode((string) $match[1], ENT_QUOTES | ENT_HTML5);
        }

        return '';
    }

    public static function documentHtml(DOMDocument $document): ?string
    {
        self::removeEncodingHints($document);
        $html = $document->saveHTML();

        return is_string($html) ? $html : null;
    }

    private static function removeEncodingHints(DOMDocument $document): void
    {
        foreach ( iterator_to_array($document->childNodes) as $node ) {
            if ( self::isEncodingHint($node) ) {
                $document->removeChild($node);
            }
        }
    }

    private static function isEncodingHint(\DOMNode $node): bool
    {
        if ( $node instanceof DOMProcessingInstruction ) {
            return 'xml' === strtolower($node->target);
        }

        return $node instanceof DOMComment && 1 === preg_match('/^[?\/]xml\s+encoding=/i', $node->data);
    }
}

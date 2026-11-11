<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

use DOMComment;
use DOMDocument;
use DOMProcessingInstruction;

/**
 * Serializes a whole document that was parsed with the `<?xml encoding="UTF-8">`
 * hint DOMDocument::loadHTML() needs to read UTF-8.
 *
 * The hint is parser input, never page content. libxml2 2.9 keeps it as an
 * `xml` processing instruction and libxml2 2.14+ keeps it as a bogus comment
 * (`<!--?xml encoding="UTF-8"-->`); either way saveHTML() would write it into
 * the page, and a page projected more than once would accumulate one per pass.
 * Removing the parsed nodes keeps every libxml version's output free of it.
 */
final class HtmlSerialization
{
    /**
     * DOMDocument::loadHTML() with libxml's parse warnings collected and
     * discarded, leaving the caller's libxml error mode as it was.
     */
    public static function loadHtml(DOMDocument $document, string $html, int $options = 0): bool
    {
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML($html, $options);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded;
    }

    /** A UTF-8 DOMDocument parsed by {@see self::loadHtml()}, or null when it fails. */
    public static function utf8Document(string $html, int $options = 0): ?DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');

        return self::loadHtml($document, '<?xml encoding="UTF-8">' . $html, $options) ? $document : null;
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

    public static function removeEncodingHints(DOMDocument $document): void
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

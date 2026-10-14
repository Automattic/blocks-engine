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

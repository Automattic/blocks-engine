<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use DOMElement;
use SplObjectStorage;

/**
 * Rendered glyph colour recorded by capture as `data-dla-glyph-color`.
 *
 * The attribute is capture evidence, not content: it is read once and removed
 * from the source DOM before conversion, so it never reaches block markup,
 * fallback HTML or materialized assets. A valid value is preferred over
 * decoding the glyph image bytes with {@see MonochromeGlyphColor}.
 */
final class CapturedGlyphColor
{
    public const ATTRIBUTE = 'data-dla-glyph-color';

    /** Lowercase `#rrggbb`, or empty when the value is not a six-digit hex colour. */
    public static function normalize(string $value): string
    {
        return 1 === preg_match('/^#[0-9a-f]{6}$/iD', $value) ? strtolower($value) : '';
    }

    /**
     * Remove every `data-dla-glyph-color` under (and on) the root and return the
     * valid colours keyed by the element that carried them. Invalid values are
     * removed too and count as absent.
     *
     * @return SplObjectStorage<DOMElement, string>
     */
    public static function detach(DOMElement $root): SplObjectStorage
    {
        /** @var SplObjectStorage<DOMElement, string> $colors */
        $colors = new SplObjectStorage();
        $elements = $root->hasAttribute(self::ATTRIBUTE) ? array( $root ) : array();
        foreach ( $root->getElementsByTagName('*') as $element ) {
            if ( $element->hasAttribute(self::ATTRIBUTE) ) {
                $elements[] = $element;
            }
        }
        foreach ( $elements as $element ) {
            $color = self::normalize($element->getAttribute(self::ATTRIBUTE));
            $element->removeAttribute(self::ATTRIBUTE);
            if ( '' !== $color ) {
                $colors[$element] = $color;
            }
        }

        return $colors;
    }
}

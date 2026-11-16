<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use DOMElement;

final class QuotePatternContext
{
    public function __construct(
        private readonly StyleResolver $styleResolver
    ) {
    }

    /** Preserve the typography of the caption consumed by a native quote. */
    public function figureCaptionContent(DOMElement $caption): string
    {
        $content = $this->styleResolver->innerHtml($caption);
        $declarations = array();
        foreach (array('font-family', 'font-size', 'font-weight', 'font-style', 'line-height', 'letter-spacing', 'text-transform', 'color') as $property) {
            $value = $this->styleResolver->authoredInheritedPropertyWinner($caption, $property);
            if ('' !== $value) {
                $declarations[$property] = $value;
            }
        }
        $style = $this->styleResolver->cssDeclarationString($declarations);
        return '' === $style ? $content : '<span style="' . htmlspecialchars($style, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . $content . '</span>';
    }

    public function citationFromElement(DOMElement $element): string
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && in_array(strtolower($child->tagName), array( 'cite', 'footer', 'figcaption' ), true) ) {
                $content = \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom::innerHtmlWithProjectedMarkers(
                    $child,
                    $this->styleResolver->richTextSourceMarkers(...),
                    $this->styleResolver->serializedInlineStyle(...)
                );
                // The native quote already saves a cite carrier. Wrapping its
                // contents in source-container classes changes selector topology.
                return $content;
            }
        }

        return '';
    }
}

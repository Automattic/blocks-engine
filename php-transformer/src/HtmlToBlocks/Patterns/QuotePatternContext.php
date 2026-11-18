<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\CommentTextRunBoundaries;
use DOMElement;

final class QuotePatternContext
{
    public function __construct(
        private readonly StyleResolver $styleResolver
    ) {
    }

    /**
     * Resolve only text styles for the paragraph replacing a consumed blockquote.
     * Do not resolve box presentation: that can register generated geometry even
     * when the caller subsequently discards the box attributes.
     *
     * @return array<string, mixed>
     */
    public function figureQuotationTypography(DOMElement $blockquote): array
    {
        return $this->mappedQuotationStyle($blockquote, array(
            'font-family', 'font-size', 'font-weight', 'font-style', 'line-height', 'letter-spacing', 'text-transform',
        ))['typography'] ?? array();
    }

    /**
     * The authored margins of the consumed blockquote. Its paragraph keeps the
     * blockquote's block box, so an authored `margin-bottom` still separates
     * the quotation from its caption. Browser-default blockquote margins are
     * not authored and stay dropped; so does the rest of the box (width,
     * padding), which would register generated geometry.
     *
     * @return array<string, mixed>
     */
    public function figureQuotationMargin(DOMElement $blockquote): array
    {
        return $this->mappedQuotationStyle($blockquote, array(
            'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        ))['spacing']['margin'] ?? array();
    }

    /**
     * @param list<string> $properties
     * @return array<string, mixed>
     */
    private function mappedQuotationStyle(DOMElement $blockquote, array $properties): array
    {
        $declarations = array_intersect_key(
            $this->styleResolver->structuralPresentationDeclarations($blockquote),
            array_flip($properties)
        );
        if (array() === $declarations) {
            return array();
        }
        $mapped = $this->styleResolver->styleAttributeMapper()->map(
            $declarations,
            fn (string $value): string => $this->styleResolver->customProperties->resolve($value, $blockquote)
        );
        return $mapped['style'] ?? array();
    }

    /**
     * The quotation's RichText. Under a clip that author motion carries it
     * across, the text runs the source split with empty comments
     * (`“<!---->{quote}<!---->”`) are painted one by one, so they keep
     * their boundaries ({@see StyleResolver::movesTextAcrossClip()}).
     */
    public function quotationContent(DOMElement $blockquote, string $content): string
    {
        return str_contains($content, '<!---->') && $this->styleResolver->movesTextAcrossClip($blockquote)
            ? CommentTextRunBoundaries::keepEmptySeams($content)
            : $content;
    }

    /** Preserve the typography of the caption consumed by a native quote. */
    public function figureCaptionContent(DOMElement $caption): string
    {
        $content = \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom::innerHtml($caption);
        $declarations = array();
        $authored = $this->styleResolver->structuralPresentationDeclarations($caption);
        foreach (array('font-family', 'font-size', 'font-weight', 'font-style', 'line-height', 'letter-spacing', 'text-transform', 'color') as $property) {
            $value = isset($authored[$property]) ? $this->styleResolver->authoredInheritedPropertyWinner($caption, $property) : '';
            if ('' !== $value) {
                $declarations[$property] = $value;
            }
        }
        if (array() !== $declarations) {
            // The span stands for the caption's block box. Inline, it would sit
            // in a line box sized by the cite's own font, not the caption's.
            $declarations['display'] = 'block';
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

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
        $declarations = $this->styleResolver->structuralPresentationDeclarations($blockquote);
        $typography = array_intersect_key($declarations, array_flip(array(
            'font-family', 'font-size', 'font-weight', 'font-style', 'line-height', 'letter-spacing', 'text-transform',
        )));
        $mapped = $this->styleResolver->styleAttributeMapper()->map(
            $typography,
            fn (string $value): string => $this->styleResolver->customProperties->resolve($value, $blockquote)
        );
        return $mapped['style']['typography'] ?? array();
    }

    /**
     * The blockquote's authored non-zero margins. The paragraph that replaces
     * it in a collapsed figure quote is that block box, so the margins keep
     * spacing the quotation from its caption.
     *
     * @return array<string, string>
     */
    public function figureQuotationMargin(DOMElement $blockquote): array
    {
        $declarations = $this->styleResolver->structuralPresentationDeclarations($blockquote);
        $margin = array_intersect_key($declarations, array_flip(array(
            'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        )));
        $mapped = $this->styleResolver->styleAttributeMapper()->map(
            $margin,
            fn (string $value): string => $this->styleResolver->customProperties->resolve($value, $blockquote)
        );
        return array_filter(
            $mapped['style']['spacing']['margin'] ?? array(),
            static fn ($value): bool => is_string($value) && 1 !== preg_match('/^-?0(?:\.0+)?[a-z%]*$/i', trim($value))
        );
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
        if ( array() !== $declarations ) {
            // The caption was its own block box; the citation is a <cite>
            // inside the quote. Lay its typography out as a block so the line
            // box takes the caption's font, not the quote's strut, and cancel
            // the UA italic of <cite> the caption never had.
            $declarations['display'] = 'block';
            if ( ! isset($declarations['font-style']) ) {
                $inherited = $this->styleResolver->authoredInheritedPropertyWinner($caption, 'font-style');
                $declarations['font-style'] = '' === $inherited ? 'normal' : $inherited;
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

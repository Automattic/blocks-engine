<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\Css\CssLength;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
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

    /**
     * The block-end margin the author put between a consumed blockquote and
     * the caption below it. The synthetic paragraph carries no box, so the
     * restated caption box keeps that distance from the quotation.
     */
    public function figureQuotationCaptionGap(DOMElement $blockquote): string
    {
        $declarations = $this->styleResolver->structuralPresentationDeclarations($blockquote);
        $gap = '';
        foreach ($declarations as $property => $value) {
            if ('margin-bottom' === $property || 'margin-block-end' === $property) {
                $gap = (string) $value;
            } elseif ('margin' === $property && CssValueInspector::hasPositionalSides((string) $value)) {
                $gap = CssValueInspector::expandBoxShorthand((string) $value)[2];
            } elseif ('margin-block' === $property) {
                $sides = CssValueSplitter::splitTopLevelWhitespace(trim((string) $value));
                $gap = (string) end($sides);
            }
        }
        $gap = trim((string) preg_replace('/\s*!\s*important\s*$/i', '', $this->styleResolver->customProperties->resolve($gap, $blockquote)));
        if ('' === $gap) {
            return '';
        }
        // The caption box has its own font size: an em gap is the quotation's.
        $relative = 1 === preg_match('/(?<!r)em\b/i', $gap);
        $emPx = $relative ? CssLength::toPx($this->styleResolver->customProperties->resolve((string) ($declarations['font-size'] ?? ''), $blockquote)) : null;
        if ($relative && null === $emPx) {
            return '';
        }
        $px = CssLength::toPx($gap, 16.0, $emPx);
        if (null === $px || $px <= 0.0) {
            return '';
        }
        return $relative ? rtrim(rtrim(number_format($px, 3, '.', ''), '0'), '.') . 'px' : $gap;
    }

    /** Preserve the typography of the caption consumed by a native quote. */
    public function figureCaptionContent(DOMElement $caption, string $quotationGap = ''): string
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
        if ('' !== $quotationGap) {
            $declarations['margin-top'] = $quotationGap;
        }
        if (array() !== $declarations) {
            // The figcaption is a block box with its own line box; the native
            // `<cite>` sets the restated typography on the quote's strut and
            // in its own UA italic. Restate the caption as that block box.
            $declarations['display'] = 'block';
            if (!isset($declarations['font-style'])) {
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

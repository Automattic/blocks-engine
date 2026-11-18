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
     * Resolve the presentation of the paragraph replacing a consumed
     * blockquote: its text styles and the margins of the block box it stands
     * in for. Read the authored declarations only: resolving full box
     * presentation can register generated geometry even when the caller
     * discards the box attributes, and the blockquote's size stays with the
     * quote that replaces the figure.
     *
     * @return array<string, mixed>
     */
    public function figureQuotationStyle(DOMElement $blockquote): array
    {
        $declarations = $this->styleResolver->structuralPresentationDeclarations($blockquote);
        $kept = array_filter(
            $declarations,
            static fn (string $property): bool => in_array($property, array(
                'font-family', 'font-size', 'font-weight', 'font-style', 'line-height', 'letter-spacing', 'text-transform',
            ), true) || 1 === preg_match('/^margin(?:-(?:top|right|bottom|left))?$/', $property),
            ARRAY_FILTER_USE_KEY
        );
        $mapped = $this->styleResolver->styleAttributeMapper()->map(
            $kept,
            fn (string $value): string => $this->styleResolver->customProperties->resolve($value, $blockquote)
        );
        return array_filter(array(
            'typography' => $mapped['style']['typography'] ?? array(),
            'spacing' => isset($mapped['style']['spacing']['margin']) ? array('margin' => $mapped['style']['spacing']['margin']) : array(),
        ), static fn (array $value): bool => array() !== $value);
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
        // The caption is a block box. As an inline run inside the citation,
        // its smaller line would sit on the citation's inherited strut and
        // grow the line box past the caption's own line height.
        if (array() !== $declarations) {
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

<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use DOMElement;

final class QuotePatternContext
{
    public function __construct(
        private readonly StyleResolver $styleResolver,
        private readonly ?HtmlTransformerSession $session = null
    ) {
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

    /**
     * A figure collapsed into core/quote saves no box for its source
     * blockquote: the quote's paragraph holds that blockquote's text instead.
     * Author rules that address the blockquote (`.card blockquote`) follow it
     * onto that paragraph through a source-addressed carrier class. Empty when
     * no author rule styles the blockquote.
     */
    public function figureBlockquoteCarrier(DOMElement $blockquote): string
    {
        return $this->innerSubjectCarrier($blockquote, static fn (string $marker): string => ':where(.' . $marker . ')');
    }

    /**
     * The figure's caption becomes the quote's native `<cite>`. Author rules
     * for the caption (`.card figcaption`) follow it onto that cite. Returns
     * the carrier class for the quote block, or '' when no rule styles it.
     */
    public function figureCaptionCarrier(DOMElement $caption): string
    {
        return $this->innerSubjectCarrier($caption, static fn (string $marker): string => ':where(.' . $marker . '>cite)');
    }

    /** @param callable(string): string $subject */
    private function innerSubjectCarrier(DOMElement $element, callable $subject): string
    {
        $path = $element->getNodePath() ?? '';
        if ( null === $this->session || '' === $path || '' === $this->session->authorStyleAnalysis()->combinedCss()
            || array() === $this->styleResolver->authoredPresentationDeclarations($element) ) {
            return '';
        }
        $projections = $this->session->authorSelectorProjectionState();
        $marker = $projections->ensureSemanticMarker($path);
        $projections->installInnerSubject($path, $subject($marker), '', false, true);

        return $marker;
    }
}

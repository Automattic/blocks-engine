<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\VisualIframeBlockGenerator;
use DOMElement;

/**
 * Lowers a text-less element whose only content is one iframe, image,
 * picture or image-only link into the standalone media block it visually is.
 */
final class TextlessMediaLowering
{
    public function __construct(
        private readonly VisualIframeBlockGenerator $visualIframeGenerator,
        private readonly ParagraphImageUnwrapping $paragraphImages
    ) {
    }

    /**
     * A heading or paragraph with no text carries no text semantics, so its
     * one iframe (or custom iframe element) lowers as the standalone embed it
     * visually is (#4303, #4666). A heading's image, picture or image-only
     * link takes the paragraph image route, keeping the heading's selector
     * identity the way a text-less paragraph keeps its own. `<br>` line
     * breaks and whitespace are not text; any other text keeps the heading's
     * RichText image behavior (#3496).
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    public function mediaBlockFromTextlessElement(DOMElement $element, array &$fallbacks): ?array
    {
        $media = $this->soleElementBesideLineBreaks($element);
        if ( null === $media || '' !== trim($this->textBesideLineBreaks($element)) ) {
            return null;
        }

        $tagName = strtolower($media->tagName);
        if ( 'iframe' === $tagName || ( str_contains($tagName, '-') && 1 === preg_match('/(?:^|-)iframe(?:-|$)/', $tagName) ) ) {
            return $this->visualIframeGenerator->convert($media, $fallbacks);
        }
        // Paragraph images keep their own stricter route, which leaves an
        // image beside a `<br>` in RichText.
        if ( 'p' === strtolower($element->tagName) ) {
            return null;
        }

        return $this->paragraphImages->unwrappedParagraphImageBlock($element, true);
    }

    /** The element's text content, ignoring `<br>` line breaks and whitespace. */
    private function textBesideLineBreaks(DOMElement $element): string
    {
        $text = '';
        foreach ( $element->childNodes as $node ) {
            if ( $node instanceof DOMElement && 'br' === strtolower($node->tagName) ) {
                continue;
            }
            $text .= $node->textContent ?? '';
        }

        return trim($text);
    }

    /** The one non-`<br>` element child, or null when there is none or several. */
    private function soleElementBesideLineBreaks(DOMElement $element): ?DOMElement
    {
        $sole = null;
        foreach ( $element->childNodes as $node ) {
            if ( ! $node instanceof DOMElement || 'br' === strtolower($node->tagName) ) {
                continue;
            }
            if ( null !== $sole ) {
                return null;
            }
            $sole = $node;
        }

        return $sole;
    }
}

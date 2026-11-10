<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\BlockFactory;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\LinkUrlSanitizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMElement;

/**
 * Unwraps a paragraph that holds only an image, picture or image-only
 * anchor into the image block, keeping the paragraph's selector identity.
 */
final class ParagraphImageUnwrapping
{
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly StyleResolver $styleResolver,
        private readonly BlockFactory $blockFactory,
        private readonly ImageLowering $imageLowering,
        private readonly AnchorLowering $anchors,
        private readonly Closure $convertPictureElement
    ) {
    }

    /**
     * The image block a paragraph unwraps into keeps the paragraph's selector
     * identity, so rules that matched the paragraph in the source (type,
     * structural position, its box) still reach the block that replaced it.
     *
     * @return array<string, mixed>|null
     */
    public function unwrappedParagraphImageBlock(DOMElement $paragraph, bool $ignoreLineBreaks = false): ?array
    {
        $block = $this->imageBlockFromParagraph($paragraph, $ignoreLineBreaks);
        if ( null === $block ) {
            return null;
        }
        $path = $paragraph->getNodePath() ?? '';
        $projections = $this->session->authorSelectorProjectionState();
        $attrs = $block['attrs'];
        $attrs['className'] = SourceDom::mergeClassNames(
            (string) ( $attrs['className'] ?? '' ),
            ...$projections->semanticCarrierClasses($projections->ensureSemanticMarker($path), $path)
        );
        if ( $this->paragraphImageSetsLineBox($paragraph, $ignoreLineBreaks) ) {
            $attrs['className'] = SourceDom::mergeClassNames((string) $attrs['className'], SourceBlockAttributeProjector::PARAGRAPH_LINE_BOX_IMAGE_FIGURE_CLASS);
        }

        return $this->blockFactory->rebuild($block, $attrs);
    }

    /**
     * An inline image with an authored vertical margin sizes its paragraph's
     * line box: the margin sits inside the paragraph and never collapses with
     * its siblings. A blockified image would let that margin collapse through
     * the figure, so the figure keeps the image on a line instead.
     */
    private function paragraphImageSetsLineBox(DOMElement $paragraph, bool $ignoreLineBreaks = false): bool
    {
        $child = $this->paragraphImageChild($paragraph, $ignoreLineBreaks);
        $image = $child instanceof DOMElement && 'img' === strtolower($child->tagName) ? $child : $child?->getElementsByTagName('img')->item(0);
        if ( ! $image instanceof DOMElement
            || ! in_array($this->styleResolver->resolvedDeclaration($image, 'display'), array( '', 'inline', 'inline-block' ), true)
            || ! in_array($this->styleResolver->resolvedDeclaration($image, 'float'), array( '', 'none' ), true)
            || ! in_array($this->styleResolver->resolvedDeclaration($image, 'position'), array( '', 'static', 'relative' ), true) ) {
            return false;
        }
        foreach ( $this->resolvedVerticalMargins($image) as $value ) {
            if ( '' !== $value && ! in_array($value, array( '0', '0px', 'auto' ), true) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The element's resolved top and bottom margins, from the `margin`
     * shorthand or its longhands, whichever the cascade declared last.
     *
     * @return array{top: string, bottom: string}
     */
    private function resolvedVerticalMargins(DOMElement $element): array
    {
        $margins = array( 'top' => '', 'bottom' => '' );
        foreach ( $this->styleResolver->resolvedPresentationDeclarations($element) as $property => $value ) {
            $value = strtolower(trim(CssValueInspector::withoutImportant((string) $value)));
            if ( 'margin' === $property ) {
                [ $top, , $bottom ] = CssValueInspector::expandBoxShorthand($value);
                $margins = array( 'top' => $top, 'bottom' => $bottom );
            } elseif ( 'margin-top' === $property || 'margin-bottom' === $property ) {
                $margins[ substr($property, 7) ] = $value;
            }
        }

        return $margins;
    }

    /**
     * Route a paragraph containing a single image (or image-only link) through
     * the existing core/image primitive. Passive inline marks around that image
     * add no text formatting to preserve. Mixed content and multiple images
     * remain on the paragraph RichText route.
     *
     * @return array<string, mixed>|null
     */
    private function imageBlockFromParagraph(DOMElement $paragraph, bool $ignoreLineBreaks = false): ?array
    {
        $child = $this->paragraphImageChild($paragraph, $ignoreLineBreaks);
        if ( ! $child instanceof DOMElement ) {
            return null;
        }

        $tagName = strtolower($child->tagName);
        if ( 'img' === $tagName ) {
            return $this->imageLowering->convertImage($child);
        }
        if ( 'picture' === $tagName ) {
            return ($this->convertPictureElement)($child);
        }

        if ( '' !== LinkUrlSanitizer::sanitize(SourceDom::attr($child, 'href')) ) {
            return $this->anchors->imageBlockFromAnchor($child);
        }

        $image = SourceDom::firstChildElement($child, 'img');
        return $image instanceof DOMElement ? $this->imageLowering->convertImage($image) : null;
    }

    /**
     * The image, picture or image-only anchor a paragraph wraps on its own
     * (through passive inline marks), which unwraps the paragraph into a
     * core/image block instead of a paragraph.
     */
    public function paragraphImageChild(DOMElement $paragraph, bool $ignoreLineBreaks = false): ?DOMElement
    {
        $child = null;
        foreach ( $paragraph->childNodes as $node ) {
            if ( $node instanceof DOMElement ) {
                if ( $ignoreLineBreaks && 'br' === strtolower($node->tagName) ) {
                    continue;
                }
                if ( $child instanceof DOMElement ) {
                    return null;
                }
                $child = $node;
                continue;
            }
            if ( '' !== trim($node->textContent ?? '') ) {
                return null;
            }
        }

        if ( ! $child instanceof DOMElement ) {
            return null;
        }
        if ( SourceDom::isInlineImageMark($child) ) {
            return $this->paragraphImageChild($child, $ignoreLineBreaks);
        }
        $tagName = strtolower($child->tagName);
        if ( in_array($tagName, array( 'img', 'picture' ), true) || ( 'a' === $tagName && $this->imageLowering->isImageOnlyAnchor($child) ) ) {
            return $child;
        }

        return null;
    }
}

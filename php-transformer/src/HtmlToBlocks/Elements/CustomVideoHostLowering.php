<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\ImageUrlResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/**
 * Lowers a custom element that hosts a native video, or a poster image
 * layered over one, without losing the host's presentation box.
 */
final class CustomVideoHostLowering implements ElementConverter
{
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly StyleResolver $styleResolver,
        private readonly ImageUrlResolver $imageUrls,
        private readonly RuntimeIslandAnalyzer $runtimeIslands,
        private readonly MediaLowering $mediaLowering,
        private readonly ImageLowering $imageLowering,
        private readonly LayoutShellLowering $layoutShells,
        private readonly SourceBlockCreator $createBlock
    ) {
    }

    /**
     * Lowers a custom video host to its video, keeping a presentation box as a
     * core wrapper rather than moving its geometry onto the video, or to its
     * poster image.
     */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        $customVideo = $this->customVideoElement($element);
        if ( $customVideo instanceof DOMElement ) {
            $video = $this->mediaLowering->fromElement($customVideo);
            if ( $this->hasTransparentCustomVideoHostPresentation($element) ) {
                return ConversionOutcome::handled($video);
            }

            return ConversionOutcome::handled($this->createBlock->createBlock('core/group', $this->styleResolver->presentationAttributes($element), array($video), $element));
        }

        $poster = $this->customVideoPosterBlock($element);

        return null === $poster ? ConversionOutcome::unhandled() : ConversionOutcome::handled($poster);
    }

    public function customVideoElement(DOMElement $element): ?DOMElement
    {
        // Capture-snapshot `data-*` on a video host parameterizes media the
        // capture already renders, so they no more prove a missing runtime
        // than they do for the poster path below (#2524).
        if ( ! str_contains($element->tagName, '-') || '' !== trim($element->textContent ?? '') || ! $this->runtimeIslands->isSafeTransparentCustomElement($element) || ! $this->hasOnlyStructuralCustomVideoHostAttributes($element, true) ) {
            return null;
        }

        $videos = $element->getElementsByTagName('video');
        if ( 1 !== $videos->length || ! $videos->item(0) instanceof DOMElement ) {
            return null;
        }

        $video = $videos->item(0);
        if ( ! $video instanceof DOMElement || ! $this->hasRedundantCustomVideoPosterMarkup($element, $video) ) {
            return null;
        }

        return $video;
    }

    private function hasRedundantCustomVideoPosterMarkup(DOMElement $host, DOMElement $video): bool
    {
        $poster = $this->imageUrls->safeUrl(SourceDom::attr($video, 'poster'));
        foreach ( $host->getElementsByTagName('*') as $descendant ) {
            if ( ! $descendant instanceof DOMElement || $descendant === $video ) {
                continue;
            }
            $tagName = strtolower($descendant->tagName);
            if ( in_array($tagName, array( 'source', 'track' ), true) && $descendant->parentNode === $video ) {
                continue;
            }
            if ( 'img' === $tagName ) {
                if ( '' === $poster || ! $this->isRedundantCustomVideoPosterImage($descendant, $poster) ) {
                    return false;
                }
                continue;
            }
            if ( '' !== trim($descendant->textContent ?? '') || ! (str_contains($tagName, '-') || in_array($tagName, array( 'div', 'span' ), true)) ) {
                return false;
            }
            if ( $descendant->hasAttributes() ) {
                return false;
            }
        }

        return true;
    }

    /** Explicit native media plus a separate decorative poster is already a
     * browser-owned presentation tree. Keep both source boxes around editable
     * native media instead of requiring a script to upgrade the custom tags,
     * whether the poster layer is a bare decorative `img` or an image-only
     * custom element wrapping one.
     */
    public function customVideoPosterBlock(DOMElement $element): ?array
    {
        if (!str_contains($element->tagName, '-') || '' !== trim($element->textContent ?? '') || !$this->runtimeIslands->isSafeTransparentCustomElement($element) || !$this->hasOnlyStructuralCustomVideoHostAttributes($element, true)) return null;
        $children = SourceDom::elementElementChildren($element);
        if (2 !== count($children)) return null;
        $video = null; $poster = null;
        foreach ($children as $child) {
            if (null === $video && 'video' === strtolower($child->tagName)) { $video = $child; continue; }
            if (null === $poster && null !== $this->posterLayerImage($child)) { $poster = $child; continue; }
            return null;
        }
        if (!$video instanceof DOMElement || !$poster instanceof DOMElement) return null;
        $image = $this->posterLayerImage($poster);
        if (!$image instanceof DOMElement || !$image->hasAttribute('alt') || '' !== SourceDom::attr($image, 'alt')) return null;
        $videoBlock = $this->mediaLowering->fromElement($video);
        $imageBlock = $this->imageLowering->convertImage($image);
        if (null === $videoBlock || null === $imageBlock) return null;
        $this->session->authorSelectorProjectionState()->ensureTagMarker(strtolower($element->tagName));
        if ($poster !== $image && $image->parentNode === $poster) {
            // The poster layer is wrapped: keep the wrapper's identity.
            if (!$this->hasOnlyStructuralCustomVideoHostAttributes($poster, true) || 1 !== count(SourceDom::elementElementChildren($poster))) return null;
            $this->session->authorSelectorProjectionState()->ensureTagMarker(strtolower($poster->tagName));
            $posterBlock = $this->layoutShells->blockForElements(array($poster), array($imageBlock), $poster);
        } else {
            $posterBlock = $imageBlock;
        }
        $blocks = $children[0] === $video ? array($videoBlock, $posterBlock) : array($posterBlock, $videoBlock);
        return $this->layoutShells->blockForElements(array($element), $blocks, $element);
    }

    /** The poster image an element owns: a bare decorative `img` itself, or
     * the single image of an image-only custom element. A bare img qualifies
     * only with media and capture-snapshot attributes, like the wrapped
     * poster element below: an authoring signal (`aria-hidden`, a role) keeps
     * the poster on the preserved-content path instead.
     */
    private function posterLayerImage(DOMElement $element): ?DOMElement
    {
        if ( 'img' === strtolower($element->tagName) ) {
            return $this->isBareDecorativePosterImage($element) ? $element : null;
        }

        return SourceDom::imageOnlyCustomElement($element);
    }

    private function isBareDecorativePosterImage(DOMElement $image): bool
    {
        if ( !$image->hasAttribute('alt') || '' !== trim(SourceDom::attr($image, 'alt')) ) {
            return false;
        }
        foreach ( $image->attributes as $attribute ) {
            $name = strtolower($attribute->name);
            if ( in_array($name, array( 'src', 'srcset', 'sizes', 'alt', 'width', 'height', 'loading', 'decoding', 'fetchpriority', 'draggable', 'class', 'style', 'id' ), true) ) {
                continue;
            }
            if ( str_starts_with($name, 'data-') && ! str_starts_with($name, 'data-wp-') && 'data-action' !== $name ) {
                continue;
            }
            return false;
        }

        return true;
    }

    private function hasOnlyStructuralCustomVideoHostAttributes(DOMElement $element, bool $allowSnapshotMetadata = false): bool
    {
        foreach ( $element->attributes as $attribute ) {
            $name = strtolower($attribute->name);
            if (in_array($name, array('class', 'style'), true)) continue;
            if ($allowSnapshotMetadata && ('id' === $name || (str_starts_with($name, 'data-') && !str_starts_with($name, 'data-wp-') && 'data-action' !== $name))) continue;
            return false;
        }

        return true;
    }

    private function isRedundantCustomVideoPosterImage(DOMElement $image, string $poster): bool
    {
        if ( ! $image->hasAttribute('alt') || '' !== SourceDom::attr($image, 'alt') || $poster !== $this->imageUrls->sourceUrl($image) ) {
            return false;
        }

        foreach ( $image->attributes as $attribute ) {
            if ( ! in_array(strtolower($attribute->name), array( 'src', 'alt' ), true) ) {
                return false;
            }
        }

        return true;
    }

    public function hasTransparentCustomVideoHostPresentation(DOMElement $element): bool
    {
        // Flattening the host drops its box. A class, id, inline declaration, or
        // matched author rule means that box may carry presentation we cannot
        // faithfully move onto core/video.
        if ( '' !== SourceDom::attr($element, 'class') || '' !== SourceDom::attr($element, 'id') || '' !== SourceDom::attr($element, 'style') || array() !== $this->styleResolver->structuralPresentationDeclarations($element) ) {
            return false;
        }

        foreach ( $this->styleResolver->matchingStyleRules($element, 'static-conditional') as $rule ) {
            if ( array() !== ($rule['declarations'] ?? array()) ) {
                return false;
            }
        }

        return true;
    }
}

<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\GalleryPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use DOMElement;

/**
 * Lowers a media collection to core/gallery through GalleryPattern when its
 * layout is gallery-compatible, or to native responsive media groups.
 */
final class MediaGalleryLowering
{
    public function __construct(
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly StyleResolver $styleResolver,
        private readonly ImageLowering $imageLowering,
        private readonly PatternTreeConverter $tree
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function mediaGalleryBlockFromElement(DOMElement $element, array &$fallbacks): ?array
    {
        if ( $this->sourceElementClassifier->isImageOnlySlideshowCollection($element) ) {
            return $this->tree->patterns($element, $fallbacks, array(GalleryPattern::class));
        }

        if ( ! $this->isGalleryCompatibleMediaLayout($element) ) {
            return null;
        }

        if ( $this->sourceElementClassifier->hasResponsiveImageSources($element) ) {
            // GalleryPattern probes child conversions before it knows whether it
            // has enough images. Keep independent img srcsets out of the gallery
            // recognizer, but let each image lower natively in its own group.
            return $this->sourceElementClassifier->hasResponsiveMediaAlternatives($element)
                && $this->sourceElementClassifier->hasGalleryMediaItems($element)
                ? $this->imageLowering->responsiveMediaBlock($element) : null;
        }

        return $this->tree->patterns($element, $fallbacks, array(GalleryPattern::class));
    }

    private function isGalleryCompatibleMediaLayout(DOMElement $element): bool
    {
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement || 'figcaption' === strtolower($child->tagName) ) {
                continue;
            }

            $layoutElements = array( $child );
            foreach ( $child->getElementsByTagName('*') as $descendant ) {
                if ( $descendant instanceof DOMElement ) {
                    $layoutElements[] = $descendant;
                }
            }

            foreach ( $layoutElements as $layoutElement ) {
                $declarations = $this->styleResolver->structuralPresentationDeclarations($layoutElement);
                $position = strtolower(trim((string) ($declarations['position'] ?? '')));
                if ( in_array($position, array( 'absolute', 'fixed', 'sticky' ), true) ) {
                    return false;
                }

                $zIndex = strtolower(trim((string) ($declarations['z-index'] ?? '')));
                if ( '' !== $zIndex && 'auto' !== $zIndex ) {
                    return false;
                }
            }
        }

        return true;
    }
}

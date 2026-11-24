<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\ImageUrlResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\ImageDimensionResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\BlockFactory;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\SourceElementDescription;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorLayoutOwnership;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\HtmlDimensionAttribute;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/**
 * Lowers an image hosted by a transparent custom element into a native image
 * block, keeping the host's box as a layout shell when its presentation needs
 * one, and decides when an image-only custom element can be promoted.
 */
final class CustomImageHostLowering implements ElementConverter
{
    public function __construct(
        private readonly SourceBlockCreator $blocks,
        private readonly BlockFactory $blockFactory,
        private readonly StyleResolver $styleResolver,
        private readonly HtmlTransformerSession $session,
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly SourceElementDescription $sourceElementDescription,
        private readonly ImageLowering $imageLowering,
        private readonly LayoutShellLowering $layoutShells,
        private readonly RuntimeIslandAnalyzer $runtimeIslands,
        private readonly AuthorLayoutOwnership $authorLayoutOwnership,
        private readonly ImageDimensionResolver $imageDimensions,
        private readonly ImageUrlResolver $imageUrls,
        private readonly RenderNeutralElements $renderNeutral
    ) {
    }

    /** Lowers an image-only custom element host (a lone image inside a custom tag). */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        $customImage = SourceDom::imageOnlyCustomElement($element);
        if ( ! $customImage instanceof DOMElement ) {
            return ConversionOutcome::unhandled();
        }
        $this->imageDimensions->fillParentImageViewportPair($customImage);
        if ('' === $this->imageUrls->sourceUrl($customImage) && !$customImage->hasAttribute('srcset')) {
            return ConversionOutcome::handled(null);
        }
        if ( ! $this->canPromoteImageOnly($element, $customImage) ) {
            return ConversionOutcome::handled($this->nativeImageIn($element, $customImage) ?? $this->imageLowering->responsiveMediaBlock($element));
        }
        $picture = $customImage->parentNode;
        return ConversionOutcome::handled($this->imageLowering->convertImage(
            $customImage,
            $element,
            $picture instanceof DOMElement && 'picture' === strtolower($picture->tagName) ? $picture : null
        ));
    }

    /** Retain a static host box around independently editable native media. */
    public function nativeImageIn(DOMElement $host, DOMElement $image, ?DOMElement $link = null): ?array
    {
        // Retain inline hosts and metadata-backed captured media boxes.
        // Ordinary block hosts keep the conservative clipping/crop policy;
        // interactive hosts must keep their original inline control topology.
        if (!$this->runtimeIslands->isSafeTransparentCustomElement($host)
            || ($this->hasBlockFigureDisplay($host) && !$host->hasAttribute('data-image-info'))
            || SourceDom::ancestorElement($host, 'button') instanceof DOMElement
            || $this->runtimeIslands->isRuntimeDomTarget($host)
            || array() !== $this->sourceElementDescription->interactiveAttributes($host)
            || array() !== $this->session->authorSelectorProjectionState()->semanticMarkersForElement($host)) {
            return null;
        }
        // Unknown host data may describe presentation that native media does
        // not model. Only the already-understood image metadata permits that
        // boundary to be split into a retained shell and editable image.
        if (!$host->hasAttribute('data-image-info')) {
            foreach ($host->attributes as $attribute) {
                if (str_starts_with(strtolower($attribute->name), 'data-')) {
                    return null;
                }
            }
        }
        $picture = $image->parentNode;
        if ($picture instanceof DOMElement && 'picture' === strtolower($picture->tagName)
            && $this->sourceElementClassifier->hasPictureSourceSelection($picture)) {
            return null;
        }
        $hasPicture = $picture instanceof DOMElement && $picture !== $host;
        // Captured geometry that proves the host's box is exactly the image's
        // box makes the host the image's own figure: the figure takes the
        // host's identity and observed box, the image keeps its own, and no
        // separate container adds a List View level around the media.
        if (null === $link
            && (! $hasPicture || $this->isNeutralPictureBox($host, $picture, $image))
            && ! $this->hasCropFocusThatCoreImageCannotCarry($image)
            && $this->imageFillsHost($host, $image)) {
            $this->session->authorSelectorProjectionState()->ensureTagMarker(strtolower($host->tagName));
            $figure = $this->imageLowering->convertImage($image, $host, $hasPicture ? $picture : null);
            if (null !== $figure && 'core/image' === $figure['blockName']) {
                return $figure;
            }
        }
        $block = $this->imageLowering->convertImage($image, null, null, $link);
        if (null === $block || 'core/image' !== $block['blockName']) {
            return null;
        }
        if ($this->hasCropFocusThatCoreImageCannotCarry($image)) {
            $shape = $this->styleResolver->imageShapeDeclarations($image);
            $position = CssValueInspector::carriableDeclarationValue((string) ($shape['object-position']['value'] ?? ''));
            if ('' !== $position) {
                $carrier = $this->session->layoutGeometryState()->allocateCarrier('custom-host-image-position' . "\n" . $position);
                $this->session->layoutGeometryState()->registerRule($carrier, '.' . $carrier . '{object-position:' . $position . '}.' . $carrier . ' img{object-position:inherit}');
                $block['attrs']['className'] = SourceDom::mergeClassNames((string) ($block['attrs']['className'] ?? ''), $carrier);
                $block = $this->blockFactory->rebuild($block, $block['attrs']);
            }
        }
        $block['attrs']['className'] = SourceDom::mergeClassNames((string) ($block['attrs']['className'] ?? ''), 'blocks-engine-custom-host-image');
        $block = $this->blockFactory->rebuild($block, $block['attrs']);
        // Preserve the host's box and identity separately from the editable
        // image. In particular an id and captured image metadata are not a
        // reason to make the image itself an opaque HTML editing surface.
        $this->session->authorSelectorProjectionState()->ensureTagMarker(strtolower($host->tagName));
        $wrappers = array($host);
        // A source-less picture only selects its lone image. Without identity,
        // presentation, or selectors that depend on it, its box is the image's
        // own and a native container for it is an extra List View level.
        if ($hasPicture && ! $this->isNeutralPictureBox($host, $picture, $image)) {
            $wrappers[] = $picture;
        }
        // Captured metadata describes a retained CSS-owned box. Uncaptured
        // geometry still needs the presentation carrier's intrinsic-height
        // folding when its visual layer is positioned out of flow.
        if (! $hasPicture && ! $host->hasAttribute('data-image-info')
            && ! $this->canGroupPicturelessImageHost($host, $image, $link)) {
            return $this->layoutShells->blockForElements($wrappers, array($block), $host);
        }
        // These boxes have no source-selection behavior. Expose each as a
        // native container rather than coalescing the chain into a companion.
        foreach (array_reverse($wrappers) as $wrapper) {
            $attrs = $this->authorLayoutOwnership->cssOwnedGroupAttributes($wrapper);
            $attrs['tagName'] = $wrapper === $host ? 'div' : 'picture';
            $block = $this->blocks->createBlock('core/group', $attrs, array($block), $wrapper);
            $block['_native_custom_wrapper_hook'] = true;
        }
        return $block;
    }

    /**
     * Whether a source-less picture contributes nothing beyond its image: no
     * identity, behavior or presentation, and no selector that reaches the
     * image through it.
     */
    private function isNeutralPictureBox(DOMElement $host, DOMElement $picture, DOMElement $image): bool
    {
        return WrapperDecision::pictureBox(
            $picture,
            fn (string $name): bool => DataAttributeReferences::isReferenced($this->session, $picture, $name),
            function () use ($host, $picture, $image): bool {
                // An offsetless relative picture is only a containing block and a
                // paint layer. Inside a positioned host, around an in-flow image,
                // neither changes what renders.
                $outOfFlow = array('absolute', 'fixed', 'sticky');
                $hostPosition = $this->positionOf($host);
                $inertPositioning = '' !== $hostPosition && 'static' !== $hostPosition
                    && ! in_array($this->positionOf($image), $outOfFlow, true);
                return $this->renderNeutral->isRenderNeutralWrapper($picture, true, true, $inertPositioning);
            },
            function () use ($image): bool {
                foreach ($this->session->authorStyleRuleCandidates($image) as $candidate) {
                    if (1 === preg_match('/(?<![\w-])picture(?![\w-])/i', (string) $candidate['selector'])) {
                        return true;
                    }
                }
                return false;
            }
        )->isCoalesce();
    }

    /**
     * Whether the authored cascade sizes the in-flow image to the full
     * content box of a block host that adds no box edges of its own.
     */
    private function imageFillsHost(DOMElement $host, DOMElement $image): bool
    {
        // An absolutely positioned host is blockified whatever its display.
        $blockified = in_array($this->positionOf($host), array('absolute', 'fixed'), true)
            && in_array($this->styleResolver->resolvedDeclaration($host, 'display'), array('', 'inline', 'block'), true);
        if (! ($blockified || $this->hasBlockFigureDisplay($host))
            || in_array($this->positionOf($image), array('absolute', 'fixed', 'sticky'), true)) {
            return false;
        }
        $shape = $this->styleResolver->imageShapeDeclarations($image);
        foreach (array('width', 'height') as $axis) {
            if ('100%' !== strtolower(trim(CssValueInspector::withoutImportant((string) ($shape[$axis]['value'] ?? ''))))) {
                return false;
            }
        }
        foreach (array('top', 'right', 'bottom', 'left') as $side) {
            foreach (array(array($host, 'padding-' . $side), array($host, 'border-' . $side . '-width'), array($image, 'margin-' . $side)) as [$element, $property]) {
                if (! in_array($this->styleResolver->resolvedDeclaration($element, $property), array('', '0', '0px'), true)) {
                    return false;
                }
            }
        }
        foreach (array(array($host, 'padding'), array($host, 'border'), array($host, 'border-width'), array($image, 'margin')) as [$element, $shorthand]) {
            if (! in_array($this->styleResolver->resolvedDeclaration($element, $shorthand), array('', '0', '0px', 'none'), true)) {
                return false;
            }
        }

        return true;
    }

    private function positionOf(DOMElement $element): string
    {
        return strtolower(trim(CssValueInspector::withoutImportant(
            (string) ($this->styleResolver->structuralPresentationDeclarations($element)['position'] ?? '')
        )));
    }

    /** Keep links, geometry and out-of-flow topology on the existing carrier. */
    private function canGroupPicturelessImageHost(DOMElement $host, DOMElement $image, ?DOMElement $link): bool
    {
        if ($link instanceof DOMElement
            || ! $this->hasOnlyInertImageHostAttributes($host)
            || array() !== $this->styleResolver->presentationDeclarations($host)
            || array() !== $this->styleResolver->presentationDeclarations($image)
            || $image->hasAttribute('width')
            || $image->hasAttribute('height')) {
            return false;
        }
        for ($ancestor = $host->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode) {
            if ('a' === strtolower($ancestor->tagName)) {
                return false;
            }
            $position = CssValueInspector::comparable((string) ($this->styleResolver->structuralPresentationDeclarations($ancestor)['position'] ?? ''));
            if (in_array($position, array('absolute', 'fixed', 'sticky'), true)) {
                return false;
            }
        }
        return true;
    }

    /** Only erase the host when its box and identity are inert. */
    public function canPromoteImageOnly(DOMElement $host, DOMElement $image): bool
    {
        if ( ! $this->hasOnlyInertImageHostAttributes($host)
            || ! $this->hasBlockFigureDisplay($host)
            || ! $this->hasBlockFigureCarrier($host)
            || ! $this->hasOnlyBlockDisplayPresentation($host, $image)
            || $this->hasCropFocusThatCoreImageCannotCarry($image) ) {
            return false;
        }

        return true;
    }

    private function hasOnlyInertImageHostAttributes(DOMElement $host): bool
    {
        return null === WrapperDecision::imageHostIdentity(
            $host,
            fn (): bool => $this->runtimeIslands->isRuntimeDomTarget($host),
            fn (string $name): bool => DataAttributeReferences::isReferenced($this->session, $host, $name),
            fn (): bool => array() !== $this->sourceElementDescription->interactiveAttributes($host),
            fn (): bool => array() !== $this->session->authorSelectorProjectionState()->semanticMarkersForElement($host)
        );
    }

    private function hasBlockFigureDisplay(DOMElement $host): bool
    {
        return 'block' === strtolower(trim(CssValueInspector::withoutImportant(
            (string) ($this->styleResolver->structuralPresentationDeclarations($host)['display'] ?? '')
        )));
    }

    private function hasBlockFigureCarrier(DOMElement $host): bool
    {
        $parent = $host->parentNode;
        if ( ! $parent instanceof DOMElement ) {
            return false;
        }

        if ( in_array(strtolower($parent->tagName), array( 'a', 'abbr', 'b', 'button', 'em', 'i', 'label', 'p', 'small', 'span', 'strong' ), true) ) {
            return false;
        }

        $display = strtolower(trim(CssValueInspector::withoutImportant(
            (string) ($this->styleResolver->structuralPresentationDeclarations($parent)['display'] ?? '')
        )));
        if ( '' !== $display && ! in_array($display, array( 'block', 'flex', 'flow-root', 'grid', 'list-item', 'table-cell' ), true) ) {
            return false;
        }

        // An unstyled custom parent has the platform's inline default. Its child
        // cannot safely be replaced by a block figure without a declared carrier.
        return ! str_contains($parent->tagName, '-') || '' !== $display;
    }

    private function hasOnlyBlockDisplayPresentation(DOMElement $host, DOMElement $image): bool
    {
        // Structural declarations include otherwise-unmapped box properties such
        // as overflow; presentation declarations catch paint that a tag-specific
        // selector could otherwise lose when the host becomes a figure.
        $declarations = array_merge(
            $this->styleResolver->presentationDeclarations($host),
            $this->styleResolver->structuralPresentationDeclarations($host)
        );
        $imageHasDefinitePixelBox = $this->imageHasDefinitePixelBox($image);
        foreach ( $declarations as $property => $value ) {
            $property = strtolower($property);
            $value = strtolower(trim(CssValueInspector::withoutImportant((string) $value)));
            if ( 'display' === $property && 'block' === $value ) {
                continue;
            }
            if ( in_array($property, array( 'object-fit', 'object-position' ), true) ) {
                continue;
            }
            if ( $imageHasDefinitePixelBox && in_array($property, array( 'width', 'height' ), true) && '100%' === $value ) {
                continue;
            }
            return false;
        }

        return true;
    }

    private function imageHasDefinitePixelBox(DOMElement $image): bool
    {
        foreach ( array( 'width', 'height' ) as $property ) {
            $inline = SourceDeclarations::inline($image)[$property] ?? '';
            $value = trim(CssValueInspector::withoutImportant((string) $inline));
            if ( '' === $value ) {
                $pixels = HtmlDimensionAttribute::pixels(SourceDom::attr($image, $property));
                if ( null === $pixels || $pixels <= 0 ) {
                    return false;
                }
                continue;
            }
            if ( 1 !== preg_match('/^(?:\d+(?:\.\d+)?|\.\d+)(?:px)?$/i', $value) || (float) rtrim(strtolower($value), 'px') <= 0 ) {
                return false;
            }
        }

        return true;
    }

    private function hasCropFocusThatCoreImageCannotCarry(DOMElement $image): bool
    {
        $declarations = $this->styleResolver->imageShapeDeclarations($image);
        $scale = strtolower(CssValueInspector::withoutImportant((string) ($declarations['object-fit']['value'] ?? '')));
        if ( ! in_array($scale, array( 'cover', 'contain' ), true) ) {
            return false;
        }

        $position = strtolower(trim(CssValueInspector::withoutImportant((string) ($declarations['object-position']['value'] ?? ''))));
        return '' !== $position && ! in_array($position, array( 'center', 'center center', '50% 50%', 'unset', 'initial', 'revert', 'revert-layer' ), true);
    }
}

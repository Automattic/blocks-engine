<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\ImageUrlResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\ImageDimensionResolver;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
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
use DOMDocument;
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
        private readonly ImageUrlResolver $imageUrls
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
        if ($picture instanceof DOMElement && $picture !== $host) {
            $wrappers[] = $picture;
        }
        // Picture-less hosts may use a native group only when the boundary is
        // inert. Linked thumbnails and geometry-bearing media still need the
        // presentation carrier (including its intrinsic-height folding).
        if (count($wrappers) === 1 && ! $this->canGroupPicturelessImageHost($host, $image, $link)) {
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
        foreach ( $host->attributes as $attribute ) {
            $name = strtolower($attribute->name);
            if ( in_array($name, array( 'class', 'style' ), true) ) {
                continue;
            }
            if ( ! str_starts_with($name, 'data-')
                || $this->runtimeIslands->isRuntimeDomTarget($host)
                || $this->imageHostDataAttributeIsReferenced($host, $name) ) {
                return false;
            }
        }

        return ! $this->runtimeIslands->isRuntimeDomTarget($host)
            && array() === $this->sourceElementDescription->interactiveAttributes($host)
            && ! (array() !== $this->session->authorSelectorProjectionState()->semanticMarkersForElement($host));
    }

    private function imageHostDataAttributeIsReferenced(DOMElement $host, string $attribute): bool
    {
        foreach ( CssValueSplitter::ruleBlocks($this->session->authorStyleAnalysis()->combinedCss()) as $rule ) {
            foreach ( \Automattic\BlocksEngine\PhpTransformer\Support\RuntimeSelectorVocabulary::dataAttributeSelectorsFromCssSelector($rule['selector']) as $selector ) {
                if ( preg_match('/\\[' . preg_quote($attribute, '/') . '(?:\\]|[\\s*=~|^$*])/', $selector) ) {
                    return true;
                }
            }
        }

        $document = $host->ownerDocument;
        if ( ! $document instanceof DOMDocument ) {
            return false;
        }
        $datasetName = preg_replace_callback('/-([a-z])/', static fn (array $match): string => strtoupper($match[1]), substr($attribute, 5));
        $references = '/\\b' . preg_quote($attribute, '/') . '\\b|\\bdataset\\s*\.\\s*' . preg_quote((string) $datasetName, '/') . '\\b/i';
        foreach ( $document->getElementsByTagName('script') as $script ) {
            if ( $script instanceof DOMElement && preg_match($references, (string) $script->textContent) ) {
                return true;
            }
        }

        return false;
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
            $inline = $this->styleResolver->cssDeclarations(SourceDom::attr($image, 'style'))[$property] ?? '';
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

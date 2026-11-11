<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredButtonBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/** Converts buttons through search, image-carrier, and generic precedence. */
final class ButtonElementConverter implements ElementConverter
{
    /** Largest used size, in CSS pixels, still read as an icon rather than content media. */
    private const ICON_SCALE_MAX_PX = 64.0;

    public function __construct(
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier $sourceElementClassifier,
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator $createBlock,
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\ElementPresentationResolver $presentationResolver,
        private readonly ButtonElementContext $context)
    {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        $roleButton = AuthoredButtonBlockGenerator::isRoleButton($element);
        if ( 'button' !== $tagName && (!$roleButton || (!$this->context->isRuntimeDomTarget($element) && !SourceDom::isBoundCapturedDialogTrigger($element))) ) {
            return ConversionOutcome::unhandled();
        }

        // Bound dialog controls retain their native tree and source geometry,
        // even when an adjacent search form would otherwise replace them.
        if ( SourceDom::isBoundCapturedDialogTrigger($element) && ( $element->hasAttribute(CaptureEvidence::DIALOG_TRIGGER_STATE) || '' === trim($element->textContent) ) ) {
            return ConversionOutcome::handled($this->context->runtimeButton($element));
        }

        if ($roleButton) {
            if (!AuthoredButtonBlockGenerator::canRetainRoleButton($element)) return ConversionOutcome::unhandled();
            return ConversionOutcome::handled($this->context->runtimeButton($element));
        }

        if ( $this->context->isReplacedSearchClusterControl($element) ) {
            return ConversionOutcome::handled(null);
        }

        if ( $this->context->isRuntimeDomTarget($element) && $this->preservesUnsafeInlineHandler($element) ) {
            return ConversionOutcome::handled($this->context->convertButton($element));
        }

        if ( $this->context->isRuntimeDomTarget($element) && ( array() !== AuthoredButtonBlockGenerator::sourceSafeAttributes($element) || ! $this->sourceElementClassifier->isRichTextButtonLabel($element) ) ) {
            $runtimeButton = $this->context->runtimeButton($element);
            if ( null !== $runtimeButton ) {
                return ConversionOutcome::handled($runtimeButton);
            }
        }

        if ( $this->hasAccessibleIconOnlyContent($element) ) {
            return ConversionOutcome::handled($this->context->runtimeButton($element));
        }

        if ( $this->sourceElementClassifier->isImageCarrierButton($element) || $this->hasContentScaleImage($element) || ! $this->sourceElementClassifier->isRichTextButtonLabel($element) ) {
            $children = $this->context->convertChildren($element, $fallbacks, true);
            if ( array() !== $children ) {
                return ConversionOutcome::handled(
                    $this->createBlock->createBlock(
                        'core/group',
                        $this->presentationResolver->presentationAttributes($element),
                        $children,
                        $element
                    )
                );
            }

            return ConversionOutcome::unhandled();
        }

        // Flow content that still reads as one label must not reach
        // core/button's phrasing RichText, which erases a heading to plain
        // button text and loses its level, typography and color. Keep one real
        // button around the editable inner blocks, mirroring the flow-content
        // anchor path; a plain-text button still lowers to core/button below.
        if ( '' !== trim($element->textContent) && $this->sourceElementClassifier->hasBlockLevelDescendant($element) ) {
            $container = $this->context->convertButtonWrapperGroup($element, $fallbacks);
            if ( null !== $container ) {
                return ConversionOutcome::handled($container);
            }
        }

        return ConversionOutcome::handled($this->context->convertButton($element));
    }

    /**
     * Whether the button paints an image at content scale.
     *
     * core/button's label is phrasing text, so lowering there swaps every
     * image for its alt text. That is right for icons beside a label, and the
     * structural classifier keeps unsized or caption-repeating images apart.
     * An image whose used size exceeds icon scale on either axis at every
     * viewport -- a fixed size, or the guaranteed minimum of a fluid one such
     * as `clamp(320px, 30vw, 480px)` -- is content, though: flattening it to
     * alt text would drop a visible logo or photo, whatever caption sits
     * beside it.
     */
    private function hasContentScaleImage(DOMElement $element): bool
    {
        if ( 'submit' === strtolower(SourceDom::attr($element, 'type')) ) {
            return false;
        }

        foreach ( $element->getElementsByTagName('img') as $image ) {
            $size = $this->context->imageMinimumPixelSize($image);
            if ( null === $size ) {
                return false;
            }
            foreach ( array( 'width', 'height' ) as $axis ) {
                if ( null !== $size[ $axis ] && self::ICON_SCALE_MAX_PX < $size[ $axis ] ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasAccessibleIconOnlyContent(DOMElement $element): bool
    {
        if ( '' === trim(SourceDom::attr($element, 'aria-label')) ) {
            return false;
        }

        $hasArtwork = false;
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType || XML_CDATA_SECTION_NODE === $child->nodeType ) {
                if ( '' !== trim($child->textContent ?? '') ) {
                    return false;
                }
                continue;
            }
            if ( ! $child instanceof DOMElement ) {
                return false;
            }
            // Empty phrasing and container elements can be the entire visible
            // icon: their classes paint backgrounds, borders and pseudo-elements
            // (a hamburger drawn as stacked empty <div> bars, say). They need
            // the same native host as image/SVG icons, not core/button's
            // text-only label (which WordPress suppresses when empty).
            if ( $this->isEmptyDecoration($child) || $this->isWrappedArtwork($child) ) {
                $hasArtwork = true;
                continue;
            }
            if ( ! in_array(strtolower($child->tagName), array('img', 'svg'), true) ) {
                return false;
            }
            if ( 'svg' === strtolower($child->tagName)
                && ( ! SourceDom::isAriaHidden($child)
                    || '' === trim(SourceDom::attr($child, 'viewbox'))
                    || 0 === SourceDom::childElementCount($child) ) ) {
                return false;
            }
            $hasArtwork = true;
        }

        return $hasArtwork;
    }

    private function isWrappedArtwork(DOMElement $element): bool
    {
        if (!in_array(strtolower($element->tagName), array('span', 'i', 'b', 'em', 'strong', 'small'), true)
            || '' !== trim($element->textContent ?? '')) {
            return false;
        }
        $hasArtwork = false;
        foreach ($element->childNodes as $child) {
            if (!$child instanceof DOMElement) continue;
            if (in_array(strtolower($child->tagName), array('svg', 'img'), true)) {
                if ('svg' === strtolower($child->tagName)
                    && ('' === trim(SourceDom::attr($child, 'viewbox'))
                        || 0 === SourceDom::childElementCount($child))) return false;
                $hasArtwork = true;
            } elseif ($this->isWrappedArtwork($child)) {
                $hasArtwork = true;
            } elseif (!$this->isEmptyDecoration($child)) {
                return false;
            }
        }
        return $hasArtwork;
    }

    private function isEmptyDecoration(DOMElement $element): bool
    {
        if ( ! in_array(strtolower($element->tagName), array('div', 'span', 'i', 'b', 'em', 'strong', 'small'), true)
            || '' !== trim($element->textContent ?? '') ) {
            return false;
        }
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && ! $this->isEmptyDecoration($child) ) {
                return false;
            }
        }
        return true;
    }

    private function preservesUnsafeInlineHandler(DOMElement $element): bool
    {
        if ( array() !== AuthoredButtonBlockGenerator::sourceSafeAttributes($element) ) {
            return false;
        }

        if ( array() !== SourceDom::eventMetadata($element) ) {
            return true;
        }

        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement && array() !== SourceDom::eventMetadata($descendant) ) {
                return true;
            }
        }

        return false;
    }
}

<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/**
 * Anchors are phrasing content, but button-like anchors must be offered to the
 * button dispatcher before generic inline lowering splits their label and
 * decorative SVG into separate paragraph blocks. Every anchor ends here.
 */
final class AnchorElementConverter implements ElementConverter
{
    public function __construct(
        private readonly ButtonLinkDispatcher $buttonLinks,
        private readonly EmptyVisualSpacerLowering $emptyVisualSpacers,
        private readonly EmptyVisualRetention $emptyVisualRetention,
        private readonly UnsupportedElementRecorder $unsupportedRecorder
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        if ( 'a' !== $tagName ) {
            return ConversionOutcome::unhandled();
        }
        $fragmentTarget = $this->emptyNamedFragmentTargetBlock($element);
        if ( null !== $fragmentTarget ) {
            return ConversionOutcome::handled($fragmentTarget);
        }
        if ( SourceDom::isEmptyNamedFragmentTarget($element) ) {
            return ConversionOutcome::handled(null);
        }
        $this->lowerSourcelessImagesToAltText($element);
        $anchorBlock = $this->buttonLinks->convertAnchor($element, $fallbacks);
        if ( null !== $anchorBlock ) {
            return ConversionOutcome::handled($anchorBlock);
        }

        // A null here used to end the anchor's conversion outright: no
        // block, no fallback, no finding — the subtree simply disappeared,
        // and nothing downstream could count what was lost. Hand it to the
        // same terminal recorder every other unconvertible element reaches,
        // so it is either generated as a custom block or recorded as an
        // unsupported-element fallback the import report can account for.
        //
        // An anchor that neither navigates nor renders anything is not
        // that loss. It is the same non-rendering inline scaffolding as
        // the empty `<span>` a widget usually leaves beside it, so it
        // follows the empty-inline disposition: carried only when authored
        // styles give its box something to show, never counted as an
        // unsupported-element fallback. Descendants that render nothing
        // either (a srcless `<img>`, an unstyled empty `<div>`, as an
        // unpopulated listing template leaves them) keep it that shape.
        if ( SourceDom::isEmptyNonNavigatingAnchor($element) || $this->isNonRenderingNonNavigatingAnchor($element) ) {
            return ConversionOutcome::handled($this->emptyVisualRetention->shouldPreserveEmptyVisualElement($element)
                ? $this->emptyVisualSpacers->emptyVisualSpacerBlock($element)
                : null);
        }

        return ConversionOutcome::handled($captureUnsupported ? $this->unsupportedRecorder->record($element, $tagName, $fallbacks) : null);
    }

    /**
     * A non-navigating anchor whose children all render nothing: no text, and
     * every descendant is a srcless `<img>` or a `<div>`/`<span>` box that
     * authored styles leave empty. Each such child converts to nothing on its own.
     */
    private function isNonRenderingNonNavigatingAnchor(DOMElement $element): bool
    {
        if ( 0 === SourceDom::childElementCount($element) ) {
            return false;
        }
        $clone = $element->cloneNode(false);
        if ( ! $clone instanceof DOMElement || ! SourceDom::isEmptyNonNavigatingAnchor($clone) || '' !== trim($element->textContent ?? '') ) {
            return false;
        }
        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            $tag = strtolower($descendant->tagName);
            // A srcless `<img>` converts to nothing on its own, whatever box
            // authored styles give it (an agent-photo circle awaiting data),
            // so the anchor around it follows that same disposition.
            if ( 'img' === $tag ) {
                if ( '' !== trim(SourceDom::attr($descendant, 'src')) || '' !== trim(SourceDom::attr($descendant, 'srcset')) ) {
                    return false;
                }
                continue;
            }
            if ( $this->emptyVisualRetention->shouldPreserveEmptyVisualElement($descendant) ) {
                return false;
            }
            if ( ! in_array($tag, array('div', 'span'), true) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * A link whose only content is `<img>` elements with no image source (no
     * src, srcset, lazy-source attribute or host metadata) shows no image: the
     * browser renders each one as its alt text, and the link navigates on that
     * text. Lower it as that text link. Each sourceless image is replaced by
     * its alt text; one without alt text renders nothing and is dropped, which
     * leaves the empty destination link every other text-less link becomes.
     * Links that wrap anything else, and href-less anchors (handled below as
     * non-rendering scaffolding), are left unchanged.
     */
    private function lowerSourcelessImagesToAltText(DOMElement $element): void
    {
        if ( ! $element->hasAttribute('href') || '' !== trim($element->textContent ?? '') || 0 === SourceDom::childElementCount($element) ) {
            return;
        }
        $images = array();
        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( 'img' !== strtolower($descendant->tagName) || ! $this->isSourcelessImage($descendant) ) {
                return;
            }
            $images[] = $descendant;
        }
        $labelled = array_values(array_filter($images, static fn (DOMElement $image): bool => '' !== self::altText($image)));
        foreach ( $images as $image ) {
            $alt = self::altText($image);
            $parent = $image->parentNode;
            if ( null === $parent || null === $element->ownerDocument ) {
                continue;
            }
            if ( '' === $alt ) {
                $parent->removeChild($image);
                continue;
            }
            $separator = $image === $labelled[0] ? '' : ' ';
            $parent->replaceChild($element->ownerDocument->createTextNode($separator . $alt), $image);
        }
    }

    private function isSourcelessImage(DOMElement $image): bool
    {
        foreach ( array( 'src', 'srcset', 'data-src', 'data-lazy-src', 'data-original', 'data-image-src', 'data-srcset', 'data-lazy-srcset' ) as $attribute ) {
            if ( '' !== trim(SourceDom::attr($image, $attribute)) ) {
                return false;
            }
        }
        $parent = $image->parentNode;
        if ( $parent instanceof DOMElement && 'picture' === strtolower($parent->tagName) ) {
            return false;
        }

        return '' === trim(SourceDom::imageUrlFromHostMetadata($image));
    }

    private static function altText(DOMElement $image): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', SourceDom::attr($image, 'alt')));
    }

    /**
     * An empty href-less named `<a>` is a fragment target. Preserve its
     * identifier on a native block HTML anchor rather than recording it as
     * unsupported HTML. Returns null when the element is not that shape, or
     * when the identifier already exists elsewhere in the document.
     *
     * @return array<string, mixed>|null
     */
    private function emptyNamedFragmentTargetBlock(DOMElement $element): ?array
    {
        if ( ! SourceDom::isEmptyNamedFragmentTarget($element) ) {
            return null;
        }

        $id = SourceDom::namedFragmentTargetId($element);
        if ( SourceDom::documentHasOtherFragmentTarget($element, $id) ) {
            return null;
        }

        if ( '' === trim(SourceDom::attr($element, 'id')) ) {
            $element->setAttribute('id', $id);
        }

        return $this->emptyVisualSpacers->emptyVisualSpacerBlock($element);
    }
}

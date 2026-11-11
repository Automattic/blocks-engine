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
        // unsupported-element fallback.
        if ( SourceDom::isEmptyNonNavigatingAnchor($element) ) {
            return ConversionOutcome::handled($this->emptyVisualRetention->shouldPreserveEmptyVisualElement($element)
                ? $this->emptyVisualSpacers->emptyVisualSpacerBlock($element)
                : null);
        }

        return ConversionOutcome::handled($captureUnsupported ? $this->unsupportedRecorder->record($element, $tagName, $fallbacks) : null);
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

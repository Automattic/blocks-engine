<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use DOMElement;

/**
 * Presentation for disclosure controls core saves without a box of its own.
 *
 * core/details renders `<summary>` with no attributes and core/accordion-heading
 * saves its own `<button>` with a fixed class, so in both cases the source
 * control's classes are dropped and every author rule addressing them is left
 * with nothing to match. The control's resolved box therefore has to be restated
 * as CSS keyed on a marker the block carries — adding attributes to the trigger
 * would diverge from core's save shape and invalidate the block.
 *
 * This lived in HtmlCompilation, which is where presentation resolution ends up
 * by default rather than by design. It depends on nothing from the compilation
 * itself: a style resolver to read the source with, and the generated support
 * stylesheet to register the result on.
 */
final class DisclosureControlPresentation
{
    public function __construct(
        private readonly StyleResolver $styles,
        private readonly GeneratedSupportStylesheetState $support
    ) {
    }

    /**
     * Marker for an accordion trigger whose box core/accordion cannot save.
     *
     * core/accordion-heading saves its own `<button>` with a fixed class and no
     * others, so the source trigger's classes are dropped and every author rule
     * addressing them is left with nothing to match. A trigger that stated its
     * own vertical padding collapses onto the destination theme's defaults and
     * every row in the accordion loses that height.
     *
     * Delivered as CSS keyed on a marker the heading carries, not as markup:
     * adding attributes to the toggle would diverge from core's save shape and
     * invalidate the block.
     */
    public function accordionToggleMarker(DOMElement $control): string
    {
        return $this->disclosureControlMarker($control, 'blocks-engine-accordion-toggle-');
    }

    /**
     * Marker for a disclosure toggle whose box core/details cannot save.
     *
     * core/details renders `<summary>` with no attributes, so a source toggle's
     * own classes are dropped and every author rule addressing them is left with
     * nothing to match — an overlay menu button loses its paint, its radius and
     * its label typography, and drops to the destination theme's defaults.
     *
     * Delivered as CSS keyed on a marker the details block carries, not as
     * markup: adding attributes to `<summary>` would diverge from core's save
     * shape and invalidate the block.
     */
    public function disclosureSummaryMarker(DOMElement $summary): string
    {
        return $this->disclosureControlMarker($summary, 'blocks-engine-disclosure-summary-');
    }

    /**
     * Register a core-owned control's presentation and return its marker.
     *
     * The unconditional box is resolved once. `display` is additionally stated
     * per viewport whenever the source conditions it, because a control hidden
     * by a responsive utility states its visibility only inside a media
     * condition — flattening that to the reference viewport's value would show
     * a small-screen control on every screen.
     */
    private function disclosureControlMarker(DOMElement $control, string $prefix): string
    {
        $conditionalDisplay = $this->styles->conditionalDisplayRules($control);
        $css = $this->disclosureControlCarriedCss($control, array() !== $conditionalDisplay);
        if ( '' === $css && array() === $conditionalDisplay ) {
            return '';
        }

        $marker = $prefix . substr(hash('sha256', $css . '|' . serialize($conditionalDisplay)), 0, 12);
        if ( '' !== $css ) {
            if ( str_starts_with($prefix, 'blocks-engine-accordion-toggle-') ) {
                $this->support->registerAccordionTogglePresentation($marker, $css);
            } else {
                $this->support->registerDisclosureSummaryPresentation($marker, $css);
            }
        }
        if ( array() !== $conditionalDisplay ) {
            $this->support->registerDisclosureControlConditionalDisplay($marker, $conditionalDisplay);
        }

        return $marker;
    }

    /**
     * The resolved box a core-owned disclosure control cannot carry as markup.
     *
     * Both core/details and core/accordion-heading save a bare trigger element,
     * so the source control's own presentation has to be restated as CSS.
     */
    private function disclosureControlCarriedCss(DOMElement $control, bool $displayIsConditional = false): string
    {
        $summary = $control;
        $declarations = $this->styles->safeVisualDeclarations(
            $this->styles->resolvedPresentationDeclarations($summary)
        );
        // core renders `<summary>` with no box of its own, so the toggle's own
        // box is carried here alongside its paint and type. Position and margin
        // stay out: the details block core lays out already holds the slot.
        $carried = array_filter(
            $declarations,
            static fn (string $property): bool => (bool) preg_match(
                '/^(?:align-items|background|border|border-radius|box-shadow|box-sizing|color|display|font|height|justify-content|letter-spacing|line-height|max-height|max-width|min-height|min-width|padding|text-align|text-decoration|text-transform|width)(?:-[a-z-]+)?$/',
                $property
            ),
            ARRAY_FILTER_USE_KEY
        );
        // The label the source painted keeps its classes but loses the toggle
        // ancestor those rules were written against. Its type is inheritable, so
        // restating it on the summary reaches the label again, and any rule the
        // label still owns keeps winning over it.
        $carried = array_merge($this->disclosureSummaryLabelTypography($summary), $carried);
        // A `display` the source states per viewport is carried with its
        // conditions instead. Restating the reference viewport's value here
        // unconditionally would outrank the author's own responsive rule, which
        // is layered, and show a small-screen control on every screen.
        if ( $displayIsConditional ) {
            unset($carried['display']);
        }

        return $this->styles->cssDeclarationString($carried);
    }

    /**
     * Inheritable type the disclosure label showed, read from the source.
     *
     * @return array<string, string>
     */
    private function disclosureSummaryLabelTypography(DOMElement $summary): array
    {
        $label = null;
        foreach ( $summary->getElementsByTagName('*') as $descendant ) {
            if ( ! $descendant instanceof DOMElement ) {
                continue;
            }
            foreach ( $descendant->childNodes as $child ) {
                if ( XML_TEXT_NODE === $child->nodeType && '' !== trim($child->textContent ?? '') ) {
                    $label = $descendant;
                    break 2;
                }
            }
        }
        if ( ! $label instanceof DOMElement ) {
            return array();
        }

        $declarations = $this->styles->safeVisualDeclarations(
            $this->styles->resolvedPresentationDeclarations($label)
        );

        return array_filter(
            $declarations,
            static fn (string $property): bool => (bool) preg_match(
                '/^(?:color|font|font-family|font-size|font-style|font-weight|letter-spacing|line-height|text-transform|text-decoration)$/',
                $property
            ),
            ARRAY_FILTER_USE_KEY
        );
    }
}

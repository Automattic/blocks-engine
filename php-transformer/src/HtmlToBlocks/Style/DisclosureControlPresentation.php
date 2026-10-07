<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use DOMElement;
use Closure;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;

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
    public const SUMMARY_CONTENT_CARRIER_CLASS = 'blocks-engine-summary-content-carrier';

    public function __construct(
        private readonly StyleResolver $styles,
        private readonly GeneratedSupportStylesheetState $support,
        private readonly ?Closure $svgMarkup = null
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
        $isSummary = str_starts_with($prefix, 'blocks-engine-disclosure-summary-');
        $hasContentCarrier = $isSummary && $this->summaryHasContentCarrier($control);
        $css = $this->disclosureControlCarriedCss(
            $control,
            array() !== $conditionalDisplay,
            $isSummary,
            $isSummary && ! $hasContentCarrier
        );
        $carrierCss = $hasContentCarrier
            ? $this->disclosureControlCarriedCss($control, array() !== $conditionalDisplay, true, true)
            : '';
        $conditionalPresentation = $this->conditionalPresentation($control, $isSummary);
        $titleCss = str_starts_with($prefix, 'blocks-engine-accordion-toggle-')
            ? $this->styles->cssDeclarationString($this->disclosureSummaryLabelTypography($control)) : '';
        $icon = str_starts_with($prefix, 'blocks-engine-accordion-toggle-') ? $this->accordionIcon($control) : array();
        if ( '' === $css && '' === $carrierCss && array() === $conditionalDisplay && array() === $conditionalPresentation && array() === $icon ) {
            return '';
        }

        $marker = $prefix . substr(hash('sha256', $css . '|' . $carrierCss . '|' . serialize($conditionalDisplay) . '|' . serialize($conditionalPresentation) . '|' . $titleCss . '|' . serialize($icon)), 0, 12);
        if ( '' !== $css ) {
            if ( str_starts_with($prefix, 'blocks-engine-accordion-toggle-') ) {
                $this->support->registerAccordionTogglePresentation($marker, $css);
            } else {
                $this->support->registerDisclosureSummaryPresentation($marker, $css);
            }
        }
        if ( '' !== $carrierCss ) {
            $this->support->registerDisclosureSummaryContentCarrierPresentation($marker, $carrierCss);
        }
        if ( array() !== $conditionalDisplay ) {
            $this->support->registerDisclosureControlConditionalDisplay($marker, $conditionalDisplay);
        }
        if ( array() !== $conditionalPresentation ) {
            $this->support->registerDisclosureControlConditionalPresentation($marker, $conditionalPresentation);
        }
        if ( '' !== $titleCss ) {
            // Core inserts a title span around the source label. Its own line
            // box otherwise inherits the larger trigger font and makes rows
            // taller even when the nested source label remains styled correctly.
            $this->support->registerAccordionTitlePresentation($marker, $titleCss);
        }
        if ( array() !== $icon ) {
            $this->support->registerAccordionIconPresentation($marker, $icon);
        }

        return $marker;
    }

    /** Core owns the icon span; carry observed passive SVG artwork through CSS.
     * Expanded class/style attributes come from a verified producer drive.
     * No icon shape or vendor class name defines an expanded-state rotation.
     * @return array<string, string>
     */
    private function accordionIcon(DOMElement $control): array
    {
        $icons = $control->getElementsByTagName('svg');
        if ( null === $this->svgMarkup || 1 !== $icons->length ) return array();
        $svg = $icons->item(0);
        if ( ! $svg instanceof DOMElement || ! SourceDom::svgHasDrawableContent($svg) ) return array();
        foreach (array('class', 'style') as $attribute) {
            if ($svg->hasAttribute('data-dla-disclosure-closed-' . $attribute)
                && $svg->getAttribute($attribute) !== $svg->getAttribute('data-dla-disclosure-closed-' . $attribute)) return array();
        }
        $declarations = $this->styles->resolvedPresentationDeclarations($svg);
        $dimensions = array();
        foreach ( array('width', 'height') as $property ) {
            $value = trim((string) ($declarations[$property] ?? $svg->getAttribute($property)));
            if ( is_numeric($value) ) $value .= 'px';
            if ( ! preg_match('/^\d+(?:\.\d+)?(?:px|em|rem)$/', $value) ) return array();
            $dimensions[$property] = $value;
        }
        $clone = $svg->cloneNode(true);
        if ( ! $clone instanceof DOMElement ) return array();
        $inline = $this->styles->cssDeclarations($clone->getAttribute('style'));
        unset($inline['transform'], $inline['rotate']);
        $color = (string) ($declarations['color'] ?? $this->styles->authoredInheritedPropertyWinner($svg, 'color'));
        if ( '' !== $color ) {
            $color = $this->styles->resolveCssVariablesInValue($color, $svg);
            $paint = $this->styles->safeVisualDeclarations(array('color' => $color));
            $inline = array_merge($inline, $paint);
        }
        $clone->setAttribute('style', $this->styles->cssDeclarationString($inline));
        $markup = ($this->svgMarkup)($clone);
        if ( ! SourceDom::isSafeSvgContent($markup) ) return array();
        $base = $this->styles->cssDeclarationString($dimensions)
            . ';display:inline-block;flex-shrink:0;font-size:0;line-height:0;transform:none;rotate:none'
            . ';background-image:url("data:image/svg+xml,' . rawurlencode($markup) . '");background-repeat:no-repeat;background-position:center;background-size:contain';
        $stateCss = fn (array $values): string => $this->styles->cssDeclarationString(array_merge(
            array('transform' => 'none', 'rotate' => 'none'),
            array_intersect_key($values, array_flip(array('transform', 'rotate', 'transform-origin', 'translate', 'scale', 'opacity', 'transition-property', 'transition-duration', 'transition-timing-function', 'transition-delay')))
        ));
        $closed = $this->styles->resolvedSourceStateDeclarations($svg);
        $expanded = $svg->cloneNode(true);
        if ( ! $expanded instanceof DOMElement ) return array();
        foreach ( array('class', 'style') as $attribute ) {
            if ( $svg->hasAttribute('data-dla-disclosure-open-' . $attribute) ) {
                $expanded->setAttribute($attribute, $svg->getAttribute('data-dla-disclosure-open-' . $attribute));
            }
        }
        // A detached state clone loses the source ancestors that author rules
        // address. The native-identity selector cache already distinguishes
        // replacement nodes; reuse it with the clone connected in that slot.
        $parent = $svg->parentNode;
        if (null === $parent) return array();
        $parent->replaceChild($expanded, $svg);
        try {
            $open = $this->styles->resolvedSourceStateDeclarations($expanded);
        } finally {
            $parent->replaceChild($svg, $expanded);
        }
        return array('closed' => $base . ';' . $stateCss($closed), 'open' => $stateCss($open));
    }

    /**
     * The resolved box a core-owned disclosure control cannot carry as markup.
     *
     * Both core/details and core/accordion-heading save a bare trigger element,
     * so the source control's own presentation has to be restated as CSS.
     */
    private function disclosureControlCarriedCss(DOMElement $control, bool $displayIsConditional = false, bool $carrySummaryLayout = false, bool $preserveReferenceDisplay = false): string
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
        if ( $carrySummaryLayout ) {
            $carried = array_merge($carried, array_intersect_key($declarations, array_flip(array('gap', 'row-gap', 'column-gap', 'flex-direction', 'flex-wrap'))));
        }
        // The label the source painted keeps its classes but loses the toggle
        // ancestor those rules were written against. Its type is inheritable, so
        // restating it on the summary reaches the label again, and any rule the
        // label still owns keeps winning over it.
        $carried = array_merge($this->disclosureSummaryLabelTypography($summary), $carried);
        // A source button inherits body type; the generated h3 introduces a
        // theme heading font between it and that ancestor. Carry the authored
        // inherited winner across that new semantic wrapper.
        foreach ( array('font-family', 'line-height') as $property ) {
            if ( ! isset($carried[$property]) || in_array(strtolower(trim($carried[$property])), array('inherit', 'unset'), true) ) {
                $value = $this->styles->authoredInheritedPropertyWinner($control, $property);
                if ( '' !== $value ) {
                    $carried[$property] = $this->styles->resolveCssVariablesInValue($value, $control);
                }
            }
        }
        // A `display` the source states per viewport is carried with its
        // conditions instead. Restating the reference viewport's value here
        // unconditionally would outrank the author's own responsive rule, which
        // is layered, and show a small-screen control on every screen.
        if ( $displayIsConditional ) {
            if ( ! $preserveReferenceDisplay ) {
                unset($carried['display']);
            }
        }

        return $this->styles->cssDeclarationString($carried);
    }

    private function summaryHasContentCarrier(DOMElement $summary): bool
    {
        foreach ( SourceDom::htmlAttributes($summary) as $name => $_value ) {
            $name = strtolower($name);
            if ( in_array($name, array('class', 'style', 'title'), true) || str_starts_with($name, 'data-') ) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> */
    private function conditionalPresentation(DOMElement $control, bool $carrySummaryLayout = false): array
    {
        $rules = array();
        // These are the same visual families the bare core trigger cannot
        // retain. Preserve viewport conditions instead of baking a scalar box.
        $properties = array('padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'font-family', 'font-size', 'font-weight', 'line-height', 'min-height', 'min-width', 'height', 'width', 'color', 'background-color', 'border-radius', 'align-items', 'justify-content');
        if ( $carrySummaryLayout ) {
            $properties = array_merge($properties, array('gap', 'row-gap', 'column-gap', 'flex-direction', 'flex-wrap'));
        }
        foreach ( $properties as $property ) {
            foreach ( $this->styles->declaredPresentation($control, $property)->conditional() as $condition => $value ) {
                $declarations = $this->styles->safeVisualDeclarations(array($property => $this->styles->resolveCssVariablesInValue($value, $control)));
                $rules[$condition] = array_merge($rules[$condition] ?? array(), $declarations);
            }
        }
        return array_map($this->styles->cssDeclarationString(...), $rules);
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

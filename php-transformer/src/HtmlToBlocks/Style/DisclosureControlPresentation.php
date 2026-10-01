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
        $css = $this->disclosureControlCarriedCss($control, array() !== $conditionalDisplay);
        $conditionalPresentation = $this->conditionalPresentation($control);
        $titleCss = str_starts_with($prefix, 'blocks-engine-accordion-toggle-')
            ? $this->styles->cssDeclarationString($this->disclosureSummaryLabelTypography($control)) : '';
        if ( '' !== $titleCss && in_array($this->styles->resolvedPresentationDeclarations($control)['display'] ?? '', array('flex', 'inline-flex'), true) ) {
            // The retained source label was a flex item. Core's extra title
            // span must not turn it into inline text and change its wrapping.
            $titleCss .= ';display:contents';
        }
        $icon = str_starts_with($prefix, 'blocks-engine-accordion-toggle-') ? $this->accordionIcon($control) : array();
        if ( '' === $css && array() === $conditionalDisplay && array() === $conditionalPresentation && array() === $icon ) {
            return '';
        }

        $marker = $prefix . substr(hash('sha256', $css . '|' . serialize($conditionalDisplay) . '|' . serialize($conditionalPresentation) . '|' . $titleCss . '|' . serialize($icon)), 0, 12);
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
        foreach (array('transform', 'rotate', 'translate', 'scale', 'transform-origin', 'transform-box') as $property) unset($inline[$property]);
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
        $stateCss = fn (DOMElement $element): string => implode(';', array_map(
            fn (string $property): string => $property . ':' . $this->styles->resolveCssVariablesInValue(
                (string) ($this->styles->matchedCascadedDeclarations($element)[$property] ?? 'none'), $element
            ), array('transform', 'rotate')
        ));
        $closed = $stateCss($svg);
        $expanded = $svg->cloneNode(true);
        if ( ! $expanded instanceof DOMElement ) return array();
        foreach ( array('class', 'style') as $attribute ) {
            if ( $svg->hasAttribute('data-dla-disclosure-open-' . $attribute) ) {
                $expanded->setAttribute($attribute, $svg->getAttribute('data-dla-disclosure-open-' . $attribute));
            }
        }
        // Resolve the observed state with its ancestor scope, on a detached
        // tree so the original DOM and its path-keyed cascade cache stay intact.
        // Source-only custom properties cannot reach the generated icon span.
        $scope = $expanded;
        for ( $ancestor = $svg->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode ) {
            $parent = $ancestor->cloneNode(false);
            $parent->appendChild($scope);
            $scope = $parent;
        }
        $open = $stateCss($expanded);
        $vectorState = function (DOMElement $element) use ($stateCss): string {
            $css = $stateCss($element);
            $declarations = $this->styles->matchedCascadedDeclarations($element);
            foreach (array('translate' => 'none', 'scale' => 'none', 'transform-origin' => '50% 50%', 'transform-box' => 'view-box') as $property => $default) {
                $css .= ';' . $property . ':' . $this->styles->resolveCssVariablesInValue((string) ($declarations[$property] ?? $default), $element);
            }
            return $css;
        };
        return array('closed' => $base . ';' . $closed, 'open' => $open, 'svg' => $markup, 'vector_closed' => $vectorState($svg), 'vector_open' => $vectorState($expanded));
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
            unset($carried['display']);
        }

        return $this->styles->cssDeclarationString($carried);
    }

    /** @return array<string, string> */
    private function conditionalPresentation(DOMElement $control): array
    {
        $rules = array();
        // These are the same visual families the bare core trigger cannot
        // retain. Preserve viewport conditions instead of baking a scalar box.
        $properties = array('padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'font-family', 'font-size', 'font-weight', 'line-height', 'min-height', 'min-width', 'height', 'width', 'color', 'background-color', 'border-radius', 'align-items', 'justify-content');
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

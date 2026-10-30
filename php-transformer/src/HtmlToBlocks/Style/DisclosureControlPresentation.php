<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
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
     *
     * A source-proved vector icon is returned alongside the marker. The heading
     * carries it as block metadata so the theme renders the actual SVG into
     * core's icon slot; its saved markup stays core's exact shape.
     *
     * `$row` is the source header row that holds the label and, at its inline
     * end, the source icon when the control itself does not contain one — an
     * item-level control whose title is a bare heading beside its icon. The
     * icon is carried from it, and the row's gap travels with the icon so the
     * label wraps where it did in the source.
     *
     * @return array{className: string, iconSvg: string}
     */
    public function accordionToggle(DOMElement $control, ?DOMElement $row = null): array
    {
        return $this->disclosureControlPresentation($control, 'blocks-engine-accordion-toggle-', $row);
    }

    /**
     * The source item's own box — padding and border — as core/accordion-item
     * style attributes.
     *
     * The item's source classes still travel, but the rules that box it often
     * address it through the list structure core/accordion replaces, so the
     * resolved, unconditional box is stated on the block itself. A family the
     * source conditions per viewport is left to the source cascade: an inline
     * reference-viewport value would outrank the author's responsive rule.
     *
     * @return array<string, string>
     */
    public function accordionItemBox(DOMElement $item): array
    {
        $declarations = $this->styles->safeVisualDeclarations($this->styles->resolvedPresentationDeclarations($item));
        $box = array();
        foreach ( $declarations as $property => $value ) {
            if ( ! preg_match('/^(?:padding|border)(?:-(?:top|right|bottom|left))?(?:-(?:width|style|color))?$/', $property) ) {
                continue;
            }
            if ( array() !== $this->styles->declaredPresentation($item, $property)->conditional() ) {
                continue;
            }
            $box[$property] = $this->styles->resolveCssVariablesInValue($value, $item);
        }

        return $box;
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
        return $this->disclosureControlPresentation($summary, 'blocks-engine-disclosure-summary-')['className'];
    }

    /**
     * Register a core-owned control's presentation and return its marker.
     *
     * The unconditional box is resolved once. `display` is additionally stated
     * per viewport whenever the source conditions it, because a control hidden
     * by a responsive utility states its visibility only inside a media
     * condition — flattening that to the reference viewport's value would show
     * a small-screen control on every screen.
     *
     * @return array{className: string, iconSvg: string}
     */
    private function disclosureControlPresentation(DOMElement $control, string $prefix, ?DOMElement $row = null): array
    {
        $conditionalDisplay = $this->styles->conditionalDisplayRules($control);
        $isSummary = str_starts_with($prefix, 'blocks-engine-disclosure-summary-');
        $isAccordionToggle = str_starts_with($prefix, 'blocks-engine-accordion-toggle-');
        $hasContentCarrier = $isSummary && $this->summaryHasContentCarrier($control);
        $css = $this->disclosureControlCarriedCss(
            $control,
            array() !== $conditionalDisplay,
            $isSummary,
            $isSummary && ! $hasContentCarrier
        );
        // A synthesized summary replaces a button-like control, not a native
        // disclosure marker. Native summary sources retain their own chrome.
        $suppressMarker = $isSummary && 'summary' !== strtolower($control->tagName);
        if ( $suppressMarker ) {
            $css = '' !== $css ? $css . ';list-style:none' : 'list-style:none';
        }
        $icon = array();
        $iconSource = null;
        if ( $isAccordionToggle ) {
            $icon = $this->accordionIcon($control);
            $iconSource = isset($icon['svg']) ? $control : null;
            if ( array() === $icon && $row instanceof DOMElement && ! $row->isSameNode($control) ) {
                $icon = $this->accordionIcon($row);
                $iconSource = isset($icon['svg']) ? $row : null;
            }
            $css = $this->accordionToggleBoxCss($control, $row, $iconSource, $css);
        }
        $carrierCss = $hasContentCarrier
            ? $this->disclosureControlCarriedCss($control, array() !== $conditionalDisplay, true, true)
            : '';
        $conditionalPresentation = $this->conditionalPresentation($control, $isSummary);
        $titleCss = str_starts_with($prefix, 'blocks-engine-accordion-toggle-')
            ? $this->styles->cssDeclarationString($this->disclosureSummaryLabelTypography($control)) : '';
        if ( '' !== $titleCss && in_array($this->styles->resolvedPresentationDeclarations($control)['display'] ?? '', array('flex', 'inline-flex'), true) ) {
            // The retained source label was a flex item. Core's extra title
            // span must not turn it into inline text and change its wrapping.
            $titleCss .= ';display:contents';
        }
        if ( '' === $css && '' === $carrierCss && array() === $conditionalDisplay && array() === $conditionalPresentation && array() === $icon ) {
            return array('className' => '', 'iconSvg' => '');
        }

        $marker = $prefix . substr(hash('sha256', $css . '|' . $carrierCss . '|' . serialize($conditionalDisplay) . '|' . serialize($conditionalPresentation) . '|' . $titleCss . '|' . serialize($icon)), 0, 12);
        if ( '' !== $css ) {
            if ( str_starts_with($prefix, 'blocks-engine-accordion-toggle-') ) {
                $this->support->registerAccordionTogglePresentation($marker, $css);
            } else {
                $this->support->registerDisclosureSummaryPresentation($marker, $css, $suppressMarker);
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
            $this->support->registerAccordionIconPresentation($marker, array_diff_key($icon, array('svg' => true)));
        }

        return array('className' => $marker, 'iconSvg' => $icon['svg'] ?? '');
    }

    /**
     * The toggle box core/accordion-heading's button must hold.
     *
     * Core lays the toggle out as a flex row (title, then icon) and pads it
     * through the destination theme's `spacing-20` preset. The source header's
     * own padding is therefore always stated — `0` when the source has none —
     * so row height never depends on the destination spacing scale. Core's flex
     * display stays: a source `display:block` would push the icon under the
     * title. With a carried source icon, the source row's gap and cross-axis
     * alignment ride on the toggle so the icon sits at the row's inline end
     * after the same gap, and the label wraps where it did in the source.
     */
    private function accordionToggleBoxCss(DOMElement $control, ?DOMElement $row, ?DOMElement $iconSource, string $css): string
    {
        $carried = $this->styles->cssDeclarations($css);
        unset($carried['display']);
        $isPadding = static fn (string $property): bool => 1 === preg_match('/^padding(?:-[a-z-]+)?$/', $property);
        $hasPadding = array() !== array_filter(array_keys($carried), $isPadding);
        if ( ! $hasPadding && $row instanceof DOMElement && ! $row->isSameNode($control) ) {
            foreach ( $this->styles->safeVisualDeclarations($this->styles->resolvedPresentationDeclarations($row)) as $property => $value ) {
                if ( $isPadding($property) ) {
                    $carried[$property] = $value;
                    $hasPadding = true;
                }
            }
        }
        // Core's own saved toggle (re-reading engine or editor output) already
        // has core's padding as its source padding; zeroing it would change it.
        $isCoreToggle = in_array('wp-block-accordion-heading__toggle', preg_split('/\s+/', $control->getAttribute('class')) ?: array(), true);
        $box = $hasPadding || $isCoreToggle ? array() : array('padding' => '0');
        if ( $iconSource instanceof DOMElement ) {
            $box = array_merge($box, $this->accordionIconRowLayout($iconSource));
        }

        return $this->styles->cssDeclarationString(array_merge($box, $carried));
    }

    /**
     * The flex row that holds the source label and its end icon.
     *
     * @return array<string, string>
     */
    private function accordionIconRowLayout(DOMElement $iconSource): array
    {
        $svg = $iconSource->getElementsByTagName('svg')->item(0);
        $layout = array('column-gap' => '0');
        for ( $node = $svg?->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
            $declarations = $this->styles->resolvedPresentationDeclarations($node);
            $display = strtolower(trim((string) ($declarations['display'] ?? '')));
            $direction = strtolower(trim((string) ($declarations['flex-direction'] ?? 'row')));
            if ( in_array($display, array('flex', 'inline-flex'), true) && in_array($direction, array('row', ''), true) ) {
                $gap = trim((string) ($declarations['column-gap'] ?? ''));
                if ( '' === $gap ) {
                    $parts = preg_split('/\s+/', trim((string) ($declarations['gap'] ?? ''))) ?: array();
                    $gap = (string) end($parts);
                }
                if ( '' !== $gap && 'normal' !== strtolower($gap) ) {
                    $layout['column-gap'] = $this->styles->resolveCssVariablesInValue($gap, $node);
                }
                $alignItems = trim((string) ($declarations['align-items'] ?? ''));
                if ( '' !== $alignItems ) {
                    $layout['align-items'] = $alignItems;
                }
                break;
            }
            if ( $node->isSameNode($iconSource) ) {
                break;
            }
        }

        return $this->styles->safeVisualDeclarations($layout);
    }

    /** Core owns the icon span; carry matching text-icon geometry or passive SVG artwork.
     * Expanded class/style attributes come from a verified producer drive.
     * No icon shape or vendor class name defines an expanded-state rotation.
     * @return array<string, string>
     */
    private function accordionIcon(DOMElement $control): array
    {
        $icons = $control->getElementsByTagName('svg');
        if ( 0 === $icons->length && ! in_array('wp-block-accordion-heading__toggle', preg_split('/\s+/', $control->getAttribute('class')) ?: array(), true) ) {
            // Core's text icon has a fixed 1.2em box. A source using the same
            // glyph with an intrinsic box must not acquire that minimum height.
            foreach ( $control->getElementsByTagName('span') as $span ) {
                if ( 'true' !== $span->getAttribute('aria-hidden') || '+' !== trim($span->textContent) ) continue;
                $declarations = $this->styles->safeVisualDeclarations($this->styles->resolvedPresentationDeclarations($span));
                $box = array_intersect_key($declarations, array_flip(array('width', 'height', 'min-width', 'min-height', 'font-size', 'line-height')));
                $css = $this->styles->cssDeclarationString(array_merge(array('width' => 'auto', 'height' => 'auto'), $box));
                return array('closed' => $css, 'open' => $css);
            }
        }
        if ( null === $this->svgMarkup || 1 !== $icons->length ) return array();
        $svg = $icons->item(0);
        if ( ! $svg instanceof DOMElement || ! SourceDom::svgHasDrawableContent($svg) ) return array();
        foreach (array('class', 'style') as $attribute) {
            if ($svg->hasAttribute(CaptureEvidence::DISCLOSURE_CLOSED_PREFIX . $attribute)
                && $svg->getAttribute($attribute) !== $svg->getAttribute(CaptureEvidence::DISCLOSURE_CLOSED_PREFIX . $attribute)) return array();
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
        // Producer state annotations describe the capture, not the artwork.
        foreach ( iterator_to_array($clone->attributes) as $attribute ) {
            if ( str_starts_with($attribute->nodeName, CaptureEvidence::ATTRIBUTE_PREFIX) ) $clone->removeAttribute($attribute->nodeName);
        }
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
            if ( $svg->hasAttribute(CaptureEvidence::DISCLOSURE_OPEN_PREFIX . $attribute) ) {
                $expanded->setAttribute($attribute, $svg->getAttribute(CaptureEvidence::DISCLOSURE_OPEN_PREFIX . $attribute));
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
        // The background artwork is the editor's rendering; the frontend renders
        // the actual SVG into the slot, which carries the state transform itself.
        // Rasterizing the vector before transforming it measurably changes its
        // antialiased paint, so the live vector owns the observed motion.
        return array(
            'closed' => $base . ';' . $stateCss($closed),
            'open' => $stateCss($open),
            'vector_closed' => 'display:block;width:100%;height:100%;' . $stateCss($closed),
            'vector_open' => $stateCss($open),
            'svg' => $markup,
        );
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
        // Buttons do not inherit line-height by default. Preserve their UA
        // normal line box rather than walking past the control to body type.
        // Explicit values (including inherit) still use the authored cascade.
        if ( ! $carrySummaryLayout && 'button' === strtolower($control->tagName) && ! isset($declarations['line-height'])
            && ! in_array('wp-block-accordion-heading__toggle', preg_split('/\s+/', $control->getAttribute('class')) ?: array(), true) ) {
            $carried['line-height'] = 'normal';
        }
        // The generated h3 introduces theme heading type between the control
        // and its source ancestors. Carry explicitly inherited winners across it.
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

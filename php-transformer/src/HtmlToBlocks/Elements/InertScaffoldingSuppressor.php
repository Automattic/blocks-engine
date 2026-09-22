<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMElement;

/** Omits inert capture iframe, live-region, and empty-container scaffolding before content dispatch. */
final class InertScaffoldingSuppressor implements ElementConverter
{
    /**
     * Every declaration that can make an empty box render, take space, or move
     * its siblings. A value drawn from this list at any viewport keeps the
     * element: it is a spacer, a divider, or a decorative rule, not scaffolding.
     *
     * @var array<int, string>
     */
    private const RENDERED_EMPTY_BOX_PROPERTIES = array(
        'align-self', 'animation', 'animation-name', 'aspect-ratio', 'background', 'background-color', 'background-image',
        'border', 'border-bottom', 'border-color', 'border-left', 'border-right', 'border-style', 'border-top', 'border-width',
        'bottom', 'box-shadow', 'flex', 'flex-basis', 'flex-grow', 'float', 'grid-area', 'grid-column', 'grid-row', 'height',
        'inset', 'justify-self', 'left', 'list-style', 'list-style-type', 'margin', 'margin-bottom', 'margin-left',
        'margin-right', 'margin-top', 'min-height', 'min-width', 'order', 'outline', 'outline-width', 'padding',
        'padding-bottom', 'padding-left', 'padding-right', 'padding-top', 'position', 'right', 'rotate', 'scale', 'top',
        'transform', 'transition', 'translate', 'width',
    );

    /** Values that declare a property without giving an empty box anything to render. @var array<int, string> */
    private const NEUTRAL_DECLARATION_TOKENS = array(
        '0', 'auto', 'baseline', 'flex-start', 'inherit', 'initial', 'none', 'normal', 'revert', 'revert-layer',
        'start', 'static', 'stretch', 'transparent', 'unset',
    );

    /**
     * @param Closure(DOMElement): ?DOMElement $soleElementChild
     * @param Closure(DOMElement): bool $shouldPreserveEmptyVisualElement
     */
    public function __construct(
        private readonly StyleResolver $styleResolver,
        private readonly RuntimeIslandAnalyzer $runtimeIslands,
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly Closure $soleElementChild,
        private readonly Closure $shouldPreserveEmptyVisualElement
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
    {
        if ( 'iframe' === $tagName && $this->isInertHiddenCaptureIframe($element) ) {
            return ConversionOutcome::handled(null);
        }

        if ( $this->isInertLiveRegionScaffolding($element) ) {
            return ConversionOutcome::handled(null);
        }

        if ( 'div' === $tagName && $this->isInertEmptyContainer($element) ) {
            return ConversionOutcome::handled(null);
        }

        return ConversionOutcome::unhandled();
    }

    /**
     * A `div` carries no semantics of its own, so an empty one is only worth a
     * block when something in the capture renders it or addresses it. Nothing
     * does when it has no children and no text, no author declaration gives it
     * a box, paint, motion, or a layout slot at any viewport, no pseudo-element
     * rule draws into it, nothing names it, and no runtime script targets it.
     * Such an element is capture scaffolding: it only inflates List View.
     */
    private function isInertEmptyContainer(DOMElement $element): bool
    {
        if ( 0 !== SourceDom::childElementCount($element)
            || '' !== trim($element->textContent ?? '')
            || ( $this->shouldPreserveEmptyVisualElement )($element)
            || $this->sourceElementClassifier->hasMotionStructureToken($element)
            || $this->statesRenderedEmptyBox($element)
            || $this->occupiesAuthorLayoutSlot($element) ) {
            return false;
        }

        foreach ( array( 'aria-describedby', 'aria-label', 'aria-labelledby', 'aria-live', 'name', 'title' ) as $attribute ) {
            if ( '' !== trim(SourceDom::attr($element, $attribute)) ) {
                return false;
            }
        }

        return true;
    }

    /** Does the author stylesheet ever give this empty box something to render? */
    private function statesRenderedEmptyBox(DOMElement $element): bool
    {
        foreach ( $this->styleResolver->authorDeclaredValuesAtAnyViewport($element, self::RENDERED_EMPTY_BOX_PROPERTIES) as $values ) {
            foreach ( $values as $value ) {
                if ( ! $this->isNeutralDeclarationValue($this->styleResolver->resolveCssVariablesInValue($value, $element)) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A parent's authored layout can hand an empty box a slot of its own: every
     * grid item owns a track, a gap is inserted between every pair of flex or
     * grid items, and a distributing `justify-content` shares the container's
     * free space between them. Removing such a child moves its siblings.
     */
    private function occupiesAuthorLayoutSlot(DOMElement $element): bool
    {
        $parent = $element->parentNode;
        if ( ! $parent instanceof DOMElement ) {
            return false;
        }

        $declared = $this->styleResolver->authorDeclaredValuesAtAnyViewport($parent, array(
            'aspect-ratio', 'column-gap', 'display', 'flex-direction', 'gap', 'height', 'justify-content', 'min-height', 'row-gap',
        ));
        if ( $this->declaresAny($declared, 'display', array( 'grid', 'inline-grid' )) ) {
            return true;
        }
        if ( ! $this->declaresAny($declared, 'display', array( 'flex', 'inline-flex' )) ) {
            return false;
        }
        foreach ( array( 'column-gap', 'gap', 'row-gap' ) as $property ) {
            if ( $this->declaresNonNeutral($declared, $property) ) {
                return true;
            }
        }
        if ( ! $this->declaresAny($declared, 'justify-content', array( 'space-around', 'space-between', 'space-evenly' )) ) {
            return false;
        }

        // Free space only exists to distribute once the main axis is definite.
        // A row container inherits its parent's inline size; a column container
        // hugs its content unless the author states a block size.
        return ! $this->declaresAny($declared, 'flex-direction', array( 'column', 'column-reverse' ))
            || $this->declaresNonNeutral($declared, 'aspect-ratio')
            || $this->declaresNonNeutral($declared, 'height')
            || $this->declaresNonNeutral($declared, 'min-height');
    }

    /**
     * @param array<string, list<string>> $declared
     * @param array<int, string> $values
     */
    private function declaresAny(array $declared, string $property, array $values): bool
    {
        foreach ( $declared[ $property ] ?? array() as $declaredValue ) {
            if ( in_array(CssValueInspector::comparable($declaredValue), $values, true) ) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, list<string>> $declared */
    private function declaresNonNeutral(array $declared, string $property): bool
    {
        foreach ( $declared[ $property ] ?? array() as $declaredValue ) {
            if ( ! $this->isNeutralDeclarationValue($declaredValue) ) {
                return true;
            }
        }

        return false;
    }

    private function isNeutralDeclarationValue(string $value): bool
    {
        foreach ( preg_split('#[\s,/]+#', CssValueInspector::comparable($value)) ?: array() as $token ) {
            if ( '' === $token
                || in_array($token, self::NEUTRAL_DECLARATION_TOKENS, true)
                || 1 === preg_match('/^0(?:\.0+)?(?:%|ch|cm|em|ex|in|mm|pc|pt|px|rem|vh|vmax|vmin|vw)?$/', $token) ) {
                continue;
            }

            return false;
        }

        return true;
    }

    private function isInertHiddenCaptureIframe(DOMElement $element): bool
    {
        if ( ! $this->sourceElementStartsHidden($element)
            || $this->styleResolver->hasConditionalStyleFamily($element, 'layout')
            || $this->styleResolver->hasConditionalStyleFamily($element, 'visibility')
            || $this->styleResolver->hasConditionalStyleFamily($element, 'opacity')
            || $this->runtimeIslands->isRuntimeDomTarget($element)
            || '' !== trim(SourceDom::attr($element, 'src'))
            || '' !== trim(SourceDom::attr($element, 'srcdoc'))
            || '' !== trim(SourceDom::attr($element, 'name'))
            || '' !== trim($element->textContent ?? '')
            || 0 !== SourceDom::childElementCount($element)
            || array() !== SourceDom::eventMetadata($element)
            || array() !== $this->safeDataAttributes($element) ) {
            return false;
        }

        return true;
    }

    private function isInertLiveRegionScaffolding(DOMElement $element): bool
    {
        if ( ! str_contains(strtolower($element->tagName), '-')
            || '' !== trim($element->textContent ?? '')
            || ! $this->isSafeTransparentCustomElement($element)
            || 0 !== $element->attributes->length ) {
            return false;
        }

        $liveRegion = ($this->soleElementChild)($element);
        if ( ! $liveRegion instanceof DOMElement
            || 0 !== SourceDom::childElementCount($liveRegion)
            || ! in_array(strtolower($liveRegion->tagName), array( 'div', 'p', 'span' ), true)
            || ! in_array(strtolower(trim(SourceDom::attr($liveRegion, 'role'))), array( 'alert', 'log', 'status' ), true)
            || ! in_array(strtolower(trim(SourceDom::attr($liveRegion, 'aria-live'))), array( 'assertive', 'polite' ), true)
            || ! $this->isVisuallyClippedLiveRegion($liveRegion)
            || array() !== $this->safeDataAttributes($liveRegion) ) {
            return false;
        }

        $allowedAttributes = array( 'aria-atomic', 'aria-live', 'class', 'id', 'role', 'style' );
        foreach ( $liveRegion->attributes as $attribute ) {
            if ( ! in_array(strtolower($attribute->name), $allowedAttributes, true) ) {
                return false;
            }
        }

        return true;
    }

    private function isVisuallyClippedLiveRegion(DOMElement $element): bool
    {
        return CssValueInspector::isVisuallyClippedBox($this->styleResolver->structuralPresentationDeclarations($element));
    }

    private function sourceElementStartsHidden(DOMElement $element): bool
    {
        $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
        $display = CssValueInspector::comparable((string) ($declarations['display'] ?? ''));
        $visibility = CssValueInspector::comparable((string) ($declarations['visibility'] ?? ''));
        $opacity = CssValueInspector::comparable((string) ($declarations['opacity'] ?? ''));
        return 'none' === $display
            || in_array($visibility, array( 'hidden', 'collapse' ), true)
            || (is_numeric($opacity) && 0.0 === (float) $opacity);
    }

    private function isSafeTransparentCustomElement(DOMElement $element): bool
    {
        foreach ( array_merge(array( $element ), iterator_to_array($element->getElementsByTagName('*'))) as $candidate ) {
            if ( ! $candidate instanceof DOMElement ) {
                continue;
            }

            if ( $this->runtimeIslands->isRuntimeDomTarget($candidate)
                || array() !== SourceDom::eventMetadata($candidate)
                || $this->sourceElementClassifier->hasMotionStructureToken($candidate)
            ) {
                return false;
            }

            foreach ( $candidate->attributes as $attribute ) {
                if ( str_starts_with(strtolower($attribute->name), 'data-wp-') ) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return array<string, string> */
    private function safeDataAttributes(DOMElement $element): array
    {
        $data = array();
        foreach ( SourceDom::htmlAttributes($element) as $name => $value ) {
            if ( preg_match('/^data-[a-z0-9_-]+$/i', $name) && strlen($value) <= 300 && ! preg_match('/javascript\s*:/i', $value) ) {
                $data[$name] = $value;
            }
        }

        return $data;
    }
}

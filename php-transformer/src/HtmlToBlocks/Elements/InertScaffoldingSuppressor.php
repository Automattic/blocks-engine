<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMElement;

/** Omits inert capture iframe and live-region scaffolding before content dispatch. */
final class InertScaffoldingSuppressor implements ElementConverter
{
    /**
     * @param Closure(DOMElement): ?DOMElement $soleElementChild
     */
    public function __construct(
        private readonly StyleResolver $styleResolver,
        private readonly RuntimeIslandAnalyzer $runtimeIslands,
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly Closure $soleElementChild
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

        return ConversionOutcome::unhandled();
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
        $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
        $width = trim((string) ($declarations['width'] ?? ''));
        $height = trim((string) ($declarations['height'] ?? ''));
        $clip = strtolower(trim((string) ($declarations['clip'] ?? '')));
        $clipPath = strtolower(trim((string) ($declarations['clip-path'] ?? '')));

        return 'absolute' === strtolower(trim((string) ($declarations['position'] ?? '')))
            && 'hidden' === strtolower(trim((string) ($declarations['overflow'] ?? '')))
            && $this->isAtMostOnePixelLength($width)
            && $this->isAtMostOnePixelLength($height)
            && (str_starts_with($clip, 'rect(') || str_starts_with($clipPath, 'inset('));
    }

    private function isAtMostOnePixelLength(string $value): bool
    {
        return 1 === preg_match('/^(?:0|1)px$/i', $value);
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

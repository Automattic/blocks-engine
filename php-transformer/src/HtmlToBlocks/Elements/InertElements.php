<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceElementVisibility;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMDocument;
use DOMElement;

/**
 * Recognizes source elements that are inert on the page: runtime media
 * placeholders that never paint, and hidden SVG sprite stores that only
 * other SVG content consumes.
 */
final class InertElements implements ElementConverter
{
    public function __construct(
        private readonly StyleResolver $styleResolver,
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly SourceElementVisibility $sourceVisibility,
        private readonly RuntimeIslandAnalyzer $runtimeIslands
    ) {
    }

    /** Omits an inert runtime media placeholder: it renders nothing until a script fills it. */
    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        return $this->isInertRuntimeMediaPlaceholder($element) ? ConversionOutcome::handled(null) : ConversionOutcome::unhandled();
    }

    public function isInertRuntimeMediaPlaceholder(DOMElement $element): bool
    {
        if ( ! $this->sourceElementClassifier->isRuntimeMediaSurfaceElement($element)
            || ! str_contains(strtolower($element->tagName), '-')
            || '' !== trim($element->textContent ?? '')
        ) {
            return false;
        }

        foreach ( array_merge(array( $element ), iterator_to_array($element->getElementsByTagName('*'))) as $candidate ) {
            if ( ! $candidate instanceof DOMElement
                || ! $this->runtimeIslands->isSafeTransparentCustomElement($candidate)
                || '' !== trim(SourceDom::attr($candidate, 'role'))
                || '' !== trim(SourceDom::attr($candidate, 'aria-label'))
            ) {
                return false;
            }

            foreach ( array( 'src', 'srcset', 'href', 'data-src', 'data-url' ) as $attribute ) {
                if ( '' !== trim(SourceDom::attr($candidate, $attribute)) ) {
                    return false;
                }
            }

            foreach ( $candidate->attributes as $attribute ) {
                if ( str_starts_with(strtolower($attribute->name), 'data-') && '' !== trim($attribute->value) ) {
                    return false;
                }
            }

            if ( $candidate !== $element && ! $this->sourceElementClassifier->isStructuralTransparentCustomWrapperChild($candidate) ) {
                return false;
            }
        }

        return true;
    }

    public function isInertHiddenSvgStorage(DOMElement $element): bool
    {
        $isDomStore = $element->hasAttribute('data-dom-store');
        if ( ! $this->sourceVisibility->startsHidden($element)
            || (! $isDomStore && (
                $this->styleResolver->hasConditionalStyleFamily($element, 'layout')
                || $this->styleResolver->hasConditionalStyleFamily($element, 'visibility')
                || $this->styleResolver->hasConditionalStyleFamily($element, 'opacity')
            ))
            || '' !== trim(SourceDom::attr($element, 'aria-label'))
            || '' !== trim(SourceDom::attr($element, 'aria-labelledby'))
            || '' !== trim(SourceDom::attr($element, 'title'))
            || ! $this->sourceElementClassifier->hasOnlySvgDefinitions($element)
            || $this->hiddenSvgStoreHasNonSvgConsumer($element) ) {
            return false;
        }

        return true;
    }

    private function hiddenSvgStoreHasNonSvgConsumer(DOMElement $store): bool
    {
        $ids = array();
        foreach ( $store->getElementsByTagName('*') as $definition ) {
            if ( $definition instanceof DOMElement && '' !== trim(SourceDom::attr($definition, 'id')) ) {
                $ids[] = trim(SourceDom::attr($definition, 'id'));
            }
        }
        if ( array() === $ids || ! $store->ownerDocument instanceof DOMDocument ) {
            return false;
        }

        foreach ( $store->ownerDocument->getElementsByTagName('*') as $candidate ) {
            if ( ! $candidate instanceof DOMElement || $this->sourceElementClassifier->isDescendantOf($candidate, $store) ) {
                continue;
            }
            foreach ( $candidate->attributes as $attribute ) {
                foreach ( $ids as $id ) {
                    if ( preg_match('/(?:^|[\\s,(])(?:url\\(\\s*["\']?#' . preg_quote($id, '/') . '["\']?\\s*\\)|#' . preg_quote($id, '/') . '(?:$|[\\s,)]))/', $attribute->value)
                        && 'svg' !== strtolower($candidate->tagName)
                        && null === SourceDom::ancestorElement($candidate, 'svg') ) {
                        return true;
                    }
                }
            }
            if ( 'style' === strtolower($candidate->tagName) ) {
                foreach ( $ids as $id ) {
                    if ( preg_match('/url\\(\\s*["\']?#' . preg_quote($id, '/') . '["\']?\\s*\\)/', $candidate->textContent ?? '') ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}

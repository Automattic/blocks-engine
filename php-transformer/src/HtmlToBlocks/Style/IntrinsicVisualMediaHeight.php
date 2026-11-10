<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

final class IntrinsicVisualMediaHeight
{
    public function __construct(private readonly StyleResolver $styleResolver, private readonly SourceStyleResolutionState $sourceStyleResolutionState)
    {
    }

    public function apply(DOMElement $element, array $attrs, bool $unsafeFixedHeightTopologyChange = false): array
    {
        $geometry = array();
        $presentation = $this->styleResolver->presentationDeclarations($element);
        $inline = $this->styleResolver->inlineDeclarations($element);
        foreach ( array( 'height', 'min-height' ) as $property ) {
            if ( 'height' === $property && $unsafeFixedHeightTopologyChange ) {
                continue;
            }
            $family = $this->styleResolver->responsivePropertyFamily($property);
            if ( array() !== $this->sourceStyleResolutionState->conditionalRules()
                && $this->styleResolver->hasConditionalStyleFamily($element, $family)
                && ! $this->styleResolver->inlineOwnsResponsiveProperty($property, $family, $inline)
            ) {
                continue;
            }
            $value = trim(CssValueInspector::withoutImportant((string) ($presentation[ $property ] ?? '')));
            if ( preg_match('/^(?:\d+|\d*\.\d+)(?:px)?$/', $value) && 0.0 < (float) $value ) {
                $geometry[ $property ] = str_ends_with($value, 'px') ? $value : $value . 'px';
            }
        }
        $height = $this->intrinsicVisualMediaHeight($element);
        if ( '' !== $height && ! isset($geometry['height']) && ! isset($geometry['min-height']) ) {
            $geometry['min-height'] = $height;
        }
        if ( array() === $geometry ) {
            return $attrs;
        }

        $carrier = $this->styleResolver->inlineGeometryClassName(
            $element,
            array(),
            array_keys($geometry),
            $geometry
        );
        if ( '' !== $carrier ) {
            $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $carrier);
        }
        return $attrs;
    }

    private function intrinsicVisualMediaHeight(DOMElement $element): string
    {
        $parent = $element->parentNode;
        if ( $parent instanceof DOMElement ) {
            $parentPosition = strtolower(trim((string) ($this->styleResolver->structuralPresentationDeclarations($parent)['position'] ?? '')));
            if ( in_array($parentPosition, array( 'absolute', 'fixed' ), true) ) {
                return '';
            }
        }
        $own = $this->styleResolver->presentationDeclarations($element);
        foreach ( array( 'height', 'min-height' ) as $property ) {
            $value = trim(CssValueInspector::withoutImportant((string) ($own[ $property ] ?? '')));
            if ( preg_match('/^(?:\d+|\d*\.\d+)(?:px)?$/', $value) && 0.0 < (float) $value ) {
                return '';
            }
        }
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            if ( $this->hasInFlowContent($child) || $this->inFlowDescendantHasDefiniteBlockSize($child) ) {
                return '';
            }
        }

        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            $position = strtolower(trim((string) ($this->styleResolver->structuralPresentationDeclarations($child)['position'] ?? '')));
            if ( ! in_array($position, array( 'absolute', 'fixed' ), true) ) {
                continue;
            }
            foreach ( $child->getElementsByTagName('img') as $image ) {
                if ( ! $image instanceof DOMElement ) {
                    continue;
                }
                for ( $carrier = $image->parentNode; $carrier instanceof DOMElement && $carrier !== $child; $carrier = $carrier->parentNode ) {
                    $carrierPosition = strtolower(trim((string) ($this->styleResolver->structuralPresentationDeclarations($carrier)['position'] ?? '')));
                    if ( 'sticky' === $carrierPosition ) {
                        continue 2;
                    }
                }
                $imageDeclarations = $this->styleResolver->structuralPresentationDeclarations($image);
                $objectFit = strtolower(trim(CssValueInspector::withoutImportant((string) ($imageDeclarations['object-fit'] ?? ''))));
                if ( in_array($objectFit, array( 'cover', 'contain' ), true) ) {
                    continue;
                }
                $height = trim(CssValueInspector::withoutImportant((string) ($imageDeclarations['height'] ?? '')));
                if ( preg_match('/^(?:\d+|\d*\.\d+)$/', $height) ) {
                    $height .= 'px';
                }
                if ( preg_match('/^(?:\d+|\d*\.\d+)px$/', $height) && 0.0 < (float) $height ) {
                    return $height;
                }
            }
        }

        return '';
    }

    private function inFlowDescendantHasDefiniteBlockSize(DOMElement $element): bool
    {
        $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
        $position = strtolower(trim((string) ($declarations['position'] ?? '')));
        if ( in_array($position, array( 'absolute', 'fixed' ), true) ) {
            return false;
        }
        foreach ( array( 'height', 'min-height' ) as $property ) {
            $value = trim(CssValueInspector::withoutImportant((string) ($declarations[ $property ] ?? '')));
            if ( preg_match('/^(?:\d+|\d*\.\d+)(?:px)?$/', $value) && 0.0 < (float) $value ) {
                return true;
            }
        }
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && $this->inFlowDescendantHasDefiniteBlockSize($child) ) {
                return true;
            }
        }

        return false;
    }

    private function hasInFlowContent(DOMElement $element): bool
    {
        $position = strtolower(trim((string) ($this->styleResolver->structuralPresentationDeclarations($element)['position'] ?? '')));
        if ( in_array($position, array( 'absolute', 'fixed' ), true) ) {
            return false;
        }
        if ( in_array(strtolower($element->tagName), array( 'img', 'picture', 'svg', 'video', 'audio', 'iframe', 'canvas' ), true) ) {
            return true;
        }
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                if ( $this->hasInFlowContent($child) ) {
                    return true;
                }
            } elseif ( '' !== trim($child->textContent ?? '') ) {
                return true;
            }
        }
        return false;
    }
}

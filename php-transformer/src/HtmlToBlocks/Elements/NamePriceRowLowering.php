<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\SourceElementDescription;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use DOMElement;

/**
 * Lowers a name/price or label/value row into core/columns when its source
 * geometry is an equal-width, non-wrapping flex row.
 */
final class NamePriceRowLowering
{
    public function __construct(
        private readonly SourceBlockCreator $blocks,
        private readonly PatternTreeConverter $tree,
        private readonly StyleResolver $styleResolver,
        private readonly SourceElementDescription $sourceElementDescription
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    public function namePriceRowBlockFromElement(DOMElement $element, array &$fallbacks): ?array
    {
        $children = $this->sourceElementDescription->namePriceChildren($element);
        if ( null === $children || ! $this->hasEqualWidthFlexColumnsGeometry($element, $children) ) {
            return null;
        }

        // core/columns is a flex layout; WordPress rejects it with is-layout-grid.
        // Decline when the resolved layout is grid so the container demotes to
        // core/group, where grid layout is native.
        $layout = $this->styleResolver->presentationAttributes($element)['layout'] ?? null;
        if ( is_array($layout) && 'grid' === (string) ($layout['type'] ?? '') ) {
            return null;
        }

        $rowFallbacks = array();
        $columns = array();
        foreach ( $children as $child ) {
            $converted = array_filter(array( $this->tree->element($child, $rowFallbacks, true) ));
            if ( array() === $converted ) {
                return null;
            }

            $columns[] = $this->blocks->createBlock('core/column', $this->styleResolver->presentationAttributes($child), $converted, $child);
        }
        array_push($fallbacks, ...$rowFallbacks);

        return $this->blocks->createBlock('core/columns', $this->styleResolver->presentationAttributes($element), $columns, $element);
    }

    /**
     * core/columns gives each child an equal share of its row. Name/price and
     * label/value semantics alone say nothing about that geometry: ordinary
     * block flow is stacked, and flex items retain their content-sized basis.
     * Restrict this decomposition to the source layout signal that core/columns
     * can reproduce: a horizontal, non-wrapping flex row with equal zero-basis
     * flex items.
     *
     * @param array<int, DOMElement> $children
     */
    private function hasEqualWidthFlexColumnsGeometry(DOMElement $element, array $children): bool
    {
        $container = $this->styleResolver->structuralPresentationDeclarations($element);
        if ( 'flex' !== CssValueInspector::declared($container, 'display') ) {
            return false;
        }

        $direction = strtolower(trim((string) ($container['flex-direction'] ?? 'row')));
        $wrap = strtolower(trim((string) ($container['flex-wrap'] ?? 'nowrap')));
        if ( ! in_array($direction, array( 'row', 'row-reverse' ), true) || 'nowrap' !== $wrap ) {
            return false;
        }

        $flex = null;
        foreach ( $children as $child ) {
            $childFlex = $this->equalWidthFlexSignal($this->styleResolver->structuralPresentationDeclarations($child));
            if ( null === $childFlex || ( null !== $flex && $flex !== $childFlex ) ) {
                return false;
            }
            $flex = $childFlex;
        }

        return null !== $flex;
    }

    /**
     * @param array<string, string> $declarations
     */
    private function equalWidthFlexSignal(array $declarations): ?string
    {
        $flex = preg_replace('/\s+/', ' ', strtolower(trim((string) ($declarations['flex'] ?? '')))) ?? '';
        if ( preg_match('/^([1-9][0-9]*(?:\.[0-9]+)?)(?: [0-9]+(?:\.[0-9]+)? (?:0|0%|0px))?$/', $flex, $matches) ) {
            return $matches[1];
        }

        $grow = trim((string) ($declarations['flex-grow'] ?? ''));
        $basis = strtolower(trim((string) ($declarations['flex-basis'] ?? '')));
        if ( is_numeric($grow) && 0 < (float) $grow && in_array($basis, array( '0', '0%', '0px' ), true) ) {
            return $grow;
        }

        return null;
    }
}

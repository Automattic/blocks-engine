<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Builds responsive media-query overrides from planned breakpoint variants.
 */
final class BreakpointMediaDiffBuilder
{
    /**
     * @param callable(array<string, mixed>): array<int, mixed> $nodeList
     * @param callable(array<string, mixed>, string, array<string, mixed>|null, array<string, mixed>|null): array<int, string> $styleDeclarations
     * @param callable(array<string, mixed>, string, array<string, mixed>|null): mixed $supportedVectorSvg
     * @param callable(array<string, mixed>, array<string, mixed>): bool $isFullyClippedDecorativeChild
     * @param callable(array<string, mixed>): bool $isPaginationContainer
     * @param callable(string): string $sanitizeAttribute
     * @param callable(string): string $slug
     * @param callable(float): string $number
     */
    public function __construct(
        private readonly StickyLayoutCoordinator $stickyLayoutCoordinator,
        private readonly mixed $nodeList,
        private readonly mixed $styleDeclarations,
        private readonly mixed $supportedVectorSvg,
        private readonly mixed $isFullyClippedDecorativeChild,
        private readonly mixed $isPaginationContainer,
        private readonly mixed $sanitizeAttribute,
        private readonly mixed $slug,
        private readonly mixed $number,
    ) {
    }

    /**
     * @param array<string, mixed>                $page
     * @param array<string, mixed>                $baseNode
     * @param array<string, array<string, mixed>> $nodeMap
     * @return array<int, string>
     */
    public function buildMediaBlocks(array $page, array $baseNode, array $nodeMap): array
    {
        $variants = is_array($page['variants'] ?? null) ? array_values($page['variants']) : array();
        if ( count($variants) < 2 ) {
            return array();
        }

        $baseStyles = array();
        $this->collectVariantNodeStyles($baseNode, 0, null, null, 'r', $baseStyles);

        $primaryViewportWidth = null;
        foreach ( $variants as $variant ) {
            if ( is_array($variant) && true === ($variant['primary'] ?? false) && is_numeric($variant['viewport_width'] ?? null) ) {
                $primaryViewportWidth = (float) $variant['viewport_width'];
                break;
            }
        }

        $blocks = array();
        $prevViewportWidth = $primaryViewportWidth;
        foreach ( $variants as $variant ) {
            if ( ! is_array($variant) || true === ($variant['primary'] ?? false) ) {
                continue;
            }

            $variantId = isset($variant['frame_id']) && is_scalar($variant['frame_id']) ? (string) $variant['frame_id'] : '';
            $viewportWidth = $variant['viewport_width'] ?? null;
            if ( '' === $variantId || ! isset($nodeMap[$variantId]) || ! is_numeric($viewportWidth) ) {
                continue;
            }

            $variantStyles = array();
            $this->collectVariantNodeStyles($nodeMap[$variantId], 0, null, null, 'r', $variantStyles);

            $rules = $this->diffRules($baseStyles, $variantStyles);
            if ( empty($rules) ) {
                $prevViewportWidth = (float) $viewportWidth;
                continue;
            }

            $breakpointPx = null !== $prevViewportWidth && $prevViewportWidth > (float) $viewportWidth
                ? (int) round(($prevViewportWidth + (float) $viewportWidth) / 2)
                : (int) round((float) $viewportWidth);

            $blocks[] = '@media (max-width:' . ($this->number)((float) $breakpointPx) . 'px){'
                . "\n" . implode("\n", $rules) . "\n}";

            $prevViewportWidth = (float) $viewportWidth;
        }

        return $blocks;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $map
     */
    private function collectVariantNodeStyles(array $node, int $depth, ?array $parentNode, ?array $grandParentNode, string $pathKey, array &$map): void
    {
        if ( $this->stickyLayoutCoordinator->isSuppressedStickyGhost($node) ) {
            return;
        }

        $id = ($this->sanitizeAttribute)((string) ($node['id'] ?? ''));
        $name = (string) ($node['name'] ?? '');
        $type = strtoupper((string) ($node['type'] ?? 'FRAME'));
        $className = 'figma-node-' . ($this->slug)($id . '-' . $name);
        $styles = $this->stickyLayoutCoordinator->stickyAwareStyleDeclarations($node, ($this->styleDeclarations)($node, $type, $parentNode, $grandParentNode));

        $map[$pathKey] = array(
            'class'           => $className,
            'styles'          => $styles,
            'contains_sticky' => $this->stickyLayoutCoordinator->containsStickyPrimary($node),
            'node'            => $node,
            'parent_node'     => $parentNode,
        );

        $vectorSvg = ($this->supportedVectorSvg)($node, $type, $parentNode);
        if ( 'BOOLEAN_OPERATION' === $type && null !== $vectorSvg ) {
            return;
        }

        $childOrdinal = 0;
        foreach ( ($this->nodeList)($node) as $child ) {
            if ( ! is_array($child) || $this->stickyLayoutCoordinator->isSuppressedStickyGhost($child) || ($this->isFullyClippedDecorativeChild)($child, $node) ) {
                continue;
            }

            $childKey = $pathKey . '/' . $this->breakpointChildKey($child, $childOrdinal);
            $this->collectVariantNodeStyles($child, $depth + 1, $node, $parentNode, $childKey, $map);
            ++$childOrdinal;
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private function breakpointChildKey(array $node, int $ordinal): string
    {
        $type = strtoupper((string) ($node['type'] ?? 'FRAME'));
        foreach ( array('figma_component_source_id', 'source_id') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) && '' !== (string) $node[$key] ) {
                return 'source:' . ($this->slug)($type . '-' . (string) $node[$key]);
            }
        }

        return $ordinal . ':' . $type;
    }

    /**
     * @param array<string, array<string, mixed>> $baseStyles
     * @param array<string, array<string, mixed>> $variantStyles
     * @return array<int, string>
     */
    private function diffRules(array $baseStyles, array $variantStyles): array
    {
        $rules = array();
        foreach ( $baseStyles as $pathKey => $base ) {
            if ( ! isset($variantStyles[$pathKey]) ) {
                continue;
            }

            $baseMap = $this->styleDeclarationMap(is_array($base['styles'] ?? null) ? $base['styles'] : array());
            $variantDeclarations = is_array($variantStyles[$pathKey]['styles'] ?? null) ? $variantStyles[$pathKey]['styles'] : array();

            $changed = array();
            $baseContainsSticky = true === ($base['contains_sticky'] ?? false);
            $baseNode = is_array($base['node'] ?? null) ? $base['node'] : array();
            $variantNode = is_array($variantStyles[$pathKey]['node'] ?? null) ? $variantStyles[$pathKey]['node'] : array();
            $baseParentNode = is_array($base['parent_node'] ?? null) ? $base['parent_node'] : null;
            $variantParentNode = is_array($variantStyles[$pathKey]['parent_node'] ?? null) ? $variantStyles[$pathKey]['parent_node'] : null;
            $preservePaginationRow = ! empty($baseNode) && ($this->isPaginationContainer)($baseNode);
            foreach ( $variantDeclarations as $declaration ) {
                $parts = explode(':', (string) $declaration, 2);
                if ( 2 !== count($parts) ) {
                    continue;
                }

                $property = trim($parts[0]);
                $value = trim($parts[1]);
                if ( $baseContainsSticky && 'overflow' === $property ) {
                    continue;
                }
                if ( $preservePaginationRow && in_array($property, array('height', 'flex-wrap', 'align-content'), true) ) {
                    continue;
                }
                if ( 'height' === $property && $this->shouldUseResponsiveAutoHeight($value, $baseMap, $baseNode, $variantNode) ) {
                    if ( ! array_key_exists('height', $baseMap) || 'auto' !== $baseMap['height'] ) {
                        $changed[] = 'height:auto';
                    }
                    continue;
                }
                $responsiveWidthDeclarations = 'width' === $property
                    ? $this->responsiveBreakpointWidthDeclarations($value, $baseMap, $baseNode, $variantNode, $baseParentNode, $variantParentNode)
                    : null;
                if ( null !== $responsiveWidthDeclarations ) {
                    foreach ( $responsiveWidthDeclarations as $responsiveWidthDeclaration ) {
                        $responsiveParts = explode(':', $responsiveWidthDeclaration, 2);
                        if ( 2 !== count($responsiveParts) ) {
                            continue;
                        }
                        $responsiveProperty = trim($responsiveParts[0]);
                        $responsiveValue = trim($responsiveParts[1]);
                        if ( ! array_key_exists($responsiveProperty, $baseMap) || $baseMap[$responsiveProperty] !== $responsiveValue ) {
                            $changed[] = $responsiveProperty . ':' . $responsiveValue;
                        }
                    }
                    continue;
                }
                if ( ! array_key_exists($property, $baseMap) || $baseMap[$property] !== $value ) {
                    $changed[] = $property . ':' . $value;
                }
            }

            if ( empty($changed) ) {
                continue;
            }

            $rules[] = '.' . (string) $base['class'] . '{' . implode(';', $changed) . '}';
        }

        return $rules;
    }

    /**
     * @param array<string, string> $baseMap
     * @param array<string, mixed> $baseNode
     * @param array<string, mixed> $variantNode
     * @param array<string, mixed>|null $baseParentNode
     * @param array<string, mixed>|null $variantParentNode
     * @return array<int, string>|null
     */
    private function responsiveBreakpointWidthDeclarations(string $value, array $baseMap, array $baseNode, array $variantNode, ?array $baseParentNode, ?array $variantParentNode): ?array
    {
        $variantWidth = $this->cssPixelValue($value);
        if ( null === $variantWidth || empty($variantNode) ) {
            return null;
        }

        foreach ( array('figma_component_source_id', 'source_id', 'componentId', 'component_id') as $identityKey ) {
            if ( isset($variantNode[$identityKey]) && is_scalar($variantNode[$identityKey]) && '' !== (string) $variantNode[$identityKey] ) {
                return null;
            }
        }

        if ( null === $variantParentNode ) {
            return array('width:100%');
        }

        $variantParentBox = is_array($variantParentNode['box'] ?? null) ? $variantParentNode['box'] : array();
        if ( ! isset($variantParentBox['width']) || ! is_numeric($variantParentBox['width']) ) {
            return null;
        }

        $variantParentWidth = (float) $variantParentBox['width'];
        if ( $variantParentWidth <= 0.0 || $variantWidth > $variantParentWidth + 1.0 ) {
            return null;
        }

        $variantParentLayout = is_array($variantParentNode['layout'] ?? null) ? $variantParentNode['layout'] : array();
        $padding = is_array($variantParentLayout['padding'] ?? null) ? $variantParentLayout['padding'] : array();
        $paddingLeft = isset($padding['left']) && is_numeric($padding['left']) ? (float) $padding['left'] : 0.0;
        $paddingRight = isset($padding['right']) && is_numeric($padding['right']) ? (float) $padding['right'] : 0.0;
        $contentWidth = max(0.0, $variantParentWidth - $paddingLeft - $paddingRight);
        if ( abs($variantWidth - $variantParentWidth) <= 1.0 || abs($variantWidth - $contentWidth) <= 1.0 ) {
            return array('width:100%');
        }

        $gutter = ($variantParentWidth - $variantWidth) / 2.0;
        if ( $gutter <= 0.0 ) {
            return null;
        }

        $baseWidth = $this->nodeBoxWidth($baseNode);
        $baseParentWidth = null === $baseParentNode ? null : $this->nodeBoxWidth($baseParentNode);
        if ( null === $baseWidth || null === $baseParentWidth || $baseWidth > $baseParentWidth + 1.0 ) {
            return null;
        }

        return array(
            'width:calc(100% - ' . ($this->number)($gutter * 2.0) . 'px)',
            'max-width:' . ($this->number)($baseWidth) . 'px',
        );
    }

    private function cssPixelValue(string $value): ?float
    {
        if ( 1 !== preg_match('/^(-?\d+(?:\.\d+)?)px$/', trim($value), $matches) ) {
            return null;
        }

        return (float) $matches[1];
    }

    /**
     * @param array<string, string> $baseMap
     * @param array<string, mixed> $baseNode
     * @param array<string, mixed> $variantNode
     */
    private function shouldUseResponsiveAutoHeight(string $value, array $baseMap, array $baseNode, array $variantNode): bool
    {
        if ( null === $this->cssPixelValue($value) || null === $this->cssPixelValue($baseMap['height'] ?? '') ) {
            return false;
        }

        if ( empty($baseNode) || empty($variantNode) ) {
            return false;
        }

        $type = strtoupper((string) ($baseNode['type'] ?? ''));
        if ( in_array($type, array('TEXT', 'VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'ELLIPSE', 'STAR', 'POLYGON', 'REGULAR_POLYGON', 'RECTANGLE', 'ROUNDED_RECTANGLE'), true) ) {
            return false;
        }

        $layout = is_array($baseNode['layout'] ?? null) ? $baseNode['layout'] : array();
        if ( 'absolute' === ($layout['positioning'] ?? null) || 'absolute' === ($baseMap['position'] ?? null) ) {
            return false;
        }

        $display = (string) ($layout['display'] ?? '');
        if ( ! in_array($display, array('flex', 'inline-flex', 'grid', 'inline-grid'), true) ) {
            return false;
        }

        if ( ($this->hasStableComponentIdentity($baseNode) || $this->hasStableComponentIdentity($variantNode)) && ! $this->variantFlowTopologyChanged($baseNode, $variantNode) ) {
            return false;
        }

        return ! empty(($this->nodeList)($baseNode)) && ! empty(($this->nodeList)($variantNode));
    }

    /**
     * @param array<string, mixed> $baseNode
     * @param array<string, mixed> $variantNode
     */
    private function variantFlowTopologyChanged(array $baseNode, array $variantNode): bool
    {
        $baseLayout = is_array($baseNode['layout'] ?? null) ? $baseNode['layout'] : array();
        $variantLayout = is_array($variantNode['layout'] ?? null) ? $variantNode['layout'] : array();
        foreach ( array('display', 'flex_direction', 'flex_wrap', 'grid_template_columns', 'grid_template_rows') as $layoutKey ) {
            if ( ($baseLayout[$layoutKey] ?? null) !== ($variantLayout[$layoutKey] ?? null) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasStableComponentIdentity(array $node): bool
    {
        foreach ( array('figma_component_source_id', 'source_id', 'componentId', 'component_id') as $identityKey ) {
            if ( isset($node[$identityKey]) && is_scalar($node[$identityKey]) && '' !== (string) $node[$identityKey] ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeBoxWidth(array $node): ?float
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        if ( ! isset($box['width']) || ! is_numeric($box['width']) ) {
            return null;
        }

        return (float) $box['width'];
    }

    /**
     * @param array<int, string> $styles
     * @return array<string, string>
     */
    private function styleDeclarationMap(array $styles): array
    {
        $map = array();
        foreach ( $styles as $style ) {
            $parts = explode(':', $style, 2);
            if ( 2 !== count($parts) ) {
                continue;
            }
            $map[trim($parts[0])] = trim($parts[1]);
        }

        return $map;
    }
}

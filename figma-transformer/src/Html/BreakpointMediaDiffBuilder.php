<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Builds responsive media-query overrides from planned breakpoint variants.
 */
final class BreakpointMediaDiffBuilder
{
    private readonly ResponsiveNodeMatcher $responsiveNodeMatcher;
    private readonly BreakpointDimensionPolicy $breakpointDimensionPolicy;
    private readonly LayoutIntentClassifier $layoutIntentClassifier;
    private readonly ResponsiveBreakpointSafetyPolicy $responsiveBreakpointSafetyPolicy;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $decisionTraces = array();

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
        ?ResponsiveNodeMatcher $responsiveNodeMatcher = null,
        ?BreakpointDimensionPolicy $breakpointDimensionPolicy = null,
        ?LayoutIntentClassifier $layoutIntentClassifier = null,
        ?ResponsiveBreakpointSafetyPolicy $responsiveBreakpointSafetyPolicy = null,
    ) {
        $this->responsiveNodeMatcher = $responsiveNodeMatcher ?? new ResponsiveNodeMatcher($this->slug);
        $this->breakpointDimensionPolicy = $breakpointDimensionPolicy ?? new BreakpointDimensionPolicy($this->number);
        $this->layoutIntentClassifier = $layoutIntentClassifier ?? new LayoutIntentClassifier();
        $this->responsiveBreakpointSafetyPolicy = $responsiveBreakpointSafetyPolicy ?? new ResponsiveBreakpointSafetyPolicy(
            $this->nodeList,
            $this->number,
            $this->breakpointDimensionPolicy,
            $this->layoutIntentClassifier
        );
    }

    public function resetDecisionTraces(): void
    {
        $this->decisionTraces = array();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function decisionTraces(): array
    {
        return array_values($this->decisionTraces);
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

            $breakpointPx = null !== $prevViewportWidth && $prevViewportWidth > (float) $viewportWidth
                ? (int) round(($prevViewportWidth + (float) $viewportWidth) / 2)
                : (int) round((float) $viewportWidth);

            $diffRules = $this->diffRules($baseStyles, $variantStyles);
            if ( ! empty($diffRules) ) {
                $blocks[] = $this->mediaBlock($breakpointPx, $diffRules);
            }

            $safetyRules = $this->responsiveSafetyRules($baseStyles, $variantStyles, (float) $viewportWidth, $this->matchedBreakpointGeometryClasses($baseStyles, $variantStyles));
            if ( ! empty($safetyRules) ) {
                $safetyBreakpointPx = (float) $viewportWidth <= 480.0 ? (int) round((float) $viewportWidth) : $breakpointPx;
                $blocks[] = $this->mediaBlock($safetyBreakpointPx, $safetyRules);
            }

            $prevViewportWidth = (float) $viewportWidth;
        }

        return $blocks;
    }

    /**
     * @param array<int, string> $rules
     */
    private function mediaBlock(int $breakpointPx, array $rules): string
    {
        return '@media (max-width:' . ($this->number)((float) $breakpointPx) . 'px){'
            . "\n" . implode("\n", $rules) . "\n}";
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
            'grand_parent_node' => $grandParentNode,
            'depth'           => $depth,
            'path_key'        => $pathKey,
        );

        $vectorSvg = ($this->supportedVectorSvg)($node, $type, $parentNode);
        if ( 'BOOLEAN_OPERATION' === $type && null !== $vectorSvg ) {
            return;
        }

        $children = array();
        foreach ( ($this->nodeList)($node) as $child ) {
            if ( ! is_array($child) || $this->stickyLayoutCoordinator->isSuppressedStickyGhost($child) || ($this->isFullyClippedDecorativeChild)($child, $node) ) {
                continue;
            }

            $children[] = $child;
        }

        $childOrdinal = 0;
        $siblingSignatureCounts = $this->responsiveNodeMatcher->siblingSignatureCounts($children);
        $siblingSourceIdentityCounts = $this->responsiveNodeMatcher->siblingSourceIdentityCounts($children);
        foreach ( $children as $child ) {
            foreach ( $this->responsiveNodeMatcher->childKeys($child, $childOrdinal, $siblingSignatureCounts, $siblingSourceIdentityCounts) as $childKeyPart ) {
                $childKey = $pathKey . '/' . $childKeyPart;
                $this->collectVariantNodeStyles($child, $depth + 1, $node, $parentNode, $childKey, $map);
            }
            ++$childOrdinal;
        }
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
            $preserveFullBleedBreakout = $this->usesFullBleedViewportBreakout($baseMap);
            $baseNode = is_array($base['node'] ?? null) ? $base['node'] : array();
            $variantNode = is_array($variantStyles[$pathKey]['node'] ?? null) ? $variantStyles[$pathKey]['node'] : array();
            $baseParentNode = is_array($base['parent_node'] ?? null) ? $base['parent_node'] : null;
            $variantParentNode = is_array($variantStyles[$pathKey]['parent_node'] ?? null) ? $variantStyles[$pathKey]['parent_node'] : null;
            $preservePaginationRow = ! empty($baseNode) && ($this->isPaginationContainer)($baseNode);
            $responsiveWidthHandledProperties = array();
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
                if ( $preserveFullBleedBreakout && in_array($property, array('width', 'left', 'right', 'margin-left'), true) ) {
                    continue;
                }
                if ( 'height' === $property && $this->shouldUseResponsiveAutoHeight($value, $baseMap, $baseNode, $variantNode, $variantParentNode) ) {
                    if ( ! array_key_exists('height', $baseMap) || 'auto' !== $baseMap['height'] ) {
                        $changed[] = 'height:auto';
                    }
                    continue;
                }
                $responsiveWidthDeclarations = 'width' === $property
                    ? $this->breakpointDimensionPolicy->breakpointWidthDeclarations($value, $baseMap, $baseNode, $variantNode, $baseParentNode, $variantParentNode)
                    : null;
                if ( null !== $responsiveWidthDeclarations ) {
                    foreach ( $responsiveWidthDeclarations as $responsiveWidthDeclaration ) {
                        $responsiveParts = explode(':', $responsiveWidthDeclaration, 2);
                        if ( 2 !== count($responsiveParts) ) {
                            continue;
                        }
                        $responsiveProperty = trim($responsiveParts[0]);
                        $responsiveValue = trim($responsiveParts[1]);
                        $responsiveWidthHandledProperties[$responsiveProperty] = true;
                        if ( ! array_key_exists($responsiveProperty, $baseMap) || $baseMap[$responsiveProperty] !== $responsiveValue ) {
                            $changed[] = $responsiveProperty . ':' . $responsiveValue;
                        }
                    }
                    continue;
                }
                if ( isset($responsiveWidthHandledProperties[$property]) ) {
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

        return array_values(array_unique($rules));
    }

    /**
     * @param array<string, string> $baseMap
     */
    private function usesFullBleedViewportBreakout(array $baseMap): bool
    {
        return '100vw' === ($baseMap['width'] ?? null)
            && '50%' === ($baseMap['left'] ?? null)
            && '-50vw' === ($baseMap['margin-left'] ?? null);
    }

    /**
     * Figma exports often keep headers, footer rows, newsletter panels, and card
     * grids as page-specific absolute/freeform nodes. When a breakpoint variant
     * changes clone/source identity, structural diffing cannot match those nodes,
     * so desktop widths survive into mobile. These class-scoped fallbacks only
     * target already-emitted base nodes whose normalized names and styles identify
     * those responsive shells.
     *
     * @param array<string, array<string, mixed>> $baseStyles
     * @param array<string, array<string, mixed>> $variantStyles
     * @return array<int, string>
     */
    private function responsiveSafetyRules(array $baseStyles, array $variantStyles, float $viewportWidth, array $matchedBreakpointGeometryClasses): array
    {
        $rules = array();
        foreach ( $baseStyles as $base ) {
            $node = is_array($base['node'] ?? null) ? $base['node'] : array();
            $parentNode = is_array($base['parent_node'] ?? null) ? $base['parent_node'] : null;
            $grandParentNode = is_array($base['grand_parent_node'] ?? null) ? $base['grand_parent_node'] : null;
            $class = isset($base['class']) && is_scalar($base['class']) ? (string) $base['class'] : '';
            $baseMap = $this->styleDeclarationMap(is_array($base['styles'] ?? null) ? $base['styles'] : array());
            if ( '' === $class || empty($node) || empty($baseMap) ) {
                continue;
            }

            $depth = isset($base['depth']) && is_numeric($base['depth']) ? (int) $base['depth'] : 0;
            $pathKey = is_string($base['path_key'] ?? null) ? (string) $base['path_key'] : '';
            $variantNode = '' !== $pathKey && is_array($variantStyles[$pathKey]['node'] ?? null) ? $variantStyles[$pathKey]['node'] : null;
            $decision = $this->responsiveBreakpointSafetyPolicy->responsiveSafetyDecision($node, $parentNode, $baseMap, $viewportWidth, $depth, $grandParentNode, $variantNode);
            $declarations = is_array($decision['declarations'] ?? null) ? $decision['declarations'] : array();
            if ( empty($declarations) ) {
                continue;
            }

            $reasonCode = (string) ($decision['reason_code'] ?? 'responsive_safety_override');
            if ( isset($matchedBreakpointGeometryClasses[$class]) && $this->isGenericResponsiveFlowSafetyReason($reasonCode) ) {
                continue;
            }

            $changed = array();
            foreach ( $declarations as $declaration ) {
                $parts = explode(':', $declaration, 2);
                if ( 2 !== count($parts) ) {
                    continue;
                }
                $property = trim($parts[0]);
                $value = trim($parts[1]);
                if ( ! array_key_exists($property, $baseMap) || $baseMap[$property] !== $value ) {
                    $changed[] = $property . ':' . $value;
                }
            }
            if ( ! empty($changed) ) {
                $rules[] = '.' . $class . '{' . implode(';', $changed) . '}';
                $this->recordResponsiveDecisionTrace($node, $parentNode, $reasonCode, $viewportWidth, $changed);
            }
        }

        return array_values(array_unique($rules));
    }

    /**
     * @param array<string, array<string, mixed>> $baseStyles
     * @param array<string, array<string, mixed>> $variantStyles
     * @return array<string, true>
     */
    private function matchedBreakpointGeometryClasses(array $baseStyles, array $variantStyles): array
    {
        $classes = array();
        foreach ( $baseStyles as $pathKey => $base ) {
            if ( ! isset($variantStyles[$pathKey]) ) {
                continue;
            }

            $class = isset($base['class']) && is_scalar($base['class']) ? (string) $base['class'] : '';
            if ( '' === $class ) {
                continue;
            }

            $variantMap = $this->styleDeclarationMap(is_array($variantStyles[$pathKey]['styles'] ?? null) ? $variantStyles[$pathKey]['styles'] : array());
            foreach ( array('position', 'left', 'right', 'top', 'bottom', 'width', 'height') as $property ) {
                if ( array_key_exists($property, $variantMap) ) {
                    $classes[$class] = true;
                    break;
                }
            }
        }

        return $classes;
    }

    private function isGenericResponsiveFlowSafetyReason(string $reasonCode): bool
    {
        return in_array($reasonCode, array(
            'responsive_header_child_chrome_safety',
            'responsive_footer_child_chrome_safety',
            'responsive_generic_mobile_safety',
        ), true);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     * @param array<int, string> $declarations
     */
    private function recordResponsiveDecisionTrace(array $node, ?array $parentNode, string $reasonCode, float $viewportWidth, array $declarations): void
    {
        DecisionTraceBuilder::recordResponsiveTrace($this->decisionTraces, $node, $parentNode, $reasonCode, $viewportWidth, $declarations);
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
    private function shouldUseResponsiveAutoHeight(string $value, array $baseMap, array $baseNode, array $variantNode, ?array $variantParentNode): bool
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

        if ( in_array($type, array('INSTANCE', 'COMPONENT', 'SYMBOL'), true) && $this->variantFillsParentWidth($variantNode, $variantParentNode) ) {
            return true;
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
    private function variantFillsParentWidth(array $node, ?array $parentNode): bool
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
        if ( ! isset($box['width'], $parentBox['width']) || ! is_numeric($box['width']) || ! is_numeric($parentBox['width']) ) {
            return false;
        }

        return abs((float) $box['width'] - (float) $parentBox['width']) <= 1.0;
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
     * @param array<string, mixed> $node
     */
    private function nodeBoxHeight(array $node): ?float
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        if ( ! isset($box['height']) || ! is_numeric($box['height']) ) {
            return null;
        }

        return (float) $box['height'];
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

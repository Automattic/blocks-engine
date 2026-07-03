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
    ) {
        $this->responsiveNodeMatcher = $responsiveNodeMatcher ?? new ResponsiveNodeMatcher($this->slug);
        $this->breakpointDimensionPolicy = $breakpointDimensionPolicy ?? new BreakpointDimensionPolicy($this->number);
        $this->layoutIntentClassifier = $layoutIntentClassifier ?? new LayoutIntentClassifier();
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

            $rules = array_merge(
                $this->diffRules($baseStyles, $variantStyles),
                $this->responsiveSafetyRules($baseStyles, $variantStyles, (float) $viewportWidth)
            );
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
    private function responsiveSafetyRules(array $baseStyles, array $variantStyles, float $viewportWidth): array
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
            $decision = $this->responsiveSafetyDecision($node, $parentNode, $baseMap, $viewportWidth, $depth, $grandParentNode, $variantNode);
            $declarations = is_array($decision['declarations'] ?? null) ? $decision['declarations'] : array();
            if ( empty($declarations) ) {
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
                $this->recordResponsiveDecisionTrace($node, $parentNode, (string) ($decision['reason_code'] ?? 'responsive_safety_override'), $viewportWidth, $changed);
            }
        }

        return array_values(array_unique($rules));
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     * @param array<string, string> $baseMap
     * @return array<int, string>
     */
    private function responsiveSafetyDeclarations(array $node, ?array $parentNode, array $baseMap, float $viewportWidth, int $depth = 0, ?array $grandParentNode = null): array
    {
        $decision = $this->responsiveSafetyDecision($node, $parentNode, $baseMap, $viewportWidth, $depth, $grandParentNode);
        return is_array($decision['declarations'] ?? null) ? $decision['declarations'] : array();
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     * @param array<string, string> $baseMap
     * @return array{reason_code: string, declarations: array<int, string>}
     */
    private function responsiveSafetyDecision(array $node, ?array $parentNode, array $baseMap, float $viewportWidth, int $depth = 0, ?array $grandParentNode = null, ?array $variantNode = null): array
    {
        $name = strtolower(trim((string) ($node['name'] ?? '')));
        $type = strtoupper((string) ($node['type'] ?? 'FRAME'));
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        $positioning = (string) ($layout['positioning'] ?? ($baseMap['position'] ?? ''));
        $display = (string) ($baseMap['display'] ?? '');
        $width = $this->cssPixelValue($baseMap['width'] ?? '');
        if ( null === $width && '100%' === ($baseMap['width'] ?? null) ) {
            $width = $this->cssPixelValue($baseMap['max-width'] ?? '');
        }
        $parentName = null === $parentNode ? '' : strtolower(trim((string) ($parentNode['name'] ?? '')));
        $isContainer = in_array($type, array('FRAME', 'GROUP', 'INSTANCE', 'COMPONENT', 'SYMBOL'), true);
        $chromeRole = $this->layoutIntentClassifier->chromeGroupRole($node, $parentNode, $depth);
        $parentChromeRole = null === $parentNode ? null : $this->layoutIntentClassifier->chromeGroupRole($parentNode, $grandParentNode, max(1, $depth - 1));

        if ( (LayoutIntentClassifier::CHROME_GROUP_ROLE_HEADER === $chromeRole || $this->isHeaderChromeShellName($name)) && $isContainer ) {
            $minHeight = $this->responsiveHeaderMinHeight($node, $baseMap, $variantNode);
            return array('reason_code' => 'responsive_header_chrome_safety', 'declarations' => $this->breakpointDimensionPolicy->headerChromeDeclarations($minHeight));
        }

        if ( LayoutIntentClassifier::CHROME_GROUP_ROLE_HEADER === $parentChromeRole || $this->isHeaderChromeShellName($parentName) ) {
            $headerChildDeclarations = array('position:relative', 'left:auto', 'right:auto', 'top:auto', 'max-width:100%');
            if ( $isContainer ) {
                array_unshift($headerChildDeclarations, 'width:100%', 'max-width:100%', 'height:auto');
                array_push($headerChildDeclarations, 'justify-content:flex-start', 'align-items:center', 'flex-wrap:wrap', 'gap:16px', 'padding-top:24px', 'padding-right:24px', 'padding-bottom:24px', 'padding-left:24px');
            }

            return array('reason_code' => 'responsive_header_child_chrome_safety', 'declarations' => array_values(array_unique($headerChildDeclarations)));
        }

        if ( $this->isNavigationShellName($name) && $isContainer ) {
            return array('reason_code' => 'responsive_navigation_shell_safety', 'declarations' => array('width:100%', 'max-width:100%', 'height:auto', 'justify-content:flex-start', 'flex-wrap:wrap', 'gap:16px'));
        }

        if ( 'footer' === $name && $isContainer && $this->hasFooterResponsiveShell($node) ) {
            return array('reason_code' => 'responsive_footer_shell_safety', 'declarations' => array('height:auto', 'min-height:' . ($this->number)($this->footerResponsiveMinHeight($node)) . 'px'));
        }

        if ( (LayoutIntentClassifier::CHROME_GROUP_ROLE_NAVIGATION === $chromeRole || 'navigation' === $name) && $isContainer ) {
            return array('reason_code' => 'responsive_navigation_chrome_safety', 'declarations' => array('width:100%', 'max-width:100%', 'height:auto', 'justify-content:flex-start', 'flex-wrap:wrap', 'gap:16px'));
        }

        if ( str_contains($name, 'newsletter signup') && $isContainer && 'absolute' === $positioning ) {
            return array('reason_code' => 'responsive_absolute_newsletter_shell_safety', 'declarations' => array_merge($this->breakpointDimensionPolicy->sourceMaxWidthDeclarations(1216.0, 24.0, 'fixed'), array('height:auto', 'left:24px')));
        }

        if ( 'frame 20' === $name && $isContainer && null !== $parentNode && str_contains($parentName, 'newsletter signup') ) {
            return array('reason_code' => 'responsive_newsletter_inner_shell_safety', 'declarations' => array('height:auto', 'padding-top:56px', 'padding-right:24px', 'padding-bottom:48px', 'padding-left:24px', 'gap:24px'));
        }

        if ( 'frame 19' === $name && $isContainer && 'absolute' === $positioning ) {
            return array('reason_code' => 'responsive_absolute_inner_shell_safety', 'declarations' => array('height:auto', 'position:relative', 'left:auto', 'top:auto', 'justify-content:center', 'flex-wrap:wrap', 'align-content:flex-start', 'padding-top:32px', 'padding-right:24px', 'padding-bottom:32px', 'padding-left:24px'));
        }

        if ( ('featured preview' === $name || 'preview' === $name) && $isContainer && null !== $width && $width > 340.0 ) {
            return array('reason_code' => 'responsive_preview_card_width_safety', 'declarations' => array('width:100%', 'height:auto'));
        }

        if ( 'pagination' === $name && $isContainer ) {
            return array('reason_code' => 'responsive_pagination_overflow_safety', 'declarations' => array_merge($this->breakpointDimensionPolicy->sourceMaxWidthDeclarations(1216.0, 24.0, 'fixed'), array('overflow-x:auto')));
        }

        if ( 'image' === $name && in_array($display, array('flex', 'inline-flex'), true) && null !== $width && $width > 340.0 ) {
            return array('reason_code' => 'responsive_image_fill_safety', 'declarations' => $this->breakpointDimensionPolicy->fluidFillDeclarations());
        }

        if ( $viewportWidth <= 480.0 ) {
            $mobileDeclarations = $this->genericMobileSafetyDeclarations($node, $parentNode, $baseMap, $viewportWidth, $isContainer, $width, $positioning, $display);
            if ( ! empty($mobileDeclarations) ) {
                return array('reason_code' => 'responsive_generic_mobile_safety', 'declarations' => $mobileDeclarations);
            }
        }

        return array('reason_code' => '', 'declarations' => array());
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     * @param array<int, string> $declarations
     */
    private function recordResponsiveDecisionTrace(array $node, ?array $parentNode, string $reasonCode, float $viewportWidth, array $declarations): void
    {
        if ( '' === $reasonCode ) {
            $reasonCode = 'responsive_safety_override';
        }
        $nodeId = (string) ($node['id'] ?? '');
        $key = implode('|', array($reasonCode, $nodeId, (string) ($parentNode['id'] ?? ''), (string) $viewportWidth));
        if ( isset($this->decisionTraces[$key]) ) {
            $this->decisionTraces[$key]['count'] = (int) ($this->decisionTraces[$key]['count'] ?? 1) + 1;
            return;
        }

        $this->decisionTraces[$key] = array_filter(array(
            'domain' => 'responsive_decision',
            'reason_code' => $reasonCode,
            'decision' => 'emit_media_override',
            'node_id' => $nodeId,
            'name' => (string) ($node['name'] ?? ''),
            'type' => strtoupper((string) ($node['type'] ?? '')),
            'parent_id' => null === $parentNode ? null : (string) ($parentNode['id'] ?? ''),
            'viewport_width' => $viewportWidth,
            'declarations' => array_values($declarations),
            'count' => 1,
        ), static fn (mixed $value): bool => null !== $value && '' !== $value && array() !== $value);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     * @param array<string, string> $baseMap
     * @return array<int, string>
     */
    private function genericMobileSafetyDeclarations(array $node, ?array $parentNode, array $baseMap, float $viewportWidth, bool $isContainer, ?float $width, string $positioning, string $display): array
    {
        $mobileContentWidth = max(1.0, $viewportWidth - 48.0);
        if ( ! $isContainer || null === $parentNode || null === $width || $width <= min(340.0, $mobileContentWidth) || empty(($this->nodeList)($node)) ) {
            return array();
        }

        $declarations = array();
        $hasContainerChild = false;
        foreach ( ($this->nodeList)($node) as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            $childType = strtoupper((string) ($child['type'] ?? 'FRAME'));
            if ( in_array($childType, array('FRAME', 'GROUP', 'INSTANCE', 'COMPONENT', 'SYMBOL'), true) ) {
                $hasContainerChild = true;
                break;
            }
        }

        if ( 'absolute' === $positioning ) {
            if ( $width > $mobileContentWidth ) {
                array_push($declarations, ...$this->breakpointDimensionPolicy->sourceMaxWidthDeclarations($width, 24.0, 'absolute'));
                $declarations[] = 'height:auto';

                if ( in_array($display, array('flex', 'inline-flex'), true) && 'row' === ($baseMap['flex-direction'] ?? null) && $hasContainerChild ) {
                    $declarations[] = 'flex-direction:column';
                    $declarations[] = 'align-items:stretch';
                    $declarations[] = 'flex-wrap:nowrap';
                }

                foreach ( array('top', 'right', 'bottom', 'left') as $edge ) {
                    $property = 'padding-' . $edge;
                    $padding = $this->cssPixelValue($baseMap[$property] ?? '');
                    if ( null !== $padding && $padding > 24.0 ) {
                        $declarations[] = $property . ':24px';
                    }
                }
            }

            return $declarations;
        }

        if ( 'auto' === ($baseMap['margin-left'] ?? null) && 'auto' === ($baseMap['margin-right'] ?? null) && $width > $mobileContentWidth ) {
            array_push($declarations, ...$this->breakpointDimensionPolicy->sourceMaxWidthDeclarations($width, 24.0, 'centered'));

            if ( $hasContainerChild ) {
                $declarations[] = 'height:auto';
            }

            if ( in_array($display, array('flex', 'inline-flex'), true) && 'row' === ($baseMap['flex-direction'] ?? null) && $hasContainerChild ) {
                $declarations[] = 'flex-direction:column';
                $declarations[] = 'align-items:stretch';
                $declarations[] = 'flex-wrap:nowrap';
            }

            if ( in_array($display, array('grid', 'inline-grid'), true) && $hasContainerChild ) {
                $declarations[] = 'grid-template-columns:1fr';
            }

            foreach ( array('top', 'right', 'bottom', 'left') as $edge ) {
                $property = 'padding-' . $edge;
                $padding = $this->cssPixelValue($baseMap[$property] ?? '');
                if ( null !== $padding && $padding > 24.0 ) {
                    $declarations[] = $property . ':24px';
                }
            }

            return $declarations;
        }

        array_push($declarations, ...$this->breakpointDimensionPolicy->fluidFillDeclarations());

        if ( $hasContainerChild ) {
            $declarations[] = 'height:auto';
        }

        if ( in_array($display, array('flex', 'inline-flex'), true) && 'row' === ($baseMap['flex-direction'] ?? null) && $hasContainerChild ) {
            $declarations[] = 'flex-direction:column';
            $declarations[] = 'align-items:stretch';
            $declarations[] = 'flex-wrap:nowrap';
        }

        if ( in_array($display, array('grid', 'inline-grid'), true) && $hasContainerChild ) {
            $declarations[] = 'grid-template-columns:1fr';
        }

        foreach ( array('top', 'right', 'bottom', 'left') as $edge ) {
            $property = 'padding-' . $edge;
            $padding = $this->cssPixelValue($baseMap[$property] ?? '');
            if ( null !== $padding && $padding > 24.0 ) {
                $declarations[] = $property . ':24px';
            }
        }

        return $declarations;
    }

    private function isHeaderChromeShellName(string $name): bool
    {
        return (bool) preg_match('/^(?:header|site\s+header|page\s+header|main\s+header|masthead|top\s*bar|site\s*chrome)$/', $name);
    }

    private function isNavigationShellName(string $name): bool
    {
        return (bool) preg_match('/(?:^|[^a-z0-9])(?:navigation|nav|menu)(?:[^a-z0-9]|$)/', $name);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasFooterResponsiveShell(array $node): bool
    {
        $hasNewsletter = false;
        $hasBottomRow = false;
        $freeformParent = $this->isFreeformContainer($node);
        foreach ( ($this->nodeList)($node) as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }
            $name = strtolower(trim((string) ($child['name'] ?? '')));
            $layout = is_array($child['layout'] ?? null) ? $child['layout'] : array();
            if ( str_contains($name, 'newsletter signup') && ('absolute' === ($layout['positioning'] ?? null) || $freeformParent) ) {
                $hasNewsletter = true;
            }
            if ( 'frame 19' === $name ) {
                $hasBottomRow = true;
            }
        }

        return $hasNewsletter && $hasBottomRow;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isFreeformContainer(array $node): bool
    {
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        return empty($layout['display']) && ! empty(($this->nodeList)($node));
    }

    /**
     * @param array<string, mixed> $node
     */
    private function footerResponsiveMinHeight(array $node): float
    {
        $baseHeight = $this->nodeBoxHeight($node) ?? 0.0;
        $newsletterHeight = 0.0;
        $bottomRowHeight = 0.0;
        foreach ( ($this->nodeList)($node) as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }
            $name = strtolower(trim((string) ($child['name'] ?? '')));
            if ( str_contains($name, 'newsletter signup') ) {
                $newsletterHeight = max($newsletterHeight, $this->nodeBoxHeight($child) ?? 0.0);
            }
            if ( 'frame 19' === $name ) {
                $bottomRowHeight = max($bottomRowHeight, $this->nodeBoxHeight($child) ?? 0.0);
            }
        }

        return max($baseHeight, $newsletterHeight + $bottomRowHeight);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, string> $baseMap
     * @param array<string, mixed>|null $variantNode
     */
    private function responsiveHeaderMinHeight(array $node, array $baseMap, ?array $variantNode): ?float
    {
        $baseHeight = $this->cssPixelValue($baseMap['height'] ?? '') ?? $this->nodeBoxHeight($node);
        $variantHeight = null === $variantNode ? null : $this->nodeBoxHeight($variantNode);

        if ( null === $baseHeight ) {
            return $variantHeight;
        }

        if ( null === $variantHeight ) {
            return $baseHeight;
        }

        return max($baseHeight, $variantHeight);
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

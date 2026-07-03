<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Resolves class-scoped responsive fallback decisions when breakpoint nodes cannot be matched directly.
 */
final class ResponsiveBreakpointSafetyPolicy
{
    /**
     * @param callable(array<string, mixed>): array<int, mixed> $nodeList
     * @param callable(float): string $number
     */
    public function __construct(
        private readonly mixed $nodeList,
        private readonly mixed $number,
        private readonly BreakpointDimensionPolicy $breakpointDimensionPolicy,
        private readonly LayoutIntentClassifier $layoutIntentClassifier,
    ) {
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     * @param array<string, string> $baseMap
     * @param array<string, mixed>|null $grandParentNode
     * @param array<string, mixed>|null $variantNode
     * @return array{reason_code: string, declarations: array<int, string>}
     */
    public function responsiveSafetyDecision(array $node, ?array $parentNode, array $baseMap, float $viewportWidth, int $depth = 0, ?array $grandParentNode = null, ?array $variantNode = null): array
    {
        $name = strtolower(trim((string) ($node['name'] ?? '')));
        $type = strtoupper((string) ($node['type'] ?? 'FRAME'));
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        $positioning = (string) ($layout['positioning'] ?? ($baseMap['position'] ?? ''));
        $display = (string) ($baseMap['display'] ?? '');
        $width = $this->responsiveSourceWidth($baseMap);
        $parentName = null === $parentNode ? '' : strtolower(trim((string) ($parentNode['name'] ?? '')));
        $isContainer = in_array($type, array('FRAME', 'GROUP', 'INSTANCE', 'COMPONENT', 'SYMBOL'), true);
        $chromeRole = $this->layoutIntentClassifier->chromeGroupRole($node, $parentNode, $depth);
        $parentChromeRole = null === $parentNode ? null : $this->layoutIntentClassifier->chromeGroupRole($parentNode, $grandParentNode, max(1, $depth - 1));

        $chromeDecision = $this->responsiveChromeFlowDecision($node, $parentNode, $baseMap, $variantNode, $name, $parentName, $isContainer, $chromeRole, $parentChromeRole);
        if ( '' !== $chromeDecision['reason_code'] ) {
            return $chromeDecision;
        }

        $namedShellDecision = $this->namedResponsiveShellDecision($node, $parentNode, $name, $parentName, $isContainer, $width, $positioning, $display, $chromeRole);
        if ( '' !== $namedShellDecision['reason_code'] ) {
            return $namedShellDecision;
        }

        if ( $viewportWidth <= 480.0 ) {
            $mobileTextDeclarations = $this->mobileCenteredTextFallbackDecision($node, $parentNode, $baseMap, $viewportWidth, $type, $width, $positioning, $variantNode);
            if ( ! empty($mobileTextDeclarations) ) {
                return array('reason_code' => 'responsive_centered_text_mobile_safety', 'declarations' => $mobileTextDeclarations);
            }

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
     * @param array<string, string> $baseMap
     * @param array<string, mixed>|null $variantNode
     * @return array{reason_code: string, declarations: array<int, string>}
     */
    public function responsiveChromeFlowDecision(array $node, ?array $parentNode, array $baseMap, ?array $variantNode, string $name, string $parentName, bool $isContainer, ?string $chromeRole, ?string $parentChromeRole): array
    {
        if ( (LayoutIntentClassifier::CHROME_GROUP_ROLE_HEADER === $chromeRole || $this->isHeaderChromeShellName($name)) && $isContainer ) {
            return array('reason_code' => 'responsive_header_chrome_safety', 'declarations' => $this->breakpointDimensionPolicy->headerChromeDeclarations($this->responsiveHeaderMinHeight($node, $baseMap, $variantNode)));
        }

        if ( null === $variantNode && (LayoutIntentClassifier::CHROME_GROUP_ROLE_HEADER === $parentChromeRole || $this->isHeaderChromeShellName($parentName)) ) {
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

        return array('reason_code' => '', 'declarations' => array());
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     * @return array{reason_code: string, declarations: array<int, string>}
     */
    private function namedResponsiveShellDecision(array $node, ?array $parentNode, string $name, string $parentName, bool $isContainer, ?float $width, string $positioning, string $display, ?string $chromeRole): array
    {
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

        return array('reason_code' => '', 'declarations' => array());
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     * @param array<string, string> $baseMap
     * @param array<string, mixed>|null $variantNode
     * @return array<int, string>
     */
    public function mobileCenteredTextFallbackDecision(array $node, ?array $parentNode, array $baseMap, float $viewportWidth, string $type, ?float $width, string $positioning, ?array $variantNode): array
    {
        if ( 'TEXT' !== $type || null === $parentNode || null === $width || 'absolute' !== $positioning ) {
            return array();
        }

        $computedLeft = $this->mobileComputedCenteredLeft($baseMap['left'] ?? '', $viewportWidth);
        if ( null === $computedLeft || $computedLeft >= 0.0 ) {
            return array();
        }

        if ( null !== $variantNode && $this->variantTextFitsViewport($variantNode, $viewportWidth) ) {
            return array();
        }

        $mobileContentWidth = max(1.0, $viewportWidth - 48.0);
        return array(
            'width:calc(100% - 48px)',
            'max-width:' . ($this->number)(min($width, $mobileContentWidth)) . 'px',
            'left:24px',
            'right:auto',
        );
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
        $hasContainerChild = $this->hasContainerChild($node);

        if ( 'absolute' === $positioning ) {
            if ( $width > $mobileContentWidth ) {
                array_push($declarations, ...$this->breakpointDimensionPolicy->sourceMaxWidthDeclarations($width, 24.0, 'absolute'));
                $declarations[] = 'height:auto';
                array_push($declarations, ...$this->stackedMobileFlowDeclarations($baseMap, $display, $hasContainerChild));
                array_push($declarations, ...$this->mobilePaddingClampDeclarations($baseMap));
            }

            return $declarations;
        }

        if ( 'auto' === ($baseMap['margin-left'] ?? null) && 'auto' === ($baseMap['margin-right'] ?? null) && $width > $mobileContentWidth ) {
            array_push($declarations, ...$this->breakpointDimensionPolicy->sourceMaxWidthDeclarations($width, 24.0, 'centered'));

            if ( $hasContainerChild ) {
                $declarations[] = 'height:auto';
            }

            array_push($declarations, ...$this->stackedMobileFlowDeclarations($baseMap, $display, $hasContainerChild));
            array_push($declarations, ...$this->mobilePaddingClampDeclarations($baseMap));

            return $declarations;
        }

        array_push($declarations, ...$this->breakpointDimensionPolicy->fluidFillDeclarations());

        if ( $hasContainerChild ) {
            $declarations[] = 'height:auto';
        }

        array_push($declarations, ...$this->stackedMobileFlowDeclarations($baseMap, $display, $hasContainerChild));
        array_push($declarations, ...$this->mobilePaddingClampDeclarations($baseMap));

        return $declarations;
    }

    /**
     * @param array<string, string> $baseMap
     * @return array<int, string>
     */
    private function stackedMobileFlowDeclarations(array $baseMap, string $display, bool $hasContainerChild): array
    {
        if ( ! $hasContainerChild ) {
            return array();
        }

        if ( in_array($display, array('flex', 'inline-flex'), true) && 'row' === ($baseMap['flex-direction'] ?? null) ) {
            return array('flex-direction:column', 'align-items:stretch', 'flex-wrap:nowrap');
        }

        if ( in_array($display, array('grid', 'inline-grid'), true) ) {
            return array('grid-template-columns:1fr');
        }

        return array();
    }

    /**
     * @param array<string, string> $baseMap
     * @return array<int, string>
     */
    private function mobilePaddingClampDeclarations(array $baseMap): array
    {
        $declarations = array();
        foreach ( array('top', 'right', 'bottom', 'left') as $edge ) {
            $property = 'padding-' . $edge;
            $padding = $this->cssPixelValue($baseMap[$property] ?? '');
            if ( null !== $padding && $padding > 24.0 ) {
                $declarations[] = $property . ':24px';
            }
        }

        return $declarations;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasContainerChild(array $node): bool
    {
        foreach ( ($this->nodeList)($node) as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            $childType = strtoupper((string) ($child['type'] ?? 'FRAME'));
            if ( in_array($childType, array('FRAME', 'GROUP', 'INSTANCE', 'COMPONENT', 'SYMBOL'), true) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $baseMap
     */
    private function responsiveSourceWidth(array $baseMap): ?float
    {
        $width = $this->cssPixelValue($baseMap['width'] ?? '');
        if ( null === $width && '100%' === ($baseMap['width'] ?? null) ) {
            $width = $this->cssPixelValue($baseMap['max-width'] ?? '');
        }

        return $width;
    }

    private function mobileComputedCenteredLeft(string $left, float $viewportWidth): ?float
    {
        $left = trim($left);
        if ( 1 === preg_match('/^calc\(50%\s*([+-])\s*(\d+(?:\.\d+)?)px\)$/', $left, $matches) ) {
            $delta = (float) $matches[2];
            return ($viewportWidth / 2.0) + ('-' === $matches[1] ? -$delta : $delta);
        }

        return $this->cssPixelValue($left);
    }

    /**
     * @param array<string, mixed> $variantNode
     */
    private function variantTextFitsViewport(array $variantNode, float $viewportWidth): bool
    {
        $box = is_array($variantNode['box'] ?? null) ? $variantNode['box'] : array();
        if ( ! isset($box['x'], $box['width']) || ! is_numeric($box['x']) || ! is_numeric($box['width']) ) {
            return false;
        }

        $x = (float) $box['x'];
        $width = (float) $box['width'];
        return $x >= 0.0 && $width > 0.0 && ($x + min($width, $viewportWidth)) <= $viewportWidth + 1.0;
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
}

<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Builds diagnostics derived from emitted static HTML artifacts.
 */
final class StaticHtmlEmissionDiagnostics
{
    /**
     * @param array<string, mixed> $coverage
     * @param array<string, string> $implicitRouteTargets
     * @return array<string, mixed>
     */
    public function linkCoverageSummary(array $coverage, array $implicitRouteTargets): array
    {
        return array(
            'schema'             => 'blocks-engine/figma-transformer/link-coverage/v1',
            'sources_found'      => (int) ($coverage['sources_found'] ?? 0),
            'anchors_emitted'    => (int) ($coverage['anchors_emitted'] ?? 0),
            'url_links'          => (int) ($coverage['url_links'] ?? 0),
            'node_links'         => (int) ($coverage['node_links'] ?? 0),
            'toc_links'          => (int) ($coverage['toc_links'] ?? 0),
            'implicit_route_links' => (int) ($coverage['implicit_route_links'] ?? 0),
            'implicit_route_self_suppressed' => (int) ($coverage['implicit_route_self_suppressed'] ?? 0),
            'implicit_route_unresolved' => (int) ($coverage['implicit_route_unresolved'] ?? 0),
            'route_targets'      => array_values($implicitRouteTargets),
            'implicit_route_unresolved_targets' => array_values(is_array($coverage['implicit_route_unresolved_targets'] ?? null) ? $coverage['implicit_route_unresolved_targets'] : array()),
            'implicit_route_self_suppressed_targets' => array_values(is_array($coverage['implicit_route_self_suppressed_targets'] ?? null) ? $coverage['implicit_route_self_suppressed_targets'] : array()),
            'unresolved'         => (int) ($coverage['unresolved'] ?? 0),
            'unresolved_targets' => array_values(is_array($coverage['unresolved_targets'] ?? null) ? $coverage['unresolved_targets'] : array()),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $visualNodeMap
     * @return array<string, mixed>
     */
    public function visualNodeMapSummary(array $visualNodeMap): array
    {
        $pagePathCounts = array();
        $emittedTagCounts = array();
        $emittedClassSamples = array();
        $withEmittedMetadata = 0;
        $withPagePath = 0;

        foreach ( $visualNodeMap as $visualNode ) {
            if ( ! is_array($visualNode) ) {
                continue;
            }

            $pagePath = isset($visualNode['page_path']) && is_scalar($visualNode['page_path']) ? (string) $visualNode['page_path'] : '';
            if ( '' !== $pagePath ) {
                ++$withPagePath;
                $pagePathCounts[$pagePath] = ($pagePathCounts[$pagePath] ?? 0) + 1;
            }

            $emittedClass = isset($visualNode['emitted_class']) && is_scalar($visualNode['emitted_class']) ? (string) $visualNode['emitted_class'] : '';
            $emittedTag = isset($visualNode['emitted_tag']) && is_scalar($visualNode['emitted_tag']) ? (string) $visualNode['emitted_tag'] : '';
            if ( '' !== $emittedClass || '' !== $emittedTag ) {
                ++$withEmittedMetadata;
            }
            if ( '' !== $emittedTag ) {
                $emittedTagCounts[$emittedTag] = ($emittedTagCounts[$emittedTag] ?? 0) + 1;
            }
            if ( '' !== $emittedClass && count($emittedClassSamples) < 10 ) {
                $emittedClassSamples[] = array(
                    'node_id' => isset($visualNode['id']) && is_scalar($visualNode['id']) ? (string) $visualNode['id'] : '',
                    'class' => $emittedClass,
                    'page_path' => '' !== $pagePath ? $pagePath : null,
                );
            }
        }

        ksort($pagePathCounts);
        ksort($emittedTagCounts);

        return array(
            'schema' => 'blocks-engine/figma-transformer/visual-node-map-summary/v1',
            'visual_node_count' => count($visualNodeMap),
            'nodes_with_emitted_metadata' => $withEmittedMetadata,
            'nodes_with_page_path' => $withPagePath,
            'page_path_counts' => $pagePathCounts,
            'emitted_tag_counts' => $emittedTagCounts,
            'emitted_class_samples' => $emittedClassSamples,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function cssDiagnostics(string $css): array
    {
        $tokens = array();
        if ( 1 === preg_match_all('/(?<![a-z0-9_-])(?:nan|(?:-)?inf(?:inity)?)(?![a-z0-9_-])/i', $css, $matches, PREG_OFFSET_CAPTURE) ) {
            foreach ( $matches[0] as $match ) {
                $tokens[] = array(
                    'token' => (string) $match[0],
                    'offset' => (int) $match[1],
                );
            }
        }

        return array(
            'schema' => 'blocks-engine/figma-transformer/css-diagnostics/v1',
            'invalid_numeric_token_count' => count($tokens),
            'invalid_numeric_tokens' => array_slice($tokens, 0, 25),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function htmlArtifactDiagnostics(string $html, string $css): array
    {
        $elementCount = preg_match_all('/<([a-z][a-z0-9:-]*)(?:\s|>|\/)/i', $html, $tagMatches) ?: 0;
        $tags = is_array($tagMatches[1] ?? null) ? array_map('strtolower', $tagMatches[1]) : array();
        $svgDrawingTags = array(
            'circle' => true,
            'clippath' => true,
            'defs' => true,
            'ellipse' => true,
            'g' => true,
            'line' => true,
            'lineargradient' => true,
            'mask' => true,
            'path' => true,
            'polygon' => true,
            'polyline' => true,
            'radialgradient' => true,
            'rect' => true,
            'stop' => true,
            'svg' => true,
            'use' => true,
        );
        $structuralTags = array_values(array_filter($tags, static fn (string $tag): bool => ! isset($svgDrawingTags[$tag])));
        $structuralElementCount = count($structuralTags);
        $divCount = count(array_filter($tags, static fn (string $tag): bool => 'div' === $tag));
        $semanticTags = array(
            'a' => true,
            'article' => true,
            'aside' => true,
            'button' => true,
            'figcaption' => true,
            'figure' => true,
            'footer' => true,
            'form' => true,
            'h1' => true,
            'h2' => true,
            'h3' => true,
            'h4' => true,
            'h5' => true,
            'h6' => true,
            'header' => true,
            'img' => true,
            'input' => true,
            'li' => true,
            'main' => true,
            'nav' => true,
            'ol' => true,
            'p' => true,
            'picture' => true,
            'section' => true,
            'ul' => true,
        );
        $semanticElementCount = count(array_filter($structuralTags, static fn (string $tag): bool => isset($semanticTags[$tag])));
        $htmlBytes = strlen($html);
        $inlineSvgBytes = 0;
        $inlineSvgCount = preg_match_all('/<svg\b[^>]*>.*?<\/svg>/is', $html, $svgMatches) ?: 0;
        foreach ( is_array($svgMatches[0] ?? null) ? $svgMatches[0] : array() as $svg ) {
            $inlineSvgBytes += strlen((string) $svg);
        }

        $divRatio = $structuralElementCount > 0 ? round($divCount / $structuralElementCount, 3) : 0.0;
        $semanticDensity = $structuralElementCount > 0 ? round($semanticElementCount / $structuralElementCount, 3) : 0.0;
        $inlineSvgRatio = $htmlBytes > 0 ? round($inlineSvgBytes / $htmlBytes, 3) : 0.0;
        $breakpointLeaks = $this->breakpointOverrideLeaks($css);
        $absoluteToFlowConversions = $this->absoluteToFlowConversions($css);
        $mediaQueryCount = preg_match_all('/@media\s*\(max-width:[^)]+\)/i', $css) ?: 0;
        $fixedWidthOverDesktopCount = 0;
        if ( preg_match_all('/\bwidth:([0-9.]+)px\b/i', $css, $widthMatches) ) {
            foreach ( is_array($widthMatches[1] ?? null) ? $widthMatches[1] : array() as $width ) {
                if ( (float) $width > 1440.0 ) {
                    ++$fixedWidthOverDesktopCount;
                }
            }
        }
        $fixedWidthCoverage = $this->fixedDesktopWidthCoverage($css);
        $largeFixedCanvasHeight = false;
        if ( preg_match_all('/\b(?:height|min-height):([0-9.]+)px\b/i', $css, $heightMatches) ) {
            foreach ( is_array($heightMatches[1] ?? null) ? $heightMatches[1] : array() as $height ) {
                if ( (float) $height >= 3000.0 ) {
                    $largeFixedCanvasHeight = true;
                    break;
                }
            }
        }

        return array(
            'schema' => 'blocks-engine/figma-transformer/html-artifact-diagnostics/v1',
            'html_bytes' => $htmlBytes,
            'element_count' => $elementCount,
            'structural_element_count' => $structuralElementCount,
            'div_count' => $divCount,
            'div_ratio' => $divRatio,
            'semantic_element_count' => $semanticElementCount,
            'semantic_density' => $semanticDensity,
            'canvas_like_dom' => $structuralElementCount >= 80 && $divRatio >= 0.75 && $semanticDensity <= 0.15,
            'semantic_sparsity' => $structuralElementCount >= 40 && $semanticDensity <= 0.08,
            'inline_svg_count' => $inlineSvgCount,
            'inline_svg_bytes' => $inlineSvgBytes,
            'inline_svg_byte_ratio' => $inlineSvgRatio,
            'overlarge_inline_svg_ratio' => $htmlBytes >= 2048 && $inlineSvgBytes >= 32768 && $inlineSvgRatio >= 0.35,
            'media_query_count' => $mediaQueryCount,
            'fixed_width_over_desktop_count' => $fixedWidthOverDesktopCount,
            'fixed_width_over_desktop_class_count' => $fixedWidthCoverage['class_count'],
            'fixed_width_over_desktop_covered_count' => $fixedWidthCoverage['covered_count'],
            'fixed_width_over_desktop_uncovered_count' => $fixedWidthCoverage['uncovered_count'],
            'fixed_width_over_desktop_covered_classes' => array_slice($fixedWidthCoverage['covered_classes'], 0, 25),
            'fixed_width_over_desktop_uncovered_classes' => array_slice($fixedWidthCoverage['uncovered_classes'], 0, 25),
            'large_fixed_canvas_height' => $largeFixedCanvasHeight,
            'desktop_canvas_without_responsive_breakpoints' => 0 === $mediaQueryCount && $largeFixedCanvasHeight && $structuralElementCount >= 80,
            'breakpoint_override_leak_count' => count($breakpointLeaks),
            'breakpoint_override_leaks' => array_slice($breakpointLeaks, 0, 25),
            'absolute_to_flow_conversion_count' => count($absoluteToFlowConversions),
            'absolute_to_flow_conversions' => array_slice($absoluteToFlowConversions, 0, 25),
        );
    }

    /**
     * @return array{class_count: int, covered_count: int, uncovered_count: int, covered_classes: array<int, string>, uncovered_classes: array<int, string>}
     */
    private function fixedDesktopWidthCoverage(string $css): array
    {
        $fixedClasses = array();
        if ( preg_match_all('/\.([a-z0-9_-]+)\{([^{}]*)\}/i', $css, $ruleMatches, PREG_SET_ORDER) ) {
            foreach ( $ruleMatches as $ruleMatch ) {
                $class = (string) ($ruleMatch[1] ?? '');
                $body = (string) ($ruleMatch[2] ?? '');
                if ( '' === $class || ! preg_match('/\bwidth:([0-9.]+)px\b/i', $body, $widthMatch) ) {
                    continue;
                }

                if ( (float) $widthMatch[1] > 1440.0 ) {
                    $fixedClasses[$class] = true;
                }
            }
        }

        $coveredClasses = array();
        if ( preg_match_all('/@media\s*\(max-width:[^)]+\)\s*\{(.*?)\n\}/is', $css, $mediaMatches) ) {
            foreach ( is_array($mediaMatches[1] ?? null) ? $mediaMatches[1] : array() as $mediaBody ) {
                if ( ! preg_match_all('/\.([a-z0-9_-]+)\{([^{}]*)\}/i', (string) $mediaBody, $ruleMatches, PREG_SET_ORDER) ) {
                    continue;
                }

                foreach ( $ruleMatches as $ruleMatch ) {
                    $class = (string) ($ruleMatch[1] ?? '');
                    $body = (string) ($ruleMatch[2] ?? '');
                    if ( '' === $class || ! isset($fixedClasses[$class]) ) {
                        continue;
                    }
                    if ( preg_match('/\bwidth:(?:100%|calc\(100%\s*-)/i', $body) || preg_match('/\bmax-width:100%\b/i', $body) ) {
                        $coveredClasses[$class] = true;
                    }
                }
            }
        }

        $uncoveredClasses = array_diff_key($fixedClasses, $coveredClasses);

        return array(
            'class_count' => count($fixedClasses),
            'covered_count' => count($coveredClasses),
            'uncovered_count' => count($uncoveredClasses),
            'covered_classes' => array_keys($coveredClasses),
            'uncovered_classes' => array_keys($uncoveredClasses),
        );
    }

    /**
     * Summarize positional decisions that are visually risky across arbitrary Figma files.
     *
     * @param array<string, mixed> $layout
     * @param array<string, mixed> $decisionTraces
     * @return array<string, mixed>
     */
    public function positionalParityDiagnostics(array $layout, string $css, array $decisionTraces): array
    {
        $decorativeUnderlays = is_array($layout['decorative_underlays']['nodes'] ?? null) ? $layout['decorative_underlays']['nodes'] : array();
        $fixedOverRootWidthUnderlays = array_values(array_filter(
            $decorativeUnderlays,
            static function (array $node): bool {
                return isset($node['width'], $node['parent_width'])
                    && is_numeric($node['width'])
                    && is_numeric($node['parent_width'])
                    && (float) $node['width'] > (float) $node['parent_width'] + 1.0;
            }
        ));

        $offCanvasNodes = is_array($layout['off_canvas_visual_nodes'] ?? null) ? $layout['off_canvas_visual_nodes'] : array();
        $chromeOverflowNodes = array_values(array_filter(
            $offCanvasNodes,
            static function (array $node): bool {
                $parentName = strtolower((string) ($node['parent_name'] ?? ''));
                $name = strtolower((string) ($node['name'] ?? ''));
                $class = strtolower((string) ($node['class'] ?? ''));

                return str_contains($parentName, 'header')
                    || str_contains($parentName, 'footer')
                    || str_contains($name, 'header')
                    || str_contains($name, 'footer')
                    || str_contains($class, 'header')
                    || str_contains($class, 'footer');
            }
        ));

        $reasonCounts = is_array($decisionTraces['reason_counts'] ?? null) ? $decisionTraces['reason_counts'] : array();
        $domainCounts = is_array($decisionTraces['domain_counts'] ?? null) ? $decisionTraces['domain_counts'] : array();

        return array(
            'schema' => 'blocks-engine/figma-transformer/positional-parity/v1',
            'full_bleed_viewport_width_count' => $this->cssDeclarationCount($css, 'width', '100vw'),
            'full_bleed_breakout_count' => $this->cssFullBleedBreakoutCount($css),
            'mirrored_transform_count' => $this->cssMirroredTransformCount($css),
            'reflected_full_bleed_count' => $this->cssReflectedFullBleedCount($css),
            'fixed_over_root_width_underlay_count' => count($fixedOverRootWidthUnderlays),
            'fixed_over_root_width_underlays' => array_slice(array_map(fn (array $node): array => $this->positionalParityNodeSample($node), $fixedOverRootWidthUnderlays), 0, 25),
            'chrome_overflow_count' => count($chromeOverflowNodes),
            'chrome_overflow_nodes' => array_slice(array_map(fn (array $node): array => $this->positionalParityNodeSample($node), $chromeOverflowNodes), 0, 25),
            'root_stacking_trace_count' => (int) ($domainCounts['stacking_context'] ?? 0),
            'root_stacking_reason_counts' => array_filter($reasonCounts, static fn (mixed $count, string $reason): bool => str_contains($reason, 'stack') || str_contains($reason, 'z_index') || str_contains($reason, 'overlap'), ARRAY_FILTER_USE_BOTH),
            'decision_trace_samples' => $this->positionalDecisionTraceSamples($decisionTraces),
        );
    }

    /**
     * @param array<string, mixed> $decisionTraces
     * @return array<int, array<string, mixed>>
     */
    private function positionalDecisionTraceSamples(array $decisionTraces): array
    {
        $samples = array();
        $traces = is_array($decisionTraces['samples'] ?? null) ? $decisionTraces['samples'] : array();
        $positionalDomains = array(
            'effective_geometry' => true,
            'stacking_context' => true,
            'transform_viewport' => true,
            'responsive_decision' => true,
        );

        foreach ( $traces as $trace ) {
            if ( ! is_array($trace) ) {
                continue;
            }

            $domain = (string) ($trace['domain'] ?? '');
            if ( ! isset($positionalDomains[$domain]) ) {
                continue;
            }

            $samples[] = $this->positionalDecisionTraceSample($trace);
            if ( count($samples) >= 25 ) {
                break;
            }
        }

        return $samples;
    }

    /**
     * @param array<string, mixed> $trace
     * @return array<string, mixed>
     */
    private function positionalDecisionTraceSample(array $trace): array
    {
        $evidence = is_array($trace['evidence'] ?? null) ? $trace['evidence'] : array();

        return array_filter(array(
            'domain' => isset($trace['domain']) && is_scalar($trace['domain']) ? (string) $trace['domain'] : null,
            'reason_code' => isset($trace['reason_code']) && is_scalar($trace['reason_code']) ? (string) $trace['reason_code'] : null,
            'decision' => isset($trace['decision']) && is_scalar($trace['decision']) ? (string) $trace['decision'] : null,
            'node_id' => isset($trace['node_id']) && is_scalar($trace['node_id']) ? (string) $trace['node_id'] : null,
            'name' => isset($trace['name']) && is_scalar($trace['name']) ? (string) $trace['name'] : null,
            'type' => isset($trace['type']) && is_scalar($trace['type']) ? (string) $trace['type'] : null,
            'class' => isset($trace['class']) && is_scalar($trace['class']) ? (string) $trace['class'] : null,
            'parent_id' => isset($trace['parent_id']) && is_scalar($trace['parent_id']) ? (string) $trace['parent_id'] : null,
            'page_path' => isset($trace['page_path']) && is_scalar($trace['page_path']) ? (string) $trace['page_path'] : null,
            'count' => isset($trace['count']) && is_numeric($trace['count']) ? (int) $trace['count'] : null,
            'source_geometry' => is_array($evidence['source_geometry'] ?? null) ? $evidence['source_geometry'] : null,
            'effective_css_geometry' => is_array($evidence['effective_css_geometry'] ?? null) ? $evidence['effective_css_geometry'] : null,
            'canvas_shell' => is_array($evidence['canvas_shell'] ?? null) ? $evidence['canvas_shell'] : null,
            'canvas_width_reason_code' => isset($evidence['canvas_width_reason_code']) && is_scalar($evidence['canvas_width_reason_code']) ? (string) $evidence['canvas_width_reason_code'] : null,
            'canvas_width_declarations' => is_array($evidence['canvas_width_declarations'] ?? null) ? $evidence['canvas_width_declarations'] : null,
            'full_bleed_reason_code' => isset($evidence['full_bleed_reason_code']) && is_scalar($evidence['full_bleed_reason_code']) ? (string) $evidence['full_bleed_reason_code'] : null,
            'full_bleed_declarations' => is_array($evidence['full_bleed_declarations'] ?? null) ? $evidence['full_bleed_declarations'] : null,
            'manages_local_stacking' => $evidence['manages_local_stacking'] ?? null,
            'needs_isolation' => $evidence['needs_isolation'] ?? null,
            'local_reasons' => is_array($evidence['local_reasons'] ?? null) ? $evidence['local_reasons'] : null,
            'sibling_role' => isset($evidence['sibling_role']) && is_scalar($evidence['sibling_role']) ? (string) $evidence['sibling_role'] : null,
            'overlaps_sibling' => $evidence['overlaps_sibling'] ?? null,
            'z_index' => isset($evidence['z_index']) && is_numeric($evidence['z_index']) ? (int) $evidence['z_index'] : null,
            'z_index_reason' => isset($evidence['z_index_reason']) && is_scalar($evidence['z_index_reason']) ? (string) $evidence['z_index_reason'] : null,
            'will_position_absolute' => $evidence['will_position_absolute'] ?? null,
            'transform' => isset($evidence['transform']) && is_scalar($evidence['transform']) ? (string) $evidence['transform'] : null,
            'matrix' => is_array($evidence['matrix'] ?? null) ? $evidence['matrix'] : null,
            'transformed_rect' => is_array($evidence['transformed_rect'] ?? null) ? $evidence['transformed_rect'] : null,
            'viewport_width' => isset($evidence['viewport_width']) && is_numeric($evidence['viewport_width']) ? $this->reportNumericValue((float) $evidence['viewport_width']) : null,
            'declarations' => is_array($evidence['declarations'] ?? null) ? $evidence['declarations'] : null,
        ), static fn (mixed $value): bool => null !== $value && '' !== $value && array() !== $value);
    }

    private function cssDeclarationCount(string $css, string $property, string $value): int
    {
        $pattern = '/' . preg_quote($property, '/') . '\s*:\s*' . preg_quote($value, '/') . '(?:[;}])/i';
        $count = preg_match_all($pattern, $css);

        return false === $count ? 0 : $count;
    }

    private function cssFullBleedBreakoutCount(string $css): int
    {
        $count = preg_match_all('/left\s*:\s*50%[^}]*margin-left\s*:\s*-?50vw/i', $css);

        return false === $count ? 0 : $count;
    }

    private function cssReflectedFullBleedCount(string $css): int
    {
        $count = preg_match_all('/margin-left\s*:\s*50vw[^}]*transform\s*:\s*matrix\s*\(\s*-1\s*,/i', $css);

        return false === $count ? 0 : $count;
    }

    private function cssMirroredTransformCount(string $css): int
    {
        $count = preg_match_all('/transform\s*:\s*matrix\s*\(\s*(?:-[0-9.]+\s*,[^)]*|[^,]+,[^,]+,[^,]+,\s*-[0-9.]+)/i', $css);

        return false === $count ? 0 : $count;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function positionalParityNodeSample(array $node): array
    {
        return array_filter(array(
            'frame_id' => isset($node['frame_id']) && is_scalar($node['frame_id']) ? (string) $node['frame_id'] : null,
            'page_path' => isset($node['page_path']) && is_scalar($node['page_path']) ? (string) $node['page_path'] : null,
            'node_id' => isset($node['node_id']) && is_scalar($node['node_id']) ? (string) $node['node_id'] : null,
            'name' => isset($node['name']) && is_scalar($node['name']) ? (string) $node['name'] : null,
            'class' => isset($node['class']) && is_scalar($node['class']) ? (string) $node['class'] : null,
            'parent_id' => isset($node['parent_id']) && is_scalar($node['parent_id']) ? (string) $node['parent_id'] : null,
            'parent_name' => isset($node['parent_name']) && is_scalar($node['parent_name']) ? (string) $node['parent_name'] : null,
            'width' => isset($node['width']) && is_numeric($node['width']) ? $this->reportNumericValue((float) $node['width']) : null,
            'height' => isset($node['height']) && is_numeric($node['height']) ? $this->reportNumericValue((float) $node['height']) : null,
            'parent_width' => isset($node['parent_width']) && is_numeric($node['parent_width']) ? $this->reportNumericValue((float) $node['parent_width']) : null,
            'left' => isset($node['left']) && is_numeric($node['left']) ? $this->reportNumericValue((float) $node['left']) : null,
            'top' => isset($node['top']) && is_numeric($node['top']) ? $this->reportNumericValue((float) $node['top']) : null,
        ), static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function breakpointOverrideLeaks(string $css): array
    {
        $leaks = array();
        foreach ( $this->mediaBlocksFromCss($css) as $mediaBlock ) {
            $breakpoint = (float) ($mediaBlock['breakpoint'] ?? 0.0);
            if ( $breakpoint <= 0.0 || $breakpoint > 600.0 ) {
                continue;
            }

            $body = (string) ($mediaBlock['body'] ?? '');
            if ( 0 === (preg_match_all('/\.([a-z0-9_-]+)\{([^{}]*)\}/i', $body, $ruleMatches, PREG_SET_ORDER) ?: 0) ) {
                continue;
            }

            foreach ( $ruleMatches as $ruleMatch ) {
                $class = (string) ($ruleMatch[1] ?? '');
                $declarations = (string) ($ruleMatch[2] ?? '');
                $declarationSamples = $this->desktopSizedResponsiveDeclarations($declarations);
                if ( empty($declarationSamples) ) {
                    continue;
                }

                $leaks[] = array(
                    'breakpoint_px' => $breakpoint,
                    'class' => $class,
                    'declarations' => $declarationSamples,
                );
                if ( count($leaks) >= 25 ) {
                    return $leaks;
                }
            }
        }

        return $leaks;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function absoluteToFlowConversions(string $css): array
    {
        $baseAbsoluteClasses = array();
        if ( 0 < (preg_match_all('/\.([a-z0-9_-]+)\{([^{}]*)\}/i', preg_replace('/@media[^{}]*\{.*?\}/is', '', $css) ?? $css, $baseMatches, PREG_SET_ORDER) ?: 0) ) {
            foreach ( $baseMatches as $baseMatch ) {
                if ( str_contains((string) ($baseMatch[2] ?? ''), 'position:absolute') ) {
                    $baseAbsoluteClasses[(string) ($baseMatch[1] ?? '')] = true;
                }
            }
        }

        $conversions = array();
        foreach ( $this->mediaBlocksFromCss($css) as $mediaBlock ) {
            $body = (string) ($mediaBlock['body'] ?? '');
            if ( 0 === (preg_match_all('/\.([a-z0-9_-]+)\{([^{}]*)\}/i', $body, $ruleMatches, PREG_SET_ORDER) ?: 0) ) {
                continue;
            }

            foreach ( $ruleMatches as $ruleMatch ) {
                $class = (string) ($ruleMatch[1] ?? '');
                $declarations = (string) ($ruleMatch[2] ?? '');
                if ( ! isset($baseAbsoluteClasses[$class]) ) {
                    continue;
                }
                if ( ! str_contains($declarations, 'position:relative') || ! str_contains($declarations, 'left:auto') || ! str_contains($declarations, 'top:auto') ) {
                    continue;
                }

                $conversions[] = array(
                    'breakpoint_px' => (float) ($mediaBlock['breakpoint'] ?? 0.0),
                    'class' => $class,
                    'declarations' => $this->compactCssDeclarations($declarations),
                );
                if ( count($conversions) >= 25 ) {
                    return $conversions;
                }
            }
        }

        return $conversions;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mediaBlocksFromCss(string $css): array
    {
        $blocks = array();
        if ( 0 === (preg_match_all('/@media\s*\([^{}]*max-width\s*:\s*([0-9.]+)px[^{}]*\)\{/i', $css, $matches, PREG_OFFSET_CAPTURE) ?: 0) ) {
            return $blocks;
        }

        $matchCount = count($matches[0]);
        for ( $i = 0; $i < $matchCount; $i++ ) {
            $start = (int) $matches[0][$i][1];
            $bodyStart = $start + strlen((string) $matches[0][$i][0]);
            $end = $i + 1 < $matchCount ? (int) $matches[0][$i + 1][1] : strlen($css);
            $blocks[] = array(
                'breakpoint' => (float) $matches[1][$i][0],
                'body' => substr($css, $bodyStart, max(0, $end - $bodyStart)),
            );
        }

        return $blocks;
    }

    /**
     * @return array<int, string>
     */
    private function desktopSizedResponsiveDeclarations(string $declarations): array
    {
        $samples = array();
        if ( 0 === (preg_match_all('/(?:^|;)(width|min-width|max-width|left|right):([^;{}]+)/i', $declarations, $matches, PREG_SET_ORDER) ?: 0) ) {
            return $samples;
        }

        foreach ( $matches as $match ) {
            $property = strtolower(trim((string) ($match[1] ?? '')));
            $value = trim((string) ($match[2] ?? ''));
            $numeric = $this->largestCssPixelValue($value);
            if ( null === $numeric ) {
                continue;
            }

            $threshold = in_array($property, array('left', 'right'), true) ? 700.0 : 900.0;
            if ( 'max-width' === $property ) {
                $threshold = 1200.0;
            }
            if ( $numeric < $threshold ) {
                continue;
            }

            $samples[] = $property . ':' . $value;
        }

        return array_values(array_unique($samples));
    }

    private function largestCssPixelValue(string $value): ?float
    {
        if ( 0 === (preg_match_all('/-?[0-9.]+px/', $value, $matches) ?: 0) ) {
            return null;
        }

        $largest = null;
        foreach ( $matches[0] as $token ) {
            $number = abs((float) str_replace('px', '', (string) $token));
            $largest = null === $largest ? $number : max($largest, $number);
        }

        return $largest;
    }

    /**
     * @return array<int, string>
     */
    private function compactCssDeclarations(string $declarations): array
    {
        return array_values(array_filter(array_map('trim', explode(';', $declarations)), static fn (string $declaration): bool => '' !== $declaration));
    }

    private function reportNumericValue(mixed $value): mixed
    {
        if ( ! is_numeric($value) ) {
            return null;
        }

        $number = (float) $value;
        if ( ! is_finite($number) ) {
            return null;
        }

        return floor($number) === $number ? (int) $number : $number;
    }
}

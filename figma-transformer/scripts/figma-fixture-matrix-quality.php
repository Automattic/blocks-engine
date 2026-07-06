<?php

declare(strict_types=1);

/**
 * @param array<int, array<string, mixed>> $fixtures
 * @return array<string, mixed>
 */
function matrix_quality_matrix(array $fixtures): array
{
    $keys = array(
        'missing_asset_nodes',
        'vector_placeholders',
        'image_block_count',
        'vector_image_fallbacks',
        'media_query_count',
        'fixed_width_over_desktop_count',
        'fixed_width_declaration_count',
        'fixed_width_without_responsive_override_count',
        'giant_fixed_section_count',
        'large_overflow_risk_count',
        'fallback_prone_form_island_count',
        'fallback_prone_svg_island_count',
        'fallback_prone_input_island_count',
        'invalid_list_child_count',
        'missing_semantic_role_count',
        'missing_emitted_text_nodes',
        'layout_mismatch_count',
        'render_style_mismatch_count',
        'link_targets_unresolved',
        'invalid_css_numeric_tokens',
        'breakpoint_override_leak_count',
        'large_absolute_offset_count',
        'large_css_offset_count',
        'large_negative_left_count',
        'off_canvas_visual_node_count',
        'clipped_visual_node_count',
        'fixed_width_over_desktop_uncovered_count',
        'uncomposed_vector_child_nodes',
    );
    $totals = array_fill_keys($keys, 0);
    $qualityStatuses = array();
    $signalCounts = array();
    $riskBucketCounts = array_fill_keys(array('low', 'medium', 'high', 'critical', 'unknown'), 0);
    $riskCategoryTotals = array_fill_keys(array('responsive_coverage', 'absolute_scaffolding', 'text_wrapping_leaks', 'image_geometry_fidelity', 'form_validity', 'route_coverage', 'unsupported_vectors'), 0);
    $perFixtureReadiness = array();
    $coverageNumerator = 0;
    $coverageDenominator = 0;
    $routeCoverageNumerator = 0;
    $routeCoverageDenominator = 0;

    foreach ( $fixtures as $fixture ) {
        if ( ! is_array($fixture) ) {
            continue;
        }
        $summary = is_array($fixture['quality_summary'] ?? null) ? $fixture['quality_summary'] : array();
        foreach ( $keys as $key ) {
            $totals[$key] += matrix_quality_summary_int($summary, $key);
        }
        $coverageNumerator += (int) ($summary['fixed_width_with_responsive_override_count'] ?? 0);
        $coverageDenominator += (int) ($summary['fixed_width_declaration_count'] ?? 0);
        $selectedRouteCount = count(is_array($fixture['selected_frame_ids'] ?? null) ? $fixture['selected_frame_ids'] : array());
        $omittedRouteCount = count(is_array($fixture['omitted_page_candidates'] ?? null) ? $fixture['omitted_page_candidates'] : array());
        $routeCoverageNumerator += $selectedRouteCount;
        $routeCoverageDenominator += $selectedRouteCount + $omittedRouteCount;
        $status = isset($fixture['quality_status']) && is_scalar($fixture['quality_status']) ? (string) $fixture['quality_status'] : 'unknown';
        $qualityStatuses[$status] = ($qualityStatuses[$status] ?? 0) + 1;
        foreach ( is_array($fixture['artifact_quality']['signals'] ?? null) ? $fixture['artifact_quality']['signals'] : array() as $signal ) {
            if ( ! is_array($signal) || ! isset($signal['code']) || ! is_scalar($signal['code']) ) {
                continue;
            }
            $code = (string) $signal['code'];
            $signalCounts[$code] = ($signalCounts[$code] ?? 0) + 1;
        }

        $readiness = is_array($fixture['visual_readiness'] ?? null) ? $fixture['visual_readiness'] : matrix_fixture_visual_readiness($fixture);
        $bucket = isset($readiness['visual_risk_bucket']) && is_scalar($readiness['visual_risk_bucket']) ? (string) $readiness['visual_risk_bucket'] : 'unknown';
        $riskBucketCounts[$bucket] = ($riskBucketCounts[$bucket] ?? 0) + 1;
        foreach ( $riskCategoryTotals as $category => $_count ) {
            $riskCategoryTotals[$category] += (int) ($readiness['risk_categories'][$category]['count'] ?? 0);
        }
        $perFixtureReadiness[] = array(
            'id' => isset($fixture['id']) && is_scalar($fixture['id']) ? (string) $fixture['id'] : '',
            'status' => $fixture['status'] ?? null,
            'readiness_score' => $readiness['readiness_score'] ?? null,
            'visual_risk_bucket' => $readiness['visual_risk_bucket'] ?? 'unknown',
            'route_coverage_ratio' => $readiness['route_coverage_ratio'] ?? null,
            'risk_categories' => $readiness['risk_categories'] ?? array(),
        );
    }

    ksort($qualityStatuses);
    ksort($signalCounts);
    ksort($riskBucketCounts);

    return array(
        'schema' => 'blocks-engine/figma-transformer/fixture-matrix-quality/v1',
        'fixture_count' => count($fixtures),
        'quality_status_counts' => $qualityStatuses,
        'signal_counts' => $signalCounts,
        'visual_risk_bucket_counts' => $riskBucketCounts,
        'risk_category_totals' => $riskCategoryTotals,
        'per_fixture_readiness' => $perFixtureReadiness,
        'effective_responsive_coverage_ratio' => $coverageDenominator > 0 ? round($coverageNumerator / $coverageDenominator, 3) : 1.0,
        'route_coverage_ratio' => $routeCoverageDenominator > 0 ? round($routeCoverageNumerator / $routeCoverageDenominator, 3) : 1.0,
        'totals' => $totals,
    );
}

/**
 * @param array<string, mixed> $fixture
 * @return array<string, mixed>
 */
function matrix_fixture_visual_readiness(array $fixture): array
{
    $summary = is_array($fixture['quality_summary'] ?? null) ? $fixture['quality_summary'] : array();
    $selectedRouteCount = count(is_array($fixture['selected_frame_ids'] ?? null) ? $fixture['selected_frame_ids'] : array());
    $omittedRouteCount = count(is_array($fixture['omitted_page_candidates'] ?? null) ? $fixture['omitted_page_candidates'] : array());
    $routeCoverageRatio = ($selectedRouteCount + $omittedRouteCount) > 0 ? round($selectedRouteCount / ($selectedRouteCount + $omittedRouteCount), 3) : 1.0;

    $categories = array(
        'responsive_coverage' => matrix_risk_category(
            matrix_quality_summary_int($summary, 'fixed_width_without_responsive_override_count')
                + matrix_quality_summary_int($summary, 'fixed_width_over_desktop_uncovered_count')
                + (true === ($summary['desktop_canvas_without_responsive_breakpoints'] ?? null) ? 1 : 0),
            array('fixed_width_without_responsive_override_count', 'fixed_width_over_desktop_uncovered_count', 'desktop_canvas_without_responsive_breakpoints')
        ),
        'absolute_scaffolding' => matrix_risk_category(
            matrix_quality_summary_int($summary, 'large_absolute_offset_count')
                + matrix_quality_summary_int($summary, 'large_css_offset_count')
                + matrix_quality_summary_int($summary, 'large_negative_left_count')
                + matrix_quality_summary_int($summary, 'off_canvas_visual_node_count')
                + matrix_quality_summary_int($summary, 'clipped_visual_node_count')
                + matrix_quality_summary_int($summary, 'giant_fixed_section_count')
                + matrix_quality_summary_int($summary, 'large_overflow_risk_count'),
            array('large_absolute_offset_count', 'large_css_offset_count', 'large_negative_left_count', 'off_canvas_visual_node_count', 'clipped_visual_node_count', 'giant_fixed_section_count', 'large_overflow_risk_count')
        ),
        'text_wrapping_leaks' => matrix_risk_category(
            matrix_quality_summary_int($summary, 'missing_emitted_text_nodes')
                + matrix_quality_summary_int($summary, 'breakpoint_override_leak_count')
                + matrix_quality_summary_int($summary, 'layout_mismatch_count')
                + matrix_quality_summary_int($summary, 'render_style_mismatch_count'),
            array('missing_emitted_text_nodes', 'breakpoint_override_leak_count', 'layout_mismatch_count', 'render_style_mismatch_count')
        ),
        'image_geometry_fidelity' => matrix_risk_category(
            matrix_quality_summary_int($summary, 'missing_asset_nodes')
                + matrix_quality_summary_int($summary, 'vector_image_fallbacks')
                + matrix_quality_summary_int($summary, 'image_heavy_landmark_candidates'),
            array('missing_asset_nodes', 'vector_image_fallbacks', 'image_heavy_landmark_candidates')
        ),
        'form_validity' => matrix_risk_category(
            matrix_quality_summary_int($summary, 'fallback_prone_form_island_count')
                + matrix_quality_summary_int($summary, 'fallback_prone_input_island_count')
                + matrix_quality_summary_int($summary, 'invalid_list_child_count')
                + matrix_quality_summary_int($summary, 'missing_semantic_role_count'),
            array('fallback_prone_form_island_count', 'fallback_prone_input_island_count', 'invalid_list_child_count', 'missing_semantic_role_count')
        ),
        'route_coverage' => matrix_risk_category(
            matrix_quality_summary_int($summary, 'link_targets_unresolved') + $omittedRouteCount,
            array('link_targets_unresolved', 'omitted_page_candidates')
        ),
        'unsupported_vectors' => matrix_risk_category(
            matrix_quality_summary_int($summary, 'vector_placeholders')
                + matrix_quality_summary_int($summary, 'uncomposed_vector_child_nodes')
                + ((float) ($summary['vector_decode_coverage_ratio'] ?? 1.0) < 1.0 ? 1 : 0),
            array('vector_placeholders', 'uncomposed_vector_child_nodes', 'vector_decode_coverage_ratio')
        ),
    );

    $riskPoints = 0;
    foreach ( $categories as $category ) {
        $riskPoints += min(25, (int) ($category['count'] ?? 0));
    }
    $readinessScore = max(0, 100 - $riskPoints);

    return array(
        'schema' => 'blocks-engine/figma-transformer/fixture-visual-readiness/v1',
        'readiness_score' => $readinessScore,
        'visual_risk_bucket' => matrix_visual_risk_bucket($readinessScore),
        'route_coverage_ratio' => $routeCoverageRatio,
        'risk_categories' => $categories,
    );
}

function matrix_visual_risk_bucket(int $readinessScore): string
{
    if ( $readinessScore >= 90 ) {
        return 'low';
    }
    if ( $readinessScore >= 75 ) {
        return 'medium';
    }
    if ( $readinessScore >= 50 ) {
        return 'high';
    }

    return 'critical';
}

/**
 * @param array<int, string> $signals
 * @return array{count: int, signals: array<int, string>}
 */
function matrix_risk_category(int $count, array $signals): array
{
    return array(
        'count' => $count,
        'signals' => $signals,
    );
}

/**
 * @param array<string, mixed> $summary
 */
function matrix_quality_summary_int(array $summary, string $key): int
{
    if ( isset($summary[$key]) && is_numeric($summary[$key]) ) {
        return (int) $summary[$key];
    }

    if ( isset($summary['html_artifact']) && is_array($summary['html_artifact']) && isset($summary['html_artifact'][$key]) && is_numeric($summary['html_artifact'][$key]) ) {
        return (int) $summary['html_artifact'][$key];
    }

    return 0;
}

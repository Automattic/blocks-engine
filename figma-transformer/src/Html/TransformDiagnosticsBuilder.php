<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Builds aggregate transform diagnostic summaries from collected emitter data.
 */
final class TransformDiagnosticsBuilder
{
    /**
     * @param array<string, mixed> $image
     * @param array<string, mixed> $vectors
     * @param array<string, mixed> $fonts
     * @param array<string, mixed> $assets
     * @param array<string, mixed> $generatedSvgAssets
     * @param array<string, mixed> $layout
     * @param array<string, mixed> $links
     * @param array<string, mixed> $text
     * @param array<string, mixed> $components
     * @param array<string, mixed> $effects
     * @param array<string, mixed> $maskEffectClipping
     * @return array<string, mixed>
     */
    public function artifactQualityDiagnostics(array $image, array $vectors, array $fonts, array $assets, array $generatedSvgAssets, array $layout, array $links = array(), array $text = array(), array $components = array(), array $effects = array(), array $maskEffectClipping = array()): array
    {
        $signals = array();

        if ( ! empty($image['missing_assets']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'missing_render_assets',
                'count' => count($image['missing_assets']),
            );
        }
        if ( ! empty($vectors['placeholders']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'vector_placeholders',
                'count' => (int) $vectors['placeholders'],
            );
        }
        if ( ! empty($fonts['missing_css']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'font_css_missing',
                'count' => count($fonts['missing_css']),
                'font_usage' => $this->fontUsageForFamilies(is_array($fonts['usage'] ?? null) ? $fonts['usage'] : array(), $fonts['missing_css']),
            );
        }
        if ( ! empty($layout['large_negative_left_count']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'off_canvas_left_css',
                'count' => (int) $layout['large_negative_left_count'],
                'sample_nodes' => array_slice(is_array($layout['large_css_offset_nodes'] ?? null) ? $layout['large_css_offset_nodes'] : array(), 0, 10),
            );
        }
        if ( ! empty($layout['large_css_offset_count']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'large_css_offsets',
                'count' => (int) $layout['large_css_offset_count'],
                'sample_nodes' => array_slice(is_array($layout['large_css_offset_nodes'] ?? null) ? $layout['large_css_offset_nodes'] : array(), 0, 10),
            );
        }
        if ( ! empty($layout['off_canvas_visual_node_count']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'off_canvas_visual_nodes',
                'count' => (int) $layout['off_canvas_visual_node_count'],
                'sample_nodes' => array_slice(is_array($layout['off_canvas_visual_nodes'] ?? null) ? $layout['off_canvas_visual_nodes'] : array(), 0, 10),
            );
        }
        if ( ! empty($layout['clipped_visual_node_count']) && (float) ($layout['clipped_visual_area_ratio'] ?? 0.0) >= 0.25 ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'clipped_visual_area',
                'count' => (int) $layout['clipped_visual_node_count'],
                'clipped_area_ratio' => (float) ($layout['clipped_visual_area_ratio'] ?? 0.0),
                'sample_nodes' => array_slice(is_array($layout['clipped_visual_nodes'] ?? null) ? $layout['clipped_visual_nodes'] : array(), 0, 10),
            );
        }
        if ( ! empty($layout['large_absolute_offset_count']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'large_absolute_offsets',
                'count' => (int) $layout['large_absolute_offset_count'],
                'sample_nodes' => array_slice(is_array($layout['large_absolute_offset_nodes'] ?? null) ? $layout['large_absolute_offset_nodes'] : array(), 0, 10),
            );
        }
        if ( ! empty($layout['image_heavy_landmark_candidates']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'image_heavy_landmark_candidate',
                'count' => count($layout['image_heavy_landmark_candidates']),
            );
        }
        $imageBlockCount = (int) ($image['image_block_count'] ?? 0);
        $totalNodeCount = max(0, (int) ($image['total_node_count'] ?? 0));
        $imageNodeDensity = $totalNodeCount > 0 ? $imageBlockCount / $totalNodeCount : 0.0;
        if ( $imageBlockCount >= 12 && ($imageNodeDensity >= 0.35 || ! empty($layout['image_heavy_landmark_candidates'])) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'excessive_image_blocks',
                'count' => $imageBlockCount,
                'threshold' => 12,
                'image_node_density' => round($imageNodeDensity, 3),
                'sample_nodes' => array_slice(is_array($image['image_block_nodes'] ?? null) ? $image['image_block_nodes'] : array(), 0, 10),
            );
        }
        if ( (int) ($vectors['rendered_asset_fallbacks'] ?? 0) >= 8 ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'excessive_vector_image_fallbacks',
                'count' => (int) $vectors['rendered_asset_fallbacks'],
            );
        }
        if ( (int) ($generatedSvgAssets['bytes'] ?? 0) > 1048576 ) {
            $signals[] = array(
                'severity' => 'info',
                'code' => 'large_generated_svg_assets',
                'count' => (int) ($generatedSvgAssets['count'] ?? 0),
                'bytes' => (int) ($generatedSvgAssets['bytes'] ?? 0),
            );
        }
        if ( ! empty($links['unresolved']) ) {
            $signals[] = array(
                'severity' => 'info',
                'code' => 'link_target_unresolved',
                'count' => (int) $links['unresolved'],
                'sample_nodes' => array_slice(is_array($links['unresolved_targets'] ?? null) ? $links['unresolved_targets'] : array(), 0, 10),
            );
        }
        if ( ! empty($text['missing_emitted_text_node_count']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'decoded_text_not_emitted',
                'count' => (int) $text['missing_emitted_text_node_count'],
                'sample_nodes' => array_slice(is_array($text['missing_emitted_text_nodes'] ?? null) ? $text['missing_emitted_text_nodes'] : array(), 0, 10),
            );
        }
        if ( ! empty($text['empty_decoded_text_node_count']) ) {
            $signals[] = array(
                'severity' => 'info',
                'code' => 'decoded_text_empty',
                'count' => (int) $text['empty_decoded_text_node_count'],
                'sample_nodes' => array_slice(is_array($text['empty_decoded_text_nodes'] ?? null) ? $text['empty_decoded_text_nodes'] : array(), 0, 10),
            );
        }
        if ( ! empty($components['missing_emitted_clone_node_count']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'component_clone_not_emitted',
                'count' => (int) $components['missing_emitted_clone_node_count'],
                'sample_nodes' => array_slice(is_array($components['missing_emitted_clone_nodes'] ?? null) ? $components['missing_emitted_clone_nodes'] : array(), 0, 10),
            );
        }
        if ( ! empty($effects['missing_emitted_effect_node_count']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'effect_node_not_emitted',
                'count' => (int) $effects['missing_emitted_effect_node_count'],
                'sample_nodes' => array_slice(is_array($effects['missing_emitted_effect_nodes'] ?? null) ? $effects['missing_emitted_effect_nodes'] : array(), 0, 10),
            );
        }
        if ( ! empty($vectors['child_composition']['uncomposed_vector_child_node_count']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'vector_child_composition_incomplete',
                'count' => (int) $vectors['child_composition']['uncomposed_vector_child_node_count'],
                'sample_nodes' => array_slice(is_array($vectors['child_composition']['sample_nodes'] ?? null) ? $vectors['child_composition']['sample_nodes'] : array(), 0, 10),
            );
        }

        $failCodes = array('missing_render_assets', 'vector_placeholders');
        $failCount = count(array_filter($signals, static fn (array $signal): bool => in_array((string) ($signal['code'] ?? ''), $failCodes, true)));
        $warningCount = count(array_filter($signals, static fn (array $signal): bool => 'warning' === ($signal['severity'] ?? null)));
        $qualityStatus = $failCount > 0 ? 'fail' : (empty($signals) ? 'pass' : 'warn');

        return array(
            'schema' => 'blocks-engine/figma-transformer/artifact-quality/v1',
            'status' => $warningCount > 0 ? 'needs_review' : (empty($signals) ? 'clean' : 'info'),
            'quality_status' => $qualityStatus,
            'signals' => $signals,
            'summary' => array(
                'missing_asset_nodes' => count($image['missing_assets'] ?? array()),
                'vector_placeholders' => (int) ($vectors['placeholders'] ?? 0),
                'missing_font_css' => count($fonts['missing_css'] ?? array()),
                'emitted_asset_files' => (int) ($assets['emitted_files'] ?? 0),
                'image_block_count' => $imageBlockCount,
                'image_node_density' => round($imageNodeDensity, 3),
                'total_node_count' => $totalNodeCount,
                'vector_image_fallbacks' => (int) ($vectors['rendered_asset_fallbacks'] ?? 0),
                'vector_nodes' => (int) ($vectors['nodes'] ?? 0),
                'vector_decoded_to_svg' => (int) ($vectors['rendered_paths'] ?? 0),
                'vector_network_decoded' => (int) ($vectors['vector_network_decoded'] ?? 0),
                'boolean_operations_composed' => (int) ($vectors['boolean_operations_composed'] ?? 0),
                'vector_decode_coverage_ratio' => (float) ($vectors['decode_coverage']['coverage_ratio'] ?? 0.0),
                'vector_placeholder_reason_categories' => is_array($vectors['decode_coverage']['placeholder_reason_categories'] ?? null) ? $vectors['decode_coverage']['placeholder_reason_categories'] : array(),
                'generated_svg_count' => (int) ($vectors['rendered_paths'] ?? 0),
                'externalized_svg_asset_count' => (int) ($generatedSvgAssets['count'] ?? 0),
                'generated_svg_bytes' => (int) ($generatedSvgAssets['bytes'] ?? 0),
                'large_negative_left_count' => (int) ($layout['large_negative_left_count'] ?? 0),
                'large_css_offset_count' => (int) ($layout['large_css_offset_count'] ?? 0),
                'off_canvas_visual_node_count' => (int) ($layout['off_canvas_visual_node_count'] ?? 0),
                'clipped_visual_node_count' => (int) ($layout['clipped_visual_node_count'] ?? 0),
                'clipped_visual_area_ratio' => (float) ($layout['clipped_visual_area_ratio'] ?? 0.0),
                'large_absolute_offset_count' => (int) ($layout['large_absolute_offset_count'] ?? 0),
                'empty_visible_container_count' => (int) ($layout['empty_visible_container_count'] ?? 0),
                'empty_visible_container_blocker_count' => (int) ($layout['empty_visible_container_blocker_count'] ?? 0),
                'decoded_text_nodes' => (int) ($text['decoded_text_node_count'] ?? 0),
                'emitted_text_nodes' => (int) ($text['emitted_text_node_count'] ?? 0),
                'empty_decoded_text_nodes' => (int) ($text['empty_decoded_text_node_count'] ?? 0),
                'missing_emitted_text_nodes' => (int) ($text['missing_emitted_text_node_count'] ?? 0),
                'image_heavy_landmark_candidates' => count($layout['image_heavy_landmark_candidates'] ?? array()),
                'layout_mismatch_count' => (int) ($layout['layout_mismatch_count'] ?? 0),
                'layout_mismatch_status' => (string) ($layout['layout_mismatch_status'] ?? 'not_evaluated'),
                'link_sources_found' => (int) ($links['sources_found'] ?? 0),
                'anchors_emitted' => (int) ($links['anchors_emitted'] ?? 0),
                'link_targets_unresolved' => (int) ($links['unresolved'] ?? 0),
                'component_clone_source_nodes' => (int) ($components['clone_source_node_count'] ?? 0),
                'component_clone_nodes_emitted' => (int) ($components['emitted_clone_node_count'] ?? 0),
                'component_override_candidates' => (int) ($components['override_candidate_node_count'] ?? 0),
                'component_overrides_applied' => (int) ($components['override_applied_node_count'] ?? 0),
                'effect_source_nodes' => (int) ($effects['source_effect_node_count'] ?? 0),
                'effect_nodes_emitted' => (int) ($effects['emitted_effect_node_count'] ?? 0),
                'mask_nodes' => (int) ($maskEffectClipping['mask_node_count'] ?? 0),
                'clips_content_nodes' => (int) ($maskEffectClipping['clips_content_node_count'] ?? 0),
                'clipped_effect_nodes' => (int) ($maskEffectClipping['clipped_effect_node_count'] ?? 0),
                'mixed_positioning_parent_count' => (int) ($layout['stacking_order']['mixed_positioning_parent_count'] ?? 0),
                'uncomposed_vector_child_nodes' => (int) ($vectors['child_composition']['uncomposed_vector_child_node_count'] ?? 0),
            ),
        );
    }

    /**
     * Summarize vector-decode coverage: how many vector-like nodes became real
     * inline SVG geometry versus how many remain placeholders, with the remaining
     * placeholders grouped into actionable reason categories.
     *
     * @param array<string, mixed> $vectors
     * @return array<string, mixed>
     */
    public function vectorDecodeCoverage(array $vectors): array
    {
        $nodes = (int) ($vectors['nodes'] ?? 0);
        $decoded = (int) ($vectors['rendered_paths'] ?? 0);
        $assetFallbacks = (int) ($vectors['rendered_asset_fallbacks'] ?? 0);
        $networkDecoded = (int) ($vectors['vector_network_decoded'] ?? 0);
        $booleanComposed = (int) ($vectors['boolean_operations_composed'] ?? 0);
        $placeholders = (int) ($vectors['placeholders'] ?? 0);
        $reasons = is_array($vectors['placeholder_reasons'] ?? null) ? $vectors['placeholder_reasons'] : array();

        $categoryByReason = array(
            'missing_vector_geometry'                => 'no_geometry_available',
            'missing_dimensions'                     => 'no_geometry_available',
            'unsupported_vector_network_blob'        => 'vector_network_blob_unsupported',
            'unsupported_path_data'                  => 'path_data_unsupported',
            'oversized_path_data'                    => 'path_data_unsupported',
            'unsupported_vector_geometry'            => 'path_data_unsupported',
            'unsupported_boolean_operation_children' => 'boolean_operation_unsupported',
            'unresolved_asset_fallback'              => 'asset_unresolved',
        );

        $categories = array();
        foreach ( $reasons as $reason => $count ) {
            $category = $categoryByReason[(string) $reason] ?? 'other';
            $categories[$category] = (int) ($categories[$category] ?? 0) + (int) $count;
        }
        ksort($categories);

        return array(
            'schema'                     => 'blocks-engine/figma-transformer/vector-decode-coverage/v1',
            'vector_nodes'               => $nodes,
            'decoded_to_svg'             => $decoded,
            'vector_network_decoded'     => $networkDecoded,
            'boolean_operations_composed' => $booleanComposed,
            'asset_fallbacks'            => $assetFallbacks,
            'placeholders'               => $placeholders,
            'coverage_ratio'             => $nodes > 0 ? round($decoded / $nodes, 3) : 0.0,
            'placeholder_reasons'        => $reasons,
            'placeholder_reason_categories' => $categories,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $fontUsage
     * @param array<int, string> $families
     * @return array<int, array<string, mixed>>
     */
    private function fontUsageForFamilies(array $fontUsage, array $families): array
    {
        $wanted = array_fill_keys(array_map('strtolower', $families), true);
        return array_values(array_filter(
            $fontUsage,
            static fn (array $usage): bool => isset($wanted[strtolower((string) ($usage['family'] ?? ''))])
        ));
    }
}

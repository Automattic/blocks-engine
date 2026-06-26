<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

final class FallbackDiagnostic
{
    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $provenance
     * @return array<string, mixed>
     */
    public static function build(array $fields, array $provenance = array()): array
    {
        return array_merge(self::defaults($fields), $fields, $provenance);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function defaults(array $fields): array
    {
        $code = (string) ($fields['diagnostic_code'] ?? '');

        return match ( $code ) {
            'html_form_fallback' => array(
                'severity'              => 'warning',
                'runtime_requirement'   => 'server_or_client_form_handler',
                'recoverability'        => 'recoverable_with_runtime_mapping',
                'actionability'         => 'map_form_action_controls_and_submission_handler',
                'suggested_primitive'   => 'form',
                'materialization_hint'  => 'preserve_form_markup_or_replace_with_form_block_integration',
            ),
            'html_script_fallback' => array(
                'severity'              => 'warning',
                'runtime_requirement'   => 'client_script_execution',
                'recoverability'        => 'recoverable_with_script_enqueue_or_component_runtime',
                'actionability'         => 'review_script_source_and_enqueue_or_rebuild_behavior',
                'suggested_primitive'   => 'script_asset',
                'materialization_hint'  => 'enqueue_script_or_rebuild_as_interactive_block',
            ),
            'html_inline_svg_fallback' => array(
                'severity'              => 'info',
                'runtime_requirement'   => 'none',
                'recoverability'        => 'recoverable_as_static_markup_or_image_asset',
                'actionability'         => 'review_sanitized_svg_and_materialize_as_image_or_html',
                'suggested_primitive'   => 'image_or_html',
                'materialization_hint'  => 'materialize_safe_svg_as_image_asset_or_core_html',
            ),
            'html_unsafe_inline_svg' => array(
                'severity'              => 'warning',
                'runtime_requirement'   => 'sanitization_review',
                'recoverability'        => 'recoverable_after_security_review',
                'actionability'         => 'remove_scriptable_svg_content_or_replace_with_safe_asset',
                'suggested_primitive'   => 'image_asset',
                'materialization_hint'  => 'sanitize_svg_before_materializing_asset',
            ),
            'html_iframe_embed_fallback' => array(
                'severity'              => 'warning',
                'runtime_requirement'   => 'third_party_embed_runtime',
                'recoverability'        => 'recoverable_with_embed_provider_or_html_preservation',
                'actionability'         => 'map_iframe_src_to_supported_embed_provider_or_preserve_html',
                'suggested_primitive'   => 'embed',
                'materialization_hint'  => 'convert_supported_src_to_core_embed_or_preserve_sanitized_iframe_html',
            ),
            'html_canvas_runtime_fallback' => array(
                'severity'              => 'warning',
                'runtime_requirement'   => 'canvas_element_and_client_script_execution',
                'recoverability'        => 'recoverable_with_canvas_markup_preservation_or_rebuilt_interactive_block',
                'actionability'         => 'preserve_canvas_markup_with_matching_script_runtime_or_rebuild_canvas_behavior',
                'suggested_primitive'   => 'runtime_canvas',
                'materialization_hint'  => 'core_blocks_cannot_emit_a_native_canvas_element_without_raw_html; preserve_bounded_canvas_metadata_for_runtime_mapping',
            ),
            'html_unsupported_element' => array(
                'severity'              => 'info',
                'runtime_requirement'   => 'unknown',
                'recoverability'        => 'recoverable_with_manual_mapping',
                'actionability'         => 'map_element_to_supported_block_or_preserve_html',
                'suggested_primitive'   => 'core/html',
                'materialization_hint'  => 'preserve_sanitized_markup_until_a_specific_block_mapping_exists',
            ),
            default => array(
                'severity'              => 'warning',
                'runtime_requirement'   => 'unknown',
                'recoverability'        => 'unknown',
                'actionability'         => 'review_fallback_metadata',
                'suggested_primitive'   => 'core/html',
                'materialization_hint'  => 'preserve_fallback_metadata_for_manual_review',
            ),
        };
    }
}

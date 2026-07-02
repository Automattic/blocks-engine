<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionFindingContract;

final class FallbackDiagnostic
{
    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $provenance
     * @return array<string, mixed>
     */
    public static function build(array $fields, array $provenance = array()): array
    {
        return self::withGenericFindingMetadata(array_merge(self::defaults($fields), $fields, $provenance));
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function withGenericFindingMetadata(array $fields): array
    {
        $selector = is_string($fields['selector'] ?? null) ? trim($fields['selector']) : '';
        $context = is_array($fields['context'] ?? null) ? $fields['context'] : array();
        $patternFamily = self::patternFamily($fields);

        $metadata = array_filter(array(
            'pattern_family'                 => $patternFamily,
            'pattern_family_detail'          => self::patternFamilyDetail($fields),
            'source_selector'                => $selector,
            'source_selector_specificity'    => '' !== $selector ? self::selectorSpecificity($selector) : array(),
            'parent_reason'                  => self::parentReason($context),
            'ancestor_reason'                => self::ancestorReason($context),
            'suggested_generic_repair_class' => self::genericRepairClass($fields, $patternFamily),
        ), static fn (mixed $value): bool => null !== $value && '' !== $value && array() !== $value);

        // Stamp the canonical classification triplet (reason_code / repair_bucket
        // / pattern_family) so every fallback/runtime-island finding clusters by
        // root cause downstream. The contract honors the richer pattern_family and
        // suggested_repair_class computed above and only fills what is missing.
        return ConversionFindingContract::withClassification(array_merge($metadata, $fields));
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
                'conversion_classification' => 'runtime_island_preserved',
                'loss_class'            => 'runtime_island_preserved',
                'diagnostic_class'      => 'runtime_island_preserved',
                'preservation_strategy' => 'fallback_metadata_with_readable_blocks',
                'runtime_requirement'   => 'server_or_client_form_handler',
                'recoverability'        => 'recoverable_with_runtime_mapping',
                'actionability'         => 'map_form_action_controls_and_submission_handler',
                'suggested_repair_class' => 'preserve_runtime_island',
                'suggested_primitive'   => 'form',
                'materialization_hint'  => 'preserve_form_markup_or_replace_with_form_block_integration',
            ),
            'html_product_grid_fallback' => array(
                'severity'              => 'info',
                'conversion_classification' => 'editable_approximation',
                'loss_class'            => 'native_conversion',
                'diagnostic_class'      => 'commerce_structure_detected',
                'preservation_strategy' => 'layout_blocks_with_structured_product_metadata',
                'runtime_requirement'   => 'none',
                'recoverability'        => 'recoverable_with_commerce_product_materialization',
                'actionability'         => 'materialize_detected_products_in_a_commerce_provider',
                'suggested_repair_class' => 'materialize_commerce_products',
                'suggested_primitive'   => 'product_grid',
                'materialization_hint'  => 'layout_blocks_are_emitted_as_is; map_each_detected_product_name_price_and_cart_control_onto_a_commerce_provider',
            ),
            'html_script_fallback' => array(
                'severity'              => 'warning',
                'conversion_classification' => 'runtime_island_preserved',
                'loss_class'            => 'runtime_island_preserved',
                'diagnostic_class'      => 'runtime_island_preserved',
                'preservation_strategy' => 'scoped_runtime_metadata',
                'runtime_requirement'   => 'client_script_execution',
                'recoverability'        => 'recoverable_with_script_enqueue_or_component_runtime',
                'actionability'         => 'review_script_source_and_enqueue_or_rebuild_behavior',
                'suggested_repair_class' => 'preserve_runtime_island',
                'suggested_primitive'   => 'script_asset',
                'materialization_hint'  => 'enqueue_script_or_rebuild_as_interactive_block',
            ),
            'html_inline_svg_fallback' => array(
                'severity'              => 'info',
                'conversion_classification' => 'editable_approximation',
                'preservation_strategy' => 'sanitized_static_markup_or_image',
                'runtime_requirement'   => 'none',
                'recoverability'        => 'recoverable_as_static_markup_or_image_asset',
                'actionability'         => 'review_sanitized_svg_and_materialize_as_image_or_html',
                'suggested_primitive'   => 'image_or_html',
                'materialization_hint'  => 'materialize_safe_svg_as_image_asset_or_core_html',
            ),
            'html_unsafe_inline_svg' => array(
                'severity'              => 'warning',
                'conversion_classification' => 'unsupported_loss',
                'preservation_strategy' => 'diagnostic_only_until_security_review',
                'runtime_requirement'   => 'sanitization_review',
                'recoverability'        => 'recoverable_after_security_review',
                'actionability'         => 'remove_scriptable_svg_content_or_replace_with_safe_asset',
                'suggested_primitive'   => 'image_asset',
                'materialization_hint'  => 'sanitize_svg_before_materializing_asset',
            ),
            'html_iframe_embed_fallback' => array(
                'severity'              => 'warning',
                'conversion_classification' => 'runtime_island_preserved',
                'loss_class'            => 'runtime_island_preserved',
                'diagnostic_class'      => 'runtime_island_preserved',
                'preservation_strategy' => 'sanitized_embed_markup',
                'runtime_requirement'   => 'third_party_embed_runtime',
                'recoverability'        => 'recoverable_with_embed_provider_or_html_preservation',
                'actionability'         => 'map_iframe_src_to_supported_embed_provider_or_preserve_html',
                'suggested_repair_class' => 'preserve_runtime_island',
                'suggested_primitive'   => 'embed',
                'materialization_hint'  => 'convert_supported_src_to_core_embed_or_preserve_sanitized_iframe_html',
            ),
            'html_canvas_runtime_fallback' => array(
                'severity'              => 'warning',
                'conversion_classification' => 'runtime_island_preserved',
                'loss_class'            => 'runtime_island_preserved',
                'diagnostic_class'      => 'runtime_island_preserved',
                'preservation_strategy' => 'bounded_raw_html_runtime_island',
                'runtime_requirement'   => 'canvas_element_and_client_script_execution',
                'recoverability'        => 'recoverable_with_canvas_markup_preservation_or_rebuilt_interactive_block',
                'actionability'         => 'preserve_canvas_markup_with_matching_script_runtime_or_rebuild_canvas_behavior',
                'suggested_repair_class' => 'preserve_runtime_island',
                'suggested_primitive'   => 'runtime_canvas',
                'materialization_hint'  => 'core_blocks_cannot_emit_a_native_canvas_element_without_raw_html; preserve_bounded_canvas_metadata_for_runtime_mapping',
            ),
            'html_template_metadata' => array(
                'severity'              => 'info',
                'conversion_classification' => 'native_conversion',
                'loss_class'            => 'native_conversion',
                'diagnostic_class'      => 'static_metadata_preserved',
                'preservation_strategy' => 'bounded_inert_template_metadata',
                'runtime_requirement'   => 'none',
                'recoverability'        => 'recoverable_from_source_metadata',
                'actionability'         => 'review_template_content_if_needed',
                'suggested_repair_class' => 'preserve_static_metadata',
                'suggested_primitive'   => 'metadata',
                'materialization_hint'  => 'html_template_elements_are_inert_and_have_no_visual_output; preserve_bounded_metadata_without_emitting_blocks',
            ),
            'html_template_runtime_fallback' => array(
                'severity'              => 'warning',
                'conversion_classification' => 'runtime_island_preserved',
                'loss_class'            => 'runtime_island_preserved',
                'diagnostic_class'      => 'runtime_island_preserved',
                'preservation_strategy' => 'bounded_inert_template_runtime_island',
                'runtime_requirement'   => 'client_template_instantiation',
                'recoverability'        => 'recoverable_with_client_template_runtime_or_component_rebuild',
                'actionability'         => 'preserve_template_source_for_runtime_or_rebuild_as_interactive_component',
                'suggested_repair_class' => 'preserve_runtime_island',
                'suggested_primitive'   => 'template',
                'materialization_hint'  => 'template_content_is_inert_until_client_runtime_clones_or_instantiates_it; preserve_bounded_source_metadata_without_visual_blocks',
            ),
            'html_unsupported_element' => array(
                'severity'              => 'info',
                'conversion_classification' => 'unsupported_loss',
                'loss_class'            => 'unsupported_element_loss',
                'diagnostic_class'      => 'unsupported_element',
                'preservation_strategy' => 'diagnostic_only',
                'runtime_requirement'   => 'unknown',
                'recoverability'        => 'recoverable_with_manual_mapping',
                'actionability'         => 'map_element_to_supported_block_or_preserve_html',
                'suggested_repair_class' => 'add_generic_pattern_recognizer',
                'suggested_primitive'   => 'core/html',
                'materialization_hint'  => 'preserve_sanitized_markup_until_a_specific_block_mapping_exists',
            ),
            'interactive_control_behavior_lost' => array(
                'severity'              => 'warning',
                'conversion_classification' => 'behavior_loss',
                'loss_class'            => 'interactive_behavior_loss',
                'diagnostic_class'      => 'interactive_behavior_loss',
                'preservation_strategy' => 'none_behavior_dropped',
                'runtime_requirement'   => 'client_event_handler',
                'recoverability'        => 'recoverable_with_interactive_block_or_script_runtime',
                'actionability'         => 'rebuild_control_behavior_as_interactive_block_or_enqueue_handler_script',
                'suggested_repair_class' => 'restore_interactive_behavior',
                'suggested_primitive'   => 'interactive_control',
                'materialization_hint'  => 'rebuild_as_interactive_block_or_preserve_handler_via_script_runtime',
            ),
            default => array(
                'severity'              => 'warning',
                'conversion_classification' => 'unsupported_loss',
                'loss_class'            => 'unsupported_loss',
                'diagnostic_class'      => 'fallback_metadata',
                'preservation_strategy' => 'diagnostic_only',
                'runtime_requirement'   => 'unknown',
                'recoverability'        => 'unknown',
                'actionability'         => 'review_fallback_metadata',
                'suggested_repair_class' => 'review_generic_mapping',
                'suggested_primitive'   => 'core/html',
                'materialization_hint'  => 'preserve_fallback_metadata_for_manual_review',
            ),
        };
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function patternFamily(array $fields): string
    {
        $code = (string) ($fields['diagnostic_code'] ?? $fields['code'] ?? '');
        $tag = (string) ($fields['tag'] ?? '');
        $kind = (string) ($fields['kind'] ?? '');

        if ( 'preserved_runtime_island' === $code && self::isInlineSemanticHtmlRuntimeIsland($fields) ) {
            return 'inline_semantic_html';
        }

        return match ( $code ) {
            'html_form_fallback' => 'interactive_form',
            'html_product_grid_fallback' => 'commerce_product_grid',
            'html_script_fallback' => 'runtime_script',
            'interactive_control_behavior_lost' => 'interactive_control',
            'html_iframe_embed_fallback' => 'external_embed',
            'html_canvas_runtime_fallback' => 'runtime_canvas',
            'html_template_metadata' => 'inert_template_metadata',
            'html_template_runtime_fallback' => 'runtime_template',
            'html_inline_svg_fallback', 'html_unsafe_inline_svg' => 'inline_svg',
            'html_unsupported_element' => '' !== $tag ? 'unsupported_' . $tag : 'unsupported_element',
            default => match ( $kind ) {
                'form', 'control' => 'interactive_form',
                'script' => 'runtime_script',
                'canvas' => 'runtime_canvas',
                default => '' !== $tag ? 'html_' . $tag : 'html_fallback',
            },
        };
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function patternFamilyDetail(array $fields): string
    {
        $parts = array_filter(array(
            (string) ($fields['tag'] ?? ''),
            (string) ($fields['reason'] ?? $fields['preservation_reason'] ?? ''),
            (string) ($fields['runtime_requirement'] ?? ''),
        ));

        return implode(':', $parts);
    }

    /**
     * @return array{ids: int, classes: int, attributes: int, pseudo_classes: int, elements: int, score: string}
     */
    private static function selectorSpecificity(string $selector): array
    {
        preg_match_all('/#[A-Za-z0-9_-]+/', $selector, $ids);
        preg_match_all('/\.[A-Za-z0-9_-]+/', $selector, $classes);
        preg_match_all('/\[[^\]]+\]/', $selector, $attributes);
        preg_match_all('/:nth-of-type\(/', $selector, $pseudoClasses);
        preg_match_all('/(?:^|>\s*)([a-z][a-z0-9-]*)/i', $selector, $elements);

        $idCount = count($ids[0]);
        $classCount = count($classes[0]);
        $attributeCount = count($attributes[0]);
        $pseudoClassCount = count($pseudoClasses[0]);
        $elementCount = count($elements[1]);

        return array(
            'ids'            => $idCount,
            'classes'        => $classCount,
            'attributes'     => $attributeCount,
            'pseudo_classes' => $pseudoClassCount,
            'elements'       => $elementCount,
            'score'          => $idCount . ',' . ($classCount + $attributeCount + $pseudoClassCount) . ',' . $elementCount,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function parentReason(array $context): string
    {
        $parent = is_string($context['parent_tag'] ?? null) ? trim($context['parent_tag']) : '';

        return '' !== $parent ? 'inside_' . $parent : '';
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function ancestorReason(array $context): string
    {
        $ancestors = is_array($context['ancestor_tags'] ?? null) ? array_values(array_filter($context['ancestor_tags'], 'is_string')) : array();

        return array() !== $ancestors ? 'within_' . implode('_', $ancestors) : '';
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function genericRepairClass(array $fields, string $patternFamily): string
    {
        if ( is_string($fields['suggested_repair_class'] ?? null) && '' !== trim($fields['suggested_repair_class']) ) {
            return (string) $fields['suggested_repair_class'];
        }

        if ( str_starts_with($patternFamily, 'runtime_') || in_array($patternFamily, array('interactive_form', 'external_embed', 'inline_semantic_html'), true) ) {
            return 'preserve_runtime_island';
        }

        if ( str_starts_with($patternFamily, 'unsupported_') ) {
            return 'add_generic_pattern_recognizer';
        }

        if ( 'inline_svg' === $patternFamily ) {
            return 'materialize_static_asset';
        }

        return 'review_generic_mapping';
    }

    /**
     * Inline elements with semantic/ARIA/class hooks cannot be represented as
     * editable RichText without risking attribute loss, so preserved runtime
     * islands should cluster separately from generic raw-HTML fallbacks.
     *
     * @param array<string, mixed> $fields
     */
    private static function isInlineSemanticHtmlRuntimeIsland(array $fields): bool
    {
        if ( 'dom' !== (string) ($fields['kind'] ?? '') ) {
            return false;
        }

        $tag = strtolower((string) ($fields['tag'] ?? ''));
        if ( ! in_array($tag, array('a', 'abbr', 'b', 'cite', 'code', 'data', 'em', 'i', 'kbd', 'label', 'mark', 'q', 's', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var'), true) ) {
            return false;
        }

        $attributes = is_array($fields['attributes'] ?? null) ? $fields['attributes'] : array();
        foreach ( array_keys($attributes) as $name ) {
            $attributeName = strtolower((string) $name);
            if ( 'class' === $attributeName || 'id' === $attributeName || 'role' === $attributeName || str_starts_with($attributeName, 'aria-') || str_starts_with($attributeName, 'data-') ) {
                return true;
            }
        }

        return false;
    }
}

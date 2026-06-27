<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics;

use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

/**
 * Projects already-computed conversion signals (fallbacks, runtime islands,
 * static script metadata, block-validity findings, and semantic-parity
 * findings) into the flat diagnostics array carried by TransformerResult.
 *
 * This is a behavior-preserving extraction of the inline projection that
 * previously lived in HtmlTransformer::transform(). It performs no DOM work and
 * no logic decisions — it only reshapes data the transformer already produced —
 * so the diagnostics output is byte-identical to the inline implementation.
 */
final class DiagnosticsCollector
{
    /**
     * Build the success-path diagnostics array from the transformer's computed
     * signals.
     *
     * @param string                            $transformerSource    Source attribution for transformer-owned diagnostics (HtmlTransformer::class).
     * @param array<int, array<string, mixed>>  $scriptMetadata       Static script metadata preserved as bounded data.
     * @param array<int, array<string, mixed>>  $fallbacks            Fallback diagnostics emitted during conversion.
     * @param array<int, array<string, mixed>>  $runtimeIslands       Preserved runtime islands.
     * @param array<string, mixed>              $blockValidityReport  Block serialization validity report.
     * @param array<string, mixed>              $semanticParityReport Semantic parity report.
     * @return array<int, array<string, mixed>>
     */
    public function collect(
        string $transformerSource,
        array $scriptMetadata,
        array $fallbacks,
        array $runtimeIslands,
        array $blockValidityReport,
        array $semanticParityReport
    ): array {
        $diagnostics = array(
            array(
                'code'    => 'html_to_blocks_core_slice',
                'message' => 'Converted supported core text, layout, media, gallery, embed, file, table, button, shortcode, spacer, definition-list, details, navigation, safe inline SVG images, and wrapper elements; unsupported elements are reported as fallbacks.',
                'source'  => $transformerSource,
            ),
        );

        foreach ( $scriptMetadata as $metadata ) {
            $diagnostics[] = array(
                'code'        => 'html_static_script_metadata',
                'message'     => 'Static script data was preserved as bounded metadata and does not require client script execution.',
                'source'      => $transformerSource,
                'reason'      => 'script_static_metadata',
                'tag'         => 'script',
                'selector'    => $metadata['selector'] ?? null,
                'script_role' => $metadata['script_role'] ?? null,
            );
        }

        foreach ( $fallbacks as $fallback ) {
            if ( ! empty($fallback['diagnostic_code']) ) {
                $diagnostics[] = array(
                    'code'                => $fallback['diagnostic_code'],
                    'message'             => $fallback['message'] ?? 'HTML element preserved as fallback metadata.',
                    'source'              => $transformerSource,
                    'reason'              => $fallback['reason'] ?? null,
                    'severity'            => $fallback['severity'] ?? null,
                    'conversion_classification' => $fallback['conversion_classification'] ?? null,
                    'loss_class'          => $fallback['loss_class'] ?? null,
                    'diagnostic_class'    => $fallback['diagnostic_class'] ?? null,
                    'preservation_strategy' => $fallback['preservation_strategy'] ?? null,
                    'runtime_requirement'             => $fallback['runtime_requirement'] ?? null,
                    'recoverability'                  => $fallback['recoverability'] ?? null,
                    'actionability'                   => $fallback['actionability'] ?? null,
                    'suggested_repair_class'          => $fallback['suggested_repair_class'] ?? null,
                    'suggested_primitive'             => $fallback['suggested_primitive'] ?? null,
                    'materialization_hint'            => $fallback['materialization_hint'] ?? null,
                    'tag'                             => $fallback['tag'] ?? null,
                    'selector'                        => $fallback['selector'] ?? null,
                    'pattern_family'                  => $fallback['pattern_family'] ?? null,
                    'pattern_family_detail'           => $fallback['pattern_family_detail'] ?? null,
                    'source_selector'                 => $fallback['source_selector'] ?? null,
                    'source_selector_specificity'     => $fallback['source_selector_specificity'] ?? null,
                    'parent_reason'                   => $fallback['parent_reason'] ?? null,
                    'ancestor_reason'                 => $fallback['ancestor_reason'] ?? null,
                    'suggested_generic_repair_class' => $fallback['suggested_generic_repair_class'] ?? null,
                );
            }
        }

        foreach ( $runtimeIslands as $island ) {
            $diagnostics[] = array_filter(array(
                'code'                => 'preserved_runtime_island',
                'message'             => 'Runtime-dependent source markup was preserved as a bounded runtime island.',
                'source'              => $transformerSource,
                'severity'            => 'info',
                'conversion_classification' => 'runtime_island_preserved',
                'loss_class'          => 'runtime_island_preserved',
                'diagnostic_class'    => 'runtime_island_preserved',
                'suggested_repair_class' => 'preserve_runtime_island',
                'preservation_strategy' => $island['preservation_strategy'] ?? 'bounded_raw_html_runtime_island',
                'runtime_requirement' => $island['runtime_requirement'] ?? null,
                'kind'                => $island['kind'] ?? null,
                'reason'              => $island['preservation_reason'] ?? null,
                'tag'                 => $island['tag'] ?? null,
                'selector'            => $island['selector'] ?? null,
                'pattern_family'                  => $island['pattern_family'] ?? null,
                'pattern_family_detail'           => $island['pattern_family_detail'] ?? null,
                'source_selector'                 => $island['source_selector'] ?? null,
                'source_selector_specificity'     => $island['source_selector_specificity'] ?? null,
                'parent_reason'                   => $island['parent_reason'] ?? null,
                'ancestor_reason'                 => $island['ancestor_reason'] ?? null,
                'suggested_generic_repair_class' => $island['suggested_generic_repair_class'] ?? null,
            ), static fn (mixed $value): bool => null !== $value && '' !== $value);
        }

        foreach ( $blockValidityReport['findings'] ?? array() as $finding ) {
            if ( ! is_array($finding) ) {
                continue;
            }

            $diagnostics[] = array(
                'code'       => 'wp_block_validity_' . (string) ($finding['code'] ?? 'warning'),
                'message'    => (string) ($finding['summary'] ?? 'Generated block serialization may trigger WordPress block invalidity warnings.'),
                'source'     => Runtime::class,
                'severity'   => $finding['severity'] ?? 'warning',
                'block_name' => $finding['block_name'] ?? null,
                'path'       => $finding['path'] ?? null,
            );
        }

        foreach ( $semanticParityReport['findings'] ?? array() as $finding ) {
            if ( ! is_array($finding) ) {
                continue;
            }

            $diagnostics[] = array(
                'code'     => 'html_semantic_parity_' . (string) ($finding['code'] ?? 'warning'),
                'message'  => (string) ($finding['summary'] ?? 'Generated blocks differ from source semantic structure.'),
                'source'   => $transformerSource,
                'severity' => $finding['severity'] ?? 'warning',
                'selector' => $finding['selector'] ?? null,
            );
        }

        return $diagnostics;
    }
}

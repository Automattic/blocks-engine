<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionReportProjection;

/** Composes the HTML transformation evidence envelope from run-scoped values. */
final class HtmlResultComposer
{
    /**
     * @param array<string, mixed> $input
     * @return array{diagnostics: array<int, array<string, mixed>>, source_reports: array<string, mixed>, coverage: array<int, array<string, mixed>>}
     */
    public function compose(array $input): array
    {
        $diagnostics = $this->diagnostics($input);
        $sourceReports = $this->sourceReports($input);

        return array(
            'diagnostics' => $diagnostics,
            'source_reports' => $sourceReports,
            'coverage' => array(
                array(
                    'supported_blocks' => $input['supported_blocks'],
                    'runtime_available_blocks' => $input['native_target_blocks'],
                    'capability_matrix' => $input['capability_matrix'],
                    'block_count' => count($input['blocks']),
                    'fallback_count' => count($input['fallbacks']),
                    'source_provenance_count' => count($input['source_provenance']),
                ),
            ),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $fallbacks
     * @param array<int, array<string, mixed>> $diagnostics
     * @param array<int, array<string, mixed>> $provenance
     * @param array<string, int|float> $metrics
     * @return array{diagnostics: array<int, array<string, mixed>>, source_reports: array<string, mixed>, coverage: array<int, array<string, mixed>>}
     */
    public function reduced(array $blocks, array $fallbacks, array $diagnostics, array $provenance, array $metrics): array
    {
        $sourceReports = array(
            'conversion_report' => ConversionReportProjection::fromResultParts('html', $blocks, $fallbacks, array(), array(), $provenance, $metrics),
        );

        return array('diagnostics' => $diagnostics, 'source_reports' => $sourceReports, 'coverage' => array());
    }

    /** @param array<string, mixed> $input @return array<int, array<string, mixed>> */
    public function diagnostics(array $input): array
    {
        $diagnostics = $input['diagnostics'];
        $diagnostics = $this->appendResponsiveGeometryDiagnostics($diagnostics, $input['responsive_geometry_ambiguities']);
        $diagnostics = $this->appendResponsiveHeightDiagnostics($diagnostics, $input['responsive_height_ambiguities']);
        $diagnostics = $this->appendHeadMetadataDiagnostic($diagnostics, $input['head_metadata'], $input['source']);
        $diagnostics = $this->appendAuthorLayoutTopologyDiagnostics($diagnostics, $input['author_layout_topology_findings'], $input['source']);

        return $this->appendDescriptionListDiagnostic($diagnostics, $input['has_description_list_block'], $input['source']);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function sourceReports(array $input): array
    {
        $sourceReports = array(
            'native_target_blocks' => $input['native_target_blocks'],
            'available_core_blocks' => $input['native_target_blocks'],
            'core_block_capabilities' => $input['capability_matrix'],
            'head_metadata' => $input['head_metadata'],
            'runtime_islands' => $input['runtime_islands'],
            'runtime_dom_contracts' => $input['runtime_dom_contracts'],
            'runtime_dom_fallbacks' => $input['runtime_dom_fallbacks'],
            'generated_blocks' => $input['generated_blocks'],
            'gutenberg_gaps' => $input['gutenberg_gaps'],
            'interaction_candidates' => $input['interaction_candidates'],
            'superseded_selectors' => $input['superseded_selectors'],
            'shell_artifacts' => $input['shell_artifacts'],
            'wp_block_validity' => $input['block_validity_report'],
            'semantic_parity' => $input['semantic_parity_report'],
            'content_round_trip' => $input['content_round_trip_report'],
            'editability_report' => $input['editability_report'],
            'html' => array(
                'presentation_signals' => $input['presentation_signals'],
                'frozen_hidden_state' => $input['frozen_hidden_state'],
                'dropped_link_wrappers' => $input['dropped_link_wrappers'],
                'gutenberg_incompatibilities' => $input['gutenberg_incompatibilities'],
                'author_layout_topology' => $input['author_layout_topology_findings'],
                'source_provenance' => $input['source_provenance'],
                'core_html_fallback_evidence' => $input['core_html_fallback_evidence'],
                'structure_signals' => $input['structure_signals'],
                'reusable_components' => $input['reusable_components'],
                'script_metadata' => $input['script_metadata'],
                'runtime_islands' => $input['runtime_islands'],
                'layout_geometry_proof' => $input['layout_geometry_proof'],
            ),
        );
        foreach (array('author_stylesheet_projections', 'runtime_script_projections', 'responsive_counterpart_contracts') as $key) {
            if (array() !== $input[$key]) {
                $sourceReports[$key] = $input[$key];
            }
        }
        $sourceReports['conversion_report'] = ConversionReportProjection::fromResultParts('html', $input['blocks'], $input['fallbacks'], $sourceReports, array(), $input['provenance'], $input['metrics']);

        return $sourceReports;
    }

    /** @param array<int, array<string, mixed>> $diagnostics @param array<int, array<string, mixed>> $ambiguities @return array<int, array<string, mixed>> */
    private function appendResponsiveGeometryDiagnostics(array $diagnostics, array $ambiguities): array
    {
        foreach ($ambiguities as $ambiguity) {
            $diagnostics[] = array('code' => 'responsive_geometry_ambiguous_min_width', 'message' => 'A wide minimum-width rule matches both page-shell and authored content surfaces, so it was retained without a responsive projection.', 'source' => HtmlTransformer::class, 'severity' => 'warning', 'selector' => $ambiguity['selector'], 'min_width' => $ambiguity['min_width']);
        }
        return $diagnostics;
    }

    /** @param array<int, array<string, mixed>> $diagnostics @param array<int, array<string, mixed>> $ambiguities @return array<int, array<string, mixed>> */
    private function appendResponsiveHeightDiagnostics(array $diagnostics, array $ambiguities): array
    {
        foreach ($ambiguities as $ambiguity) {
            $diagnostics[] = array('code' => 'responsive_geometry_ambiguous_percentage_height', 'message' => 'A percentage-height rule matches both auto-sized structural wrappers and height-owning content, so it was retained without a responsive projection.', 'source' => HtmlTransformer::class, 'severity' => 'warning', 'selector' => $ambiguity['selector'], 'height' => $ambiguity['height']);
        }
        return $diagnostics;
    }

    /** @param array<int, array<string, mixed>> $diagnostics @param array<int, array<string, mixed>> $headMetadata @return array<int, array<string, mixed>> */
    private function appendHeadMetadataDiagnostic(array $diagnostics, array $headMetadata, string $source): array
    {
        if (array() !== $headMetadata) {
            $diagnostics[] = array('code' => 'html_head_metadata_not_carried', 'message' => 'Named head metadata (meta description and social property tags) is not representable in block markup; the entries are surfaced in source_reports.head_metadata for the destination document to adopt deliberately.', 'source' => $source, 'severity' => 'info', 'entries' => $headMetadata);
        }
        return $diagnostics;
    }

    /** @param array<int, array<string, mixed>> $diagnostics @param array<int, array<string, mixed>> $findings @return array<int, array<string, mixed>> */
    private function appendAuthorLayoutTopologyDiagnostics(array $diagnostics, array $findings, string $source): array
    {
        foreach ($findings as $finding) {
            $diagnostics[] = array('code' => 'author_layout_topology_changed', 'message' => 'Gutenberg block conversion changed the direct-child topology of a CSS-owned layout container.', 'source' => $source, 'severity' => 'warning', 'selector' => $finding['selector'], 'source_child_count' => $finding['source_child_count'], 'block_child_count' => $finding['block_child_count']);
        }
        return $diagnostics;
    }

    /** @param array<int, array<string, mixed>> $diagnostics @return array<int, array<string, mixed>> */
    private function appendDescriptionListDiagnostic(array $diagnostics, bool $hasDescriptionListBlock, string $source): array
    {
        if ($hasDescriptionListBlock) {
            $diagnostics[] = array('code' => 'semantic_description_list_gutenberg_gap', 'message' => 'A semantic description list was materialized with the Blocks Engine companion block because Gutenberg has no core description-list block.', 'source' => $source, 'severity' => 'info', 'references' => array('https://github.com/WordPress/gutenberg/issues/4880', 'https://github.com/WordPress/gutenberg/pull/20760'));
        }
        return $diagnostics;
    }
}

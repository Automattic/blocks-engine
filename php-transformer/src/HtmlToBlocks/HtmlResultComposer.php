<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionReportProjection;
use Automattic\BlocksEngine\PhpTransformer\Contract\BlockCompilationOutput;
use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatchCache;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackDiagnostic;

/** Projects HTML reports and assembles TransformerResult from explicit compilation outputs. */
final class HtmlResultComposer
{
    /**
     * @param array<string, mixed> $provenanceFallback
     * @param array<int, array<string, mixed>> $provenance
     * @param array<string, mixed> $context
     */
    public function parseFailed(string $html, array $provenanceFallback, array $provenance, array $context, int $startedAt, CssSelectorMatchCache $selectorCache): TransformerResult
    {
        $diagnostics = array(
            array(
                'code'    => 'html_parse_failed',
                'message' => 'Unable to parse HTML input.',
                'source'  => HtmlTransformer::class,
            ),
        );
        $fallbacks = array(
            FallbackDiagnostic::build(array(
                'type'            => 'html',
                'reason'          => 'parse_failed',
                'diagnostic_code' => 'html_parse_failed',
                'source_format'   => 'html',
                'html'            => $html,
            ), $provenanceFallback),
        );

        return $this->earlyResult($html, $fallbacks, $diagnostics, $provenance, $context, $startedAt, $selectorCache);
    }

    /**
     * @param array<int, array<string, mixed>> $provenance
     * @param array<string, mixed> $context
     */
    public function emptyBody(string $html, array $provenance, array $context, int $startedAt, CssSelectorMatchCache $selectorCache): TransformerResult
    {
        return $this->earlyResult($html, array(), array(), $provenance, $context, $startedAt, $selectorCache);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function result(array $input, BlockCompilationOutput $blockCompilationOutput): TransformerResult
    {
        $diagnostics = $this->diagnostics($input);
        $metrics = $this->metrics(
            $input['html'],
            $input['blocks'],
            $input['serialized_blocks'],
            $input['fallbacks'],
            $diagnostics,
            $input['started_at'],
            $input['selector_cache']
        );
        $input['diagnostics'] = $diagnostics;
        $input['metrics'] = $metrics;
        $composition = $this->compose($input, $blockCompilationOutput);

        return new TransformerResult(
            status: $this->statusForFallbacks($input['fallbacks'], $input['context']),
            blocks: $input['blocks'],
            serializedBlocks: $input['serialized_blocks'],
            assets: $input['assets'],
            diagnostics: $composition['diagnostics'],
            fallbacks: $input['fallbacks'],
            provenance: $input['provenance'],
            sourceReports: $composition['source_reports'],
            coverage: $composition['coverage'],
            context: $input['context'],
            metrics: $metrics,
            blockCompilationOutput: $blockCompilationOutput
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array{diagnostics: array<int, array<string, mixed>>, source_reports: array<string, mixed>, coverage: array<int, array<string, mixed>>}
     */
    public function compose(array $input, BlockCompilationOutput $blockCompilationOutput): array
    {
        $sourceReports = $this->sourceReports($input, $blockCompilationOutput);

        return array(
            'diagnostics' => $input['diagnostics'],
            'source_reports' => $sourceReports,
            'coverage' => array(
                array(
                    'supported_blocks' => $input['supported_blocks'],
                    'runtime_available_blocks' => $input['native_target_blocks'],
                    'bundled_snapshot_blocks' => $input['bundled_snapshot_blocks'],
                    'runtime_registered_blocks' => $input['runtime_registered_blocks'],
                    'capability_matrix' => $input['capability_matrix'],
                    'block_count' => count($input['blocks']),
                    'fallback_count' => count($input['fallbacks']),
                    'source_provenance_count' => count($blockCompilationOutput->sourceProvenance),
                ),
            ),
        );
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

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @param array<int, array<string, mixed>> $diagnostics
     * @param array<int, array<string, mixed>> $provenance
     * @param array<string, mixed> $context
     */
    private function earlyResult(string $html, array $fallbacks, array $diagnostics, array $provenance, array $context, int $startedAt, CssSelectorMatchCache $selectorCache): TransformerResult
    {
        $metrics = $this->metrics($html, array(), '', $fallbacks, $diagnostics, $startedAt, $selectorCache);

        return new TransformerResult(
            diagnostics: $diagnostics,
            sourceReports: array(
                'conversion_report' => ConversionReportProjection::fromResultParts('html', array(), $fallbacks, array(), array(), $provenance, $metrics),
            ),
            fallbacks: $fallbacks,
            provenance: $provenance,
            context: $context,
            metrics: $metrics,
            blockCompilationOutput: BlockCompilationOutput::empty()
        );
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $fallbacks
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, int|float>
     */
    private function metrics(string $input, array $blocks, string $output, array $fallbacks, array $diagnostics, int $startedAt, CssSelectorMatchCache $selectorCache): array
    {
        return array(
            'input_bytes'           => strlen($input),
            'block_count'           => $this->countBlocks($blocks),
            'fallback_count'        => count($fallbacks),
            'diagnostic_count'      => count($diagnostics),
            'transform_duration_ms' => (hrtime(true) - $startedAt) / 1000000,
            'output_bytes'          => strlen($output),
            'selector_match_cache_hits' => $selectorCache->matchHits,
            'selector_match_cache_misses' => $selectorCache->matchMisses,
            'selector_match_cache_evictions' => $selectorCache->matchEvictions,
            'selector_match_cache_peak_entries' => $selectorCache->matchPeakEntries,
            'style_rule_candidate_cache_hits' => $selectorCache->candidateRuleHits,
            'style_rule_candidate_cache_misses' => $selectorCache->candidateRuleMisses,
            'style_rule_candidate_cache_evictions' => $selectorCache->candidateRuleEvictions,
            'style_rule_candidate_cache_peak_entries' => $selectorCache->candidateRulePeakEntries,
            'style_rule_candidate_cache_peak_rule_references' => $selectorCache->candidateRulePeakRetained,
        );
    }

    /** @param array<int, array<string, mixed>> $blocks */
    private function countBlocks(array $blocks): int
    {
        $count = 0;

        foreach ( $blocks as $block ) {
            ++$count;
            if ( ! empty($block['innerBlocks']) && is_array($block['innerBlocks']) ) {
                $count += $this->countBlocks($block['innerBlocks']);
            }
        }

        return $count;
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @param array<string, mixed> $context
     */
    private function statusForFallbacks(array $fallbacks, array $context): string
    {
        if ( array() === $fallbacks || $context['allow_fallbacks'] ) {
            return 'success';
        }

        return $context['strict'] ? 'failed' : 'success_with_warnings';
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function sourceReports(array $input, BlockCompilationOutput $blockCompilationOutput): array
    {
        $sourceReports = array(
            'native_target_blocks' => $input['native_target_blocks'],
            'available_core_blocks' => $input['native_target_blocks'],
            'bundled_snapshot_blocks' => $input['bundled_snapshot_blocks'],
            'runtime_registered_blocks' => $input['runtime_registered_blocks'],
            'core_block_capabilities' => $input['capability_matrix'],
            'head_metadata' => $input['head_metadata'],
            'runtime_islands' => $blockCompilationOutput->runtimeIslands,
            'runtime_dom_contracts' => $input['runtime_dom_contracts'],
            'runtime_dom_fallbacks' => $input['runtime_dom_fallbacks'],
            'generated_blocks' => $blockCompilationOutput->generatedBlocks,
            'gutenberg_gaps' => $blockCompilationOutput->gutenbergGaps,
            'interaction_candidates' => $blockCompilationOutput->interactionCandidates,
            'superseded_selectors' => $blockCompilationOutput->supersededSelectors,
            'shell_artifacts' => $blockCompilationOutput->shellArtifacts,
            'wp_block_validity' => $input['block_validity_report'],
            'semantic_parity' => $input['semantic_parity_report'],
            'content_round_trip' => $input['content_round_trip_report'],
            'editability_report' => $blockCompilationOutput->editabilityReport,
            'html' => array(
                'presentation_signals' => $input['presentation_signals'],
                'frozen_hidden_state' => $input['frozen_hidden_state'],
                'dropped_link_wrappers' => $input['dropped_link_wrappers'],
                'gutenberg_incompatibilities' => $input['gutenberg_incompatibilities'],
                'author_layout_topology' => $input['author_layout_topology_findings'],
                'source_provenance' => $blockCompilationOutput->sourceProvenance,
                'core_html_fallback_evidence' => $blockCompilationOutput->coreHtmlFallbackEvidence,
                'structure_signals' => $input['structure_signals'],
                'reusable_components' => $blockCompilationOutput->reusableComponents,
                'script_metadata' => $input['script_metadata'],
                'runtime_islands' => $blockCompilationOutput->runtimeIslands,
                'layout_geometry_proof' => $blockCompilationOutput->layoutGeometryProof,
                'source_target_projections' => $input['source_target_projections'],
            ),
        );
        if (array() !== $blockCompilationOutput->authorStylesheetProjections) {
            $sourceReports['author_stylesheet_projections'] = $blockCompilationOutput->authorStylesheetProjections;
        }
        if (array() !== $blockCompilationOutput->runtimeScriptProjections) {
            $sourceReports['runtime_script_projections'] = $blockCompilationOutput->runtimeScriptProjections;
        }
        if (array() !== $blockCompilationOutput->responsiveCounterpartContracts) {
            $sourceReports['responsive_counterpart_contracts'] = $blockCompilationOutput->responsiveCounterpartContracts;
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

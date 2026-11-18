<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Contract;

use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;

/** Measures bounded editability risks independently of visual-parity evidence. */
final class EditabilityReport
{
    public const SCHEMA = 'blocks-engine/php-transformer/editability-report/v2';
    private const MAX_REPORTED_SIGNALS = 100;
    private const MAX_NORMALIZATION_PATH_ENTRIES = 24;

    /** @param array<int,array<string,mixed>> $blocks @return array<string,mixed> */
    public function fromBlocks(array $blocks, string $sourcePath = '', string $serializedBlocks = '', string $generatedCarrierCss = '', array $runtimeBlockPaths = array(), array $visualBlockPaths = array(), array $sourceProvenance = array(), array $generatedBlocks = array()): array
    {
        $metrics = array(
            'block_count' => 0,
            'leaf_block_count' => 0,
            'content_block_count' => 0,
            'wrapper_block_count' => 0,
            'empty_wrapper_count' => 0,
            'empty_visual_group_count' => 0,
            'empty_runtime_group_count' => 0,
            'max_nesting_depth' => 0,
            'raw_html_block_count' => 0,
            'html_bearing_attribute_count' => 0,
            'html_bearing_table_cell_count' => 0,
            'structural_rich_text_attribute_count' => 0,
            'structural_rich_text_attribute_bytes' => 0,
            'source_marker_class_count' => 0,
            'generated_geometry_class_count' => 0,
            'custom_wrapper_block_count' => 0,
            'layout_shell_wrapper_count' => 0,
            'layout_shell_editable_descendant_count' => 0,
            'responsive_counterpart_count' => 0,
            'serialized_bytes' => '' === $serializedBlocks ? 0 : strlen($serializedBlocks),
        );
        $runtimeBlockPaths = array_map(static fn (string $path): string => str_replace('.innerBlocks', '', $path), $runtimeBlockPaths);
        $visualBlockPaths = array_map(static fn (string $path): string => str_replace('.innerBlocks', '', $path), $visualBlockPaths);
        $walk = new EditabilityWalk($metrics, $sourcePath, $generatedCarrierCss, array_fill_keys($runtimeBlockPaths, true), array_fill_keys($visualBlockPaths, true), $this->provenanceByBlockPath($sourceProvenance), GeneratedBlockEditingSurface::rawHtmlBlockNames($generatedBlocks));
        $walk->walk($blocks, 1, array());
        $metrics = $walk->metrics;
        $blockTypes = $walk->blockTypes;
        $signals = $walk->signals;
        $deepestBlock = $walk->deepestBlock;
        ksort($blockTypes, SORT_STRING);
        $policyWrapperCount = $metrics['wrapper_block_count'] - $metrics['empty_visual_group_count'] - $metrics['empty_runtime_group_count'];
        $metrics['wrapper_to_content_ratio'] = 0 === $metrics['content_block_count']
            ? (float) $policyWrapperCount
            : round($policyWrapperCount / $metrics['content_block_count'], 4);

        $signalCount = count($signals);
        return array(
            'schema' => self::SCHEMA,
            'scope' => array_filter(array('source_path' => $sourcePath), static fn(string $value): bool => '' !== $value),
            'metrics' => $metrics,
            'deepest_block' => $deepestBlock,
            'normalization_evidence' => $this->normalizationEvidence($blocks, (string) ($deepestBlock['block_path'] ?? '')),
            'block_types' => $blockTypes,
            'signals' => $this->boundedSignals($signals),
            'signal_totals' => array(
                'observed' => $signalCount,
                'reported' => min($signalCount, self::MAX_REPORTED_SIGNALS),
                'omitted' => max(0, $signalCount - self::MAX_REPORTED_SIGNALS),
                'truncated' => $signalCount > self::MAX_REPORTED_SIGNALS,
            ),
        );
    }

    /** @param array<string,array<string,mixed>> $documents @return array<string,mixed> */
    public function fromDocuments(array $documents): array
    {
        ksort($documents, SORT_STRING);
        $reports = array();
        $totals = array();
        $blockTypes = array();
        $signals = array();
        $signalCount = 0;
        foreach ($documents as $sourcePath => $document) {
            $report = is_array($document['editability_report'] ?? null) ? $document['editability_report'] : $this->fromBlocks(
                ArrayField::array($document, 'blocks'),
                (string) $sourcePath,
                ArrayField::string($document, 'serialized_blocks'),
                ArrayField::string($document, 'generated_carrier_css'),
                ArrayField::array($document, 'runtime_block_paths'),
                ArrayField::array($document, 'visual_block_paths'),
                ArrayField::array($document, 'source_provenance'),
                ArrayField::array($document, 'generated_blocks')
            );
            $reports[] = array(
                'source_path' => (string) $sourcePath,
                'metrics' => $report['metrics'],
                'deepest_block' => $report['deepest_block'],
                'normalization_evidence' => $report['normalization_evidence'],
                'block_types' => $report['block_types'],
                'signals' => $report['signals'],
                'signal_totals' => $report['signal_totals'],
            );
            $index = array_key_last($reports);
            if (is_array($document['template_surface'] ?? null)) $reports[$index]['template_surface_declaration'] = $document['template_surface'];
            if (is_array($document['provenance'] ?? null)) $reports[$index]['provenance'] = $document['provenance'];
            foreach ($report['metrics'] as $key => $value) {
                if ('max_nesting_depth' === $key) {
                    $totals[$key] = max((int) ($totals[$key] ?? 0), (int) $value);
                } elseif ('wrapper_to_content_ratio' !== $key) {
                    $totals[$key] = ($totals[$key] ?? 0) + $value;
                }
            }
            foreach ($report['block_types'] as $name => $count) $blockTypes[$name] = ($blockTypes[$name] ?? 0) + $count;
            $signals = array_merge($signals, $report['signals']);
            $signalCount += $report['signal_totals']['observed'];
        }
        $totals['document_count'] = count($reports);
        $policyWrapperCount = ($totals['wrapper_block_count'] ?? 0) - ($totals['empty_visual_group_count'] ?? 0) - ($totals['empty_runtime_group_count'] ?? 0);
        $totals['wrapper_to_content_ratio'] = 0 === ($totals['content_block_count'] ?? 0)
            ? (float) $policyWrapperCount
            : round($policyWrapperCount / $totals['content_block_count'], 4);
        ksort($blockTypes, SORT_STRING);

        return array(
            'schema' => self::SCHEMA,
            'metrics' => $totals,
            'block_types' => $blockTypes,
            'documents' => $reports,
            'signals' => $this->boundedSignals($signals),
            'signal_totals' => array(
                'observed' => $signalCount,
                'reported' => min($signalCount, self::MAX_REPORTED_SIGNALS),
                'omitted' => max(0, $signalCount - self::MAX_REPORTED_SIGNALS),
                'truncated' => $signalCount > self::MAX_REPORTED_SIGNALS,
            ),
        );
    }

    /**
     * Site-level design-system metrics from the WordPress site plan: preset
     * share, palette size, unused font families and generated-to-source CSS
     * bytes (see WordPressSitePlan\DesignSystem::metrics()).
     *
     * @param array<string,mixed> $report @param array<string,mixed> $designSystem @return array<string,mixed>
     */
    public function withDesignSystem(array $report, array $designSystem): array
    {
        if (!is_array($designSystem['metrics'] ?? null)) return $report;
        $report['design_system'] = array('schema' => (string) ($designSystem['schema'] ?? ''), 'metrics' => $designSystem['metrics']);
        return $report;
    }

    /** @param array<string,mixed> $report @param array<int,array<string,mixed>> $templates @return array<string,mixed> */
    public function withTemplateSurfaceSelection(array $report, array $templates): array
    {
        $selected = array();
        foreach ($templates as $template) if (is_array($template['template_surface'] ?? null) && is_string($template['source_path'] ?? null)) $selected[$template['source_path']] = $template;
        if (!is_array($report['documents'] ?? null)) return $report;
        foreach ($report['documents'] as &$document) {
            $template = $selected[$document['source_path'] ?? ''] ?? null;
            if (!is_array($template)) continue;
            $document['template_surface_selection'] = array('schema' => 'blocks-engine/template-surface-selection/v1', 'role' => $template['template_surface']['role'], 'slug' => $template['template_surface']['slug'], 'selected_source_path' => $template['source_path'], 'source_variants' => $template['template_surface']['source_variants'], 'declaration_provenance' => $template['template_surface']['declaration_provenance'], 'source_provenance' => $template['template_surface']['source_provenance']);
        }
        unset($document);
        return $report;
    }

    /**
     * @param array<int,array<string,mixed>> $blocks
     * @param array<int,int> $path
     * @param array<string,int|float> $metrics
     * @param array<string,int> $blockTypes
     * @param array<int,array<string,mixed>> $signals
     */

    /** @param array<int,array<string,mixed>> $sourceProvenance @return array<string,array<string,mixed>> */
    private function provenanceByBlockPath(array $sourceProvenance): array
    {
        $resolved = array();
        foreach ($sourceProvenance as $provenance) {
            if (!is_array($provenance) || !is_string($provenance['block_path'] ?? null)) continue;
            $path = preg_replace('/(?:^blocks\.|\.innerBlocks\.)/', '.', $provenance['block_path']);
            if (!is_string($path) || !preg_match('/^\.\d+(?:\.\d+)*$/', $path)) continue;
            $resolved[ltrim($path, '.')] = array_filter(array_intersect_key($provenance, array_flip(array('tag', 'selector', 'source_attributes', 'source_fragment', 'source_digest', 'source_bytes', 'source_path', 'context'))), static fn(mixed $value): bool => is_array($value) || '' !== $value);
        }
        return $resolved;
    }

    /** @param array<int,array<string,mixed>> $blocks @return array<string,mixed> */
    private function normalizationEvidence(array $blocks, string $deepestPath): array
    {
        if (!preg_match('/^\d+(?:\.\d+)*$/', $deepestPath)) return array();
        $current = $blocks;
        $entries = array();
        $firstBoundary = null;
        foreach (array_slice(explode('.', $deepestPath), 0, self::MAX_NORMALIZATION_PATH_ENTRIES) as $depth => $index) {
            $block = $current[(int) $index] ?? null;
            if (!is_array($block)) break;
            $name = (string) ($block['blockName'] ?? 'core/freeform');
            $entry = array('block_path' => implode('.', array_slice(explode('.', $deepestPath), 0, $depth + 1)), 'block_name' => $name);
            if (EditabilityWalk::isLayoutShell($name)) {
                $entry['kind'] = 'layout_shell';
                $entry['wrapper_count'] = count(is_array($block['attrs']['wrappers'] ?? null) ? $block['attrs']['wrappers'] : array());
                $entry['reason_code'] = 'folded_wrapper_chain';
            }
            $boundary = $block['_wrapper_normalization_boundary'] ?? null;
            if (is_string($boundary) && '' !== $boundary) {
                $entry['boundary_reason_code'] = $boundary;
                if (null === $firstBoundary) $firstBoundary = array('block_path' => $entry['block_path'], 'block_name' => $name, 'reason_code' => $boundary);
            }
            if (isset($entry['kind']) || isset($entry['boundary_reason_code'])) $entries[] = $entry;
            $current = ArrayField::array($block, 'innerBlocks');
        }
        return array_filter(array('path' => $entries, 'first_boundary' => $firstBoundary), static fn(mixed $value): bool => null !== $value && array() !== $value);
    }

    /** @param array<int,array<string,mixed>> $signals @return array<int,array<string,mixed>> */
    private function boundedSignals(array $signals): array
    {
        // Preserve actionable policy failures when a noisy document reaches the
        // evidence cap; aggregate counts still describe omitted lower-priority facts.
        usort($signals, static fn(array $left, array $right): int => ('structural_rich_text_attribute' === ($right['kind'] ?? '')) <=> ('structural_rich_text_attribute' === ($left['kind'] ?? '')));
        return array_slice($signals, 0, self::MAX_REPORTED_SIGNALS);
    }
}

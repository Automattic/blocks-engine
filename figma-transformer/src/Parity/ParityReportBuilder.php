<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Parity;

/**
 * Builds the stable parity report envelope from runner-supplied evidence.
 */
final class ParityReportBuilder
{
    public const SCHEMA = 'blocks-engine/figma-transformer/parity-report/v1';

    private const KNOWN_STATUSES = array('not_run', 'pending', 'compared', 'pass', 'fail');

    /**
     * @param array<string, mixed> $evidence
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function build(array $evidence = array(), array $overrides = array()): array
    {
        $status = (string) ($overrides['status'] ?? $evidence['status'] ?? 'not_run');
        if ( ! in_array($status, self::KNOWN_STATUSES, true) ) {
            $status = 'pending';
        }

        $artifacts = $this->arrayValue($evidence, 'artifacts');
        $layoutDiagnostics = $this->arrayValue($evidence, 'layout_diagnostics');
        $source = $this->arrayValue($evidence, 'source');
        $generated = $this->arrayValue($evidence, 'generated');
        $diff = $this->nullableArrayValue($evidence, 'diff');
        $diffSummary = $this->arrayValue($evidence, 'diff_summary');
        $metrics = $this->arrayValue($evidence, 'metrics');
        $viewport = $this->arrayValue($evidence, 'viewport');

        $this->copyScalar($evidence, 'source_screenshot_path', $source, 'screenshot_path');
        $this->copyScalar($evidence, 'source_screenshot_url', $source, 'screenshot_url');
        $this->copyScalar($evidence, 'source_screenshot_artifact', $source, 'screenshot_artifact');
        $this->copyScalar($evidence, 'generated_screenshot_path', $generated, 'screenshot_path');
        $this->copyScalar($evidence, 'generated_screenshot_url', $generated, 'screenshot_url');
        $this->copyScalar($evidence, 'generated_screenshot_artifact', $generated, 'screenshot_artifact');
        $this->copyScalar($evidence, 'diff_image_path', $diff, 'image_path');
        $this->copyScalar($evidence, 'diff_image_url', $diff, 'image_url');
        $this->copyScalar($evidence, 'diff_image_artifact', $diff, 'image_artifact');
        $this->copyScalar($evidence, 'report_path', $artifacts, 'report_path');
        $this->copyScalar($evidence, 'report_artifact', $artifacts, 'report_artifact');
        $this->copyScalar($evidence, 'dom_boxes_path', $artifacts, 'dom_boxes_path');
        $this->copyScalar($evidence, 'layout_report_path', $artifacts, 'layout_report_path');
        $this->copyScalar($evidence, 'layout_mismatch_report_path', $artifacts, 'layout_mismatch_report_path');
        $this->copyScalar($evidence, 'frame_id', $source, 'frame_id');
        $this->copyScalar($evidence, 'frame_id', $generated, 'frame_id');
        $this->copyNumeric($evidence, 'pixel_mismatch_count', $diffSummary, 'pixel_mismatch_count');
        $this->copyNumeric($evidence, 'pixel_mismatch_count', $metrics, 'pixel_mismatch_count');
        $this->copyNumeric($evidence, 'pixel_mismatch_ratio', $diffSummary, 'pixel_mismatch_ratio');
        $this->copyNumeric($evidence, 'pixel_mismatch_ratio', $metrics, 'pixel_mismatch_ratio');
        $this->copyNumeric($evidence, 'threshold', $diffSummary, 'threshold');
        $this->copyNumeric($evidence, 'layout_mismatch_count', $layoutDiagnostics, 'mismatch_count');

        if ( isset($evidence['layout_top_nodes']) && is_array($evidence['layout_top_nodes']) ) {
            $layoutDiagnostics['top_nodes'] = array_values($evidence['layout_top_nodes']);
        }

        if ( array_key_exists('threshold', $diffSummary) && array_key_exists('pixel_mismatch_ratio', $diffSummary) ) {
            $diffSummary['passed'] = (float) $diffSummary['pixel_mismatch_ratio'] <= (float) $diffSummary['threshold'];
        }

        return array(
            'schema'       => self::SCHEMA,
            'status'       => $status,
            'reason'       => (string) ($overrides['reason'] ?? $evidence['reason'] ?? ''),
            'artifacts'    => $artifacts,
            'source'       => $source,
            'generated'    => $generated,
            'side_by_side' => $evidence['side_by_side'] ?? null,
            'diff'         => empty($diff) ? null : $diff,
            'diff_summary' => $diffSummary,
            'layout_diagnostics' => $layoutDiagnostics,
            'metrics'      => $metrics,
            'viewport'     => $viewport,
        );
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function arrayValue(array $values, string $key): array
    {
        return isset($values[$key]) && is_array($values[$key]) ? $values[$key] : array();
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function nullableArrayValue(array $values, string $key): array
    {
        return isset($values[$key]) && is_array($values[$key]) ? $values[$key] : array();
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $target
     */
    private function copyScalar(array $source, string $sourceKey, array &$target, string $targetKey): void
    {
        if ( isset($source[$sourceKey]) && is_scalar($source[$sourceKey]) ) {
            $target[$targetKey] = (string) $source[$sourceKey];
        }
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $target
     */
    private function copyNumeric(array $source, string $sourceKey, array &$target, string $targetKey): void
    {
        if ( isset($source[$sourceKey]) && is_numeric($source[$sourceKey]) ) {
            $target[$targetKey] = str_contains((string) $source[$sourceKey], '.') ? (float) $source[$sourceKey] : (int) $source[$sourceKey];
        }
    }
}

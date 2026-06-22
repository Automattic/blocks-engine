<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Parity;

/**
 * Builds the stable parity report envelope from runner-supplied evidence.
 */
final class ParityReportBuilder
{
    public const SCHEMA = 'blocks-engine/figma-transformer/parity-report/v1';

    private const KNOWN_STATUSES = array('not_run', 'pending', 'compared');

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

        return array(
            'schema'       => self::SCHEMA,
            'status'       => $status,
            'reason'       => (string) ($overrides['reason'] ?? $evidence['reason'] ?? ''),
            'artifacts'    => $evidence['artifacts'] ?? array(),
            'source'       => $evidence['source'] ?? array(),
            'generated'    => $evidence['generated'] ?? array(),
            'side_by_side' => $evidence['side_by_side'] ?? null,
            'diff'         => $evidence['diff'] ?? null,
            'diff_summary' => $evidence['diff_summary'] ?? array(),
            'metrics'      => $evidence['metrics'] ?? array(),
        );
    }
}

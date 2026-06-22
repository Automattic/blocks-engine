<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Parity;

/**
 * Builds the stable parity report envelope from runner-supplied evidence.
 */
final class ParityReportBuilder
{
    public const SCHEMA = 'blocks-engine/figma-transformer/parity-report/v1';

    /**
     * @param array<string, mixed> $evidence
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function build(array $evidence = array(), array $overrides = array()): array
    {
        return array(
            'schema'    => self::SCHEMA,
            'status'    => (string) ($overrides['status'] ?? $evidence['status'] ?? 'not_run'),
            'reason'    => (string) ($overrides['reason'] ?? $evidence['reason'] ?? ''),
            'source'    => $evidence['source'] ?? array(),
            'generated' => $evidence['generated'] ?? array(),
            'side_by_side' => $evidence['side_by_side'] ?? null,
            'diff'      => $evidence['diff'] ?? null,
            'metrics'   => $evidence['metrics'] ?? array(),
        );
    }
}

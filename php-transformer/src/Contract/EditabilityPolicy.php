<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Contract;

/** Enforces bounded, source-agnostic editability limits on a measured report. */
final class EditabilityPolicy
{
    public const SCHEMA = 'blocks-engine/php-transformer/editability-policy/v1';
    public const MAX_REPORTED_FAILURES = 100;

    /**
     * These limits reject block trees that are impractical in List View while
     * allowing ordinary documents and leaving visual-parity scoring separate.
     */
    public const THRESHOLDS = array(
        'empty_wrapper_count' => 10,
        'structural_rich_text_attribute_count' => 0,
        'max_nesting_depth' => 20,
        'wrapper_to_content_ratio' => 4.0,
    );

    /** @param array<string,mixed> $report @return array<string,mixed> */
    public function evaluate(array $report): array
    {
        $failures = array();
        if (is_array($report['documents'] ?? null)) {
            foreach ($report['documents'] as $document) {
                if (!is_array($document)) continue;
                $failures = array_merge($failures, $this->failures(
                    is_array($document['metrics'] ?? null) ? $document['metrics'] : array(),
                    is_string($document['source_path'] ?? null) ? $document['source_path'] : '',
                    is_array($document['deepest_block'] ?? null) ? $document['deepest_block'] : array()
                ));
            }
        } else {
            $scope = is_array($report['scope'] ?? null) ? $report['scope'] : array();
            $failures = $this->failures(
                is_array($report['metrics'] ?? null) ? $report['metrics'] : array(),
                is_string($scope['source_path'] ?? null) ? $scope['source_path'] : '',
                is_array($report['deepest_block'] ?? null) ? $report['deepest_block'] : array()
            );
        }

        $failureCount = count($failures);
        return array(
            'schema' => self::SCHEMA,
            'enforcement' => 'required',
            'status' => 0 === $failureCount ? 'passed' : 'failed',
            'thresholds' => self::THRESHOLDS,
            'failures' => array_slice($failures, 0, self::MAX_REPORTED_FAILURES),
            'failure_totals' => array(
                'observed' => $failureCount,
                'reported' => min($failureCount, self::MAX_REPORTED_FAILURES),
                'omitted' => max(0, $failureCount - self::MAX_REPORTED_FAILURES),
                'truncated' => $failureCount > self::MAX_REPORTED_FAILURES,
            ),
        );
    }

    /** @param array<string,mixed> $metrics @return array<int,array<string,mixed>> */
    private function failures(array $metrics, string $sourcePath, array $deepestBlock = array()): array
    {
        $failures = array();
        foreach (self::THRESHOLDS as $metric => $maximum) {
            $actual = $metrics[$metric] ?? 0;
            if ($actual <= $maximum) continue;
            $failure = array(
                'metric' => $metric,
                'actual' => $actual,
                'maximum' => $maximum,
                'message' => sprintf('%s is %s; meaningful editability allows at most %s.', $metric, $actual, $maximum),
            );
            if ('' !== $sourcePath) $failure['source_path'] = $sourcePath;
            if ('max_nesting_depth' === $metric && array() !== $deepestBlock) $failure['deepest_block'] = $deepestBlock;
            $failures[] = $failure;
        }
        return $failures;
    }
}

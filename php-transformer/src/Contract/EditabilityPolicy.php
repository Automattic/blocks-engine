<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Contract;

/** Enforces substantive, source-agnostic editability limits on a measured report. */
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

    /**
     * @param array<string,mixed> $report
     * @param string|null $entrypointPath Site compiles pass their entrypoint so every
     *        breach becomes a per-page `finding`. Without it every
     *        breach is a blocking failure (the strict self-consistency gate).
     * @return array<string,mixed>
     */
    public function evaluate(array $report, ?string $entrypointPath = null): array
    {
        $breaches = array();
        if (is_array($report['documents'] ?? null)) {
            foreach ($report['documents'] as $document) {
                if (!is_array($document)) continue;
                $breaches = array_merge($breaches, $this->breaches(
                    is_array($document['metrics'] ?? null) ? $document['metrics'] : array(),
                    is_string($document['source_path'] ?? null) ? $document['source_path'] : ''
                ));
            }
        } else {
            $scope = is_array($report['scope'] ?? null) ? $report['scope'] : array();
            $breaches = $this->breaches(
                is_array($report['metrics'] ?? null) ? $report['metrics'] : array(),
                is_string($scope['source_path'] ?? null) ? $scope['source_path'] : ''
            );
        }

        $failures = array();
        $findings = array();
        foreach ($breaches as $breach) {
            // Without a site entrypoint this is the strict self-consistency check.
            // Site compiles keep every page and report each breach as a finding.
            if (null === $entrypointPath) {
                $failures[] = $breach;
            } else {
                $findings[] = $breach;
            }
        }

        return array(
            'schema' => self::SCHEMA,
            'enforcement' => 'required',
            'status' => array() === $failures ? 'passed' : 'failed',
            'thresholds' => self::THRESHOLDS,
            'failures' => array_slice($failures, 0, self::MAX_REPORTED_FAILURES),
            'failure_totals' => self::totals(count($failures)),
            'findings' => array_slice($findings, 0, self::MAX_REPORTED_FAILURES),
            'finding_totals' => self::totals(count($findings)),
        );
    }

    /** @return array<string,int|bool> */
    private static function totals(int $count): array
    {
        return array(
            'observed' => $count,
            'reported' => min($count, self::MAX_REPORTED_FAILURES),
            'omitted' => max(0, $count - self::MAX_REPORTED_FAILURES),
            'truncated' => $count > self::MAX_REPORTED_FAILURES,
        );
    }

    /** @param array<string,mixed> $metrics @return array<int,array<string,mixed>> */
    private function breaches(array $metrics, string $sourcePath): array
    {
        $breaches = array();
        foreach (self::THRESHOLDS as $metric => $maximum) {
            // Depth remains measured with its threshold and provenance, but it is
            // diagnostic evidence rather than a rejecting editability condition.
            if ('max_nesting_depth' === $metric) continue;
            $actual = $metrics[$metric] ?? 0;
            if ($actual <= $maximum) continue;
            $failure = array(
                'metric' => $metric,
                'actual' => $actual,
                'maximum' => $maximum,
                'message' => sprintf('%s is %s; meaningful editability allows at most %s.', $metric, $actual, $maximum),
            );
            if ('' !== $sourcePath) $failure['source_path'] = $sourcePath;
            $breaches[] = $failure;
        }
        return $breaches;
    }
}

<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/**
 * The units one site plan run dropped or rewrote instead of failing.
 *
 * Collaborators record each finding through `record()`; the plan reports them
 * once as warning diagnostics through `diagnostics()`. Build one per run.
 */
final class UnitFindings
{
    /** @var array<string,array<string,mixed>> Dropped or rewritten units, keyed by their identity fields. */
    private array $findings = array();

    /**
     * Clips one diagnostic field to its budget without splitting a UTF-8
     * sequence. A byte-wise cut can leave an invalid string, and planIdentity()
     * json_encode()s every diagnostic under JSON_THROW_ON_ERROR, so a split
     * sequence would discard the whole plan -- the failure this omission exists
     * to avoid, one layer up.
     */
    public static function clip(string $value): string { return mb_strcut($value, 0, 256, 'UTF-8'); }

    /**
     * Records one unit the plan dropped or rewrote instead of failing. Findings
     * with the same fields are one row whose `omitted_count` counts them.
     *
     * @param array<string,mixed> $finding code, unit, source_path, reason_code, message, metrics and unit identity fields.
     */
    public function record(array $finding): void
    {
        $finding = array_map(static fn(mixed $value): mixed => is_string($value) ? self::clip($value) : $value, $finding);
        $key = json_encode($finding, JSON_THROW_ON_ERROR);
        if (isset($this->findings[$key])) { ++$this->findings[$key]['omitted_count']; return; }
        $this->findings[$key] = array('code' => $finding['code'], 'severity' => 'warning') + $finding + array('omitted_count' => 1);
    }

    /** @return array<int,array<string,mixed>> */
    public function diagnostics(): array { return array_values($this->findings); }

    /** A plan that carries warning findings is at best `success_with_warnings`. @param array<int,array<string,mixed>> $findings */
    public static function status(string $status, array $findings): string
    {
        return 'success' === $status && array() !== $findings ? 'success_with_warnings' : $status;
    }
}

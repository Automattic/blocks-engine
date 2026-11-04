<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Contract;

/** Controls optional validation reports, never required validation facts. */
final class ValidationEvidencePolicy
{
    public const FULL = 'full';
    public const COMPACT = 'compact';
    public const NONE = 'none';

    private function __construct(public readonly string $detail)
    {
    }

    /** @param array<string, mixed> $options */
    public static function fromOptions(array $options): self
    {
        $detail = array_key_exists('validation_evidence', $options) ? $options['validation_evidence'] : self::FULL;
        if (!is_string($detail) || !in_array($detail, array(self::FULL, self::COMPACT, self::NONE), true)) {
            throw new \InvalidArgumentException('validation_evidence must be "full", "compact" or "none".');
        }

        return new self($detail);
    }

    public function includesReports(): bool
    {
        return self::NONE !== $this->detail;
    }
}

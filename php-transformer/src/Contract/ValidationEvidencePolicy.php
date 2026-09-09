<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Contract;

/** Controls optional detail in validation-report projections. */
final class ValidationEvidencePolicy
{
    public const FULL = 'full';
    public const COMPACT = 'compact';

    private function __construct(public readonly string $detail)
    {
    }

    /** @param array<string, mixed> $options */
    public static function fromOptions(array $options): self
    {
        $detail = array_key_exists('validation_evidence', $options) ? $options['validation_evidence'] : self::FULL;
        if (!is_string($detail) || !in_array($detail, array(self::FULL, self::COMPACT), true)) {
            throw new \InvalidArgumentException('validation_evidence must be "full" or "compact".');
        }

        return new self($detail);
    }
}

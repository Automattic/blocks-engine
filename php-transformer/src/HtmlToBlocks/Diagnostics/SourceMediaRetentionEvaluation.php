<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics;

use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionFindingContract;

/**
 * The single source-media retention evaluation, projected as a detailed report
 * or consumed directly by required validation diagnostics.
 */
final class SourceMediaRetentionEvaluation
{
    /** @param array<int, array<string, mixed>> $findings */
    public function __construct(
        public readonly array $findings
    ) {
    }

    public function status(): string
    {
        return array() === $this->findings ? 'pass' : 'error';
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        return array(
            'schema'         => SourceMediaRetentionReporter::SCHEMA,
            'finding_schema' => ConversionFindingContract::SCHEMA,
            'status'         => $this->status(),
            'findings'       => $this->findings,
        );
    }
}

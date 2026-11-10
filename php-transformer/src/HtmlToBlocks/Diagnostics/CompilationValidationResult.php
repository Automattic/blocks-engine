<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics;

use Automattic\BlocksEngine\PhpTransformer\Contract\HtmlValidationOutcome;

/** Reports, outcome and diagnostics produced by CompilationValidation. */
final class CompilationValidationResult
{
    /**
     * @param array<string, mixed> $blockValidityReport
     * @param array<string, mixed> $semanticParityReport
     * @param array<string, mixed> $contentRoundTripReport
     * @param array<string, mixed> $sourceMediaRetentionReport
     * @param array<string, mixed> $deadProjectedSelectorsReport
     * @param array<int, array<string, mixed>> $diagnostics
     */
    public function __construct(
        public readonly array $blockValidityReport,
        public readonly array $semanticParityReport,
        public readonly array $contentRoundTripReport,
        public readonly array $sourceMediaRetentionReport,
        public readonly array $deadProjectedSelectorsReport,
        public readonly HtmlValidationOutcome $outcome,
        public readonly array $diagnostics
    ) {
    }
}

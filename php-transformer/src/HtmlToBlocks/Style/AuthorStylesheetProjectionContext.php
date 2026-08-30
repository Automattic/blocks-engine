<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\TransformationEvidenceState;

/** Explicit per-transform inputs for authored stylesheet projection. */
final class AuthorStylesheetProjectionContext
{
    public function __construct(
        public readonly AuthorStyleAnalysis $authorStyles,
        public readonly SourceStyleResolutionState $sourceStyles,
        public readonly AuthorSelectorProjectionState $selectorProjections,
        public readonly TransformationEvidenceState $evidence
    ) {}
}

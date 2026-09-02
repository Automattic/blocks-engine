<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

/** Per-transform state used to project source identity onto block attributes. */
final class SourceBlockAttributeProjectionContext
{
    public function __construct(
        public readonly AuthorStyleAnalysis $authorStyles,
        public readonly AuthorSelectorProjectionState $selectorProjections,
        public readonly GeneratedSupportStylesheetState $generatedStyles
    ) {}
}

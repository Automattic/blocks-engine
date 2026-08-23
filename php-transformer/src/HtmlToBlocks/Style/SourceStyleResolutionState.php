<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

/** Per-transform source stylesheet matching and declaration state. */
final class SourceStyleResolutionState
{
    public ?CssSelectorMatchCache $selectorMatchCache = null;

    /** @var array<string, array<string, mixed>> */
    public array $ruleCandidateIndexes = array();

    /** @var array<string, array<string, array<int, string>>> */
    public array $authorDeclaredPropertyValues = array();
}

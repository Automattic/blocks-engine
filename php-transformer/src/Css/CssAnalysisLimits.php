<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Shared bounds for source-preserving CSS analysis consumers. */
final class CssAnalysisLimits
{
    // Allows a complete author stylesheet while retaining a bounded parser input.
    public const MAX_STYLESHEET_BYTES = 4194304;
}

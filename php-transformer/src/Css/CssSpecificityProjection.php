<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Restore authored weight after a source selector is replaced by neutral native transport. */
final class CssSpecificityProjection
{
    /** Compound weight only; the caller retains the authored dynamic state on the native target. @param array<string, mixed> $parsed */
    public static function shims(array $parsed, string $type, string $class, string $id): string
    {
        $weight = CssSelectorMatcher::specificityCounts($parsed, false);
        return str_repeat(':not(' . $type . ')', $weight['types'])
            . str_repeat(':not(.' . $class . ')', $weight['classes'])
            . str_repeat(':not(#' . $id . ')', $weight['ids']);
    }
}

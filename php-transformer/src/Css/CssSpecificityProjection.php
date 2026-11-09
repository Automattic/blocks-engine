<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Restore authored weight after a source selector is replaced by neutral native transport. */
final class CssSpecificityProjection
{
    /** Compound weight only; the caller retains the authored dynamic state on the native target. @param array<string, mixed> $parsed */
    public static function shims(array $parsed, string $type, string $class, string $id, bool $includeState = false): string
    {
        $weight = CssSelectorMatcher::specificityCounts($parsed, $includeState);
        return self::guard($type, $weight['types'])
            . self::guard('.' . $class, $weight['classes'])
            . self::guard('#' . $id, $weight['ids']);
    }

    /** A single never-matching compound carries the requested weight. */
    public static function guard(string $token, int $count): string
    {
        if (0 === $count) return '';
        $number = (int) substr($token, (int) strrpos($token, '-') + 1);
        $prefix = substr($token, 0, (int) strrpos($token, '-') + 1);
        $tokens = array();
        for ($index = 0; $index < $count; $index++) {
            $tokens[] = $prefix . ($number + $index);
        }
        // The first token is collision-allocated and absent from the source;
        // the numbered compound (or type chain) therefore never matches.
        $separator = str_starts_with($token, '.') || str_starts_with($token, '#') ? '' : ' ';
        return ':not(' . implode($separator, $tokens) . ')';
    }
}

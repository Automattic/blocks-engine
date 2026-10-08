<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

/**
 * Attribute names that cannot be string array keys.
 *
 * HTML allows an attribute named `0` or `512`. A script can set such a name,
 * and libxml 2.14+ keeps it while parsing (older libxml drops it). PHP stores
 * a decimal-integer string key as an int, so a map of attributes keyed by name
 * would give string code an int, which throws a TypeError under strict_types.
 * No HTML or SVG attribute has such a name, so the engine drops it when it
 * reads attributes.
 */
final class HtmlAttributeName
{
    /** True for names PHP would store as an int array key (`0`, `512`, `-1`). */
    public static function isIntegerKey(string $name): bool
    {
        return (string) (int) $name === $name;
    }
}

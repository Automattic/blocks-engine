<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/**
 * Declaration names that cannot be CSS properties.
 *
 * A declaration name must be an identifier. Some generators write broken
 * declarations such as `0: none`; the name starts like a number, so browsers
 * drop the declaration. PHP also stores a name like `0` or `12` as an int
 * array key, and a map of declarations keyed by name would then give string
 * code an int, which throws a TypeError under strict_types. Parsers that build
 * such maps skip these names, the same way a browser does.
 */
final class CssPropertyName
{
    /**
     * True for names that start like a CSS number (`0`, `12`, `-1`, `+1`,
     * `.5`, `1px`). Every name PHP would store as an int key is one of them.
     */
    public static function startsLikeNumber(string $name): bool
    {
        return 1 === preg_match('/^[+-]?\.?[0-9]/', $name);
    }
}

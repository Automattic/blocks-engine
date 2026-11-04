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
     * Known browser properties, plus custom properties and prefixed aliases.
     * The bundled name list comes from mdn-data 2.27.1 css/properties.json
     * (CC0); values are deliberately left to the existing declaration parser.
     */
    public static function isKnown(string $name): bool
    {
        if (str_starts_with($name, '--')) return true;
        static $names = null;
        $names ??= array_fill_keys(json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/resources/css-property-names.json'), true), true);
        return isset($names[$name]) || (1 === preg_match('/^-(?:webkit|moz|ms|o)-(.+)$/', $name, $match) && isset($names[$match[1]]));
    }

    /**
     * True for names that start like a CSS number (`0`, `12`, `-1`, `+1`,
     * `.5`, `1px`). Every name PHP would store as an int key is one of them.
     */
    public static function startsLikeNumber(string $name): bool
    {
        return 1 === preg_match('/^[+-]?\.?[0-9]/', $name);
    }
}

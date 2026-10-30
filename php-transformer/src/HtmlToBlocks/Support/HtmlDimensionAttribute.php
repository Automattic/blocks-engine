<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

/**
 * Reads an HTML `width`/`height` attribute the way browsers do.
 *
 * The HTML standard's "rules for parsing dimension values" read a leading
 * non-negative number after optional whitespace and stop at the first
 * character that cannot continue it. A `%` directly after the number makes the
 * value a percentage; any other trailing text (`px`, `em`, junk) is ignored and
 * the number is a CSS pixel length. So `width="42px"` means 42 pixels, exactly
 * like `width="42"`, and `width="50%"` stays relative.
 *
 * Every reader of an `<img>`/`<video>`/`<input type=image>` dimension
 * attribute goes through this one helper instead of its own regex.
 *
 * @see https://html.spec.whatwg.org/multipage/common-microsyntaxes.html#rules-for-parsing-dimension-values
 */
final class HtmlDimensionAttribute
{
    /**
     * @return array{value: float, percentage: bool}|null Null when the
     *         attribute does not start with a number (an error per the spec).
     */
    public static function parse(string $value): ?array
    {
        if ( 1 !== preg_match('/^[\t\n\f\r ]*(\d+(?:\.\d+)?|\.\d+)(%?)/', $value, $matches) ) {
            return null;
        }

        return array(
            'value' => (float) $matches[1],
            'percentage' => '%' === $matches[2],
        );
    }

    /** The attribute's length in CSS pixels, or null for a percentage or an unparsable value. */
    public static function pixels(string $value): ?float
    {
        $dimension = self::parse($value);

        return null === $dimension || $dimension['percentage'] ? null : $dimension['value'];
    }

    /** The attribute as a CSS length (`42px`, `50%`), or '' when it does not parse. */
    public static function cssLength(string $value): string
    {
        $dimension = self::parse($value);
        if ( null === $dimension ) {
            return '';
        }

        return self::number($dimension['value']) . ($dimension['percentage'] ? '%' : 'px');
    }

    /** The attribute as a pixel count for a re-emitted HTML attribute (`42`), or ''. */
    public static function pixelAttribute(string $value): string
    {
        $pixels = self::pixels($value);

        return null === $pixels ? '' : self::number($pixels);
    }

    private static function number(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

        return '' === $formatted ? '0' : $formatted;
    }
}

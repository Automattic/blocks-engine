<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/**
 * Converts absolute and font-relative CSS lengths to CSS pixels.
 *
 * Only lengths with a definite pixel value are converted: `px`, `pt`, `pc`,
 * `in`, `cm`, `mm`, `q`, unitless zero, `rem` (against the document root
 * size) and `em` (against the supplied font size). A `calc()` that scales
 * one such length by a number (`calc(0.25rem * 4)`, the form Tailwind v4
 * spacing utilities resolve to) is converted too. Percentages, viewport
 * units, other `calc()` expressions and keywords return null because their
 * pixel value depends on layout.
 */
final class CssLength
{
    /** Absolute-size font-size keywords at the CSS initial 16px medium. */
    public const FONT_SIZE_KEYWORDS = array(
        'xx-small' => 9.0, 'x-small' => 10.0, 'small' => 13.0, 'medium' => 16.0,
        'large' => 18.0, 'x-large' => 24.0, 'xx-large' => 32.0, 'xxx-large' => 48.0,
    );

    public static function toPx(string $value, float $rootPx = 16.0, ?float $emPx = null): ?float
    {
        $value = strtolower(trim((string) preg_replace('/\s*!\s*important\s*$/i', '', $value)));
        if (str_starts_with($value, 'calc(')) return self::scaledLength($value, $rootPx, $emPx);
        if (1 !== preg_match('/^(-?(?:\d+\.?\d*|\.\d+))(px|pt|pc|in|cm|mm|q|rem|em)?$/', $value, $match)) return null;
        $number = (float) $match[1];
        $unit = $match[2] ?? '';
        if ('' === $unit) return 0.0 === $number ? 0.0 : null;
        $px = match ($unit) {
            'px' => $number,
            'pt' => $number * 4 / 3,
            'pc' => $number * 16,
            'in' => $number * 96,
            'cm' => $number * 96 / 2.54,
            'mm' => $number * 96 / 25.4,
            'q' => $number * 96 / 101.6,
            'rem' => $number * $rootPx,
            'em' => null === $emPx ? null : $number * $emPx,
        };
        return null === $px ? null : round($px, 3);
    }

    /** `calc(<length> * <number>)`, `calc(<number> * <length>)` or `calc(<length> / <number>)`. */
    private static function scaledLength(string $value, float $rootPx, ?float $emPx): ?float
    {
        $number = '(-?(?:\d+\.?\d*|\.\d+))';
        $length = '(-?(?:\d+\.?\d*|\.\d+)[a-z]*)';
        if (1 === preg_match('/^calc\(\s*' . $length . '\s*([*\/])\s*' . $number . '\s*\)$/', $value, $match)) {
            $px = self::toPx($match[1], $rootPx, $emPx);
            if (null === $px || ('/' === $match[2] && 0.0 === (float) $match[3])) return null;
            return round('*' === $match[2] ? $px * (float) $match[3] : $px / (float) $match[3], 3);
        }
        if (1 === preg_match('/^calc\(\s*' . $number . '\s*\*\s*' . $length . '\s*\)$/', $value, $match)) {
            $px = self::toPx($match[2], $rootPx, $emPx);
            return null === $px ? null : round($px * (float) $match[1], 3);
        }
        return null;
    }

    /** Compact pixel text for reports and deterministic keys. */
    public static function format(float $px): string
    {
        $text = rtrim(rtrim(number_format($px, 3, '.', ''), '0'), '.');
        return ('' === $text || '-0' === $text ? '0' : $text) . 'px';
    }
}

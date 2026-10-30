<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/**
 * Parses CSS colour values into sRGB and measures perceptual distance between
 * them.
 *
 * The parser reads hex notation (3, 4, 6 or 8 digits), `rgb()`/`rgba()`,
 * `hsl()`/`hsla()`, `oklab()`/`oklch()` (Tailwind v4 emits these), CIE
 * `lab()`/`lch()` (Lightning CSS emits these after a hex fallback), the CSS
 * named colours, and `transparent`. Any other notation (`color-mix()`,
 * `currentColor`, system colours, relative colour syntax) is null: it is
 * either not a design token or cannot be resolved without a rendering engine.
 *
 * Perceptual distance is CIE76 ΔE*ab in CIELAB (D65). A ΔE of about 2.3 is
 * the commonly cited just-noticeable difference.
 */
final class CssColor
{
    /** CIE76 ΔE*ab treated as one just-noticeable difference. */
    public const JUST_NOTICEABLE_DIFFERENCE = 2.3;

    private const NAMED = array(
        'aliceblue' => 'f0f8ff', 'antiquewhite' => 'faebd7', 'aqua' => '00ffff', 'aquamarine' => '7fffd4', 'azure' => 'f0ffff',
        'beige' => 'f5f5dc', 'bisque' => 'ffe4c4', 'black' => '000000', 'blanchedalmond' => 'ffebcd', 'blue' => '0000ff',
        'blueviolet' => '8a2be2', 'brown' => 'a52a2a', 'burlywood' => 'deb887', 'cadetblue' => '5f9ea0', 'chartreuse' => '7fff00',
        'chocolate' => 'd2691e', 'coral' => 'ff7f50', 'cornflowerblue' => '6495ed', 'cornsilk' => 'fff8dc', 'crimson' => 'dc143c',
        'cyan' => '00ffff', 'darkblue' => '00008b', 'darkcyan' => '008b8b', 'darkgoldenrod' => 'b8860b', 'darkgray' => 'a9a9a9',
        'darkgreen' => '006400', 'darkgrey' => 'a9a9a9', 'darkkhaki' => 'bdb76b', 'darkmagenta' => '8b008b', 'darkolivegreen' => '556b2f',
        'darkorange' => 'ff8c00', 'darkorchid' => '9932cc', 'darkred' => '8b0000', 'darksalmon' => 'e9967a', 'darkseagreen' => '8fbc8f',
        'darkslateblue' => '483d8b', 'darkslategray' => '2f4f4f', 'darkslategrey' => '2f4f4f', 'darkturquoise' => '00ced1', 'darkviolet' => '9400d3',
        'deeppink' => 'ff1493', 'deepskyblue' => '00bfff', 'dimgray' => '696969', 'dimgrey' => '696969', 'dodgerblue' => '1e90ff',
        'firebrick' => 'b22222', 'floralwhite' => 'fffaf0', 'forestgreen' => '228b22', 'fuchsia' => 'ff00ff', 'gainsboro' => 'dcdcdc',
        'ghostwhite' => 'f8f8ff', 'gold' => 'ffd700', 'goldenrod' => 'daa520', 'gray' => '808080', 'green' => '008000',
        'greenyellow' => 'adff2f', 'grey' => '808080', 'honeydew' => 'f0fff0', 'hotpink' => 'ff69b4', 'indianred' => 'cd5c5c',
        'indigo' => '4b0082', 'ivory' => 'fffff0', 'khaki' => 'f0e68c', 'lavender' => 'e6e6fa', 'lavenderblush' => 'fff0f5',
        'lawngreen' => '7cfc00', 'lemonchiffon' => 'fffacd', 'lightblue' => 'add8e6', 'lightcoral' => 'f08080', 'lightcyan' => 'e0ffff',
        'lightgoldenrodyellow' => 'fafad2', 'lightgray' => 'd3d3d3', 'lightgreen' => '90ee90', 'lightgrey' => 'd3d3d3', 'lightpink' => 'ffb6c1',
        'lightsalmon' => 'ffa07a', 'lightseagreen' => '20b2aa', 'lightskyblue' => '87cefa', 'lightslategray' => '778899', 'lightslategrey' => '778899',
        'lightsteelblue' => 'b0c4de', 'lightyellow' => 'ffffe0', 'lime' => '00ff00', 'limegreen' => '32cd32', 'linen' => 'faf0e6',
        'magenta' => 'ff00ff', 'maroon' => '800000', 'mediumaquamarine' => '66cdaa', 'mediumblue' => '0000cd', 'mediumorchid' => 'ba55d3',
        'mediumpurple' => '9370db', 'mediumseagreen' => '3cb371', 'mediumslateblue' => '7b68ee', 'mediumspringgreen' => '00fa9a', 'mediumturquoise' => '48d1cc',
        'mediumvioletred' => 'c71585', 'midnightblue' => '191970', 'mintcream' => 'f5fffa', 'mistyrose' => 'ffe4e1', 'moccasin' => 'ffe4b5',
        'navajowhite' => 'ffdead', 'navy' => '000080', 'oldlace' => 'fdf5e6', 'olive' => '808000', 'olivedrab' => '6b8e23',
        'orange' => 'ffa500', 'orangered' => 'ff4500', 'orchid' => 'da70d6', 'palegoldenrod' => 'eee8aa', 'palegreen' => '98fb98',
        'paleturquoise' => 'afeeee', 'palevioletred' => 'db7093', 'papayawhip' => 'ffefd5', 'peachpuff' => 'ffdab9', 'peru' => 'cd853f',
        'pink' => 'ffc0cb', 'plum' => 'dda0dd', 'powderblue' => 'b0e0e6', 'purple' => '800080', 'rebeccapurple' => '663399',
        'red' => 'ff0000', 'rosybrown' => 'bc8f8f', 'royalblue' => '4169e1', 'saddlebrown' => '8b4513', 'salmon' => 'fa8072',
        'sandybrown' => 'f4a460', 'seagreen' => '2e8b57', 'seashell' => 'fff5ee', 'sienna' => 'a0522d', 'silver' => 'c0c0c0',
        'skyblue' => '87ceeb', 'slateblue' => '6a5acd', 'slategray' => '708090', 'slategrey' => '708090', 'snow' => 'fffafa',
        'springgreen' => '00ff7f', 'steelblue' => '4682b4', 'tan' => 'd2b48c', 'teal' => '008080', 'thistle' => 'd8bfd8',
        'tomato' => 'ff6347', 'turquoise' => '40e0d0', 'violet' => 'ee82ee', 'wheat' => 'f5deb3', 'white' => 'ffffff',
        'whitesmoke' => 'f5f5f5', 'yellow' => 'ffff00', 'yellowgreen' => '9acd32',
    );

    /**
     * @return array{0:float,1:float,2:float,3:float}|null sRGB channels 0–255 and alpha 0–1.
     */
    public static function parse(string $value): ?array
    {
        $value = strtolower(trim((string) preg_replace('/\s*!\s*important\s*$/i', '', $value)));
        if ('' === $value) return null;
        if ('transparent' === $value) return array(0.0, 0.0, 0.0, 0.0);
        if (isset(self::NAMED[$value])) $value = '#' . self::NAMED[$value];
        if (1 === preg_match('/^#([0-9a-f]{3,8})$/', $value, $match)) {
            $hex = $match[1];
            if (3 === strlen($hex) || 4 === strlen($hex)) $hex = implode('', array_map(static fn(string $c): string => $c . $c, str_split($hex)));
            if (6 !== strlen($hex) && 8 !== strlen($hex)) return null;
            $alpha = 8 === strlen($hex) ? hexdec(substr($hex, 6, 2)) / 255 : 1.0;
            return array((float) hexdec(substr($hex, 0, 2)), (float) hexdec(substr($hex, 2, 2)), (float) hexdec(substr($hex, 4, 2)), round($alpha, 4));
        }
        if (1 !== preg_match('/^(rgba?|hsla?|oklab|oklch|lab|lch)\(\s*([^()]*)\)$/', $value, $match)) return null;
        $arguments = self::arguments($match[2]);
        if (null === $arguments) return null;
        [$channels, $alpha] = $arguments;
        $alpha = null === $alpha ? 1.0 : self::alpha($alpha);
        if (null === $alpha) return null;
        $color = match ($match[1]) {
            'rgb', 'rgba' => self::rgb($channels),
            'hsl', 'hsla' => self::hsl($channels),
            'oklab' => self::oklab($channels, false),
            'oklch' => self::oklab($channels, true),
            'lab' => self::cieLab($channels, false),
            'lch' => self::cieLab($channels, true),
        };
        if (null === $color) return null;
        $color[] = $alpha;
        return $color;
    }

    /** Canonical lowercase `#rrggbb` (or `#rrggbbaa` when translucent), or null. */
    public static function hex(string $value): ?string
    {
        $color = self::parse($value);
        if (null === $color) return null;
        $hex = sprintf('#%02x%02x%02x', (int) round($color[0]), (int) round($color[1]), (int) round($color[2]));
        return $color[3] >= 1.0 ? $hex : $hex . sprintf('%02x', (int) round($color[3] * 255));
    }

    /** Whether the value parses to a fully opaque colour. */
    public static function opaque(string $value): bool
    {
        $color = self::parse($value);
        return null !== $color && $color[3] >= 1.0;
    }

    /** @return array{0:float,1:float,2:float} CIELAB L*, a*, b* under D65. */
    public static function lab(string $value): ?array
    {
        $color = self::parse($value);
        if (null === $color) return null;
        $linear = array_map(static function (float $channel): float {
            $channel /= 255;
            return $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
        }, array($color[0], $color[1], $color[2]));
        $x = (0.4124564 * $linear[0] + 0.3575761 * $linear[1] + 0.1804375 * $linear[2]) / 0.95047;
        $y = 0.2126729 * $linear[0] + 0.7151522 * $linear[1] + 0.0721750 * $linear[2];
        $z = (0.0193339 * $linear[0] + 0.1191920 * $linear[1] + 0.9503041 * $linear[2]) / 1.08883;
        $f = static fn(float $t): float => $t > 216 / 24389 ? $t ** (1 / 3) : (24389 / 27 * $t + 16) / 116;
        return array(116 * $f($y) - 16, 500 * ($f($x) - $f($y)), 200 * ($f($y) - $f($z)));
    }

    /** CIE76 ΔE*ab, or null when either value is not a parseable colour. */
    public static function distance(string $left, string $right): ?float
    {
        $a = self::lab($left);
        $b = self::lab($right);
        if (null === $a || null === $b) return null;
        return sqrt(($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2);
    }

    /** CIELAB chroma: how far a colour is from neutral grey. */
    public static function chroma(string $value): ?float
    {
        $lab = self::lab($value);
        return null === $lab ? null : sqrt($lab[1] ** 2 + $lab[2] ** 2);
    }

    /**
     * WCAG 2 contrast ratio between two colours, from 1 (identical luminance)
     * to 21 (black on white), or null when either is not a parseable colour.
     */
    public static function contrastRatio(string $left, string $right): ?float
    {
        $a = self::relativeLuminance($left);
        $b = self::relativeLuminance($right);
        if (null === $a || null === $b) return null;
        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /** WCAG 2 relative luminance of the colour's sRGB channels. */
    private static function relativeLuminance(string $value): ?float
    {
        $color = self::parse($value);
        if (null === $color) return null;
        $linear = array_map(static function (float $channel): float {
            $channel /= 255;
            return $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
        }, array($color[0], $color[1], $color[2]));
        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }

    /** CIELAB lightness, 0 (black) to 100 (white). */
    public static function lightness(string $value): ?float
    {
        $lab = self::lab($value);
        return null === $lab ? null : $lab[0];
    }

    /** @return array{0:list<string>,1:?string}|null */
    private static function arguments(string $body): ?array
    {
        $body = trim($body);
        $alpha = null;
        if (str_contains($body, '/')) {
            [$body, $alpha] = array_map('trim', explode('/', $body, 2));
        }
        $parts = str_contains($body, ',') ? array_map('trim', explode(',', $body)) : (preg_split('/\s+/', $body) ?: array());
        if (4 === count($parts) && null === $alpha) $alpha = array_pop($parts);
        if (3 !== count($parts)) return null;
        foreach ($parts as $part) if (1 !== preg_match('/^-?(?:\d+\.?\d*|\.\d+)(?:%|deg)?$/', $part)) return null;
        return array($parts, $alpha);
    }

    private static function alpha(string $value): ?float
    {
        if (1 !== preg_match('/^(\d+\.?\d*|\.\d+)(%)?$/', trim($value), $match)) return null;
        $alpha = (float) $match[1] / (isset($match[2]) ? 100 : 1);
        return round(max(0.0, min(1.0, $alpha)), 4);
    }

    private static function number(string $value, float $percentScale): float
    {
        return str_ends_with($value, '%') ? (float) rtrim($value, '%') * $percentScale / 100 : (float) preg_replace('/deg$/', '', $value);
    }

    /** @param list<string> $channels @return list<float> */
    private static function rgb(array $channels): array
    {
        return array_map(static fn(string $channel): float => max(0.0, min(255.0, self::number($channel, 255))), $channels);
    }

    /** @param list<string> $channels @return list<float> */
    private static function hsl(array $channels): array
    {
        $h = fmod(fmod(self::number($channels[0], 360), 360) + 360, 360) / 360;
        $s = max(0.0, min(1.0, self::number($channels[1], 100) / 100));
        $l = max(0.0, min(1.0, self::number($channels[2], 100) / 100));
        if (0.0 === $s) return array($l * 255, $l * 255, $l * 255);
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $hue = static function (float $t) use ($p, $q): float {
            if ($t < 0) $t += 1;
            if ($t > 1) $t -= 1;
            if ($t < 1 / 6) return $p + ($q - $p) * 6 * $t;
            if ($t < 1 / 2) return $q;
            if ($t < 2 / 3) return $p + ($q - $p) * (2 / 3 - $t) * 6;
            return $p;
        };
        return array($hue($h + 1 / 3) * 255, $hue($h) * 255, $hue($h - 1 / 3) * 255);
    }

    /** @param list<string> $channels @return list<float> */
    private static function oklab(array $channels, bool $polar): array
    {
        $l = self::number($channels[0], 1);
        if ($polar) {
            $c = self::number($channels[1], 0.4);
            $h = deg2rad(self::number($channels[2], 360));
            $a = $c * cos($h);
            $b = $c * sin($h);
        } else {
            $a = self::number($channels[1], 0.4);
            $b = self::number($channels[2], 0.4);
        }
        $l_ = ($l + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m_ = ($l - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s_ = ($l - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;
        $linear = array(
            4.0767416621 * $l_ - 3.3077115913 * $m_ + 0.2309699292 * $s_,
            -1.2684380046 * $l_ + 2.6097574011 * $m_ - 0.3413193965 * $s_,
            -0.0041960863 * $l_ - 0.7034186147 * $m_ + 1.7076147010 * $s_,
        );
        return self::encodeSrgb($linear);
    }

    /**
     * CSS Color 4 `lab()`/`lch()`: CIELAB under D50, adapted to D65 with the
     * Bradford matrix and encoded as sRGB, clamped to the sRGB gamut.
     *
     * @param list<string> $channels @return list<float>
     */
    private static function cieLab(array $channels, bool $polar): array
    {
        $l = self::number($channels[0], 100);
        if ($polar) {
            $c = self::number($channels[1], 150);
            $h = deg2rad(self::number($channels[2], 360));
            $a = $c * cos($h);
            $b = $c * sin($h);
        } else {
            $a = self::number($channels[1], 125);
            $b = self::number($channels[2], 125);
        }
        $epsilon = 216 / 24389;
        $kappa = 24389 / 27;
        $fy = ($l + 16) / 116;
        $fx = $fy + $a / 500;
        $fz = $fy - $b / 200;
        $x = 0.3457 / 0.3585 * ($fx ** 3 > $epsilon ? $fx ** 3 : (116 * $fx - 16) / $kappa);
        $y = $l > $kappa * $epsilon ? $fy ** 3 : $l / $kappa;
        $z = (1.0 - 0.3457 - 0.3585) / 0.3585 * ($fz ** 3 > $epsilon ? $fz ** 3 : (116 * $fz - 16) / $kappa);
        $x65 = 0.9554734527042182 * $x - 0.023098536874261423 * $y + 0.0632593086610217 * $z;
        $y65 = -0.028369706963208136 * $x + 1.0099954580058226 * $y + 0.021041398966943008 * $z;
        $z65 = 0.012314001688319899 * $x - 0.020507696433477912 * $y + 1.3303659366080753 * $z;
        return self::encodeSrgb(array(
            3.2409699419045226 * $x65 - 1.537383177570094 * $y65 - 0.4986107602930034 * $z65,
            -0.9692436362808796 * $x65 + 1.8759675015077202 * $y65 + 0.04155505740717559 * $z65,
            0.05563007969699366 * $x65 - 0.20397695888897652 * $y65 + 1.0569715142428786 * $z65,
        ));
    }

    /** @param list<float> $linear Linear-light sRGB 0–1. @return list<float> */
    private static function encodeSrgb(array $linear): array
    {
        return array_map(static function (float $channel): float {
            $channel = max(0.0, min(1.0, $channel));
            $encoded = $channel <= 0.0031308 ? 12.92 * $channel : 1.055 * $channel ** (1 / 2.4) - 0.055;
            return $encoded * 255;
        }, $linear);
    }
}

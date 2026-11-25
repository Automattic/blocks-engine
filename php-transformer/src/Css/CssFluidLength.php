<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/**
 * Conservative, dimension-checked evaluator for captured fluid lengths.
 *
 * Evaluates px, viewport units (including the small, large and dynamic
 * `sv*`/`lv*`/`dv*` forms), `%`, `em` and `rem` (when their bases are known)
 * combined with `calc()`, `min()`, `max()` and `clamp()` at one viewport.
 * Anything else (container units, keywords, unresolved `var()`) returns
 * null, so the caller keeps observed pixels.
 */
final class CssFluidLength
{
    /**
     * @param float|null $percent Pixels `100%` resolves to, when known.
     * @param float|null $em Pixels `1em` resolves to, when known.
     * @param float|null $rem Pixels `1rem` resolves to, when known.
     */
    public static function evaluate(string $value, float $width, float $height, ?float $percent = null, ?float $em = null, ?float $rem = null): ?float
    {
        $offset = 0;
        $tokens = array();
        while ($offset < strlen($value)) {
            if (!preg_match('/\G\s*(?:(\d*\.?\d+)(px|[sld]?v(?:w|h|i|b|min|max)|rem|em|%)?|([a-z]+)|([()+*,\/\-]))/Ai', $value, $m, 0, $offset)) {
                if ('' === trim(substr($value, $offset))) break;
                return null;
            }
            $offset += strlen($m[0]);
            $tokens[] = array($m[1] ?? '', $m[2] ?? '', $m[3] ?? '', $m[4] ?? '');
        }
        $index = 0;
        $expression = null;
        $atom = function () use (&$atom, &$expression, &$index, $tokens, $width, $height, $percent, $em, $rem): ?array {
            $t = $tokens[$index++] ?? null;
            if (null === $t) return null;
            if ('' !== $t[0]) {
                // Small, large and dynamic viewport units all resolve against
                // the one viewport a capture was taken in; `vi`/`vb` follow a
                // horizontal writing mode.
                $factor = match (preg_replace('/^[sld](?=v)/', '', strtolower($t[1]))) {
                    '' , 'px' => 1.0, 'vw', 'vi' => $width / 100, 'vh', 'vb' => $height / 100,
                    'vmin' => min($width, $height) / 100, 'vmax' => max($width, $height) / 100,
                    '%' => null === $percent ? null : $percent / 100,
                    'em' => $em, 'rem' => $rem, default => null,
                };
                return null === $factor ? null : array((float) $t[0] * $factor, '' === $t[1] ? 0 : 1);
            }
            if (in_array($t[3], array('+', '-'), true)) {
                $v = $atom();
                return null === $v ? null : array(('-' === $t[3] ? -1 : 1) * $v[0], $v[1]);
            }
            $name = strtolower($t[2]);
            if ('(' !== $t[3] && !in_array($name, array('calc', 'min', 'max', 'clamp'), true)) return null;
            if ('' !== $name && '(' !== ($tokens[$index++][3] ?? null)) return null;
            $args = array();
            do {
                $v = $expression(0);
                if (null === $v) return null;
                $args[] = $v;
                $next = $tokens[$index++][3] ?? null;
            } while (',' === $next);
            if (')' !== $next || count(array_unique(array_column($args, 1))) !== 1) return null;
            $values = array_column($args, 0);
            $result = match ($name) {
                '', 'calc' => 1 === count($args) ? $values[0] : null,
                'min' => min($values), 'max' => max($values),
                'clamp' => 3 === count($args) ? max($values[0], min($values[1], $values[2])) : null,
                default => null,
            };
            return null === $result ? null : array($result, $args[0][1]);
        };
        $expression = function (int $minimum) use (&$expression, $atom, &$index, $tokens): ?array {
            $left = $atom();
            if (null === $left) return null;
            while (true) {
                $op = $tokens[$index][3] ?? '';
                $priority = match ($op) { '+', '-' => 1, '*', '/' => 2, default => -1 };
                if ($priority < $minimum) break;
                ++$index;
                $right = $expression($priority + 1);
                if (null === $right) return null;
                if (in_array($op, array('+', '-'), true)) {
                    if ($left[1] !== $right[1]) return null;
                    $left[0] += ('+' === $op ? 1 : -1) * $right[0];
                } elseif ('*' === $op) {
                    if ($left[1] + $right[1] > 1) return null;
                    $left = array($left[0] * $right[0], $left[1] + $right[1]);
                } else {
                    if (0 !== $right[1] || 0.0 === $right[0]) return null;
                    $left[0] /= $right[0];
                }
            }
            return $left;
        };
        $result = $expression(0);
        return null !== $result && $index === count($tokens) && is_finite($result[0]) && (1 === $result[1] || 0.0 === $result[0]) ? $result[0] : null;
    }
}

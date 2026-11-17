<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/**
 * Removes `@media` blocks that can never apply inside a stylesheet whose own
 * media condition excludes them, such as `@media (max-width: 1023px)` rules
 * in a sheet only activated by `media="(min-width:1024px)"`.
 *
 * Device-split captures publish one copy of a source sheet per viewport
 * range; each copy otherwise ships the other ranges' rules as dead weight.
 * Only media types and pixel `min-width` / `max-width` features are read.
 * Any other feature widens the range it belongs to, and a query this cannot
 * read (a leading `not`, a top-level `or`) is never treated as disjoint.
 */
final class UnreachableMediaPruner
{
    public static function prune(string $css, string $media): string
    {
        $outer = self::ranges($media);
        if (null === $outer || !str_contains($css, '@')) return $css;
        $constrained = false;
        foreach ($outer as $range) $constrained = $constrained || null !== $range['type'] || $range['min'] > -INF || $range['max'] < INF;
        if (!$constrained) return $css;
        $tree = new CssRuleTree($css);
        if (!$tree->wellFormed) return $css;
        $transformer = new CssStylesheetTransformer();
        $removals = array();
        $walk = function (int $scope) use (&$walk, &$removals, $tree, $css, $outer, $transformer): void {
            foreach ($tree->rules($scope) as $rule) {
                if ('{' !== $css[$rule['boundary']]) continue;
                $prelude = substr($css, $rule['start'], $rule['boundary'] - $rule['start']);
                $plain = trim(CssSyntaxScanner::withoutComments($prelude));
                if (preg_match('/^@media\s+(.+)$/is', $plain, $match)) {
                    $inner = self::ranges($match[1]);
                    if (null !== $inner && self::rangesDisjoint($outer, $inner)) {
                        // Preserve comments (including licenses) preceding the rule.
                        $at = strripos($prelude, '@');
                        $removals[] = array(false === $at ? $rule['start'] : $rule['start'] + $at, $rule['end'] + 1);
                        continue;
                    }
                }
                if ($transformer->nestsStyleRules($plain)) $walk($rule['boundary']);
            }
        };
        $walk(-1);
        usort($removals, static fn(array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($removals as [$start, $end]) $css = substr_replace($css, '', $start, $end - $start);
        return $css;
    }

    /** True when no viewport satisfies both media query lists. */
    public static function disjoint(string $left, string $right): bool
    {
        $a = self::ranges($left);
        $b = self::ranges($right);
        return null !== $a && null !== $b && self::rangesDisjoint($a, $b);
    }

    /**
     * @param list<array{type:?string,min:float,max:float}> $a
     * @param list<array{type:?string,min:float,max:float}> $b
     */
    private static function rangesDisjoint(array $a, array $b): bool
    {
        foreach ($a as $x) {
            foreach ($b as $y) {
                if (null !== $x['type'] && null !== $y['type'] && $x['type'] !== $y['type']) continue;
                if (max($x['min'], $y['min']) <= min($x['max'], $y['max'])) return false;
            }
        }
        return true;
    }

    /**
     * Each query as a media type (null for any) and an inclusive width range.
     *
     * @return list<array{type:?string,min:float,max:float}>|null
     */
    private static function ranges(string $media): ?array
    {
        $media = trim(CssSyntaxScanner::withoutComments($media));
        if ('' === $media) return array(array('type' => null, 'min' => -INF, 'max' => INF));
        $ranges = array();
        foreach (CssValueSplitter::splitTopLevel($media, array(',')) as $query) {
            $query = trim($query);
            if ('' === $query || preg_match('/^not\b/i', $query)) return null;
            $query = (string) preg_replace('/^only\s+/i', '', $query);
            $parts = preg_split('/\s+and\s+/i', $query);
            if (false === $parts) return null;
            $range = array('type' => null, 'min' => -INF, 'max' => INF);
            foreach ($parts as $index => $part) {
                $part = trim($part);
                if (0 === $index && preg_match('/^[a-z][a-z0-9-]*$/iD', $part)) {
                    $type = strtolower($part);
                    if ('all' !== $type) $range['type'] = $type;
                    continue;
                }
                // Anything but one parenthesized group joins conditions in a
                // way this reader does not model.
                if ('(' !== ($part[0] ?? '') || ')' !== substr($part, -1) || null === self::closingParenthesis($part)) return null;
                if (preg_match('/^\(\s*(min|max)-width\s*:\s*(\d+(?:\.\d+)?|\.\d+)(px)?\s*\)$/iD', $part, $match)) {
                    if (!isset($match[3]) && 0.0 !== (float) $match[2]) continue;
                    $value = (float) $match[2];
                    if ('min' === strtolower($match[1])) $range['min'] = max($range['min'], $value);
                    else $range['max'] = min($range['max'], $value);
                }
            }
            $ranges[] = $range;
        }
        return array() === $ranges ? null : $ranges;
    }

    /** The group is one balanced parenthesis pair spanning the whole part. */
    private static function closingParenthesis(string $part): ?int
    {
        $depth = 0;
        $length = strlen($part);
        for ($i = 0; $i < $length; ++$i) {
            if ('(' === $part[$i]) ++$depth;
            elseif (')' === $part[$i]) {
                --$depth;
                if (0 === $depth) return $i === $length - 1 ? $i : null;
            }
        }
        return null;
    }
}

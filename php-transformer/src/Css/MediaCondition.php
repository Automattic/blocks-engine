<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Intersection of media query lists, without changing query-level negation. */
final class MediaCondition
{
    /**
     * Whether any alternative of a media query list can match a screen. A
     * list that names only `print` or `speech` never renders on screen at
     * any width, so it states nothing about the page's viewport boxes.
     */
    public static function targetsScreen(string $condition): bool
    {
        foreach (CssValueSplitter::splitTopLevel($condition, array(',')) as $alternative) {
            $alternative = strtolower(trim($alternative));
            $negated = str_starts_with($alternative, 'not ');
            $type = (string) preg_replace('/^(?:not|only)\s+/', '', $alternative);
            $type = strtok($type, " \t\n(") ?: '';
            if ($negated || ! in_array($type, array('print', 'speech'), true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Null means the intersection needs nested @media rules. In particular,
     * appending to `not screen and (...)` would negate the NEW condition too.
     * Media types cannot be parenthesized as MQ4 conditions, so nesting is the
     * simplest lossless alternative rather than inventing a single query.
     */
    public static function combine(string $left, string $right): ?string
    {
        $left = trim($left);
        $right = trim($right);
        if ('' === $left || 'all' === strtolower($left)) return $right;
        if ('' === $right || 'all' === strtolower($right)) return $left;
        $result = array();
        foreach (CssValueSplitter::splitTopLevel($left, array(',')) as $a) {
            foreach (CssValueSplitter::splitTopLevel($right, array(',')) as $b) {
                if ($a === $b) {
                    $result[] = $a;
                    continue;
                }
                if (preg_match('/^not\s+[a-z]/i', $a) || preg_match('/^not\s+[a-z]/i', $b)) return null;
                preg_match('/^(?:only\s+)?([a-z][a-z0-9-]*)(?:\s+and\s+(.+))?$/is', $a, $typeA);
                preg_match('/^(?:only\s+)?([a-z][a-z0-9-]*)(?:\s+and\s+(.+))?$/is', $b, $typeB);
                $types = array();
                $conditions = array();
                $only = false;
                foreach (array(array($a, $typeA), array($b, $typeB)) as [$query, $type]) {
                    if (array() !== $type) {
                        if ('all' !== strtolower($type[1])) $types[] = strtolower($type[1]);
                        $only = $only || str_starts_with(strtolower($query), 'only ');
                        if (isset($type[2])) $conditions[] = $type[2];
                    } else {
                        // Parenthesizing also preserves MQ4 `or` and condition-level `not`.
                        $conditions[] = '(' === $query[0] && !preg_match('/\)\s+(?:or|and)\s+/i', $query) ? $query : '(' . $query . ')';
                    }
                }
                $types = array_values(array_unique($types));
                if (count($types) > 1) continue; // Distinct media types cannot both match.
                $prefix = $types[0] ?? ($only ? 'all' : '');
                if ($only && '' !== $prefix) $prefix = 'only ' . $prefix;
                $result[] = implode(' and ', array_merge('' !== $prefix ? array($prefix) : array(), $conditions)) ?: 'all';
            }
        }
        return implode(', ', array_unique($result)) ?: 'not all';
    }

    /**
     * Removes `@media` blocks that can never apply under the media the whole
     * stylesheet is delivered with. A capture that serves one copy of a page
     * stylesheet per viewport (`media="(min-width:1024px)"` and
     * `media="(max-width:1023px)"`) leaves each copy carrying the other
     * viewport's `@media (max-width:1023px)` / `@media (min-width:1024px)`
     * blocks: dead bytes in every published stylesheet. Only a provable
     * disjunction is removed (see disjoint()); anything else stays.
     */
    public static function withoutUnreachableBlocks(string $css, string $delivery): string
    {
        $delivery = trim($delivery);
        if ('' === $delivery || 'all' === strtolower($delivery) || false === stripos($css, '@media')) return $css;
        $tree = new CssRuleTree($css);
        if (!$tree->wellFormed) return $css;
        $removals = array();
        $walk = static function (int $scope) use (&$walk, &$removals, $tree, $css, $delivery): void {
            foreach ($tree->rules($scope) as $rule) {
                if ('{' !== ($css[$rule['boundary']] ?? '')) continue;
                $prelude = substr($css, $rule['start'], $rule['boundary'] - $rule['start']);
                $plain = trim(CssSyntaxScanner::withoutComments($prelude));
                if (preg_match('/^@media\s+(.+)$/is', $plain, $match) && self::disjoint($delivery, $match[1])) {
                    // Keep comments (licenses) that precede the rule.
                    $at = strripos($prelude, '@');
                    $removals[] = array(false === $at ? $rule['start'] : $rule['start'] + $at, $rule['end'] + 1);
                } elseif (preg_match('/^@(?:media|supports|layer)\b/i', $plain)) {
                    $walk($rule['boundary']);
                }
            }
        };
        $walk(-1);
        foreach (array_reverse($removals) as [$start, $end]) $css = substr_replace($css, '', $start, $end - $start);
        return $css;
    }

    /**
     * True only when no viewport can satisfy both media query lists: every
     * pair of their queries names different media types or width ranges that
     * do not meet. A query this cannot read (negation, `or`, non-px widths)
     * is assumed to match, so the answer is never a false positive.
     */
    public static function disjoint(string $left, string $right): bool
    {
        foreach (CssValueSplitter::splitTopLevel($left, array(',')) as $a) {
            $a = self::query($a);
            if (null === $a) return false;
            foreach (CssValueSplitter::splitTopLevel($right, array(',')) as $b) {
                $b = self::query($b);
                if (null === $b) return false;
                $types = null !== $a['type'] && null !== $b['type'] && $a['type'] !== $b['type'];
                if (!$types && max($a['min'], $b['min']) <= min($a['max'], $b['max'])) return false;
            }
        }
        return true;
    }

    /** @return array{type:?string,min:float,max:float}|null */
    private static function query(string $query): ?array
    {
        $query = strtolower(trim($query));
        if ('' === $query || preg_match('/(?<![\w-])(?:not|or)(?![\w-])/', $query)) return null;
        $query = (string) preg_replace('/^only\s+/', '', $query);
        $parsed = array('type' => null, 'min' => 0.0, 'max' => INF);
        foreach (CssValueSplitter::splitTopLevelWhitespace($query) as $index => $part) {
            $part = trim($part);
            if ('' === $part || 'and' === $part) continue;
            if ('(' !== $part[0]) {
                if (0 !== $index || !preg_match('/^[a-z][a-z0-9-]*$/', $part)) return null;
                if ('all' !== $part) $parsed['type'] = $part;
                continue;
            }
            // Any other feature only narrows the query; it never widens it.
            if (!preg_match('/^\(\s*(min-|max-)?width\s*:\s*(\d+(?:\.\d+)?|\.\d+)(px)?\s*\)$/', $part, $feature)) continue;
            if ('' === ($feature[3] ?? '') && 0.0 !== (float) $feature[2]) continue;
            $value = (float) $feature[2];
            if ('max-' !== $feature[1]) $parsed['min'] = max($parsed['min'], $value);
            if ('min-' !== $feature[1]) $parsed['max'] = min($parsed['max'], $value);
        }
        return $parsed;
    }

    /** @param array<int,string> $conditions */
    public static function wrap(string $css, array $conditions): string
    {
        foreach (array_reverse($conditions) as $condition) {
            if ('' !== trim($condition) && 'all' !== strtolower(trim($condition))) $css = '@media ' . $condition . '{' . $css . '}';
        }
        return $css;
    }
}

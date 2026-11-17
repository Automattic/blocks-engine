<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Intersection of media query lists, without changing query-level negation. */
final class MediaCondition
{
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

    /** @param array<int,string> $conditions */
    public static function wrap(string $css, array $conditions): string
    {
        foreach (array_reverse($conditions) as $condition) {
            if ('' !== trim($condition) && 'all' !== strtolower(trim($condition))) $css = '@media ' . $condition . '{' . $css . '}';
        }
        return $css;
    }
}

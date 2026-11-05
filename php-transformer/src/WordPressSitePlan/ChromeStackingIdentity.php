<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Support\BlockAttributes;

/** Identity-only numbering of inline stacking siblings; source paint is untouched. */
final class ChromeStackingIdentity
{
    public static function normalize(string $markup): string
    {
        // Keep block comments opaque: their label HTML is a separate fragment.
        $markup = preg_replace_callback('/<!--.*?-->/s', static function (array $match): string {
            if (!preg_match('/^(<!--\s*wp:\S+\s+)(\{.*\})(\s*\/?-->\s*)$/s', $match[0], $parts)) return $match[0];
            $attrs = json_decode($parts[2], true);
            if (!is_array($attrs)) return $match[0];
            foreach ($attrs as &$value) if (is_string($value) && preg_match('/<[a-zA-Z]/', $value)) $value = self::normalize($value);
            unset($value);
            return $parts[1] . BlockAttributes::encode($attrs) . $parts[3];
        }, $markup) ?? $markup;
        preg_match_all('/<!--.*?-->|<\/?([a-zA-Z][a-zA-Z0-9-]*)\b[^>]*>/s', $markup, $tags, PREG_OFFSET_CAPTURE);
        $stack = array(0); $next = 0; $groups = array();
        foreach ($tags[0] as [$tag, $offset]) {
            if (str_starts_with($tag, '<!--')) continue;
            if (str_starts_with($tag, '</')) { if (count($stack) > 1) array_pop($stack); continue; }
            $parent = end($stack);
            if (preg_match('/\bstyle="([^"]*)"/i', $tag, $style, PREG_OFFSET_CAPTURE)
                && preg_match('/(?:^|;)\s*z-index\s*:\s*([+-]?\d+)(\s*!important)?\s*(?=;|$)/i', $style[1][0], $z, PREG_OFFSET_CAPTURE)) {
                $groups[$parent][] = array('offset' => $offset + $style[1][1] + $z[1][1], 'length' => strlen($z[1][0]), 'value' => (int) $z[1][0]);
            }
            preg_match('/^<([a-zA-Z0-9-]+)/', $tag, $name);
            if (!in_array(strtolower($name[1]), array('area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'), true) && !str_ends_with($tag, '/>')) $stack[] = ++$next;
        }
        $replacements = array();
        foreach ($groups as $rows) {
            $values = array_values(array_unique(array_column($rows, 'value')));
            sort($values, SORT_NUMERIC);
            foreach ($rows as $row) {
                $sign = $row['value'] <=> 0;
                // Rank within the sign class preserves ties and ordering without
                // letting a page's unrelated counter magnitude enter identity.
                $peers = array_values(array_filter($values, static fn(int $value): bool => ($value <=> 0) === $sign));
                $rank = array_search($row['value'], $peers, true);
                $row['replacement'] = 0 === $sign ? '0' : (string) ($sign < 0 ? $rank - count($peers) : $rank + 1);
                $replacements[] = $row;
            }
        }
        usort($replacements, static fn(array $a, array $b): int => $b['offset'] <=> $a['offset']);
        foreach ($replacements as $row) $markup = substr_replace($markup, $row['replacement'], $row['offset'], $row['length']);
        return $markup;
    }
}

<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

/**
 * Element markers name one carrier element each: rich text, control or
 * semantic. A marker no emitted markup, runtime declaration or entity record
 * still carries names an element the plan replaced or dropped, so a selector
 * naming it matches nothing and is pruned.
 */
final class DeadElementMarkers
{
    /** Markers that name one carrier element: rich text, control and semantic. */
    public const PATTERN = '/blocks-engine-(?:richtext|control|semantic)-[a-f0-9]+-\d+/';

    /**
     * An element marker no emitted markup, runtime declaration or entity
     * record carries names an element the plan replaced or dropped, so every
     * stylesheet selector naming it matches nothing: drop those arms from
     * page stylesheets too, as the shared-chrome projection already does.
     *
     * @param list<array<string,mixed>> $assets
     * @param array<string,true> $live
     * @return list<array<string,mixed>>
     */
    public static function withoutDeadSelectors(array $assets, array $live): array
    {
        foreach ($assets as &$asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null) || isset($asset['payload_reference']) || 1 !== preg_match(self::PATTERN, $asset['content'])) continue;
            $content = (new CssStylesheetTransformer())->transformStyleRules(
                $asset['content'],
                static function (string $prelude, string $body) use ($live): string {
                    if (1 !== preg_match(self::PATTERN, $prelude)) return $prelude . '{' . $body . '}';
                    $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
                    if (null === $selectors) return $prelude . '{' . $body . '}';
                    $kept = array();
                    $changed = false;
                    foreach ($selectors as $selector) {
                        $pruned = self::withoutDead(trim($selector), $live);
                        if ($pruned !== trim($selector)) $changed = true;
                        if (null !== $pruned) $kept[] = $pruned;
                    }
                    if (!$changed) return $prelude . '{' . $body . '}';
                    return array() === $kept ? '' : implode(',', $kept) . '{' . $body . '}';
                }
            );
            if ($content === $asset['content']) continue;
            $asset = WordPressSitePlan::withRewrittenTextContent($asset, $content);
        }
        unset($asset);
        return $assets;
    }

    public static function withoutDead(string $selector, array $live): ?string
    {
        $dead = static function (string $text) use ($live): bool {
            if (!preg_match_all(self::PATTERN, $text, $markers)) return false;
            foreach ($markers[0] as $marker) if (!isset($live[$marker])) return true;
            return false;
        };
        if (!$dead($selector)) return $selector;
        $offset = 0;
        while (false !== ($start = stripos($selector, ':where(', $offset))) {
            $open = $start + strlen(':where(');
            $depth = 1;
            for ($end = $open; $end < strlen($selector) && $depth > 0; ++$end) {
                if ('(' === $selector[$end]) ++$depth;
                elseif (')' === $selector[$end]) --$depth;
            }
            if (0 !== $depth) return $selector;
            $inner = substr($selector, $open, $end - 1 - $open);
            $arguments = CssStylesheetTransformer::splitSelectorList($inner);
            if (null === $arguments || !$dead($inner)) {
                $offset = $open;
                continue;
            }
            $alive = array();
            foreach ($arguments as $argument) {
                $argument = self::withoutDead(trim($argument), $live);
                if (null !== $argument) $alive[] = $argument;
            }
            if (array() !== $alive) {
                $replacement = ':where(' . implode(',', $alive) . ')';
                $selector = substr($selector, 0, $start) . $replacement . substr($selector, $end);
                $offset = $start + strlen($replacement);
                continue;
            }
            // `:not(:where(dead))` excluded nothing; any other position required a dead element.
            if ($start >= 5 && 0 === strcasecmp(substr($selector, $start - 5, 5), ':not(') && ')' === ($selector[$end] ?? '')) {
                // A compound left with no other simple selector keeps `*`,
                // which, like `:not(:where())`, adds no specificity.
                $before = $start - 5;
                $after = $selector[$end + 1] ?? '';
                $alone = (0 === $before || str_contains(" \t\n>+~(,", $selector[$before - 1])) && ('' === $after || str_contains(" \t\n>+~),", $after));
                $replacement = $alone ? '*' : '';
                $selector = substr($selector, 0, $before) . $replacement . substr($selector, $end + 1);
                $offset = $before + strlen($replacement);
                continue;
            }
            return null;
        }
        // A dead marker outside `:where()` (a class or attribute the subject
        // must carry) leaves nothing to match; inside another `:not()` it is
        // left as authored.
        $positive = WordPressSitePlan::positiveSelector($selector);
        if ($dead($positive)) return null;
        $selector = trim($selector);
        return '' === $selector ? null : $selector;
    }
}

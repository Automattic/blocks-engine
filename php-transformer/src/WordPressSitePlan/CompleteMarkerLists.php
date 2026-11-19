<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;

/**
 * Projection addresses the carriers an authored rule matched by their
 * per-element markers, and list compaction folds arms that differ only in
 * that marker into one `:where(.blocks-engine-control-…-0,…)`. A site-wide
 * sheet then enumerates most of the site's carriers in every such rule.
 *
 * Once markup is final, the plan knows every carrier: each element with a
 * role kind's marker carries that kind's role class and exactly one marker of
 * that kind, and no element carries the role class without one. Then a list
 * of markers matches exactly the role carriers that carry none of the other
 * markers, so the list is written as the role class (when it names every
 * marker) or the role class minus the markers it leaves out, whichever is
 * shorter. Both stay inside `:where()`, so specificity is unchanged. A lone
 * marker is its carrier's own address and is left as it is.
 *
 * The rewrite needs proof for each kind: every place the role class or one of
 * its markers is named, in markup, runtime records and inline scripts, is a
 * class list that keeps the one-marker invariant. A marker or role class
 * named anywhere else (an escaped attribute, a script building class names)
 * leaves that kind's lists as they are. A record's list of class tokens is
 * one carrier's class list.
 *
 * UnreferencedEngineClasses runs later and drops markers no sheet names any
 * more. That keeps the rewrite exact: the role class stays (sheets name it),
 * and every marker a `:not()` still excludes is still named, so it stays too.
 */
final class CompleteMarkerLists
{
    private const MARKER = '(?<![\w-])blocks-engine-(%s)-[a-f0-9]{12}-[0-9]+(?![\w-])';

    /** @var array<string, array<string, true>> Kind => every marker of that kind the plan emits. */
    private array $markers = array();

    /**
     * @param list<array<string,mixed>> $assets
     * @param string $markup Every emitted page, part, template, pattern and menu.
     * @param array<mixed> $records Runtime declarations and entity records.
     * @return list<array<string,mixed>>
     */
    public static function compact(array $assets, string $markup, array $records): array
    {
        $texts = array($markup);
        self::recordTexts($records, $texts);
        foreach ($assets as $asset) {
            if ('css' !== ($asset['kind'] ?? null) && empty($asset['binary']) && is_string($asset['content'] ?? null)) $texts[] = $asset['content'];
        }
        $lists = new self($texts);
        if (array() === $lists->markers) return $assets;
        foreach ($assets as &$asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null) || isset($asset['payload_reference']) || !str_contains($asset['content'], ':where(.blocks-engine-')) continue;
            $content = $lists->compactStylesheet($asset['content']);
            if ($content === $asset['content']) continue;
            $asset = CompiledAssets::withRewrittenTextContent($asset, $content);
        }
        unset($asset);
        return $assets;
    }

    /** @param list<string> $texts */
    private function __construct(array $texts)
    {
        foreach (EngineMarker::ROLE_KINDS as $kind) {
            $role = 'blocks-engine-role-' . $kind;
            $rolePattern = '/(?<![\w-])' . preg_quote($role, '/') . '(?![\w-])/';
            $markerPattern = '/' . sprintf(self::MARKER, preg_quote($kind, '/')) . '/';
            $roles = $named = 0;
            $listedRoles = $listedMarkers = 0;
            $markers = array();
            $sound = true;
            foreach ($texts as $text) {
                if (!str_contains($text, $role) && !str_contains($text, 'blocks-engine-' . $kind . '-')) continue;
                $roles += preg_match_all($rolePattern, $text);
                $named += preg_match_all($markerPattern, $text);
                foreach (self::classLists($text) as $classes) {
                    $carriesRole = preg_match_all($rolePattern, $classes);
                    $carried = preg_match_all($markerPattern, $classes, $found);
                    if (0 === $carriesRole && 0 === $carried) continue;
                    if (1 !== $carriesRole || 1 !== $carried) {
                        $sound = false;
                        break 2;
                    }
                    $listedRoles += 1;
                    $listedMarkers += 1;
                    $markers[$found[0][0]] = true;
                }
            }
            if ($sound && array() !== $markers && $roles === $listedRoles && $named === $listedMarkers) $this->markers[$kind] = $markers;
        }
    }

    /**
     * Runtime records name a carrier either inside a class string or as a
     * list of class tokens (`control_carrier_classes`). A token list is one
     * carrier's classes, so it is read as one class attribute.
     *
     * @param array<mixed> $node
     * @param list<string> $texts
     */
    private static function recordTexts(array $node, array &$texts): void
    {
        if (array() !== $node && array_is_list($node) && array() === array_filter($node, static fn (mixed $value): bool => !is_string($value) || 1 !== preg_match('/^[\w-]+$/', $value))) {
            $tokens = implode(' ', $node);
            if (str_contains($tokens, 'blocks-engine-')) {
                $texts[] = 'class="' . $tokens . '"';
                return;
            }
        }
        foreach ($node as $value) {
            if (is_string($value)) $texts[] = $value;
            elseif (is_array($value)) self::recordTexts($value, $texts);
        }
    }

    /** @return list<string> Every class attribute and serialized `className` in the text. */
    private static function classLists(string $text): array
    {
        $lists = array();
        if (preg_match_all('/\bclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) $lists[] = ($match[1] ?? '') . ($match[2] ?? '');
        }
        if (preg_match_all('/"className"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $text, $matches)) {
            foreach ($matches[1] as $classes) $lists[] = $classes;
        }
        return $lists;
    }

    private function compactStylesheet(string $css): string
    {
        $kinds = implode('|', array_map(static fn (string $kind): string => preg_quote($kind, '/'), array_keys($this->markers)));
        $marker = 'blocks-engine-(?:' . $kinds . ')-[a-f0-9]{12}-[0-9]+';
        $pattern = '/(?<!\\\\):where\(\s*(\.' . $marker . '(?:\s*,\s*\.' . $marker . ')+)\s*\)/';
        return (new CssStylesheetTransformer())->transformStyleRules($css, function (string $prelude, string $body) use ($pattern): string {
            if (!str_contains($prelude, ':where(.blocks-engine-')) return $prelude . '{' . $body . '}';
            $compacted = preg_replace_callback($pattern, fn (array $match): string => $this->compactList($match[0], $match[1]), $prelude);
            return (is_string($compacted) ? $compacted : $prelude) . '{' . $body . '}';
        });
    }

    private function compactList(string $list, string $items): string
    {
        $listed = array();
        $kind = null;
        foreach (explode(',', $items) as $item) {
            $name = substr(trim($item), 1);
            if (1 !== preg_match('/^blocks-engine-([a-z]+)-/', $name, $match) || ($kind ?? $match[1]) !== $match[1]) return $list;
            $kind = $match[1];
            $listed[$name] = true;
        }
        if (null === $kind || !isset($this->markers[$kind])) return $list;
        // A listed marker no element carries matches nothing either way.
        if (array() === array_intersect_key($listed, $this->markers[$kind])) return $list;
        $omitted = array_keys(array_diff_key($this->markers[$kind], $listed));
        sort($omitted, SORT_STRING);
        $role = '.blocks-engine-role-' . $kind;
        $compacted = array() === $omitted
            ? ':where(' . $role . ')'
            : ':where(' . $role . ':not(' . implode(',', array_map(static fn (string $name): string => '.' . $name, $omitted)) . '))';
        return strlen($compacted) < strlen($list) ? $compacted : $list;
    }
}

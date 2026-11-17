<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

/**
 * Projection restates an authored rule for every native carrier a source
 * element could become: a lowered layout-table cell, an inline or flex-item
 * image figure, a listing card's core/post-featured-image. Which carriers a
 * site actually has is known only once the plan's markup is final. A selector
 * arm that requires a carrier class no emitted markup has matches nothing on
 * any route, so it is dropped rather than shipped in every theme stylesheet.
 */
final class AbsentCarrierSelectors
{
    /**
     * Carrier class => the markup that proves a carrier exists. Engine
     * classes are serialized in the block's className; core's featured image
     * class is rendered only by its block. Materialization must not import
     * conversion internals, so the engine carrier classes are spelled out
     * here; the absent-carrier-selectors contract pins them to their owners.
     */
    public const CARRIERS = array(
        'wp-block-post-featured-image' => '<!-- wp:post-featured-image',
        'blocks-engine-synthetic-flex-image-figure' => 'blocks-engine-synthetic-flex-image-figure',
        'blocks-engine-synthetic-image-figure-inline' => 'blocks-engine-synthetic-image-figure-inline',
        'blocks-engine-synthetic-image-figure-strut' => 'blocks-engine-synthetic-image-figure-strut',
        'blocks-engine-layout-table-cell' => 'blocks-engine-layout-table-cell',
        'blocks-engine-linked-image-figure' => 'blocks-engine-linked-image-figure',
        // Engine support ships every layout-neutral button modifier, because
        // per-route sheets coexist until the plan folds them. Once markup is
        // final, a modifier no button carries restores nothing anywhere.
        'blocks-engine-layout-child-button-conditional-width' => 'blocks-engine-layout-child-button-conditional-width',
        'blocks-engine-layout-child-button-conditional-height' => 'blocks-engine-layout-child-button-conditional-height',
        'blocks-engine-layout-child-button-display-inline-block' => 'blocks-engine-layout-child-button-display-inline-block',
        'blocks-engine-layout-child-button-display-block' => 'blocks-engine-layout-child-button-display-block',
        'blocks-engine-layout-child-button-display-flex' => 'blocks-engine-layout-child-button-display-flex',
        'blocks-engine-layout-child-button-display-inline-flex' => 'blocks-engine-layout-child-button-display-inline-flex',
        'blocks-engine-layout-child-button-display-inline-grid' => 'blocks-engine-layout-child-button-display-inline-grid',
        'blocks-engine-layout-child-button-display-inline-table' => 'blocks-engine-layout-child-button-display-inline-table',
    );

    /** Pseudo-classes whose single argument the subject must itself match. */
    private const REQUIRED_ARGUMENT = array( 'is', 'where', 'matches', '-webkit-any', '-moz-any' );

    /**
     * @param list<array<string,mixed>> $assets
     * @param string $markup Every emitted page, part, template, pattern, menu and runtime record.
     * @return list<array<string,mixed>>
     */
    public static function prune(array $assets, string $markup): array
    {
        $absent = array();
        foreach (self::CARRIERS as $class => $evidence) {
            if (!str_contains($markup, $evidence)) $absent[] = preg_quote($class, '/');
        }
        if (array() === $absent) return $assets;
        $pattern = '/(?<!\\\\)\.(?:' . implode('|', $absent) . ')(?![\w\\\\-])/';
        foreach ($assets as &$asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null) || isset($asset['payload_reference']) || 1 !== preg_match($pattern, $asset['content'])) continue;
            $content = (new CssStylesheetTransformer())->transformStyleRules(
                $asset['content'],
                static function (string $prelude, string $body) use ($pattern): string {
                    if (1 !== preg_match($pattern, $prelude)) return $prelude . '{' . $body . '}';
                    $arms = CssStylesheetTransformer::splitSelectorList($prelude);
                    if (null === $arms) return $prelude . '{' . $body . '}';
                    $kept = array();
                    foreach ($arms as $arm) {
                        $live = self::live(trim($arm), $pattern);
                        if (null !== $live) $kept[] = $live;
                    }
                    if (array() === $kept) return '';
                    $selector = implode(',', $kept);
                    return ($selector === implode(',', array_map('trim', $arms)) ? $prelude : $selector) . '{' . $body . '}';
                }
            );
            if ($content === $asset['content']) continue;
            $asset = CompiledAssets::withRewrittenTextContent($asset, $content);
        }
        unset($asset);
        return $assets;
    }

    /**
     * The arm without alternatives that require an absent carrier, or null
     * when the arm itself requires one. A required class is one outside any
     * functional pseudo-class, or inside an `:is()`/`:where()` whose every
     * alternative requires it. Excluded (`:not()`) and relational (`:has()`)
     * arguments, and quoted text, never decide pruning. An `:is()`
     * alternative is only dropped when the remaining ones weigh as much, so
     * the arm keeps its specificity.
     */
    private static function live(string $selector, string $pattern, string &$requiredOut = ''): ?string
    {
        $live = '';
        $required = '';
        // An unreadable selector requires nothing it can be pruned for.
        $requiredOut = '';
        $length = strlen($selector);
        for ($i = 0; $i < $length; ++$i) {
            $char = $selector[$i];
            if ('\\' === $char) {
                $live .= substr($selector, $i, 2);
                $required .= substr($selector, $i, 2);
                ++$i;
                continue;
            }
            if ('"' === $char || "'" === $char) {
                $close = $i;
                do {
                    $close = strpos($selector, $char, $close + 1);
                } while (false !== $close && '\\' === $selector[$close - 1]);
                if (false === $close) return $selector;
                $live .= substr($selector, $i, $close - $i + 1);
                $i = $close;
                continue;
            }
            if (':' !== $char || 1 !== preg_match('/\G:{1,2}([\w-]+)\(/A', $selector, $match, 0, $i)) {
                $live .= $char;
                $required .= $char;
                continue;
            }
            $open = $i + strlen($match[0]);
            $depth = 1;
            for ($end = $open; $end < $length && $depth > 0; ++$end) {
                if ('\\' === $selector[$end]) ++$end;
                elseif ('(' === $selector[$end]) ++$depth;
                elseif (')' === $selector[$end]) --$depth;
            }
            if (0 !== $depth) return $selector;
            $group = substr($selector, $i, $end - $i);
            $i = $end - 1;
            $name = strtolower($match[1]);
            $alternatives = in_array($name, self::REQUIRED_ARGUMENT, true) ? CssStylesheetTransformer::splitSelectorList(substr($selector, $open, $end - 1 - $open)) : null;
            if (null === $alternatives || array() === $alternatives) {
                $live .= $group;
                continue;
            }
            $alternatives = array_map('trim', $alternatives);
            $alive = $aliveRequired = array();
            $alternativeRequired = '';
            foreach ($alternatives as $alternative) {
                $kept = self::live($alternative, $pattern, $alternativeRequired);
                if (null === $kept) continue;
                $alive[] = $kept;
                $aliveRequired[] = $alternativeRequired;
            }
            if (array() === $alive) return null;
            // A lone alternative's requirements are the arm's too; what it
            // excludes (`:not()`) or relates (`:has()`) still requires nothing.
            if (1 === count($alive)) {
                $required .= $aliveRequired[0];
            }
            if ($alive === $alternatives || ('where' !== $name && (null === self::weight($alternatives) || self::weight($alive) !== self::weight($alternatives)))) {
                $live .= $group;
                continue;
            }
            $live .= $match[0] . implode(',', $alive) . ')';
        }
        if (1 === preg_match($pattern, $required)) return null;
        $requiredOut = $required;
        return $live;
    }

    /** @param list<string> $alternatives @return array{ids:int,classes:int,types:int}|null The weight of an `:is()` over them. */
    private static function weight(array $alternatives): ?array
    {
        $largest = array('ids' => 0, 'classes' => 0, 'types' => 0);
        foreach ($alternatives as $alternative) {
            $parsed = CssSelectorMatcher::parse($alternative);
            if (!($parsed['supported'] ?? false)) return null;
            $largest = max($largest, CssSelectorMatcher::specificityCounts($parsed));
        }
        return $largest;
    }
}

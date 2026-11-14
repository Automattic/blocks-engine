<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

/**
 * Linear, site-wide pass after chrome factoring and asset assembly.
 *
 * Navigation projection names each emitted navigation block with an instance
 * marker. Chrome factoring can then replace a route's navigation with a shared
 * part that carries another instance, leaving the route's projected rules
 * aimed at a marker no emitted block carries. Those selector arms and
 * selector-list alternatives cannot match, so they are removed without
 * changing what any rule matches or how it ranks. Anything left is reported.
 */
final class NavigationInstanceDiagnostics
{
    private const MARKER = '/(?<![A-Za-z0-9_-])blocks-engine-navigation-instance-[a-f0-9]{12}-[0-9]+(?![A-Za-z0-9_-])/';

    /**
     * @param list<array<string,mixed>> $assets
     * @return list<array<string,mixed>>
     */
    public static function withoutOrphanedRules(array $assets, string $markup): array
    {
        $emitted = self::emitted($markup);
        $transformer = new CssStylesheetTransformer();
        foreach ($assets as &$asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null) || isset($asset['payload_reference'])) continue;
            preg_match_all(self::MARKER, $asset['content'], $matches);
            $orphaned = array_values(array_diff(array_unique($matches[0]), array_keys($emitted)));
            if (array() === $orphaned) continue;
            $content = $transformer->transform($asset['content'], static function (string $prelude, string $body) use ($orphaned) {
                $arms = CssStylesheetTransformer::splitSelectorList($prelude);
                if (null === $arms) return $prelude;
                $live = array();
                foreach ($arms as $arm) {
                    if (!self::requiresAny($arm, $orphaned)) $live[] = self::withoutOrphanedAlternatives($arm, $orphaned);
                }
                if ($live === $arms) return $prelude;
                return array() === $live ? array() : array(array('prelude' => implode(',', $live), 'body' => $body));
            });
            if ($content !== $asset['content']) $asset = CompiledAssets::withRewrittenTextContent($asset, $content);
        }
        unset($asset);
        return $assets;
    }

    /** @param list<array<string,mixed>> $assets @return list<array<string,mixed>> */
    public static function collect(array $assets, string $markup): array
    {
        $emitted = self::emitted($markup);
        $missing = array();
        foreach ($assets as $asset) {
            if ('css' !== ($asset['kind'] ?? null)) continue;
            preg_match_all(self::MARKER, (string) ($asset['content'] ?? ''), $matches);
            foreach (array_unique($matches[0]) as $marker) {
                if (!isset($emitted[$marker])) $missing[$marker] ??= (string) ($asset['source_path'] ?? '');
            }
        }
        $diagnostics = array();
        foreach ($missing as $marker => $source) $diagnostics[] = array(
            'code' => 'wordpress_site_plan_orphaned_navigation_instance',
            'severity' => 'warning',
            'message' => 'Projected navigation CSS targets an instance absent from emitted markup: ' . $marker,
            'source_path' => $source,
            'instance_marker' => $marker,
        );
        return $diagnostics;
    }

    /** @return array<string, true> */
    private static function emitted(string $markup): array
    {
        preg_match_all(self::MARKER, $markup, $matches);
        return array_fill_keys($matches[0], true);
    }

    /**
     * Whether every element `$selector` matches carries one of `$markers`.
     * Plain compounds require their classes; `:is()`, `:where()` and `:has()`
     * require a marker only when each alternative does; negations, other
     * functional pseudo-classes, attribute values and strings require nothing.
     *
     * @param list<string> $markers
     */
    private static function requiresAny(string $selector, array $markers): bool
    {
        $plain = '';
        $length = strlen($selector);
        for ($i = 0; $i < $length; ++$i) {
            $char = $selector[$i];
            if ('"' === $char || "'" === $char) {
                $end = $i + 1;
                while ($end < $length && $selector[$end] !== $char) $end += '\\' === $selector[$end] ? 2 : 1;
                $i = $end;
                $plain .= ' ';
                continue;
            }
            if ('[' === $char || '(' === $char) {
                $end = self::groupEnd($selector, $i);
                if (null === $end) return false;
                if ('(' === $char && preg_match('/:(is|where|has)$/i', $plain)) {
                    $alternatives = CssStylesheetTransformer::splitSelectorList(substr($selector, $i + 1, $end - $i - 1)) ?? array();
                    $required = array() !== $alternatives;
                    foreach ($alternatives as $alternative) $required = $required && self::requiresAny($alternative, $markers);
                    if ($required) return true;
                }
                $i = $end;
                $plain .= ' ';
                continue;
            }
            $plain .= $char;
        }
        foreach ($markers as $marker) {
            if (preg_match('/\.' . preg_quote($marker, '/') . '(?![A-Za-z0-9_-])/', $plain)) return true;
        }
        return false;
    }

    /**
     * `$selector` without the selector-list alternatives that require one of
     * `$markers`. Each list keeps at least one alternative, and `:is()`,
     * `:not()` and `:has()` keep their weight: an alternative heavier than
     * every one kept stays, since the list takes its heaviest argument.
     *
     * @param list<string> $markers
     */
    private static function withoutOrphanedAlternatives(string $selector, array $markers): string
    {
        $output = '';
        $length = strlen($selector);
        for ($i = 0; $i < $length; ++$i) {
            $char = $selector[$i];
            if ('"' === $char || "'" === $char || '[' === $char || '(' === $char) {
                $end = '(' === $char || '[' === $char ? self::groupEnd($selector, $i) : null;
                if ('"' === $char || "'" === $char) {
                    for ($end = $i + 1; $end < $length && $selector[$end] !== $char; ++$end) if ('\\' === $selector[$end]) ++$end;
                }
                if (null === $end) return $selector;
                $group = substr($selector, $i, $end - $i + 1);
                if ('(' === $char && preg_match('/:(is|where|not|has)$/i', $output, $name)) {
                    $alternatives = CssStylesheetTransformer::splitSelectorList(substr($selector, $i + 1, $end - $i - 1));
                    if (null !== $alternatives) {
                        $kept = $dropped = array();
                        foreach ($alternatives as $alternative) {
                            if (self::requiresAny($alternative, $markers)) $dropped[] = $alternative;
                            else $kept[] = self::withoutOrphanedAlternatives($alternative, $markers);
                        }
                        if (array() === $kept) $kept[] = array_shift($dropped);
                        $weights = 'where' === strtolower($name[1]) ? array() : array_map(self::weight(...), $alternatives);
                        if (!in_array(null, $weights, true)) {
                            $weight = array() === $weights ? 0 : max(array_map(self::weight(...), $kept));
                            foreach ($dropped as $alternative) if (array() !== $weights && self::weight($alternative) > $weight) $kept[] = $alternative;
                            $group = '(' . implode(',', $kept) . ')';
                        }
                    }
                }
                $output .= $group;
                $i = $end;
                continue;
            }
            $output .= $char;
        }
        return $output;
    }

    /** Specificity of one complex selector, or null when it does not parse. */
    private static function weight(string $selector): ?int
    {
        $parsed = CssSelectorMatcher::parse(trim($selector));
        return ($parsed['supported'] ?? false) ? CssSelectorMatcher::specificity($parsed) : null;
    }

    /** Offset of the bracket closing the group opened at `$start`, skipping strings. */
    private static function groupEnd(string $selector, int $start): ?int
    {
        $depth = 0;
        $length = strlen($selector);
        for ($i = $start; $i < $length; ++$i) {
            $char = $selector[$i];
            if ('"' === $char || "'" === $char) {
                for (++$i; $i < $length && $selector[$i] !== $char; ++$i) if ('\\' === $selector[$i]) ++$i;
                continue;
            }
            if ('(' === $char || '[' === $char) ++$depth;
            if (')' === $char || ']' === $char) {
                --$depth;
                if (0 === $depth) return $i;
            }
        }
        return null;
    }
}

<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

/**
 * Route geometry for chrome that moved into a shared part.
 *
 * Shell extraction dedupes a route's chrome into one part by structure, not
 * by geometry carriers, so the part keeps the chosen capture's carriers. A
 * carrier is that capture's geometry: where the part sits in its route's
 * page grid, for one. Another route placed its copy elsewhere, and its own
 * carrier rules still say so in its stylesheet, but name a class no markup
 * on the route carries any more. Those rules are restated for the part's
 * carrier in the same position, in the route's own stylesheet: it renders
 * only on that route and after shared chrome, so on that route it wins.
 */
final class ExtractedChromeCarriers
{
    /** Page working state: the route's carrier => the part's carrier in its place. */
    public const KEY = '_chrome_carrier_aliases';

    private const CARRIER = '/(?<![\w-])be-inline-geometry-[a-f0-9]{16}(?:-[a-f0-9]{16})?(?![\w-])/';

    /**
     * The route's carriers the part replaced, paired by position. Empty when
     * the two markups do not carry the same number of carriers, or when one
     * route carrier sits where the part has two different ones.
     *
     * @return array<string, string>
     */
    public static function aliases(string $routeChrome, string $partMarkup): array
    {
        $own = self::carriers($routeChrome);
        $part = self::carriers($partMarkup);
        if (count($own) !== count($part)) return array();
        $aliases = array();
        $conflicts = array();
        foreach ($own as $position => $carrier) {
            $replacement = $part[$position];
            if (isset($aliases[$carrier]) && $aliases[$carrier] !== $replacement) $conflicts[$carrier] = true;
            $aliases[$carrier] = $replacement;
        }
        return array_filter(array_diff_key($aliases, $conflicts), static fn(string $replacement, string $carrier): bool => $replacement !== $carrier, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Restate each route's replaced carrier rules for the part's carrier, in
     * the stylesheets scoped to that route alone.
     *
     * @param array<int, array<string, mixed>> $assets
     * @param array<int, array<string, mixed>> $pages
     * @return array<int, array<string, mixed>>
     */
    public static function apply(array $assets, array $pages): array
    {
        $aliasesBySource = array();
        foreach ($pages as $page) {
            $aliases = $page[self::KEY] ?? array();
            if (is_array($aliases) && array() !== $aliases) $aliasesBySource[(string) ($page['source_path'] ?? '')] = $aliases;
        }
        if (array() === $aliasesBySource) return $assets;
        $transformer = new CssStylesheetTransformer();
        foreach ($assets as &$asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null) || isset($asset['payload_reference'])) continue;
            $scopes = is_array($asset['scopes'] ?? null) ? $asset['scopes'] : array();
            if (1 !== count($scopes) || 'page' !== ($scopes[0]['kind'] ?? null)) continue;
            $aliases = $aliasesBySource[(string) ($scopes[0]['source_path'] ?? '')] ?? null;
            if (null === $aliases || 1 !== preg_match(self::CARRIER, $asset['content'])) continue;
            $content = $transformer->transform($asset['content'], static function (string $prelude, string $body) use ($aliases): string|array {
                $aliased = preg_replace_callback(self::CARRIER, static fn(array $match): string => $aliases[$match[0]] ?? $match[0], $prelude) ?? $prelude;
                return $aliased === $prelude ? $prelude : array(array('prelude' => $prelude, 'body' => $body), array('prelude' => $aliased, 'body' => $body));
            });
            if ($content !== $asset['content']) $asset = CompiledAssets::withRewrittenTextContent($asset, $content);
        }
        unset($asset);
        return $assets;
    }

    /** @return list<string> Carriers on the markup's elements, in document order. */
    private static function carriers(string $markup): array
    {
        $carriers = array();
        if (!preg_match_all('/<[a-z][\w-]*\s[^>]*?\bclass="([^"]*)"/i', $markup, $matches)) return $carriers;
        foreach ($matches[1] as $classes) {
            if (preg_match_all(self::CARRIER, $classes, $found)) array_push($carriers, ...$found[0]);
        }
        return $carriers;
    }
}

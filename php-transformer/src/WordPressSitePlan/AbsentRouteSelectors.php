<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/**
 * Route-variant projection scopes a shared stylesheet's rules to the routes
 * that need them with root classes: a route class (`be-r-…`) or a route-set
 * class (`be-rs-…`) on `<html>`. The variants are projected for every route
 * of the site, but a stylesheet is delivered only where its scopes say: a
 * sheet whose documents never carry a root class matches nothing with an arm
 * that requires it. Those arms are dropped from that sheet.
 *
 * Only sheets confined to known documents are pruned. A global sheet, a
 * frame fallback lent to WordPress-made views, and a sheet another sheet
 * imports may load where no route is known. Editor canvases and WordPress
 * views without a captured route carry a subset of a route's root classes
 * (the bare `be-r` marker), so an arm no delivering route can match matches
 * nothing there either.
 */
final class AbsentRouteSelectors
{
    private const ROUTE_CLASS = '/^be-rs?-[0-9a-f]+$/D';

    /**
     * @param list<array<string,mixed>> $assets
     * @param array<string,array<string,mixed>> $documentsBySource Every canonical document by source path.
     * @return list<array<string,mixed>>
     */
    public static function prune(array $assets, array $documentsBySource): array
    {
        $rootClasses = array();
        $routeClasses = array();
        foreach ($documentsBySource as $source => $document) {
            $classes = preg_split('/\s+/', trim((string) ($document['document_metadata']['root_attributes']['class'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: array();
            $classes = array_values(array_filter($classes, static fn(string $class): bool => 1 === preg_match(self::ROUTE_CLASS, $class)));
            $rootClasses[(string) $source] = array_fill_keys($classes, true);
            $routeClasses += array_fill_keys($classes, true);
        }
        if (array() === $routeClasses) return $assets;
        $stylesheets = array();
        foreach ($assets as $index => $asset) {
            if ('css' === ($asset['kind'] ?? null) && is_string($asset['content'] ?? null)) $stylesheets[$index] = $asset['content'];
        }
        foreach ($assets as $index => &$asset) {
            if (!isset($stylesheets[$index]) || isset($asset['payload_reference']) || !empty($asset['frame_fallback'])) continue;
            $live = self::deliveredRootClasses($asset, $rootClasses);
            if (null === $live || self::imported($asset, $stylesheets, $index)) continue;
            $absent = array();
            if (preg_match_all('/(?<!\\\\)\.(be-rs?-[0-9a-f]+)(?![\w\\\\-])/', $asset['content'], $matches)) {
                foreach (array_unique($matches[1]) as $class) {
                    if (isset($routeClasses[$class]) && !isset($live[$class])) $absent[] = preg_quote($class, '/');
                }
            }
            if (array() === $absent) continue;
            $content = AbsentCarrierSelectors::withoutArmsRequiring($asset['content'], '/(?<!\\\\)\.(?:' . implode('|', $absent) . ')(?![\w\\\\-])/');
            if ($content === $asset['content']) continue;
            $asset = CompiledAssets::withRewrittenTextContent($asset, $content);
        }
        unset($asset);
        return $assets;
    }

    /**
     * The route classes of every document that loads the sheet, or null when
     * it may load where no canonical document is known.
     *
     * @param array<string,mixed> $asset
     * @param array<string,array<string,true>> $rootClasses
     * @return array<string,true>|null
     */
    private static function deliveredRootClasses(array $asset, array $rootClasses): ?array
    {
        $scopes = $asset['scopes'] ?? null;
        if (!is_array($scopes) || array() === $scopes) return null;
        $sources = array();
        foreach ($scopes as $scope) {
            if (!is_array($scope) || 'global' === ($scope['kind'] ?? 'global') || !is_string($scope['source_path'] ?? null)) return null;
            $sources[] = $scope['source_path'];
        }
        foreach ($asset['stylesheet_instances'] ?? array() as $instance) {
            if (!is_array($instance) || !is_string($instance['source_path'] ?? null)) return null;
            $sources[] = $instance['source_path'];
        }
        $live = array();
        foreach (array_unique($sources) as $source) {
            if (!isset($rootClasses[$source])) return null;
            $live += $rootClasses[$source];
        }
        return $live;
    }

    /** @param array<string,mixed> $asset @param array<int,string> $stylesheets */
    private static function imported(array $asset, array $stylesheets, int $index): bool
    {
        $name = basename((string) ($asset['target_path'] ?? $asset['source_path'] ?? ''));
        if ('' === $name) return true;
        foreach ($stylesheets as $other => $css) {
            if ($other !== $index && str_contains($css, $name)) return true;
        }
        return false;
    }
}

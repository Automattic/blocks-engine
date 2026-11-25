<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/**
 * Route-variant projection gates a shared stylesheet's rules with root
 * classes: a route class (`be-r-…`) or a route-set class (`be-rs-…`) on
 * `<html>`. A sheet every route links carries the gated arms of all of them,
 * so each visitor downloads every other route's variants too.
 * {@see AbsentRouteSelectors} cannot prune that sheet: it also loads as the
 * frame fallback on WordPress-made views and in the editor.
 *
 * Each document instance of such a sheet is delivered instead from a copy
 * without the arms that require a route class that document's root does not
 * carry. The copy keeps every other rule in its order, so on that route (and
 * in its page editor, which stamps the same root classes) it matches exactly
 * what the shared sheet matches. The shared sheet stays as it is for the
 * frame fallback, where no route's classes are known. Documents whose roots
 * agree on the sheet's route classes share one copy, and a copy saving less
 * than {@see MIN_SAVED_BYTES} is not written.
 *
 * Only a sheet whose bytes are its own is copied: not one delivered through
 * parts or replays, a part itself, one another sheet imports, or one with
 * no document instances. A copy sits beside its sheet and keeps its leading
 * `@import`s, so they resolve and order the same.
 */
final class RouteStylesheetDeliveries
{
    /** Asset field mapping an instance's document source path to the target path delivering it. */
    public const FIELD = 'stylesheet_deliveries';

    private const ROUTE_CLASS = '/^be-rs?-[0-9a-f]+$/D';
    /** A copy saving less than this costs more theme bytes than visitors gain. */
    private const MIN_SAVED_BYTES = 4096;

    /**
     * @param list<array<string,mixed>> $assets
     * @param array<string,array<string,mixed>> $documentsBySource Every canonical document by source path.
     * @return list<array<string,mixed>>
     */
    public static function specialize(array $assets, array $documentsBySource): array
    {
        $rootClasses = $routeClasses = array();
        foreach ($documentsBySource as $source => $document) {
            $classes = preg_split('/\s+/', trim((string) ($document['document_metadata']['root_attributes']['class'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: array();
            $classes = array_values(array_filter($classes, static fn(string $class): bool => 1 === preg_match(self::ROUTE_CLASS, $class)));
            $rootClasses[(string) $source] = array_fill_keys($classes, true);
            $routeClasses += array_fill_keys($classes, true);
        }
        if (array() === $routeClasses) return $assets;
        $stylesheets = $partTargets = $targets = array();
        foreach ($assets as $index => $asset) {
            $targets[strtolower((string) ($asset['target_path'] ?? ''))] = true;
            foreach ($asset['stylesheet_parts'] ?? array() as $path) $partTargets[(string) $path] = true;
            if ('css' === ($asset['kind'] ?? null) && is_string($asset['content'] ?? null)) $stylesheets[$index] = $asset['content'];
        }
        $copies = array();
        foreach ($assets as $index => &$asset) {
            if (!isset($stylesheets[$index]) || isset($asset['payload_reference']) || 'editor' === ($asset['stylesheet_target'] ?? 'both')
                || empty($asset['stylesheet_instances']) || isset($asset['stylesheet_parts']) || isset($asset['stylesheet_replays']) || isset($asset[self::FIELD])
                || isset($partTargets[(string) ($asset['target_path'] ?? '')])
                || !str_ends_with(strtolower((string) ($asset['target_path'] ?? '')), '.css') || self::imported($asset, $stylesheets, $index)) continue;
            preg_match_all('/(?<!\\\\)\.(be-rs?-[0-9a-f]+)(?![\w\\\\-])/', $asset['content'], $matches);
            $named = array_values(array_filter(array_unique($matches[1]), static fn(string $class): bool => isset($routeClasses[$class])));
            if (array() === $named) continue;
            $deliveries = $byAbsent = array();
            foreach ($asset['stylesheet_instances'] as $instance) {
                $source = is_array($instance) ? ($instance['source_path'] ?? null) : null;
                if (!is_string($source) || !isset($rootClasses[$source]) || isset($deliveries[$source])) continue;
                $absent = array_values(array_filter($named, static fn(string $class): bool => !isset($rootClasses[$source][$class])));
                if (array() === $absent) continue;
                $key = implode(' ', $absent);
                if (!array_key_exists($key, $byAbsent)) {
                    $content = AbsentCarrierSelectors::withoutArmsRequiring($asset['content'], '/(?<!\\\\)\.(?:' . implode('|', array_map(static fn(string $class): string => preg_quote($class, '/'), $absent)) . ')(?![\w\\\\-])/');
                    $byAbsent[$key] = strlen($asset['content']) - strlen($content) < self::MIN_SAVED_BYTES || '' === trim($content) ? null : self::copy($asset, $content, $targets, $copies);
                }
                if (null === $byAbsent[$key]) continue;
                $deliveries[$source] = $byAbsent[$key];
                $scope = PageStylesheets::documentScope($documentsBySource[$source]);
                if (!in_array($scope, $copies[$byAbsent[$key]]['scopes'], true)) $copies[$byAbsent[$key]]['scopes'][] = $scope;
            }
            if (array() === $deliveries) continue;
            ksort($deliveries, SORT_STRING);
            $asset[self::FIELD] = $deliveries;
        }
        unset($asset);
        return array_merge(array_values($assets), array_values($copies));
    }

    /**
     * Target paths only ever delivered through another asset's instances.
     *
     * @param array<int,array<string,mixed>> $assets
     * @return array<string,true>
     */
    public static function deliveryTargets(array $assets): array
    {
        $targets = array();
        foreach ($assets as $asset) foreach ($asset[self::FIELD] ?? array() as $target) $targets[(string) $target] = true;
        return $targets;
    }

    /**
     * The copy delivering $content for $asset, beside it so relative URLs
     * resolve the same; copies with the same bytes are one resource.
     *
     * @param array<string,mixed> $asset
     * @param array<string,true> $targets
     * @param array<string,array<string,mixed>> $copies
     */
    private static function copy(array $asset, string $content, array &$targets, array &$copies): ?string
    {
        $target = (string) $asset['target_path'];
        $hash = hash('sha256', $target . "\0" . $content);
        for ($length = 12; $length <= 64; ++$length) {
            $suffix = '.route-' . substr($hash, 0, $length) . '.css';
            $path = substr($target, 0, -4) . $suffix;
            if (isset($copies[$path])) return $path;
            if (!isset($targets[strtolower($path)])) break;
        }
        if (!isset($path) || isset($targets[strtolower($path)])) return null;
        $targets[strtolower($path)] = true;
        $copy = CompiledAssets::withRewrittenTextContent($asset, $content);
        $copy['path'] = $copy['target_path'] = $path;
        $copy['source_path'] = (string) preg_replace('/\.css$/i', '', (string) $asset['source_path']) . $suffix;
        // URLs inside the copy still resolve against the source sheet's origin.
        $copy['reference_origin'] = $asset['reference_origin'] ?? $asset['source_path'];
        $copy['token'] = 'asset-' . substr(hash('sha256', $path), 0, 16);
        $copy['reconciliation_identity'] = WordPressSitePlan::identity('asset', $copy['source_path'], $path);
        // Scoped to the documents it delivers; it is enqueued only in their
        // stead (see ThemeBootstrap), never on its own.
        $copy['scopes'] = array();
        unset($copy['stylesheet_instances'], $copy['stylesheet_parent'], $copy['stylesheet_inline_order'], $copy['frame_fallback'], $copy['frame_fallback_handle'], $copy['references'], $copy['inline_references']);
        $copies[$path] = $copy;
        return $path;
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

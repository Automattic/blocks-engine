<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleTree;

/** Share resources, not cascade positions: each original enqueue replays its ordered parts. */
final class EditorStaticStateAssets
{
    /** @param list<array<string,mixed>> $assets @return list<array<string,mixed>> */
    public static function share(array $assets, AssetReferenceCanonicalizer $references): array
    {
        $origins = array_values(array_filter(array_map(static fn(array $asset): ?string => 'css' === ($asset['kind'] ?? null) && 'files' === ($asset['source'] ?? null) ? ($asset['source_path'] ?? null) : null, $assets)));
        $units = $owners = array();
        foreach ($assets as $index => $asset) {
            if ('editor-static-state' !== ($asset['source'] ?? null) || 'css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null) || isset($asset['payload_reference'])) continue;
            // Resolve against the real origins before moving text to a synthetic file.
            $css = $references->content($references->cssFromOrigins($asset['content'], $origins), (string) ($asset['reference_origin'] ?? $asset['source_path']));
            $tree = new CssRuleTree($css);
            if (!$tree->wellFormed) continue;
            $end = 0;
            foreach ($tree->rules() as $rule) {
                $text = substr($css, $end, $rule['end'] + 1 - $end);
                $end = $rule['end'] + 1;
                // Preambles/imports cannot safely move to another stylesheet.
                if (';' === $css[$rule['boundary']]) { unset($units[$index]); continue 2; }
                $units[$index][] = $text;
            }
            if (!isset($units[$index])) continue;
            $units[$index][count($units[$index]) - 1] .= substr($css, $end);
            foreach ($units[$index] as $text) foreach ($asset['scopes'] ?? array() as $scope) {
                $owner = $scope['source_path'] ?? 'global';
                $owners[$text][$owner] = true;
            }
        }
        // Owner sets are unordered: discovery order must not split otherwise identical runs.
        $ownerSets = array();
        foreach ($owners as $text => $set) {
            $pages = array_keys($set);
            sort($pages, SORT_STRING);
            $ownerSets[$text] = $pages;
        }
        $resources = array();
        foreach ($units as $index => $texts) {
            $shared = false;
            foreach ($texts as $text) if (count($owners[$text] ?? array()) > 1) $shared = true;
            if (!$shared) continue;
            $asset = $assets[$index];
            $parts = array();
            foreach ($texts as $text) {
                $set = $ownerSets[$text];
                $last = count($parts) - 1;
                if ($last >= 0 && $parts[$last]['owners'] === $set) {
                    $parts[$last]['text'] .= $text;
                } else {
                    $parts[] = array('text' => $text, 'owners' => $set);
                }
            }
            $paths = array();
            foreach ($parts as $part) {
                $text = $part['text'];
                $prefix = count($part['owners']) > 1 ? 'shared-' : 'page-' . substr(hash('sha256', $asset['source_path']), 0, 16) . '-';
                $path = 'assets/css/editor-static-state-' . $prefix . hash('sha256', $text) . '.css';
                $paths[] = $path;
                if (isset($resources[$path])) {
                    foreach ($asset['scopes'] as $scope) if (!in_array($scope, $resources[$path]['scopes'], true)) $resources[$path]['scopes'][] = $scope;
                    continue;
                }
                $resource = CompiledAssets::withRewrittenTextContent($asset, $text);
                $resource['source_path'] = $resource['target_path'] = $path;
                $resource['token'] = 'asset-' . substr(hash('sha256', $path), 0, 16);
                $resource['reconciliation_identity'] = WordPressSitePlan::identity('asset', $path, $path);
                $resource['scopes'] = $asset['scopes'];
                unset($resource['stylesheet_instances'], $resource['stylesheet_parent'], $resource['frame_fallback'], $resource['reference_origin'], $resource['references']);
                $resources[$path] = $resource;
            }
            // Keep occurrence ownership as metadata on a real resource, never
            // as an empty CSS file that would also be published to disk.
            $asset['stylesheet_parts'] = $paths;
            unset($asset['content'], $asset['content_hash'], $asset['size_bytes']);
            $resources[$paths[0]]['stylesheet_replays'][] = $asset;
            unset($assets[$index]);
        }
        return array_merge(array_values($assets), array_values($resources));
    }
}

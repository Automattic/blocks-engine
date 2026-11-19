<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;

/** Author selectors are analysis input, never delivery for captured routes. */
final class EvidenceRouteStylesheets
{
    /**
     * @param list<array<string, mixed>> $parts Template parts every route renders; their markup counts as emitted.
     */
    public static function retire(array $assets, array $pages, array $parts = array()): array
    {
        $evidence = array();
        foreach ($pages as $page) if (!empty($page['document_metadata']['engine_owned_styles'])) $evidence[$page['source_path']] = true;
        if (array() === $evidence) return $assets;
        $markup = (string) json_encode(array_values(array_map(static fn(array $page): array => array($page['canonical_block_markup'] ?? null, $page['resolved_block_markup'] ?? null), array_filter($pages, static fn(array $page): bool => isset($evidence[$page['source_path']])))), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $retired = array();
        // A sheet legacy routes still link is retired only on the evidence
        // routes, which never linked it: they get, as a sibling sheet in the
        // same cascade position, only what makes their carriers' animations
        // run (carriers bind an animation by name, not its timing).
        $siblings = array();
        $legacy = array_values(array_filter($pages, static fn(array $page): bool => !isset($evidence[$page['source_path']])));
        $evidencePages = array_values(array_filter($pages, static fn(array $page): bool => isset($evidence[$page['source_path']])));
        foreach ($assets as $key => &$asset) {
            // Engine-owned generated sheets (editor repairs naming only engine
            // markup) are not author selectors: retiring them hides the editor's
            // own repairs, such as the selectable box of a hidden-at-rest image.
            if ('css' !== ($asset['kind'] ?? null) || in_array($asset['source'] ?? '', array('engine-support', 'wordpress-compat'), true) || EngineMarker::ENGINE_OWNED_STYLESHEET_ROLE === ($asset['source_role'] ?? null)) continue;
            $scopes = array();
            $evidenceScopes = array();
            foreach ($asset['scopes'] as $scope) {
                if ('global' === $scope['kind']) {
                    foreach ($legacy as $page) $scopes[] = PageStylesheets::documentScope($page);
                    foreach ($evidencePages as $page) $evidenceScopes[] = PageStylesheets::documentScope($page);
                } elseif (!isset($evidence[$scope['source_path'] ?? ''])) $scopes[] = $scope;
                else $evidenceScopes[] = $scope;
            }
            $asset['stylesheet_instances'] = array_values(array_filter($asset['stylesheet_instances'] ?? array(), static fn(array $instance): bool => !isset($evidence[$instance['source_path']])));
            unset($asset['frame_fallback'], $asset['stylesheet_replays']);
            if (array() !== $scopes) {
                if (array() !== $evidenceScopes && 'editor-static-state' !== ($asset['source'] ?? null)) $siblings[$key] = self::evidenceSibling($asset, $evidenceScopes);
                $asset['scopes'] = $scopes;
                continue;
            }
            $retired[] = $key;
        }
        unset($asset);
        if (array() === $retired && array() === $siblings) return $assets;
        // Preserve resource identities referenced by document metadata (font
        // faces have already been projected into theme.json at this point),
        // and the state rules carriers cannot replace. A sheet legacy routes
        // keep is author CSS, not delivered evidence context.
        $context = '';
        foreach ($assets as $key => $asset) if ('css' === ($asset['kind'] ?? null) && !in_array($key, $retired, true) && !isset($siblings[$key])) $context .= (string) ($asset['content'] ?? '');
        $placedSiblings = array();
        if (array() !== $siblings) {
            // Each sibling follows the sheet it was retired from.
            $placed = array();
            $placedRetired = array();
            foreach ($assets as $key => $asset) {
                $placed[] = $asset;
                if (in_array($key, $retired, true)) $placedRetired[] = array_key_last($placed);
                if (!isset($siblings[$key])) continue;
                $placed[] = $siblings[$key];
                $placedRetired[] = $placedSiblings[] = array_key_last($placed);
            }
            $assets = $placed;
            $retired = $placedRetired;
        }
        $assets = self::retireRules($assets, $retired, $markup, $parts, $context, $placedSiblings);
        // A sibling that retains nothing has nothing to deliver.
        if (array() === $placedSiblings) return $assets;
        foreach ($placedSiblings as $key) if ('' === trim((string) preg_replace('/@layer\s[^{};]+;/i', '', (string) ($assets[$key]['content'] ?? '')))) unset($assets[$key]);
        return array_values($assets);
    }

    /**
     * @param array<int|string, array<string, mixed>> $assets
     * @param list<int|string> $retired
     * @param list<array<string, mixed>> $parts
     * @param list<int|string> $siblings Retired keys that are evidence-route siblings of a sheet legacy routes keep.
     * @return array<int|string, array<string, mixed>>
     */
    private static function retireRules(array $assets, array $retired, string $markup, array $parts, string $context, array $siblings = array()): array
    {
        // Editor static state sheets are editor projections of author rules
        // the author sheets themselves still carry: keeping their state rules
        // would deliver every kept rule twice.
        $editor = array_values(array_filter($retired, static fn(int|string $key): bool => 'editor-static-state' === ($assets[$key]['source'] ?? null)));
        $author = array_values(array_diff($retired, $editor));
        foreach ($editor as $key) {
            preg_match_all('/@font-face\s*\{[^{}]*\}/i', (string) ($assets[$key]['content'] ?? ''), $faces);
            $assets[$key] = CompiledAssets::withRewrittenTextContent($assets[$key], implode("\n", $faces[0]));
        }
        if (array() === $author) return $assets;
        $reach = $markup . (string) json_encode(array_column($parts, 'canonical_block_markup'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $attributes = EvidenceRouteStateRules::stateAttributes($markup);
        // The evidence routes never linked a sheet legacy routes keep, so its
        // sibling delivers no state rules they did not already receive: only
        // what makes the carriers' animations run.
        foreach (array(array_values(array_diff($author, $siblings)), array_values(array_intersect($author, $siblings))) as $carriedOnly => $keys) {
            if (array() === $keys) continue;
            $rules = new EvidenceRouteStateRules($attributes, $markup, $reach);
            $kept = $rules->retain(array_map(static fn(int|string $key): string => (string) ($assets[$key]['content'] ?? ''), $keys), $context, (bool) $carriedOnly);
            foreach ($keys as $index => $key) $assets[$key] = CompiledAssets::withRewrittenTextContent($assets[$key], $kept[$index]);
        }
        return $assets;
    }

    /**
     * The evidence routes' share of a sheet legacy routes keep linking. It is
     * written, and named as a source, next to its sheet, so relative resource
     * URLs resolve unchanged.
     *
     * @param array<string, mixed> $asset
     * @param list<array<string, mixed>> $scopes
     * @return array<string, mixed>
     */
    private static function evidenceSibling(array $asset, array $scopes): array
    {
        $sibling = $asset;
        $sibling['target_path'] = self::suffixed((string) $asset['target_path']);
        if (isset($sibling['path'])) $sibling['path'] = $sibling['target_path'];
        $sibling['source_path'] = self::suffixed((string) ($asset['source_path'] ?? ''));
        $sibling['scopes'] = $scopes;
        $sibling['stylesheet_instances'] = array();
        $sibling['token'] = 'asset-' . substr(hash('sha256', $sibling['target_path']), 0, 16);
        $sibling['reconciliation_identity'] = WordPressSitePlan::identity('asset', $sibling['source_path'], $sibling['target_path']);
        return $sibling;
    }

    private static function suffixed(string $path): string
    {
        $dot = strrpos($path, '.');
        $slash = strrpos($path, '/');
        $stem = false !== $dot && (false === $slash || $dot > $slash) ? substr($path, 0, $dot) : $path;
        return $stem . '-evidence-routes' . substr($path, strlen($stem));
    }
}

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
        foreach ($pages as $page) if (!empty($page['document_metadata']['engine_owned_styles'])) $evidence[$page['source_path']] = $page;
        if (array() === $evidence) return $assets;
        $markup = (string) json_encode(array_values(array_map(static fn(array $page): array => array($page['canonical_block_markup'] ?? null, $page['resolved_block_markup'] ?? null), $evidence)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $retired = $shared = array();
        $legacy = array_values(array_filter($pages, static fn(array $page): bool => !isset($evidence[$page['source_path']])));
        foreach ($assets as $key => &$asset) {
            // Engine-owned generated sheets (editor repairs naming only engine
            // markup) are not author selectors: retiring them hides the editor's
            // own repairs, such as the selectable box of a hidden-at-rest image.
            if ('css' !== ($asset['kind'] ?? null) || self::engineOwned($asset)) continue;
            $scopes = $evidenceScopes = array();
            foreach ($asset['scopes'] as $scope) {
                if ('global' === $scope['kind']) {
                    foreach ($legacy as $page) $scopes[] = PageStylesheets::documentScope($page);
                    foreach ($evidence as $page) $evidenceScopes[] = PageStylesheets::documentScope($page);
                } elseif (!isset($evidence[$scope['source_path'] ?? ''])) $scopes[] = $scope;
                else $evidenceScopes[] = $scope;
            }
            $evidenceInstances = array_values(array_filter($asset['stylesheet_instances'] ?? array(), static fn(array $instance): bool => isset($evidence[$instance['source_path']])));
            $asset['stylesheet_instances'] = array_values(array_filter($asset['stylesheet_instances'] ?? array(), static fn(array $instance): bool => !isset($evidence[$instance['source_path']])));
            unset($asset['frame_fallback'], $asset['stylesheet_replays']);
            if (array() !== $scopes) {
                // A sheet legacy routes still load keeps serving them whole;
                // the evidence routes it also styled get its retained part.
                if (array() !== $evidenceScopes && !isset($asset['stylesheet_parts']) && 'editor-static-state' !== ($asset['source'] ?? null)) $shared[$key] = array('scopes' => $evidenceScopes, 'instances' => $evidenceInstances);
                $asset['scopes'] = $scopes;
                continue;
            }
            $retired[] = $key;
        }
        unset($asset);
        if (array() === $retired && array() === $shared) return $assets;
        // Preserve resource identities referenced by document metadata (font
        // faces have already been projected into theme.json at this point),
        // and the state rules carriers cannot replace.
        $context = $readers = '';
        foreach ($assets as $key => $asset) {
            if ('css' !== ($asset['kind'] ?? null) || in_array($key, $retired, true) || isset($shared[$key])) continue;
            $context .= (string) ($asset['content'] ?? '');
            if (self::engineOwned($asset) && self::reachesEvidence($asset, $evidence)) $readers .= (string) ($asset['content'] ?? '');
        }
        // Editor static state sheets are editor projections of author rules
        // the author sheets themselves still carry: keeping their state rules
        // would deliver every kept rule twice.
        $editor = array_values(array_filter($retired, static fn(int|string $key): bool => 'editor-static-state' === ($assets[$key]['source'] ?? null)));
        $author = array_values(array_diff($retired, $editor));
        foreach ($editor as $key) {
            preg_match_all('/@font-face\s*\{[^{}]*\}/i', (string) ($assets[$key]['content'] ?? ''), $faces);
            $assets[$key] = CompiledAssets::withRewrittenTextContent($assets[$key], implode("\n", $faces[0]));
        }
        // Retired and shared author sheets, in cascade order.
        $author = array_values(array_filter(array_keys($assets), static fn(int|string $key): bool => in_array($key, $author, true) || isset($shared[$key])));
        if (array() === $author) return $assets;
        $reach = $markup . (string) json_encode(array_column($parts, 'canonical_block_markup'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $rules = new EvidenceRouteStateRules(EvidenceRouteStateRules::stateAttributes($markup), $markup, $reach, EvidenceRouteStateRules::chromeHooks($parts));
        // The parts render here unchanged and carry no carriers: the rules
        // reaching them stay, confined to them. Blocks and carriers still read
        // the author's custom properties (`calc(var(--spacing) * 24)`,
        // `var(--text-4xl)`): their definitions stay with the evidence routes.
        $kept = $rules->retain(array_map(static fn(int|string $key): string => (string) ($assets[$key]['content'] ?? ''), $author), $context, $reach . $readers);
        $derived = array();
        foreach ($author as $index => $key) {
            if (!isset($shared[$key])) {
                $assets[$key] = CompiledAssets::withRewrittenTextContent($assets[$key], $kept[$index]);
                continue;
            }
            if ('' === trim($kept[$index])) continue;
            $derived[$key] = self::retainedCopy($assets[$key], $kept[$index], $shared[$key]['scopes'], $shared[$key]['instances']);
        }
        if (array() === $derived) return $assets;
        // Each retained copy follows the sheet it was drawn from.
        $result = array();
        foreach ($assets as $key => $asset) {
            $result[] = $asset;
            if (isset($derived[$key])) $result[] = $derived[$key];
        }
        return $result;
    }

    /** @param array<string, mixed> $asset */
    private static function engineOwned(array $asset): bool
    {
        return in_array($asset['source'] ?? '', array('engine-support', 'wordpress-compat'), true) || EngineMarker::ENGINE_OWNED_STYLESHEET_ROLE === ($asset['source_role'] ?? null);
    }

    /** @param array<string, mixed> $asset @param array<string, array<string, mixed>> $evidence */
    private static function reachesEvidence(array $asset, array $evidence): bool
    {
        foreach ($asset['scopes'] ?? array() as $scope) if ('global' === $scope['kind'] || isset($evidence[$scope['source_path'] ?? ''])) return true;
        return false;
    }

    /**
     * The retained part of a sheet legacy routes still load whole, delivered
     * to the evidence routes it styled at the same link occurrences.
     *
     * @param array<string, mixed> $asset
     * @param list<array<string, mixed>> $scopes
     * @param list<array<string, mixed>> $instances
     * @return array<string, mixed>
     */
    private static function retainedCopy(array $asset, string $content, array $scopes, array $instances): array
    {
        $copy = CompiledAssets::withRewrittenTextContent($asset, $content);
        foreach (array('source_path', 'target_path') as $field) {
            $path = (string) $copy[$field];
            $copy[$field] = (str_ends_with(strtolower($path), '.css') ? substr($path, 0, -4) : $path) . '.evidence-routes.css';
        }
        if (isset($copy['path'])) $copy['path'] = $copy['target_path'];
        $copy['token'] = 'asset-' . substr(hash('sha256', $copy['target_path']), 0, 16);
        $copy['reconciliation_identity'] = WordPressSitePlan::identity('asset', $copy['source_path'], $copy['target_path']);
        $copy['scopes'] = $scopes;
        unset($copy['stylesheet_instances'], $copy['stylesheet_parent'], $copy['frame_fallback'], $copy['stylesheet_replays'], $copy['reference_origin'], $copy['references']);
        if (array() !== $instances) $copy['stylesheet_instances'] = $instances;
        return $copy;
    }
}

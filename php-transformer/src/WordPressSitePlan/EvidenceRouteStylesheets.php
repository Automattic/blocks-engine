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
        // Sheets legacy routes still apply whole; never context for evidence routes.
        $legacyOnly = array();
        $legacy = array_values(array_filter($pages, static fn(array $page): bool => !isset($evidence[$page['source_path']])));
        $evidencePages = array_values(array_filter($pages, static fn(array $page): bool => isset($evidence[$page['source_path']])));
        $projected = array();
        foreach ($assets as $asset) {
            // Engine-owned generated sheets (editor repairs naming only engine
            // markup) are not author selectors: retiring them hides the editor's
            // own repairs, such as the selectable box of a hidden-at-rest image.
            if ('css' !== ($asset['kind'] ?? null) || in_array($asset['source'] ?? '', array('engine-support', 'wordpress-compat'), true) || EngineMarker::ENGINE_OWNED_STYLESHEET_ROLE === ($asset['source_role'] ?? null)) {
                $projected[] = $asset;
                continue;
            }
            $scopes = array();
            $evidenceScopes = array();
            foreach ($asset['scopes'] as $scope) {
                if ('global' === $scope['kind']) {
                    foreach ($legacy as $page) $scopes[] = PageStylesheets::documentScope($page);
                    foreach ($evidencePages as $page) $evidenceScopes[] = PageStylesheets::documentScope($page);
                } elseif (!isset($evidence[$scope['source_path'] ?? ''])) $scopes[] = $scope;
                else $evidenceScopes[] = $scope;
            }
            $original = $asset;
            $asset['stylesheet_instances'] = array_values(array_filter($asset['stylesheet_instances'] ?? array(), static fn(array $instance): bool => !isset($evidence[$instance['source_path']])));
            unset($asset['frame_fallback'], $asset['stylesheet_replays']);
            if (array() === $scopes) {
                $retired[] = count($projected);
                $projected[] = $asset;
                continue;
            }
            $asset['scopes'] = $scopes;
            $projected[] = $asset;
            // Legacy routes still apply the whole sheet. Evidence routes apply
            // what carriers cannot replace from it, at its cascade position.
            if (array() === $evidenceScopes || 'editor-static-state' === ($asset['source'] ?? null)) continue;
            $legacyOnly[] = count($projected) - 1;
            $retired[] = count($projected);
            $projected[] = self::evidenceCopy($original, $evidenceScopes);
        }
        $assets = $projected;
        if (array() === $retired) return $assets;
        // Preserve resource identities referenced by document metadata (font
        // faces have already been projected into theme.json at this point),
        // and the state rules carriers cannot replace.
        $context = '';
        foreach ($assets as $key => $asset) if ('css' === ($asset['kind'] ?? null) && !in_array($key, $retired, true) && !in_array($key, $legacyOnly, true)) $context .= (string) ($asset['content'] ?? '');
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
        $templateParts = array_values(array_filter($parts, static fn(array $part): bool => 'wp_template_part' === ($part['post_type'] ?? null)));
        $reach = $markup . (string) json_encode(array_column($parts, 'canonical_block_markup'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $rules = new EvidenceRouteStateRules(EvidenceRouteStateRules::stateAttributes($markup), $markup, $reach, implode("\n", array_map(static fn(array $part): string => (string) ($part['canonical_block_markup'] ?? ''), $templateParts)));
        $kept = $rules->retain(array_map(static fn(int|string $key): string => (string) ($assets[$key]['content'] ?? ''), $author), $context);
        foreach ($author as $index => $key) $assets[$key] = CompiledAssets::withRewrittenTextContent($assets[$key], $kept[$index]);
        // The sheet legacy routes keep already carries the font faces theme.json
        // was given; a copy left with only layer order is no resource of its own.
        $delivered = array();
        foreach ($assets as $asset) {
            $copy = $asset['_evidence_route_copy'] ?? false;
            unset($asset['_evidence_route_copy']);
            if ($copy) {
                $content = (string) preg_replace('/@font-face\s*\{[^{}]*\}/i', '', (string) ($asset['content'] ?? ''));
                if ('' === trim((string) preg_replace('/@layer\s[^{};]+;/i', '', $content))) continue;
                $asset = CompiledAssets::withRewrittenTextContent($asset, $content);
            }
            $delivered[] = $asset;
        }
        return $delivered;
    }

    /**
     * The evidence routes' own resource for a sheet legacy routes keep whole.
     *
     * @param array<string, mixed> $asset
     * @param list<array<string, mixed>> $scopes
     * @return array<string, mixed>
     */
    private static function evidenceCopy(array $asset, array $scopes): array
    {
        $source = (string) ($asset['source_path'] ?? $asset['path'] ?? '');
        $target = (string) ($asset['target_path'] ?? '');
        $copy = $asset;
        $copy['source_path'] = $source . '.evidence-route';
        // Relative url() references still resolve against the authored sheet.
        $copy['reference_origin'] = (string) ($asset['reference_origin'] ?? $source);
        $copy['target_path'] = (str_ends_with($target, '.css') ? substr($target, 0, -4) : $target) . '.evidence-route.css';
        if (isset($copy['path'])) $copy['path'] = $copy['target_path'];
        $copy['token'] = 'asset-' . substr(hash('sha256', $copy['target_path']), 0, 16);
        $copy['reconciliation_identity'] = WordPressSitePlan::identity('asset', $copy['source_path'], $copy['target_path']);
        $copy['scopes'] = $scopes;
        $copy['stylesheet_instances'] = array();
        $copy['_evidence_route_copy'] = true;
        unset($copy['frame_fallback'], $copy['stylesheet_replays'], $copy['inline_references']);
        return $copy;
    }
}

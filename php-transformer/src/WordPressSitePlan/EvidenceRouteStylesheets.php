<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;

/**
 * Author selectors are analysis input, never delivery for captured routes.
 * The capture's island visibility sheet is not author CSS: no carrier
 * restates it, so it stays delivered.
 */
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
        $legacy = array_values(array_filter($pages, static fn(array $page): bool => !isset($evidence[$page['source_path']])));
        foreach ($assets as $key => &$asset) {
            // Engine-owned generated sheets (editor repairs naming only engine
            // markup) are not author selectors: retiring them hides the editor's
            // own repairs, such as the selectable box of a hidden-at-rest image.
            if ('css' !== ($asset['kind'] ?? null) || in_array($asset['source'] ?? '', array('engine-support', 'wordpress-compat'), true) || EngineMarker::ENGINE_OWNED_STYLESHEET_ROLE === ($asset['source_role'] ?? null)) continue;
            if (CaptureEvidence::isDeviceIslandSheet((string) ($asset['content'] ?? ''))) continue;
            $scopes = array();
            foreach ($asset['scopes'] as $scope) {
                if ('global' === $scope['kind']) foreach ($legacy as $page) $scopes[] = PageStylesheets::documentScope($page);
                elseif (!isset($evidence[$scope['source_path'] ?? ''])) $scopes[] = $scope;
            }
            $asset['stylesheet_instances'] = array_values(array_filter($asset['stylesheet_instances'] ?? array(), static fn(array $instance): bool => !isset($evidence[$instance['source_path']])));
            unset($asset['frame_fallback'], $asset['stylesheet_replays']);
            if (array() !== $scopes) {
                $asset['scopes'] = $scopes;
                continue;
            }
            $retired[] = $key;
        }
        unset($asset);
        if (array() === $retired) return $assets;
        // Preserve resource identities referenced by document metadata (font
        // faces have already been projected into theme.json at this point),
        // and the state rules carriers cannot replace.
        $context = '';
        foreach ($assets as $key => $asset) if ('css' === ($asset['kind'] ?? null) && !in_array($key, $retired, true)) $context .= (string) ($asset['content'] ?? '');
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
        $rules = new EvidenceRouteStateRules(EvidenceRouteStateRules::stateAttributes($markup), $markup, $reach);
        $kept = $rules->retain(array_map(static fn(int|string $key): string => (string) ($assets[$key]['content'] ?? ''), $author), $context);
        foreach ($author as $index => $key) $assets[$key] = CompiledAssets::withRewrittenTextContent($assets[$key], $kept[$index]);
        return $assets;
    }
}

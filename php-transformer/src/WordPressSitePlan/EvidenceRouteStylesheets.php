<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/** Author selectors are analysis input, never delivery for captured routes. */
final class EvidenceRouteStylesheets
{
    public static function retire(array $assets, array $pages): array
    {
        $evidence = array();
        foreach ($pages as $page) if (!empty($page['document_metadata']['engine_owned_styles'])) $evidence[$page['source_path']] = true;
        if (array() === $evidence) return $assets;
        $legacy = array_values(array_filter($pages, static fn(array $page): bool => !isset($evidence[$page['source_path']])));
        foreach ($assets as &$asset) {
            if ('css' !== ($asset['kind'] ?? null) || in_array($asset['source'] ?? '', array('engine-support', 'wordpress-compat'), true)) continue;
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
            // Preserve resource identities referenced by document metadata. Font
            // faces have already been projected into theme.json at this point.
            preg_match_all('/@font-face\s*\{[^{}]*\}/i', (string) ($asset['content'] ?? ''), $faces);
            $asset = CompiledAssets::withRewrittenTextContent($asset, implode("\n", $faces[0]));
        }
        unset($asset);
        return $assets;
    }
}

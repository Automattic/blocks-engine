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
        // A global stylesheet retired to its font faces still loads on every
        // route. A page stylesheet restating the same face adds a second,
        // identical copy of it to that route.
        $globalFaces = array();
        if (array() === $legacy) {
            foreach ($assets as $asset) {
                if ('css' !== ($asset['kind'] ?? null) || in_array($asset['source'] ?? '', array('engine-support', 'wordpress-compat'), true)
                    || !in_array('global', array_column($asset['scopes'] ?? array(), 'kind'), true)
                    || !in_array((string) ($asset['media'] ?? ''), array('', 'all'), true)
                    || !empty($asset['stylesheet_activation']['disabled']) || 'both' !== ($asset['stylesheet_target'] ?? 'both')
                    || in_array('css-import', array_column(array_filter($asset['references'] ?? array(), 'is_array'), 'context'), true)) continue;
                foreach (self::fontFaces($asset) as $face) $globalFaces[$face] = true;
            }
        }
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
            $global = in_array('global', array_column($asset['scopes'], 'kind'), true);
            $faces = array_filter(self::fontFaces($asset), static fn(string $face): bool => $global || !isset($globalFaces[$face]));
            $asset = CompiledAssets::withRewrittenTextContent($asset, implode("\n", $faces));
        }
        unset($asset);
        return $assets;
    }

    /** @return list<string> */
    private static function fontFaces(array $asset): array
    {
        preg_match_all('/@font-face\s*\{[^{}]*\}/i', (string) ($asset['content'] ?? ''), $faces);
        return $faces[0];
    }
}

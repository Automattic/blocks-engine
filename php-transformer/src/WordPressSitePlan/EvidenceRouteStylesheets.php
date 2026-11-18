<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

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
            $content = (string) ($asset['content'] ?? '');
            preg_match_all('/@font-face\s*\{[^{}]*\}/i', $content, $faces);
            $asset = CompiledAssets::withRewrittenTextContent($asset, implode("\n", array_filter(array_merge($faces[0], array(self::deviceIslandRules($content))))));
        }
        unset($asset);
        return $assets;
    }

    /**
     * The capture's device-island visibility rules, with their media
     * conditions. A responsive capture folds each route's desktop and phone
     * frames into sibling islands and ships the rules choosing one per
     * viewport. They are capture runtime, not author presentation: box-tree
     * evidence observed each island only at its own capture width, so no
     * projected rule reproduces the switch, and retiring them would show both
     * islands at every width.
     */
    private static function deviceIslandRules(string $css): string
    {
        if (!str_contains($css, CaptureEvidence::DESKTOP_ONLY_CLASS) && !str_contains($css, CaptureEvidence::MOBILE_ONLY_CLASS)) return '';
        $island = '/\.(?:' . preg_quote(CaptureEvidence::DESKTOP_ONLY_CLASS, '/') . '|' . preg_quote(CaptureEvidence::MOBILE_ONLY_CLASS, '/') . ')(?![\w-])/';
        $kept = '';
        (new CssStylesheetTransformer())->visitStyleRules($css, static function (string $prelude, string $body, array $ancestors) use ($island, &$kept): void {
            if (1 !== preg_match($island, $prelude)) return;
            $rule = trim($prelude) . '{' . $body . '}';
            foreach (array_reverse($ancestors) as $condition) $rule = $condition . '{' . $rule . '}';
            $kept .= $rule;
        });
        return $kept;
    }
}

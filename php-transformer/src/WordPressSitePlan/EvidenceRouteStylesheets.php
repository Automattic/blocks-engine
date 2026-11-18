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
            $retained = $faces[0];
            $visibility = self::deviceIslandVisibility($content);
            if ('' !== $visibility) $retained[] = $visibility;
            $asset = CompiledAssets::withRewrittenTextContent($asset, implode("\n", $retained));
        }
        unset($asset);
        return $assets;
    }

    /**
     * The capture's device-island visibility rules
     * (`@media(max-width:1023px){.data-blocks-engine-capture-desktop-only{display:none!important}}`)
     * are capture contract, not author presentation: they pick which of a
     * route's desktop and phone islands paints at each width. Box-tree
     * evidence owns presentation, not that choice, so retiring them would
     * paint both islands everywhere.
     */
    private static function deviceIslandVisibility(string $css): string
    {
        $island = '/\.(?:' . preg_quote(CaptureEvidence::DESKTOP_ONLY_CLASS, '/') . '|' . preg_quote(CaptureEvidence::MOBILE_ONLY_CLASS, '/') . ')(?![\w-])/';
        if (1 !== preg_match($island, $css)) return '';
        $transformer = new CssStylesheetTransformer();
        // A malformed sheet comes back unchanged even when every rule is
        // dropped: retire it whole rather than ship its author rules.
        if ($transformer->transformStyleRules($css, static fn(): string => '') === $css) return '';
        $kept = $transformer->transformStyleRules(
            $css,
            static function (string $prelude, string $body) use ($island): string {
                if (1 !== preg_match($island, $prelude)) return '';
                $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
                if (null === $selectors) return '';
                $arms = array_values(array_filter(array_map('trim', $selectors), static fn(string $selector): bool => 1 === preg_match($island, $selector)));
                return array() === $arms ? '' : implode(',', $arms) . '{' . $body . '}';
            }
        );
        // Font faces are retained separately; drop the at-rules left empty.
        $kept = (string) preg_replace('/@font-face\s*\{[^{}]*\}/i', '', $kept);
        do {
            $kept = (string) preg_replace('/@[^{};]+\{\s*\}/', '', $kept, -1, $count);
        } while ($count > 0);
        return trim($kept);
    }
}

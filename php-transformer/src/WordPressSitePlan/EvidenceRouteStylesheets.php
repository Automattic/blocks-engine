<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

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
        $legacy = array_values(array_filter($pages, static fn(array $page): bool => !isset($evidence[$page['source_path']])));
        foreach ($assets as $key => &$asset) {
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
        foreach ($author as $index => $key) {
            $islands = self::deviceIslandRules((string) ($assets[$key]['content'] ?? ''));
            $assets[$key] = CompiledAssets::withRewrittenTextContent($assets[$key], implode("\n", array_filter(array_merge(array($kept[$index]), $islands), static fn(string $rule): bool => '' !== trim($rule))));
        }
        return $assets;
    }

    /**
     * The capture's device visibility sheet is not author styling: it is the
     * only thing that hides a route's desktop-only island at phone widths and
     * its mobile-only island at desktop widths. Box-tree evidence describes
     * each island's boxes, not which one the viewport shows, and engine
     * support mirrors these gates only in the editor. Retiring them would
     * render both device copies of every island on the front end.
     *
     * @return list<string> Each island rule, wrapped in its conditional groups.
     */
    private static function deviceIslandRules(string $css): array
    {
        if (!str_contains($css, CaptureEvidence::DESKTOP_ONLY_CLASS) && !str_contains($css, CaptureEvidence::MOBILE_ONLY_CLASS)) return array();
        $island = '/\.(?:' . preg_quote(CaptureEvidence::DESKTOP_ONLY_CLASS, '/') . '|' . preg_quote(CaptureEvidence::MOBILE_ONLY_CLASS, '/') . ')(?![\w-])/';
        $rules = array();
        (new CssStylesheetTransformer())->visitStyleRules($css, static function (string $prelude, string $body, array $ancestors) use ($island, &$rules): void {
            if (1 !== preg_match($island, $prelude)) return;
            $rule = trim($prelude) . '{' . $body . '}';
            foreach (array_reverse($ancestors) as $group) $rule = $group . '{' . $rule . '}';
            $rules[] = $rule;
        });
        return $rules;
    }
}

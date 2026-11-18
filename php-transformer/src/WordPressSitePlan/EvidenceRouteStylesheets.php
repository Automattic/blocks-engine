<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSyntaxScanner;
use Automattic\BlocksEngine\PhpTransformer\Css\UnreachableSelectorPruner;

/**
 * Author selectors are analysis input, never delivery for captured routes.
 *
 * Box-tree evidence makes a route's own content engine-owned. The template
 * parts it renders are not: they are shared with routes that keep the author
 * cascade, and their markup still carries the author hooks that cascade
 * styles. A captured route therefore keeps the author rules that reach its
 * template parts, confined to template-part descendants so route content
 * stays engine-owned, plus the custom-property definitions those rules read.
 */
final class EvidenceRouteStylesheets
{
    /**
     * Classes WordPress adds while rendering a part's blocks (`wp-block-navigation`,
     * `is-layout-flex`, `has-text-color`), which saved part markup never spells.
     */
    private const RENDER_MINTED = '/^(?:wp-|is-|has-)/';

    /** Zero-specificity confinement to shared chrome. */
    private const PART_SCOPE = ':where(.wp-block-template-part *)';

    /** Route content a frame part renders in its post content slot is not chrome. */
    private const SLOT_EXCLUSION = ':not(:where(.wp-block-post-content,.wp-block-post-content *))';

    /**
     * @param array<int,array<string,mixed>> $assets
     * @param array<int,array<string,mixed>> $pages
     * @param array<int,array<string,mixed>> $parts Template parts every route renders.
     * @return array<int,array<string,mixed>>
     */
    public static function retire(array $assets, array $pages, array $parts = array()): array
    {
        $evidence = array();
        foreach ($pages as $page) if (!empty($page['document_metadata']['engine_owned_styles'])) $evidence[$page['source_path']] = $page;
        if (array() === $evidence) return $assets;
        $legacy = array_values(array_filter($pages, static fn(array $page): bool => !isset($evidence[$page['source_path']])));
        $partMarkup = array_values(array_filter(array_map(static fn(array $part): string => (string) ($part['canonical_block_markup'] ?? ''), $parts), static fn(string $markup): bool => '' !== trim($markup)));
        $partNames = self::partNames($partMarkup);
        // Only names the parts carry are evidence: engine names on route content prove nothing here.
        $partScope = self::PART_SCOPE . (array() !== array_filter($partMarkup, static fn(string $markup): bool => 1 === preg_match('/<!--\s*wp:post-content\b/', $markup)) ? self::SLOT_EXCLUSION : '');
        $parts = array() !== $partNames ? new UnreachableSelectorPruner(array(implode("\n", array_keys($partNames))), false, null, self::RENDER_MINTED) : null;
        $retired = array();
        foreach ($assets as $asset) {
            if ('css' !== ($asset['kind'] ?? null) || in_array($asset['source'] ?? '', array('engine-support', 'wordpress-compat'), true)) { $retired[] = $asset; continue; }
            $scopes = $evidenceScopes = array();
            foreach ($asset['scopes'] as $scope) {
                if ('global' === $scope['kind']) {
                    foreach ($legacy as $page) $scopes[] = PageStylesheets::documentScope($page);
                    foreach ($evidence as $page) $evidenceScopes[] = PageStylesheets::documentScope($page);
                } elseif (!isset($evidence[$scope['source_path'] ?? ''])) $scopes[] = $scope;
                else $evidenceScopes[] = $scope;
            }
            $chrome = null !== $parts && array() !== $evidenceScopes && is_string($asset['content'] ?? null) ? self::chromeRules($asset['content'], $parts, $partScope) : '';
            $source = $asset;
            $asset['stylesheet_instances'] = array_values(array_filter($asset['stylesheet_instances'] ?? array(), static fn(array $instance): bool => !isset($evidence[$instance['source_path']])));
            unset($asset['frame_fallback'], $asset['stylesheet_replays']);
            if (array() !== $scopes) {
                $asset['scopes'] = $scopes;
            } else {
                // Preserve resource identities referenced by document metadata. Font
                // faces have already been projected into theme.json at this point.
                preg_match_all('/@font-face\s*\{[^{}]*\}/i', (string) ($asset['content'] ?? ''), $faces);
                $asset = CompiledAssets::withRewrittenTextContent($asset, implode("\n", $faces[0]));
            }
            $retired[] = $asset;
            if ('' !== $chrome) $retired[] = self::chromeAsset($source, $chrome, $evidenceScopes);
        }
        return $retired;
    }

    /**
     * Class and id names the template-part markup carries, from rendered
     * attributes and from block comments (a dynamic block keeps its classes
     * only there).
     *
     * @param list<string> $markups
     * @return array<string,true>
     */
    private static function partNames(array $markups): array
    {
        $names = array();
        foreach ($markups as $markup) {
            preg_match_all('/\s(?:class|id)="([^"]*)"/', $markup, $attributes);
            foreach ($attributes[1] as $value) foreach (preg_split('/\s+/', html_entity_decode($value, ENT_QUOTES | ENT_HTML5)) ?: array() as $name) if ('' !== $name) $names[$name] = true;
            preg_match_all('/<!--\s*wp:[a-z0-9\/-]+\s+(\{.*?\})\s*\/?-->/s', $markup, $comments);
            foreach ($comments[1] as $json) {
                $attrs = json_decode($json, true);
                if (!is_array($attrs)) continue;
                foreach (array('className', 'anchor') as $key) if (is_string($attrs[$key] ?? null)) foreach (preg_split('/\s+/', $attrs[$key]) ?: array() as $name) if ('' !== $name) $names[$name] = true;
            }
        }
        return $names;
    }

    /**
     * Rules of $css with a selector arm that names a class or id and can
     * match template-part markup, each such arm confined to template-part
     * descendants, plus the hookless custom-property definitions
     * (`:root{--text-lg:…}`) those rules resolve. Other rules go.
     */
    private static function chromeRules(string $css, UnreachableSelectorPruner $parts, string $scope): string
    {
        $matched = false;
        $chrome = (new CssStylesheetTransformer())->transformStyleRules($css, static function (string $prelude, string $body) use ($parts, $scope, &$matched): string {
            $arms = CssStylesheetTransformer::splitSelectorList($prelude);
            if (null === $arms) return '';
            $declarations = CssRuleAnalyzer::declarationList($body);
            $definitions = array() !== $declarations && array() === array_filter($declarations, static fn(array $declaration): bool => !str_starts_with($declaration['name'], '--'));
            $kept = array();
            foreach ($arms as $arm) {
                $arm = trim((string) preg_replace('#/\*.*?\*/#s', ' ', $arm));
                if ('' === $arm || str_contains($arm, '&')) continue;
                if (!self::hooked($arm)) {
                    // A token definition with no hook reaches chrome by inheritance.
                    if ($definitions) $kept[] = $arm;
                    continue;
                }
                if (!$parts->reachable($arm)) continue;
                $kept[] = self::confined($arm, $scope);
                $matched = true;
            }
            return array() === $kept ? '' : implode(',', $kept) . '{' . $body . '}';
        });
        if (!$matched) return '';
        do {
            $chrome = (string) preg_replace('/@(?:-webkit-)?(?:media|supports|container|layer|document)\b[^{};]*\{\s*\}/i', '', $chrome, -1, $count);
        } while (0 < $count);
        return trim($chrome);
    }

    /** Whether $arm names a class or id outside strings, attribute selectors and negations. */
    private static function hooked(string $arm): bool
    {
        $arm = (string) preg_replace('/"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|\[[^\]]*\]/', '', $arm);
        while (preg_match('/:not\(/i', $arm, $negation, PREG_OFFSET_CAPTURE)) {
            $close = CssSyntaxScanner::matchingClose($arm, $negation[0][1] + 4);
            if (null === $close) return false;
            $arm = substr($arm, 0, $negation[0][1]) . substr($arm, $close + 1);
        }
        return 1 === preg_match('/[.#](?:[A-Za-z_-]|\\\\)/', $arm);
    }

    /** $arm with its subject (before any pseudo-element) confined to template-part descendants. */
    private static function confined(string $arm, string $scope): string
    {
        $pseudo = preg_match('/(::?(?:before|after|first-line|first-letter)|::[\w-]+(?:\([^()]*\))?)$/i', $arm, $match) ? $match[1] : '';
        return substr($arm, 0, strlen($arm) - strlen($pseudo)) . $scope . $pseudo;
    }

    /**
     * @param array<string,mixed> $asset
     * @param list<array<string,mixed>> $scopes
     * @return array<string,mixed>
     */
    private static function chromeAsset(array $asset, string $css, array $scopes): array
    {
        $chrome = CompiledAssets::withRewrittenTextContent($asset, $css);
        $chrome['path'] = $chrome['target_path'] = 'assets/css/evidence-route-chrome-' . substr(hash('sha256', (string) ($asset['target_path'] ?? '') . "\0" . $css), 0, 16) . '.css';
        $chrome['source_path'] = (string) ($asset['source_path'] ?? $asset['path'] ?? '') . '.evidence-route-chrome';
        // Relative url() references still resolve against the authored sheet.
        $chrome['reference_origin'] = (string) ($asset['reference_origin'] ?? $asset['source_path'] ?? $asset['path'] ?? '');
        $chrome['scopes'] = $scopes;
        $chrome['token'] = 'asset-' . substr(hash('sha256', $chrome['target_path']), 0, 16);
        $chrome['reconciliation_identity'] = WordPressSitePlan::identity('asset', $chrome['source_path'], $chrome['target_path']);
        unset($chrome['inline_references'], $chrome['stylesheet_instances'], $chrome['stylesheet_parent'], $chrome['stylesheet_inline_order'], $chrome['stylesheet_parts'], $chrome['frame_fallback'], $chrome['stylesheet_replays']);
        return $chrome;
    }
}

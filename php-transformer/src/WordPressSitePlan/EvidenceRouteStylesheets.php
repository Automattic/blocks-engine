<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorTokenizer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;

/** Author selectors are analysis input, never delivery for captured routes. */
final class EvidenceRouteStylesheets
{
    /**
     * Template part content on an evidence route: inside a part, outside the
     * route content a frame part wraps. Zero weight keeps authored specificity.
     */
    private const CHROME_SUBJECT = ':where(.wp-block-template-part *):not(:where(.wp-block-template-part .wp-block-post-content *))';

    /**
     * @param list<array<string, mixed>> $parts Template parts every route renders; their markup counts as emitted.
     */
    public static function retire(array $assets, array $pages, array $parts = array()): array
    {
        $evidence = array();
        foreach ($pages as $page) if (!empty($page['document_metadata']['engine_owned_styles'])) $evidence[$page['source_path']] = $page;
        if (array() === $evidence) return $assets;
        // A shared part's markup is one capture's chrome, rendered unchanged on
        // every route. Chosen from a legacy route, it carries no evidence
        // carriers, only the authored classes the author sheet styles (a
        // `hidden md:flex` desktop nav, an `md:hidden` menu button). Retiring
        // that sheet on evidence routes would leave the same part unstyled
        // there, so the rules reaching the parts stay, confined to the parts.
        $slices = array();
        $hooks = self::chromeHooks($parts);
        if (array() !== $hooks) foreach ($assets as $key => $asset) {
            if ('css' !== ($asset['kind'] ?? null) || in_array($asset['source'] ?? '', array('engine-support', 'wordpress-compat', 'editor-static-state'), true) || EngineMarker::ENGINE_OWNED_STYLESHEET_ROLE === ($asset['source_role'] ?? null) || 'editor' === ($asset['stylesheet_target'] ?? 'both')) continue;
            $slice = self::chromeSlice($asset, $evidence, $hooks);
            if (null !== $slice) $slices[$key] = $slice;
        }
        $assets = self::retireAuthorSheets($assets, $pages, $parts, $evidence);
        if (array() === $slices) return $assets;
        $result = array();
        foreach ($assets as $key => $asset) {
            $result[] = $asset;
            if (isset($slices[$key])) $result[] = $slices[$key];
        }
        return $result;
    }

    /** @param array<string, array<string, mixed>> $evidence Evidence pages by source path. */
    private static function retireAuthorSheets(array $assets, array $pages, array $parts, array $evidence): array
    {
        $markup = (string) json_encode(array_values(array_map(static fn(array $page): array => array($page['canonical_block_markup'] ?? null, $page['resolved_block_markup'] ?? null), array_filter($pages, static fn(array $page): bool => isset($evidence[$page['source_path']])))), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $retired = array();
        $legacy = array_values(array_filter($pages, static fn(array $page): bool => !isset($evidence[$page['source_path']])));
        foreach ($assets as $key => &$asset) {
            // Engine-owned generated sheets (editor repairs naming only engine
            // markup) are not author selectors: retiring them hides the editor's
            // own repairs, such as the selectable box of a hidden-at-rest image.
            if ('css' !== ($asset['kind'] ?? null) || in_array($asset['source'] ?? '', array('engine-support', 'wordpress-compat'), true) || EngineMarker::ENGINE_OWNED_STYLESHEET_ROLE === ($asset['source_role'] ?? null)) continue;
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

    /**
     * Authored classes and ids the parts carry, from rendered HTML and block
     * attributes (a dynamic block keeps its classes only in its comment).
     *
     * @param list<array<string, mixed>> $parts
     * @return array{class:array<string,true>,id:array<string,true>}|array{}
     */
    private static function chromeHooks(array $parts): array
    {
        $hooks = array('class' => array(), 'id' => array());
        foreach ($parts as $part) {
            $markup = (string) ($part['canonical_block_markup'] ?? '');
            preg_match_all('/\sclass="([^"]*)"/', $markup, $html);
            preg_match_all('/"className":"((?:[^"\\\\]|\\\\.)*)"/', $markup, $json);
            foreach (array_merge(array_map(static fn(string $value): string => html_entity_decode($value, ENT_QUOTES | ENT_HTML5), $html[1]), array_map(static fn(string $value): string => (string) json_decode('"' . $value . '"'), $json[1])) as $value) {
                foreach (preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: array() as $class) $hooks['class'][$class] = true;
            }
            preg_match_all('/\sid="([^"]+)"|"anchor":"([^"\\\\]+)"/', $markup, $ids);
            foreach (array_merge($ids[1], $ids[2]) as $id) if ('' !== $id) $hooks['id'][html_entity_decode($id, ENT_QUOTES | ENT_HTML5)] = true;
        }
        return array() === $hooks['class'] && array() === $hooks['id'] ? array() : $hooks;
    }

    /**
     * The rules of $asset whose subject names a part hook, each subject
     * confined to part content, delivered on the evidence routes the asset
     * styled before retirement. Null when no rule reaches the parts.
     *
     * @param array<string, array<string, mixed>> $evidence
     * @param array{class:array<string,true>,id:array<string,true>} $hooks
     */
    private static function chromeSlice(array $asset, array $evidence, array $hooks): ?array
    {
        $instances = array_values(array_filter($asset['stylesheet_instances'] ?? array(), static fn(array $instance): bool => isset($evidence[$instance['source_path'] ?? ''])));
        $routes = array();
        foreach ($instances as $instance) $routes[$instance['source_path']] = true;
        if (array() === $instances) foreach ($asset['scopes'] ?? array() as $scope) {
            if ('global' === ($scope['kind'] ?? null)) $routes += array_fill_keys(array_keys($evidence), true);
            elseif (isset($evidence[$scope['source_path'] ?? ''])) $routes[$scope['source_path']] = true;
        }
        if (array() === $routes || !is_string($asset['content'] ?? null) || '' === trim($asset['content'])) return null;
        $matched = false;
        $css = (new CssStylesheetTransformer())->transformStyleRules($asset['content'], static function (string $prelude, string $body) use ($hooks, &$matched): string {
            $arms = CssStylesheetTransformer::splitSelectorList($prelude);
            if (null === $arms) return '';
            $kept = array();
            foreach ($arms as $arm) {
                $arm = trim($arm);
                $tokens = CssSelectorTokenizer::tokenize($arm);
                if (!$tokens['supported'] || array() === $tokens['compounds']) continue;
                $last = count($tokens['compounds']) - 1;
                $compound = $tokens['compounds'][$last];
                $pseudo = CssStylesheetTransformer::pseudoElementOffset($compound);
                if (!self::namesHook(null === $pseudo ? $compound : substr($compound, 0, $pseudo), $hooks)) continue;
                $span = $tokens['compound_spans'][$last];
                $insert = null === $pseudo ? $span['end'] : $span['start'] + $pseudo;
                $kept[] = substr($arm, 0, $insert) . self::CHROME_SUBJECT . substr($arm, $insert);
            }
            if (array() === $kept) return '';
            $matched = true;
            return implode(',', $kept) . '{' . $body . '}';
        });
        if (!$matched) return null;
        $source = (string) ($asset['source_path'] ?? $asset['path'] ?? '');
        $slice = CompiledAssets::withRewrittenTextContent($asset, $css);
        $slice['path'] = $slice['target_path'] = 'assets/css/evidence-chrome-' . substr(hash('sha256', $source . "\0" . $css), 0, 16) . '.css';
        $slice['source_path'] = $source . '.evidence-chrome';
        // Relative url() references still resolve against the authored sheet.
        $slice['reference_origin'] = (string) ($asset['reference_origin'] ?? $source);
        $slice['token'] = 'asset-' . substr(hash('sha256', $slice['target_path']), 0, 16);
        $slice['reconciliation_identity'] = WordPressSitePlan::identity('asset', $slice['source_path'], $slice['target_path']);
        $slice['scopes'] = array_values(array_map(static fn(string $route): array => PageStylesheets::documentScope($evidence[$route]), array_keys($routes)));
        // The slice takes the authored sheet's place in each evidence route's cascade.
        $slice['stylesheet_instances'] = $instances;
        unset($slice['frame_fallback'], $slice['stylesheet_replays'], $slice['inline_references'], $slice['stylesheet_parent'], $slice['stylesheet_inline_order'], $slice['_stylesheet_projection_origins']);
        if (array() === $instances) unset($slice['stylesheet_instances']);
        return $slice;
    }

    /**
     * Whether a subject compound names a part hook, directly or through a
     * matches-any argument (`:is(.hidden)`, as an unlayered revert-layer shim
     * writes it), but never through a negation.
     */
    private static function namesHook(string $compound, array $hooks): bool
    {
        $top = '';
        $negations = array();
        for ($i = 0, $length = strlen($compound); $i < $length; ++$i) {
            $char = $compound[$i];
            $negated = in_array(true, $negations, true);
            if ('\\' === $char) {
                if (!$negated) $top .= substr($compound, $i, 2);
                ++$i;
            } elseif ('(' === $char) {
                $negations[] = 1 === preg_match('/:not$/i', substr($compound, 0, $i));
                $top .= ' ';
            } elseif (')' === $char) {
                array_pop($negations);
                $top .= ' ';
            } elseif (!$negated) {
                $top .= $char;
            }
        }
        if (!preg_match_all('/([.#])((?:\\\\.|[\w-])+)/', $top, $names, PREG_SET_ORDER)) return false;
        foreach ($names as [, $sigil, $name]) {
            $name = (string) preg_replace('/\\\\(.)/s', '$1', $name);
            if (isset($hooks['.' === $sigil ? 'class' : 'id'][$name])) return true;
        }
        return false;
    }
}

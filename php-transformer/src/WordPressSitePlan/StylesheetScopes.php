<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use InvalidArgumentException;

/**
 * Where each compiled stylesheet is delivered, and how generated support
 * sheets are partitioned and folded across the documents that consume them.
 *
 * A stylesheet's scopes come from its compilation owner, its inline or link
 * references, or the whole site when every canonical document links it.
 * Generated support rules are then split by their exact consumers and folded
 * back together where a rule provably matches nothing elsewhere.
 */
final class StylesheetScopes
{
    /**
     * A page-scoped stylesheet compiled from a captured surface is shared:
     * the surface renders on more than one route.
     *
     * @param array<int,array<string,mixed>> $assets
     * @param array<int,array<string,mixed>> $surfaces
     * @return array<int,array<string,mixed>>
     */
    public static function withSharedSurfaces(array $assets, array $surfaces): array
    {
        $surfaceSources = array_fill_keys(array_column($surfaces, 'source_path'), true);
        foreach ($assets as &$asset) {
            if ('page' === ($asset['compilation']['scope'] ?? null) && isset($surfaceSources[$asset['compilation']['id'] ?? ''])) $asset['compilation'] = array('scope' => 'shared');
        }
        unset($asset);
        return $assets;
    }

    /**
     * The documents a stylesheet scope can name, by source path: canonical
     * pages, then root documents that are not pages.
     *
     * @param array<int,array<string,mixed>> $pages @param array<int,array<string,mixed>> $rootDocuments @return array<string,array<string,mixed>>
     */
    public static function documentsBySource(array $pages, array $rootDocuments): array
    {
        return array_column($pages, null, 'source_path') + array_column($rootDocuments, null, 'source_path');
    }

    /** A stylesheet's scopes: a nonempty list of global, page, post or archive scopes, each with exactly its kind's fields. */
    public static function assertValid(mixed $scopes): void
    {
        if (!is_array($scopes) || array() === $scopes) throw new InvalidArgumentException('WordPress site plan asset scopes must be a nonempty array.');
        foreach ($scopes as $scope) {
            if (!is_array($scope) || !in_array($scope['kind'] ?? null, array('global', 'page', 'post', 'archive'), true)) throw new InvalidArgumentException('WordPress site plan asset scope is invalid.');
            if ('global' === $scope['kind']) {
                if (array('kind') !== array_keys($scope)) throw new InvalidArgumentException('A global asset scope cannot declare page fields.');
                continue;
            }
            if ('archive' === $scope['kind']) {
                if (array('kind', 'source_path', 'route_path') !== array_keys($scope) || !WordPressSitePlan::safePath($scope['source_path']) || !is_string($scope['route_path'])) throw new InvalidArgumentException('An archive asset scope is structurally invalid.');
                continue;
            }
            if (array('kind', 'source_path', 'route_path', 'reconciliation_identity', 'front_page') !== array_keys($scope) || !WordPressSitePlan::safePath($scope['source_path']) || !is_string($scope['route_path']) || !WordPressSitePlan::hash($scope['reconciliation_identity']) || !is_bool($scope['front_page'])) throw new InvalidArgumentException('A page asset scope is structurally invalid.');
        }
    }

    /**
     * Every non-global scope names a canonical document and equals that
     * document's scope, so a stylesheet cannot be delivered to a route it was
     * not compiled for.
     *
     * @param array<int,array<string,mixed>> $assets @param array<string,array<string,mixed>> $documentsBySource
     */
    public static function assertOwned(array $assets, array $documentsBySource): void
    {
        foreach ($assets as $asset) foreach ($asset['scopes'] ?? array() as $scope) if ('global' !== $scope['kind']) {
            $document = $documentsBySource[$scope['source_path']] ?? null;
            if (!is_array($document) || $scope != PageStylesheets::documentScope($document)) throw new InvalidArgumentException('An asset scope does not match its canonical document.');
        }
    }

    /** @param array<int,array<string,mixed>> $assets @param array<string,array<string,mixed>> $documentsBySource @return array<int,array<string,mixed>> */
    public static function assign(array $assets, array $documentsBySource): array
    {
        $stylesheetSets = array();
        foreach ($assets as $asset) {
            $state = $asset['stylesheet_activation'] ?? null;
            if (is_array($state) && ('' !== $state['title'] || !$state['active'])) $stylesheetSets[$asset['stylesheet_source_path']] = true;
        }
        foreach ($assets as &$asset) {
            $compilation = $asset['compilation'] ?? null;
            $stylesheetSource = $asset['stylesheet_source_path'] ?? null;
            unset($asset['compilation'], $asset['stylesheet_link_position'], $asset['stylesheet_source_path']);
            if ('css' !== $asset['kind']) continue;
            // A source payload can be preferred here and active or disabled on
            // another route. Its link occurrences, rather than shared chrome
            // ownership, determine where each activation variant is delivered.
            if (isset($asset['stylesheet_activation'], $stylesheetSets[$stylesheetSource ?? '']) && 'page' !== ($compilation['scope'] ?? null)) $compilation = null;
            // Explicit page ownership wins over inherited occurrence records.
            if (isset($asset['inline_references']) && 'page' !== ($compilation['scope'] ?? null)) {
                $referencedPages = array();
                foreach ($asset['inline_references'] as $reference) if (isset($documentsBySource[$reference['source_path']])) $referencedPages[$reference['source_path']] = $documentsBySource[$reference['source_path']];
                $asset['scopes'] = array_values(array_map(static fn(array $page): array => PageStylesheets::documentScope($page), $referencedPages));
                if (array() === $asset['scopes']) $asset['scopes'] = array(array('kind' => 'global'));
                continue;
            }
            if ('shared' === ($compilation['scope'] ?? null)) {
                $sources = $compilation['source_paths'] ?? null;
                $consumers = is_array($sources) ? array_intersect_key($documentsBySource, array_fill_keys($sources, true)) : array();
                // A deduplicated document payload is shared only by its
                // consumers, not by every route. Truly site-wide support and
                // isolated shared-shell compilations keep their global scope.
                $canonicalPages = array_filter($documentsBySource, static fn(array $page): bool => empty($page['synthetic']));
                $asset['scopes'] = null === $sources || count($consumers) === count($canonicalPages)
                    ? array(array('kind' => 'global'))
                    : array_values(array_map(PageStylesheets::documentScope(...), $consumers));
                if (array() === $asset['scopes']) $asset['scopes'] = array(array('kind' => 'global'));
                continue;
            }
            if (null === $compilation) {
                $referencedPages = array();
                foreach (ArrayField::array($asset, 'references') as $reference) {
                    $sourcePath = is_array($reference) && 'link' === ($reference['element'] ?? null) ? ($reference['source_path'] ?? null) : null;
                    if (is_string($sourcePath) && is_array($documentsBySource[$sourcePath] ?? null)) $referencedPages[$sourcePath] = $documentsBySource[$sourcePath];
                }
                if (array() === $referencedPages) {
                    $asset['scopes'] = array(array('kind' => 'global'));
                    continue;
                }
                // A stylesheet every canonical source document links is
                // site-wide author CSS: WordPress-native views (404, search,
                // archives) render the shared chrome it dresses, so they load
                // it through the route-flag fallback in source order, ahead of
                // the shared-chrome projections lifted from it. Route
                // documents keep their per-occurrence instance enqueues, and a
                // stylesheet only some documents link stays route-only.
                // Generated synthetic route pages capture nothing, so they do
                // not hold a site-wide stylesheet back.
                if (count($referencedPages) === count(array_filter($documentsBySource, static fn(array $page): bool => empty($page['synthetic'])))) {
                    $asset['scopes'] = array(array('kind' => 'global'));
                    continue;
                }
                $asset['scopes'] = array_values(array_map(static fn(array $page): array => PageStylesheets::documentScope($page), $referencedPages));
                continue;
            }
            if (!is_array($compilation) || 'page' !== ($compilation['scope'] ?? null) || !is_string($compilation['id'] ?? null) || !is_array($documentsBySource[$compilation['id']] ?? null)) {
                throw new InvalidArgumentException('A document-owned compiled asset must resolve to a canonical document.');
            }
            $page = $documentsBySource[$compilation['id']];
            $asset['scopes'] = array(PageStylesheets::documentScope($page));
        }
        unset($asset);
        return self::allocateSupport($assets, self::canonicalDocuments($documentsBySource));
    }

    /**
     * @param array<string,array<string,mixed>> $documentsBySource
     * @return list<string>
     */
    public static function canonicalDocuments(array $documentsBySource): array
    {
        return array_keys(array_filter($documentsBySource, static fn(array $page): bool => empty($page['synthetic'])));
    }

    /**
     * Fold each run of adjacent allocated support sheets with one delivery
     * into a single resource, in their existing rule order. A rule reaches
     * routes outside its owners only where it provably matches nothing in
     * their documents; otherwise a zero-weight route gate keeps it on its
     * owners. Rules that can be neither proven nor gated keep their sheet.
     *
     * Each document is proven with every template part's markup, since any
     * of them may render on it.
     *
     * @param array<int,array<string,mixed>> $assets
     * @param array<string,array<string,mixed>> $documentsBySource
     * @param list<string> $canonicalSources
     * @return array<int,array<string,mixed>>
     */
    public static function foldSupport(array $assets, array $documentsBySource, array $canonicalSources, string $sharedMarkup = ''): array
    {
        $routeClasses = array();
        foreach ($canonicalSources as $source) {
            $class = (string) ($documentsBySource[$source]['document_metadata']['root_attributes']['class'] ?? '');
            if (preg_match('/(?:^|\s)(be-r-[0-9a-f]+)(?:\s|$)/', $class, $match)) $routeClasses[$source] = $match[1];
        }
        $index = null;
        $transformer = new \Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer();
        // The rules a member contributes to a fold, or null when one of them
        // can be neither proven inert outside its owners nor gated to them.
        $foldable = static function (array $asset) use (&$index, $documentsBySource, $canonicalSources, $routeClasses, $transformer, $sharedMarkup): ?string {
            $owners = array_column($asset['scopes'], 'source_path');
            if (in_array('global', array_column($asset['scopes'], 'kind'), true)) return (string) $asset['content'];
            $outside = array_fill_keys(array_diff($canonicalSources, $owners), true);
            $gate = array();
            foreach ($owners as $owner) {
                if (!isset($routeClasses[$owner])) return null;
                $gate[] = '.' . $routeClasses[$owner];
            }
            sort($gate);
            $scope = ':where(:root:is(' . implode(',', $gate) . '))';
            if (null === $index) {
                $documents = array();
                foreach ($canonicalSources as $source) {
                    // A document without emitted markup (a WordPress-rendered
                    // archive) is never evidence that a rule is inert there.
                    if (!is_string($documentsBySource[$source]['canonical_block_markup'] ?? null) || '' === trim($documentsBySource[$source]['canonical_block_markup'])) continue;
                    $dom = HtmlSerialization::parseDocument('<html><body>' . $documentsBySource[$source]['canonical_block_markup'] . "\n" . $sharedMarkup . '</body></html>');
                    foreach (array('html' => 'root_attributes', 'body' => 'body_attributes') as $tag => $key) {
                        $element = $dom->getElementsByTagName($tag)->item(0);
                        foreach ($documentsBySource[$source]['document_metadata'][$key] ?? array() as $name => $value) $element?->setAttribute((string) $name, (string) $value);
                    }
                    $documents[$source] = $dom;
                }
                $index = new \Automattic\BlocksEngine\PhpTransformer\Css\RouteSelectorMatchIndex($documents);
            }
            $failed = false;
            $content = $transformer->transformStyleRules((string) $asset['content'], static function (string $prelude, string $body) use ($index, $outside, $scope, &$failed): string {
                $arms = \Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer::splitSelectorList($prelude);
                if (null === $arms) { $failed = true; return ''; }
                $arms = array_map('trim', $arms);
                if (array() === array_filter($arms, static fn(string $arm): bool => !$index->inertOutside($arm, $outside))) return $prelude . '{' . $body . '}';
                $scoped = \Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer::routeScopedArms($arms, $scope);
                if (null === $scoped) { $failed = true; return ''; }
                return implode(',', $scoped) . '{' . $body . '}';
            });
            return $failed ? null : $content;
        };
        // A member is still its own support sheet (sheets projected from
        // support, such as shared chrome, carry their own target) and has no
        // url() or statement whose meaning its file position decides.
        $delivery = static fn(array $asset): ?string => 'engine-support' === ($asset['source'] ?? '') && 'css' === ($asset['kind'] ?? null) && str_starts_with(basename((string) ($asset['target_path'] ?? '')), 'engine-support-')
            && is_string($asset['content'] ?? null) && 1 !== preg_match('/url\(|@import|@charset|@namespace/i', $asset['content'])
            ? serialize(array($asset['stylesheet_placement'] ?? '', $asset['stylesheet_target'] ?? 'both', $asset['media'] ?? '')) : null;
        // A fold takes the position of its first member, so other assets keep
        // their order.
        $result = $run = array();
        $slot = 0;
        $flush = static function () use (&$run, &$result, &$slot, $canonicalSources): void {
            if (count($run) < 2) {
                if (array() !== $run) $result[$slot] = $run[0]['asset'];
                $run = array();
                return;
            }
            $asset = $run[0]['asset'];
            $scopes = array();
            foreach ($run as $member) foreach ($member['asset']['scopes'] as $scope) $scopes['global' === $scope['kind'] ? '' : $scope['source_path']] = $scope;
            $sources = array_diff(array_keys($scopes), array(''));
            // Members allocated under one layer order each restate it; the
            // fold states each leading order once, ahead of its first use.
            $stated = array();
            $contents = array_map(static function (array $member) use (&$stated): string {
                return (string) preg_replace_callback('/^(?:\s*@layer\s+[\w-]+(?:\s*,\s*[\w-]+)*\s*;)+/', static function (array $match) use (&$stated): string {
                    preg_match_all('/@layer\s+[\w-]+(?:\s*,\s*[\w-]+)*\s*;/', $match[0], $orders);
                    $kept = '';
                    foreach ($orders[0] as $order) if (!isset($stated[$order])) { $stated[$order] = true; $kept .= $order; }
                    return $kept;
                }, $member['content']);
            }, $run);
            $asset = CompiledAssets::withRewrittenTextContent($asset, implode("\n", $contents));
            $path = 'assets/css/engine-support-' . ($asset['stylesheet_placement'] ?? 'after-author') . '-' . substr(hash('sha256', serialize(array_keys($scopes)) . $asset['content']), 0, 16) . '.css';
            $asset['source_path'] = $path;
            $asset['target_path'] = 'assets/' . $path;
            $asset['token'] = 'asset-' . substr(hash('sha256', $asset['target_path']), 0, 16);
            $asset['reconciliation_identity'] = WordPressSitePlan::identity('asset', $path, $asset['target_path']);
            ksort($scopes);
            $asset['scopes'] = isset($scopes['']) || (count($sources) === count($canonicalSources) && array() === array_diff($canonicalSources, $sources))
                ? array(array('kind' => 'global')) : array_values($scopes);
            $result[$slot] = $asset;
            $run = array();
        };
        foreach ($assets as $asset) {
            $key = $delivery($asset);
            if ('css' !== ($asset['kind'] ?? null)) { $result[] = $asset; continue; }
            $content = null === $key ? null : $foldable($asset);
            if (null === $content || (array() !== $run && $run[0]['key'] !== $key)) $flush();
            if (null === $content) { $result[] = $asset; continue; }
            if (array() === $run) $result[$slot = count($result)] = null;
            $run[] = array('key' => $key, 'asset' => $asset, 'content' => $content);
        }
        $flush();
        return array_values($result);
    }

    /**
     * Partition generated rules by their exact consumers and delivery side.
     * Opaque statements and sheets with location-dependent URLs retain their
     * original resource. Self-contained data URLs can move with their rules.
     *
     * @param array<int,array<string,mixed>> $assets
     * @param list<string> $canonicalSources
     * @return array<int,array<string,mixed>>
     */
    private static function allocateSupport(array $assets, array $canonicalSources): array
    {
        $rules = $eligible = array();
        foreach ($assets as $index => $asset) {
            if ('engine-support' !== ($asset['source'] ?? '') || 'css' !== ($asset['kind'] ?? '') || in_array('global', array_column($asset['scopes'], 'kind'), true)) continue;
            $content = (string) ($asset['content'] ?? '');
            // Data URLs do not depend on the resource's location. Keep the
            // conservative URL guard for every other reference.
            $withoutDataUrls = preg_replace('/url\(\s*(?:"data:[^"]*"|\'data:[^\']*\'|data:[^\s)"\']*)\s*\)/i', '', $content) ?? $content;
            if (false !== stripos($withoutDataUrls, 'url(')) continue;
            $tree = new \Automattic\BlocksEngine\PhpTransformer\Css\CssRuleTree($content);
            if (!$tree->wellFormed) continue;
            $parts = $layerOrder = array();
            foreach ($tree->rules() as $rule) {
                $text = trim(substr($content, $rule['start'], $rule['end'] - $rule['start'] + 1));
                if (';' === $content[$rule['boundary']]) {
                    // A leading layer-order statement heads the sheet: it
                    // travels with every rule allocated from it, and sheets
                    // only share rules under the same order.
                    if (array() === $parts && 1 === preg_match('/^@layer\s+[\w-]+(?:\s*,\s*[\w-]+)*\s*;$/', $text)) { $layerOrder[] = $text; continue; }
                    $parts = array();
                    break;
                }
                $parts[] = $text;
            }
            if (array() === $parts) continue;
            $eligible[$index] = true;
            $delivery = serialize(array($asset['stylesheet_placement'] ?? '', $asset['stylesheet_target'] ?? 'both', $asset['media'] ?? '', $layerOrder));
            foreach ($parts as $text) {
                $key = $delivery . "\0" . $text;
                if (!isset($rules[$key])) $rules[$key] = array('text' => $text, 'asset' => $asset, 'index' => $index, 'scopes' => array(), 'layer_order' => $layerOrder);
                foreach ($asset['scopes'] as $scope) $rules[$key]['scopes'][$scope['source_path']] = $scope;
            }
        }
        $groups = array();
        foreach ($rules as $rule) {
            ksort($rule['scopes']);
            $asset = $rule['asset'];
            $key = serialize(array($asset['stylesheet_placement'] ?? '', $asset['stylesheet_target'] ?? 'both', $asset['media'] ?? '', $rule['layer_order'], $rule['scopes']));
            if (!isset($groups[$key])) $groups[$key] = array('asset' => $asset, 'index' => $rule['index'], 'scopes' => array_values($rule['scopes']), 'rules' => $rule['layer_order']);
            $groups[$key]['rules'][] = $rule['text'];
        }
        // Each allocated sheet takes the slot of the first sheet it draws
        // rules from, so the inventory keeps its cascade order.
        $allocated = array();
        foreach ($groups as $key => $group) {
            $asset = CompiledAssets::withRewrittenTextContent($group['asset'], implode("\n", $group['rules']));
            $path = 'assets/css/engine-support-' . ($asset['stylesheet_placement'] ?? 'after-author') . '-' . substr(hash('sha256', $key . $asset['content']), 0, 16) . '.css';
            $asset['source_path'] = $path;
            $asset['target_path'] = 'assets/' . $path;
            $asset['token'] = 'asset-' . substr(hash('sha256', $asset['target_path']), 0, 16);
            $asset['reconciliation_identity'] = WordPressSitePlan::identity('asset', $path, $asset['target_path']);
            $sources = array_column($group['scopes'], 'source_path');
            $asset['scopes'] = count($sources) === count($canonicalSources) && array() === array_diff($canonicalSources, $sources)
                ? array(array('kind' => 'global')) : $group['scopes'];
            $allocated[$group['index']][] = $asset;
        }
        $result = array();
        foreach ($assets as $index => $asset) {
            if (!isset($eligible[$index])) $result[] = $asset;
            foreach ($allocated[$index] ?? array() as $sheet) $result[] = $sheet;
        }
        return $result;
    }
}

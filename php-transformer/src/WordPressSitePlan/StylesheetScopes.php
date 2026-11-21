<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleTree;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSyntaxScanner;
use Automattic\BlocksEngine\PhpTransformer\Css\RouteSelectorMatchIndex;
use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use InvalidArgumentException;

/**
 * Where each compiled stylesheet is delivered, and how generated support
 * sheets are partitioned and folded across the documents that consume them.
 *
 * A stylesheet's scopes come from its compilation owner, its inline or link
 * references, or the whole site when every canonical document links it.
 * Generated support rules are then split by their exact consumers and bundled
 * per route: rules every route loads stay global, and each route loads one
 * bundle of the rest, so no route loads another route's support.
 */
final class StylesheetScopes
{
    /**
     * Bytes above which a support sheet shared by a subset of routes keeps its
     * own resource instead of being copied into each owner route's bundle:
     * past this size the duplicated bytes outweigh the request a bundle saves.
     */
    public const SUBSET_SHEET_THRESHOLD = 8192;

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
     * Bundle each run of adjacent support sheets with one delivery (placement,
     * target and media; layer order travels in the content) per route, so a
     * route loads at most one route-owned support resource per run instead of
     * one per owner subset it belongs to (#7279).
     *
     * Within a run, global members fold into the shared global resource, which
     * every route caches. A member scoped to a subset of routes is copied into
     * each owner route's bundle, in its original order, so every route loads
     * exactly the rules it loaded before and never another route's support,
     * however inert it would be there (#6458). A subset member larger than
     * SUBSET_SHEET_THRESHOLD bytes is not copied: duplicating it would cost
     * more than the request it saves, so it keeps one resource for its owner
     * set. Bundles that end up byte-identical (two routes with the same
     * support) are delivered as one resource scoped to both routes.
     *
     * Each bundle takes the position of its first member, so a member that
     * joins a bundle moves ahead of every later stylesheet delivered in the
     * same before-author or after-author sequence. It joins only when, on
     * every route that loads both, each such stylesheet states the same
     * leading layer order and sets no common property family on one element
     * with it; otherwise its bundle's next resource starts at the member.
     * Stylesheets delivered through document instances or replays, or whose
     * bytes are unknown, are crossed only where they never load in between.
     * Byte-identical bundles merge under the same proof. Sheets whose meaning
     * depends on their file position (a url(), an import) keep their own
     * resource.
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
        $index = null;
        $matchIndex = static function () use (&$index, $documentsBySource, $canonicalSources, $sharedMarkup): RouteSelectorMatchIndex {
            if (null !== $index) return $index;
            $documents = array();
            foreach ($canonicalSources as $source) {
                // A document without emitted markup (a WordPress-rendered
                // archive) is never evidence about its cascade.
                if (!is_string($documentsBySource[$source]['canonical_block_markup'] ?? null) || '' === trim($documentsBySource[$source]['canonical_block_markup'])) continue;
                $dom = HtmlSerialization::parseDocument('<html><body>' . $documentsBySource[$source]['canonical_block_markup'] . "\n" . $sharedMarkup . '</body></html>');
                foreach (array('html' => 'root_attributes', 'body' => 'body_attributes') as $tag => $key) {
                    $element = $dom->getElementsByTagName($tag)->item(0);
                    foreach ($documentsBySource[$source]['document_metadata'][$key] ?? array() as $name => $value) $element?->setAttribute((string) $name, (string) $value);
                }
                $documents[$source] = $dom;
            }
            return $index = new RouteSelectorMatchIndex($documents);
        };
        $transformer = new CssStylesheetTransformer();
        $footprints = array();
        $footprint = static function (array $member, string $owner) use (&$footprints, $matchIndex, $transformer): ?array {
            if (!array_key_exists($owner, $footprints[$member['id']] ?? array())) $footprints[$member['id']][$owner] = $transformer->cascadeFootprint((string) $member['asset']['content'], $matchIndex(), $owner);
            return $footprints[$member['id']][$owner];
        };
        $layerOrder = static fn(string $css): string => 1 === preg_match('/^(?:\s*@layer\s+[\w-]+(?:\s*,\s*[\w-]+)*\s*;)+/', $css, $match) ? (string) preg_replace('/\s+/', '', $match[0]) : '';
        // Whether two members can trade places without changing the cascade
        // of any route that loads both.
        $commute = static function (array $first, array $second) use ($canonicalSources, $footprint, $layerOrder): bool {
            $owners = static fn(array $member): array => null === $member['owners'] ? $canonicalSources : $member['owners'];
            $shared = array_intersect($owners($first), $owners($second));
            if (array() === $shared) return true;
            if ($layerOrder((string) $first['asset']['content']) !== $layerOrder((string) $second['asset']['content'])) return false;
            foreach ($shared as $owner) {
                $a = $footprint($first, $owner);
                $b = null === $a ? null : $footprint($second, $owner);
                if (null === $b || CssStylesheetTransformer::cascadeFootprintsOverlap($a, $b)) return false;
            }
            return true;
        };
        // A member is still its own support sheet (sheets projected from
        // support, such as shared chrome, carry their own target) and has no
        // url() or statement whose meaning its file position decides.
        $delivery = static fn(array $asset): ?string => 'engine-support' === ($asset['source'] ?? '') && 'css' === ($asset['kind'] ?? null) && str_starts_with(basename((string) ($asset['target_path'] ?? '')), 'engine-support-')
            && is_string($asset['content'] ?? null) && 1 !== preg_match('/url\(|@import|@charset|@namespace/i', $asset['content'])
            ? serialize(array($asset['stylesheet_placement'] ?? '', $asset['stylesheet_target'] ?? 'both', $asset['media'] ?? '')) : null;
        $assets = self::withoutShadowedRules($assets, $delivery);
        $scopesOf = static function (array $members): array {
            $scopes = array();
            foreach ($members as $member) foreach ($member['asset']['scopes'] as $scope) $scopes['global' === $scope['kind'] ? '' : $scope['source_path']] = $scope;
            ksort($scopes);
            return $scopes;
        };
        $identify = static function (array $asset, array $scopes): array {
            $path = 'assets/css/engine-support-' . ($asset['stylesheet_placement'] ?? 'after-author') . '-' . substr(hash('sha256', serialize(array_keys($scopes)) . $asset['content']), 0, 16) . '.css';
            $asset['source_path'] = $path;
            $asset['target_path'] = 'assets/' . $path;
            $asset['token'] = 'asset-' . substr(hash('sha256', $asset['target_path']), 0, 16);
            $asset['reconciliation_identity'] = WordPressSitePlan::identity('asset', $path, $asset['target_path']);
            $asset['scopes'] = isset($scopes['']) ? array(array('kind' => 'global')) : array_values($scopes);
            return $asset;
        };
        $fold = static function (array $members) use ($scopesOf, $identify): array {
            // A lone member that is still its whole original resource keeps
            // its identity; a route's copy of a subset sheet is its own.
            if (1 === count($members) && !$members[0]['copy']) return $members[0]['asset'];
            // Members allocated under one layer order each restate it; the
            // fold states each leading order once, ahead of its first use.
            $stated = array();
            $contents = array_map(static function (array $member) use (&$stated): string {
                return (string) preg_replace_callback('/^(?:\s*@layer\s+[\w-]+(?:\s*,\s*[\w-]+)*\s*;)+/', static function (array $match) use (&$stated): string {
                    preg_match_all('/@layer\s+[\w-]+(?:\s*,\s*[\w-]+)*\s*;/', $match[0], $orders);
                    $kept = '';
                    foreach ($orders[0] as $order) if (!isset($stated[$order])) { $stated[$order] = true; $kept .= $order; }
                    return $kept;
                }, (string) $member['asset']['content']);
            }, $members);
            // Folding adds formatting between contributions, not CSS tokens.
            // Compact at this final boundary, before deriving the resource's
            // identity, without changing selectors, values or cascade order.
            $content = CssSyntaxScanner::withoutRedundantWhitespace(implode("\n", $contents));
            return $identify(CompiledAssets::withRewrittenTextContent($members[0]['asset'], $content), $scopesOf($members));
        };
        $ownersOf = static function (array $members): array {
            $owners = array();
            foreach ($members as $member) foreach ($member['owners'] ?? array() as $owner) $owners[$owner] = true;
            return $owners;
        };
        // The delivered row sequences an asset joins: before-author rows load
        // ahead of every document's own stylesheet instances, after-author
        // rows behind them (ThemeBootstrap::stylesheetEnqueues).
        $buckets = static function (array $asset): array {
            $placement = $asset['stylesheet_placement'] ?? '';
            if ('before-author' === $placement) return array('before');
            if ('after-author' === $placement || 'wordpress-compat' === ($asset['source'] ?? '')) return array('after');
            return array('before', 'after');
        };
        // Whether a member may move ahead of an item without changing the
        // cascade of any route that loads both.
        $passes = static function (array $member, array $item) use ($buckets, $commute): bool {
            $asset = $item['asset'];
            if (array() === array_intersect($buckets($member['asset']), $buckets($asset))) return true;
            if (!$item['fixed']) return $commute($member, $item);
            if (isset($asset['stylesheet_replays']) || isset($asset['stylesheet_parts']) || isset($asset['stylesheet_parent']) || !empty($asset['frame_fallback']) || !is_string($asset['content'] ?? null)) return false;
            // A stylesheet with document instances loads on its routes between
            // the before and after rows; only its native-route fallback, an
            // after row of a global stylesheet, sits among global support.
            if (!empty($asset['stylesheet_instances'])) return null !== $member['owners'] || null !== $item['owners'] || !in_array('after', $buckets($member['asset']), true);
            return $commute($member, $item);
        };
        $slots = $groups = $open = array();
        $passesAfter = static function (array $member, int $slot) use (&$slots, &$groups, $passes): bool {
            for ($k = $slot + 1, $count = count($slots); $k < $count; ++$k) {
                if (isset($slots[$k]['item'])) { if (!$passes($member, $slots[$k]['item'])) return false; continue; }
                foreach ($groups[$slots[$k]['group']]['members'] as $placed) if (!$passes($member, $placed)) return false;
            }
            return true;
        };
        foreach ($assets as $id => $asset) {
            if ('css' !== ($asset['kind'] ?? null)) { $slots[] = array('asset' => $asset); continue; }
            $global = in_array('global', array_column($asset['scopes'] ?? array(array('kind' => 'global')), 'kind'), true);
            $owners = $global ? null : array_values(array_unique(array_column($asset['scopes'], 'source_path')));
            if (null !== $owners) sort($owners);
            $member = array('id' => $id, 'asset' => $asset, 'owners' => $owners, 'copy' => false, 'original' => $asset, 'source_owners' => $owners, 'fixed' => false);
            $key = $delivery($asset);
            if (null === $key) { $slots[] = array('asset' => $asset, 'item' => array('fixed' => true) + $member); continue; }
            // The bundles this member joins: the global resource, its route's
            // bundle, each owner route's bundle for a subset sheet, or its own
            // owner set's resource for a subset sheet too large to copy.
            $placements = array();
            if (null === $owners) $placements[''] = $member;
            elseif (1 === count($owners) || strlen((string) $asset['content']) > self::SUBSET_SHEET_THRESHOLD) $placements[implode("\0", $owners)] = $member;
            else foreach ($owners as $owner) {
                $copy = $member;
                $copy['owners'] = array($owner);
                $copy['copy'] = true;
                $copy['asset']['scopes'] = array_slice(array_values(array_filter($asset['scopes'], static fn(array $scope): bool => $owner === ($scope['source_path'] ?? null))), 0, 1);
                $placements[$owner] = $copy;
            }
            foreach ($placements as $owner => $placed) {
                $bundle = $key . "\0" . $owner;
                // A bundle takes the slot of its first member; a member that
                // cannot move there starts the bundle's next resource.
                if (isset($open[$bundle]) && !$passesAfter($placed, $groups[$open[$bundle]]['slot'])) unset($open[$bundle]);
                if (!isset($open[$bundle])) {
                    $open[$bundle] = count($groups);
                    $groups[] = array('slot' => count($slots), 'delivery' => $key, 'members' => array());
                    $slots[] = array('group' => $open[$bundle]);
                }
                $groups[$open[$bundle]]['members'][] = $placed;
            }
        }
        // Byte-identical bundles of disjoint routes are delivered as one
        // resource at the first one's slot, when the later one may move there.
        $folded = array_map(static fn(array $group): array => $fold($group['members']), $groups);
        $kept = array();
        foreach ($groups as $j => $group) {
            $into = null;
            if (null !== $group['members'][0]['owners']) foreach ($kept as $i => $members) {
                if (null === $groups[$i]['members'][0]['owners'] || $groups[$i]['delivery'] !== $group['delivery'] || $folded[$i]['content'] !== $folded[$j]['content']) continue;
                if (array() !== array_intersect_key($ownersOf($members), $ownersOf($group['members']))) continue;
                for ($k = $groups[$i]['slot'] + 1; $k < $group['slot']; ++$k) {
                    $between = isset($slots[$k]['item']) ? array($slots[$k]['item']) : (isset($slots[$k]['group']) ? $groups[$slots[$k]['group']]['members'] : array());
                    foreach ($between as $placed) foreach ($group['members'] as $member) if (!$passes($member, $placed)) continue 4;
                }
                $into = $i;
                break;
            }
            if (null === $into) $kept[$j] = $group['members'];
            else $kept[$into] = array_merge($kept[$into], $group['members']);
        }
        $result = array();
        foreach ($slots as $slot) {
            if (!isset($slot['group'])) { $result[] = $slot['asset']; continue; }
            $i = $slot['group'];
            if (!isset($kept[$i])) continue;
            $members = $kept[$i];
            if (count($members) === count($groups[$i]['members'])) { $result[] = $folded[$i]; continue; }
            $owners = array_keys($ownersOf($members));
            sort($owners);
            // Every route's copy of one subset sheet is that sheet again.
            $result[] = 1 === count(array_unique(array_column($members, 'id'))) && $owners === $members[0]['source_owners']
                ? $members[0]['original'] : $identify($folded[$i], $scopesOf($members));
        }
        return $result;
    }

    /** At-rules whose block holds rules: their preludes are a rule's enclosing context. */
    private const GROUP_RULE = '/^@(?:media|supports|layer|container)\b/i';

    /**
     * Drop each support rule an identical rule in a later sheet shadows (#7301): same
     * normalized selector list, declarations (with `!important`) and
     * enclosing at-rule/layer chain, in a support sheet of the same delivery
     * that every route loading the earlier rule also loads (a global sheet
     * covers every route). The later copy wins wherever the earlier one
     * applies, so the earlier one changes nothing. Layer-order and other
     * statements stay, an emptied `@layer` block still declares its layer,
     * and a sheet left with nothing is not emitted. Editor-only sheets and
     * sheets $delivery keeps in place are untouched.
     *
     * @param array<int,array<string,mixed>> $assets
     * @param callable(array<string,mixed>):?string $delivery
     * @return array<int,array<string,mixed>>
     */
    private static function withoutShadowedRules(array $assets, callable $delivery): array
    {
        $covered = $removed = $trees = array();
        foreach (array_reverse($assets, true) as $index => $asset) {
            $key = $delivery($asset);
            if (null === $key || 'editor' === ($asset['stylesheet_target'] ?? 'both')) continue;
            $scopes = $asset['scopes'] ?? array(array('kind' => 'global'));
            $routes = in_array('global', array_column($scopes, 'kind'), true) ? array('') : array_unique(array_column($scopes, 'source_path'));
            $tree = new CssRuleTree((string) $asset['content']);
            if (array() === $routes || !$tree->wellFormed) continue;
            $trees[$index] = $tree;
            $identities = self::ruleIdentities($tree, -1, $key);
            foreach ($identities as [$start, $identity]) {
                foreach ($covered[$identity] ?? array() as $laterRoutes) {
                    if (isset($laterRoutes['']) || array() === array_diff_key(array_flip($routes), $laterRoutes)) { $removed[$index][$start] = true; break; }
                }
            }
            foreach ($identities as [, $identity]) $covered[$identity][$index] = array_flip($routes);
        }
        $result = array();
        foreach ($assets as $index => $asset) {
            if (isset($removed[$index])) {
                $content = self::withoutRules($trees[$index], -1, $removed[$index]);
                if ('' === trim(CssSyntaxScanner::withoutComments($content))) continue;
                $asset = CompiledAssets::withRewrittenTextContent($asset, $content);
            }
            $result[] = $asset;
        }
        return $result;
    }

    /**
     * Each style rule (or other leaf block) in a scope with its structural
     * identity: delivery, enclosing group preludes, selectors and body.
     *
     * @return list<array{0:int,1:string}>
     */
    private static function ruleIdentities(CssRuleTree $tree, int $scope, string $context): array
    {
        $normal = static fn(string $css): string => trim(CssSyntaxScanner::withoutRedundantWhitespace(CssSyntaxScanner::withoutComments($css)));
        $identities = array();
        foreach ($tree->rules($scope) as $rule) {
            if (';' === $tree->source[$rule['boundary']]) continue;
            $prelude = $normal(substr($tree->source, $rule['start'], $rule['boundary'] - $rule['start']));
            if (1 === preg_match(self::GROUP_RULE, $prelude)) {
                // Each anonymous layer creates a distinct cascade context.
                if (1 !== preg_match('/^@layer\s*$/i', $prelude)) array_push($identities, ...self::ruleIdentities($tree, $rule['boundary'], $context . "\0" . $prelude));
                continue;
            }
            // Opaque at-rules (font faces, keyframes, etc.) are not style rules.
            if (str_starts_with($prelude, '@')) continue;
            $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
            if (null !== $selectors) $prelude = implode(',', array_map($normal, $selectors));
            // Normalize as a block so only a structural final semicolon goes;
            // a semicolon inside a string or escaped value is significant.
            $body = $normal('{' . substr($tree->source, $rule['boundary'] + 1, $rule['end'] - $rule['boundary'] - 1) . '}');
            $identities[] = array($rule['start'], serialize(array($context, $prelude, $body)));
        }
        return $identities;
    }

    /** @param array<int,true> $removed Starts of rules to drop. */
    private static function withoutRules(CssRuleTree $tree, int $scope, array $removed): string
    {
        $css = '';
        foreach ($tree->rules($scope) as $rule) {
            $text = substr($tree->source, $rule['start'], $rule['end'] - $rule['start'] + 1);
            if (';' === $tree->source[$rule['boundary']]) { $css .= $text; continue; }
            $prelude = trim(CssSyntaxScanner::withoutComments(substr($tree->source, $rule['start'], $rule['boundary'] - $rule['start'])));
            if (1 === preg_match(self::GROUP_RULE, $prelude)) {
                $inner = self::withoutRules($tree, $rule['boundary'], $removed);
                // An emptied conditional group applies nothing; an emptied
                // layer block still declares its layer's position.
                if ('' === trim(CssSyntaxScanner::withoutComments($inner)) && 1 !== preg_match('/^@layer\b/i', $prelude)) continue;
                $css .= substr($tree->source, $rule['start'], $rule['boundary'] - $rule['start'] + 1) . $inner . '}';
                continue;
            }
            if (!isset($removed[$rule['start']])) $css .= $text;
        }
        return $css;
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
            $tree = new CssRuleTree($content);
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
        $lastKey = null;
        foreach ($rules as $rule) {
            ksort($rule['scopes']);
            $asset = $rule['asset'];
            $key = serialize(array($asset['stylesheet_placement'] ?? '', $asset['stylesheet_target'] ?? 'both', $asset['media'] ?? '', $rule['layer_order'], $rule['scopes']));
            // Only consecutive rules may share a sheet, preserving their
            // relative order for every consumer even after adjacent sheets fold.
            if ($key !== $lastKey) {
                $groups[] = array('key' => $key, 'asset' => $asset, 'index' => $rule['index'], 'scopes' => array_values($rule['scopes']), 'rules' => $rule['layer_order']);
                $lastKey = $key;
            }
            $groups[array_key_last($groups)]['rules'][] = $rule['text'];
        }
        // Each allocated sheet takes the slot of the first sheet it draws
        // rules from, so the inventory keeps its cascade order.
        $allocated = array();
        foreach ($groups as $group) {
            $asset = CompiledAssets::withRewrittenTextContent($group['asset'], implode("\n", $group['rules']));
            $path = 'assets/css/engine-support-' . ($asset['stylesheet_placement'] ?? 'after-author') . '-' . substr(hash('sha256', $group['key'] . $asset['content']), 0, 16) . '.css';
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

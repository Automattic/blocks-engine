<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Automattic\BlocksEngine\PhpTransformer\Support\StyleTagScanner;

/**
 * Where page stylesheets load: the instances each document carries, in the
 * order its source loaded them, with their media, device and scope.
 *
 * `instances()` records each document's stylesheet occurrences on the asset
 * that materializes them. `place()` then orders page projections, gives shared
 * projections a slot on every page that loads them, and folds byte-identical
 * page copies into one resource.
 */
final class PageStylesheets
{
    /**
     * Fields that only identify a stylesheet resource or say which documents
     * apply it. Everything else (bytes, media, target, placement, parent,
     * activation, ...) is the delivery contract two page stylesheets must
     * share before one file can serve both.
     */
    private const PAGE_STYLESHEET_IDENTITY_FIELDS = array('source_path', 'target_path', 'token', 'reconciliation_identity', 'scopes', 'stylesheet_instances', 'references', 'frame_fallback');

    /**
     * Place page stylesheets in document order: order each page's projections,
     * give shared projections a slot on every page that loads them, then fold
     * byte-identical page copies into one resource.
     *
     * @param array<int,array<string,mixed>> $assets
     * @param array<int,array<string,mixed>> $pages
     * @param array<int,array<string,mixed>> $documents
     * @return array<int,array<string,mixed>>
     */
    public static function place(array $assets, array $pages, array $documents): array
    {
        return self::mergeIdentical(self::share(self::order($assets), $pages), $documents);
    }

    /**
     * A stylesheet resource owns bytes; each document link or <style> that
     * applies it is an ordered instance with its own media/type. Record every
     * instance (A,B,A is three) on its resource so the bootstrap can replay
     * each document's cascade, then order the resource inventory by first
     * instance for display only. Unlinked/generated assets keep their slots.
     *
     * @param array<int,array<string,mixed>> $assets
     * @param array<int,array<string,mixed>> $pages
     * @return array<int,array<string,mixed>>
     */
    public static function instances(array $assets, array $pages): array
    {
        $indexesByToken = array();
        foreach ($assets as $index => $asset) {
            if ('css' === ($asset['kind'] ?? null) && is_string($asset['token'] ?? null)) {
                $indexesByToken[$asset['token']] = $index;
            }
        }

        // Page-owned <style> assets record how many stylesheet links precede
        // them, so they join that page's sequence between the same links.
        $inlineIndexesByPage = array();
        foreach ($assets as $index => $asset) {
            $owner = $asset['compilation'] ?? null;
            if ('css' === ($asset['kind'] ?? null) && is_int($asset['stylesheet_link_position'] ?? null) && is_array($owner) && 'page' === ($owner['scope'] ?? null) && is_string($owner['id'] ?? null)) {
                $inlineIndexesByPage[$owner['id']][] = $index;
            }
        }

        $linkedIndexes = array();
        $firstOccurrences = array();
        foreach ($pages as $page) {
            // The Nth stylesheet link sorts at 2N+1 and a <style> preceded by
            // N stylesheet links sorts at 2N, placing it before that link.
            $sequence = array();
            $links = is_array($page['document_metadata']['links'] ?? null) ? $page['document_metadata']['links'] : array();
            usort($links, static fn(array $left, array $right): int => (int) ($left['order'] ?? PHP_INT_MAX) <=> (int) ($right['order'] ?? PHP_INT_MAX));
            $stylesheetLinkPosition = 0;
            foreach ($links as $link) {
                if (!is_array($link) || !StyleTagScanner::isStylesheetRel((string) ($link['rel'] ?? '')) || !StyleTagScanner::isCssType((string) ($link['type'] ?? ''))) continue;
                $position = $stylesheetLinkPosition++;
                $reference = (string) ($link['asset_reference'] ?? '');
                if (!preg_match('/^' . preg_quote(WordPressSitePlan::TOKEN_PREFIX, '/') . '(asset-[a-f0-9]{16})}}$/', $reference, $match)) continue;
                $index = $indexesByToken[$match[1]] ?? null;
                if (!is_int($index)) continue;
                $sequence[] = array(2 * $position + 1, $index, (string) ($link['media'] ?? $assets[$index]['media'] ?? ''), (string) ($link['type'] ?? ''), 0, self::deviceAttributes($link));
            }
            foreach ($assets as $index => $asset) foreach ($asset['inline_references'] ?? array() as $reference) {
                if ('css' !== ($asset['kind'] ?? null) || $page['source_path'] !== ($reference['source_path'] ?? null)) continue;
                $sequence[] = array(2 * (int) ($reference['stylesheet_link_position'] ?? 0), $index, (string) (isset($reference[CaptureEvidence::DEVICE_STYLE]) ? ($reference['media'] ?? '') : ($reference['source_media'] ?? $reference['media'] ?? '')), (string) ($asset['type'] ?? ''), (int) ($reference['stylesheet_index'] ?? 0), self::inlineDeviceAttributes($reference));
            }
            foreach ($inlineIndexesByPage[(string) ($page['source_path'] ?? '')] ?? array() as $index) {
                $sequence[] = array(2 * (int) $assets[$index]['stylesheet_link_position'], $index, (string) ($assets[$index]['media'] ?? ''), '', (int) ($assets[$index]['stylesheet_inline_order'] ?? $index), self::inlineDeviceAttributes($assets[$index]));
            }
            usort($sequence, static fn(array $left, array $right): int => $left[0] <=> $right[0] ?: $left[4] <=> $right[4] ?: $left[1] <=> $right[1]);
            foreach ($sequence as $order => $entry) {
                $linkedIndexes[$entry[1]] = true;
                if (!in_array($entry[1], $firstOccurrences, true)) $firstOccurrences[] = $entry[1];
                $assets[$entry[1]]['stylesheet_instances'][] = array('source_path' => $page['source_path'], 'order' => $order, 'media' => $entry[2], 'type' => $entry[3]) + ($entry[5] ?? array());
            }
        }
        // This is only the resource inventory's display order. Runtime order is
        // per-document instances; no global graph can represent A,B,A or A/B
        // on one route and B/A on another.
        $slots = array_keys($linkedIndexes);
        sort($slots, SORT_NUMERIC);
        $orderedAssets = array_map(static fn(int $index): array => $assets[$index], $firstOccurrences);
        foreach ($slots as $position => $slot) $assets[$slot] = $orderedAssets[$position];

        return array_values($assets);
    }

    /**
     * A page-specific projection must follow its source stylesheet in the
     * WordPress enqueue list, even when another document's linked CSS forces
     * the original stylesheet into a later slot. Keep each detached shared
     * chrome slice with its page projection at that same source boundary.
     *
     * @param array<int,array<string,mixed>> $assets
     * @return array<int,array<string,mixed>>
     */
    private static function order(array $assets): array
    {
        $sources = array_fill_keys(array_column($assets, 'source_path'), true);
        $children = array();
        foreach ($assets as $asset) {
            $source = (string) ($asset['source_path'] ?? '');
            if ('css' !== ($asset['kind'] ?? null)
                || !preg_match('/^(.*)\.page-[a-f0-9]{12}\.css(\.shared-chrome)?$/D', $source, $match)
                || !isset($sources[$match[1] . '.css'])
            ) {
                continue;
            }
            $children[$match[1] . '.css'][] = $asset;
        }
        if (array() === $children) return $assets;

        $ordered = array();
        foreach ($assets as $asset) {
            $source = (string) ($asset['source_path'] ?? '');
            if (preg_match('/^(.*)\.page-[a-f0-9]{12}\.css(?:\.shared-chrome)?$/D', $source, $match)
                && isset($children[$match[1] . '.css'])
            ) {
                continue;
            }
            $ordered[] = $asset;
            $parent = str_ends_with($source, '.shared-chrome') ? substr($source, 0, -strlen('.shared-chrome')) : $source;
            if (isset($children[$parent]) && ($source === $parent . '.shared-chrome' || !isset($sources[$parent . '.shared-chrome']))) {
                array_push($ordered, ...$children[$parent]);
            }
        }
        return $ordered;
    }

    /**
     * Shared chrome rules lifted out of authored stylesheets style a template
     * part on every route, so they keep a route-independent instance on every
     * canonical document. On a document that applies a contributing stylesheet
     * the projection follows that stylesheet's last instance there (its rules'
     * winning source position); elsewhere it follows the last instance whose
     * resource precedes it in the inventory, as the flat enqueue order did.
     * Each document's sequence is renumbered so (source_path, order) stays
     * unique across resources. A projection none of whose contributing
     * stylesheets has instances keeps its inventory slot instead.
     *
     * @param array<int,array<string,mixed>> $assets
     * @param array<int,array<string,mixed>> $pages
     * @return array<int,array<string,mixed>>
     */
    private static function share(array $assets, array $pages): array
    {
        $sequences = array();
        $owners = array();
        foreach ($assets as $index => $asset) {
            foreach ($asset['stylesheet_instances'] ?? array() as $key => $instance) {
                $sequences[$instance['source_path']][] = array('index' => $index, 'key' => $key, 'order' => $instance['order']);
                $owners[(string) ($asset['source_path'] ?? '')] = true;
            }
        }
        $projections = array();
        foreach ($assets as $index => $asset) {
            if (!isset($asset['_stylesheet_projection_origins'])) continue;
            if (array_filter($asset['_stylesheet_projection_origins'], static fn(array $origin): bool => isset($owners[$origin['source_path']]))) $projections[] = $index;
            else unset($assets[$index]['_stylesheet_projection_origins']);
        }
        if (array() === $projections) return $assets;
        // A route without the source stylesheet still loads the slice for its
        // shared chrome; a device-selected source keeps that selection there.
        $deviceConditions = array();
        foreach ($projections as $projection) {
            $sources = array_column($assets[$projection]['_stylesheet_projection_origins'], 'source_path', 'source_path');
            foreach ($assets as $asset) {
                if (!isset($sources[(string) ($asset['source_path'] ?? '')])) continue;
                foreach ($asset['stylesheet_instances'] ?? array() as $instance) {
                    $device = self::deviceAttributes($instance);
                    if (!isset($device[CaptureEvidence::DEVICE_STYLE])) continue;
                    $conditions = array('media' => $instance['media'], 'type' => '') + $device;
                    $deviceConditions[$projection][serialize($conditions)] = $conditions;
                }
            }
        }
        $applies = static fn(array $scopes, string $document): bool => array() !== array_filter($scopes, static fn(array $scope): bool => 'global' === ($scope['kind'] ?? null) || ($scope['source_path'] ?? null) === $document);
        foreach ($pages as $page) {
            $document = (string) $page['source_path'];
            $sequence = $sequences[$document] ?? array();
            usort($sequence, static fn(array $left, array $right): int => $left['order'] <=> $right['order']);
            foreach ($projections as $projection) {
                $origins = array();
                $preceding = null;
                foreach ($sequence as $position => $entry) {
                    if ($entry['index'] < $projection) $preceding = $position;
                    if (isset($entry['projection'])) continue;
                    foreach ($assets[$projection]['_stylesheet_projection_origins'] as $source) {
                        if ($source['source_path'] !== ($assets[$entry['index']]['source_path'] ?? null) || !$applies($source['scopes'], $document)) continue;
                        $instance = $assets[$entry['index']]['stylesheet_instances'][$entry['key']];
                        // Coalesced sources share one winning slot per condition,
                        // not one copy per link or only the last condition.
                        // An omitted type is text/css: both are one condition.
                        $conditions = array('media' => $instance['media'], 'type' => $instance['type']) + self::deviceAttributes($instance);
                        $key = array('type' => 'text/css' === strtolower(trim((string) $instance['type'])) ? '' : $instance['type']) + $conditions;
                        $origins[serialize($key)] = array('position' => $position) + $conditions;
                    }
                }
                if (array() === $origins) {
                    foreach ($deviceConditions[$projection] ?? array(array('media' => (string) ($assets[$projection]['media'] ?? ''), 'type' => '')) as $conditions) $origins[] = array('position' => $preceding ?? -1) + $conditions;
                }
                // Insert backwards so earlier positions do not shift.
                usort($origins, static fn(array $left, array $right): int => $right['position'] <=> $left['position']);
                foreach ($origins as $origin) {
                    array_splice($sequence, $origin['position'] + 1, 0, array(array('index' => $projection, 'projection' => true, 'media' => $origin['media'], 'type' => $origin['type']) + self::deviceAttributes($origin)));
                }
            }
            foreach ($sequence as $order => $entry) {
                if (isset($entry['projection'])) $assets[$entry['index']]['stylesheet_instances'][] = array('source_path' => $document, 'order' => $order, 'media' => $entry['media'], 'type' => $entry['type']) + self::deviceAttributes($entry);
                else $assets[$entry['index']]['stylesheet_instances'][$entry['key']]['order'] = $order;
            }
        }
        foreach ($projections as $projection) unset($assets[$projection]['_stylesheet_projection_origins']);
        return $assets;
    }

    /**
     * Page-scoped stylesheets with identical bytes and delivery contract
     * become one resource that every one of their pages applies.
     *
     * Each page used to publish its own copy of what is often the same
     * projection: the slice of a shared stylesheet a page needs beyond the
     * site-wide copy, its compatibility replay, its editor state. A merged
     * resource keeps the first copy's identity and gains the other copies'
     * scopes and document instances, so the bootstrap enqueues it for each of
     * those pages exactly where it enqueued that page's copy.
     *
     * The enqueue order on a page is resource order (within one source
     * position for projected slices). The later copy is folded into the
     * earlier one only when no resource between them applies to any of the
     * later copy's pages and both sit on the same side of the first
     * document-ordered resource, so no page's cascade order changes. Copies
     * in different directories stay apart: their relative url() references
     * resolve differently, and a copy a document or stylesheet names by its
     * token (an inline `<style>` replayed in the head, an `@import`) keeps it.
     *
     * @param array<int,array<string,mixed>> $assets
     * @param array<int,array<string,mixed>> $documents
     * @return array<int,array<string,mixed>>
     */
    private static function mergeIdentical(array $assets, array $documents): array
    {
        $assets = array_values($assets);
        // Tokens and file names anything still links, imports or url()s, and
        // the stylesheets page slices hang off: those keep their identity.
        $named = array();
        foreach (array_merge(array((string) json_encode($documents, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)), array_map(static fn (array $asset): string => ArrayField::string($asset, 'content'), $assets)) as $text) {
            if (preg_match_all('/\{\{wordpress-site-plan:asset:([^}]+)\}\}/', $text, $matches)) $named += array_fill_keys($matches[1], true);
            if (preg_match_all('/(?:url\(\s*|@import\s+(?!url\()|(?:href|src)=)\\\\?["\']?([^"\'()\s\\\\]+)/i', $text, $matches)) {
                foreach ($matches[1] as $reference) $named[strtolower(basename((string) parse_url($reference, PHP_URL_PATH)))] = true;
            }
        }
        $parents = array();
        $instancesBySource = array();
        foreach ($assets as $asset) {
            if (is_string($asset['stylesheet_parent'] ?? null)) $parents[$asset['stylesheet_parent']] = true;
            if (is_string($asset['source_path'] ?? null) && !isset($instancesBySource[$asset['source_path']])) $instancesBySource[$asset['source_path']] = ArrayField::array($asset, 'stylesheet_instances');
        }
        $firstManaged = count($assets);
        foreach ($assets as $index => $asset) if (!empty($asset['stylesheet_instances'])) $firstManaged = min($firstManaged, $index);
        $pagesOf = static function (array $asset): ?array {
            $pages = array();
            foreach (is_array($asset['scopes'] ?? null) ? $asset['scopes'] : array(array('kind' => 'global')) as $scope) {
                if ('global' === ($scope['kind'] ?? null) || !is_string($scope['source_path'] ?? null)) return null;
                $pages[$scope['source_path']] = true;
            }
            return $pages;
        };
        // How ThemeBootstrap::stylesheetEnqueues() places a resource on one page: document
        // instances sort by their source position (then resource order); a
        // resource without any sits in the flat before/after run in resource
        // order. Returns null when the page does not apply the resource.
        $placement = static function (array $asset, string $page) use ($pagesOf, $instancesBySource): ?array {
            if ('css' !== ($asset['kind'] ?? null)) return null;
            $own = $pagesOf($asset);
            $applies = null === $own || isset($own[$page]);
            $occurrences = ArrayField::array($asset, 'stylesheet_instances');
            if (array() === $occurrences && is_string($asset['stylesheet_parent'] ?? null)) $occurrences = $instancesBySource[$asset['stylesheet_parent']] ?? array();
            if (array() === $occurrences) return $applies || !empty($asset['frame_fallback']) ? array('flat' => true) : null;
            $orders = array();
            foreach ($occurrences as $occurrence) if ($applies && $page === ($occurrence['source_path'] ?? null)) $orders[(int) $occurrence['order']] = true;
            if (!empty($asset['frame_fallback'])) return array('flat' => true, 'orders' => $orders);
            return array() === $orders ? null : array('flat' => false, 'orders' => $orders);
        };
        $merged = array();
        $heads = array();
        foreach ($assets as $index => $asset) {
            $source = (string) ($asset['source_path'] ?? '');
            // Projection can empty distinct generated resources. Empty bytes
            // are not evidence that those resource identities are equivalent.
            $pages = 'css' === ($asset['kind'] ?? null) && is_string($asset['content'] ?? null) && '' !== trim($asset['content']) && !isset($asset['payload_reference']) && !isset($asset['content_base64']) && !isset($parents[$source]) && !isset($named[(string) ($asset['token'] ?? '')]) && !isset($named[strtolower(basename((string) ($asset['target_path'] ?? '')))]) && !isset($named[strtolower(basename($source))]) ? $pagesOf($asset) : null;
            if (null === $pages || array() === $pages) {
                $merged[] = $asset;
                continue;
            }
            $contract = array_diff_key($asset, array_flip(self::PAGE_STYLESHEET_IDENTITY_FIELDS));
            ksort($contract);
            $key = hash('sha256', serialize(array($contract, dirname($source), dirname((string) $asset['target_path']), $index < $firstManaged, !empty($asset['stylesheet_instances']))));
            $folded = false;
            foreach ($heads[$key] ?? array() as $position) {
                $head = $merged[$position];
                if (array() !== array_intersect_key($pagesOf($head) ?? array(), $pages)) continue;
                // Moving this copy up to the head must not pass any resource
                // the same page enqueues in the same run or source position.
                // A frame fallback copy also styles native routes no document
                // owns; there it must not pass a resource those routes load.
                if (!empty($asset['frame_fallback'])) {
                    for ($between = $position + 1, $count = count($merged); $between < $count; ++$between) {
                        if ('css' === ($merged[$between]['kind'] ?? null) && (null === $pagesOf($merged[$between]) || !empty($merged[$between]['frame_fallback']))) continue 2;
                    }
                }
                foreach (array_keys($pages) as $page) {
                    $own = $placement($asset, (string) $page);
                    if (null === $own) continue;
                    for ($between = $position + 1, $count = count($merged); $between < $count; ++$between) {
                        $other = $placement($merged[$between], (string) $page);
                        if (null === $other) continue;
                        if ($own['flat'] ? $other['flat'] : array() !== array_intersect_key($own['orders'], $other['orders'] ?? array())) continue 3;
                    }
                }
                $head['scopes'] = array_merge($head['scopes'], $asset['scopes']);
                if (!empty($asset['frame_fallback'])) $head['frame_fallback'] = true;
                if (isset($asset['stylesheet_instances']) || isset($head['stylesheet_instances'])) $head['stylesheet_instances'] = array_merge($head['stylesheet_instances'] ?? array(), $asset['stylesheet_instances'] ?? array());
                if (isset($asset['references']) || isset($head['references'])) $head['references'] = WordPressSitePlan::mergedReferences(ArrayField::array($head, 'references'), ArrayField::array($asset, 'references'));
                $merged[$position] = $head;
                $folded = true;
                break;
            }
            if ($folded) continue;
            $heads[$key][] = count($merged);
            $merged[] = $asset;
        }
        return $merged;
    }

    /** Inline occurrences store the author's media separately from captured media. @return array<string,mixed> */
    private static function inlineDeviceAttributes(array $occurrence): array
    {
        if (!isset($occurrence[CaptureEvidence::DEVICE_STYLE])) return array();
        return self::deviceAttributes($occurrence) + array(CaptureEvidence::SOURCE_MEDIA => (string) ($occurrence['source_media'] ?? $occurrence['media'] ?? ''));
    }

    /** @return array<string,mixed> */
    public static function deviceAttributes(array $occurrence): array
    {
        return array_intersect_key($occurrence, array_flip(array(CaptureEvidence::DEVICE_STYLE, CaptureEvidence::SOURCE_MEDIA)));
    }

    /** @param array<string,mixed> $page @return array<string,mixed> */
    public static function documentScope(array $page): array
    {
        if (!isset($page['post_type'])) return array('kind' => 'archive', 'source_path' => $page['source_path'], 'route_path' => trim($page['route']['path'], '/'));
        return array('kind' => 'post' === $page['post_type'] ? 'post' : 'page', 'source_path' => $page['source_path'], 'route_path' => trim($page['route']['path'], '/'), 'reconciliation_identity' => $page['reconciliation_identity'], 'front_page' => '/' === $page['route']['path']);
    }
}

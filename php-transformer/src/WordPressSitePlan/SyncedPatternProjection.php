<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use InvalidArgumentException;

/**
 * Owns content every core/tab-panel of one core/tabs block repeats.
 *
 * core/tabs admits only a tab list and its panels, so a selectable set that
 * switches part of a region writes the unchanged rest into every panel. A block
 * subtree whose serialized markup is identical at the same position in every
 * panel (and is not the panel's whole content) is declared once as a synced
 * pattern entity, and each panel references it with a `core/block` whose `ref`
 * is the entity token. core/block renders the pattern content in place without
 * a wrapper on the front end, so panel-ancestor author styles still apply.
 */
final class SyncedPatternProjection
{
    public const REFERENCE_CONTRACT = 'explicit_refs/v1';
    public const SOURCE_RELATION = 'tab_panels_invariant_content';
    /** Pattern documents own the runtime bindings inside them, as shared template parts do. */
    public const SOURCE_PATH_PREFIX = 'wordpress-site-plan/synced-patterns/';
    /** Single text blocks stay in their panel. */
    private const TEXT_BLOCKS = array('paragraph', 'heading', 'list', 'list-item');
    /** The block editor's core/block wrapper, a layer saved markup does not have. */
    public const EDITOR_CONTAINER_CLASS = 'block-library-block__reusable-block-container';
    /**
     * The block editor renders a core/block inside one wrapper the front end
     * does not have. It takes no box of its own, so the pattern content keeps
     * its layout participation inside the panel.
     */
    public const EDITOR_CONTAINER_CSS = ':root .editor-styles-wrapper .wp-block-tab-panel .' . self::EDITOR_CONTAINER_CLASS . '{display:contents}';

    /**
     * Each edited page keeps `_synced_pattern_source` (its markup before
     * projection and the replaced ranges) so canonicalEntityBindings() can
     * move the runtime bindings inside a pattern onto the pattern document.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param array<int,array<string,mixed>> $assets Stylesheets that decide which content a panel hides.
     * @return array{pages:array<int,array<string,mixed>>,patterns:list<array<string,mixed>>}
     */
    public static function project(array $pages, array $assets = array()): array
    {
        $hiding = self::hidingClasses($assets);
        $clusters = array();
        foreach ($pages as $index => $page) {
            $markup = (string) ($page['canonical_block_markup'] ?? '');
            if (!str_contains($markup, 'wp:tab-panel')) continue;
            foreach (self::tabsBlocks(self::tree($markup)) as $tabs) {
                $shared = array();
                self::sharedSubtrees(array_map(static fn(array $panel): array => $panel['children'], $tabs['panels']), $markup, '', true, $hiding, $shared);
                foreach ($shared as $match) {
                    $first = $match['nodes'][0];
                    $inner = substr($markup, $first['offset'], $first['length']);
                    $clusters[WordPressSitePlan::contentHash($inner)][] = array(
                        'index' => $index, 'source_path' => (string) ($page['source_path'] ?? ''), 'markup' => $inner,
                        'nodes' => $match['nodes'], 'block_path' => $match['path'], 'panel_count' => count($tabs['panels']),
                        'title' => self::title($inner, substr($markup, $tabs['panels'][0]['offset'], $first['offset'] - $tabs['panels'][0]['offset']), $tabs['label']),
                    );
                }
            }
        }
        if (array() === $clusters) return array('pages' => $pages, 'patterns' => array());

        $patterns = array();
        $edits = array();
        foreach ($clusters as $contentHash => $occurrences) {
            $canonical = $occurrences[0];
            $identity = WordPressSitePlan::identity('synced_pattern', $canonical['source_path'], $contentHash);
            $sourcePath = self::SOURCE_PATH_PREFIX . self::token($identity);
            $reference = '<!-- wp:block ' . json_encode(array('ref' => WordPressSitePlan::SYNCED_PATTERN_TOKEN_PREFIX . self::token($identity) . '}}'), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . ' /-->';
            $patterns[] = array(
                'identity' => $identity,
                'title' => $canonical['title'],
                'canonical_block_markup' => $canonical['markup'],
                'source_path' => $sourcePath,
                'provenance' => array(
                    'source_relation' => self::SOURCE_RELATION,
                    'occurrences' => array_map(static fn(array $occurrence): array => array('source_path' => $occurrence['source_path'], 'panel_count' => $occurrence['panel_count'], 'block_path' => $occurrence['block_path']), $occurrences),
                ),
            );
            // One copy, the first panel's, becomes the pattern; the others are duplicates.
            foreach ($occurrences as $occurrenceIndex => $occurrence) foreach ($occurrence['nodes'] as $panelIndex => $node) {
                $edits[$occurrence['index']][] = array('offset' => $node['offset'], 'length' => $node['length'], 'reference' => $reference, 'source_path' => $sourcePath, 'canonical' => 0 === $occurrenceIndex && 0 === $panelIndex);
            }
        }
        foreach ($edits as $index => $nodeEdits) {
            usort($nodeEdits, static fn(array $left, array $right): int => $left['offset'] <=> $right['offset']);
            $source = (string) $pages[$index]['canonical_block_markup'];
            $markup = $source;
            foreach (array_reverse($nodeEdits) as $edit) $markup = substr($markup, 0, $edit['offset']) . $edit['reference'] . substr($markup, $edit['offset'] + $edit['length']);
            $pages[$index]['canonical_block_markup'] = $markup;
            $pages[$index]['content_hash'] = WordPressSitePlan::contentHash($markup);
            $pages[$index]['_synced_pattern_source'] = array('markup' => $source, 'moves' => array_map(static fn(array $edit): array => array('offset' => $edit['offset'], 'length' => $edit['length'], 'reference_length' => strlen($edit['reference']), 'source_path' => $edit['source_path'], 'canonical' => $edit['canonical']), $nodeEdits));
        }
        return array('pages' => $pages, 'patterns' => $patterns);
    }

    /**
     * Where a block range of a page's pre-projection markup lands: inside a
     * pattern (relative to the pattern document), or on the page with every
     * earlier or enclosed replacement applied. `duplicate` marks a range in a
     * copy that is not the pattern's own; its claim names the pattern block.
     *
     * @param array{markup:string,moves:list<array<string,mixed>>} $source
     * @param array{offset:int,length:int} $range
     * @return array{source_path:?string,markup:string,range:array{offset:int,length:int},duplicate:bool,claim:?string}
     */
    public static function rebase(array $source, string $markup, array $range): array
    {
        $shift = 0;
        $length = $range['length'];
        foreach ($source['moves'] as $move) {
            if ($range['offset'] >= $move['offset'] && $range['offset'] + $range['length'] <= $move['offset'] + $move['length']) {
                $relative = $range['offset'] - $move['offset'];
                return array('source_path' => $move['source_path'], 'markup' => substr($source['markup'], $move['offset'], $move['length']), 'range' => array('offset' => $relative, 'length' => $range['length']), 'duplicate' => !$move['canonical'], 'claim' => $move['source_path'] . "\n" . $relative);
            }
            $delta = $move['length'] - $move['reference_length'];
            if ($move['offset'] + $move['length'] <= $range['offset']) $shift += $delta;
            elseif ($move['offset'] >= $range['offset'] && $move['offset'] + $move['length'] <= $range['offset'] + $range['length']) $length -= $delta;
        }
        return array('source_path' => null, 'markup' => $markup, 'range' => array('offset' => $range['offset'] - $shift, 'length' => $length), 'duplicate' => false, 'claim' => null);
    }

    /**
     * Drop the bindings rebase() marked as duplicates, and the entities left
     * with none. The kept entity moves to the pattern document and lists the
     * fallback identities it replaces, as shared template part hoists do.
     *
     * @param array<int,array<string,mixed>> $declarations
     * @return array<int,array<string,mixed>>
     */
    public static function collapseDuplicateBindings(array $declarations): array
    {
        $replaced = array();
        foreach ($declarations as &$declaration) {
            if (!is_array($declaration['payload']['entities'] ?? null)) continue;
            foreach ($declaration['payload']['entities'] as $entityIndex => &$entity) {
                if (!is_array($entity['bindings'] ?? null)) continue;
                $claims = array_values(array_filter(array_map(static fn(mixed $binding): ?string => is_array($binding) ? ($binding['_synced_pattern_duplicate'] ?? null) : null, $entity['bindings'])));
                if (array() === $claims) continue;
                $entity['bindings'] = array_values(array_filter($entity['bindings'], static fn(mixed $binding): bool => !is_array($binding) || !isset($binding['_synced_pattern_duplicate'])));
                if (array() !== $entity['bindings']) continue;
                if (is_string($entity['fallback_identity'] ?? null) && '' !== $entity['fallback_identity']) $replaced[$claims[0]][] = $entity['fallback_identity'];
                unset($declaration['payload']['entities'][$entityIndex]);
            }
            unset($entity);
            $declaration['payload']['entities'] = array_values($declaration['payload']['entities']);
        }
        unset($declaration);
        foreach ($declarations as &$declaration) {
            if (!is_array($declaration['payload']['entities'] ?? null)) continue;
            foreach ($declaration['payload']['entities'] as &$entity) {
                if (!is_array($entity) || !is_array($entity['bindings'] ?? null)) continue;
                $claim = null;
                foreach ($entity['bindings'] as &$binding) {
                    if (!is_array($binding) || !isset($binding['_synced_pattern_claim'])) continue;
                    $claim = $binding['_synced_pattern_claim'];
                    unset($binding['_synced_pattern_claim']);
                }
                unset($binding);
                if (null === $claim) continue;
                $sources = array_unique(array_column($entity['bindings'], 'source_path'));
                if (1 === count($sources) && isset($entity['source_path'])) $entity['source_path'] = $sources[0];
                $identities = array_merge(is_string($entity['fallback_identity'] ?? null) ? array($entity['fallback_identity']) : array(), $entity['replaced_fallback_identities'] ?? array(), $replaced[$claim] ?? array());
                $identities = array_values(array_unique($identities));
                sort($identities, SORT_STRING);
                if (1 < count($identities)) $entity['replaced_fallback_identities'] = $identities;
            }
            unset($entity);
        }
        unset($declaration);
        return $declarations;
    }

    public static function token(string $identity): string
    {
        return 'pattern-' . substr($identity, 0, 16);
    }

    /**
     * Walk the panels' children in step. A position whose markup is equal in
     * every panel is shared; positions that differ but keep one block type and
     * child count are compared one level down. Only maximal subtrees that
     * render in every panel qualify: content a panel hides (display:none) and
     * single text blocks stay inline.
     *
     * @param list<list<array<string,mixed>>> $lists Sibling lists, one per panel.
     * @param list<string> $hiding Classes a stylesheet sets to display:none.
     * @param list<array{path:string,nodes:list<array<string,mixed>>}> $shared
     */
    private static function sharedSubtrees(array $lists, string $markup, string $path, bool $panelRoot, array $hiding, array &$shared): void
    {
        $count = count($lists[0]);
        foreach ($lists as $list) if (count($list) !== $count) return;
        for ($position = 0; $position < $count; ++$position) {
            $nodes = array_column($lists, $position);
            $blockPath = ('' === $path ? '' : $path . '.') . $position;
            $first = substr($markup, $nodes[0]['offset'], $nodes[0]['length']);
            $equal = true;
            foreach ($nodes as $node) if (substr($markup, $node['offset'], $node['length']) !== $first) { $equal = false; break; }
            // The whole panel content is not shared content: the set would not switch.
            if ($equal && $panelRoot && 1 === $count) return;
            foreach ($nodes as $node) if (self::hidden($node, $markup, $hiding)) continue 2;
            if ($equal) {
                if (in_array($nodes[0]['name'], self::TEXT_BLOCKS, true) || 'block' === $nodes[0]['name'] || str_contains($first, WordPressSitePlan::SYNCED_PATTERN_TOKEN_PREFIX)) continue;
                $shared[] = array('path' => $blockPath, 'nodes' => $nodes);
                continue;
            }
            $name = $nodes[0]['name'];
            foreach ($nodes as $node) if ($node['name'] !== $name || array() === $node['children'] || 'tabs' === $name) continue 2;
            self::sharedSubtrees(array_map(static fn(array $node): array => $node['children'], $nodes), $markup, $blockPath, false, $hiding, $shared);
        }
    }

    /**
     * Whether the block's own leading element(s) are hidden: a `hidden`
     * attribute, an inline `display:none`, or a class a stylesheet hides at
     * any viewport.
     *
     * @param list<string> $hiding
     */
    private static function hidden(array $node, string $markup, array $hiding): bool
    {
        $end = strpos($markup, '-->', $node['offset']);
        if (false === $end || !preg_match('/\G(?:\s*<[a-z][^>]*>)+/i', $markup, $tags, 0, $end + 3)) return false;
        preg_match_all('/<[a-z][a-z0-9-]*([^>]*)>/i', $tags[0], $elements);
        foreach ($elements[1] as $attributes) {
            preg_match_all('/([^\s=>\/]+)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+))?/', $attributes, $pairs, PREG_SET_ORDER);
            foreach ($pairs as $pair) {
                $name = strtolower($pair[1]);
                $value = html_entity_decode(trim($pair[2] ?? '', '"\''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ('hidden' === $name || ('style' === $name && preg_match('/(?:^|;)\s*display\s*:\s*none\b/i', $value))
                    || ('class' === $name && array() !== array_intersect(preg_split('/\s+/', $value) ?: array(), $hiding))) return true;
            }
        }
        return false;
    }

    /**
     * Classes in the subject of some stylesheet rule that sets display:none,
     * whatever its context or media query: a superset of what can hide.
     *
     * @param array<int,array<string,mixed>> $assets
     * @return list<string>
     */
    private static function hidingClasses(array $assets): array
    {
        $classes = array();
        foreach ($assets as $asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null)) continue;
            preg_match_all('/([^{}]+)\{([^{}]*)\}/', (string) preg_replace('~/\*.*?\*/~s', '', $asset['content']), $rules, PREG_SET_ORDER);
            foreach ($rules as $rule) {
                if (!preg_match('/(?:^|;)\s*display\s*:\s*none\s*(?:!important\s*)?(?:;|$)/i', trim($rule[2]))) continue;
                foreach (explode(',', $rule[1]) as $selector) {
                    // The subject compound's classes, outside any :not()/:where() argument.
                    do $selector = (string) preg_replace('/\([^()]*\)/', '', $selector, -1, $stripped); while ($stripped > 0);
                    $compounds = preg_split('/[\s>+~]+/', trim($selector)) ?: array();
                    if (str_contains($selector, '::') || !preg_match_all('/\.((?:\\\\.|[\w-])+)/', (string) end($compounds), $subject)) continue;
                    foreach ($subject[1] as $class) $classes[] = (string) preg_replace('/\\\\(.)/', '$1', $class);
                }
            }
        }
        return array_values(array_unique($classes));
    }

    /**
     * Tabs blocks outside any other tabs block; a nested set stays inside the
     * panel content its parent set compares.
     *
     * @return list<array{panels:list<array<string,mixed>>,label:string}>
     */
    private static function tabsBlocks(array $nodes): array
    {
        $found = array();
        foreach ($nodes as $node) {
            if ('tabs' !== $node['name']) {
                $found = array_merge($found, self::tabsBlocks($node['children']));
                continue;
            }
            $label = '';
            $panels = array();
            foreach ($node['children'] as $child) {
                if ('tab-list' === $child['name'] && is_string($child['attrs']['ariaLabel'] ?? null)) $label = trim($child['attrs']['ariaLabel']);
                if ('tab-panels' === $child['name']) $panels = array_values(array_filter($child['children'], static fn(array $panel): bool => 'tab-panel' === $panel['name']));
            }
            if (count($panels) >= 2) $found[] = array('panels' => $panels, 'label' => $label);
        }
        return $found;
    }

    /** @return list<array{offset:int,length:int,name:string,attrs:array<string,mixed>,children:list<array<string,mixed>>}> */
    private static function tree(string $markup): array
    {
        $nodes = array();
        $childrenOf = array();
        $stack = array();
        foreach (WordPressSitePlan::blockRanges($markup) as $index => $range) {
            while (array() !== $stack && $range['offset'] >= $nodes[end($stack)]['offset'] + $nodes[end($stack)]['length']) array_pop($stack);
            preg_match('/^<!--\s*wp:([a-z0-9\/_-]+)(?:\s+(\{.*?\}))?\s*\/?-->/s', substr($markup, $range['offset'], min($range['length'], 65536)), $opening);
            $attrs = isset($opening[2]) ? json_decode($opening[2], true) : array();
            $name = (string) ($opening[1] ?? '');
            $nodes[$index] = $range + array('name' => str_starts_with($name, 'core/') ? substr($name, 5) : $name, 'attrs' => is_array($attrs) ? $attrs : array());
            $childrenOf[array() === $stack ? -1 : end($stack)][] = $index;
            $stack[] = $index;
        }
        $build = static function (int $parent) use (&$build, $nodes, $childrenOf): array {
            return array_map(static fn(int $index): array => $nodes[$index] + array('children' => $build($index)), $childrenOf[$parent] ?? array());
        };
        return $build(-1);
    }

    /**
     * The subtree's first heading, else the nearest heading before it in its
     * panel, else the tab list label.
     */
    private static function title(string $markup, string $before, string $label): string
    {
        foreach (array(array($markup, false), array($before, true)) as [$source, $last]) {
            if (!preg_match_all('/<h[1-6]\b[^>]*>(.*?)<\/h[1-6]>/is', $source, $headings)) continue;
            foreach ($last ? array_reverse($headings[1]) : $headings[1] as $heading) {
                $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($heading), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                if ('' !== $text) return $text;
            }
        }
        return '' !== $label ? $label : 'Shared content';
    }

    /** Validate pattern declarations and document references as one contract. */
    public static function assertReferences(array $plan): void
    {
        $declared = array_key_exists('synced_patterns', $plan);
        if ($declared && (!is_array($plan['synced_patterns']) || !array_is_list($plan['synced_patterns']) || array() === $plan['synced_patterns'] || self::REFERENCE_CONTRACT !== ($plan['reference_semantics']['synced_patterns'] ?? null))
            || (!$declared && isset($plan['reference_semantics']['synced_patterns']))) {
            throw new InvalidArgumentException('WordPress site plan synced pattern contract is invalid.');
        }
        $entities = array();
        foreach ($declared ? $plan['synced_patterns'] : array() as $pattern) {
            if (!is_array($pattern) || array('identity', 'title', 'canonical_block_markup', 'source_path', 'provenance') !== array_keys(array_diff_key($pattern, array('resolved_block_markup' => true)))
                || !is_string($pattern['identity']) || !preg_match('/^[a-f0-9]{64}$/', $pattern['identity']) || isset($entities[self::token($pattern['identity'])])
                || !is_string($pattern['title']) || '' === trim($pattern['title']) || self::SOURCE_PATH_PREFIX . self::token($pattern['identity']) !== $pattern['source_path']
                || !is_string($pattern['canonical_block_markup']) || array() === WordPressSitePlan::blockRanges($pattern['canonical_block_markup'])
                || str_contains($pattern['canonical_block_markup'], WordPressSitePlan::SYNCED_PATTERN_TOKEN_PREFIX)
                || !is_array($pattern['provenance']) || self::SOURCE_RELATION !== ($pattern['provenance']['source_relation'] ?? null)) {
                throw new InvalidArgumentException('WordPress site plan synced pattern declaration is invalid or duplicated.');
            }
            $entities[self::token($pattern['identity'])] = false;
        }
        foreach (array('pages', 'template_parts', 'templates') as $group) foreach ($plan[$group] as $document) self::assertMarkupReferences((string) $document['canonical_block_markup'], $entities);
        foreach ($plan['writes'] as $write) if ('utf8' === ($write['payload']['encoding'] ?? null)) self::assertMarkupReferences((string) $write['payload']['data'], $entities);
        if (in_array(false, $entities, true)) throw new InvalidArgumentException('WordPress site plan synced patterns require explicit owned references.');
    }

    private static function assertMarkupReferences(string $markup, array &$entities): void
    {
        if (!str_contains($markup, WordPressSitePlan::SYNCED_PATTERN_TOKEN_PREFIX)) return;
        $pattern = '/<!-- wp:block \{"ref":"' . preg_quote(WordPressSitePlan::SYNCED_PATTERN_TOKEN_PREFIX, '/') . '(pattern-[a-f0-9]{16})\}\}"\} \/-->/';
        preg_match_all($pattern, $markup, $matches);
        foreach ($matches[1] as $token) {
            if (!array_key_exists($token, $entities)) throw new InvalidArgumentException('WordPress site plan contains an undeclared synced pattern reference.');
            $entities[$token] = true;
        }
        if (str_contains((string) preg_replace($pattern, '', $markup), WordPressSitePlan::SYNCED_PATTERN_TOKEN_PREFIX)) throw new InvalidArgumentException('WordPress site plan synced pattern references must belong to core/block blocks.');
    }
}

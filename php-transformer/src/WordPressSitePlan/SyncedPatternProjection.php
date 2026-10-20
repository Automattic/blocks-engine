<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeEntityManifest;
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
    /** The block editor's core/block wrapper, a layer saved markup does not have. */
    public const EDITOR_CONTAINER_CLASS = 'block-library-block__reusable-block-container';
    /**
     * The block editor renders a core/block inside one wrapper the front end
     * does not have. It takes no box of its own, so the pattern content keeps
     * its layout participation inside the panel.
     */
    public const EDITOR_CONTAINER_CSS = ':root .editor-styles-wrapper .wp-block-tab-panel .' . self::EDITOR_CONTAINER_CLASS . '{display:contents}';

    /** The editor layer EditorShellBoundary restatements already tolerate (the HtmlToBlocks layout-shell inner-blocks class). */
    private const EDITOR_SHELL_LAYER_CLASS = 'blocks-engine-layout-shell-editor-inner-blocks';

    /**
     * @param array<int,array<string,mixed>> $pages
     * @return array{pages:array<int,array<string,mixed>>,patterns:list<array<string,mixed>>}
     */
    public static function project(array $pages, array $declarations = array(), array $records = array()): array
    {
        $searches = self::bindingSearches($declarations, $records);
        $clusters = array();
        foreach ($pages as $index => $page) {
            $markup = (string) ($page['canonical_block_markup'] ?? '');
            if (!str_contains($markup, 'wp:tab-panel')) continue;
            $source = (string) ($page['source_path'] ?? '');
            $protected = array();
            foreach ($searches[$source] ?? array() as $search) {
                for ($cursor = 0; false !== ($found = strpos($markup, $search, $cursor)); $cursor = $found + 1) $protected[] = array('offset' => $found, 'length' => strlen($search));
            }
            foreach (self::tabsBlocks(self::tree($markup)) as $tabs) {
                $panels = $tabs['panels'];
                $shared = array();
                self::sharedSubtrees(array_map(static fn(array $panel): array => $panel['children'], $panels), $markup, '', true, $shared);
                foreach ($shared as $match) {
                    foreach ($match['nodes'] as $node) if (self::overlaps($node, $protected)) continue 2;
                    $inner = substr($markup, $match['nodes'][0]['offset'], $match['nodes'][0]['length']);
                    $clusters[WordPressSitePlan::contentHash($inner)][] = array(
                        'index' => $index, 'source_path' => $source, 'markup' => $inner,
                        'nodes' => $match['nodes'], 'block_path' => $match['path'],
                        'label' => $tabs['label'], 'panel_count' => count($panels),
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
            $reference = '<!-- wp:block ' . json_encode(array('ref' => WordPressSitePlan::SYNCED_PATTERN_TOKEN_PREFIX . self::token($identity) . '}}'), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . ' /-->';
            $patterns[] = array(
                'identity' => $identity,
                'title' => self::title($canonical['markup'], $canonical['label']),
                'canonical_block_markup' => $canonical['markup'],
                'source_path' => $canonical['source_path'],
                'provenance' => array(
                    'source_relation' => self::SOURCE_RELATION,
                    'occurrences' => array_map(static fn(array $occurrence): array => array('source_path' => $occurrence['source_path'], 'panel_count' => $occurrence['panel_count'], 'block_path' => $occurrence['block_path']), $occurrences),
                ),
            );
            foreach ($occurrences as $occurrence) foreach ($occurrence['nodes'] as $node) $edits[$occurrence['index']][] = $node + array('markup' => $reference);
        }
        foreach ($edits as $index => $nodeEdits) {
            usort($nodeEdits, static fn(array $left, array $right): int => $right['offset'] <=> $left['offset']);
            $markup = (string) $pages[$index]['canonical_block_markup'];
            foreach ($nodeEdits as $edit) $markup = substr($markup, 0, $edit['offset']) . $edit['markup'] . substr($markup, $edit['offset'] + $edit['length']);
            $pages[$index]['canonical_block_markup'] = $markup;
            $pages[$index]['content_hash'] = WordPressSitePlan::contentHash($markup);
        }
        return array('pages' => $pages, 'patterns' => $patterns);
    }

    /**
     * Editor rules that tolerate a layout-shell layer at each authored child
     * combinator (EditorShellBoundary) also tolerate the core/block wrapper,
     * so panel-ancestor child combinators still reach the pattern content in
     * the editor. These rules never match the front end.
     *
     * @param array<int,array<string,mixed>> $assets
     * @return array<int,array<string,mixed>>
     */
    public static function editorStylesheets(array $assets): array
    {
        $layer = ':where(.' . self::EDITOR_SHELL_LAYER_CLASS . ')>';
        foreach ($assets as &$asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null) || isset($asset['payload_reference']) || !str_contains($asset['content'], $layer)) continue;
            $asset = WordPressSitePlan::withRewrittenTextContent($asset, str_replace($layer, ':where(.' . self::EDITOR_SHELL_LAYER_CLASS . ',.' . self::EDITOR_CONTAINER_CLASS . ')>', $asset['content']));
        }
        unset($asset);
        return $assets;
    }

    public static function token(string $identity): string
    {
        return 'pattern-' . substr($identity, 0, 16);
    }

    /**
     * Walk the panels' children in step. A position whose markup is equal in
     * every panel is shared; positions that differ but keep one block type and
     * child count are compared one level down.
     *
     * @param list<list<array<string,mixed>>> $lists Sibling lists, one per panel.
     * @param list<array{path:string,nodes:list<array<string,mixed>>}> $shared
     */
    private static function sharedSubtrees(array $lists, string $markup, string $path, bool $panelRoot, array &$shared): void
    {
        $count = count($lists[0]);
        foreach ($lists as $list) if (count($list) !== $count) return;
        for ($position = 0; $position < $count; ++$position) {
            $nodes = array_column($lists, $position);
            $blockPath = ('' === $path ? '' : $path . '.') . $position;
            $first = substr($markup, $nodes[0]['offset'], $nodes[0]['length']);
            $equal = true;
            foreach ($nodes as $node) if (substr($markup, $node['offset'], $node['length']) !== $first) { $equal = false; break; }
            if ($equal) {
                // The whole panel content is not shared content: the set would not switch.
                if ($panelRoot && 1 === $count) return;
                if ('block' === $nodes[0]['name'] || str_contains($first, WordPressSitePlan::SYNCED_PATTERN_TOKEN_PREFIX)) continue;
                $shared[] = array('path' => $blockPath, 'nodes' => $nodes);
                continue;
            }
            $name = $nodes[0]['name'];
            foreach ($nodes as $node) if ($node['name'] !== $name || array() === $node['children'] || 'tabs' === $name) continue 2;
            self::sharedSubtrees(array_map(static fn(array $node): array => $node['children'], $nodes), $markup, $blockPath, false, $shared);
        }
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

    private static function title(string $markup, string $label): string
    {
        if (preg_match('/<h[1-6]\b[^>]*>(.*?)<\/h[1-6]>/is', $markup, $heading)) {
            $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($heading[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ('' !== $text) return $text;
        }
        return '' !== $label ? $label : 'Shared content';
    }

    private static function overlaps(array $node, array $ranges): bool
    {
        foreach ($ranges as $range) if ($node['offset'] < $range['offset'] + $range['length'] && $range['offset'] < $node['offset'] + $node['length']) return true;
        return false;
    }

    /**
     * Every block a runtime binding searches for. A shared subtree holding any
     * occurrence keeps it inline, so binding occurrences never renumber.
     *
     * @return array<string,list<string>>
     */
    private static function bindingSearches(array $declarations, array $records): array
    {
        $searches = array();
        foreach ($declarations as $declaration) {
            $payload = $declaration['payload'] ?? array();
            $entities = RuntimeEntityManifest::SCHEMA === ($payload['schema'] ?? null)
                ? RuntimeEntityManifest::resolve($payload, $records) : ($payload['entities'] ?? array());
            foreach ($entities as $entity) foreach (is_array($entity) ? ($entity['bindings'] ?? array()) : array() as $binding) {
                if (is_string($binding['source_path'] ?? null) && is_string($binding['search_block_markup'] ?? null) && '' !== $binding['search_block_markup']) $searches[$binding['source_path']][] = $binding['search_block_markup'];
            }
        }
        return $searches;
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
                || !is_string($pattern['title']) || '' === trim($pattern['title']) || !is_string($pattern['source_path']) || '' === $pattern['source_path']
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

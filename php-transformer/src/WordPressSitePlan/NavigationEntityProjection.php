<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeEntityManifest;
use Automattic\BlocksEngine\PhpTransformer\Support\BlockAttributes;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use InvalidArgumentException;

/** Owns navigation content sharing and exact document occurrences. */
final class NavigationEntityProjection
{
    public const REFERENCE_CONTRACT = 'explicit_refs/v1';

    /** Reference metadata: each item's own presentation, in document order. */
    public const ITEMS_KEY = 'blocksEngineNavigationItems';

    private const ITEM_PATTERN = '/<!--\s*wp:navigation-(?:link|submenu)\s+(\{.*?\})\s*(\/)?-->/s';

    /** @return array{pages:array,parts:array,menus:array} */
    public static function project(array $pages, array $parts, array $menus, array $declarations = array(), array $records = array()): array
    {
        $clusters = array();
        $protected = self::bindingRanges($declarations, $records);
        foreach (array('part' => $parts, 'page' => $pages) as $kind => $documents) {
            foreach ($documents as $index => $document) {
                $source = (string) ($document['source_path'] ?? '');
                $markup = (string) ($document['canonical_block_markup'] ?? '');
                // In-place panels retain source-owned item style subjects. A
                // semantically equal entity can carry another scope's classes.
                $sourcePanels = array_values(array_filter((new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($markup))->ranges(), static function (array $range) use ($markup): bool {
                    $block = substr($markup, $range['offset'], $range['length']);
                    return 1 === preg_match('/^<!--\s*wp:[^\s]+\/captured-dialog\s+(\{.*?\})\s*-->/s', $block, $match)
                        && 'in-place' === (json_decode($match[1], true)['placement'] ?? null);
                }));
                foreach (self::navigationBlocks($markup) as $block) {
                    // Provider-owned ancestors and descendants keep their exact
                    // anchors. Unrelated navigation in the same document can share.
                    if (self::overlapsBinding($block, $protected[$source] ?? array()) || self::overlapsBinding($block, $sourcePanels)) continue;
                    $inner = self::normalizeLabels(ShellExtraction::withoutCurrentNavigationState($block['inner']));
                    $identity = self::contentIdentity($inner);
                    $clusters[$identity][] = array(
                        'kind' => $kind, 'index' => $index, 'block' => $block,
                        'inner' => $inner, 'captured_inner' => $block['inner'], 'source_path' => $source,
                        'area' => (string) ($document['area'] ?? ''),
                        'title' => 'part' === $kind ? (string) ($document['title'] ?? 'Navigation') : 'Navigation',
                    );
                }
            }
        }
        if (array() === $clusters) return array('pages' => $pages, 'parts' => $parts, 'menus' => $menus);

        $entities = array();
        $usedSlugs = array();
        $replacements = array();
        foreach ($clusters as $contentIdentity => $occurrences) {
            usort($occurrences, static fn(array $left, array $right): int =>
                ('header' === $left['area'] ? 0 : 1) <=> ('header' === $right['area'] ? 0 : 1)
                ?: ('part' === $left['kind'] ? 0 : 1) <=> ('part' === $right['kind'] ? 0 : 1)
                ?: strcmp($left['source_path'], $right['source_path'])
                ?: $left['block']['offset'] <=> $right['block']['offset']);
            $canonical = $occurrences[0];
            $title = trim($canonical['title']) ?: 'Navigation';
            $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $title), '-')) ?: 'navigation';
            if (isset($usedSlugs[$slug])) $slug .= '-' . substr($contentIdentity, 0, 16);
            $usedSlugs[$slug] = true;
            $identity = hash('sha256', 'navigation:' . $contentIdentity);
            $token = 'navigation-' . substr($identity, 0, 16);
            $markup = self::sharedItemClasses($occurrences);
            $entities[] = array(
                'kind' => 'menu', 'source_path' => $canonical['source_path'],
                'target_slug' => $slug, 'title' => $title,
                'source_relation' => 'navigation_entity', 'order' => count($entities),
                'items' => $canonical['block']['items'], 'block_markup' => $markup,
                'token' => $token, 'reconciliation_identity' => $identity,
            );
            $shared = self::itemPresentation($markup);
            foreach ($occurrences as $occurrence) {
                $attrs = $occurrence['block']['attrs'];
                $attrs['ref'] = WordPressSitePlan::NAVIGATION_TOKEN_PREFIX . $token . '}}';
                // The entity holds one copy of each item's presentation. A
                // reference whose items present differently renders its own.
                $own = self::itemPresentation($occurrence['inner']);
                if ($own !== $shared) $attrs['metadata'][self::ITEMS_KEY] = self::withCapturedCurrentState($own, $occurrence['captured_inner']);
                $replacements[$occurrence['kind']][$occurrence['index']][] = array(
                    'offset' => $occurrence['block']['offset'], 'length' => $occurrence['block']['length'],
                    'markup' => '<!-- wp:navigation ' . BlockAttributes::encode($attrs, JSON_THROW_ON_ERROR) . ' /-->',
                );
            }
        }
        self::replaceOccurrences($pages, $replacements['page'] ?? array());
        self::replaceOccurrences($parts, $replacements['part'] ?? array());
        return array('pages' => $pages, 'parts' => $parts, 'menus' => $entities);
    }

    /**
     * The menus a markup renders: a factored host by its entity reference, an
     * inline menu by its content identity. Projections that keep one copy of
     * repeated markup compare these so a copy is dropped only when the kept
     * copy renders the same menus.
     *
     * @return array<string,true>
     */
    public static function renderedMenus(string $markup): array
    {
        $menus = array();
        if (!str_contains($markup, 'wp:navigation')) return $menus;
        foreach ((new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($markup))->ranges() as $range) {
            $block = substr($markup, $range['offset'], $range['length']);
            if (!preg_match('/^<!--\s*wp:navigation(?:\s+(\{.*?\}))?\s*(\/)?-->/s', $block, $opening)) continue;
            $attrs = isset($opening[1]) && '' !== $opening[1] ? json_decode($opening[1], true) : array();
            if (!is_array($attrs)) continue;
            if (is_string($attrs['ref'] ?? null)) {
                $menus['ref:' . $attrs['ref']] = true;
                continue;
            }
            $end = strrpos($block, '<!-- /wp:navigation');
            if (isset($opening[2]) || false === $end) continue;
            $inner = substr($block, strlen($opening[0]), $end - strlen($opening[0]));
            if (0 === self::itemCount($inner)) continue;
            $menus['content:' . self::contentIdentity(ShellExtraction::withoutCurrentNavigationState($inner))] = true;
        }
        return $menus;
    }

    /** Apply only recognized positions, without another content match. */
    private static function replaceOccurrences(array &$documents, array $replacements): void
    {
        foreach ($replacements as $index => $edits) {
            usort($edits, static fn(array $left, array $right): int => $right['offset'] <=> $left['offset']);
            $markup = $documents[$index]['canonical_block_markup'];
            foreach ($edits as $edit) $markup = substr($markup, 0, $edit['offset']) . $edit['markup'] . substr($markup, $edit['offset'] + $edit['length']);
            $documents[$index]['canonical_block_markup'] = $markup;
            $documents[$index]['content_hash'] = WordPressSitePlan::contentHash($markup);
        }
    }

    private static function bindingRanges(array $declarations, array $records): array
    {
        $ranges = array();
        foreach ($declarations as $declaration) {
            $payload = $declaration['payload'] ?? array();
            $entities = RuntimeEntityManifest::SCHEMA === ($payload['schema'] ?? null)
                ? RuntimeEntityManifest::resolve($payload, $records) : ($payload['entities'] ?? array());
            foreach ($entities as $entity) foreach ($entity['bindings'] ?? array() as $binding) {
                if (is_string($binding['source_path'] ?? null) && is_array($binding['position'] ?? null)) $ranges[$binding['source_path']][] = $binding['position'];
            }
        }
        return $ranges;
    }

    private static function overlapsBinding(array $block, array $ranges): bool
    {
        foreach ($ranges as $range) if ($block['offset'] < $range['offset'] + $range['length'] && $range['offset'] < $block['offset'] + $block['length']) return true;
        return false;
    }

    private static function itemCount(string $markup): int
    {
        return preg_match_all('/<!--\s*wp:navigation-(?:link|submenu)(?=[\s{\/])/', $markup);
    }

    /** Ordered item semantics, not source carriers or placement presentation. */
    private static function contentIdentity(string $markup): string
    {
        $items = static function (array $blocks) use (&$items): array {
            $result = array();
            foreach ($blocks as $block) {
                $attrs = $block['attrs'] ?? array();
                $children = $items($block['innerBlocks'] ?? array());
                if (in_array($block['blockName'] ?? '', array('core/navigation-link', 'core/navigation-submenu'), true)) {
                    $result[] = array(
                        'block' => $block['blockName'],
                        'label' => self::labelText((string) ($attrs['label'] ?? '')),
                        'url' => (string) ($attrs['url'] ?? ''),
                        'kind' => (string) ($attrs['kind'] ?? 'custom'),
                        'type' => (string) ($attrs['type'] ?? ''),
                        'opensInNewTab' => (bool) ($attrs['opensInNewTab'] ?? false),
                        'rel' => (string) ($attrs['rel'] ?? ''),
                        'children' => $children,
                    );
                } else {
                    array_push($result, ...$children);
                }
            }
            return $result;
        };
        return hash('sha256', json_encode($items((new Runtime())->parseBlocks($markup)), JSON_THROW_ON_ERROR));
    }

    /**
     * Source item classes mean what their own page's stylesheet says, so the
     * shared entity keeps only the classes every reference's item carries.
     * Document-seeded engine markers stay canonical. Each reference also keeps
     * its own navigation subject markers: its item rules are scoped by its
     * navigation instance, so they bind only inside that reference.
     */
    private static function sharedItemClasses(array $occurrences): string
    {
        $pattern = self::ITEM_PATTERN;
        $classes = static function (array $attrs): array {
            return array(
                preg_split('/\s+/', trim((string) ($attrs['className'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: array(),
                preg_split('/\s+/', trim((string) ($attrs['metadata']['blocksEngineNavigationAnchor']['className'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: array(),
            );
        };
        $isSubject = static fn(string $token): bool => 1 === preg_match('/^blocks-engine-navigation-(?:item|anchor|box)-[a-f0-9]{12}-\d+$/D', $token);
        $shared = null;
        $subjects = array();
        foreach ($occurrences as $occurrence) {
            preg_match_all($pattern, $occurrence['inner'], $matches);
            $items = array_map(static fn(string $json): array => $classes(json_decode($json, true) ?: array()), $matches[1]);
            foreach ($items as $position => $item) {
                foreach (array(0, 1) as $side) foreach (array_filter($item[$side], $isSubject) as $token) $subjects[$position][$side][$token] = true;
            }
            $shared = null === $shared ? $items : array_map(static fn(array $left, array $right): array => array(
                array_intersect($left[0], $right[0] ?? array()), array_intersect($left[1], $right[1] ?? array()),
            ), $shared, $items);
        }
        $keep = static fn(array $tokens, array $common, array $own): string => implode(' ', array_unique(array_merge(array_filter($tokens, static fn(string $token): bool =>
            in_array($token, $common, true) || EngineMarker::isSpecificityShim($token) || 1 === preg_match('/^blocks-engine-[a-z0-9-]+-[a-f0-9]{12}-\d+$/D', $token)), array_keys($own))));
        $position = 0;
        return preg_replace_callback($pattern, static function (array $match) use ($classes, $keep, $shared, $subjects, &$position): string {
            $own = $subjects[$position] ?? array();
            $common = $shared[$position++] ?? array(array(), array());
            $attrs = json_decode($match[1], true);
            if (!is_array($attrs)) return $match[0];
            $before = $classes($attrs);
            $item = $keep($before[0], $common[0], $own[0] ?? array());
            $anchor = $keep($before[1], $common[1], $own[1] ?? array());
            if ($item === implode(' ', $before[0]) && $anchor === implode(' ', $before[1])) return $match[0];
            if ('' === $item) unset($attrs['className']); else $attrs['className'] = $item;
            if (isset($attrs['metadata']['blocksEngineNavigationAnchor']['className'])) $attrs['metadata']['blocksEngineNavigationAnchor']['className'] = $anchor;
            return str_replace($match[1], BlockAttributes::encode($attrs, JSON_THROW_ON_ERROR), $match[0]);
        }, $occurrences[0]['inner']) ?? $occurrences[0]['inner'];
    }

    /**
     * Item classes and anchor presentation, in document order: the order
     * NavigationReferenceItemsRuntime visits the entity's rendered items.
     *
     * @return list<array{className?:string,blocksEngineNavigationAnchor?:array}>
     */
    private static function itemPresentation(string $markup): array
    {
        preg_match_all(self::ITEM_PATTERN, $markup, $matches);
        return array_map(static function (string $json): array {
            $attrs = json_decode($json, true) ?: array();
            $class = trim((string) ($attrs['className'] ?? ''));
            $anchor = $attrs['metadata']['blocksEngineNavigationAnchor'] ?? null;
            // Label markup carries the label's own style subjects; plain text is content only.
            $label = is_string($attrs['label'] ?? null) && str_contains($attrs['label'], '<') ? $attrs['label'] : null;
            return ('' === $class ? array() : array('className' => $class))
                + (is_array($anchor) ? array('blocksEngineNavigationAnchor' => $anchor) : array())
                + (null === $label ? array() : array('label' => $label));
        }, $matches[1]);
    }

    /**
     * Mark the items that were the served route where the reference was
     * captured. An item's captured presentation holds the state it was
     * captured in: a part's items all rest, a page's current item is
     * current. Where the served route puts an item in the other state, the
     * runtime keeps the entity's presentation for it, whose state the
     * navigation's own current and resting rules restore.
     *
     * @param list<array<string,mixed>> $presentation
     * @return list<array<string,mixed>>
     */
    private static function withCapturedCurrentState(array $presentation, string $captured): array
    {
        preg_match_all(self::ITEM_PATTERN, $captured, $matches);
        if (count($matches[1]) !== count($presentation)) return $presentation;
        foreach ($matches[1] as $position => $json) {
            $attrs = json_decode($json, true);
            $classes = preg_split('/\s+/', trim((string) (is_array($attrs) ? ($attrs['className'] ?? '') : ''))) ?: array();
            if (in_array('blocks-engine-current-navigation-item', $classes, true)) $presentation[$position]['current'] = true;
        }
        return $presentation;
    }

    private static function labelText(string $label): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($label), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? $label);
    }

    /** Unwrap inert spans, retaining inline formatting and semantic attributes. */
    private static function normalizeLabels(string $markup): string
    {
        return preg_replace_callback('/<!--\s*wp:navigation-(?:link|submenu)\s+(\{.*?\})\s*(\/)?-->/s', static function (array $match): string {
            $attrs = json_decode($match[1], true);
            if (!is_array($attrs) || !is_string($attrs['label'] ?? null)) return $match[0];
            $label = $attrs['label'];
            do {
                $before = $label;
                $label = preg_replace('/<span(?:\s+class\s*=\s*(?:"\s*"|\'\s*\'))?\s*>((?:(?!<span\b|<\/span>).)*)<\/span>/si', '$1', $label) ?? $label;
            } while ($before !== $label);
            $attrs['label'] = trim($label);
            return str_replace($match[1], BlockAttributes::encode($attrs, JSON_THROW_ON_ERROR), $match[0]);
        }, $markup) ?? $markup;
    }

    /** @return list<array{offset:int,length:int,inner:string,attrs:array,items:int}> */
    private static function navigationBlocks(string $markup): array
    {
        $blocks = array();
        foreach ((new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($markup))->ranges() as $range) {
            $block = substr($markup, $range['offset'], $range['length']);
            if (!preg_match('/^<!--\s*wp:navigation(?:\s+(\{.*?\}))?\s*-->/', $block, $opening)) continue;
            $attrs = isset($opening[1]) ? json_decode($opening[1], true) : array();
            if (!is_array($attrs) || isset($attrs['ref'])) continue;
            $end = strrpos($block, '<!-- /wp:navigation');
            if (false === $end) continue;
            $inner = substr($block, strlen($opening[0]), $end - strlen($opening[0]));
            $items = self::itemCount($inner);
            if (0 === $items) continue;
            // Invalid nested navigation hosts cannot become overlapping edits.
            if (preg_match('/<!--\s*wp:navigation(?=[\s{\/])/', $inner)) continue;
            $blocks[] = $range + array('inner' => $inner, 'attrs' => $attrs, 'items' => $items);
        }
        return $blocks;
    }

    /** Validate entity declarations and document references as one contract. */
    public static function assertReferences(array $plan): void
    {
        if (isset($plan['reference_semantics']['navigation_entities']) && self::REFERENCE_CONTRACT !== $plan['reference_semantics']['navigation_entities']) {
            throw new InvalidArgumentException('WordPress site plan navigation reference contract is unsupported.');
        }
        $entities = array();
        $identities = array();
        foreach ($plan['menus'] as $menu) {
            if (!isset($menu['token'])) continue;
            $token = $menu['token'];
            if (!is_string($token) || !preg_match('/^navigation-[a-f0-9]{16}$/', $token) || isset($entities[$token])
                || !is_string($menu['block_markup'] ?? null) || 0 === self::itemCount($menu['block_markup'])
                || self::itemCount($menu['block_markup']) !== ($menu['items'] ?? null)
                || !is_string($menu['reconciliation_identity'] ?? null) || !preg_match('/^[a-f0-9]{64}$/', $menu['reconciliation_identity'])
                || isset($identities[$menu['reconciliation_identity']])) {
                throw new InvalidArgumentException('WordPress site plan navigation entity declaration is invalid or duplicated.');
            }
            $entities[$token] = false;
            $identities[$menu['reconciliation_identity']] = true;
        }
        foreach (array('pages', 'template_parts', 'templates', 'synced_patterns') as $group) foreach ($plan[$group] ?? array() as $document) self::assertMarkupReferences((string) ($document['canonical_block_markup'] ?? ''), $entities);
        foreach ($plan['writes'] as $write) if ('utf8' === ($write['payload']['encoding'] ?? null)) self::assertMarkupReferences($write['payload']['data'], $entities);
        if (array() !== $entities && (self::REFERENCE_CONTRACT !== ($plan['reference_semantics']['navigation_entities'] ?? null) || in_array(false, $entities, true))) {
            throw new InvalidArgumentException('WordPress site plan navigation entities require explicit owned references.');
        }
    }

    private static function assertMarkupReferences(string $markup, array &$entities): void
    {
        if (!str_contains($markup, WordPressSitePlan::NAVIGATION_TOKEN_PREFIX)) return;
        $remaining = $markup;
        foreach ((new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($markup))->ranges() as $range) {
            $block = substr($markup, $range['offset'], $range['length']);
            if (!preg_match('/^<!--\s*wp:navigation\s+(\{.*?\})\s*\/-->$/s', $block, $match)) continue;
            $attrs = json_decode($match[1], true);
            $ref = $attrs['ref'] ?? null;
            if (!is_string($ref) || !str_starts_with($ref, WordPressSitePlan::NAVIGATION_TOKEN_PREFIX)) continue;
            if (!preg_match('/^' . preg_quote(WordPressSitePlan::NAVIGATION_TOKEN_PREFIX, '/') . '(navigation-[a-f0-9]{16})}}$/', $ref, $token) || !array_key_exists($token[1], $entities)) {
                throw new InvalidArgumentException('WordPress site plan contains an undeclared navigation reference.');
            }
            $entities[$token[1]] = true;
            $remaining = str_replace($block, '', $remaining);
        }
        if (str_contains($remaining, WordPressSitePlan::NAVIGATION_TOKEN_PREFIX)) throw new InvalidArgumentException('WordPress site plan navigation references must belong to navigation blocks.');
    }
}

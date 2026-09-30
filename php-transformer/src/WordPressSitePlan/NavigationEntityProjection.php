<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeEntityManifest;
use InvalidArgumentException;

/** Owns navigation content sharing and exact document occurrences. */
final class NavigationEntityProjection
{
    public const REFERENCE_CONTRACT = 'explicit_refs/v1';

    /** @return array{pages:array,parts:array,menus:array} */
    public static function project(array $pages, array $parts, array $menus, array $declarations = array(), array $records = array()): array
    {
        $clusters = array();
        $protected = self::bindingRanges($declarations, $records);
        foreach (array('part' => $parts, 'page' => $pages) as $kind => $documents) {
            foreach ($documents as $index => $document) {
                $source = (string) ($document['source_path'] ?? '');
                foreach (self::navigationBlocks((string) ($document['canonical_block_markup'] ?? '')) as $block) {
                    // Provider-owned ancestors and descendants keep their exact
                    // anchors. Unrelated navigation in the same document can share.
                    if (self::overlapsBinding($block, $protected[$source] ?? array())) continue;
                    $inner = ShellExtraction::withoutCurrentNavigationState($block['inner']);
                    $identity = self::contentIdentity($inner);
                    $clusters[$identity][] = array(
                        'kind' => $kind, 'index' => $index, 'block' => $block,
                        'inner' => $inner, 'source_path' => $source,
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
            $identity = WordPressSitePlan::identity('menu', $canonical['source_path'], $slug);
            $token = 'navigation-' . substr($identity, 0, 16);
            $entities[] = array(
                'kind' => 'menu', 'source_path' => $canonical['source_path'],
                'target_slug' => $slug, 'title' => $title,
                'source_relation' => 'navigation_entity', 'order' => count($entities),
                'items' => $canonical['block']['items'], 'block_markup' => $canonical['inner'],
                'token' => $token, 'reconciliation_identity' => $identity,
            );
            foreach ($occurrences as $occurrence) {
                $attrs = $occurrence['block']['attrs'];
                $attrs['ref'] = WordPressSitePlan::NAVIGATION_TOKEN_PREFIX . $token . '}}';
                $replacements[$occurrence['kind']][$occurrence['index']][] = array(
                    'offset' => $occurrence['block']['offset'], 'length' => $occurrence['block']['length'],
                    'markup' => '<!-- wp:navigation ' . json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . ' /-->',
                );
            }
        }
        self::replaceOccurrences($pages, $replacements['page'] ?? array());
        self::replaceOccurrences($parts, $replacements['part'] ?? array());
        return array('pages' => $pages, 'parts' => $parts, 'menus' => $entities);
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

    /** JSON escape spelling and object key order are transport, not paint. */
    private static function contentIdentity(string $markup): string
    {
        $canonical = preg_replace_callback('/<!--\s*wp:([^\s]+)\s+(\{.*?\})\s*(\/)?-->/s', static function (array $match): string {
            $attrs = json_decode($match[2], true);
            if (!is_array($attrs)) return $match[0];
            $normalize = static function (array &$value) use (&$normalize): void {
                if (!array_is_list($value)) ksort($value);
                foreach ($value as &$child) if (is_array($child)) $normalize($child);
            };
            $normalize($attrs);
            return '<!-- wp:' . $match[1] . ' ' . json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . ' ' . ($match[3] ?? '') . '-->';
        }, $markup);
        return hash('sha256', $canonical ?? $markup);
    }

    /** @return list<array{offset:int,length:int,inner:string,attrs:array,items:int}> */
    private static function navigationBlocks(string $markup): array
    {
        $blocks = array();
        foreach (WordPressSitePlan::blockRanges($markup) as $range) {
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
        foreach (array('pages', 'template_parts', 'templates') as $group) foreach ($plan[$group] as $document) self::assertMarkupReferences($document['canonical_block_markup'], $entities);
        foreach ($plan['writes'] as $write) if ('utf8' === ($write['payload']['encoding'] ?? null)) self::assertMarkupReferences($write['payload']['data'], $entities);
        if (array() !== $entities && (self::REFERENCE_CONTRACT !== ($plan['reference_semantics']['navigation_entities'] ?? null) || in_array(false, $entities, true))) {
            throw new InvalidArgumentException('WordPress site plan navigation entities require explicit owned references.');
        }
    }

    private static function assertMarkupReferences(string $markup, array &$entities): void
    {
        if (!str_contains($markup, WordPressSitePlan::NAVIGATION_TOKEN_PREFIX)) return;
        $remaining = $markup;
        foreach (WordPressSitePlan::blockRanges($markup) as $range) {
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

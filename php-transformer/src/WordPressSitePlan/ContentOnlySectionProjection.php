<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeEntityManifest;
use Automattic\BlocksEngine\PhpTransformer\Support\BlockAttributes;
use Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex;

/**
 * Locks the structure of imported page sections so owners edit content, not wrappers.
 *
 * A top-level core/group on a page whose subtree nests structural groups at
 * least two levels deep and carries content gets `templateLock:contentOnly`.
 * Only the opening block comment's JSON changes: the attribute is appended to
 * the existing attributes, so every other byte of the document stays as it was.
 * A section that is itself a runtime-bound block keeps its exact markup: its
 * binding anchors on those bytes, and the provider owns that block.
 */
final class ContentOnlySectionProjection
{
    public const ATTRIBUTE = 'templateLock';
    public const VALUE = 'contentOnly';
    private const CONTENT_BLOCKS = array('paragraph', 'heading', 'image', 'button', 'list', 'quote', 'table', 'video', 'cover', 'gallery', 'media-text');

    /**
     * @param array<int,array<string,mixed>> $pages
     * @param array<int,array<string,mixed>> $declarations Runtime declarations whose bound blocks keep their bytes.
     * @param array<int,array<string,mixed>> $records Runtime entity records the declarations reference.
     * @return array<int,array<string,mixed>>
     */
    public static function project(array $pages, array $declarations = array(), array $records = array()): array
    {
        $bound = self::boundBlocks($declarations, $records);
        foreach ($pages as $index => $page) {
            if (!is_array($page) || 'page' !== ($page['post_type'] ?? 'page') || !is_string($page['canonical_block_markup'] ?? null)) continue;
            $markup = $page['canonical_block_markup'];
            $source = $page['_synced_pattern_source'] ?? null;
            $ordinals = self::lockableSections($markup, $bound[(string) ($page['source_path'] ?? '')] ?? array(), is_array($source) && is_string($source['markup'] ?? null) ? $source['markup'] : null);
            if (array() === $ordinals) continue;
            [$locked] = self::lock($markup, $ordinals);
            $pages[$index]['canonical_block_markup'] = $locked;
            $pages[$index]['content_hash'] = WordPressSitePlan::contentHash($locked);
            // Synced pattern moves are nested inside sections, never top-level,
            // so the same sections exist in the pre-pattern markup bindings
            // anchor on. Lock them there too and shift the later moves.
            if (is_array($source) && is_string($source['markup'] ?? null) && is_array($source['moves'] ?? null)) {
                [$sourceLocked, $insertions] = self::lock($source['markup'], $ordinals);
                foreach ($source['moves'] as &$move) {
                    $shift = 0;
                    foreach ($insertions as [$at, $length]) if ($at <= $move['offset']) $shift += $length;
                    $move['offset'] += $shift;
                }
                unset($move);
                $source['markup'] = $sourceLocked;
                $pages[$index]['_synced_pattern_source'] = $source;
            }
        }
        return $pages;
    }

    /** Top-level sections that carry the lock, counted across documents. */
    public static function count(array $documents): int
    {
        $count = 0;
        foreach ($documents as $document) {
            $markup = is_array($document) ? ($document['canonical_block_markup'] ?? null) : null;
            if (!is_string($markup) || !str_contains($markup, '"' . self::ATTRIBUTE . '":"' . self::VALUE . '"')) continue;
            foreach (self::topLevelGroups($markup) as $group) if (self::VALUE === (self::attributes($group['attributes'])[self::ATTRIBUTE] ?? null)) $count++;
        }
        return $count;
    }

    /**
     * Each document's runtime-bound block markup.
     *
     * @return array<string,array<string,true>>
     */
    private static function boundBlocks(array $declarations, array $records): array
    {
        $bound = array();
        foreach ($declarations as $declaration) {
            $payload = is_array($declaration) ? ($declaration['payload'] ?? array()) : array();
            $entities = RuntimeEntityManifest::SCHEMA === ($payload['schema'] ?? null)
                ? RuntimeEntityManifest::resolve($payload, $records) : ($payload['entities'] ?? array());
            foreach (is_array($entities) ? $entities : array() as $entity) foreach (is_array($entity) ? ($entity['bindings'] ?? array()) : array() as $binding) {
                if (is_string($binding['source_path'] ?? null) && is_string($binding['search_block_markup'] ?? null)) $bound[$binding['source_path']][$binding['search_block_markup']] = true;
            }
        }
        return $bound;
    }

    /**
     * Ordinals (among top-level groups) of sections to lock.
     *
     * @param array<string,true> $bound Runtime-bound block markup on this document.
     * @param string|null $sourceMarkup Pre-pattern markup bindings also anchor on.
     * @return array<int,true>
     */
    private static function lockableSections(string $markup, array $bound, ?string $sourceMarkup): array
    {
        if (!str_contains($markup, 'wp:group')) return array();
        $ordinals = array();
        $sourceGroups = null === $sourceMarkup ? array() : self::topLevelGroups($sourceMarkup);
        foreach (self::topLevelGroups($markup) as $ordinal => $group) {
            if (isset($bound[substr($markup, $group['offset'], $group['length'])])) continue;
            $counterpart = $sourceGroups[$ordinal] ?? null;
            if (null !== $counterpart && isset($bound[substr((string) $sourceMarkup, $counterpart['offset'], $counterpart['length'])])) continue;
            $attributes = self::attributes($group['attributes']);
            // Unreadable attributes are left alone; an existing lock is the author's.
            if (null === $attributes || array_key_exists(self::ATTRIBUTE, $attributes)) continue;
            if ($group['nested_groups'] >= 2 && $group['content']) $ordinals[$ordinal] = true;
        }
        return $ordinals;
    }

    /**
     * Each top-level group's opening token and the shape of its subtree:
     * the deepest chain of descendant groups and whether it holds content.
     *
     * @return list<array{offset:int,length:int,token:string,attributes:string,nested_groups:int,content:bool}>
     */
    private static function topLevelGroups(string $markup): array
    {
        $groups = array();
        $stack = array();
        foreach ((new BlockMarkupIndex($markup))->tokens() as $token) {
            $name = self::name($token['name']);
            if ($token['closing']) {
                $closed = array_pop($stack);
                if (array() === $stack && is_int($closed)) $groups[$closed]['length'] = $token['offset'] + strlen($token['token']) - $groups[$closed]['offset'];
                continue;
            }
            if (array() === $stack) {
                if ('group' === $name && !$token['self_closing']) {
                    $groups[] = array('offset' => $token['offset'], 'length' => 0, 'token' => $token['token'], 'attributes' => $token['attributes'], 'nested_groups' => 0, 'content' => false);
                }
            } elseif (null !== $stack[0]) {
                $section = &$groups[$stack[0]];
                if (in_array($name, self::CONTENT_BLOCKS, true)) $section['content'] = true;
                if ('group' === $name) {
                    // Groups between this one and the section root, plus this one.
                    $depth = 1 + count(array_filter(array_slice($stack, 1), static fn(mixed $entry): bool => 'group' === $entry));
                    $section['nested_groups'] = max($section['nested_groups'], $depth);
                }
                unset($section);
            }
            if ($token['self_closing']) continue;
            // The root entry holds the section index (or null), deeper entries their block name.
            $stack[] = array() === $stack ? ('group' === $name ? count($groups) - 1 : null) : $name;
        }
        return $groups;
    }

    /**
     * Appends the lock to the chosen top-level groups' opening comments.
     *
     * @param array<int,true> $ordinals
     * @return array{0:string,1:list<array{0:int,1:int}>} Markup and each insertion's offset and length.
     */
    private static function lock(string $markup, array $ordinals): array
    {
        $edits = array();
        foreach (self::topLevelGroups($markup) as $ordinal => $group) {
            if (!isset($ordinals[$ordinal])) continue;
            $edits[] = array('offset' => $group['offset'], 'token' => $group['token'], 'attributes' => $group['attributes']);
        }
        $insertions = array();
        $pair = substr(BlockAttributes::encode(array(self::ATTRIBUTE => self::VALUE), JSON_THROW_ON_ERROR), 1, -1);
        foreach (array_reverse($edits) as $edit) {
            $token = $edit['token'];
            if ('' === $edit['attributes']) {
                // `<!-- wp:group -->` gains its first attribute object.
                $position = strpos($token, 'wp:group') + strlen('wp:group');
                $inserted = ' {' . $pair . '}';
            } else {
                $json = $edit['attributes'];
                $position = strpos($token, $json) + strlen($json) - 1;
                $inserted = ('{}' === preg_replace('/\s+/', '', $json) ? '' : ',') . $pair;
            }
            $at = $edit['offset'] + $position;
            $markup = substr($markup, 0, $at) . $inserted . substr($markup, $at);
            $insertions[] = array($at, strlen($inserted));
        }
        return array($markup, array_reverse($insertions));
    }

    private static function name(string $name): string
    {
        return str_starts_with($name, 'core/') ? substr($name, 5) : $name;
    }

    /** @return array<string,mixed>|null The comment's attributes, or null when they are not a JSON object. */
    private static function attributes(string $json): ?array
    {
        if ('' === $json) return array();
        $attributes = json_decode($json, true);
        return is_array($attributes) && str_starts_with($json, '{') ? $attributes : null;
    }
}

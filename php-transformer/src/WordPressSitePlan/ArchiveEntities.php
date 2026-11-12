<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use InvalidArgumentException;

/**
 * The author and taxonomy-term entities a site plan declares.
 *
 * Each entity names its slug, its member posts, the evidence that proved it
 * and, for an archive-backed entity, the archive route, query and optional
 * captured presentation. A hierarchical category names a proven immediate
 * parent whose archive route is its own route's directory.
 */
final class ArchiveEntities
{
    public static function assertAuthors(array $entities, array $pagesBySource): void
    {
        if (!array_is_list($entities) || count($entities) > 256) throw new InvalidArgumentException('WordPress site plan author entity count or list is invalid.');
        $slugs = array();
        $claimed = array();
        foreach ($entities as $entity) {
            if (!is_array($entity) || 'author' !== ($entity['kind'] ?? null) || !is_string($entity['slug'] ?? null) || !preg_match('/^[a-z0-9][a-z0-9-]{0,49}$/', $entity['slug']) || !is_string($entity['name'] ?? null) || '' === trim($entity['name'])) throw new InvalidArgumentException('WordPress site plan author entity is structurally invalid.');
            WordPressSitePlan::unique($slugs, $entity['slug'], 'author nicename');
            $archive = $entity['archive'] ?? null;
            if (!is_array($archive) || !WordPressSitePlan::safePath($archive['source_path'] ?? null) || ($archive['source_route'] ?? null) !== '/author/' . $entity['slug'] || ($archive['query'] ?? null) !== array('post_type' => 'post', 'author' => $entity['slug']) || !self::validPresentation($archive)) throw new InvalidArgumentException('WordPress site plan author archive is structurally invalid.');
            $members = $entity['membership_source_paths'] ?? null;
            if (!is_array($members) || !array_is_list($members) || array() === $members || count($members) > 10000) throw new InvalidArgumentException('WordPress site plan author membership list is invalid.');
            $sorted = $members;
            sort($sorted, SORT_STRING);
            if ($members !== $sorted) throw new InvalidArgumentException('WordPress site plan author membership must be sorted.');
            foreach ($members as $source) {
                if (!is_string($source) || 'post' !== ($pagesBySource[$source]['post_type'] ?? null)) throw new InvalidArgumentException('WordPress site plan author membership must name a canonical post.');
                WordPressSitePlan::unique($claimed, $source, 'post author membership');
            }
            if (($entity['evidence'] ?? null) !== array('membership' => true, 'name' => true, 'archive' => true)) throw new InvalidArgumentException('WordPress site plan author evidence is invalid.');
        }
    }

    /** Presentation may retain captured static blocks or an inherited archive query. */
    private static function validPresentation(array $archive): bool
    {
        if (!array_key_exists('presentation_markup', $archive)) return true;
        $markup = $archive['presentation_markup'];
        return is_string($markup) && '' !== trim($markup) && strlen($markup) <= 1000000
            && (!str_contains($markup, '<!-- wp:query ') || (
                str_contains($markup, '"inherit":true')
                && str_contains($markup, '<!-- wp:query-pagination ')
                && str_contains($markup, '<!-- wp:query-no-results -->')
            ));
    }

    public static function assertTerms(array $entities, array $pagesBySource): void
    {
        $seen = array();
        $byTerm = array();
        foreach ($entities as $entity) if (is_array($entity) && is_string($entity['taxonomy'] ?? null) && is_string($entity['slug'] ?? null)) $byTerm[$entity['taxonomy'] . ':' . $entity['slug']] = $entity;
        if (count($entities) > 256) throw new InvalidArgumentException('WordPress site plan taxonomy entity count exceeds its bound.');
        foreach ($entities as $entity) {
            if (!is_array($entity) || 'taxonomy_term' !== ($entity['kind'] ?? null) || !in_array($entity['taxonomy'] ?? null, array('category', 'post_tag'), true) || !is_string($entity['slug'] ?? null) || !preg_match('/^[a-z0-9][a-z0-9-]{0,199}$/', $entity['slug']) || !is_string($entity['name'] ?? null) || '' === trim($entity['name'])) throw new InvalidArgumentException('WordPress site plan taxonomy entity is structurally invalid.');
            WordPressSitePlan::unique($seen, $entity['taxonomy'] . ':' . $entity['slug'], 'taxonomy term');
            $archive = $entity['archive'] ?? null;
            if (array_key_exists('archive', $entity)) {
            if (!is_array($archive) || !WordPressSitePlan::safePath($archive['source_path'] ?? null) || !is_string($archive['source_route'] ?? null) || !preg_match('~^/[a-z0-9-]+(?:/[a-z0-9-]+)*$~', $archive['source_route']) || !self::validPresentation($archive) || ($archive['query'] ?? null) !== array('post_type' => 'post', 'taxonomy' => $entity['taxonomy'], 'term' => $entity['slug'])) throw new InvalidArgumentException('WordPress site plan taxonomy archive is structurally invalid.');
            if (basename($archive['source_route']) !== $entity['slug']) throw new InvalidArgumentException('WordPress site plan taxonomy slug does not match its archive route.');
            if (array_key_exists('parent', $entity)) {
                $parent = $entity['parent'];
                if ('category' !== $entity['taxonomy'] || !is_string($parent) || !preg_match('/^[a-z0-9][a-z0-9-]{0,199}$/', $parent) || $parent === $entity['slug']) throw new InvalidArgumentException('WordPress site plan taxonomy parent is structurally invalid.');
                $parentEntity = $byTerm[$entity['taxonomy'] . ':' . $parent] ?? null;
                if (!is_array($parentEntity) || ($parentEntity['archive']['source_route'] ?? null) !== dirname($archive['source_route'])) throw new InvalidArgumentException('WordPress site plan taxonomy parent is not a proven immediate ancestor.');
            } elseif ('category' === $entity['taxonomy'] && preg_match('~(?:^|/)category/[^/]+/[^/]+~', $archive['source_route'])) {
                throw new InvalidArgumentException('WordPress site plan hierarchical category lacks its parent.');
            }
            } elseif (('category' !== $entity['taxonomy'] && 'metadata:content_index' !== ($entity['provenance'] ?? null)) || isset($entity['parent'])) {
                throw new InvalidArgumentException('WordPress site plan section must be an unparented category.');
            }
            $members = $entity['membership_source_paths'] ?? null;
            if (!is_array($members) || !array_is_list($members) || count($members) > 10000) throw new InvalidArgumentException('WordPress site plan taxonomy membership list is structurally invalid.');
            $memberSet = array();
            foreach ($members as $sourcePath) {
                if (!is_string($sourcePath) || !isset($pagesBySource[$sourcePath]) || 'post' !== ($pagesBySource[$sourcePath]['post_type'] ?? null)) throw new InvalidArgumentException('WordPress site plan taxonomy membership does not name a canonical post.');
                WordPressSitePlan::unique($memberSet, $sourcePath, 'taxonomy membership');
            }
            $evidence = array('membership' => true, 'name' => true);
            if (array_key_exists('archive', $entity)) $evidence['archive'] = true;
            if (($entity['evidence'] ?? null) !== $evidence) throw new InvalidArgumentException('WordPress site plan taxonomy evidence is incomplete.');
        }
    }

    /**
     * Each archive-backed entity names a captured source that has no
     * materialized page of its own.
     *
     * @param array<int,array<string,mixed>> $entities @param array<string,mixed> $sourceCatalog @param array<string,array<string,mixed>> $pagesBySource
     */
    public static function assertArchiveSources(array $entities, array $sourceCatalog, array $pagesBySource): void
    {
        foreach ($entities as $entity) {
            if (!array_key_exists('archive', $entity)) continue;
            $source = $entity['archive']['source_path'] ?? '';
            if (!isset($sourceCatalog[$source]) || isset($pagesBySource[$source])) throw new InvalidArgumentException('WordPress site plan native archive must name a captured source without a materialized page.');
        }
    }

    /**
     * An archive entity with captured presentation has exactly one per-entity
     * template, from its own archive source and carrying that presentation. An
     * archive that falls back to the template hierarchy has none.
     *
     * @param array<int,array<string,mixed>> $entities @param array<int,array<string,mixed>> $templates
     */
    public static function assertArchiveTemplates(array $entities, array $templates): void
    {
        foreach ($entities as $entity) {
            if (!isset($entity['archive'])) continue;
            $templateSlug = TemplateSlugs::forArchive($entity);
            $archiveTemplates = array_values(array_filter($templates, static fn(array $template): bool => $templateSlug === ($template['slug'] ?? null)));
            if (!array_key_exists('presentation_markup', $entity['archive'])) {
                if (array() !== $archiveTemplates) throw new InvalidArgumentException('WordPress site plan fallback archive must not have a per-entity template.');
                continue;
            }
            if (1 !== count($archiveTemplates) || ($archiveTemplates[0]['source_path'] ?? null) !== $entity['archive']['source_path'] || ($archiveTemplates[0]['canonical_block_markup'] ?? null) !== $entity['archive']['presentation_markup']) throw new InvalidArgumentException('WordPress site plan native archive entity lacks its source-owned archive template.');
        }
    }
}

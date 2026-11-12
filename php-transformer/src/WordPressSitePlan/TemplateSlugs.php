<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/**
 * Which WordPress template slug serves a page or archive, and which per-route
 * override templates a site plan's shared parts reach.
 *
 * One slug names a route for placement, exclusions, template registration and
 * assignment: `front-page`, `page-<slug>`, `single-<post type>-<slug>`, or a
 * declared `route_template`. Archives use `category-`, `tag-` or `author-`.
 */
final class TemplateSlugs
{
    /** One key for placement, exclusions, template registration and assignment. */
    public static function forPage(array $page): string
    {
        return $page['route_template'] ?? (!empty($page['entrypoint']) ? 'front-page' : ('page' === ($page['post_type'] ?? null) ? 'page-' . $page['slug'] : 'single-' . $page['post_type'] . '-' . $page['slug']));
    }

    /** @param array<string,mixed> $part */
    public static function partAppliesToOverride(array $part, string $slug): bool
    {
        if (!preg_match('/^(?:page|single)-[a-z0-9_-]+$/', $slug) || in_array($slug, $part['placement']['excluded_template_slugs'] ?? array(), true)) return false;
        $types = $part['placement']['post_types'] ?? (isset($part['placement']['post_type']) ? array($part['placement']['post_type']) : null);
        if (null === $types) return true;
        foreach ($types as $type) {
            if (str_starts_with($slug, 'page' === $type ? 'page-' : 'single-' . $type . '-')) return true;
        }
        return false;
    }

    /**
     * Per-route override templates: slugs a part is excluded from, wrapper
     * templates, and frame templates. Excluded slugs are kept as given; the
     * other sources count only page-* and single-* slugs.
     *
     * @param array<int,array<string,mixed>> $parts @param array<int,mixed> $frameSlugs @return array<string,bool>
     */
    public static function overrides(array $parts, array $frameSlugs): array
    {
        $slugs = array();
        foreach ($parts as $part) {
            foreach ($part['placement']['excluded_template_slugs'] ?? array() as $slug) if (is_string($slug)) $slugs[$slug] = true;
            foreach (array_keys($part['placement']['template_wrappers'] ?? array()) as $slug) if (preg_match('/^(?:page|single)-[a-z0-9_-]+$/', $slug)) $slugs[$slug] = true;
        }
        foreach ($frameSlugs as $slug) if (is_string($slug) && preg_match('/^(?:page|single)-[a-z0-9_-]+$/', $slug)) $slugs[$slug] = true;
        return $slugs;
    }

    /** Source-owned native templates share the taxonomy presentation path. */
    public static function forArchive(array $entity): string
    {
        return ('author' === $entity['kind'] ? 'author-' : ('post_tag' === ($entity['taxonomy'] ?? null) ? 'tag-' : 'category-')) . $entity['slug'];
    }
}

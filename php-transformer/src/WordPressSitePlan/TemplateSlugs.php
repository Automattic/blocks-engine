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

    /** The generic hierarchy template used without a route override. */
    public static function genericForPage(array $page, array $templateSlugs): string
    {
        if ('page' === $page['post_type']) return 'page';
        $typed = 'single-' . $page['post_type'];
        return in_array($typed, $templateSlugs, true) ? $typed : 'single';
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

    /**
     * Pages whose terminal slugs collide get a readable route-qualified
     * template (`page-north-about`), as an editor would name it; a short
     * route hash disambiguates only when the readable key is itself taken.
     * The front page is never qualified.
     *
     * @param array<int,array<string,mixed>> $pages
     * @return array<int,array<string,mixed>>
     */
    public static function qualifyColliding(array $pages): array
    {
        $templateOwners = array();
        foreach ($pages as $page) if (empty($page['entrypoint'])) $templateOwners[self::forPage($page)][] = $page['source_path'];
        $claimedTemplates = $templateOwners;
        foreach ($pages as &$page) {
            $key = self::forPage($page);
            if (empty($page['entrypoint']) && 1 < count($templateOwners[$key] ?? array())) {
                $prefix = 'page' === ($page['post_type'] ?? null) ? 'page-' : 'single-' . $page['post_type'] . '-';
                $routeSlug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(trim((string) $page['route']['path'], '/'))), '-');
                $template = $prefix . ('' === $routeSlug ? $page['slug'] : $routeSlug);
                if ($template === $key || isset($claimedTemplates[$template])) $template .= '-' . substr(hash('sha256', (string) $page['route']['path']), 0, 8);
                $claimedTemplates[$template] = true;
                $page['route_template'] = $template;
                $page['metadata']['post_meta']['_wp_page_template'] = $template;
            }
        }
        unset($page);
        return $pages;
    }
}

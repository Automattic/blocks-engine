<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/**
 * Numbered captures of a listing: `/page/2/` and `/comment-page-2/` routes.
 *
 * A pagination capture is a view of its parent, not a standalone document, so
 * the plan suppresses it. It still repeats its listing's chrome, so each one
 * records the listing that owns it, and the runtime entities it shares with
 * that listing (a sidebar form, for instance) fold into the listing's binding.
 */
final class PaginationCaptures
{
    /**
     * @param array<int,array<string,mixed>> $pages
     * @param array<string,mixed> $routeBySource target route per source path
     * @param array<int,array<string,mixed>> $surfaces
     * @param array<string,string> $ownerBySource archive owners from the taxonomy projection
     * @return array{sources:array<string,bool>,owners:array<string,string>,diagnostics:list<array<string,mixed>>}
     */
    public static function select(array $pages, array $routeBySource, array $surfaces, array $ownerBySource): array
    {
        $sources = array();
        $diagnostics = array();
        foreach ($pages as $page) {
            $route = $routeBySource[$page['source_path']] ?? '';
            $parent = PageRoute::paginationParent($route);
            if (!empty($page['entrypoint']) || null === $parent) continue;
            $sources[$page['source_path']] = true;
            $diagnostics[] = array(
                'code' => 'wordpress_site_plan_pagination_suppressed',
                'severity' => 'info',
                'message' => 'A pagination capture is a view of its parent, not a standalone document.',
                'source_path' => $page['source_path'],
                'source_route' => $route,
                'parent_route' => $parent,
                'reason_code' => str_contains($route, '/comment-page-') ? 'comment_pagination' : 'listing_pagination',
            );
        }
        // A suppressed numbered capture repeats its listing's chrome. Record the
        // listing that owns it so the runtime entities it shares with that
        // listing (a sidebar form, for instance) fold into the listing's binding.
        $owners = $ownerBySource;
        $sourceByRoute = array_flip(array_map(static fn(string $route): string => rtrim($route, '/'), array_filter($routeBySource, 'is_string')));
        // A listing that became a template surface has no page route; its
        // numbered capture still sits beside it in the source tree.
        $surfaceSources = array_fill_keys(array_column($surfaces, 'source_path'), true);
        foreach (array_keys($sources) as $source) {
            $owner = $sourceByRoute[rtrim((string) PageRoute::paginationParent($routeBySource[$source] ?? ''), '/')] ?? null;
            $sibling = preg_replace('~/(?:page/[1-9][0-9]*|comment-page-[1-9][0-9]*)(/index\.html?)$~i', '$1', $source);
            if (!is_string($owner) && is_string($sibling) && isset($surfaceSources[$sibling])) $owner = $sibling;
            if (is_string($owner) && $owner !== $source && !isset($sources[$owner])) $owners[$source] = $owner;
        }
        return array('sources' => $sources, 'owners' => $owners, 'diagnostics' => $diagnostics);
    }
}

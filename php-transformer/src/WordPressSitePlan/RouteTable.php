<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\DeclaredContent;
use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use InvalidArgumentException;

/**
 * The page routes one site plan run assigns: one canonical WordPress route per
 * captured document, the page hierarchy those routes imply, and a warning for
 * every route that had to be renamed or flattened to keep routes unique.
 *
 * Build one per run. `canonical()` assigns routes, `hierarchy()` nests pages
 * under them, and `diagnostics()` reports the collisions both recorded.
 */
final class RouteTable
{
    private const MAX_ROUTE_COLLISION_DIAGNOSTICS = 50;

    /** @var array<int,array<string,mixed>> */
    private array $routeCollisions = array();
    private int $omittedRouteCollisionDiagnostics = 0;

    /**
     * The canonical source-path-to-route map, and the only place a route
     * identity is decided.
     *
     * A collision here is information about two pages, not about the plan: a
     * CMS that slugifies author-written titles into filenames routinely emits
     * two paths that derive one slug (one name captured with two extensions,
     * `about.html` beside `about.htm`), and aborting costs every other page in
     * the site. So the
     * first occurrence in document order keeps the route and later ones take a
     * deterministic `-2`, `-3` suffix, exactly as WordPress resolves a
     * duplicate `post_name`, with one warning naming both source paths.
     *
     * Two identities are not the plan's to rename, and both are reserved before
     * any derived route is assigned: an authored `metadata.route_path`, where
     * two explicit values naming one route are a contradiction that still fails
     * closed (except author claims, which are diagnosed and withheld), and the
     * entrypoint, which must keep `/` or the site has no front page.
     *
     * @param mixed $documents @param array<int,array<string,mixed>> $legacyRoutes @return array<int,array<string,mixed>>
     */
    public function canonical(mixed $documents, array $legacyRoutes): array
    {
        if (!is_array($documents)) throw new InvalidArgumentException('Compiled site documents must be an array.');
        $legacy = array(); foreach ($legacyRoutes as $route) if (is_array($route) && is_string($route['source_path'] ?? null)) $legacy[$route['source_path']] = $route;
        $entryRoot = WordPressSitePlan::entryRootFromDocuments($documents);
        $this->routeCollisions = array(); $this->omittedRouteCollisionDiagnostics = 0;
        $derived = array(); $explicit = array();
        foreach ($documents as $order => $document) {
            if (!is_array($document) || !WordPressSitePlan::safePath($document['source_path'] ?? null)) throw new InvalidArgumentException('Compiled site route source is invalid.');
            $metadata = ArrayField::array($document, 'metadata');
            $explicit[$order] = is_string($metadata['route_path'] ?? null) && '' !== $metadata['route_path'];
            if ('' !== $entryRoot && ! str_starts_with((string) $document['source_path'], $entryRoot . '/') && !$explicit[$order]) throw new InvalidArgumentException('Compiled site document is outside the entrypoint content root.');
            $derived[$order] = $explicit[$order] ? PageRoute::canonical($metadata['route_path']) : PageRoute::fromSource($document['source_path'], $entryRoot);
        }
        $taken = array(); $reserved = array();
        foreach ($derived as $order => $path) if ($explicit[$order]) {
            if (isset($taken[$path])) {
                // Duplicate author claims are evidence ambiguity, not permission
                // to rename an author. Keep both captures as fallback documents;
                // AuthorProjection sees their original claim and withholds both.
                if (preg_match('~^/author/[^/]+$~', $path)) continue;
                throw new InvalidArgumentException(sprintf('WordPress site plan has colliding page routes: %s and %s both declare %s.', $taken[$path], (string) $documents[$order]['source_path'], $path));
            }
            $taken[$path] = (string) $documents[$order]['source_path']; $reserved[$order] = true;
        }
        foreach ($derived as $order => $path) if (!$explicit[$order] && !empty($documents[$order]['entrypoint']) && !isset($taken[$path])) { $taken[$path] = (string) $documents[$order]['source_path']; $reserved[$order] = true; }
        // Every first claimant keeps its own route before any suffix is handed
        // out, so a renamed duplicate cannot take the route a later page derived
        // for itself: `about.htm` beside a real `about.html` and a page that
        // derived `/about-2` becomes `/about-3`, not `/about-2`.
        foreach ($derived as $order => $path) if (!isset($reserved[$order]) && !isset($taken[$path])) { $taken[$path] = (string) $documents[$order]['source_path']; $reserved[$order] = true; }
        $routes = array();
        foreach ($documents as $order => $document) {
            $sourcePath = (string) $document['source_path'];
            $path = $derived[$order];
            if (!isset($reserved[$order])) {
                $kept = $taken[$path];
                $path = PageRoute::disambiguated($path, $taken);
                $this->recordCollision($kept, $sourcePath, $derived[$order], $path);
                $taken[$path] = $sourcePath;
            }
            $metadata = ArrayField::array($document, 'metadata');
            $previous = $legacy[$sourcePath] ?? array();
            $routes[] = array('kind' => 'route', 'source_path' => $document['source_path'], 'target_path' => $path, 'target_slug' => WordPressSitePlan::value($document, 'slug', PageRoute::slug($path)), 'title' => WordPressSitePlan::value($document, 'title'), 'parent_source_path' => WordPressSitePlan::value($metadata, 'parent_source_path'), 'source_relation' => !empty($document['entrypoint']) ? 'entrypoint' : ($previous['source_relation'] ?? 'document'), 'order' => $order);
            if (preg_match('~^/author/[^/]+$~', $derived[$order])) $routes[array_key_last($routes)]['source_claim_path'] = $derived[$order];
        }
        return $routes;
    }

    private function recordCollision(string $keptSourcePath, string $sourcePath, string $routePath, string $resolvedPath, bool $flattened = false): void
    {
        if (count($this->routeCollisions) >= self::MAX_ROUTE_COLLISION_DIAGNOSTICS) { ++$this->omittedRouteCollisionDiagnostics; return; }
        $this->routeCollisions[] = array(
            'code' => $flattened ? 'wordpress_site_plan_page_hierarchy_flattened' : 'wordpress_site_plan_colliding_page_route',
            'severity' => 'warning',
            'message' => substr($flattened ? sprintf('%s cannot inherit the post route of %s (%s); it materializes at %s instead.', $sourcePath, $keptSourcePath, $routePath, $resolvedPath) : sprintf('%s derives the same route as %s (%s); it materializes at %s instead.', $sourcePath, $keptSourcePath, $routePath, $resolvedPath), 0, 256),
            'source_path' => mb_strcut($sourcePath, 0, 256, 'UTF-8'),
            'colliding_source_path' => mb_strcut($keptSourcePath, 0, 256, 'UTF-8'),
            'route_path' => mb_strcut($routePath, 0, 256, 'UTF-8'),
            'resolved_route_path' => mb_strcut($resolvedPath, 0, 256, 'UTF-8'),
            'reason_code' => $flattened ? 'page_hierarchy_flattened' : 'colliding_page_route',
            'pattern_family' => 'site_plan_route',
            'repair_bucket' => 'restore_canonical_route_identity',
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function diagnostics(): array
    {
        $diagnostics = $this->routeCollisions;
        if ($this->omittedRouteCollisionDiagnostics > 0) $diagnostics[] = array('code' => 'wordpress_site_plan_colliding_page_route', 'severity' => 'warning', 'message' => sprintf('%d more colliding page routes were disambiguated; omitted from this diagnostic list.', $this->omittedRouteCollisionDiagnostics), 'reason' => 'truncated', 'reason_code' => 'colliding_page_route', 'pattern_family' => 'site_plan_route', 'repair_bucket' => 'restore_canonical_route_identity', 'omitted_count' => $this->omittedRouteCollisionDiagnostics);
        return $diagnostics;
    }

    /** @param array<int,array<string,mixed>> $pages @param array<int,array<string,mixed>> $routes @return array<int,array<string,mixed>> */
    public function hierarchy(array $pages, array $routes): array
    {
        $byRoute = array(); $sources = array(); foreach ($pages as $page) $sources[$page['source_path']] = true;
        foreach ($pages as $index => &$page) {
            $route = array_values(array_filter($routes, static fn(array $route): bool => $route['source_path'] === $page['source_path']))[0] ?? null; if (!is_array($route)) throw new InvalidArgumentException('WordPress site plan page lacks a canonical route.'); $path = $route['target_path'];
            if (isset($byRoute[$path])) throw new InvalidArgumentException(sprintf('WordPress site plan has colliding page routes: %s and %s both resolve to %s.', (string) ($pages[$byRoute[$path]]['source_path'] ?? ''), (string) $page['source_path'], $path));
            $page['route'] = array('path' => $path, 'parent_path' => PageRoute::parent($path), 'slug' => PageRoute::slug($path));
            if ('/' !== $path) $page['slug'] = DeclaredContent::sanitize($page['metadata']['declared_content'] ?? null)['slug'] ?? $page['route']['slug'];
            $page['reconciliation_identity'] = WordPressSitePlan::identity('page', $page['source_path'], $path);
            $byRoute[$path] = $index;
        }
        unset($page);
        $inboundLabels = null;
        foreach (array_keys($byRoute) as $path) {
            if ('post' === ($pages[$byRoute[$path]]['post_type'] ?? null)) continue;
            // Any existing route, including a post, blocks a synthetic ancestor.
            foreach (PageRoute::ancestors($path) as $ancestor) if (!isset($byRoute[$ancestor])) {
            $source = 'wordpress-site-plan/routes/' . trim($ancestor, '/') . '.html';
            if (isset($sources[$source])) throw new InvalidArgumentException('WordPress site plan synthetic route source collides with a document.');
            $markup = '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"></div><!-- /wp:group -->' . "\n";
            $inboundLabels ??= self::inboundLinkLabels($pages);
            $title = self::mostCommonInboundLabel($inboundLabels, $ancestor) ?? ucwords(str_replace('-', ' ', PageRoute::slug($ancestor)));
            $pages[] = array('source_path' => $source, 'slug' => PageRoute::slug($ancestor), 'title' => $title, 'post_type' => 'page', 'parent_source_path' => '', 'entrypoint' => false, 'area' => null, 'placement' => null, 'canonical_block_markup' => $markup, 'metadata' => array(), 'document_metadata' => array('source_context' => array('source_path' => $source, 'kind' => 'synthetic_route'), 'title' => $title, 'title_declaration' => array('order' => 0, 'placement' => 'head'), 'meta' => array(), 'links' => array(), 'scripts' => array()), 'provenance' => array(), 'reconciliation_identity' => WordPressSitePlan::identity('page', $source, $ancestor), 'content_hash' => hash('sha256', $markup), 'route' => array('path' => $ancestor, 'parent_path' => PageRoute::parent($ancestor), 'slug' => PageRoute::slug($ancestor)), 'synthetic' => true);
            $byRoute[$ancestor] = count($pages) - 1;
            $sources[$source] = true;
            }
        }
        // The front page has no route segment, but WordPress still needs a
        // physical slug. Reserve captured routes and their generated ancestors
        // before choosing that fallback, so /home keeps its own native page.
        if (isset($byRoute['/'])) {
            $root = &$pages[$byRoute['/']];
            $fallbackPath = '/' . $root['slug'];
            if (isset($byRoute[$fallbackPath])) $root['slug'] = PageRoute::slug(PageRoute::disambiguated($fallbackPath, $byRoute));
            unset($root);
        }
        // Repair ancestors before descendants, including synthetic pages. Existing
        // post routes stay reserved and must never be replaced by synthetic pages.
        $hierarchyOrder = array_keys($pages);
        usort($hierarchyOrder, static fn(int $left, int $right): int => substr_count($pages[$left]['route']['path'], '/') <=> substr_count($pages[$right]['route']['path'], '/'));
        foreach ($hierarchyOrder as $index) {
            $page = &$pages[$index];
            if ('post' === ($page['post_type'] ?? null)) { $page['parent_source_path'] = ''; continue; }
            $parent = $page['route']['parent_path'];
            if ('/' !== $parent && 'page' !== ($pages[$byRoute[$parent] ?? -1]['post_type'] ?? null)) {
                $originalPath = $page['route']['path'];
                $parentSource = $pages[$byRoute[$parent] ?? -1]['source_path'] ?? '';
                do { $parent = PageRoute::parent($parent); } while ('/' !== $parent && 'page' !== ($pages[$byRoute[$parent] ?? -1]['post_type'] ?? null));
                $path = rtrim($parent, '/') . '/' . PageRoute::slug($originalPath);
                if (isset($byRoute[$path])) $path = PageRoute::disambiguated($path, $byRoute);
                $this->recordCollision($parentSource, $page['source_path'], $originalPath, $path, true);
                unset($byRoute[$originalPath]);
                $byRoute[$path] = $index;
                $page['route'] = array('path' => $path, 'parent_path' => PageRoute::parent($path), 'slug' => PageRoute::slug($path));
                $page['slug'] = $page['route']['slug'];
                $page['metadata']['route_path'] = $path;
                $page['reconciliation_identity'] = WordPressSitePlan::identity('page', $page['source_path'], $path);
            }
            $page['parent_source_path'] = '/' === $parent ? '' : $pages[$byRoute[$parent]]['source_path'];
        }
        unset($page);
        usort($pages, static fn(array $left, array $right): int => substr_count($left['route']['path'], '/') <=> substr_count($right['route']['path'], '/') ?: strcmp($left['route']['path'], $right['route']['path']));
        return $pages;
    }

    /**
     * Inbound internal link labels per canonical route path, counted across the
     * canonical page markup. A synthesized route parent has no source document
     * of its own, so the label other pages use to reach its route is the best
     * available human title.
     *
     * @param array<int,array<string,mixed>> $pages
     * @return array<string,array<string,int>>
     */
    private static function inboundLinkLabels(array $pages): array
    {
        $labels = array();
        $collect = static function (string $href, string $label) use (&$labels): void {
            $href = html_entity_decode((string) preg_replace('/[?#].*$/s', '', trim($href)), ENT_QUOTES | ENT_HTML5);
            if ('' === $href || !str_starts_with($href, '/') || str_starts_with($href, '//')) return;
            $label = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($label), ENT_QUOTES | ENT_HTML5)));
            if ('' === $label) return;
            $path = '/' . trim($href, '/');
            $labels[$path][$label] = ($labels[$path][$label] ?? 0) + 1;
        };
        foreach ($pages as $page) {
            $markup = (string) ($page['canonical_block_markup'] ?? '');
            if (preg_match_all('/<!--\s*wp:navigation-(?:link|submenu)\s+(\{.*?\})\s*\/?-->/s', $markup, $matches)) foreach ($matches[1] as $json) {
                $attributes = json_decode($json, true);
                if (is_array($attributes) && is_string($attributes['url'] ?? null) && is_string($attributes['label'] ?? null)) $collect($attributes['url'], $attributes['label']);
            }
            if (preg_match_all('~<a\b[^>]*?\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>~is', $markup, $matches, PREG_SET_ORDER)) foreach ($matches as $anchor) $collect($anchor[2], $anchor[3]);
        }
        return $labels;
    }

    /** @param array<string,array<string,int>> $labels */
    private static function mostCommonInboundLabel(array $labels, string $path): ?string
    {
        $best = null; $bestCount = 0;
        foreach ($labels[$path] ?? array() as $label => $count) if ($count > $bestCount) { $best = (string) $label; $bestCount = $count; }
        return $best;
    }

    /** @param array<int,array<string,mixed>> $pages @return array<int,array<string,mixed>> */
    public static function forPages(array $pages): array { $routes = array(); foreach ($pages as $page) $routes[] = array('kind' => 'route', 'source_path' => $page['source_path'], 'target_path' => $page['route']['path'], 'target_slug' => $page['slug'], 'title' => $page['title'], 'parent_source_path' => $page['parent_source_path'], 'source_relation' => !empty($page['synthetic']) ? 'synthetic_parent' : (!empty($page['entrypoint']) ? 'entrypoint' : 'document'), 'order' => count($routes)); return $routes; }
}

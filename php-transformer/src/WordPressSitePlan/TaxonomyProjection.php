<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/** Recognizes captured category collections from reciprocal source-document evidence. */
final class TaxonomyProjection
{
    /** @param array<int,array<string,mixed>> $documents @param array<int,array<string,mixed>> $routes @return array{entities:array<int,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>,pagination_source_paths_by_archive:array<string,array<int,string>>} */
    public static function project(array $documents, array $routes, string $sourceOrigin = ''): array
    {
        $routeBySource = array_column($routes, 'target_path', 'source_path');
        $routeByLinkPath = ReciprocalArchiveProof::routeLinkAliases($routes);
        $documentsByRoute = array();
        foreach ($documents as $document) {
            $source = $document['source_path'] ?? null;
            $route = is_string($source) ? ($routeBySource[$source] ?? null) : null;
            if (is_string($route)) $documentsByRoute[$route] = $document;
        }
        $linksBySource = array();
        foreach ($documents as $document) {
            $source = $document['source_path'] ?? null;
            if (is_string($source)) $linksBySource[$source] = ReciprocalArchiveProof::links((string) ($document['html'] ?? ''), $sourceOrigin);
        }

        $entities = array();
        $diagnostics = array();
        $corroboratedBySlug = array();
        foreach ($documents as $archive) {
            $source = $archive['source_path'] ?? null;
            $route = is_string($source) ? ($routeBySource[$source] ?? null) : null;
            if (!is_string($source) || !is_string($route) || !preg_match('~(?:^|/)category/([^/]+(?:/[^/]+)*)$~', $route, $categoryMatch) || preg_match('~/page/[1-9][0-9]{0,5}$~', $route)) continue;
            $slug = basename($route);
            if (!self::supportedTermSpelling($route, $slug)) {
                $diagnostics[] = array('code' => 'wordpress_site_plan_taxonomy_archive_unsupported_spelling', 'severity' => 'info', 'message' => 'A captured category route or slug spelling is outside the canonical term vocabulary, so the collection stays explicitly unproven.', 'source_path' => $source, 'source_route' => $route);
                continue;
            }
            $heading = ReciprocalArchiveProof::heading((string) ($archive['html'] ?? ''));
            $listedDocuments = array();
            $listed = array();
            foreach (ReciprocalArchiveProof::links((string) ($archive['html'] ?? ''), $sourceOrigin) as $link) {
                $listedRoute = $routeByLinkPath[$link['href']] ?? $link['href'];
                $listedDocument = $documentsByRoute[$listedRoute] ?? null;
                if (!is_array($listedDocument)) continue;
                $listedDocuments[$listedRoute] = $listedDocument;
                if ('post' === ($listedDocument['metadata']['post_type'] ?? null)) $listed[$listedRoute] = $listedDocument;
            }
            $members = array();
            $paginationSources = array();
            $archiveDocuments = array($route => $archive);
            foreach ($documentsByRoute as $archiveRoute => $sibling) {
                if (!preg_match('~^' . preg_quote($route, '~') . '/page/[1-9][0-9]{0,5}$~', $archiveRoute)) continue;
                $archiveDocuments[$archiveRoute] = $sibling;
            }
            if ('' !== $heading && '' !== $slug && basename($route) === $slug) {
                foreach ($listed as $articleRoute => $article) {
                    $articleSource = (string) $article['source_path'];
                    foreach ($linksBySource[$articleSource] ?? array() as $link) {
                        $linkedRoute = $routeByLinkPath[$link['href']] ?? $link['href'];
                        if ($route === $linkedRoute && $heading === $link['label']) {
                            $members[] = $articleSource;
                            break;
                        }
                    }
                }
                foreach ($archiveDocuments as $archiveRoute => $sibling) {
                    if ($archiveRoute === $route) continue;
                    if (ReciprocalArchiveProof::heading((string) ($sibling['html'] ?? '')) !== $heading) continue;
                    $siblingSource = (string) ($sibling['source_path'] ?? '');
                    foreach (ReciprocalArchiveProof::links((string) ($sibling['html'] ?? ''), $sourceOrigin) as $link) {
                        $listedRoute = $routeByLinkPath[$link['href']] ?? $link['href'];
                        $listedDocument = $documentsByRoute[$listedRoute] ?? null;
                        if (!is_array($listedDocument) || 'post' !== ($listedDocument['metadata']['post_type'] ?? null)) continue;
                        $articleSource = (string) ($listedDocument['source_path'] ?? '');
                        foreach ($linksBySource[$articleSource] ?? array() as $backlink) {
                            $backlinkRoute = $routeByLinkPath[$backlink['href']] ?? $backlink['href'];
                            if ($route === $backlinkRoute && $heading === $backlink['label']) {
                                $members[] = $articleSource;
                                $paginationSources[$siblingSource] = true;
                                break;
                            }
                        }
                    }
                }
            }
            $members = array_values(array_unique($members));
            if (array() === $members) {
                if (array() !== $listedDocuments) $diagnostics[] = array('code' => 'wordpress_site_plan_taxonomy_archive_unproven', 'severity' => 'info', 'message' => 'A captured category collection lacked matching article return links, source labels, or post members.', 'source_path' => $source, 'source_route' => $route);
                continue;
            }
            $corroboratedBySlug['category:' . $slug][] = array('source' => $source, 'route' => $route, 'slug' => $slug, 'heading' => $heading, 'members' => $members, 'archive' => $archive, 'pagination_sources' => array_keys($paginationSources), 'parent_route' => str_contains($categoryMatch[1], '/') ? dirname($route) : null);
        }
        $paginationSourcesByArchive = array();
        $candidatesByRoute = array();
        foreach ($corroboratedBySlug as $group) {
            if (1 < count($group)) {
                foreach ($group as $candidate) $diagnostics[] = array('code' => 'wordpress_site_plan_taxonomy_archive_ambiguous_term', 'severity' => 'info', 'message' => 'More than one captured category archive claims the same term slug, so none of them can prove ownership of that term.', 'source_path' => $candidate['source'], 'source_route' => $candidate['route']);
                continue;
            }
            $candidatesByRoute[$group[0]['route']] = $group[0];
        }
        // Parents precede descendants regardless of source-document order.
        uksort($candidatesByRoute, static fn(string $a, string $b): int => substr_count($a, '/') <=> substr_count($b, '/') ?: strcmp($a, $b));
        $provenRoutes = array();
        foreach ($candidatesByRoute as $candidate) {
            $parentRoute = $candidate['parent_route'];
            if (null !== $parentRoute && !isset($provenRoutes[$parentRoute])) {
                $diagnostics[] = self::missingParentDiagnostic($candidate['source'], $candidate['route'], $parentRoute);
                continue;
            }
            $provenRoutes[$candidate['route']] = true;
            sort($candidate['members'], SORT_STRING);
            if (array() !== $candidate['pagination_sources']) $paginationSourcesByArchive[$candidate['source']] = $candidate['pagination_sources'];
            $archive = array(
                'source_path' => $candidate['source'],
                'source_route' => $candidate['route'],
                'presentation_markup' => (string) ($candidate['archive']['block_markup'] ?? ''),
                'query' => array('post_type' => 'post', 'taxonomy' => 'category', 'term' => $candidate['slug']),
            );
            $entity = array(
                'kind' => 'taxonomy_term', 'taxonomy' => 'category', 'slug' => $candidate['slug'], 'name' => $candidate['heading'],
                'membership_source_paths' => $candidate['members'],
                'archive' => $archive,
                'evidence' => array('membership' => true, 'name' => true, 'archive' => true),
            );
            if (null !== $parentRoute) $entity['parent'] = basename($parentRoute);
            $entities[] = $entity;
        }
        return array('entities' => $entities, 'diagnostics' => $diagnostics, 'pagination_source_paths_by_archive' => $paginationSourcesByArchive);
    }

    /** Remove descendants if a parent's native query presentation was subsequently declined. */
    public static function retainProvenParents(array $entities, array &$diagnostics): array
    {
        $byTerm = array();
        foreach ($entities as $entity) $byTerm[$entity['taxonomy'] . ':' . $entity['slug']] = $entity;
        do {
            $changed = false;
            foreach ($byTerm as $key => $entity) {
                if (!isset($entity['parent']) || isset($byTerm[$entity['taxonomy'] . ':' . $entity['parent']])) continue;
                $diagnostics[] = self::missingParentDiagnostic($entity['archive']['source_path'], $entity['archive']['source_route'], dirname($entity['archive']['source_route']));
                unset($byTerm[$key]);
                $changed = true;
            }
        } while ($changed);
        return array_values($byTerm);
    }

    private static function missingParentDiagnostic(string $source, string $route, string $parentRoute): array
    {
        return array('code' => 'wordpress_site_plan_taxonomy_archive_unproven_parent', 'severity' => 'info', 'message' => 'The category archive stays unproven because its parent archive ' . $parentRoute . ' is not proven.', 'source_path' => $source, 'source_route' => $route);
    }

    /** A candidate must already spell the route and slug the canonical plan accepts, or the evidence stays unproven instead of failing the whole plan. */
    private static function supportedTermSpelling(string $route, string $slug): bool
    {
        return 1 === preg_match('~^/[a-z0-9-]+(?:/[a-z0-9-]+)*$~', $route) && 1 === preg_match('/^[a-z0-9][a-z0-9-]{0,199}$/', $slug);
    }
}

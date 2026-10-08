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
        $routeByLinkPath = self::routeLinkAliases($routes);
        $documentsByRoute = array();
        foreach ($documents as $document) {
            $source = $document['source_path'] ?? null;
            $route = is_string($source) ? ($routeBySource[$source] ?? null) : null;
            if (is_string($route)) $documentsByRoute[$route] = $document;
        }
        $linksBySource = array();
        foreach ($documents as $document) {
            $source = $document['source_path'] ?? null;
            if (is_string($source)) $linksBySource[$source] = self::links((string) ($document['html'] ?? ''), $sourceOrigin);
        }

        $entities = array();
        $diagnostics = array();
        $corroboratedBySlug = array();
        foreach ($documents as $archive) {
            $source = $archive['source_path'] ?? null;
            $route = is_string($source) ? ($routeBySource[$source] ?? null) : null;
            if (!is_string($source) || !is_string($route) || !preg_match('~(?:^|/)category/[^/]+$~', $route)) continue;
            $slug = basename($route);
            if (!self::supportedTermSpelling($route, $slug)) {
                $diagnostics[] = array('code' => 'wordpress_site_plan_taxonomy_archive_unsupported_spelling', 'severity' => 'info', 'message' => 'A captured category route or slug spelling is outside the canonical term vocabulary, so the collection stays explicitly unproven.', 'source_path' => $source, 'source_route' => $route);
                continue;
            }
            $heading = self::heading((string) ($archive['html'] ?? ''));
            $listedDocuments = array();
            $listed = array();
            foreach (self::links((string) ($archive['html'] ?? ''), $sourceOrigin) as $link) {
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
                    if (self::heading((string) ($sibling['html'] ?? '')) !== $heading) continue;
                    $siblingSource = (string) ($sibling['source_path'] ?? '');
                    foreach (self::links((string) ($sibling['html'] ?? ''), $sourceOrigin) as $link) {
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
            if (count($members) < 2) {
                if (array() !== $listedDocuments) $diagnostics[] = array('code' => 'wordpress_site_plan_taxonomy_archive_unproven', 'severity' => 'info', 'message' => 'A captured category collection lacked matching article return links, source labels, or multiple post members.', 'source_path' => $source, 'source_route' => $route);
                continue;
            }
            $corroboratedBySlug['category:' . $slug][] = array('source' => $source, 'route' => $route, 'slug' => $slug, 'heading' => $heading, 'members' => $members, 'archive' => $archive, 'pagination_sources' => array_keys($paginationSources));
        }
        $paginationSourcesByArchive = array();
        foreach ($corroboratedBySlug as $group) {
            if (1 < count($group)) {
                foreach ($group as $candidate) $diagnostics[] = array('code' => 'wordpress_site_plan_taxonomy_archive_ambiguous_term', 'severity' => 'info', 'message' => 'More than one captured category archive claims the same term slug, so none of them can prove ownership of that term.', 'source_path' => $candidate['source'], 'source_route' => $candidate['route']);
                continue;
            }
            $candidate = $group[0];
            sort($candidate['members'], SORT_STRING);
            if (array() !== $candidate['pagination_sources']) $paginationSourcesByArchive[$candidate['source']] = $candidate['pagination_sources'];
            $archive = array(
                'source_path' => $candidate['source'],
                'source_route' => $candidate['route'],
                'presentation_markup' => (string) ($candidate['archive']['block_markup'] ?? ''),
                'query' => array('post_type' => 'post', 'taxonomy' => 'category', 'term' => $candidate['slug']),
            );
            $entities[] = array(
                'kind' => 'taxonomy_term', 'taxonomy' => 'category', 'slug' => $candidate['slug'], 'name' => $candidate['heading'],
                'membership_source_paths' => $candidate['members'],
                'archive' => $archive,
                'evidence' => array('membership' => true, 'name' => true, 'archive' => true),
            );
        }
        return array('entities' => $entities, 'diagnostics' => $diagnostics, 'pagination_source_paths_by_archive' => $paginationSourcesByArchive);
    }

    /** Resolve only aliases declared by the exact source document and canonical route tables. */
    private static function routeLinkAliases(array $routes): array
    {
        $sourceRoot = null;
        foreach ($routes as $route) {
            if ('/' !== ($route['target_path'] ?? null) || !is_string($route['source_path'] ?? null)) continue;
            $source = str_replace('\\', '/', $route['source_path']);
            if ('index.html' === $source || str_ends_with($source, '/index.html')) {
                $sourceRoot = dirname($source);
            }
            break;
        }

        $targetsByLink = array();
        foreach ($routes as $route) {
            $source = $route['source_path'] ?? null;
            $target = $route['target_path'] ?? null;
            if (!is_string($source) || !is_string($target) || '' === $target) continue;
            $target = rtrim($target, '/') ?: '/';
            $targetsByLink[$target][$target] = true;

            if (null === $sourceRoot) continue;
            $source = str_replace('\\', '/', $source);
            if ('.' === $sourceRoot) {
                $relativeSource = $source;
            } else {
                $prefix = rtrim($sourceRoot, '/') . '/';
                if (!str_starts_with($source, $prefix)) continue;
                $relativeSource = substr($source, strlen($prefix));
            }
            if ('' !== $relativeSource) {
                $sourceAlias = '/' . ltrim($relativeSource, '/');
                $targetsByLink[$sourceAlias][$target] = true;
            }
        }

        $aliases = array();
        foreach ($targetsByLink as $link => $targets) {
            if (1 === count($targets)) $aliases[$link] = (string) array_key_first($targets);
        }
        return $aliases;
    }

    /** A candidate must already spell the route and slug the canonical plan accepts, or the evidence stays unproven instead of failing the whole plan. */
    private static function supportedTermSpelling(string $route, string $slug): bool
    {
        return 1 === preg_match('~^/[a-z0-9-]+(?:/[a-z0-9-]+)*$~', $route) && 1 === preg_match('/^[a-z0-9][a-z0-9-]{0,199}$/', $slug);
    }

    /** @return array<int,array{href:string,label:string}> */
    private static function links(string $html, string $sourceOrigin): array
    {
        if ('' === $html || !class_exists(\DOMDocument::class)) return array();
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) return array();
            $links = array();
            foreach ($document->getElementsByTagName('a') as $anchor) {
                $path = self::localLinkPath(trim($anchor->getAttribute('href')), $sourceOrigin);
                $label = trim(preg_replace('/\s+/u', ' ', $anchor->textContent) ?? '');
                if (null !== $path && '' !== $label) $links[] = array('href' => rtrim($path, '/') ?: '/', 'label' => $label);
            }
            return $links;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** Only the source site's own links can prove local structure: root-relative paths, or absolute HTTP(S) URLs on the source origin. */
    private static function localLinkPath(string $href, string $sourceOrigin): ?string
    {
        if ('' === $href) return null;
        if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $href) === 1) return '' !== $sourceOrigin && preg_match('~^https?://~i', $href) === 1 && self::origin($href) === $sourceOrigin ? (string) (parse_url($href, PHP_URL_PATH) ?? '') : null;
        if (str_starts_with($href, '/')) return (string) (parse_url($href, PHP_URL_PATH) ?? '');
        return null;
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), array('http', 'https'), true) || '' === (string) ($parts['host'] ?? '')) return '';
        $scheme = strtolower((string) $parts['scheme']);
        return $scheme . '://' . strtolower((string) $parts['host']) . ':' . (string) ($parts['port'] ?? ('https' === $scheme ? 443 : 80));
    }

    private static function heading(string $html): string
    {
        if ('' === $html || !class_exists(\DOMDocument::class)) return '';
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) return '';
            $heading = $document->getElementsByTagName('h1')->item(0);
            return $heading instanceof \DOMNode ? trim(preg_replace('/\s+/u', ' ', $heading->textContent) ?? '') : '';
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

}

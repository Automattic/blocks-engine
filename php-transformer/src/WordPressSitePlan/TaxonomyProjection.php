<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/** Recognizes captured category collections from reciprocal source-document evidence. */
final class TaxonomyProjection
{
    /** @param array<int,array<string,mixed>> $documents @param array<int,array<string,mixed>> $routes @return array{entities:array<int,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>} */
    public static function project(array $documents, array $routes): array
    {
        $routeBySource = array_column($routes, 'target_path', 'source_path');
        $documentsByRoute = array();
        foreach ($documents as $document) {
            $source = $document['source_path'] ?? null;
            $route = is_string($source) ? ($routeBySource[$source] ?? null) : null;
            if (is_string($route)) $documentsByRoute[$route] = $document;
        }
        $linksBySource = array();
        foreach ($documents as $document) {
            $source = $document['source_path'] ?? null;
            if (is_string($source)) $linksBySource[$source] = self::links((string) ($document['html'] ?? ''));
        }

        $entities = array();
        $diagnostics = array();
        foreach ($documents as $archive) {
            $source = $archive['source_path'] ?? null;
            $route = is_string($source) ? ($routeBySource[$source] ?? null) : null;
            if (!is_string($source) || !is_string($route) || !preg_match('~(?:^|/)category/[^/]+$~', $route)) continue;
            $heading = self::heading((string) ($archive['html'] ?? ''));
            $slug = basename($route);
            $listedDocuments = array();
            $listed = array();
            foreach (self::links((string) ($archive['html'] ?? '')) as $link) {
                $listedDocument = $documentsByRoute[$link['href']] ?? null;
                if (!is_array($listedDocument)) continue;
                $listedDocuments[$link['href']] = $listedDocument;
                if ('post' === ($listedDocument['metadata']['post_type'] ?? null)) $listed[$link['href']] = $listedDocument;
            }
            $members = array();
            if ('' !== $heading && '' !== $slug && basename($route) === $slug) {
                foreach ($listed as $articleRoute => $article) {
                    $articleSource = (string) $article['source_path'];
                    foreach ($linksBySource[$articleSource] ?? array() as $link) {
                        if ($route === $link['href'] && $heading === $link['label']) {
                            $members[] = $articleSource;
                            break;
                        }
                    }
                }
            }
            if (count($members) < 2) {
                if (array() !== $listedDocuments) $diagnostics[] = array('code' => 'wordpress_site_plan_taxonomy_archive_unproven', 'severity' => 'info', 'message' => 'A captured category collection lacked matching article return links, source labels, or multiple post members.', 'source_path' => $source, 'source_route' => $route);
                continue;
            }
            sort($members, SORT_STRING);
            $entities[] = array(
                'kind' => 'taxonomy_term', 'taxonomy' => 'category', 'slug' => $slug, 'name' => $heading,
                'membership_source_paths' => $members,
                'archive' => array('source_path' => $source, 'source_route' => $route, 'presentation_markup' => (string) ($archive['block_markup'] ?? ''), 'query' => array('post_type' => 'post', 'taxonomy' => 'category', 'term' => $slug)),
                'evidence' => array('membership' => true, 'name' => true, 'archive' => true),
            );
        }
        return array('entities' => $entities, 'diagnostics' => $diagnostics);
    }

    /** @return array<int,array{href:string,label:string}> */
    private static function links(string $html): array
    {
        if ('' === $html || !class_exists(\DOMDocument::class)) return array();
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) return array();
            $links = array();
            foreach ($document->getElementsByTagName('a') as $anchor) {
                $path = parse_url(trim($anchor->getAttribute('href')), PHP_URL_PATH);
                $label = trim(preg_replace('/\s+/u', ' ', $anchor->textContent) ?? '');
                if (is_string($path) && str_starts_with($path, '/') && '' !== $label) $links[] = array('href' => rtrim($path, '/') ?: '/', 'label' => $label);
            }
            return $links;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
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

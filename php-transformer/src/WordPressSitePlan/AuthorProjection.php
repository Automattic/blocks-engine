<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/** Proves authors from reciprocal bylines or WordPress author archive body classes. */
final class AuthorProjection
{
    private static function hasAuthorBodyClasses(string $html, string $slug): bool
    {
        if ('' === $html || !class_exists(\DOMDocument::class)) return false;
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) return false;
            $body = $document->getElementsByTagName('body')->item(0);
            if (!$body instanceof \DOMElement) return false;
            $classes = preg_split('/[\x20\t\r\n\f]+/', $body->getAttribute('class'), -1, PREG_SPLIT_NO_EMPTY);
            return in_array('author', $classes, true) && in_array('author-' . $slug, $classes, true);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @return array{entities:array,diagnostics:array,pagination_source_paths_by_archive:array} */
    public static function project(array $documents, array $routes, string $sourceOrigin = ''): array
    {
        $routeBySource = array_column($routes, 'target_path', 'source_path');
        $claimsBySource = array();
        foreach ($routes as $route) $claimsBySource[$route['source_path']] = $route['source_claim_path'] ?? $route['target_path'];
        $aliases = ReciprocalArchiveProof::routeLinkAliases($routes);
        $byRoute = array();
        $links = array();
        foreach ($documents as $document) {
            $source = $document['source_path'] ?? null;
            if (!is_string($source)) continue;
            if (isset($routeBySource[$source])) $byRoute[$routeBySource[$source]] = $document;
            $links[$source] = ReciprocalArchiveProof::links((string) ($document['html'] ?? ''), $sourceOrigin);
        }
        $groups = array();
        $diagnostics = array();
        $diagnose = static function (string $code, string $message, string $source, string $route) use (&$diagnostics): void {
            $diagnostics[] = array('code' => 'wordpress_site_plan_author_archive_' . $code, 'severity' => 'info', 'message' => $message, 'source_path' => $source, 'source_route' => $route);
        };
        foreach ($documents as $archive) {
            $source = (string) ($archive['source_path'] ?? '');
            $route = $claimsBySource[$source] ?? '';
            if (!preg_match('~(?:^|/)author/[^/]+$~', $route)) continue;
            $slug = basename($route);
            $name = ReciprocalArchiveProof::heading((string) ($archive['html'] ?? ''));
            $members = array();
            $pagination = array();
            if (preg_match('~^/author/[a-z0-9][a-z0-9-]{0,49}$~', $route) && '' !== $name) {
                // Iterate the source archive itself, not a route-indexed replacement:
                // two captures of the same route must not silently overwrite proof.
                $views = array($source => $archive);
                foreach ($byRoute as $viewRoute => $view) if (preg_match('~^' . preg_quote($route, '~') . '/page/[1-9][0-9]{0,5}$~', $viewRoute)) $views[$view['source_path']] = $view;
                foreach ($views as $viewSource => $view) {
                    if ($name !== ReciprocalArchiveProof::heading((string) ($view['html'] ?? ''))) continue;
                    $hasAuthorBodyClasses = self::hasAuthorBodyClasses((string) ($view['html'] ?? ''), $slug);
                    foreach ($links[$viewSource] ?? array() as $link) {
                        $post = $byRoute[$aliases[$link['href']] ?? $link['href']] ?? null;
                        if (!is_array($post) || 'post' !== ($post['metadata']['post_type'] ?? null)) continue;
                        $postSource = (string) $post['source_path'];
                        if ($hasAuthorBodyClasses) {
                            $members[$postSource] = true;
                            if ($viewSource !== $source) $pagination[$viewSource] = true;
                            continue;
                        }
                        foreach ($links[$postSource] ?? array() as $backlink) {
                            if ($route === ($aliases[$backlink['href']] ?? $backlink['href']) && $name === $backlink['label']) {
                                $members[$postSource] = true;
                                if ($viewSource !== $source) $pagination[$viewSource] = true;
                                break;
                            }
                        }
                    }
                }
            }
            if (count($members) > 10000) $members = array();
            if (array() === $members) $diagnose('unproven', 'The author archive lacks reciprocal byline or WordPress body-class membership proof, or has an unsupported nicename or route.', $source, $route);
            // All captures claim their route leaf, even if one cannot prove membership.
            $groups[$slug][] = array('kind' => 'author', 'slug' => $slug, 'name' => $name, 'membership_source_paths' => array_keys($members), 'archive' => array('source_path' => $source, 'source_route' => $route, 'presentation_markup' => (string) ($archive['block_markup'] ?? ''), 'query' => array('post_type' => 'post', 'author' => $slug)), 'evidence' => array('membership' => true, 'name' => true, 'archive' => true), '_pagination' => array_keys($pagination));
        }
        $candidates = array();
        foreach ($groups as $group) {
            if (count($group) > 1) {
                foreach ($group as $entity) $diagnose('ambiguous_slug', 'Multiple captured author archives claim the same nicename; none is projected.', $entity['archive']['source_path'], $entity['archive']['source_route']);
                continue;
            }
            if (array() !== $group[0]['membership_source_paths']) $candidates[] = $group[0];
        }
        $claims = array();
        foreach ($candidates as $index => $entity) foreach ($entity['membership_source_paths'] as $source) $claims[$source][] = $index;
        $conflicted = array();
        foreach ($claims as $source => $owners) if (count($owners) > 1) {
            $diagnostics[] = array('code' => 'wordpress_site_plan_author_membership_ambiguous', 'severity' => 'info', 'message' => 'A post has reciprocal evidence for multiple authors; those author projections are withheld rather than guessing ownership.', 'source_path' => $source);
            foreach ($owners as $owner) $conflicted[$owner] = true;
        }
        $entities = array();
        $paginationByArchive = array();
        foreach ($candidates as $index => $entity) {
            if (isset($conflicted[$index])) continue;
            if (count($entities) >= 256) {
                $diagnose('unproven', 'The author entity count exceeds the bounded projection vocabulary.', $entity['archive']['source_path'], $entity['archive']['source_route']);
                continue;
            }
            sort($entity['membership_source_paths'], SORT_STRING);
            if (array() !== $entity['_pagination']) $paginationByArchive[$entity['archive']['source_path']] = $entity['_pagination'];
            unset($entity['_pagination']);
            $entities[] = $entity;
        }
        return array('entities' => $entities, 'diagnostics' => $diagnostics, 'pagination_source_paths_by_archive' => $paginationByArchive);
    }
}

<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\DeclaredContent;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlMarkupParser;

/** Recognizes captured category and tag collections from reciprocal links or WordPress body identity. */
final class TaxonomyProjection
{
    private const TERM_ROUTE = '~(?:^|/)(category|categories|tag|tags)/([^/]+(?:/[^/]+)*)$~';

    /**
     * Is this route a category or tag archive? Its listed entries' dates are
     * not its own publication, so it is never a post itself.
     */
    public static function isTermRoute(string $route): bool
    {
        if (!preg_match(self::TERM_ROUTE, $route, $match) || preg_match('~/page/[1-9][0-9]{0,5}$~', $route)) return false;

        return in_array($match[1], array('category', 'categories'), true) || !str_contains($match[2], '/');
    }

    /**
     * Entities in `declared_entities` carry no archive; the caller adds them
     * once the final post set is known.
     *
     * @param array<int,array<string,mixed>> $documents @param array<int,array<string,mixed>> $routes @return array{entities:array<int,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>,pagination_source_paths_by_archive:array<string,array<int,string>>,declared_entities:array<int,array<string,mixed>>}
     */
    public static function project(array $documents, array $routes, string $sourceOrigin = ''): array
    {
        $routeBySource = array_column($routes, 'target_path', 'source_path');
        $declared = self::declaredTerms($documents, $routeBySource);
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

        $siteName = self::sharedTitleSuffix($documents);
        $entities = $declared['entities'];
        $diagnostics = $declared['diagnostics'];
        $corroboratedBySlug = array();
        foreach ($documents as $archive) {
            $source = $archive['source_path'] ?? null;
            $route = is_string($source) ? ($routeBySource[$source] ?? null) : null;
            if (!is_string($source) || !is_string($route) || isset($declared['archive_sources'][$source]) || !self::isTermRoute($route)) continue;
            preg_match(self::TERM_ROUTE, $route, $termMatch);
            $taxonomy = in_array($termMatch[1], array('tag', 'tags'), true) ? 'post_tag' : 'category';
            $slug = basename($route);
            if (!self::supportedTermSpelling($route, $slug)) {
                $diagnostics[] = array('code' => 'wordpress_site_plan_taxonomy_archive_unsupported_spelling', 'severity' => 'info', 'message' => 'A captured taxonomy route or slug spelling is outside the canonical term vocabulary, so the collection stays explicitly unproven.', 'source_path' => $source, 'source_route' => $route);
                continue;
            }
            $classes = DocumentRootContext::bodyClasses($archive);
            $bodyIdentity = in_array('archive', $classes, true) && in_array($termMatch[1], $classes, true) && in_array($termMatch[1] . '-' . $slug, $classes, true);
            $heading = ReciprocalArchiveProof::heading((string) ($archive['html'] ?? ''));
            $listed = array();
            foreach (ReciprocalArchiveProof::links((string) ($archive['html'] ?? ''), $sourceOrigin) as $link) {
                $listedRoute = $routeByLinkPath[$link['href']] ?? $link['href'];
                $listedDocument = $documentsByRoute[$listedRoute] ?? null;
                if (!is_array($listedDocument)) continue;
                if ('post' === ($listedDocument['metadata']['post_type'] ?? null)) $listed[$listedRoute] = $listedDocument;
            }
            // Keep exact-heading proof, but allow a descriptive heading when
            // CollectionPage names corroborate one consistent reciprocal label.
            $name = $heading;
            $labels = array();
            foreach ($listed as $article) foreach ($linksBySource[$article['source_path']] ?? array() as $link) {
                if ($route === ($routeByLinkPath[$link['href']] ?? $link['href'])) $labels[$link['label']] = true;
            }
            // An archive without a heading of its own (its headings title the
            // listed entries) is named by the label when its document title
            // leads with it, as CMS term archives title themselves.
            if (1 === count($labels)) {
                $label = (string) array_key_first($labels);
                if (in_array($label, self::collectionNames((string) ($archive['html'] ?? '')), true) || ('' === $heading && self::titleLeadsWith(self::documentTitle($archive), $label))) $name = $label;
            }
            $members = array();
            $paginationSources = array();
            $archiveDocuments = array($route => $archive);
            foreach ($documentsByRoute as $archiveRoute => $sibling) {
                if (!preg_match('~^' . preg_quote($route, '~') . '/page/[1-9][0-9]{0,5}$~', $archiveRoute)) continue;
                $archiveDocuments[$archiveRoute] = $sibling;
            }
            if ('' !== $name && '' !== $slug && basename($route) === $slug) {
                foreach ($listed as $articleRoute => $article) {
                    $articleSource = (string) $article['source_path'];
                    foreach ($linksBySource[$articleSource] ?? array() as $link) {
                        $linkedRoute = $routeByLinkPath[$link['href']] ?? $link['href'];
                        if ($route === $linkedRoute && $name === $link['label']) {
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
                            if ($route === $backlinkRoute && $name === $backlink['label']) {
                                $members[] = $articleSource;
                                $paginationSources[$siblingSource] = true;
                                break;
                            }
                        }
                    }
                }
            }
            $members = array_values(array_unique($members));
            if ($bodyIdentity) {
                $name = $heading;
                if ('' === $name) {
                    $name = self::documentTitle($archive);
                    if ('' !== $siteName) $name = preg_replace('~ (?:–|\\||-) ' . preg_quote($siteName, '~') . '$~u', '', $name) ?? $name;
                }
            }
            if (array() === $members && (!$bodyIdentity || '' === $name)) {
                $diagnostics[] = array('code' => 'wordpress_site_plan_taxonomy_archive_unproven', 'severity' => 'info', 'message' => 'A captured taxonomy collection lacked matching article return links, source labels, or post members.', 'source_path' => $source, 'source_route' => $route);
                continue;
            }
            // Identity proves the archive and its numbered captures, never their members.
            if ($bodyIdentity) foreach ($archiveDocuments as $archiveRoute => $sibling) {
                if ($archiveRoute !== $route) $paginationSources[(string) $sibling['source_path']] = true;
            }
            $corroboratedBySlug[$taxonomy . ':' . $slug][] = array('source' => $source, 'route' => $route, 'taxonomy' => $taxonomy, 'slug' => $slug, 'heading' => $name, 'members' => $members, 'archive' => $archive, 'pagination_sources' => array_keys($paginationSources), 'parent_route' => 'category' === $taxonomy && str_contains($termMatch[2], '/') ? dirname($route) : null);
        }
        $paginationSourcesByArchive = array();
        $candidatesByRoute = array();
        foreach ($corroboratedBySlug as $key => $group) {
            // A declared term owns its slug; reciprocal-link proof cannot claim it again.
            if (isset($declared['keys'][$key])) continue;
            if (1 < count($group)) {
                foreach ($group as $candidate) $diagnostics[] = array('code' => 'wordpress_site_plan_taxonomy_archive_ambiguous_term', 'severity' => 'info', 'message' => 'More than one captured taxonomy archive claims the same term slug, so none of them can prove ownership of that term.', 'source_path' => $candidate['source'], 'source_route' => $candidate['route']);
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
                'query' => array('post_type' => 'post', 'taxonomy' => $candidate['taxonomy'], 'term' => $candidate['slug']),
            );
            $entity = array(
                'kind' => 'taxonomy_term', 'taxonomy' => $candidate['taxonomy'], 'slug' => $candidate['slug'], 'name' => $candidate['heading'],
                'membership_source_paths' => $candidate['members'],
                'archive' => $archive,
                'evidence' => array('membership' => true, 'name' => true, 'archive' => true),
            );
            if (null !== $parentRoute) $entity['parent'] = basename($parentRoute);
            $entities[] = $entity;
        }
        return array('entities' => $entities, 'diagnostics' => $diagnostics, 'pagination_source_paths_by_archive' => $paginationSourcesByArchive, 'declared_entities' => $declared['unarchived']);
    }

    /**
     * Tags declared by content records (`metadata.declared_content`): posts
     * name their terms, and a declared term archive names the term it lists.
     * The declaration is the proof, so no reciprocal links are required.
     *
     * @param array<int,array<string,mixed>> $documents @param array<string,mixed> $routeBySource
     * @return array{entities:array<int,array<string,mixed>>,unarchived:array<int,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>,archive_sources:array<string,bool>,keys:array<string,bool>}
     */
    private static function declaredTerms(array $documents, array $routeBySource): array
    {
        $members = array();
        $archives = array();
        foreach ($documents as $document) {
            $source = $document['source_path'] ?? null;
            $declared = DeclaredContent::sanitize($document['metadata']['declared_content'] ?? null);
            if (!is_string($source) || null === $declared) continue;
            if (isset($declared['term_archive'])) {
                $route = $routeBySource[$source] ?? null;
                if (is_string($route)) $archives[$declared['term_archive']['name']][] = array('source' => $source, 'route' => $route, 'archive' => $document);
                continue;
            }
            if ('post' !== ($document['metadata']['post_type'] ?? null)) continue;
            foreach ($declared['terms'] ?? array() as $name) $members[$name][] = $source;
        }
        $result = array('entities' => array(), 'unarchived' => array(), 'diagnostics' => array(), 'archive_sources' => array(), 'keys' => array());
        $bySlug = array();
        foreach (array_unique(array_merge(array_map('strval', array_keys($members)), array_map('strval', array_keys($archives)))) as $name) {
            $archive = null;
            $candidates = $archives[$name] ?? array();
            foreach ($candidates as $candidate) $result['archive_sources'][$candidate['source']] = true;
            if (1 < count($candidates)) {
                foreach ($candidates as $candidate) $result['diagnostics'][] = array('code' => 'wordpress_site_plan_taxonomy_archive_ambiguous_term', 'severity' => 'info', 'message' => 'More than one captured taxonomy archive claims the same term slug, so none of them can prove ownership of that term.', 'source_path' => $candidate['source'], 'source_route' => $candidate['route']);
            } elseif (1 === count($candidates)) {
                if (self::supportedTermSpelling($candidates[0]['route'], basename($candidates[0]['route']))) $archive = $candidates[0];
                else $result['diagnostics'][] = array('code' => 'wordpress_site_plan_taxonomy_archive_unsupported_spelling', 'severity' => 'info', 'message' => 'A captured taxonomy route or slug spelling is outside the canonical term vocabulary, so the collection stays explicitly unproven.', 'source_path' => $candidates[0]['source'], 'source_route' => $candidates[0]['route']);
            }
            $termMembers = array_values(array_unique($members[$name] ?? array()));
            sort($termMembers, SORT_STRING);
            $slug = null !== $archive ? basename($archive['route']) : self::termSlug($name);
            $bySlug[$slug][] = array('name' => $name, 'slug' => $slug, 'archive' => $archive, 'members' => $termMembers);
        }
        foreach ($bySlug as $slug => $group) {
            if (1 < count($group)) {
                $result['diagnostics'][] = array('code' => 'wordpress_site_plan_taxonomy_declared_term_ambiguous', 'severity' => 'info', 'message' => 'More than one declared term name resolves to the same term slug, so none of them is projected.', 'source_route' => (string) $slug);
                continue;
            }
            $term = $group[0];
            $result['keys']['post_tag:' . $slug] = true;
            $entity = array('kind' => 'taxonomy_term', 'taxonomy' => 'post_tag', 'slug' => (string) $slug, 'name' => $term['name'], 'membership_source_paths' => $term['members']);
            if (null === $term['archive']) {
                if (array() === $term['members']) continue;
                $result['unarchived'][] = $entity + array('evidence' => array('membership' => true, 'name' => true), 'provenance' => DeclaredContent::PROVENANCE);
                continue;
            }
            $entity['archive'] = array(
                'source_path' => $term['archive']['source'],
                'source_route' => $term['archive']['route'],
                'presentation_markup' => (string) ($term['archive']['archive']['block_markup'] ?? ''),
                'query' => array('post_type' => 'post', 'taxonomy' => 'post_tag', 'term' => (string) $slug),
            );
            $result['entities'][] = $entity + array('evidence' => array('membership' => true, 'name' => true, 'archive' => true), 'provenance' => DeclaredContent::PROVENANCE);
        }
        return $result;
    }

    /** A canonical term slug for a declared name without a captured archive route. */
    private static function termSlug(string $name): string
    {
        $slug = trim(substr(trim(strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name)), '-'), 0, 200), '-');
        return '' === $slug ? 'term-' . substr(hash('sha256', $name), 0, 12) : $slug;
    }

    /** Parent-route sections share the archive term contract, without claiming an archive. */
    public static function sections(array $pages, array $entities, string $sourceOrigin = ''): array
    {
        $sections = array();
        foreach ($pages as $page) {
            if ('post' !== ($page['post_type'] ?? null) || !empty($page['synthetic'])) continue;
            $parent = dirname($page['route']['path']);
            // Calendar permalink parents are core date archives, not categories.
            if (preg_match('~^/[0-9]{4}(?:/(?:0[1-9]|1[0-2])(?:/(?:0[1-9]|[12][0-9]|3[01]))?)?$~', $parent)) continue;
            $sections[$parent][] = $page['source_path'];
        }
        if (count($sections) < 2) return array();
        $result = array();
        foreach ($sections as $route => $members) {
            sort($members, SORT_STRING);
            foreach ($entities as $entity) {
                $existing = $entity['membership_source_paths'];
                sort($existing, SORT_STRING);
                if ('category' === ($entity['taxonomy'] ?? null) && $existing === $members) {
                    $result[$route] = $entity;
                    continue 2;
                }
            }
            $slug = basename($route);
            if (!preg_match('/^[a-z0-9][a-z0-9-]{0,199}$/', $slug)) continue;
            // Never give a different membership the identity of an existing term.
            foreach (array_merge($entities, array_values($result)) as $entity) if ('category' === ($entity['taxonomy'] ?? null) && $slug === $entity['slug']) continue 2;
            $name = '';
            foreach ($pages as $page) if ($route === ($page['route']['path'] ?? null)) $name = ReciprocalArchiveProof::heading((string) ($page['canonical_block_markup'] ?? ''));
            if ('' === $name) foreach ($pages as $page) foreach (ReciprocalArchiveProof::links((string) ($page['canonical_block_markup'] ?? ''), $sourceOrigin) as $link) if (rtrim($link['href'], '/') === $route && '' !== $link['label']) { $name = $link['label']; break 2; }
            if ('' === $name) $name = ucwords(str_replace('-', ' ', $slug));
            $result[$route] = array('kind' => 'taxonomy_term', 'taxonomy' => 'category', 'slug' => $slug, 'name' => $name, 'membership_source_paths' => $members, 'evidence' => array('membership' => true, 'name' => true));
        }
        return $result;
    }

    /** The retained head title is authoritative; source HTML also supports direct projection. */
    private static function documentTitle(array $document): string
    {
        $title = $document['document_metadata']['title'] ?? null;
        if (!is_string($title)) {
            preg_match('~<title\\b[^>]*>(.*?)</title>~is', (string) ($document['html'] ?? ''), $match);
            $title = html_entity_decode(strip_tags($match[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return trim(preg_replace('/\\s+/u', ' ', $title) ?? '');
    }

    private static function titleLeadsWith(string $title, string $label): bool
    {
        return '' !== trim($label) && 1 === preg_match('~^' . preg_quote($label, '~') . '(?:$|\s+[—–|:·•-]\s)~u', $title);
    }

    /** Strip only a site-name suffix corroborated by titles on other documents. */
    private static function sharedTitleSuffix(array $documents): string
    {
        $counts = array();
        foreach ($documents as $document) {
            if (preg_match('~^.+ (?:–|\\||-) ([^|]+)$~u', self::documentTitle($document), $match)) $counts[$match[1]] = ($counts[$match[1]] ?? 0) + 1;
        }
        $shared = array_keys(array_filter($counts, static fn(int $count): bool => $count > 1));
        return 1 === count($shared) ? (string) $shared[0] : '';
    }

    /** Only explicit CollectionPage names can corroborate a descriptive heading. */
    private static function collectionNames(string $html): array
    {
        $names = array();
        $collect = static function (mixed $value) use (&$collect, &$names): void {
            if (!is_array($value)) return;
            $types = array_map(static fn($type): string => is_string($type) ? (string) preg_replace('~^https?://schema\\.org/~', '', $type) : '', (array) ($value['@type'] ?? array()));
            if (in_array('CollectionPage', $types, true) && is_string($value['name'] ?? null)) $names[] = trim(preg_replace('/\s+/u', ' ', $value['name']) ?? '');
            foreach ($value as $child) if (is_array($child)) $collect($child);
        };
        foreach (HtmlMarkupParser::htmlMarkupNodes($html) as $node) {
            if ('rawtext' === ($node['kind'] ?? null) && 'script' === ($node['name'] ?? null) && 'application/ld+json' === strtolower(trim((string) ($node['attributes']['type'] ?? '')))) $collect(json_decode($node['content'], true));
        }
        return array_values(array_unique($names));
    }

    /**
     * Each pagination capture of an archive, keyed by source path, with the
     * archive source that owns it.
     *
     * @param array{entities:array<int,array<string,mixed>>,pagination_source_paths_by_archive:array<string,array<int,string>>} $projection
     * @return array<string,string>
     */
    public static function paginationOwners(array $projection): array
    {
        $owners = array();
        foreach ($projection['entities'] as $taxonomyEntity) {
            $archiveSource = $taxonomyEntity['archive']['source_path'];
            foreach ($projection['pagination_source_paths_by_archive'][$archiveSource] ?? array() as $paginationSource) $owners[$paginationSource] = $archiveSource;
        }
        return $owners;
    }

    /**
     * Probes presentation with the final plan's canonical listing projection.
     * An archive whose probed listing renders no query loop declines its
     * presentation. Entity ownership is already proven independently and
     * survives a declined presentation, including ownership of numbered
     * captures.
     *
     * @param array<string,mixed> $projection
     * @param array<int,array<string,mixed>> $preflightPages
     * @return array<string,mixed>
     */
    public static function withPreflightPresentation(array $projection, array $preflightPages): array
    {
        $preflightBySource = array_column($preflightPages, null, 'source_path');
        $projectedTaxonomyEntities = array();
        foreach ($projection['entities'] as $taxonomyEntity) {
            $archiveSource = $taxonomyEntity['archive']['source_path'];
            if (!str_contains((string) ($preflightBySource[$archiveSource]['canonical_block_markup'] ?? ''), '<!-- wp:query ')) {
                unset($taxonomyEntity['archive']['presentation_markup']);
            }
            $projectedTaxonomyEntities[] = $taxonomyEntity;
        }
        $projection['entities'] = self::retainProvenParents($projectedTaxonomyEntities, $projection['diagnostics']);
        return $projection;
    }

    /**
     * Settles each archive's presentation on its final page. An archive with
     * no page is dropped. One that renders no query loop keeps its captured
     * presentation and static cards, with a warning.
     *
     * @param array<string,mixed> $projection
     * @param array<int,array<string,mixed>> $pages
     * @return array<string,mixed>
     */
    public static function withSettledPresentation(array $projection, array $pages): array
    {
        $projectedTaxonomyEntities = array();
        foreach ($projection['entities'] as $taxonomyEntity) {
            foreach ($pages as $page) if (($page['source_path'] ?? null) === ($taxonomyEntity['archive']['source_path'] ?? null)) {
                if (!str_contains((string) ($page['canonical_block_markup'] ?? ''), '<!-- wp:query ')) {
                    $projection['diagnostics'][] = array('code' => 'wordpress_site_plan_' . ('author' === $taxonomyEntity['kind'] ? 'author' : 'taxonomy') . '_archive_presentation_static', 'severity' => 'warning', 'message' => 'The archive renders its captured presentation and static cards rather than a live query because the listing could not be converted to an inherited native query loop.', 'source_path' => $taxonomyEntity['archive']['source_path'], 'source_route' => $taxonomyEntity['archive']['source_route']);
                }
                if ('' !== trim($page['canonical_block_markup'])) $taxonomyEntity['archive']['presentation_markup'] = $page['canonical_block_markup'];
                else unset($taxonomyEntity['archive']['presentation_markup']);
                $projectedTaxonomyEntities[] = $taxonomyEntity;
                continue 2;
            }
        }
        $projection['entities'] = self::retainProvenParents($projectedTaxonomyEntities, $projection['diagnostics']);
        return $projection;
    }

    /**
     * Gives each archive the markup of the theme template that renders it.
     *
     * @param array<string,mixed> $projection
     * @param array<int,array<string,mixed>> $templates
     * @return array<string,mixed>
     */
    public static function withArchivePresentation(array $projection, array $templates): array
    {
        foreach ($projection['entities'] as &$taxonomyEntity) foreach ($templates as $template) if (isset($taxonomyEntity['archive']) && TemplateSlugs::forArchive($taxonomyEntity) === ($template['slug'] ?? null)) {
            $taxonomyEntity['archive']['presentation_markup'] = $template['canonical_block_markup'];
            continue 2;
        }
        unset($taxonomyEntity);
        return $projection;
    }

    /** Remove descendants if a parent's native query presentation was subsequently declined. */
    public static function retainProvenParents(array $entities, array &$diagnostics): array
    {
        $byTerm = array();
        // Authors share the archive pipeline but have no term hierarchy.
        foreach ($entities as $entity) $byTerm[($entity['taxonomy'] ?? $entity['kind']) . ':' . $entity['slug']] = $entity;
        do {
            $changed = false;
            foreach ($byTerm as $key => $entity) {
                if (!isset($entity['parent']) || isset($byTerm[($entity['taxonomy'] ?? $entity['kind']) . ':' . $entity['parent']])) continue;
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

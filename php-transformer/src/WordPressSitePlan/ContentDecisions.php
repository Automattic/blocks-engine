<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\DeclaredContent;
use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Automattic\BlocksEngine\PhpTransformer\Support\BlockAttributes;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlMarkupParser;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use InvalidArgumentException;

/**
 * Decides what each captured document is as content: a post or a page, when it
 * was published, and the headline it carries. Writes each document's
 * `blocks-engine/content-decision/v1` record.
 *
 * The decision reads the whole site, not one document: explicit declarations
 * win, then structured and visible publication evidence, then listing pages
 * that date the entries they link to, then the site's dominant permalink shape.
 * Date archives stay out of the inference entirely; WordPress owns them.
 */
final class ContentDecisions
{
    /**
     * Article markers alone (no publication date) are weak: page builders stamp
     * Article JSON-LD on ordinary service pages. They only count as publication
     * evidence when the page also looks like it belongs to a blog.
     *
     * @param array<int,array<string,string>> $evidence
     * @param array<string,mixed> $document
     * @param array<string,mixed>|null $parent
     * @return array<int,array<string,string>>
     */
    private static function withoutUnsupportedArticleMarkers(array $evidence, array $document, string $route, ?array $parent): array
    {
        foreach ($evidence as $row) {
            if (isset($row['publication_timestamp']) || !in_array($row['source'] ?? null, array('json-ld:Article', 'json-ld:BlogPosting', 'microdata:itemtype'), true)) {
                return $evidence;
            }
        }
        return self::hasBlogContext($document, $route, $parent) ? $evidence : array();
    }

    /** @param array<string,mixed> $document @param array<string,mixed>|null $parent */
    private static function hasBlogContext(array $document, string $route, ?array $parent): bool
    {
        if (1 === preg_match('~/(?:blog|news|posts?|articles?|journal|stories|insights)(?:/|$)~i', $route)) return true;
        foreach (array_filter(array($document, $parent)) as $candidate) {
            $html = is_string($candidate['html'] ?? null) ? $candidate['html'] : (ArrayField::string($candidate, 'block_markup'));
            foreach (($candidate['document_metadata']['meta'] ?? array()) as $meta) {
                if (is_array($meta) && 'og:type' === strtolower((string) ($meta['property'] ?? $meta['name'] ?? '')) && 'article' === strtolower(trim((string) ($meta['content'] ?? '')))) return true;
            }
            foreach (($candidate['document_metadata']['links'] ?? array()) as $link) {
                $rel = is_array($link) ? preg_split('/\s+/', strtolower((string) ($link['rel'] ?? ''))) : array();
                $type = is_array($link) ? strtolower((string) ($link['type'] ?? '')) : '';
                if (in_array('alternate', $rel, true) && (str_contains($type, 'rss') || str_contains($type, 'atom'))) return true;
                if (in_array('prev', $rel, true) || in_array('next', $rel, true)) return true;
            }
            if (1 === preg_match('~<link\b[^>]*\brel=["\']?(?:prev|next)\b|<link\b[^>]*type=["\']?application/(?:rss|atom)\+xml~i', $html)) return true;
        }
        $html = is_array($parent) ? (is_string($parent['html'] ?? null) ? $parent['html'] : (string) ($parent['block_markup'] ?? '')) : '';
        return preg_match_all('~<article\b~i', $html) >= 2 || 1 === preg_match('~read\s+more|continue\s+reading~i', strip_tags($html));
    }

    /**
     * Route paths a document's links point at, resolved against its own route
     * the way a browser resolves them on the served page.
     *
     * @param array<string,mixed> $document
     * @return list<string>
     */
    private static function documentLinkedRoutes(array $document, string $route): array
    {
        $html = is_string($document['html'] ?? null) ? $document['html'] : (string) ($document['block_markup'] ?? '');
        if (!preg_match_all('~<a\b[^>]*?\bhref\s*=\s*["\']([^"\']*)["\']~i', $html, $matches)) return array();
        $linked = array();
        foreach ($matches[1] as $href) {
            $path = self::hrefRoute((string) $href, $route);
            if (null !== $path) $linked[$path] = true;
        }
        return array_keys($linked);
    }

    /** Route path one href resolves to from the page at $route, or null when it names none. */
    private static function hrefRoute(string $href, string $route): ?string
    {
        $base = '/' === $route ? '/' : $route . '/';
        $href = html_entity_decode((string) preg_replace('/[?#].*$/s', '', trim($href)), ENT_QUOTES | ENT_HTML5);
        if (1 === preg_match('~^[a-z][a-z0-9+.-]*:~i', $href) || str_starts_with($href, '//')) {
            $path = parse_url(str_starts_with($href, '//') ? 'http:' . $href : $href, PHP_URL_PATH);
            if (!is_string($path)) return null;
            $href = '' === $path ? '/' : $path;
        }
        if ('' === $href) return null;
        $path = str_starts_with($href, '/') ? $href : $base . $href;
        $segments = array();
        foreach (explode('/', $path) as $segment) {
            if ('' === $segment || '.' === $segment) continue;
            if ('..' === $segment) { array_pop($segments); continue; }
            $segments[] = $segment;
        }
        if (array() !== $segments && 1 === preg_match('/^index\.html?$/i', (string) end($segments))) array_pop($segments);
        elseif (array() !== $segments) $segments[count($segments) - 1] = (string) preg_replace('/\.html?$/i', '', (string) end($segments));
        return '/' . implode('/', $segments);
    }

    /**
     * Child routes a listing's repeated cards link to, each with the card's
     * single visible date. A card is the smallest element around a link that
     * holds exactly one <time> and links to exactly one child route. The
     * datetime attribute is ignored: templates repeat a placeholder there, so
     * the visible text is the source of truth. A date that does not parse
     * yields null rather than a wrong timestamp.
     *
     * @param array<string,mixed> $document
     * @param array<string,bool> $childRoutes
     * @return array<string,string|null> child route => UTC timestamp
     */
    private static function listingCardDates(array $document, string $route, array $childRoutes): array
    {
        $html = is_string($document['html'] ?? null) ? $document['html'] : (string) ($document['block_markup'] ?? '');
        if ('' === trim($html) || array() === $childRoutes) return array();
        $dom = HtmlSerialization::parseDocument($html);
        $xpath = new \DOMXPath($dom);
        $cards = array();
        foreach ($xpath->query('//a[@href]') ?: array() as $anchor) {
            $target = self::hrefRoute((string) $anchor->getAttribute('href'), $route);
            if (null === $target || !isset($childRoutes[$target])) continue;
            $card = $anchor;
            while ($card instanceof \DOMElement && 0 === ($xpath->query('descendant-or-self::time', $card)->length ?? 0)) $card = $card->parentNode;
            if (!$card instanceof \DOMElement || 'body' === $card->nodeName || 'html' === $card->nodeName) continue;
            $targets = array();
            foreach ($xpath->query('descendant-or-self::a[@href]', $card) ?: array() as $link) {
                $linked = self::hrefRoute((string) $link->getAttribute('href'), $route);
                if (null !== $linked && isset($childRoutes[$linked])) $targets[$linked] = true;
            }
            $times = $xpath->query('descendant-or-self::time', $card);
            if (1 !== count($targets) || 1 !== $times->length) continue;
            $cards[$target][] = PublicationDate::fromVisibleText((string) $times->item(0)->textContent);
        }
        $dates = array();
        foreach ($cards as $target => $found) if (1 === count($found)) $dates[$target] = $found[0];
        return $dates;
    }

    /** @param array<int,array<string,string>> $evidence */
    private static function hasOnlyPublishTimeMeta(array $evidence): bool
    {
        if (array() === $evidence) return false;
        foreach ($evidence as $row) {
            $source = $row['source'] ?? '';
            if (!str_starts_with($source, 'meta:') || 'meta:og:url-dated' === $source || !isset($row['publication_timestamp'])) return false;
        }
        return true;
    }

    /** @param mixed $documents @return array<int,array<string,mixed>> */
    public static function documents(mixed $documents): array
    {
        if (!is_array($documents)) throw new InvalidArgumentException('Compiled site documents must be an array.');
        // WordPress owns year/month/day archives natively. Their visible dates,
        // article metadata and dated paths describe a query, not a publication.
        // Keep the capture in the source catalog, but out of content, route and
        // taxonomy inference entirely (including listing-card date inference).
        $documents = array_values(array_filter($documents, static fn(mixed $document): bool => !is_array($document) || !self::isDateArchive($document)));
        $entryRoot = WordPressSitePlan::entryRootFromDocuments($documents);
        $routes = array();
        $evidenceByIndex = array();
        $localTimesByIndex = array();
        $structuredTypes = array();
        $pageEvidenceByIndex = array();
        $dateSlotsByIndex = array();
        $declaredByIndex = array();
        foreach ($documents as $index => $document) {
            if (!is_array($document) || !WordPressSitePlan::safePath($document['source_path'] ?? null)) throw new InvalidArgumentException('Compiled site document is invalid.');
            $metadata = ArrayField::array($document, 'metadata');
            $declaredByIndex[$index] = DeclaredContent::sanitize($metadata['declared_content'] ?? null);
            $path = is_string($metadata['route_path'] ?? null) && '' !== $metadata['route_path'] ? $metadata['route_path'] : PageRoute::fromSource((string) $document['source_path'], $entryRoot);
            $routes[$index] = '/' === $path ? '/' : '/' . trim($path, '/');
            $structuredTypes[$index] = self::structuredPageTypes($document);
            $localTimesByIndex[$index] = array();
            $dateSlotsByIndex[$index] = array();
            // Repeated article metadata on pagination, and the listed entries'
            // dates on a category or tag archive, are not an independent
            // publication and must not vote in listing or dated-site inference.
            $evidenceByIndex[$index] = null === PageRoute::paginationParent($routes[$index]) && !TaxonomyProjection::isTermRoute($routes[$index]) && !isset($declaredByIndex[$index]['term_archive']) ? self::publicationEvidence($document, $localTimesByIndex[$index], $dateSlotsByIndex[$index]) : array();
            $pageTypes = array_intersect(array('WebPage', 'AboutPage', 'ContactPage', 'CollectionPage'), $structuredTypes[$index]);
            if (!array_intersect(array('Article', 'BlogPosting', 'NewsArticle'), $structuredTypes[$index]) && $pageTypes) {
                $evidenceByIndex[$index] = array();
                $pageEvidenceByIndex[$index] = array_values(array_map(static fn(string $type): array => array('source' => 'json-ld:' . $type), $pageTypes));
            }
        }
        // Unmarked builder dates become evidence only when the same source
        // slot varies across sibling routes. Count routes, not captures, so
        // a lone date or identical template chrome cannot establish a slot.
        $dateSlotsByParent = array();
        foreach ($dateSlotsByIndex as $index => $slots) {
            if (!empty($documents[$index]['entrypoint']) || isset($pageEvidenceByIndex[$index])) continue;
            foreach ($slots as $identity => $slot) $dateSlotsByParent[PageRoute::parent($routes[$index])][$identity][$routes[$index]] = $slot['text'];
        }
        foreach ($dateSlotsByIndex as $index => $slots) {
            if (!empty($documents[$index]['entrypoint']) || isset($pageEvidenceByIndex[$index])) continue;
            foreach ($slots as $identity => $slot) {
                $texts = $dateSlotsByParent[PageRoute::parent($routes[$index])][$identity] ?? array();
                if (count(array_unique($texts)) < 2 || count($evidenceByIndex[$index]) >= 16) continue;
                $row = array('source' => 'html:visible-date', 'publication_timestamp' => $slot['publication_timestamp']);
                if (!in_array($row, $evidenceByIndex[$index], true)) $evidenceByIndex[$index][] = $row;
            }
        }
        $indexByRoute = array_flip($routes);
        foreach ($evidenceByIndex as $index => $evidence) {
            $parentIndex = $indexByRoute[PageRoute::parent($routes[$index])] ?? null;
            $evidenceByIndex[$index] = array_intersect(array('BlogPosting', 'NewsArticle'), $structuredTypes[$index]) ? $evidence : self::withoutUnsupportedArticleMarkers($evidence, $documents[$index], $routes[$index], null === $parentIndex ? null : $documents[$parentIndex]);
        }
        // A listing whose repeated cards each link to one child and carry one
        // visible date dates the children that carry no evidence themselves.
        foreach ($documents as $listingIndex => $listingDocument) {
            $listingRoute = $routes[$listingIndex];
            if ('/' === $listingRoute || null !== PageRoute::paginationParent($listingRoute) || TaxonomyProjection::isTermRoute($listingRoute) || isset($declaredByIndex[$listingIndex]['term_archive'])) continue;
            $children = array();
            foreach ($routes as $childIndex => $childRoute) if ($listingRoute === PageRoute::parent($childRoute) && array() === $evidenceByIndex[$childIndex] && empty($documents[$childIndex]['entrypoint']) && !isset($pageEvidenceByIndex[$childIndex])) $children[$childRoute] = $childIndex;
            $cardDates = self::listingCardDates($listingDocument, $listingRoute, array_fill_keys(array_keys($children), true));
            if (count($cardDates) < 2) continue;
            foreach ($cardDates as $childRoute => $date) $evidenceByIndex[$children[$childRoute]] = array(array_filter(array('source' => 'listing:card-date', 'publication_timestamp' => $date), static fn(mixed $value): bool => null !== $value));
        }
        $datedByParent = array();
        foreach ($evidenceByIndex as $index => $evidence) {
            if (array() === $evidence || !empty($documents[$index]['entrypoint'])) continue;
            $datedByParent[PageRoute::parent($routes[$index])][] = $index;
        }
        $listings = array();
        foreach ($datedByParent as $parent => $indexes) {
            if ('/' !== $parent && count($indexes) >= 2) {
                $listings[$parent] = true;
            }
        }
        $metaOnlyPosts = array();
        $inferredPostCount = 0;
        $datedPostCount = 0;
        foreach ($documents as $index => &$document) {
            $metadata = ArrayField::array($document, 'metadata');
            // CollectionPage is page evidence, not proof that a native archive
            // exists. Taxonomy/author projection below owns archive promotion.
            $frontmatter = ArrayField::array($metadata, 'frontmatter');
            $explicit = null;
            $provenance = null;
            // A declared content record (a content index entry) is the
            // source's own statement of type, slug and dates. A declared term
            // archive is a listing, never content of its own.
            $declared = $declaredByIndex[$index];
            if (null !== $declared) {
                $declaredType = isset($declared['term_archive']) ? 'page' : (is_string($metadata['post_type'] ?? null) && preg_match('/^[a-z0-9_-]{1,20}$/', strtolower($metadata['post_type'])) ? strtolower($metadata['post_type']) : null);
                if (null !== $declaredType) {
                    $explicit = $declaredType;
                    $provenance = DeclaredContent::PROVENANCE;
                }
                $metadata['declared_content'] = $declared;
            }
            foreach (array('post_type', 'type') as $key) {
                if (null !== $explicit) break;
                if (is_string($frontmatter[$key] ?? null) && in_array(strtolower($frontmatter[$key]), array('page', 'post'), true)) {
                    $explicit = strtolower($frontmatter[$key]);
                    $provenance = 'frontmatter:' . $key;
                    break;
                }
            }
            if (null === $explicit && 'metadata:post_type' === ($metadata['post_type_declaration'] ?? null) && is_string($metadata['post_type'] ?? null) && preg_match('/^[a-z0-9_-]{1,20}$/', strtolower($metadata['post_type']))) {
                $explicit = strtolower($metadata['post_type']);
                $provenance = 'metadata:post_type';
            }
            $evidence = $evidenceByIndex[$index];
            $route = $routes[$index];
            $parent = PageRoute::parent($route);
            if (isset($listings[$route])) {
                $evidence = array();
            } elseif (!isset($listings[$parent])) {
                $evidence = array_values(array_filter($evidence, static fn(array $row): bool => 'html:visible-date' !== ($row['source'] ?? null)));
            } elseif (array() !== $evidence) {
                // A listing that links to its entries says which routes it
                // contains. An undated route it leaves out is not one of its
                // posts: as a post it would take the import time as its date
                // and lead the reconstructed Query Loop. A listing that links
                // to none of its children (an uncaptured or stub index) says
                // nothing, so blog context still decides those routes.
                $listingIndex = $indexByRoute[$parent] ?? null;
                if (null !== $listingIndex && !array_filter($evidence, static fn(array $row): bool => isset($row['publication_timestamp']))) {
                    $listed = array_flip(self::documentLinkedRoutes($documents[$listingIndex], $parent));
                    $listsChildren = array() !== array_filter($routes, static fn(string $child): bool => isset($listed[$child]) && $parent === PageRoute::parent($child));
                    if ($listsChildren && !isset($listed[$route])) {
                        $evidence = array();
                    }
                }
            }
            if (!isset($listings[$route]) && isset($listings[$parent]) && array() !== $evidence) {
                $hasListing = false;
                foreach ($evidence as $row) {
                    if ('listing:parent' === ($row['source'] ?? null)) {
                        $hasListing = true;
                    }
                }
                if (!$hasListing && count($evidence) < 16) {
                    $evidence[] = array('source' => 'listing:parent');
                }
            }
            // Page evidence must not participate in publication/listing inference.
            // Attach it after that inference so consumers can honour the page decision.
            $pageEvidence = $pageEvidenceByIndex[$index] ?? array();
            if (array() !== $pageEvidence) $evidence = $pageEvidence;
            if (isset($declared['term_archive'])) {
                $evidence = array();
            } elseif (isset($declared['published']) && null !== ($declaredTimestamp = PublicationDate::normalize($declared['published'], $declaredLocalTime))) {
                $evidence = array(array('source' => DeclaredContent::PROVENANCE, 'publication_timestamp' => $declaredTimestamp));
                if (null !== $declaredLocalTime) $localTimesByIndex[$index][$declaredTimestamp] = $declaredLocalTime;
            }
            if (isset($declared['slug'])) $document['slug'] = $declared['slug'];
            if (isset($declared['updated']) && null !== ($modified = PublicationDate::normalize($declared['updated']))) $document['modified_timestamp'] = $modified;
            if (isset($declared['author'])) $document['author_name'] = $declared['author'];
            $postType = $explicit ?? ((!empty($document['entrypoint']) || array() !== $pageEvidence || array() === $evidence) ? 'page' : 'post');
            if (null === $explicit && 'post' === $postType && null === PageRoute::paginationParent($route)) {
                // Meta-only documents are the ambiguous candidates, not votes
                // against a dated permalink pattern. Stronger undated posts
                // do vote against it, even when listing inference drops a date.
                if (self::hasOnlyPublishTimeMeta($evidenceByIndex[$index])) {
                    $metaOnlyPosts[] = $index;
                } else {
                    ++$inferredPostCount;
                    if (in_array('route:dated', array_column($evidenceByIndex[$index], 'source'), true)) ++$datedPostCount;
                }
            }
            $surface = self::templateSurface($metadata['template_surface'] ?? null, (string) ($document['source_path'] ?? ''));
            if (null !== $surface) {
                $document['template_surface'] = $surface;
                $metadata['template_surface'] = $surface;
            }
            $metadata['post_type'] = $postType;
            $document['metadata'] = $metadata;
            $document['content_decision'] = array_filter(array('schema' => 'blocks-engine/content-decision/v1', 'state' => null !== $explicit ? 'declared' : (array() === $evidence ? 'defaulted' : 'inferred'), 'post_type' => $postType, 'provenance' => $provenance, 'evidence' => $evidence), static fn(mixed $value): bool => null !== $value);
            $timestamp = self::preferredPublicationTimestamp($evidence);
            if (null !== $timestamp) {
                $document['publication_timestamp'] = $timestamp;
                if (isset($localTimesByIndex[$index][$timestamp])) {
                    $document['publication_local_time'] = $localTimesByIndex[$index][$timestamp];
                }
            }
        }
        unset($document);
        // Decide from the complete site's inferred posts, independently of
        // document order. Declarations and stronger publication evidence are
        // never overridden by the site's permalink pattern. The dated pattern
        // must also be the site's dominant post shape: dated posts outnumber
        // the meta-only candidates, so one dated URL on a `/slug/` blog never
        // turns its undated posts into pages.
        if ($datedPostCount > 0 && $datedPostCount === $inferredPostCount && $datedPostCount > count($metaOnlyPosts)) {
            foreach ($metaOnlyPosts as $index) {
                $documents[$index]['metadata']['post_type'] = 'page';
                $documents[$index]['content_decision']['post_type'] = 'page';
                $documents[$index]['content_decision']['evidence'][] = array('source' => 'site:dated-post-routes');
            }
        }
        // Shared H1 text is chrome, even when its wrappers are plain divs.
        // Count routes rather than occurrences so duplicate captures cannot vote.
        $headlineRoutes = array();
        $postHeadlines = array();
        foreach ($documents as $index => $document) {
            $html = (string) ($document['html'] ?? '');
            foreach (self::visibleH1s($html, false) as $heading) {
                $headlineRoutes[mb_strtolower($heading, 'UTF-8')][$routes[$index]] = true;
            }
            if ('post' === ($document['metadata']['post_type'] ?? null)) {
                $headings = self::visibleH1s($html, true);
                if (1 === count($headings)) $postHeadlines[$index] = $headings[0];
            }
        }
        foreach ($documents as $index => &$document) {
            $headline = $postHeadlines[$index] ?? '';
            if ('' === $headline || count($headlineRoutes[mb_strtolower($headline, 'UTF-8')] ?? array()) > 1) continue;
            // Preserve equivalent existing labels (including their casing).
            if ('' !== $headline && $headline !== (string) ($document['title'] ?? '')) $document['title'] = $headline;
        }
        unset($document);
        return $documents;
    }

    /** @return list<string> Visible H1 text, optionally scoped to post content. */
    private static function visibleH1s(string $html, bool $contentOnly): array
    {
        if ('' === trim($html)) return array();
        $dom = HtmlSerialization::parseDocument($html);
        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//*[@hidden or translate(@aria-hidden, "TRUE", "true") = "true"] | //template | //dialog[not(@open)] | //nav | //footer | //aside') ?: array() as $hidden) {
            $hidden->parentNode?->removeChild($hidden);
        }
        $scope = ($contentOnly ? $xpath->query('//main')->item(0) : null) ?? $xpath->query('//body')->item(0);
        if (!$scope instanceof \DOMElement) return array();
        if ($contentOnly) {
            $articles = $xpath->query('.//article', $scope);
            if (false !== $articles && 1 === $articles->length) $scope = $articles->item(0);
        }
        $headings = array();
        foreach ($xpath->query('.//h1', $scope) ?: array() as $heading) {
            $headings[] = trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $heading->textContent)) ?? '');
        }
        return $headings;
    }

    /** @return array<string,mixed>|null */
    private static function templateSurface(mixed $surface, string $sourcePath): ?array
    {
        if (null === $surface) return null;
        if (!is_array($surface) || !TemplateSurfaces::validDeclaration($surface, $sourcePath)) throw new ValidationException('A declared template surface must have coherent versioned identity and declaration provenance.', array('source_path' => $sourcePath, 'document_kind' => 'template_surface', 'declaration_kind' => 'template_surface', 'reason' => 'invalid_template_surface'));
        return $surface;
    }

    /** @param array<string,mixed> $document */
    public static function isDateArchive(array $document): bool
    {
        $classes = DocumentRootContext::bodyClasses($document);
        return in_array('archive', $classes, true) && in_array('date', $classes, true);
    }

    /** @param array<string,mixed> $document @return list<string> */
    private static function structuredPageTypes(array $document): array
    {
        $types = array();
        $collect = static function (mixed $value) use (&$collect, &$types): void {
            if (!is_array($value)) return;
            foreach ((array) ($value['@type'] ?? array()) as $type) {
                if (is_string($type)) $types[] = (string) preg_replace('~^https?://schema\\.org/~', '', $type);
            }
            foreach ($value as $child) if (is_array($child)) $collect($child);
        };
        foreach (HtmlMarkupParser::htmlMarkupNodes((string) ($document['html'] ?? '')) as $node) {
            if ('rawtext' === ($node['kind'] ?? null) && 'script' === ($node['name'] ?? null) && 'application/ld+json' === strtolower(trim((string) ($node['attributes']['type'] ?? '')))) $collect(json_decode($node['content'], true));
        }
        return array_values(array_unique($types));
    }

    /** @param array<string,mixed> $document @param array<string,string> $localTimes Non-zero-offset representations keyed by UTC instant. @return array<int,array<string,string>> */
    private static function publicationEvidence(array $document, array &$localTimes, array &$dateSlots): array
    {
        $html = ArrayField::string($document, 'html');
        if ('' === trim($html) && is_string($document['block_markup'] ?? null)) $html = $document['block_markup'];
        $evidence = array(); $add = static function (array &$rows, string $source, ?string $value = null): void { if (count($rows) >= 16) return; $row = array('source' => $source); if (null !== $value) $row['publication_timestamp'] = $value; $rows[] = $row; };
        // Keep offset candidates separate from evidence: a plain <time> must
        // neither select the publication instant nor affect post inference.
        $timestamp = static function (string $value) use (&$localTimes): ?string {
            $date = PublicationDate::normalize($value, $localTime);
            if (null !== $date && null !== $localTime) $localTimes[$date] ??= $localTime;
            return $date;
        };
        foreach (($document['document_metadata']['meta'] ?? array()) as $meta) if (is_array($meta) && is_string($meta['content'] ?? null) && in_array(strtolower((string) ($meta['property'] ?? $meta['name'] ?? '')), array('article:published_time', 'article:published', 'pubdate', 'publishdate', 'date', 'dc.date.issued', 'dc.date', 'parsely-pub-date', 'releasedate'), true)) if (null !== ($date = $timestamp($meta['content']))) $add($evidence, 'meta:' . strtolower((string) ($meta['property'] ?? $meta['name'])), $date);
        foreach (HtmlMarkupParser::htmlMarkupNodes($html) as $node) if ('tag' === ($node['kind'] ?? null)) { $attributes = $node['attributes']; if ('time' === ($node['name'] ?? null) && is_string($attributes['datetime'] ?? null)) $timestamp(html_entity_decode($attributes['datetime'], ENT_QUOTES | ENT_HTML5, 'UTF-8')); if (preg_match('~\b(?:Article|BlogPosting)\b~', (string) ($attributes['itemtype'] ?? ''))) $add($evidence, 'microdata:itemtype'); if ('datePublished' === ($attributes['itemprop'] ?? null)) foreach (array('datetime', 'content') as $key) if (is_string($attributes[$key] ?? null) && null !== ($date = $timestamp(html_entity_decode($attributes[$key], ENT_QUOTES | ENT_HTML5, 'UTF-8')))) { $add($evidence, 'microdata:datePublished', $date); break; } }
        foreach (HtmlMarkupParser::htmlMarkupNodes($html) as $node) if ('rawtext' === ($node['kind'] ?? null) && 'script' === ($node['name'] ?? null) && 'application/ld+json' === strtolower(trim((string) ($node['attributes']['type'] ?? '')))) foreach (self::jsonLdPublicationEvidence(json_decode($node['content'], true), $timestamp) as $row) $add($evidence, $row['source'], $row['publication_timestamp'] ?? null);
        $route = is_string($document['metadata']['route_path'] ?? null) ? $document['metadata']['route_path'] : PageRoute::fromSource((string) $document['source_path'], WordPressSitePlan::entryRootFromDocuments(array($document)));
        if (preg_match('~/(?:[0-9]{4})/(?:0[1-9]|1[0-2])(?:/|$)~', $route)) $add($evidence, 'route:dated');
        foreach (self::visiblePublicationEvidence($html, $timestamp, $dateSlots) as $row) {
            $add($evidence, $row['source'], $row['publication_timestamp'] ?? null);
        }
        foreach (($document['document_metadata']['meta'] ?? array()) as $meta) {
            if (!is_array($meta) || !is_string($meta['content'] ?? null) || !in_array(strtolower((string) ($meta['property'] ?? $meta['name'] ?? '')), array('og:url', 'twitter:url'), true)) {
                continue;
            }
            $path = parse_url($meta['content'], PHP_URL_PATH);
            if (!is_string($path) || !preg_match('~/(20\d{2})/(0[1-9]|1[0-2])(?:/|$)~', $path, $match)) {
                continue;
            }
            $add($evidence, 'meta:og:url-dated', $timestamp($match[1] . '-' . $match[2] . '-01'));
        }
        $unique = array();
        foreach ($evidence as $row) {
            $unique[$row['source'] . "\n" . ($row['publication_timestamp'] ?? '')] = $row;
        }
        return array_values($unique);
    }

    /** @param array<string,array<string,string>> $dateSlots @return array<int,array<string,string>> */
    private static function visiblePublicationEvidence(string $html, callable $timestamp, array &$dateSlots): array
    {
        $rows = array();
        foreach (self::publicationHtmlFragments($html) as $fragment) {
            foreach (HtmlMarkupParser::htmlMarkupNodes($fragment) as $node) {
                if ('tag' !== ($node['kind'] ?? null) || !is_int($node['inner_offset'] ?? null)) {
                    continue;
                }
                $attributes = ArrayField::array($node, 'attributes');
                $visibleDate = PublicationDate::isVisibleElement((string) ($node['name'] ?? ''), $attributes);
                $identities = array();
                if (in_array($node['name'] ?? '', array('p', 'span', 'div', 'li', 'td', 'mark'), true)) {
                    foreach (array('id', 'data-element-id') as $key) {
                        if (is_string($attributes[$key] ?? null) && '' !== trim($attributes[$key])) $identities[] = $key . ':' . $attributes[$key];
                    }
                }
                if (!$visibleDate && array() === $identities) continue;
                $text = HtmlMarkupParser::innerText($fragment, (string) $node['name'], $node['inner_offset']);
                $timestamp(trim(preg_replace('/\s+/', ' ', $text) ?? $text));
                $parsed = PublicationDate::fromVisibleText($text);
                if (null !== $parsed) {
                    if ($visibleDate) $rows[] = array('source' => 'html:visible-date', 'publication_timestamp' => $parsed);
                    else foreach ($identities as $identity) $dateSlots[$identity] = array('text' => $text, 'publication_timestamp' => $parsed);
                }
            }
        }
        return $rows;
    }

    /** @return array<int,string> */
    private static function publicationHtmlFragments(string $html): array
    {
        $fragments = array($html);
        foreach (HtmlMarkupParser::htmlMarkupNodes($html) as $node) {
            if ('comment' !== ($node['kind'] ?? null)) {
                continue;
            }
            $attributes = BlockAttributes::decodeComment((string) ($node['content'] ?? ''));
            if (!is_array($attributes) || !is_string($attributes['content'] ?? null) || !str_contains($attributes['content'], '<')) {
                continue;
            }
            $fragments[] = $attributes['content'];
        }
        return $fragments;
    }

    /** @param array<int,array<string,string>> $evidence */
    private static function preferredPublicationTimestamp(array $evidence): ?string
    {
        $fallback = null;
        foreach ($evidence as $row) {
            if (!is_string($row['publication_timestamp'] ?? null)) {
                continue;
            }
            if ('meta:og:url-dated' === ($row['source'] ?? null)) {
                $fallback ??= $row['publication_timestamp'];
                continue;
            }
            return $row['publication_timestamp'];
        }
        return $fallback;
    }

    /** @return array<int,array<string,string>> */
    private static function jsonLdPublicationEvidence(mixed $value, callable $timestamp): array
    {
        if (!is_array($value)) return array(); $rows = array();
        if (isset($value['@type'])) { $types = array_map(static fn(mixed $type): mixed => is_string($type) ? preg_replace('~^https?://schema\\.org/~', '', $type) : $type, is_array($value['@type']) ? $value['@type'] : array($value['@type'])); if (array_intersect(array('Article', 'BlogPosting', 'NewsArticle'), $types)) { $row = array('source' => 'json-ld:' . (in_array('BlogPosting', $types, true) ? 'BlogPosting' : (in_array('NewsArticle', $types, true) ? 'NewsArticle' : 'Article'))); if (is_string($value['datePublished'] ?? null) && null !== ($date = $timestamp($value['datePublished']))) $row['publication_timestamp'] = $date; $rows[] = $row; } }
        foreach ($value as $child) if (is_array($child)) $rows = array_merge($rows, self::jsonLdPublicationEvidence($child, $timestamp));
        return $rows;
    }
}

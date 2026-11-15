<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;
use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlTagScanner;
use DOMElement;
use DOMXPath;

/**
 * Reads what a source site calls its pages: the document title with the
 * site-name segment shared across pages removed, and the label the site's
 * own navigation gives each page.
 */
final class DocumentTitles
{
    private const DOCUMENT_TITLE_SEPARATOR = '/\s+[|\x{2013}\x{2014}\x{00B7}\x{2022}:-]\s+/u';

    /**
     * The site-name run a multi-page site repeats in every document title
     * ("About | Example", "Contact | Example" share the trailing "Example";
     * "A | Site | Tagline" pages share the whole trailing "Site | Tagline"
     * run). With it removed, each title names its page the way the site
     * itself does, which is what an owner expects a WordPress page to be
     * called. The run is the longest contiguous leading or trailing sequence
     * of segments a majority of titles share, so a site name that is itself
     * several segments long is stripped whole while page-specific segments
     * stay. Returns no segments when there is no such run, or when all titles
     * are identical (a single-page app that never updates its title). The
     * edge is which end of the title the run was found on. Each distinct
     * title votes once so routes serving a stale title cannot dominate.
     *
     * @param array<int, array<string, mixed>> $files
     * @return array{segments: list<string>, edge: string}
     */
    public function sharedDocumentTitleSegment(array $files): array
    {
        $titles = array();
        foreach ( $files as $file ) {
            if ( 'html' !== ($file['kind'] ?? '') || HtmlFragmentIncludes::isTemplatePartFile($file) ) {
                continue;
            }
            $declarations = HtmlTagScanner::scan((string) ($file['content'] ?? ''), 'title');
            if ( isset($declarations[0]) ) {
                $title = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($declarations[0]['content']), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
                if ( '' !== $title ) {
                    $titles[] = $title;
                }
            }
        }
        if ( count($titles) < 2 || 1 === count(array_unique($titles)) ) {
            return array('segments' => array(), 'edge' => '');
        }
        // A stale title repeated by many routes is one vote, not a site-name
        // run of its own (which could otherwise swallow the actual brand).
        $titles = array_values(array_unique($titles));
        // Only titles that carry a separator can vote: a blog whose posts use
        // bare titles still names its pages "Page | Site", and those bare
        // titles say nothing about the site-name run.
        $separated = count(array_filter($titles, static fn (string $title): bool => 1 === preg_match(self::DOCUMENT_TITLE_SEPARATOR, $title)));
        $majority = max(2, intdiv($separated, 2) + 1);
        $sharedRun = static function (array $titles, bool $leading) use ($majority): array {
            $counts = array();
            foreach ( $titles as $title ) {
                $segments = preg_split(self::DOCUMENT_TITLE_SEPARATOR, $title) ?: array();
                if ( count($segments) < 2 ) {
                    continue;
                }
                for ( $length = 1, $total = count($segments); $length <= $total; ++$length ) {
                    $run = implode("\0", $leading ? array_slice($segments, 0, $length) : array_slice($segments, $length * -1));
                    $counts[$length][$run] = ($counts[$length][$run] ?? 0) + 1;
                }
            }
            for ( $length = count($counts); $length >= 1; --$length ) {
                foreach ( $counts[$length] ?? array() as $run => $count ) {
                    if ( $count >= $majority ) {
                        return explode("\0", (string) $run);
                    }
                }
            }

            return array();
        };
        $leading = $sharedRun($titles, true);
        $trailing = $sharedRun($titles, false);

        return count($trailing) >= count($leading)
            ? array('segments' => $trailing, 'edge' => 'trailing')
            : array('segments' => $leading, 'edge' => 'leading');
    }

    /**
     * The number of title segments at one edge that equal the start or the
     * end of the site-name run, compared without case. 0 when none match or
     * nothing of the title's own would remain.
     *
     * @param list<string> $segments
     * @param list<string> $siteNameSegments
     */
    private static function siteNameEdgeRun(array $segments, array $siteNameSegments, bool $trailing): int
    {
        $fold = static fn (array $values): array => array_map(static fn (string $value): string => mb_strtolower(trim($value)), $values);
        $segments = $fold($segments);
        $siteName = $fold($siteNameSegments);
        $matched = 0;
        while ( count($segments) - $matched > 1 ) {
            $found = 0;
            $max = min(count($siteName), count($segments) - $matched - 1);
            for ( $length = $max; 0 < $length; --$length ) {
                $start = $trailing ? count($segments) - $matched - $length : $matched;
                $run = array_slice($segments, $start, $length);
                if ( $run === array_slice($siteName, 0, $length) || $run === array_slice($siteName, $length * -1) ) {
                    $found = $length;
                    break;
                }
            }
            if ( 0 === $found ) {
                break;
            }
            $matched += $found;
        }
        return $matched;
    }

    /**
     * Section branding needs local evidence: two member routes repeating a
     * segment, or the section index naming itself with that segment in its h1.
     *
     * @return list<string>
     */
    private function sectionTitleBrands(string $section, array $files, array $siteNameSegments, bool $requireMember = false): array
    {
        if ( '/' === $section || '.' === $section ) {
            return array();
        }
        $counts = array();
        $brands = array();
        foreach ( $files as $file ) {
            if ( 'html' !== ($file['kind'] ?? '') || HtmlFragmentIncludes::isTemplatePartFile($file) ) {
                continue;
            }
            $route = ArtifactPath::routePath((string) ($file['path'] ?? ''));
            if ( $route !== $section && dirname($route) !== $section ) {
                continue;
            }
            $html = (string) ($file['content'] ?? '');
            if ( $route === $section ) {
                $visible = self::withoutHiddenSubtrees($html);
                if ( preg_match('/<main\b[^>]*>(.*?)<\/main\s*>/is', $visible, $main) ) {
                    $visible = $main[1];
                }
                preg_match_all('/<h1\b[^>]*>(.*?)<\/h1\s*>/is', $visible, $headings);
                foreach ( $headings[1] as $heading ) {
                    if ( ! $this->headingIsHyperlinkChrome($heading) ) {
                        $brands[] = trim(html_entity_decode(strip_tags($heading), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                        break;
                    }
                }
                continue;
            }
            $title = HtmlTagScanner::scan($html, 'title')[0]['content'] ?? '';
            $segments = preg_split(self::DOCUMENT_TITLE_SEPARATOR, trim(html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?: array();
            $trailing = self::siteNameEdgeRun($segments, $siteNameSegments, true);
            $leading = self::siteNameEdgeRun($segments, $siteNameSegments, false);
            if ( 0 === $trailing && 0 === $leading ) {
                continue;
            }
            $own = $trailing > 0 ? array_slice($segments, 0, -$trailing) : array_slice($segments, $leading);
            // The leading segment belongs to the member, not its section.
            foreach ( array_unique(array_map('mb_strtolower', array_slice($own, 1))) as $segment ) {
                $counts[$segment][$route] = true;
            }
        }
        if ( $requireMember ) {
            $brands = array_values(array_filter($brands, static fn (string $brand): bool => isset($counts[mb_strtolower($brand)])));
        }
        foreach ( $counts as $segment => $routes ) {
            if ( count($routes) >= 2 ) {
                $brands[] = $segment;
            }
        }
        return $brands;
    }

    /**
     * @param list<string> $siteNameSegments The shared site-name run cut from
     *                                       the title edge it sits on.
     * @param string       $siteNameEdge     `leading` or `trailing` when the
     *                                       run was detected; empty otherwise.
     * @param string       $navigationLabel  Navigation label targeting the
     *                                       front page, when the compiled site
     *                                       has one.
     * @param string       $entryDocumentTitle The front page's original head title.
     * @param array<string, int> $titleCounts Route counts for each document title.
     * @param array<int, array<string, mixed>> $files Site files for inbound labels.
     */
    public function titleFromHtml(string $html, string $path, string $entryPath = '', string $entryTitle = '', array $siteNameSegments = array(), string $siteNameEdge = '', string $navigationLabel = '', string $entryDocumentTitle = '', array $titleCounts = array(), array $files = array(), ?string $declaredTitle = null): string
    {
        if (null !== $declaredTitle) return $declaredTitle;

        $normalize = static function (string $titleHtml): string {
            $titleHtml = preg_replace('/<\s*(?:br|\/?\s*(?:div|h[1-6]|p|li|ul|ol|section|article|header|footer|blockquote))\b[^>]*>/i', ' ', $titleHtml) ?? $titleHtml;
            // Inline elements shown as blocks (a `display:block` style or a
            // `block`/`flex`/`grid` utility class) start a new line, but the
            // markup has no whitespace between them. Pad both tag edges.
            $blockDisplay = '/<(span|a|em|strong|b|i|small|mark|label)\b(?=[^>]*(?:style\s*=\s*["\'][^"\']*display\s*:\s*(?:block|flex|grid|list-item|table)|class\s*=\s*["\'](?:[^"\']*\s)?(?:block|flex|grid|d-block)(?:\s[^"\']*)?["\']))[^>]*>/i';
            $stack = array();
            $parts = preg_split('/(<\/?[a-z][^>]*>)/i', $titleHtml, -1, PREG_SPLIT_DELIM_CAPTURE) ?: array($titleHtml);
            $titleHtml = '';
            foreach ( $parts as $part ) {
                if ( preg_match('/^<\/\s*([a-z0-9]+)/i', $part, $closing) ) {
                    $isBlock = array() !== $stack && end($stack)['tag'] === strtolower($closing[1]) ? array_pop($stack)['block'] : false;
                    $titleHtml .= $isBlock ? $part . ' ' : $part;
                } elseif ( preg_match('/^<([a-z0-9]+)\b/i', $part, $opening) && ! preg_match('/\/\s*>$/', $part) && ! in_array(strtolower($opening[1]), array('br', 'img', 'hr', 'input', 'wbr'), true) ) {
                    $isBlock = (bool) preg_match($blockDisplay, $part);
                    $stack[] = array('tag' => strtolower($opening[1]), 'block' => $isBlock);
                    $titleHtml .= $isBlock ? ' ' . $part : $part;
                } else {
                    $titleHtml .= $part;
                }
            }
            $titleHtml = str_replace("\u{00A0}", ' ', html_entity_decode(strip_tags($titleHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            return trim(preg_replace('/\s+/', ' ', $titleHtml) ?? '');
        };

        $titleDeclaration = HtmlTagScanner::scan($html, 'title')[0] ?? null;
        $titleKey = null !== $titleDeclaration ? trim(html_entity_decode(strip_tags($titleDeclaration['content']), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
        // Content shown only on demand (a closed dialog, a hidden panel) never
        // names the page a visitor lands on.
        $visibleHtml = self::withoutTitleHeadingChrome(self::withoutHiddenSubtrees($html));
        if ( $path !== $entryPath && ($titleCounts[$titleKey] ?? 0) > 1 ) {
            // A title served verbatim by multiple routes cannot identify one.
            // The entry page owns its title; the routes that copy it do not.
            // Read headings in content order, not heading-level priority, and
            // never let a shell heading displace the route's inbound label.
            // Without a <main> landmark, read the body minus page chrome.
            $html = $visibleHtml;
            if ( preg_match('/<main\b[^>]*>(.*?)<\/main\s*>/is', $html, $mainMatch) ) {
                $main = $mainMatch[1];
            } else {
                $main = preg_match('/<body\b[^>]*>(.*)<\/body\s*>/is', $html, $bodyMatch) ? $bodyMatch[1] : $html;
                $main = preg_replace('/<(header|nav|footer|aside)\b[^>]*>.*?<\/\1\s*>/is', '', $main) ?? $main;
            }
            if ( preg_match_all('/<h([1-6])\b[^>]*>(.*?)<\/h\1>/is', $main, $headings) ) {
                // The page's own share title names its heading when content
                // order alone would pick a byline or another lead-in heading.
                $shareTitle = self::openGraphTitle($html);
                if ( '' !== $shareTitle ) {
                    foreach ( $headings[2] as $heading ) {
                        if ( $normalize($this->withoutOtherLanguageText($heading, $html)) === $shareTitle ) {
                            return $shareTitle;
                        }
                    }
                }
                foreach ( $headings[2] as $heading ) {
                    if ( ! $this->headingIsHyperlinkChrome($heading) ) {
                        $label = $normalize($this->withoutOtherLanguageText($heading, $html));
                        if ( '' !== $label ) {
                            return $label;
                        }
                    }
                }
            }
            $label = $this->inboundPageLabel($files, $path);
            return '' !== $label ? $label : ArtifactPath::title($path);
        }
        if ( array() !== $siteNameSegments && null !== $titleDeclaration ) {
            $segments = preg_split(self::DOCUMENT_TITLE_SEPARATOR, $normalize($titleDeclaration['content'])) ?: array();
            // A title may spell the site name in another case or carry only
            // part of the shared run ("Page | site name" beside the majority
            // "Page | Site Name | Tagline"), so match the longest edge run that
            // is the start or end of the site-name run, ignoring case.
            $trailingLength = self::siteNameEdgeRun($segments, $siteNameSegments, true);
            $leadingLength = self::siteNameEdgeRun($segments, $siteNameSegments, false);
            $trailingMatch = 0 < $trailingLength;
            $leadingMatch = 0 < $leadingLength;
            $length = $trailingMatch ? $trailingLength : $leadingLength;
            $entryPrefixedBySiteName = '' !== $entryPath && $path === $entryPath && 'trailing' === $siteNameEdge && $leadingMatch && ! $trailingMatch;
            if ( $entryPrefixedBySiteName && '' !== $navigationLabel ) {
                return $navigationLabel;
            }
            if ( ! $entryPrefixedBySiteName && $trailingMatch ) {
                $own = array_slice($segments, 0, count($segments) - $length);
            } elseif ( ! $entryPrefixedBySiteName && $leadingMatch ) {
                $own = array_slice($segments, $leadingLength);
            } else {
                $own = $segments;
            }
            if ( array() !== $own && count($own) < count($segments) ) {
                $route = ArtifactPath::routePath($path);
                $brands = $this->sectionTitleBrands(dirname($route), $files, $siteNameSegments);
                $sectionLength = 0;
                foreach ( $brands as $brand ) {
                    $sectionLength = max($sectionLength, self::siteNameEdgeRun($own, array($brand), true));
                }
                if ( $sectionLength > 0 ) {
                    $own = array_slice($own, 0, -$sectionLength);
                }
                // An index owns its leading name, not the description following
                // it, when member titles corroborate that name as branding.
                $indexBrands = $this->sectionTitleBrands($route, $files, $siteNameSegments, true);
                if ( count($own) > 1 && in_array(mb_strtolower($own[0]), array_map('mb_strtolower', $indexBrands), true) ) {
                    $own = array($own[0]);
                }
                // Keep the source separators between any surviving segments.
                preg_match_all(self::DOCUMENT_TITLE_SEPARATOR, $normalize($titleDeclaration['content']), $separators);
                $offset = $leadingMatch && ! $trailingMatch ? $leadingLength : 0;
                $label = $own[0];
                foreach ( array_slice($own, 1) as $index => $segment ) {
                    $label .= $separators[0][$offset + $index] . $segment;
                }
                return $label;
            }
            // A title may name the site without the shared separator ("About
            // Example" beside "Contact | Example"). The remaining words name
            // the page when the site's own links call the route the same.
            if ( 1 === count($segments) ) {
                foreach ( $siteNameSegments as $siteName ) {
                    $quoted = preg_quote(trim($siteName), '/');
                    $own = trim(preg_replace('/^' . $quoted . '\s+|\s+' . $quoted . '$/iu', '', $segments[0], 1) ?? '');
                    if ( '' !== $own && $own !== $segments[0] && mb_strtolower($own) === mb_strtolower($this->inboundPageLabel($files, $path)) ) {
                        return $own;
                    }
                }
            }
        }

        $contentHeading = '';
        if ( preg_match_all('/<h1\b[^>]*>(.*?)<\/h1>/is', $visibleHtml, $matches) ) {
            foreach ( $matches[1] as $headingHtml ) {
                if ( $this->headingIsHyperlinkChrome($headingHtml) ) {
                    continue;
                }
                $title = $normalize($this->withoutOtherLanguageText($headingHtml, $html));
                if ( '' !== $title ) {
                    $contentHeading = $title;
                    break;
                }
            }
        }
        if ( '' !== $contentHeading ) {
            // Block-display inline children (a styled span on its own line) carry
            // no whitespace in the markup, so flattening the heading to text glues
            // the lines together. When the document title spells the same words
            // with the spacing the author rendered, it is the faithful form.
            if ( null !== $titleDeclaration ) {
                $documentTitle = $normalize($titleDeclaration['content']);
                $compact = static fn (string $text): string => strtolower(preg_replace('/\s+/u', '', $text) ?? $text);
                if ( $documentTitle !== $contentHeading && '' !== $documentTitle && $compact($documentTitle) === $compact($contentHeading) ) {
                    return $documentTitle;
                }
            }
            // With only one page, the document title cannot be separated into
            // site and page names by comparison with other routes. A different
            // hero heading is not evidence of a page name; use the conventional
            // WordPress front-page label instead.
            if ( $path === $entryPath && null !== $titleDeclaration
                && '' !== $normalize($titleDeclaration['content'])
                && $normalize($titleDeclaration['content']) !== $contentHeading
                && 1 === count(array_filter($files, fn (array $file): bool => 'html' === ($file['kind'] ?? '') && ! HtmlFragmentIncludes::isTemplatePartFile($file))) ) {
                return 'Home';
            }
            if ( $path !== $entryPath && $contentHeading === $entryTitle
                && null !== $titleDeclaration ) {
                $documentTitle = $normalize($titleDeclaration['content']);
                if ( '' !== $documentTitle && $documentTitle !== $normalize($entryDocumentTitle) && $documentTitle !== $entryTitle ) {
                    return $documentTitle;
                }
            }
            return $contentHeading;
        }

        if ( null !== $titleDeclaration ) {
            $title = $normalize($titleDeclaration['content']);
            if ( '' !== $title && ( $path === $entryPath || '' === $entryPath || $title !== $entryTitle ) ) {
                return $title;
            }
        }

        return ArtifactPath::title($path);
    }

    /** Remove heading candidates in comments/complementary regions or after the entry body. */
    private static function withoutTitleHeadingChrome(string $html): string
    {
        $dom = HtmlSerialization::parseDocument($html);
        $xpath = new \DOMXPath($dom);
        $entryBody = 'contains(concat(" ", normalize-space(@class), " "), " entry-content ") or contains(concat(" ", normalize-space(@class), " "), " wp-block-post-content ")';
        $chrome = 'self::aside or @id="comments" or contains(@class, "comments") or @role="complementary"';
        $headings = $xpath->query('//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6][ancestor-or-self::*[' . $chrome . '] or preceding::*[' . $entryBody . ']]');
        $nodes = array();
        foreach ($headings ?: array() as $node) $nodes[] = $node;
        if (array() === $nodes) return $html;
        foreach ($nodes as $node) $node->parentNode?->removeChild($node);
        $root = $dom->documentElement;
        return $root instanceof \DOMElement ? ($dom->saveHTML($root) ?: $html) : $html;
    }

    /**
     * The document without subtrees a visitor cannot see at rest: elements
     * with the `hidden` attribute or `aria-hidden="true"`, closed `<dialog>`s,
     * and `<template>` contents.
     */
    private static function withoutHiddenSubtrees(string $html): string
    {
        if ( ! preg_match('/<(?:[a-z][a-z0-9-]*\b[^>]*\s(?:hidden(?=[\s=\/>])|aria-hidden\s*=\s*["\']?true)|template\b|dialog\b)/i', $html) ) {
            return $html;
        }
        $dom = HtmlSerialization::parseDocument($html);
        $xpath = new \DOMXPath($dom);
        $hidden = $xpath->query('//*[@hidden] | //*[translate(@aria-hidden, "TRUE", "true") = "true"] | //template | //dialog[not(@open)]');
        $nodes = array();
        foreach ( $hidden ?: array() as $node ) {
            $nodes[] = $node;
        }
        foreach ( $nodes as $node ) {
            $node->parentNode?->removeChild($node);
        }
        $root = $dom->documentElement;
        return $root instanceof \DOMElement ? ($dom->saveHTML($root) ?: $html) : $html;
    }

    /** The document's Open Graph title, normalized like a heading label. */
    private static function openGraphTitle(string $html): string
    {
        foreach ( HtmlTagScanner::scan($html, 'meta') as $meta ) {
            $attributes = HtmlTagScanner::attributes($meta['tag']);
            if ( 'og:title' !== strtolower($attributes['property'] ?? $attributes['name'] ?? '') ) {
                continue;
            }
            $value = html_entity_decode($attributes['content'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $value)) ?? '');
        }
        return '';
    }

    /**
     * Multilingual headings often carry one `lang`-tagged child per language
     * and show only the active one. Keep the document language's text.
     */
    private function withoutOtherLanguageText(string $headingHtml, string $documentHtml): string
    {
        if ( ! preg_match('/<html\b[^>]*\blang\s*=\s*["\']?([a-z]+)/i', $documentHtml, $documentLang) ) {
            return $headingHtml;
        }
        $language = strtolower($documentLang[1]);
        $filtered = preg_replace_callback(
            '/<([a-z][a-z0-9]*)\b[^>]*\blang\s*=\s*["\']?([a-z]+)[^>]*>.*?<\/\1\s*>/is',
            static fn (array $match): string => strtolower($match[2]) === $language ? $match[0] : '',
            $headingHtml
        );

        return is_string($filtered) && '' !== trim(strip_tags($filtered)) ? $filtered : $headingHtml;
    }

    /** @param array<int, array<string, mixed>> $files */
    private function inboundPageLabel(array $files, string $path): string
    {
        $routeKey = static fn (string $value): string => rtrim(preg_replace('#(?:^|/)index\.html?$#i', '/', $value) ?? $value, '/');
        foreach ( $files as $file ) {
            $sourcePath = (string) ($file['path'] ?? '');
            if ( 'html' !== ($file['kind'] ?? '') || $sourcePath === $path ) {
                continue;
            }
            preg_match_all('/(<a\b[^>]*>)(.*?)<\/a\s*>/is', (string) ($file['content'] ?? ''), $anchors, PREG_SET_ORDER);
            foreach ( $anchors as $anchor ) {
                $href = html_entity_decode(HtmlTagScanner::attribute($anchor[1], 'href'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ( '' === $href || preg_match('#^(?:[a-z][a-z0-9+.-]*:|//|\#)#i', $href) || null !== parse_url($href, PHP_URL_FRAGMENT) ) {
                    continue;
                }
                $target = ArtifactPath::linkedFilePath($href, $sourcePath, $files);
                if ( $routeKey($target) !== $routeKey($path) ) {
                    continue;
                }
                $label = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($anchor[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
                if ( '' !== $label ) {
                    return $label;
                }
            }
        }
        return '';
    }

    private function headingIsHyperlinkChrome(string $headingHtml): bool
    {
        $remaining = trim($headingHtml);
        while ( preg_match('/^<(?:span|mark)\b[^>]*>([\s\S]*)<\/(?:span|mark)>$/is', $remaining, $match) ) {
            $remaining = trim($match[1]);
        }

        return (bool) preg_match('/^<a\b[^>]*>[\s\S]*<\/a>$/is', $remaining);
    }

    /**
     * The first navigation label that targets the front page.
     *
     * These are the same anchors navigation_links are built from: a
     * core/navigation-link comment, else an anchor inside nav. Fragment links
     * name a section of the front page, never the page itself. A label that is
     * only the shared site name is not the page name.
     *
     * @param array<int, array<string, mixed>> $files
     * @param list<string> $siteNameSegments
     */
    public function entryNavigationLabel(array $files, array $siteNameSegments): string
    {
        $siteName = implode(' ', $siteNameSegments);
        $plain = '';
        foreach ( $files as $file ) {
            if ( ! is_array($file) || 'html' !== ($file['kind'] ?? '') ) {
                continue;
            }
            foreach ( $this->navigationAnchors((string) ($file['content'] ?? '')) as $anchor ) {
                if ( '/' !== ArtifactPath::routePath($anchor['href']) ) {
                    continue;
                }
                $label = $anchor['label'];
                if ( '' === $label || ( '' !== $siteName && $label === $siteName ) ) {
                    continue;
                }
                $fragment = parse_url($anchor['href'], PHP_URL_FRAGMENT);
                if ( ( ! is_string($fragment) || '' === $fragment ) && '' === $plain ) {
                    $plain = $label;
                }
                if ( '' !== $plain ) {
                    return $plain;
                }
            }
        }

        return $plain;
    }

    /**
     * @return array<int, array{href: string, label: string}>
     */
    private function navigationAnchors(string $html): array
    {
        if ( '' === trim($html) ) {
            return array();
        }
        $anchors = array();
        if ( preg_match_all('/<!--\s*wp:(?:navigation-link|navigation-submenu)\s+(\{.*?\})\s*\/?-->/s', $html, $matches) ) {
            foreach ( $matches[1] as $json ) {
                $attrs = json_decode((string) $json, true);
                if ( ! is_array($attrs) ) {
                    continue;
                }
                $href = is_string($attrs['url'] ?? null) ? trim($attrs['url']) : '';
                $host = parse_url($href, PHP_URL_HOST);
                $label = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(ArrayField::string($attrs, 'label')), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
                if ( '' !== $href && '' !== $label && ( ! is_string($host) || '' === $host ) ) {
                    $anchors[] = array('href' => $href, 'label' => $label);
                }
            }
        }
        if ( array() !== $anchors ) {
            return $anchors;
        }
        if ( ! preg_match_all('/<nav\b[^>]*>(.*?)<\/nav>/is', $html, $navMatches) ) {
            return array();
        }
        foreach ( $navMatches[1] as $navHtml ) {
            if ( ! preg_match_all('/<a\b([^>]*)>(.*?)<\/a>/is', (string) $navHtml, $anchorMatches, PREG_SET_ORDER) ) {
                continue;
            }
            foreach ( $anchorMatches as $anchorMatch ) {
                $href = HtmlTagScanner::attribute('<a' . $anchorMatch[1] . '>', 'href');
                $host = parse_url($href, PHP_URL_HOST);
                if ( is_string($host) && '' !== $host ) {
                    continue;
                }
                $label = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $anchorMatch[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
                if ( '' === $href || '' === $label ) {
                    continue;
                }
                $anchors[] = array('href' => $href, 'label' => $label);
            }
        }

        return $anchors;
    }
}

<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Support\BlockAttributes;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlMarkupParser;

/**
 * The core post blocks a source block becomes in a listing card or a post
 * template: its title, date and featured image, and the query loop that
 * repeats them. Each builder keeps the source block's own style, classes and
 * visibility.
 */
final class PostBlocks
{
    public static function title(string $slice): string
    {
        // The title links to its post only where the source title is a link:
        // listing cards link it, a post's own heading does not.
        $linked = array() !== array_filter(HtmlMarkupParser::htmlMarkupNodes($slice), static fn(array $node): bool => 'a' === ($node['name'] ?? ''));
        $attrs = $linked ? array('isLink' => true) : array();
        $blockAttrs = self::sourceAttributes($slice);
        if (is_array($blockAttrs['style'] ?? null)) $attrs['style'] = $blockAttrs['style'];
        $level = is_int($blockAttrs['level'] ?? null) ? $blockAttrs['level'] : self::headingLevel($slice);
        if (2 !== $level) {
            $attrs['level'] = $level;
        }
        $className = self::preservedClassName($slice, array('wp-block-heading'));
        if (null !== $className) {
            $attrs['className'] = $className;
        }
        foreach (HtmlMarkupParser::htmlMarkupNodes($slice) as $node) if ('a' === ($node['name'] ?? '') && is_string($node['attributes']['class'] ?? null)) $attrs['metadata']['blocksEngineLinkClass'] = $node['attributes']['class'];
        self::keepBlockVisibility($attrs, $blockAttrs);
        return '<!-- wp:post-title' . (array() === $attrs ? '' : ' ' . BlockAttributes::encode($attrs)) . ' /-->';
    }

    public static function date(string $slice): string
    {
        // A date-bearing leaf can also carry an authored label and icon. Split
        // only the date (including its link), never discard the surrounding copy.
        $labelled = self::labelledDate($slice);
        if (null !== $labelled) return $labelled;
        $attrs = array();
        $blockAttrs = self::sourceAttributes($slice);
        if (true === ($blockAttrs['isLink'] ?? false)) $attrs['isLink'] = true;
        if (is_array($blockAttrs['style'] ?? null)) $attrs['style'] = $blockAttrs['style'];
        $format = PublicationDate::visibleFormat(trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($slice), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? ''));
        if (null !== $format) {
            $attrs['format'] = $format;
        }
        $className = self::preservedClassName($slice, array('wp-block-paragraph'));
        if (null !== $className) {
            $attrs['className'] = $className;
        }
        self::keepBlockVisibility($attrs, $blockAttrs);
        $encoded = array() === $attrs ? '' : ' ' . BlockAttributes::encode($attrs);
        return '<!-- wp:post-date' . $encoded . ' /-->';
    }

    /**
     * Keep inline ancestors balanced on either side of the dynamic date. The
     * outer paragraph becomes a row, so post-date is never nested in RichText.
     */
    private static function labelledDate(string $slice): ?string
    {
        if (!preg_match('/<time\b[^>]*datetime=/i', $slice)) return null;
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">' . $slice, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $times = $document->getElementsByTagName('time');
        if (1 !== $times->length) return null;
        $time = $times->item(0);
        if (!$time instanceof \DOMElement) return null;
        if (ListingExcerptProjection::text($slice) === ListingExcerptProjection::text($document->saveHTML($time) ?: '')) return null;
        $target = $time;
        if ($time->parentNode instanceof \DOMElement && 'a' === $time->parentNode->tagName
            && trim($time->parentNode->textContent) === trim($time->textContent)) $target = $time->parentNode;
        $root = $target;
        while ($root->parentNode instanceof \DOMElement) $root = $root->parentNode;
        $seen = false;
        $split = static function (\DOMNode $node) use (&$split, &$seen, $target, $document): array {
            if ($node === $target) {
                $seen = true;
                return array('', '');
            }
            $ancestor = $target->parentNode;
            while (null !== $ancestor && $ancestor !== $node) $ancestor = $ancestor->parentNode;
            if (null === $ancestor) return $seen ? array('', $document->saveHTML($node) ?: '') : array($document->saveHTML($node) ?: '', '');
            $parts = array('', '');
            foreach ($node->childNodes as $child) {
                $childParts = $split($child);
                foreach (array(0, 1) as $side) $parts[$side] .= $childParts[$side];
            }
            foreach ($parts as &$part) {
                if ('' === trim($part)) continue;
                $clone = $node->cloneNode(false);
                $shell = $document->saveHTML($clone) ?: '';
                $end = strpos($shell, '>');
                $part = (false === $end ? '' : substr($shell, 0, $end + 1)) . $part . '</' . $node->nodeName . '>';
            }
            return $parts;
        };
        // Split children, leaving the source outer box to the group.
        $parts = array('', '');
        foreach ($root->childNodes as $child) {
            $childParts = $split($child);
            foreach (array(0, 1) as $side) $parts[$side] .= $childParts[$side];
        }
        $attrs = self::sourceAttributes($slice);
        $classes = self::preservedClassName($slice, array('wp-block-paragraph'));
        if (null !== $classes) $attrs['className'] = $classes;
        $attrs['layout'] = array('type' => 'flex', 'flexWrap' => 'wrap');
        $paragraph = static fn(string $html): string => '' === trim($html) ? '' : '<!-- wp:paragraph --><p>' . $html . '</p><!-- /wp:paragraph -->';
        $dateClasses = array();
        foreach (array($target, $time) as $element) {
            if ($element->hasAttribute('class')) $dateClasses[] = $element->getAttribute('class');
        }
        $dateSource = array('className' => implode(' ', array_unique($dateClasses)), 'isLink' => 'a' === $target->tagName);
        $date = self::date('<!-- wp:paragraph ' . BlockAttributes::encode($dateSource) . ' -->' . ($document->saveHTML($target) ?: '') . '<!-- /wp:paragraph -->');
        return '<!-- wp:group ' . BlockAttributes::encode($attrs) . ' --><div class="wp-block-group' . (null === $classes ? '' : ' ' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8')) . '">' . $paragraph($parts[0]) . $date . $paragraph($parts[1]) . '</div><!-- /wp:group -->';
    }

    /**
     * A card link to its own post, rendered per post: a button binds its URL
     * to the permalink; a lone text link becomes a read-more link.
     */
    public static function permalink(string $slice): ?string
    {
        $attrs = self::sourceAttributes($slice);
        $name = self::sourceBlockName($slice);
        if ('button' === $name) {
            unset($attrs['url']);
            $attrs['metadata']['bindings']['url'] = array('source' => 'core/post-data', 'args' => array('field' => 'link'));
            $slice = (string) preg_replace('/(<a\b[^>]*?)\s+href="[^"]*"/', '$1', $slice, 1);
            return (string) preg_replace('/^<!-- wp:button(?: \{.*?\})? -->/s', '<!-- wp:button ' . BlockAttributes::encode($attrs) . ' -->', $slice, 1);
        }
        if ('paragraph' !== $name || 1 !== preg_match('/^\s*<p\b[^>]*>\s*<a\b[^>]*>(.*?)<\/a>\s*<\/p>\s*$/s', (string) preg_replace('/^<!-- wp:paragraph(?: \{.*?\})? -->|<!-- \/wp:paragraph -->$/s', '', $slice), $match)) return null;
        $readMore = array('content' => trim($match[1]));
        foreach (array('className', 'style') as $key) if (isset($attrs[$key])) $readMore[$key] = $attrs[$key];
        return '<!-- wp:read-more ' . BlockAttributes::encode($readMore) . ' /-->';
    }

    public static function featuredImage(string $slice): string
    {
        $source = self::sourceAttributes($slice);
        $attrs = array('isLink' => true);
        foreach (array('width', 'height', 'scale', 'aspectRatio', 'style') as $key) {
            if (isset($source[$key]) && (is_string($source[$key]) || is_array($source[$key]))) $attrs[$key] = $source[$key];
        }
        $classes = array_values(array_filter(preg_split('/\s+/', (string) ($source['className'] ?? '')) ?: array(), static fn(string $class): bool => '' !== $class && 'is-resized' !== $class));
        if (array() !== $classes) $attrs['className'] = implode(' ', $classes);
        self::keepBlockVisibility($attrs, $source);
        return '<!-- wp:post-featured-image ' . BlockAttributes::encode($attrs) . ' /-->';
    }

    /**
     * @param array{taxonomy:string,slug:string,order_by:string}|null $relatedTerm
     *        The current post's term a related listing is scoped to, from
     *        ListingQueryLoops::relatedTermScopes().
     */
    public static function query(int $perPage, string $template, bool $inherit = false, ?string $sectionSlug = null, string $sourcePagination = '', ?array $relatedTerm = null): string
    {
        $attrs = array(
            'queryId' => 1,
            'query' => array(
                'perPage' => $perPage,
                'pages' => 0,
                'offset' => 0,
                'postType' => 'post',
                'order' => 'desc',
                'orderBy' => 'date',
                'author' => '',
                'search' => '',
                'exclude' => array(),
                'sticky' => '',
                'inherit' => $inherit,
            ),
            'className' => WordPressSitePlan::LISTING_QUERY_CLASS,
        );
        if (null !== $sectionSlug) $attrs['query']['taxQuery'] = array('category' => array('{{wordpress-site-plan:term:category:' . $sectionSlug . '}}'));
        if (null !== $relatedTerm) {
            $attrs['query']['orderBy'] = $relatedTerm['order_by'];
            $attrs['query']['taxQuery'] = array('include' => array($relatedTerm['taxonomy'] => array('{{wordpress-site-plan:term:' . $relatedTerm['taxonomy'] . ':' . $relatedTerm['slug'] . '}}')));
            $attrs['query']['excludeCurrent'] = true;
        }
        $encoded = BlockAttributes::encode($attrs);
        $pagination = '' !== $sourcePagination ? $sourcePagination : ($inherit
            ? '<!-- wp:query-pagination {"paginationArrow":"arrow","layout":{"type":"flex","justifyContent":"space-between"}} --><!-- wp:query-pagination-previous /--><!-- wp:query-pagination-next /--><!-- /wp:query-pagination --><!-- wp:query-no-results --><!-- wp:paragraph --><p>No posts found.</p><!-- /wp:paragraph --><!-- /wp:query-no-results -->'
            : '');
        return '<!-- wp:query ' . $encoded . ' -->' . '<div class="wp-block-query ' . WordPressSitePlan::LISTING_QUERY_CLASS . '"><!-- wp:post-template -->' . $template . '<!-- /wp:post-template -->' . $pagination . '</div>' . '<!-- /wp:query -->';
    }

    public static function defaultQueryLoop(bool $inherit): string
    {
        return '<!-- wp:query {"queryId":1,"query":{"perPage":10,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":' . ($inherit ? 'true' : 'false') . '},"layout":{"type":"constrained"}} -->' . "\n" . '<div class="wp-block-query"><!-- wp:post-template -->' . "\n" . '<!-- wp:post-title {"isLink":true} /-->' . "\n" . '<!-- wp:post-excerpt /-->' . "\n" . '<!-- wp:post-date {"isLink":true} /-->' . "\n" . '<!-- /wp:post-template -->' . "\n" . '<!-- wp:query-pagination {"paginationArrow":"arrow","layout":{"type":"flex","justifyContent":"space-between"}} -->' . "\n" . '<!-- wp:query-pagination-previous /-->' . "\n" . '<!-- wp:query-pagination-next /-->' . "\n" . '<!-- /wp:query-pagination -->' . "\n" . '<!-- wp:query-no-results -->' . "\n" . '<!-- wp:paragraph -->' . "\n" . '<p>No posts found.</p>' . "\n" . '<!-- /wp:paragraph -->' . "\n" . '<!-- /wp:query-no-results --></div>' . "\n" . '<!-- /wp:query -->';
    }

    /**
     * The single plan-owned image of a card slot, which the post owns as its
     * featured image. Remote or ambiguous images stay out of the handoff.
     *
     * @return array{asset_token:string,alt:string}|null
     */
    public static function leadImage(string $slice): ?array
    {
        $slice .= (string) (self::sourceAttributes($slice)['content'] ?? '');
        $images = array_values(array_filter(HtmlMarkupParser::htmlMarkupNodes($slice), static fn(array $node): bool => 'tag' === ($node['kind'] ?? null) && 'img' === strtolower((string) ($node['name'] ?? ''))));
        if (1 !== count($images)) return null;
        $attributes = is_array($images[0]['attributes'] ?? null) ? $images[0]['attributes'] : array();
        $visibleImage = self::sourceAttributes($slice)['metadata']['blocksEngineVisibleImage']['url'] ?? null;
        $url = is_string($visibleImage) ? $visibleImage : (string) ($attributes['src'] ?? '');
        if (1 !== preg_match('/^' . preg_quote(WordPressSitePlan::TOKEN_PREFIX, '/') . '(asset-[0-9a-f]{16})\}\}$/', $url, $match)) return null;
        return array('asset_token' => $match[1], 'alt' => html_entity_decode((string) ($attributes['alt'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** @return array<string,mixed> */
    public static function sourceAttributes(string $slice): array
    {
        foreach (HtmlMarkupParser::htmlMarkupNodes($slice) as $node) {
            if ('comment' !== ($node['kind'] ?? null)) {
                continue;
            }
            $attributes = BlockAttributes::decodeComment((string) ($node['content'] ?? ''));
            return is_array($attributes) ? $attributes : array();
        }
        return array();
    }

    public static function sourceBlockName(string $slice): string
    {
        return preg_match('/^<!--\s*wp:([a-z0-9-]+)/', ltrim($slice), $match) ? $match[1] : '';
    }

    /**
     * @param array<string,mixed> $attrs
     * @param array<string,mixed> $source
     */
    private static function keepBlockVisibility(array &$attrs, array $source): void
    {
        if (isset($source['metadata']['blockVisibility'])) $attrs['metadata']['blockVisibility'] = $source['metadata']['blockVisibility'];
    }

    private static function headingLevel(string $slice): int
    {
        if ('paragraph' === self::sourceBlockName($slice)) return 0;
        foreach (HtmlMarkupParser::htmlMarkupNodes($slice) as $node) {
            if ('tag' === ($node['kind'] ?? null) && preg_match('/^h([1-6])$/', (string) ($node['name'] ?? ''), $match)) {
                return (int) $match[1];
            }
        }
        return 2;
    }

    /** @param array<int,string> $omit */
    private static function preservedClassName(string $slice, array $omit): ?string
    {
        $blockAttrs = self::sourceAttributes($slice);
        $classes = array();
        if (is_string($blockAttrs['className'] ?? null) && '' !== trim($blockAttrs['className'])) {
            foreach (preg_split('/\s+/', trim($blockAttrs['className'])) ?: array() as $token) {
                $classes[$token] = true;
            }
        }
        foreach (HtmlMarkupParser::htmlMarkupNodes($slice) as $node) {
            if ('tag' !== ($node['kind'] ?? null) || !is_string($node['attributes']['class'] ?? null)) {
                continue;
            }
            $contributed = false;
            foreach (preg_split('/\s+/', trim($node['attributes']['class'])) ?: array() as $token) {
                if ('' === $token || str_starts_with($token, 'wp-block-') || in_array($token, $omit, true) || str_starts_with($token, 'blocks-engine-')) {
                    continue;
                }
                $classes[$token] = true;
                $contributed = true;
            }
            if ($contributed) {
                break;
            }
        }
        if (array() === $classes) {
            return null;
        }
        return implode(' ', array_keys($classes));
    }
}

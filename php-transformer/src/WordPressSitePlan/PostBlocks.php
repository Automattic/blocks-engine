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
        $attrs = array('isLink' => true);
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
        return '<!-- wp:post-title ' . BlockAttributes::encode($attrs) . ' /-->';
    }

    public static function date(string $slice): string
    {
        $attrs = array();
        $blockAttrs = self::sourceAttributes($slice);
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

    public static function query(int $perPage, string $template, bool $inherit = false, ?string $sectionSlug = null): string
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
        $encoded = BlockAttributes::encode($attrs);
        $pagination = $inherit
            ? '<!-- wp:query-pagination {"paginationArrow":"arrow","layout":{"type":"flex","justifyContent":"space-between"}} --><!-- wp:query-pagination-previous /--><!-- wp:query-pagination-next /--><!-- /wp:query-pagination --><!-- wp:query-no-results --><!-- wp:paragraph --><p>No posts found.</p><!-- /wp:paragraph --><!-- /wp:query-no-results -->'
            : '';
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
        if (1 !== preg_match('/^' . preg_quote(WordPressSitePlan::TOKEN_PREFIX, '/') . '(asset-[0-9a-f]{16})\}\}$/', (string) ($attributes['src'] ?? ''), $match)) return null;
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

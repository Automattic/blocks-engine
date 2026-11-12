<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Support\BlockAttributes;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use InvalidArgumentException;

/** Keep linked metadata and plain card labels on their own post through native block bindings. */
final class ListingFieldProjection
{
    /** @param array<string,mixed> $metadata */
    public static function assertMetadata(array $metadata): void
    {
        if (array_key_exists('excerpt', $metadata) && !is_string($metadata['excerpt'])) throw new InvalidArgumentException('Source-backed excerpts must be strings.');
        if (array_key_exists('featured_image', $metadata)) {
            $image = $metadata['featured_image'];
            if (!is_array($image) || !is_string($image['asset_token'] ?? null) || '' === $image['asset_token'] || !is_string($image['alt'] ?? null) || (array_key_exists('target_path', $image) && (!is_string($image['target_path']) || '' === $image['target_path']))) throw new InvalidArgumentException('A featured image handoff names a plan asset token, its alt text and, once resolved, its theme target path.');
        }
        if (!array_key_exists('post_meta', $metadata)) return;
        if (!is_array($metadata['post_meta'])) throw new InvalidArgumentException('Source-backed post metadata must be a string map.');
        foreach ($metadata['post_meta'] as $key => $value) if (!is_string($key) || '' === $key || !is_string($value)) throw new InvalidArgumentException('Source-backed post metadata must be a string map.');
    }

    /**
     * Name each featured-image handoff by its declared theme write, so the
     * consumer attaches the same file the plan materializes.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param array<int,array{token:string,source_path:string,target_path:string}> $tokens
     * @return array<int,array<string,mixed>>
     */
    public static function resolveFeaturedImageTargets(array $pages, array $tokens): array
    {
        $targets = array_column($tokens, 'target_path', 'token');
        foreach ($pages as &$page) {
            $image = $page['metadata']['featured_image'] ?? null;
            if (!is_array($image)) continue;
            $target = $targets[$image['asset_token'] ?? ''] ?? null;
            if (is_string($target)) $page['metadata']['featured_image']['target_path'] = $target;
            else unset($page['metadata']['featured_image']);
        }
        unset($page);
        return $pages;
    }

    public static function isLinkedMetadata(string $markup): bool
    {
        if (str_contains($markup, WordPressSitePlan::TOKEN_PREFIX) || preg_match('/<(?:img|svg|video|audio|iframe|input|button|select|textarea)\b/i', $markup)) return false;
        $document = HtmlSerialization::parseDocument('<body>' . $markup . '</body>');
        $paragraph = $document->getElementsByTagName('p')->item(0);
        if (null === $paragraph) return false;
        $links = 0;
        foreach ($paragraph->childNodes as $child) {
            if ($child instanceof \DOMElement && 'a' === strtolower($child->tagName)) { ++$links; continue; }
            if (!preg_match('/^[\s·|,\/;•—–]*$/u', $child->textContent ?? '')) return false;
        }
        return $links > 0;
    }

    /** @param list<string> $paragraphs */
    public static function content(array $paragraphs): string
    {
        $content = '';
        // Slice the authored inner markup rather than reserializing it: libxml
        // percent-encodes URL attributes on some versions, which would corrupt
        // reference tokens such as image sources.
        foreach ($paragraphs as $markup) {
            if (preg_match('/<p\b[^>]*>(.*)<\/p>/s', $markup, $match)) $content .= $match[1];
        }
        return $content;
    }

    /** @param array<string,mixed> $attrs */
    public static function paragraph(string $source, array $attrs, string $key, string $content): string
    {
        $document = HtmlSerialization::parseDocument('<body>' . $source . '</body>');
        $paragraph = $document->getElementsByTagName('p')->item(0);
        if (null === $paragraph) return $source;
        $marker = 'blocks-engine-listing-bound-meta';
        $attrs['className'] = trim((string) ($attrs['className'] ?? '') . ' ' . $marker);
        $paragraph->setAttribute('class', trim($paragraph->getAttribute('class') . ' ' . $marker));
        $opening = strstr($document->saveHTML($paragraph), '>', true) . '>';
        $attrs['metadata']['bindings']['content'] = array('source' => 'core/post-meta', 'args' => array('key' => $key));
        return '<!-- wp:paragraph ' . BlockAttributes::encode($attrs) . ' -->' . $opening . $content . '</p><!-- /wp:paragraph -->';
    }

    /** @param list<array<string,mixed>> $pages */
    public static function bootstrap(array $pages): string
    {
        $keys = array();
        foreach ($pages as $page) {
            self::assertMetadata($page['metadata']);
            foreach (array_keys($page['metadata']['post_meta'] ?? array()) as $key) $keys[$page['post_type']][$key] = true;
        }
        $lines = array();
        foreach ($keys as $type => $fields) foreach (array_keys($fields) as $key) {
            $lines[] = "add_action( 'init', static function (): void { register_post_meta( " . var_export($type, true) . ', ' . var_export($key, true) . ", array( 'type' => 'string', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => 'wp_kses_post' ) ); } );";
        }
        return implode("\n", $lines);
    }
}

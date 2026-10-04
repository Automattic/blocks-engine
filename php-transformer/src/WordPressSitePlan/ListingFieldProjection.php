<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use DOMDocument;
use InvalidArgumentException;

/** Keep linked card metadata on its own post through native block bindings. */
final class ListingFieldProjection
{
    /** @param array<string,mixed> $metadata */
    public static function assertMetadata(array $metadata): void
    {
        if (array_key_exists('excerpt', $metadata) && !is_string($metadata['excerpt'])) throw new InvalidArgumentException('Source-backed excerpts must be strings.');
        if (!array_key_exists('post_meta', $metadata)) return;
        if (!is_array($metadata['post_meta'])) throw new InvalidArgumentException('Source-backed post metadata must be a string map.');
        foreach ($metadata['post_meta'] as $key => $value) if (!is_string($key) || '' === $key || !is_string($value)) throw new InvalidArgumentException('Source-backed post metadata must be a string map.');
    }

    public static function isLinkedMetadata(string $markup): bool
    {
        if (str_contains($markup, WordPressSitePlan::TOKEN_PREFIX) || preg_match('/<(?:img|svg|video|audio|iframe|input|button|select|textarea)\b/i', $markup)) return false;
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8"><body>' . $markup . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
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
        foreach ($paragraphs as $markup) {
            $document = new DOMDocument();
            $document->loadHTML('<?xml encoding="UTF-8"><body>' . $markup . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
            $paragraph = $document->getElementsByTagName('p')->item(0);
            foreach ($paragraph?->childNodes ?? array() as $child) $content .= $document->saveHTML($child);
        }
        return $content;
    }

    /** @param array<string,mixed> $attrs */
    public static function paragraph(string $source, array $attrs, string $key, string $content): string
    {
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8"><body>' . $source . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
        $paragraph = $document->getElementsByTagName('p')->item(0);
        if (null === $paragraph) return $source;
        $opening = strstr($document->saveHTML($paragraph), '>', true) . '>';
        $attrs['metadata']['bindings']['content'] = array('source' => 'core/post-meta', 'args' => array('key' => $key));
        return '<!-- wp:paragraph ' . json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ' -->' . $opening . $content . '</p><!-- /wp:paragraph -->';
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

<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

final class StyleTagScanner
{
    public static function isCssType(string $type): bool
    {
        $type = strtolower(trim($type));
        return '' === $type || 1 === preg_match("/^text\\/css(?:\\s*;\\s*[!#$%&'*+\\-.^_`|~0-9a-z]+(?:\\s*=\\s*(?:[!#$%&'*+\\-.^_`|~0-9a-z]+|\"(?:[^\"\\\\]|\\\\.)*\"))?)*\\s*$/i", $type);
    }

    /**
     * Does a `<link>`'s `rel` mark it as a stylesheet?
     *
     * `rel` is a space-separated token list, so the token has to be matched on
     * its own boundaries — `rel="preload stylesheet"` is one, `rel="stylesheets"`
     * is not.
     */
    public static function isStylesheetRel(string $rel): bool
    {
        return 1 === preg_match('/(?:^|\s)stylesheet(?:\s|$)/i', $rel);
    }

    /**
     * Every `<link>` tag in a document, with the byte offset it opened at.
     *
     * The `<style>` scan below is careful about tag boundaries and nesting;
     * `<link>` is void, so a tag-level scan is enough. It is here so that callers
     * asking "which stylesheets does this document link" reach for one scanner
     * rather than repeating the pattern, which is how the same document ended up
     * scanned five times with three different readings of `rel`.
     *
     * @return list<array{tag:string,offset:int}>
     */
    public static function scanLinks(string $html): array
    {
        if ( 1 !== preg_match_all('/<link\b[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE) && array() === ($matches[0] ?? array()) ) {
            return array();
        }

        $links = array();
        foreach ( $matches[0] as $match ) {
            $links[] = array( 'tag' => (string) $match[0], 'offset' => (int) $match[1] );
        }

        return $links;
    }

    public static function attribute(string $attributes, string $name): string
    {
        if ( 1 !== preg_match('/(?:^|\\s)' . preg_quote($name, '/') . '\\s*=\\s*(?:"([^"]*)"|\\\'([^\\\']*)\\\'|([^\\s>]+))/i', $attributes, $matches) ) {
            return '';
        }

        return html_entity_decode((string) ($matches[1] ?? $matches[2] ?? $matches[3] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * @return list<array{attributes:string,content:string,offset:int,end_offset:int}>
     */
    public static function scan(string $html): array
    {
        $styles = array();
        $offset = 0;
        $length = strlen($html);

        while ($offset < $length) {
            $open = stripos($html, '<style', $offset);
            if (false === $open) {
                break;
            }
            $boundary = $html[$open + 6] ?? '';
            if ('' !== $boundary && '>' !== $boundary && '/' !== $boundary && !ctype_space($boundary)) {
                $offset = $open + 6;
                continue;
            }
            $openEnd = self::tagEnd($html, $open + 6);
            if (null === $openEnd) {
                break;
            }
            $close = self::closingTag($html, $openEnd + 1);
            if (null === $close) {
                break;
            }

            $styles[] = array(
                'attributes' => substr($html, $open + 6, $openEnd - $open - 6),
                'content' => substr($html, $openEnd + 1, $close['offset'] - $openEnd - 1),
                'offset' => $open,
                'end_offset' => $close['end_offset'],
            );
            $offset = $close['end_offset'];
        }

        return $styles;
    }

    private static function tagEnd(string $html, int $offset): ?int
    {
        $quote = '';
        for ($index = $offset, $length = strlen($html); $index < $length; ++$index) {
            $character = $html[$index];
            if ('' !== $quote) {
                if ($character === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ('"' === $character || "'" === $character) {
                $quote = $character;
                continue;
            }
            if ('>' === $character) {
                return $index;
            }
        }

        return null;
    }

    /** @return array{offset:int,end_offset:int}|null */
    private static function closingTag(string $html, int $offset): ?array
    {
        $length = strlen($html);
        while ($offset < $length) {
            $close = stripos($html, '</style', $offset);
            if (false === $close) {
                return null;
            }
            $boundary = $html[$close + 7] ?? '';
            if ('' !== $boundary && '>' !== $boundary && !ctype_space($boundary)) {
                $offset = $close + 7;
                continue;
            }
            $closeEnd = self::tagEnd($html, $close + 7);
            if (null === $closeEnd) {
                return null;
            }

            return array('offset' => $close, 'end_offset' => $closeEnd + 1);
        }

        return null;
    }
}

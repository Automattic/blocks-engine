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
     * The shared document scan respects comments, raw text and quoted values.
     * It is here so that callers asking "which stylesheets does this document
     * link" reach for one scanner
     * rather than repeating the pattern, which is how the same document ended up
     * scanned five times with three different readings of `rel`.
     *
     * @return list<array{tag:string,offset:int}>
     */
    public static function scanLinks(string $html): array
    {
        $links = array();
        foreach ( HtmlTagScanner::scan($html, 'link') as $link ) {
            $links[] = array( 'tag' => $link['tag'], 'offset' => $link['offset'] );
        }

        return $links;
    }

    public static function attribute(string $attributes, string $name): string
    {
        return HtmlTagScanner::attributes($attributes)[strtolower($name)] ?? '';
    }

    /**
     * @return list<array{attributes:string,content:string,offset:int,end_offset:int}>
     */
    public static function scan(string $html): array
    {
        $styles = array();
        foreach (HtmlTagScanner::scan($html, 'style') as $style) {
            $styles[] = array(
                'attributes' => $style['attributes'],
                'content' => $style['content'],
                'offset' => $style['offset'],
                'end_offset' => $style['end_offset'],
            );
        }

        return $styles;
    }
}

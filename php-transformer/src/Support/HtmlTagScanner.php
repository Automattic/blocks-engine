<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

/**
 * Source-preserving declaration scan, with HTML comment, attribute and raw-text
 * boundaries. This is not a tree builder: offsets and payloads refer to the
 * original bytes, so asset identities never depend on reserialized HTML.
 */
final class HtmlTagScanner
{
    /** Parse a start tag or its attribute text without treating quoted examples as attributes. @return array<string,string> */
    public static function attributes(string $tag): array
    {
        $length = strlen($tag);
        $offset = 0;
        if (str_starts_with(ltrim($tag), '<')) {
            $offset = (int) strpos($tag, '<') + 1;
            while ($offset < $length && ctype_space($tag[$offset])) ++$offset;
            if ($offset < $length && '/' === $tag[$offset]) ++$offset;
            while ($offset < $length && !ctype_space($tag[$offset]) && !in_array($tag[$offset], array('>', '/'), true)) ++$offset;
        }
        $attributes = array();
        while ($offset < $length) {
            while ($offset < $length && ctype_space($tag[$offset])) ++$offset;
            if ($offset >= $length || '>' === $tag[$offset] || '/' === $tag[$offset]) break;
            $start = $offset;
            while ($offset < $length && !ctype_space($tag[$offset]) && !in_array($tag[$offset], array('=', '>', '/', '"', "'", '<'), true)) ++$offset;
            if ($start === $offset) break;
            $name = strtolower(substr($tag, $start, $offset - $start));
            while ($offset < $length && ctype_space($tag[$offset])) ++$offset;
            $value = '';
            if ($offset < $length && '=' === $tag[$offset]) {
                ++$offset;
                while ($offset < $length && ctype_space($tag[$offset])) ++$offset;
                if ($offset >= $length) break;
                if (in_array($tag[$offset], array('"', "'"), true)) {
                    $quote = $tag[$offset++]; $start = $offset;
                    while ($offset < $length && $tag[$offset] !== $quote) ++$offset;
                    if ($offset >= $length) break;
                    $value = substr($tag, $start, $offset - $start); ++$offset;
                } else {
                    $start = $offset;
                    while ($offset < $length && !ctype_space($tag[$offset]) && '>' !== $tag[$offset]) {
                        if (in_array($tag[$offset], array('"', "'", '<'), true)) break 2;
                        ++$offset;
                    }
                    $value = substr($tag, $start, $offset - $start);
                }
            }
            if (!isset($attributes[$name])) $attributes[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $attributes;
    }

    /** @return list<array{tag:string,attributes:string,content:string,offset:int,end_offset:int,placement:string}> */
    public static function scan(string $html, string $name): array
    {
        $tags = array();
        $offset = 0;
        $length = strlen($html);
        $inHead = false;
        $name = strtolower($name);
        while ($offset < $length && false !== ($open = strpos($html, '<', $offset))) {
            if ('<!--' === substr($html, $open, 4)) {
                $end = strpos($html, '-->', $open + 4);
                $offset = false === $end ? $length : $end + 3;
                continue;
            }
            if (in_array($html[$open + 1] ?? '', array('!', '?'), true)) {
                $end = strpos($html, '>', $open + 2);
                $offset = false === $end ? $length : $end + 1;
                continue;
            }
            if (!preg_match('/\G<(\/)?([a-z][^\s\/>]*)(?=[\s\/>])/i', $html, $match, 0, $open)) {
                $offset = $open + 1;
                continue;
            }
            $tagName = strtolower($match[2]);
            $attributeStart = $open + strlen($match[0]);
            $openEnd = self::tagEnd($html, $attributeStart);
            if (null === $openEnd) break;
            $offset = $openEnd + 1;
            if ('/' === $match[1]) {
                if ('head' === $tagName || 'body' === $tagName) $inHead = false;
                continue;
            }
            if ('head' === $tagName) $inHead = true;
            if ('body' === $tagName) $inHead = false;
            $content = '';
            if ('plaintext' === $tagName) break;
            // RCDATA also hides tag examples. Scripting-enabled noscript is raw
            // text, as in the destination that executes materialized scripts.
            if (in_array($tagName, array('script', 'style', 'title', 'textarea', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript'), true)) {
                $close = self::closingTag($html, $offset, $tagName);
                if (null === $close) break;
                $content = substr($html, $offset, $close['offset'] - $offset);
                $offset = $close['end_offset'];
            }
            if ($name === $tagName) $tags[] = array(
                'tag' => substr($html, $open, $openEnd + 1 - $open),
                'attributes' => substr($html, $attributeStart, $openEnd - $attributeStart),
                'content' => $content,
                'offset' => $open,
                'end_offset' => $offset,
                'placement' => $inHead ? 'head' : 'body',
            );
        }
        return $tags;
    }

    private static function tagEnd(string $html, int $offset): ?int
    {
        $quote = '';
        $beforeValue = false;
        $unquotedValue = false;
        for ($index = $offset, $length = strlen($html); $index < $length; ++$index) {
            $character = $html[$index];
            if ('' !== $quote) {
                if ($character === $quote) $quote = '';
            } elseif ('>' === $character) {
                return $index;
            } elseif ($unquotedValue) {
                if (ctype_space($character)) $unquotedValue = false;
            } elseif ($beforeValue) {
                if (ctype_space($character)) continue;
                $beforeValue = false;
                if ('"' === $character || "'" === $character) $quote = $character;
                else $unquotedValue = true;
            } elseif ('=' === $character) {
                $beforeValue = true;
            }
        }
        return null;
    }

    /** @return array{offset:int,end_offset:int}|null */
    private static function closingTag(string $html, int $offset, string $name): ?array
    {
        $prefix = '</' . $name;
        while (false !== ($close = stripos($html, $prefix, $offset))) {
            $boundary = $html[$close + strlen($prefix)] ?? '';
            if ('>' !== $boundary && '/' !== $boundary && !ctype_space($boundary)) {
                $offset = $close + strlen($prefix);
                continue;
            }
            $end = self::tagEnd($html, $close + strlen($prefix));
            return null === $end ? null : array('offset' => $close, 'end_offset' => $end + 1);
        }
        return null;
    }
}

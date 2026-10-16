<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

/** Shared HTML tokenizer for captured source markup. */
final class HtmlMarkupParser
{
    /** @return array<int,array<string,mixed>> */
    public static function htmlMarkupNodes(string $content): array
    {
        $nodes = array(); $length = strlen($content); $offset = 0;
        while ($offset < $length) {
            $start = strpos($content, '<', $offset); if (false === $start) break;
            if (str_starts_with(substr($content, $start), '<!--')) { $end = strpos($content, '-->', $start + 4); if (false === $end) break; $nodes[] = array('kind' => 'comment', 'content' => substr($content, $start + 4, $end - $start - 4)); $offset = $end + 3; continue; }
            if ($start + 1 < $length && '!' === $content[$start + 1]) { if (str_starts_with(substr($content, $start), '<![CDATA[')) { $end = strpos($content, ']]>', $start + 9); $offset = false === $end ? $length : $end + 3; continue; } $cursor = $start + 2; $quote = ''; while ($cursor < $length) { if ('' !== $quote) { if ($quote === $content[$cursor]) $quote = ''; ++$cursor; continue; } if ('"' === $content[$cursor] || "'" === $content[$cursor]) { $quote = $content[$cursor++]; continue; } if ('>' === $content[$cursor++]) break; } $offset = $cursor; continue; }
            $cursor = $start + 1; if ($cursor >= $length || !ctype_alpha($content[$cursor])) { $offset = $cursor; continue; }
            $nameStart = $cursor; while ($cursor < $length && preg_match('/[A-Za-z0-9:-]/', $content[$cursor])) ++$cursor;
            $name = strtolower(substr($content, $nameStart, $cursor - $nameStart)); $attributes = array();
            while ($cursor < $length) {
                while ($cursor < $length && ctype_space($content[$cursor])) ++$cursor;
                if ($cursor >= $length) break;
                if ('>' === $content[$cursor] || ('/' === $content[$cursor] && $cursor + 1 < $length && '>' === $content[$cursor + 1])) { $selfClosing = '>' !== $content[$cursor]; $cursor += $selfClosing ? 2 : 1; $tag = array('kind' => 'tag', 'name' => $name, 'attributes' => $attributes); if (!$selfClosing) $tag['inner_offset'] = $cursor; $nodes[] = $tag; if ('style' === $name) { $closing = self::rawTextEnd($content, $name, $cursor); if (null !== $closing) { $nodes[] = array('kind' => 'style', 'css' => substr($content, $cursor, $closing[0] - $cursor)); $offset = $closing[1]; } else { $nodes[] = array('kind' => 'style', 'css' => substr($content, $cursor)); $offset = $length; } continue 2; } if ('plaintext' === $name) { $offset = $length; continue 2; } if (in_array($name, array('script', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript'), true)) { $closing = self::rawTextEnd($content, $name, $cursor); if ('script' === $name && null !== $closing) $nodes[] = array('kind' => 'rawtext', 'name' => $name, 'attributes' => $attributes, 'content' => substr($content, $cursor, $closing[0] - $cursor)); $offset = null === $closing ? $length : $closing[1]; continue 2; } $offset = $cursor; continue 2; }
                $attributeStart = $cursor; while ($cursor < $length && !ctype_space($content[$cursor]) && !str_contains('=/>', $content[$cursor])) ++$cursor;
                if ($attributeStart === $cursor) { ++$cursor; continue; }
                $attribute = strtolower(substr($content, $attributeStart, $cursor - $attributeStart)); while ($cursor < $length && ctype_space($content[$cursor])) ++$cursor;
                if ($cursor >= $length || '=' !== $content[$cursor]) { if (!array_key_exists($attribute, $attributes)) $attributes[$attribute] = ''; continue; }
                ++$cursor; while ($cursor < $length && ctype_space($content[$cursor])) ++$cursor;
                if ($cursor >= $length) { if (!array_key_exists($attribute, $attributes)) $attributes[$attribute] = ''; break; }
                if ('"' === $content[$cursor] || "'" === $content[$cursor]) { $quote = $content[$cursor++]; $valueStart = $cursor; while ($cursor < $length && $quote !== $content[$cursor]) ++$cursor; if (!array_key_exists($attribute, $attributes)) $attributes[$attribute] = substr($content, $valueStart, $cursor - $valueStart); if ($cursor < $length) ++$cursor; continue; }
                $valueStart = $cursor; while ($cursor < $length && !ctype_space($content[$cursor]) && '>' !== $content[$cursor]) ++$cursor; if (!array_key_exists($attribute, $attributes)) $attributes[$attribute] = substr($content, $valueStart, $cursor - $valueStart);
            }
            $nodes[] = array('kind' => 'tag', 'name' => $name, 'attributes' => $attributes); $offset = $cursor;
        }
        return $nodes;
    }
    /** @return array{0:int,1:int}|null */
    public static function rawTextEnd(string $content, string $name, int $offset): ?array
    {
        if (!preg_match('~</' . preg_quote($name, '~') . '(?=[\s/>])[^>]*>~i', $content, $match, PREG_OFFSET_CAPTURE, $offset)) return null;
        return array($match[0][1], $match[0][1] + strlen($match[0][0]));
    }
}

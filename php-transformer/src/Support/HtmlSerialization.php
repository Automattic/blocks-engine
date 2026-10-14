<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

/** Removes the UTF-8 parsing marker serialized by DOMDocument::loadHTML(). */
final class HtmlSerialization
{
    public static function normalizeSerializedHtml(string $html): string
    {
        $html = preg_replace('/^(?:<\?xml encoding="UTF-8" ?\??>|<!--[?\/]xml encoding="UTF-8" ?-->)/i', '', $html) ?? $html;
        $html = preg_replace('/<!--[?\/]xml encoding="UTF-8" ?-->/', '<?xml encoding="UTF-8">', $html) ?? $html;
        if (defined('LIBXML_VERSION') && LIBXML_VERSION >= 21400) {
            $html = self::canonicalizeAttributeQuotes($html);
            $html = self::normalizeHtml5VoidSerialization($html);
            $html = self::canonicalizeEntityOutput($html);
        }
        return $html;
    }

    public static function normalizeHtml5VoidSerialization(string $html): string
    {
        return preg_replace_callback('/<(source|track|wbr)\b([^>]*)>(?!\s*<\/\1\s*>)/i', static function (array $element): string {
            $tag = strtolower($element[1]);
            $attributes = $element[2];
            if (str_ends_with(rtrim($attributes), '/')) return $element[0];
            return '<' . $tag . $attributes . '></' . $tag . '>';
        }, $html) ?? $html;
    }

    public static function canonicalizeEntityOutput(string $html): string
    {
        return str_replace('²', '&sup2;', $html);
    }

    public static function canonicalizeAttributeQuotes(string $html): string
    {
        if (!str_contains($html, '&quot;') || !preg_match('/<[a-zA-Z][a-zA-Z0-9:-]*(?:\s|\/?>)/', $html)) return $html;
        return preg_replace_callback('/([a-zA-Z_:][a-zA-Z0-9_.:-]*)="([^"]*&quot;[^"]*)"/', static fn (array $attribute): string => $attribute[1] . "='" . str_replace('&quot;', '"', $attribute[2]) . "'", $html) ?? $html;
    }

    public static function normalizeCurrentSerialization(string $html): string
    {
        return defined('LIBXML_VERSION') && LIBXML_VERSION >= 21400 ? self::normalizeSerializedHtml($html) : $html;
    }

}

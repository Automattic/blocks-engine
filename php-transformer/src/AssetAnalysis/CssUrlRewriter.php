<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\AssetAnalysis;

/** Rewrites bounded CSS url() values without accepting malformed functions. */
final class CssUrlRewriter
{
    /** @param callable(string):string $replace */
    public static function rewrite(string $content, callable $replace): string
    {
        $offset = 0;
        while (false !== ($start = stripos($content, 'url(', $offset))) {
            if ($start > 0 && preg_match('/[A-Za-z0-9_-]/', $content[$start - 1])) {
                $offset = $start + 4;
                continue;
            }
            $cursor = $start + 4;
            while (isset($content[$cursor]) && str_contains(" \t\r\n\f", $content[$cursor])) ++$cursor;
            $quote = self::quoteAt($content, $cursor);
            $quoted = null !== $quote;
            $valueStart = $quoted ? $cursor + $quote[1] : $cursor;
            $cursor = $valueStart;
            $value = '';
            $valid = false;
            while (isset($content[$cursor])) {
                $character = $content[$cursor];
                $closingQuote = $quoted ? self::quoteAt($content, $cursor) : null;
                if (null !== $closingQuote && $closingQuote[0] === $quote[0]) {
                    $cursor += $closingQuote[1];
                    while (isset($content[$cursor]) && str_contains(" \t\r\n\f", $content[$cursor])) ++$cursor;
                    $valid = ')' === ($content[$cursor] ?? '');
                    break;
                }
                if ('\\' === $character && isset($content[$cursor + 1])) {
                    $value .= $character . $content[++$cursor];
                    ++$cursor;
                    continue;
                }
                if (!$quoted && ')' === $character) {
                    $valid = true;
                    break;
                }
                if (!$quoted && (str_contains(" \t\r\n\f\"'(", $character) || ord($character) < 0x20)) break;
                $value .= $character;
                ++$cursor;
            }
            if (!$valid || '' === $value) {
                $offset = $start + 4;
                continue;
            }
            $reference = self::unescape($value);
            $replacement = $replace($reference);
            if ($replacement !== $reference) {
                $content = substr($content, 0, $valueStart) . $replacement . substr($content, $valueStart + strlen($value));
                $cursor += strlen($replacement) - strlen($value);
            }
            $offset = $cursor + 1;
        }
        return $content;
    }

    private static function unescape(string $value): string
    {
        return preg_replace_callback('/\\\\([0-9a-fA-F]{1,6}\s?|.)/s', static function (array $match): string {
            $escape = $match[1];
            if (preg_match('/^[0-9a-fA-F]{1,6}\s?$/', $escape)) {
                return html_entity_decode('&#x' . trim($escape) . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            return $escape;
        }, $value) ?? $value;
    }

    /** @return array{string,int}|null */
    private static function quoteAt(string $content, int $offset): ?array
    {
        $character = $content[$offset] ?? '';
        if ('"' === $character || "'" === $character) return array($character, 1);
        if (preg_match('/\A\\\\u0022/i', substr($content, $offset, 6), $match)) return array('"', strlen($match[0]));
        if (!preg_match('/\A(?:&|\\\\u0026)(?:[A-Za-z][A-Za-z0-9]{0,30}|#[0-9]{1,8}|#x[0-9A-Fa-f]{1,8});/i', substr($content, $offset, 46), $match)) return null;
        $entity = preg_replace('/\A\\\\u0026/i', '&', $match[0]) ?? $match[0];
        $character = html_entity_decode($entity, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return '"' === $character || "'" === $character ? array($character, strlen($match[0])) : null;
    }
}

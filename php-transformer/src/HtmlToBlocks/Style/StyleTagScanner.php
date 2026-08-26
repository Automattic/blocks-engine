<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

/**
 * Linear extraction of `<style>` blocks (and stylesheet `<link>` tags) from HTML.
 *
 * `preg_match_all('@<style\b([^>]*)>(.*?)</style>@is', ...)` exhausts PCRE's
 * backtracking budget (pcre.backtrack_limit, 1,000,000 by default) once a
 * single style body approaches ~1MB and then returns `false`, which call sites
 * cannot distinguish from "no matches" — so every author stylesheet on the
 * page is silently dropped. Captured pages routinely carry multi-megabyte
 * inline stylesheets, so extraction must not depend on PCRE limits.
 *
 * This scanner walks the document once with `stripos()`/`strpos()` and
 * preserves the regex's semantics for well-formed input: case-insensitive
 * tags, attributes read up to the first `>`, verbatim body capture, and an
 * unclosed `<style>` yields no match.
 *
 * @internal Shared style-tag extraction for compiler and transformer paths.
 */
final class StyleTagScanner
{
    /**
     * Every `<style>` block in document order. Bodies are verbatim; callers
     * own trimming and type filtering.
     *
     * @return list<array{attributes: string, css: string}>
     */
    public static function styleBlocks(string $html): array
    {
        $blocks = array();
        foreach ( self::styleBlockSpans($html) as $span ) {
            $blocks[] = array( 'attributes' => $span['attributes'], 'css' => $span['css'] );
        }

        return $blocks;
    }

    /**
     * Every `<style>` block and `<link>` tag in interleaved document order,
     * preserving the cascade order of inline and linked stylesheets. As with
     * the regex alternation this replaces, a `<link>` inside a style element
     * is not emitted.
     *
     * @return list<array{kind: 'style', attributes: string, css: string}|array{kind: 'link', markup: string}>
     */
    public static function styleAndLinkTags(string $html): array
    {
        $spans = self::styleBlockSpans($html);
        $entries = array();
        foreach ( $spans as $span ) {
            $entries[] = array(
                'offset' => $span['start'],
                'token' => array( 'kind' => 'style', 'attributes' => $span['attributes'], 'css' => $span['css'] ),
            );
        }
        if ( preg_match_all('/<link\b[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE) ) {
            $spanIndex = 0;
            $spanCount = count($spans);
            foreach ( $matches[0] as $match ) {
                $offset = (int) $match[1];
                while ( $spanIndex < $spanCount && $spans[$spanIndex]['end'] <= $offset ) {
                    ++$spanIndex;
                }
                if ( $spanIndex < $spanCount && $spans[$spanIndex]['start'] <= $offset ) {
                    continue;
                }
                $entries[] = array( 'offset' => $offset, 'token' => array( 'kind' => 'link', 'markup' => (string) $match[0] ) );
            }
        }
        usort($entries, static fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);

        return array_column($entries, 'token');
    }

    /**
     * @return list<array{start: int, end: int, attributes: string, css: string}>
     */
    private static function styleBlockSpans(string $html): array
    {
        $spans = array();
        $offset = 0;
        $length = strlen($html);
        while ( $offset < $length && false !== ( $start = stripos($html, '<style', $offset) ) ) {
            $openEnd = strpos($html, '>', $start + 6);
            if ( false === $openEnd ) {
                break;
            }
            $attributes = substr($html, $start + 6, $openEnd - $start - 6);
            if ( '' !== $attributes && 1 !== preg_match('/^[\s\/]/', $attributes) ) {
                // A longer tag name (e.g. a <style-guide> custom element), not a style boundary.
                $offset = $start + 6;
                continue;
            }
            $close = stripos($html, '</style>', $openEnd + 1);
            if ( false === $close ) {
                break;
            }
            $spans[] = array(
                'start' => $start,
                'end' => $close + 8,
                'attributes' => rtrim($attributes, '/'),
                'css' => substr($html, $openEnd + 1, $close - $openEnd - 1),
            );
            $offset = $close + 8;
        }

        return $spans;
    }
}

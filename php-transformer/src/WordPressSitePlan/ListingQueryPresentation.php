<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSyntaxScanner;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;

/** Replay source child rules across native Query Loop transport wrappers. */
final class ListingQueryPresentation
{
    /** Zero-weight route gate added by route-variant scoping (`be-r-…`/`be-rs-…` root classes). */
    private const ROUTE_SCOPE = ':where\\(:root(?:\\.be-rs?(?:-[0-9a-f]+)?(?::not\\((?:\\.be-rs?-[0-9a-f]+,?)+\\))?|:(?:is|not)\\((?:\\.be-rs?-[0-9a-f]+,?)+\\))\\)';

    /** @param list<array<string,mixed>> $assets @param list<string> $containers @return list<array<string,mixed>> */
    public static function project(array $assets, array $containers): array
    {
        if (array() === $containers) return $assets;
        $elements = array();
        foreach (array_unique($containers) as $markup) {
            $document = HtmlSerialization::parseDocument('<body>' . $markup . '</body>');
            foreach ($document->getElementsByTagName('div') as $query) {
                if (!in_array(WordPressSitePlan::LISTING_QUERY_CLASS, preg_split('/\s+/', $query->getAttribute('class')) ?: array(), true)) continue;
                foreach ($query->childNodes as $root) {
                    if (!$root instanceof \DOMElement || !$query->parentNode instanceof \DOMElement) continue;
                    $elements[] = array('container' => $query->parentNode, 'root' => $root->tagName);
                    break;
                }
            }
        }
        $transformer = new CssStylesheetTransformer();
        foreach ($assets as &$asset) {
            if ('css' !== ($asset['kind'] ?? '') || !is_string($asset['content'] ?? null)) continue;
            $content = $transformer->transformStyleRules($asset['content'], static function (string $prelude, string $body) use ($elements): string {
                $original = $prelude . '{' . $body . '}';
                $aliases = array();
                foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
                    $selector = trim(preg_replace('~/\*.*?\*/~s', '', $selector) ?? $selector);
                    // Route scoping runs at compile time, before Query Loops
                    // exist: carry its ancestor gate onto the alias.
                    $scope = '';
                    if (preg_match('/^(' . self::ROUTE_SCOPE . ')\s+/', $selector, $gate)) {
                        $scope = $gate[1] . ' ';
                        $selector = substr($selector, strlen($gate[0]));
                    }
                    $zero = false;
                    $suffix = '';
                    if (str_starts_with($selector, ':where(')) {
                        $state = CssSyntaxScanner::state();
                        $state['parens'] = 1;
                        for ($offset = 7; $offset < strlen($selector);) {
                            $next = CssSyntaxScanner::consume($selector, $offset, $state);
                            if (null === $next) break;
                            if (0 === $state['parens']) {
                                $suffix = substr($selector, $next);
                                $selector = substr($selector, 7, $offset - 7);
                                $zero = true;
                                break;
                            }
                            $offset = $next;
                        }
                        if (!$zero || !preg_match('/^(?::not\(\.' . EngineMarker::specificityPatternBody('specificity-class') . '\))*$/', self::withoutRouteScope($suffix))) continue;
                    }
                    $parts = explode('>', $selector);
                    if (2 !== count($parts)) continue;
                    [$parent, $child] = array_map('trim', $parts);
                    if ('' === $child) continue;
                    if (!preg_match('/^\*?((?::not\(:last-child\)|:first-child|:last-child)?)$/', $child, $match)) continue;
                    $parsed = CssSelectorMatcher::parse(self::withoutRouteScope($parent));
                    if (!($parsed['supported'] ?? false)) continue;
                    foreach ($elements as $element) {
                        if (!CssSelectorMatcher::matches($element['container'], $parsed)['matches']) continue;
                        $alias = $parent . '>:where(.blocks-engine-listing-query)>:where(.wp-block-post-template)>:where(li)' . $match[1] . '>:where(' . $element['root'] . ')';
                        $aliases[$scope . ($zero ? ':where(' . $alias . ')' : $alias) . $suffix] = true;
                    }
                }
                return $original . (array() === $aliases ? '' : implode(',', array_keys($aliases)) . '{' . $body . '}');
            });
            if ($content === $asset['content']) continue;
            $asset = CompiledAssets::withRewrittenTextContent($asset, $content);
        }
        unset($asset);
        return $assets;
    }

    /** A scoped compound (`.parent:where(:root:not(.be-r-…))`) keeps its gate on the alias but matches as `.parent`. */
    private static function withoutRouteScope(string $selector): string
    {
        return preg_replace('/' . self::ROUTE_SCOPE . '/', '', $selector) ?? $selector;
    }
}

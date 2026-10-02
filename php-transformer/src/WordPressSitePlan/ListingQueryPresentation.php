<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSyntaxScanner;
use DOMDocument;

/** Replay source child spacing across native Query Loop transport wrappers. */
final class ListingQueryPresentation
{
    /** @param list<array<string,mixed>> $assets @param list<string> $containers @return list<array<string,mixed>> */
    public static function project(array $assets, array $containers): array
    {
        if (array() === $containers) return $assets;
        $elements = array();
        foreach (array_unique($containers) as $markup) {
            $document = new DOMDocument();
            $document->loadHTML('<?xml encoding="UTF-8"><body>' . $markup . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
            $body = $document->getElementsByTagName('body')->item(0);
            foreach ($body?->childNodes ?? array() as $child) if ($child instanceof \DOMElement) { $elements[] = $child; break; }
        }
        $transformer = new CssStylesheetTransformer();
        foreach ($assets as &$asset) {
            if ('css' !== ($asset['kind'] ?? '') || !is_string($asset['content'] ?? null)) continue;
            $content = $transformer->transformStyleRules($asset['content'], static function (string $prelude, string $body) use ($elements): string {
                $original = $prelude . '{' . $body . '}';
                if (!preg_match('/(?:^|;)\s*margin(?:-[a-z-]+)?\s*:/i', $body)) return $original;
                $aliases = array();
                foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
                    $selector = trim(preg_replace('~/\*.*?\*/~s', '', $selector) ?? $selector);
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
                        if (!$zero || !preg_match('/^(?::not\(\.[a-z0-9-]*blocks-engine-specificity[a-z0-9-]*\))*$/', $suffix)) continue;
                    }
                    $parts = explode('>', $selector);
                    if (2 !== count($parts)) continue;
                    [$parent, $child] = array_map('trim', $parts);
                    if ('' === $child) continue;
                    if (!preg_match('/^\*?((?::not\(:last-child\)|:first-child|:last-child)?)$/', $child, $match)) continue;
                    $parsed = CssSelectorMatcher::parse($parent);
                    if (!($parsed['supported'] ?? false) || !array_filter($elements, static fn(\DOMElement $element): bool => CssSelectorMatcher::matches($element, $parsed)['matches'])) continue;
                    $alias = $parent . '>:where(.blocks-engine-listing-query)>:where(.wp-block-post-template)>:where(li)' . $match[1] . '>:where(article)';
                    $aliases[($zero ? ':where(' . $alias . ')' : $alias) . $suffix] = true;
                }
                return $original . (array() === $aliases ? '' : implode(',', array_keys($aliases)) . '{' . $body . '}');
            });
            if ($content === $asset['content']) continue;
            $asset['content'] = $content;
            $asset['content_hash'] = hash('sha256', $content);
            $asset['hash'] = $asset['content_hash'];
            $asset['bytes'] = strlen($content);
        }
        unset($asset);
        return $assets;
    }
}

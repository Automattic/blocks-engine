<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Closure;
use DOMElement;

/**
 * Resolves a captured child-combinator selector path such as
 * `body > main > div:nth-of-type(2) > button#menu` below a scope element.
 */
final class CapturedSelectorPath
{
    /**
     * Each `>`-separated part is `tag`, optionally `#id`, optionally
     * `:nth-of-type(n)`, matched against direct children. A leading `body`
     * part is dropped. Anything outside that grammar matches nothing.
     *
     * @param bool $skipsScopeTag Drop a leading part naming the scope's own tag.
     * @param int $limit Stop and return a step's matches once they exceed this many (0: no limit).
     * @param ?Closure(string, string): bool $lastTagMatches Accepts a differing child tag on the last part.
     * @return list<DOMElement>
     */
    public static function matches(DOMElement $scope, string $selector, bool $skipsScopeTag = false, int $limit = 0, ?Closure $lastTagMatches = null): array
    {
        if ('' === $selector || str_contains($selector, ',') || ! str_contains($selector, '>')) {
            return array();
        }
        $parts = preg_split('/\s*>\s*/', $selector);
        if (! is_array($parts) || array() === $parts) {
            return array();
        }
        if ('body' === strtolower($parts[0])) {
            array_shift($parts);
        }
        if (array() === $parts) {
            return array();
        }
        if ($skipsScopeTag && 1 === preg_match('/^([a-z][a-z0-9]*)/i', $parts[0], $scopeToken) && strtolower($scope->tagName) === strtolower($scopeToken[1])) {
            array_shift($parts);
        }
        $current = array($scope);
        foreach ($parts as $index => $part) {
            if (1 !== preg_match('/^([a-z][a-z0-9]*)(#([A-Za-z][A-Za-z0-9_.:-]*))?(:nth-of-type\((\d+)\))?$/i', $part, $tokens)) {
                return array();
            }
            $tag = strtolower($tokens[1]);
            $id = $tokens[3] ?? '';
            $nth = isset($tokens[5]) && '' !== $tokens[5] ? (int) $tokens[5] : 0;
            $isLast = $index === array_key_last($parts);
            $next = array();
            foreach ($current as $node) {
                $seen = array();
                foreach ($node->childNodes as $child) {
                    if (! $child instanceof DOMElement) {
                        continue;
                    }
                    $childTag = strtolower($child->tagName);
                    $seen[$childTag] = ($seen[$childTag] ?? 0) + 1;
                    if ($childTag !== $tag && ! ($isLast && null !== $lastTagMatches && $lastTagMatches($tag, $childTag))) {
                        continue;
                    }
                    if ('' !== $id && $child->getAttribute('id') !== $id) {
                        continue;
                    }
                    if ($nth > 0 && ($seen[$tag] ?? 0) !== $nth) {
                        continue;
                    }
                    $next[] = $child;
                }
            }
            if (array() === $next) {
                return array();
            }
            if (0 < $limit && count($next) > $limit) {
                return $next;
            }
            $current = $next;
        }
        return $current;
    }
}

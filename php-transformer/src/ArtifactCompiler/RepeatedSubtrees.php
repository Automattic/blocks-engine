<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\ChromeJournal\SubtreeIdentity;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;

/**
 * Source positions where pages repeat the same markup: the subtrees worth a
 * {@see \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\ChromeJournal\ChromeJournal}.
 * A position qualifies when at least two pages hold identical markup there;
 * whether a page may reuse another's conversion is decided at conversion.
 */
final class RepeatedSubtrees
{
    /** Smaller subtrees convert faster than a journal records. */
    private const MIN_BYTES = 2048;

    /**
     * @param array<int,array<string,mixed>> $files
     * @return list<string> {@see SourceDom::elementSelector()} paths, none inside another.
     */
    public static function selectors(array $files): array
    {
        $pages = array();
        foreach ($files as $file) {
            if ('html' !== ($file['kind'] ?? null) || HtmlFragmentIncludes::isTemplatePartFile($file) || !is_string($file['content'] ?? null)) continue;
            $body = HtmlSerialization::parseDocument($file['content'])->getElementsByTagName('body')->item(0);
            if ($body instanceof \DOMElement) {
                $subtrees = array();
                self::subtrees($body, $subtrees, true);
                $pages[] = $subtrees;
            }
        }
        if (count($pages) < 2) return array();
        $counts = array();
        foreach ($pages as $subtrees) foreach (array_unique($subtrees) as $subtree) $counts[$subtree] = ($counts[$subtree] ?? 0) + 1;
        $sharing = array();
        foreach ($pages as $subtrees) {
            foreach ($subtrees as $selector => $subtree) if (2 <= $counts[$subtree]) $sharing[$selector] = ($sharing[$selector] ?? 0) + 1;
        }
        // Nested positions convert through one journal: the position more
        // pages share wins, so two duplicate pages cannot hide the chrome
        // every page repeats inside them.
        uksort($sharing, static fn (string $left, string $right): int => array($sharing[$right], $left) <=> array($sharing[$left], $right));
        $selectors = array();
        foreach (array_keys($sharing) as $selector) {
            foreach ($selectors as $chosen) {
                if (str_starts_with($selector, $chosen . ' > ') || str_starts_with($chosen, $selector . ' > ')) continue 2;
            }
            $selectors[] = $selector;
        }
        return $selectors;
    }

    /**
     * Hash each element once, bottom-up, without serializing its descendants again.
     * @param array<string,string> $subtrees Selector => selector and structural hash.
     * @return array{string,int} Structural hash and serialized byte size.
     */
    private static function subtrees(\DOMElement $parent, array &$subtrees, bool $body = false): array
    {
        $attributes = array();
        foreach ($parent->attributes as $attribute) $attributes[$attribute->name] = SubtreeIdentity::normalize($attribute->name . '="' . htmlspecialchars(SubtreeIdentity::normalize($attribute->value), ENT_QUOTES) . '"');
        $parts = array($parent->tagName, $attributes);
        $bytes = strlen((string) $parent->ownerDocument?->saveHTML($parent->cloneNode(false)));
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                [$hash, $size] = self::subtrees($child, $subtrees);
                $parts[] = $hash;
                $bytes += $size;
            } else {
                $markup = SubtreeIdentity::normalize((string) $child->ownerDocument?->saveHTML($child));
                $parts[] = array($child->nodeType, $markup);
                $bytes += strlen($markup);
            }
        }
        $hash = hash('sha256', serialize($parts));
        if (!$body && $bytes >= self::MIN_BYTES) {
            $selector = SourceDom::elementSelector($parent);
            $subtrees[$selector] = hash('sha256', $selector . "\0" . $hash);
        }
        return array($hash, $bytes);
    }
}

<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

use DOMDocument;

/** Immutable compiled documents, indexed once for all author stylesheets. */
final class RouteSelectorMatchIndex
{
    private CssSelectorMatchCache $cache;
    private array $elements = array();
    private array $keys = array();
    private array $selectors = array();
    private array $hits = array();

    /** @param array<string,DOMDocument> $documents */
    public function __construct(array $documents)
    {
        $this->cache = new CssSelectorMatchCache();
        foreach ($documents as $owner => $document) {
            $this->elements[$owner] = array();
            $this->keys[$owner] = array();
            foreach ($document->getElementsByTagName('*') as $element) {
                $this->elements[$owner][] = $element;
                $keys = array('t:' . strtolower($element->tagName));
                if ($element->hasAttribute('id')) $keys[] = 'i:' . $element->getAttribute('id');
                foreach ($this->cache->classTokens($element) as $class) $keys[] = 'c:' . $class;
                foreach ($keys as $key) $this->keys[$owner][$key][] = $element;
            }
        }
    }

    /** Missing documents and unsupported selectors are never evidence of inertness. */
    public function inertOutside(string $arm, array $outside): bool
    {
        if (!isset($this->selectors[$arm])) {
            // Match the structural subject in every state, as the projector does.
            // Do not rewrite quoted attributes or functional negations: those
            // require reasoning about state alternatives, so retain their scope.
            $structural = $arm;
            if (false === strpbrk($arm, '\\"\'') && !str_contains($arm, ':not(')) {
                $structural = preg_replace('/:(?:hover|focus-visible|focus-within|focus|active|visited|any-link|link|disabled|enabled|target)(?![\w(-])/i', '', $arm) ?? $arm;
            }
            $parsed = CssSelectorMatcher::parse($structural);
            $keys = array();
            if ($parsed['supported']) {
                $leaf = $parsed['compounds'][count($parsed['compounds']) - 1];
                foreach ($leaf['classes'] as $class) $keys[] = 'c:' . $class;
                // An id also matches an editor anchor carrier: either key
                // can supply candidates, never require the literal id alone.
                foreach ($leaf['ids'] as $id) {
                    $keys[] = array('i:' . $id, 'c:' . \Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker::editorAnchorClass($id));
                }
                if (null !== $leaf['type']) $keys[] = 't:' . strtolower($leaf['type']);
            }
            $this->selectors[$arm] = array($parsed, $keys);
        }
        [$parsed, $keys] = $this->selectors[$arm];
        if (!$parsed['supported']) return false;
        foreach ($outside as $owner => $_) {
            if (!isset($this->elements[$owner])) return false;
            if (!isset($this->hits[$arm][$owner])) {
                $candidates = $this->elements[$owner];
                foreach ($keys as $key) {
                    $list = array();
                    foreach ((array) $key as $alternative) $list = array_merge($list, $this->keys[$owner][$alternative] ?? array());
                    if (count($list) < count($candidates)) $candidates = $list;
                    if (!$candidates) break;
                }
                $hit = false;
                foreach ($candidates as $element) {
                    $match = CssSelectorMatcher::matches($element, $parsed, true, $this->cache);
                    if (!$match['supported'] || $match['matches']) { $hit = true; break; }
                }
                $this->hits[$arm][$owner] = $hit;
            }
            if ($this->hits[$arm][$owner]) return false;
        }
        return true;
    }
}

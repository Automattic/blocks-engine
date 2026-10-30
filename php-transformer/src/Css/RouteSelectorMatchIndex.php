<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

use DOMDocument;

/** Immutable compiled documents, indexed once for all author stylesheets. */
final class RouteSelectorMatchIndex
{
    private CssSelectorMatchCache $cache;
    private array $elements = array();
    private array $owners = array();
    private array $elementOwners = array();
    private array $keys = array();
    private array $selectors = array();
    private array $hits = array();

    /** @param array<string,DOMDocument> $documents */
    public function __construct(array $documents)
    {
        $this->cache = new CssSelectorMatchCache();
        foreach ($documents as $owner => $document) {
            $this->owners[$owner] = true;
            foreach ($document->getElementsByTagName('*') as $element) {
                $id = count($this->elements);
                $this->elements[$id] = $element;
                $this->elementOwners[$id] = $owner;
                $keys = array('t:' . strtolower($element->tagName));
                if ($element->hasAttribute('id')) $keys[] = 'i:' . $element->getAttribute('id');
                foreach ($this->cache->classTokens($element) as $class) $keys[] = 'c:' . $class;
                foreach ($keys as $key) $this->keys[$key][$id] = true;
            }
        }
    }

    /**
     * Compiled markup cannot disprove state WordPress restores at render time.
     * Use the same class vocabulary as RouteStateProjector's route binding,
     * including Core's current-menu/current_page classes and BE's current item.
     * Scan the whole arm, including functional pseudo-class arguments.
     */
    public static function dependsOnRouteState(string $arm): bool
    {
        if (preg_match('/\[\s*(?:aria-current|data-status|data-state)\b/i', $arm)) return true;
        // Escapes cannot safely be interpreted by this lightweight proof.
        if (str_contains($arm, '\\')) return true;
        preg_match_all('/\.([A-Za-z0-9_-]+)/', $arm, $classes);
        foreach ($classes[1] as $class) {
            if (\Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\MenuVocabulary::containsCurrentStateToken($class)) return true;
        }
        // Class attribute selectors may name the same mutable state tokens.
        return 1 === preg_match('/\[\s*class\b/i', $arm);
    }

    /** Missing documents and unsupported selectors are never evidence of inertness. */
    public function inertOutside(string $arm, array $outside): bool
    {
        if (self::dependsOnRouteState($arm)) return false;
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
            // Pick the smallest subject-key union once across all routes.
            // Integer element ids deduplicate id/editor-anchor alternatives.
            $candidates = null;
            foreach ($keys as $key) {
                $list = array();
                foreach ((array) $key as $alternative) $list += $this->keys[$alternative] ?? array();
                if (null === $candidates || count($list) < count($candidates)) $candidates = $list;
                if (!$candidates) break;
            }
            $byOwner = array();
            foreach (null === $candidates ? array_keys($this->elements) : array_keys($candidates) as $id) {
                $byOwner[$this->elementOwners[$id]][] = $id;
            }
            $this->selectors[$arm] = array($parsed, $byOwner);
        }
        [$parsed, $byOwner] = $this->selectors[$arm];
        if (!$parsed['supported']) return false;
        foreach ($outside as $owner => $_) {
            if (!isset($this->owners[$owner])) return false;
            if (!isset($this->hits[$arm][$owner])) {
                $hit = false;
                foreach ($byOwner[$owner] ?? array() as $id) {
                    $match = $this->cache->matches($this->elements[$id], $arm, $parsed, true);
                    if (!$match['supported'] || $match['matches']) { $hit = true; break; }
                }
                $this->hits[$arm][$owner] = $hit;
            }
            if ($this->hits[$arm][$owner]) return false;
        }
        return true;
    }
}

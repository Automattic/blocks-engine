<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

use DOMDocument;

/**
 * Immutable compiled documents, indexed once for all author stylesheets.
 *
 * Work is keyed on content, not on the route that asks. Routes whose
 * documents have the same structure (tags, attributes, tree shape and the
 * text emptiness `:empty` reads; other text is never read) share one
 * representative, and an element's result is reported by its document-order
 * ordinal, which is the same on every route of the group. Elements whose
 * ancestor chains carry the same tags and attributes, such as one shared
 * shell appended to every route, share each result of a selector that reads
 * only ancestors. Both keys live as long as this index, one compose pass.
 */
final class RouteSelectorMatchIndex
{
    private CssSelectorMatchCache $cache;
    private array $elements = array();
    /** @var array<int, int> Element id to its interned ancestor-chain content. */
    private array $ancestry = array();
    /** @var array<int, int> Element id to its document-order ordinal on its route. */
    private array $ordinals = array();
    /** @var array<string, string> Route to the first route with the same document structure. */
    private array $representatives = array();
    private array $owners = array();
    private array $ownerElements = array();
    private array $keys = array();
    private array $selectors = array();
    private array $hits = array();
    private array $cascadeArms = array();
    private array $cascadeMatches = array();
    private int $resolutions = 0;
    /** @var array<string, array<string, true>> Classes self-closing dynamic blocks render, per route. */
    private array $dynamicClasses = array();

    /** @param array<string,DOMDocument> $documents */
    public function __construct(array $documents)
    {
        $this->cache = new CssSelectorMatchCache();
        // Transient: only the interned ids outlive construction.
        $chains = $structures = array();
        foreach ($documents as $owner => $document) {
            $this->owners[$owner] = true;
            $ids = new \WeakMap();
            $structure = '';
            foreach ($document->getElementsByTagName('*') as $element) {
                $id = count($this->elements);
                $this->elements[$id] = $element;
                $ids[$element] = $id;
                $this->ordinals[$id] = count($this->ownerElements[$owner] ?? array());
                $this->ancestry[$id] = self::ancestry($element, $element->parentNode instanceof \DOMElement && isset($ids[$element->parentNode]) ? $this->ancestry[$ids[$element->parentNode]] : null, $id, $chains);
                // The preorder sequence of ancestor chains fixes the tree.
                $structure .= $this->ancestry[$id] . (self::hasText($element) ? 't' : '') . ',';
                $keys = array('t:' . strtolower($element->tagName));
                if ($element->hasAttribute('id')) $keys[] = 'i:' . $element->getAttribute('id');
                foreach ($this->cache->classTokens($element) as $class) $keys[] = 'c:' . $class;
                foreach ($keys as $key) $this->keys[$owner][$key][$id] = true;
                $this->ownerElements[$owner][] = $id;
            }
            // A self-closing block (core/navigation-link) renders its className
            // on the front end, but its serialized form is a comment the
            // parser cannot match against.
            foreach ((new \DOMXPath($document))->query('//comment()') ?: array() as $comment) {
                if (1 !== preg_match('/^\s*wp:[a-z0-9\/-]+\s+(\{.*\})\s*\/\s*$/s', (string) $comment->nodeValue, $block)) continue;
                $attributes = json_decode($block[1], true);
                foreach (preg_split('/\s+/', is_array($attributes) && is_string($attributes['className'] ?? null) ? $attributes['className'] : '') ?: array() as $class) {
                    if ('' !== $class) $this->dynamicClasses[$owner][$class] = true;
                }
            }
            $classes = array_keys($this->dynamicClasses[$owner] ?? array());
            sort($classes, SORT_STRING);
            $structure .= "\0" . implode(' ', $classes);
            $this->representatives[$owner] = $structures[$structure] ??= (string) $owner;
        }
    }

    /**
     * The interned content of an element's ancestor chain: its tag name and
     * attributes, after its parent's chain. A namespaced element or attribute
     * may answer attribute reads it does not list, so it gets a unique chain.
     *
     * @param array<string, int> $chains
     */
    private static function ancestry(\DOMElement $element, ?int $parent, int $id, array &$chains): int
    {
        $attributes = array();
        $unique = null !== $element->namespaceURI || ( $element->parentNode instanceof \DOMElement && null === $parent );
        foreach ($element->attributes ?? array() as $attribute) {
            if (null !== $attribute->namespaceURI || array_key_exists($attribute->nodeName, $attributes)) $unique = true;
            $attributes[$attribute->nodeName] = $attribute->value;
        }
        ksort($attributes, SORT_STRING);
        $key = $unique ? "\1" . $id : $parent . "\0" . serialize(array(strtolower($element->tagName), $attributes));
        return $chains[$key] ??= count($chains);
    }

    /** Whether an element has a non-empty text child, which `:empty` reads. */
    private static function hasText(\DOMElement $element): bool
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMText && '' !== $child->textContent) return true;
        }
        return false;
    }

    /**
     * The elements of one route's document that a selector arm may style,
     * matched structurally in every interaction state, or null when the arm
     * cannot be evaluated — an unsupported selector or a missing document —
     * so cascade interaction cannot be disproved. A pseudo-element arm styles
     * `<id>::name` of each matched host element.
     *
     * @return array<int|string, true>|null
     */
    public function matchedElements(string $arm, string $owner): ?array
    {
        $owner = $this->representatives[$owner] ?? $owner;
        if ( ! array_key_exists($arm, $this->cascadeMatches) || ! array_key_exists($owner, $this->cascadeMatches[$arm]) ) {
            ++$this->resolutions;
            $this->cascadeMatches[$arm][$owner] = $this->computeMatchedElements($arm, $owner);
        }
        return $this->cascadeMatches[$arm][$owner];
    }

    /**
     * Selector arms evaluated against a document so far, and selector
     * matcher executions behind them: both grow with distinct arms and
     * distinct documents, never with routes that repeat a structure.
     *
     * @return array{resolutions: int, executions: int}
     */
    public function workCounts(): array
    {
        return array('resolutions' => $this->resolutions, 'executions' => $this->cache->matchExecutions);
    }

    /** @return array<int|string, true>|null */
    private function computeMatchedElements(string $arm, string $owner): ?array
    {
        if ( ! isset($this->owners[$owner]) ) {
            return null;
        }
        // An arm naming a class only a dynamic block renders cannot be
        // disproved from the serialized document.
        if ( isset($this->dynamicClasses[$owner]) && preg_match_all('/\.(-?[_a-zA-Z][\w-]*)/', $arm, $classes) ) {
            foreach ( $classes[1] as $class ) {
                if ( isset($this->dynamicClasses[$owner][$class]) ) return null;
            }
        }
        if ( ! array_key_exists($arm, $this->cascadeArms) ) {
            [$structural, $pseudo] = self::cascadeSubject($arm) ?? array('', null);
            $parsed = '' === $structural ? array('supported' => false) : CssSelectorMatcher::parse($structural);
            if ( ! $parsed['supported'] ) {
                // Without the pseudo-classes the matcher cannot evaluate, the
                // subject matches a superset, which can only keep more rules.
                [$structural, $pseudo] = self::cascadeSubject($arm, true) ?? array('', null);
                $parsed = '' === $structural ? array('supported' => false) : CssSelectorMatcher::parse($structural);
            }
            $this->cascadeArms[$arm] = array($parsed, $pseudo);
        }
        [$parsed, $pseudo] = $this->cascadeArms[$arm];
        if ( ! $parsed['supported'] ) {
            return null;
        }
        $candidates = $this->candidates($parsed, $owner);
        $matched = array();
        foreach ( null === $candidates ? ($this->ownerElements[$owner] ?? array()) : array_keys($candidates) as $id ) {
            if ( $this->cache->matches($this->elements[$id], $arm, $parsed, true, $this->ancestry[$id])['matches'] ) {
                $ordinal = $this->ordinals[$id];
                $matched[ null === $pseudo ? $ordinal : $ordinal . $pseudo ] = true;
            }
        }
        return $matched;
    }

    /**
     * The structural subject of a cascade arm and the pseudo-element it
     * styles, or null when the arm cannot be reduced. State pseudo-classes
     * are dropped, so the subject matches in every state: a superset, which
     * can only keep more rules. A pseudo-element is its own cascade target,
     * so it competes only with rules for the same pseudo-element of a host.
     *
     * @return array{0: string, 1: ?string}|null
     */
    private static function cascadeSubject(string $arm, bool $anyPseudoClass = false): ?array
    {
        if ( str_contains($arm, '\\') ) {
            return array($arm, null);
        }
        $subject = '';
        $pseudo = null;
        $length = strlen($arm);
        for ( $i = 0; $i < $length; ) {
            $char = $arm[ $i ];
            if ( '"' === $char || "'" === $char || '[' === $char || '(' === $char ) {
                $end = CssSyntaxScanner::matchingClose($arm, $i);
                if ( null === $end ) return null;
                ++$end;
                if ( null !== $pseudo ) return null;
                $subject .= substr($arm, $i, $end - $i);
                $i = $end;
                continue;
            }
            if ( ':' !== $char ) {
                // Only state pseudo-classes may follow a pseudo-element.
                if ( null !== $pseudo ) return null;
                $subject .= $char;
                ++$i;
                continue;
            }
            $double = ':' === ( $arm[ $i + 1 ] ?? '' );
            if ( 1 !== preg_match('/\G:{1,2}(-?[A-Za-z][\w-]*)/', $arm, $name, 0, $i) ) return null;
            $end = $i + strlen($name[0]);
            $argument = null;
            if ( '(' === ( $arm[ $end ] ?? '' ) ) {
                $close = CssSyntaxScanner::matchingClose($arm, $end);
                if ( null === $close ) return null;
                ++$close;
                $argument = substr($arm, $end + 1, $close - $end - 2);
                $end = $close;
            }
            $lower = strtolower($name[1]);
            $element = $double || isset(self::LEGACY_PSEUDO_ELEMENTS[ $lower ]);
            if ( ! $element && ! $anyPseudoClass && ( null === $argument ? ! self::isStatePseudoClass($lower) : ( 'not' !== $lower || ! self::onlyStates($argument) ) ) ) {
                if ( null !== $pseudo ) return null;
                $subject .= substr($arm, $i, $end - $i);
                $i = $end;
                continue;
            }
            // A compound left empty by a dropped pseudo matches any element.
            if ( 1 === preg_match('/(?:^|[\s>+~])$/', $subject) && ( $element || $end >= $length || false !== strpos(" \t\n\r\f>+~", $arm[ $end ]) ) ) {
                $subject .= '*';
            }
            if ( $element ) {
                if ( null !== $pseudo ) return null;
                $pseudo = '::' . $lower . ( null === $argument ? '' : '(' . trim($argument) . ')' );
            }
            $i = $end;
        }
        return array(trim($subject), $pseudo);
    }

    /** Pseudo-classes that vary with user, form or media state, never structure. */
    private static function isStatePseudoClass(string $name): bool
    {
        return isset(self::STATE_PSEUDO_CLASSES[ $name ]) || 1 === preg_match('/^-(?:moz|webkit|ms|o)-/', $name);
    }

    /** Whether a :not() argument lists only state pseudo-classes. */
    private static function onlyStates(string $argument): bool
    {
        return 1 === preg_match('/^(?:\s*(?::[A-Za-z-]+\s*)+,?)+$/', $argument)
            && array() === array_filter(preg_split('/[\s,:]+/', $argument, -1, PREG_SPLIT_NO_EMPTY) ?: array(), static fn (string $name): bool => ! self::isStatePseudoClass(strtolower($name)));
    }

    private const LEGACY_PSEUDO_ELEMENTS = array( 'before' => true, 'after' => true, 'first-line' => true, 'first-letter' => true );

    private const STATE_PSEUDO_CLASSES = array(
        'hover' => true, 'focus' => true, 'focus-visible' => true, 'focus-within' => true, 'active' => true,
        'visited' => true, 'any-link' => true, 'link' => true, 'target' => true, 'target-within' => true,
        'disabled' => true, 'enabled' => true, 'checked' => true, 'indeterminate' => true, 'default' => true,
        'placeholder-shown' => true, 'autofill' => true, 'valid' => true, 'invalid' => true, 'user-valid' => true,
        'user-invalid' => true, 'required' => true, 'optional' => true, 'read-only' => true, 'read-write' => true,
        'in-range' => true, 'out-of-range' => true, 'blank' => true, 'open' => true, 'closed' => true,
        'popover-open' => true, 'modal' => true, 'fullscreen' => true, 'picture-in-picture' => true,
        'playing' => true, 'paused' => true, 'seeking' => true, 'buffering' => true, 'stalled' => true,
        'muted' => true, 'volume-locked' => true, 'current' => true, 'past' => true, 'future' => true,
    );

    /**
     * Compiled markup cannot disprove state WordPress restores at render time.
     * Use the same class vocabulary as RouteStateProjector's route binding,
     * including Core's current-menu/current_page classes and BE's current item.
     * Scan the whole arm, including functional pseudo-class arguments.
     */
    public static function dependsOnRouteState(string $arm): bool
    {
        // Capture device ancestors are restored at render time, including on
        // shared parts absent from an individual route's serialized content.
        // Their absence cannot prove a flattened projection inert elsewhere.
        if (preg_match('/\[\s*(?:aria-current|data-status|data-state|' . preg_quote(\Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker::DEVICE_SCOPE_ATTRIBUTE, '/') . '|' . preg_quote(\Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence::DEVICE_DOCUMENT, '/') . ')\b/i', $arm)) return true;
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
            $this->selectors[$arm] = $parsed;
        }
        $parsed = $this->selectors[$arm];
        if (!$parsed['supported']) return false;
        foreach ($outside as $owner => $_) {
            if (!isset($this->owners[$owner])) return false;
            $owner = $this->representatives[$owner];
            if (!isset($this->hits[$arm][$owner])) {
                $hit = false;
                $candidates = $this->candidates($parsed, $owner);
                foreach (null === $candidates ? ($this->ownerElements[$owner] ?? array()) : array_keys($candidates) as $id) {
                    $match = $this->cache->matches($this->elements[$id], $arm, $parsed, true, $this->ancestry[$id]);
                    if (!$match['supported'] || $match['matches']) { $hit = true; break; }
                }
                $this->hits[$arm][$owner] = $hit;
            }
            if ($this->hits[$arm][$owner]) return false;
        }
        return true;
    }

    /**
     * Possible subjects on one route, or null when no key narrows the search.
     * Every preceding compound must match somewhere on the route, whether it
     * is connected by a descendant, child, adjacent or general sibling combinator.
     * An absent key proves no match; its presence does not prove a relationship.
     *
     * @return array<int,true>|null
     */
    private function candidates(array $parsed, string $owner): ?array
    {
        $compounds = $parsed['compounds'];
        $subject = array_pop($compounds);
        foreach ($compounds as $preceding) {
            if (array() === $this->compoundCandidates($preceding, $owner)) return array();
        }
        return $this->compoundCandidates($subject, $owner);
    }

    /**
     * Smallest necessary key set for a compound (a conservative superset).
     * Negations supply no positive keys. An any group can narrow the search
     * only when every alternative's subject is keyed; otherwise it is unbounded.
     *
     * @return array<int,true>|null
     */
    private function compoundCandidates(array $compound, string $owner): ?array
    {
        $keys = $this->keys[$owner] ?? array();
        $options = array();
        foreach ($compound['classes'] as $class) $options[] = $keys['c:' . $class] ?? array();
        // An id can also match an editor anchor carrier. Union, never intersect.
        foreach ($compound['ids'] as $id) {
            $options[] = ($keys['i:' . $id] ?? array())
                + ($keys['c:' . \Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker::editorAnchorClass($id)] ?? array());
        }
        if (null !== $compound['type']) $options[] = $keys['t:' . strtolower($compound['type'])] ?? array();
        foreach ($compound['any'] ?? array() as $group) {
            $union = array();
            foreach ($group['alternatives'] as $alternative) {
                $subject = $this->compoundCandidates($alternative['compounds'][count($alternative['compounds']) - 1], $owner);
                if (null === $subject) continue 2;
                $union += $subject;
            }
            $options[] = $union;
        }
        $best = null;
        foreach ($options as $option) {
            if (null === $best || count($option) < count($best)) $best = $option;
            if (array() === $best) break;
        }
        return $best;
    }
}

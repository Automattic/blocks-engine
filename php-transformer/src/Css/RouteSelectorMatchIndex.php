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
    private array $ownerElements = array();
    private array $keys = array();
    private array $selectors = array();
    private array $hits = array();
    private array $cascadeArms = array();
    private array $cascadeMatches = array();

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
                $this->ownerElements[$owner][] = $id;
            }
        }
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
        if ( ! array_key_exists($arm, $this->cascadeMatches) || ! array_key_exists($owner, $this->cascadeMatches[$arm]) ) {
            $this->cascadeMatches[$arm][$owner] = $this->computeMatchedElements($arm, $owner);
        }
        return $this->cascadeMatches[$arm][$owner];
    }

    /** @return array<int|string, true>|null */
    private function computeMatchedElements(string $arm, string $owner): ?array
    {
        if ( ! isset($this->owners[$owner]) ) {
            return null;
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
            $keys = array();
            if ( $parsed['supported'] ) {
                $leaf = $parsed['compounds'][count($parsed['compounds']) - 1];
                foreach ($leaf['classes'] as $class) $keys[] = 'c:' . $class;
                // An id also matches an editor anchor carrier: either key
                // can supply candidates, never require the literal id alone.
                foreach ($leaf['ids'] as $id) {
                    $keys[] = array('i:' . $id, 'c:' . \Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker::editorAnchorClass($id));
                }
                if (null !== $leaf['type']) $keys[] = 't:' . strtolower($leaf['type']);
            }
            $candidates = null;
            foreach ($keys as $key) {
                $list = array();
                foreach ((array) $key as $alternative) $list += $this->keys[$alternative] ?? array();
                if (null === $candidates || count($list) < count($candidates)) $candidates = $list;
                if (!$candidates) break;
            }
            $this->cascadeArms[$arm] = array($parsed, $candidates, $pseudo);
        }
        [$parsed, $candidates, $pseudo] = $this->cascadeArms[$arm];
        if ( ! $parsed['supported'] ) {
            return null;
        }
        $matched = array();
        foreach ( null === $candidates ? ($this->ownerElements[$owner] ?? array()) : array_keys($candidates) as $id ) {
            if ( $this->elementOwners[$id] !== $owner ) {
                continue;
            }
            if ( $this->cache->matches($this->elements[$id], $arm, $parsed, true)['matches'] ) {
                $matched[ null === $pseudo ? $id : $id . $pseudo ] = true;
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
                $end = self::groupEnd($arm, $i);
                if ( null === $end ) return null;
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
                $close = self::groupEnd($arm, $end);
                if ( null === $close ) return null;
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

    /** Offset just past a quoted string or a bracketed or parenthesized group. */
    private static function groupEnd(string $arm, int $start): ?int
    {
        $open = $arm[ $start ];
        if ( '"' === $open || "'" === $open ) {
            $end = strpos($arm, $open, $start + 1);
            return false === $end ? null : $end + 1;
        }
        $close = '[' === $open ? ']' : ')';
        $depth = 0;
        $length = strlen($arm);
        for ( $i = $start; $i < $length; ++$i ) {
            $char = $arm[ $i ];
            if ( '"' === $char || "'" === $char ) {
                $end = self::groupEnd($arm, $i);
                if ( null === $end ) return null;
                $i = $end - 1;
            } elseif ( $char === $open ) {
                ++$depth;
            } elseif ( $char === $close && 0 === --$depth ) {
                return $i + 1;
            }
        }
        return null;
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

<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;
use DOMDocument;
use DOMElement;
use DOMNode;
use WeakMap;

/** Caches immutable-DOM selector inputs for one author-selector discovery pass. */
final class CssSelectorMatchCache
{
    /**
     * A large source document resolves selectors against far more elements than
     * a small one, and evicting a hot result only to recompute it is the cost
     * this cache exists to avoid. The bound is per instance so a test can prove
     * eviction behaviour at a small capacity instead of building a document
     * large enough to overflow the production bound.
     */
    public const MAX_MATCHES = 131072;

    /**
     * Candidate lists are keyed by the element's selector signature (see
     * styleRuleCandidates()), so the number of lists a document needs is its
     * number of distinct signatures per collection, not its element count. A
     * large site-builder page measured 7,497 elements but 1,115 signatures,
     * and a 9,649-element homepage 2,253; a resolver asks for several
     * collections per element. The list bound leaves room for a few thousand
     * signatures in each of the hot collections at once.
     *
     * Retained lists hold the index's own rule arrays, which PHP shares by
     * reference count, so a retained entry costs one array slot. The entry
     * bound caps that at a few megabytes while fitting thousands of lists of
     * the 50 or so candidates a typical element collects.
     */
    public const MAX_CANDIDATE_RULES = 262144;

    public const MAX_CANDIDATE_LISTS = 16384;

    /** @var array<string, list<string>> */
    private array $classTokens = array();

    /** @var array<string, array<string, string|null>> */
    private array $attributes = array();

    /** @var array<string, list<string>> */
    private array $attributeNames = array();

    /** @var array<string, array{supported: bool, matches: bool}> */
    private array $matches = array();

    /** @var array<string, list<array<string, mixed>>> */
    private array $ruleCandidates = array();

    /** @var array<string, string> Element key to interned candidate signature key. */
    private array $candidateSignatures = array();

    /** @var array<string, string> Element key to interned subject signature key, or the element key when unshareable. */
    private array $subjectSignatures = array();

    /** @var array<string, int> Serialized signature to its interned number. */
    private array $signatureIds = array();

    /** @var array<string, bool> Selector text to whether its result depends only on the subject. */
    private array $subjectOnlySelectors = array();

    /** @var WeakMap<DOMElement, int> */
    private WeakMap $detachedElementKeys;

    /** @var WeakMap<DOMElement, string> */
    private WeakMap $connectedElementKeys;

    /** @var WeakMap<DOMDocument, int> */
    private WeakMap $connectedDocumentKeys;

    /** @var list<DOMDocument> */
    private array $connectedDocuments = array();
    /** @var array<string,array{node:DOMElement,key:string}> */
    private array $connectedPaths = array();
    private int $nextConnectedElementKey = 0;

    private int $nextDetachedElementKey = 0;

    private int $nextConnectedDocumentKey = 0;

    public int $classTokenBuilds = 0;

    public int $classTokenHits = 0;

    public int $attributeReads = 0;

    public int $matchExecutions = 0;

    public int $matchHits = 0;

    public int $matchMisses = 0;

    public int $matchEvictions = 0;

    public int $matchPeakEntries = 0;

    public int $candidateRuleChecks = 0;

    public int $candidateRulesSkipped = 0;

    public int $candidateRulesRetained = 0;

    public int $candidateRuleHits = 0;

    public int $candidateRuleMisses = 0;

    public int $candidateRuleEvictions = 0;

    public int $candidateRulePeakEntries = 0;

    public int $candidateRulePeakRetained = 0;

    public int $candidateSignatureBuilds = 0;

    /** Lists keyed by element because two distinct entries tie in cascade order. */
    public int $candidateElementKeyedLists = 0;

    public int $subjectSignatureBuilds = 0;

    /** Match lookups keyed by subject signature rather than element. */
    public int $subjectMatchLookups = 0;

    public int $connectedElementKeyBuilds = 0;

    public int $connectedElementKeyHits = 0;

    private int $maxMatches;

    public function __construct(?int $maxMatches = null)
    {
        if ( null !== $maxMatches && $maxMatches < 1 ) {
            throw new \InvalidArgumentException('A selector match cache must retain at least one result.');
        }
        $this->maxMatches = $maxMatches ?? self::MAX_MATCHES;
        $this->detachedElementKeys = new WeakMap();
        $this->connectedElementKeys = new WeakMap();
        $this->connectedDocumentKeys = new WeakMap();
    }

    /** @return list<string> */
    public function classTokens(DOMElement $element): array
    {
        $key = $this->elementKey($element);
        if ( array_key_exists($key, $this->classTokens) ) {
            ++$this->classTokenHits;
            return $this->classTokens[$key];
        }

        ++$this->classTokenBuilds;
        return $this->classTokens[$key] = preg_split('/[\x09\x0A\x0C\x0D\x20]+/', trim($this->attribute($element, 'class') ?? '')) ?: array();
    }

    public function attribute(DOMElement $element, string $name): ?string
    {
        $key = $this->elementKey($element);
        if ( array_key_exists($name, $this->attributes[$key] ?? array()) ) {
            return $this->attributes[$key][$name];
        }

        ++$this->attributeReads;
        return $this->attributes[$key][$name] = $element->hasAttribute($name) ? $element->getAttribute($name) : null;
    }

    /** @return list<string> */
    public function attributeNames(DOMElement $element): array
    {
        $key = $this->elementKey($element);
        if ( isset($this->attributeNames[$key]) ) {
            return $this->attributeNames[$key];
        }

        $names = array();
        foreach ( $element->attributes ?? array() as $attribute ) {
            $name = strtolower($attribute->nodeName);
            // The selector parser's supported HTML attribute names are simple
            // identifiers. Keep namespace and other syntax conservative.
            if ( 1 === preg_match('/^[a-z][a-z0-9_-]*$/', $name) ) {
                $names[] = $name;
            }
        }
        return $this->attributeNames[$key] = $names;
    }

    /** @param array<string, mixed> $selector @return array{supported: bool, matches: bool} */
    public function matches(DOMElement $element, string $selectorText, array $selector, bool $accountForPseudoStateSuffix = false): array
    {
        $key = $this->matchSubjectKey($element, $selectorText, $selector) . "\0" . $selectorText . "\0" . ($accountForPseudoStateSuffix ? '1' : '0');
        if ( isset($this->matches[$key]) ) {
            ++$this->matchHits;
            $match = $this->matches[$key];
            // PHP arrays retain insertion order, making this a compact LRU queue.
            unset($this->matches[$key]);
            $this->matches[$key] = $match;
            return $match;
        }

        ++$this->matchMisses;
        if ( count($this->matches) >= $this->maxMatches ) {
            // Evict the least recently used eighth at once. Promoting a hit
            // leaves a deleted slot at the front of the table, so finding the
            // single oldest entry with array_key_first() scans past every one
            // of them; slicing drops a batch and compacts the table.
            $evict = max(1, intdiv($this->maxMatches, 8));
            $this->matches = array_slice($this->matches, $evict, null, true);
            $this->matchEvictions += $evict;
        }
        ++$this->matchExecutions;
        $this->matches[$key] = CssSelectorMatcher::matches($element, $selector, $accountForPseudoStateSuffix, $this);
        $this->matchPeakEntries = max($this->matchPeakEntries, count($this->matches));
        return $this->matches[$key];
    }

    /**
     * @param array{universal: list<array{order: int, rule: array<string, mixed>}>, ids: array<string, list<array{order: int, rule: array<string, mixed>}>>, classes: array<string, list<array{order: int, rule: array<string, mixed>}>>, tags: array<string, list<array{order: int, rule: array<string, mixed>}>>, attributes: array<string, list<array{order: int, rule: array<string, mixed>}>>, total: int} $index
     * @return list<array<string, mixed>>
     */
    public function styleRuleCandidates(DOMElement $element, string $collection, array $index): array
    {
        $key = $collection . "\0" . $this->candidateSignature($element);
        if ( ! isset($this->ruleCandidates[$key]) && isset($this->ruleCandidates[$elementListKey = $collection . "\0" . $this->elementKey($element)]) ) {
            $key = $elementListKey;
        }
        if ( isset($this->ruleCandidates[$key]) ) {
            ++$this->candidateRuleHits;
            $rules = $this->ruleCandidates[$key];
            unset($this->ruleCandidates[$key]);
            $this->ruleCandidates[$key] = $rules;
            return $rules;
        }

        ++$this->candidateRuleMisses;

        $candidates = $index['universal'];
        $id = $this->attribute($element, 'id');
        if ( null !== $id ) {
            $candidates = array_merge($candidates, $index['ids'][$id] ?? array());
        }
        foreach ( $this->classTokens($element) as $class ) {
            $candidates = array_merge($candidates, $index['classes'][$class] ?? array());
            $anchoredId = EngineMarker::editorAnchorId($class);
            if ( null !== $anchoredId && $anchoredId !== $id ) {
                $candidates = array_merge($candidates, $index['ids'][$anchoredId] ?? array());
            }
        }
        $candidates = array_merge($candidates, $index['tags'][strtolower($element->tagName)] ?? array());
        foreach ( $this->attributeNames($element) as $name ) {
            $candidates = array_merge($candidates, $index['attributes'][$name] ?? array());
        }

        uasort($candidates, static fn (array $left, array $right): int => $left['order'] <=> $right['order'] ?: (($left['sequence'] ?? $left['order']) <=> ($right['sequence'] ?? $right['order'])));
        if ( ! $this->candidateOrderIsSignatureExact($candidates) ) {
            // Two distinct entries share a cascade position, so their relative
            // order follows the element's own class and attribute order, which
            // the signature sorts away. Keep this list to this element.
            $key = $collection . "\0" . $this->elementKey($element);
            ++$this->candidateElementKeyedLists;
        }
        $rules = array_column($candidates, 'rule');
        $this->candidateRuleChecks += count($rules);
        $this->candidateRulesSkipped += $index['total'] - count($rules);

        $ruleCount = count($rules);
        if ( $ruleCount > self::MAX_CANDIDATE_RULES ) {
            return $rules;
        }
        while (
            $this->candidateRulesRetained + $ruleCount > self::MAX_CANDIDATE_RULES
            || count($this->ruleCandidates) >= self::MAX_CANDIDATE_LISTS
        ) {
            $oldestKey = array_key_first($this->ruleCandidates);
            $this->candidateRulesRetained -= count($this->ruleCandidates[$oldestKey]);
            unset($this->ruleCandidates[$oldestKey]);
            ++$this->candidateRuleEvictions;
        }
        $this->candidateRulesRetained += $ruleCount;
        $this->ruleCandidates[$key] = $rules;
        $this->candidateRulePeakEntries = max($this->candidateRulePeakEntries, count($this->ruleCandidates));
        $this->candidateRulePeakRetained = max($this->candidateRulePeakRetained, $this->candidateRulesRetained);
        return $rules;
    }

    /** Clear results and selector inputs after a source-DOM mutation. */
    public function clear(): void
    {
        $this->classTokens = array();
        $this->attributes = array();
        $this->attributeNames = array();
        $this->matches = array();
        $this->ruleCandidates = array();
        $this->candidateRulesRetained = 0;
        $this->candidateSignatures = array();
        $this->subjectSignatures = array();
        $this->signatureIds = array();
        $this->detachedElementKeys = new WeakMap();
        $this->connectedElementKeys = new WeakMap();
        $this->connectedDocumentKeys = new WeakMap();
        $this->connectedDocuments = array();
        $this->connectedPaths = array();
        $this->nextConnectedElementKey = 0;
        $this->nextConnectedDocumentKey = 0;
        $this->nextDetachedElementKey = 0;
    }

    /**
     * The inputs styleRuleCandidates() reads: tag, id, class tokens (with
     * multiplicity, since a repeated token merges its bucket twice; editor
     * anchor ids derive from them) and selector-readable attribute names.
     * Elements with equal signatures collect equal candidate lists.
     */
    private function candidateSignature(DOMElement $element): string
    {
        $elementKey = $this->elementKey($element);
        if ( isset($this->candidateSignatures[$elementKey]) ) {
            return $this->candidateSignatures[$elementKey];
        }

        ++$this->candidateSignatureBuilds;
        $classes = $this->classTokens($element);
        sort($classes, SORT_STRING);
        $names = $this->attributeNames($element);
        sort($names, SORT_STRING);
        $signature = serialize(array( strtolower($element->tagName), $this->attribute($element, 'id'), $classes, $names ));
        return $this->candidateSignatures[$elementKey] = 'candidates:' . $this->signatureId($signature);
    }

    /**
     * Whether the sorted candidates are fully ordered by cascade position.
     * Repeated entries are the same rule, so only distinct entries that tie
     * make the order depend on how the element's buckets were merged.
     *
     * @param array<array-key, array<string, mixed>> $candidates Sorted candidates.
     */
    private function candidateOrderIsSignatureExact(array $candidates): bool
    {
        $previous = null;
        foreach ( $candidates as $candidate ) {
            if (
                null !== $previous
                && $previous['order'] === $candidate['order']
                && ($previous['sequence'] ?? $previous['order']) === ($candidate['sequence'] ?? $candidate['order'])
                && $previous['rule'] !== $candidate['rule']
            ) {
                return false;
            }
            $previous = $candidate;
        }
        return true;
    }

    /**
     * A selector that reads only its subject's tag name and attributes gives
     * the same result on every element with the same tag name and attributes,
     * so its result is keyed by that full signature. Every other selector
     * reads the element's position and keeps the element key.
     *
     * @param array<string, mixed> $selector
     */
    private function matchSubjectKey(DOMElement $element, string $selectorText, array $selector): string
    {
        $subjectOnly = $this->subjectOnlySelectors[$selectorText] ??= CssSelectorMatcher::dependsOnlyOnSubject($selector);
        if ( ! $subjectOnly || ! ($selector['supported'] ?? false) ) {
            return $this->elementKey($element);
        }
        ++$this->subjectMatchLookups;
        $elementKey = $this->elementKey($element);
        if ( isset($this->subjectSignatures[$elementKey]) ) {
            return $this->subjectSignatures[$elementKey];
        }

        ++$this->subjectSignatureBuilds;
        // Attribute reads go through hasAttribute()/getAttribute() by
        // qualified name. Namespaced nodes can answer those for names they do
        // not list as plain attributes, so they keep their element key.
        if ( null !== $element->namespaceURI ) {
            return $this->subjectSignatures[$elementKey] = $elementKey;
        }
        $attributes = array();
        foreach ( $element->attributes ?? array() as $attribute ) {
            if ( null !== $attribute->namespaceURI || array_key_exists($attribute->nodeName, $attributes) ) {
                return $this->subjectSignatures[$elementKey] = $elementKey;
            }
            $attributes[$attribute->nodeName] = $attribute->value;
        }
        ksort($attributes, SORT_STRING);
        $signature = serialize(array( strtolower($element->tagName), $attributes ));
        return $this->subjectSignatures[$elementKey] = 'subject:' . $this->signatureId($signature);
    }

    private function signatureId(string $signature): int
    {
        return $this->signatureIds[$signature] ??= count($this->signatureIds) + 1;
    }

    private function elementKey(DOMElement $element): string
    {
        if ( isset($this->connectedElementKeys[$element]) ) {
            ++$this->connectedElementKeyHits;
            return $this->connectedElementKeys[$element];
        }

        // PHP may return a new wrapper each time the same native DOM node is
        // fetched, and it reuses spl_object_id() as soon as an old wrapper is
        // released. Paths find wrappers for the same native node, but lowering
        // can replace a node at that path. Confirm native identity before reuse.
        for ( $ancestor = $element; $ancestor instanceof DOMNode; $ancestor = $ancestor->parentNode ) {
            if ( $ancestor instanceof DOMDocument ) {
                ++$this->connectedElementKeyBuilds;
                if ( ! isset($this->connectedDocumentKeys[$ancestor]) ) {
                    $this->connectedDocumentKeys[$ancestor] = ++$this->nextConnectedDocumentKey;
                    // Retain the wrapper so PHP cannot recycle its object ID for
                    // another native document during this cache revision.
                    $this->connectedDocuments[] = $ancestor;
                }
                $path = 'document:' . $this->connectedDocumentKeys[$ancestor] . ':path:' . $element->getNodePath();
                $retained = $this->connectedPaths[$path] ?? null;
                if (null === $retained || !$retained['node']->isSameNode($element)) {
                    $retained = array('node' => $element, 'key' => $path . ':node:' . ++$this->nextConnectedElementKey);
                    $this->connectedPaths[$path] = $retained;
                }
                return $this->connectedElementKeys[$element] = $retained['key'];
            }
        }

        // Detached nodes can share a path such as `/p`; keep their live wrapper
        // identity without retaining the wrapper or ever reusing its token.
        if ( ! isset($this->detachedElementKeys[$element]) ) {
            $this->detachedElementKeys[$element] = ++$this->nextDetachedElementKey;
        }

        return 'detached:' . $this->detachedElementKeys[$element];
    }
}

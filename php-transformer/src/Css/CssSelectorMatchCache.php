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
     * Byte bound for retained selector results.
     *
     * A result is two bits in a per-selector row indexed by interned element
     * id, so a selector checked against every element of a 10,000-element
     * page costs about 2.5 KB (5 KB with growth slack) plus ROW_OVERHEAD_BYTES.
     * A large site-builder page measured about 6.5 million distinct results
     * (Automattic/blocks-engine#3126); evicting them only to recompute them
     * doubled compile CPU. 64 MiB retains every row of such a page with
     * thousands of selectors to spare, while a pathological stylesheet still
     * cannot grow one cache instance past it.
     *
     * The bound is per instance so a test can prove eviction behaviour at a
     * small capacity instead of building a document large enough to overflow
     * the production bound.
     */
    public const MAX_MATCH_BYTES = 67108864;

    /**
     * Estimated fixed cost of one selector row beyond its packed bytes: the
     * row string header, its slots in the row, base, recency and count
     * tables, and the selector's intern entry. Accounting it keeps a bound
     * honest for stylesheets with many selectors that each see few elements.
     */
    public const ROW_OVERHEAD_BYTES = 192;

    /** Smallest row allocation, in bytes (four results per byte). */
    private const MIN_ROW_BYTES = 16;

    /**
     * Shared results indexed by their two-bit state. State 0 means unknown.
     * Returning these constants means a hit allocates nothing.
     */
    private const RESULTS = array(
        1 => array( 'supported' => true, 'matches' => false ),
        2 => array( 'supported' => true, 'matches' => true ),
        3 => array( 'supported' => false, 'matches' => false ),
    );

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

    /** @var array<int, string> Selector id to its packed two-bit results, four per byte. */
    private array $matchRows = array();

    /** @var array<int, int> Selector id to the element-id byte its row starts at. */
    private array $matchRowBases = array();

    /** @var array<int, int> Selector id to the number of results its row holds. */
    private array $matchRowResults = array();

    /**
     * Rows in eviction order. The value is the row's reference bit: a hit
     * sets it, and eviction gives a referenced row a second chance by moving
     * it to the back instead of dropping it (a CLOCK approximation of LRU).
     *
     * @var array<int, bool>
     */
    private array $matchRowOrder = array();

    /** @var array<string, int> Selector text to its interned id, without pseudo-state suffix accounting. */
    private array $selectorIds = array();

    /** @var array<string, int> Selector text to its interned id, with pseudo-state suffix accounting. */
    private array $suffixSelectorIds = array();

    /** @var array<int, bool> Selector id to whether its result depends only on the subject. */
    private array $subjectOnlySelectors = array();

    /** @var array<string, int> Element key or subject signature to its interned match element id. */
    private array $matchElementIds = array();

    /** @var array<string, list<array<string, mixed>>> */
    private array $ruleCandidates = array();

    /** @var array<string, string> Element key to interned candidate signature key. */
    private array $candidateSignatures = array();

    /** @var array<string, int> Element key to the match element id of its subject signature, or of the element key when unshareable. */
    private array $subjectSignatures = array();

    /** @var array<string, int> Serialized signature to its interned number. */
    private array $signatureIds = array();

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

    /** Peak number of retained results. */
    public int $matchPeakEntries = 0;

    /** Bytes the retained results are accounted at against the bound. */
    public int $matchBytes = 0;

    public int $matchPeakBytes = 0;

    /** Whole selector rows dropped by eviction; matchEvictions counts their results. */
    public int $matchRowEvictions = 0;

    private int $matchEntries = 0;

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

    private int $maxMatchBytes;

    /** @param int|null $maxMatchBytes Byte bound for retained results; the most recently written row is always kept. */
    public function __construct(?int $maxMatchBytes = null)
    {
        if ( null !== $maxMatchBytes && $maxMatchBytes < 1 ) {
            throw new \InvalidArgumentException('A selector match cache must retain at least one result.');
        }
        $this->maxMatchBytes = $maxMatchBytes ?? self::MAX_MATCH_BYTES;
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
        $selectorId = $accountForPseudoStateSuffix
            ? ($this->suffixSelectorIds[$selectorText] ?? $this->internSelector($selectorText, $selector, true))
            : ($this->selectorIds[$selectorText] ?? $this->internSelector($selectorText, $selector, false));
        $elementId = $this->matchElementId($element, $selectorId, $selector);
        if ( isset($this->matchRows[$selectorId]) ) {
            $offset = ($elementId >> 2) - $this->matchRowBases[$selectorId];
            if ( $offset >= 0 && isset($this->matchRows[$selectorId][$offset]) ) {
                $state = (ord($this->matchRows[$selectorId][$offset]) >> (($elementId & 3) << 1)) & 3;
                if ( 0 !== $state ) {
                    ++$this->matchHits;
                    $this->matchRowOrder[$selectorId] = true;
                    return self::RESULTS[$state];
                }
            }
        }

        ++$this->matchMisses;
        ++$this->matchExecutions;
        $match = CssSelectorMatcher::matches($element, $selector, $accountForPseudoStateSuffix, $this);
        $state = $match['supported'] ? ($match['matches'] ? 2 : 1) : 3;
        $this->storeMatch($selectorId, $elementId, $state);
        return self::RESULTS[$state];
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
        $this->matchRows = array();
        $this->matchRowBases = array();
        $this->matchRowResults = array();
        $this->matchRowOrder = array();
        $this->selectorIds = array();
        $this->suffixSelectorIds = array();
        $this->subjectOnlySelectors = array();
        $this->matchElementIds = array();
        $this->matchBytes = 0;
        $this->matchEntries = 0;
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

    /** @param array<string, mixed> $selector */
    private function internSelector(string $selectorText, array $selector, bool $accountForPseudoStateSuffix): int
    {
        $selectorId = count($this->selectorIds) + count($this->suffixSelectorIds);
        $this->subjectOnlySelectors[$selectorId] = CssSelectorMatcher::dependsOnlyOnSubject($selector);
        if ( $accountForPseudoStateSuffix ) {
            return $this->suffixSelectorIds[$selectorText] = $selectorId;
        }
        return $this->selectorIds[$selectorText] = $selectorId;
    }

    /**
     * Record one result in its selector's row, growing the row to cover the
     * element id and evicting other rows when the byte bound is exceeded.
     */
    private function storeMatch(int $selectorId, int $elementId, int $state): void
    {
        $byte = $elementId >> 2;
        if ( ! isset($this->matchRows[$selectorId]) ) {
            $this->matchRows[$selectorId] = str_repeat("\0", self::MIN_ROW_BYTES);
            $this->matchRowBases[$selectorId] = $byte;
            $this->matchRowResults[$selectorId] = 0;
            $this->matchBytes += self::ROW_OVERHEAD_BYTES + self::MIN_ROW_BYTES;
        } else {
            // Rows double in whichever direction they need room, so growing a
            // row costs amortized O(1) per byte it ends up holding.
            $base = $this->matchRowBases[$selectorId];
            $length = strlen($this->matchRows[$selectorId]);
            if ( $byte < $base ) {
                $newBase = max(0, min($byte, $base - $length));
                $this->matchRows[$selectorId] = str_repeat("\0", $base - $newBase) . $this->matchRows[$selectorId];
                $this->matchRowBases[$selectorId] = $newBase;
                $this->matchBytes += $base - $newBase;
            } elseif ( $byte - $base >= $length ) {
                $grow = max($byte - $base + 1, 2 * $length) - $length;
                $this->matchRows[$selectorId] .= str_repeat("\0", $grow);
                $this->matchBytes += $grow;
            }
        }
        // Write through the property so the row string is modified in place.
        $offset = $byte - $this->matchRowBases[$selectorId];
        $this->matchRows[$selectorId][$offset] = chr(ord($this->matchRows[$selectorId][$offset]) | ($state << (($elementId & 3) << 1)));
        ++$this->matchRowResults[$selectorId];
        ++$this->matchEntries;
        // The row just written moves to the back, so eviction reaches it last.
        unset($this->matchRowOrder[$selectorId]);
        $this->matchRowOrder[$selectorId] = false;
        if ( $this->matchBytes > $this->maxMatchBytes ) {
            $this->evictMatchRows($selectorId);
        }
        $this->matchPeakEntries = max($this->matchPeakEntries, $this->matchEntries);
        $this->matchPeakBytes = max($this->matchPeakBytes, $this->matchBytes);
    }

    /**
     * Drop whole rows from the front of the eviction order until the cache is
     * an eighth below its bound. A referenced row is moved to the back with
     * its bit cleared instead, so each row is passed over at most once per
     * batch. The batch frees an eighth of the bound, and a pass touches each
     * row at most twice, so eviction costs amortized O(1) per byte stored.
     */
    private function evictMatchRows(int $keep): void
    {
        $target = $this->maxMatchBytes - intdiv($this->maxMatchBytes, 8);
        while ( $this->matchBytes > $target ) {
            $evict = array();
            $requeue = array();
            $bytes = $this->matchBytes;
            foreach ( $this->matchRowOrder as $selectorId => $referenced ) {
                if ( $bytes <= $target || $selectorId === $keep ) {
                    break;
                }
                if ( $referenced ) {
                    $requeue[] = $selectorId;
                    continue;
                }
                $evict[] = $selectorId;
                $bytes -= self::ROW_OVERHEAD_BYTES + strlen($this->matchRows[$selectorId]);
            }
            foreach ( $evict as $selectorId ) {
                $this->matchBytes -= self::ROW_OVERHEAD_BYTES + strlen($this->matchRows[$selectorId]);
                $this->matchEntries -= $this->matchRowResults[$selectorId];
                $this->matchEvictions += $this->matchRowResults[$selectorId];
                ++$this->matchRowEvictions;
                unset($this->matchRows[$selectorId], $this->matchRowBases[$selectorId], $this->matchRowResults[$selectorId], $this->matchRowOrder[$selectorId]);
            }
            foreach ( $requeue as $selectorId ) {
                unset($this->matchRowOrder[$selectorId]);
                $this->matchRowOrder[$selectorId] = false;
            }
            if ( array() === $evict && array() === $requeue ) {
                // Only the row being written remains; it is always kept.
                return;
            }
            if ( array() !== $requeue ) {
                // Keep the row being written behind the second-chance rows.
                unset($this->matchRowOrder[$keep]);
                $this->matchRowOrder[$keep] = false;
            }
        }
    }

    /**
     * The interned id a result is stored under. A selector that reads only
     * its subject's tag name and attributes gives the same result on every
     * element with the same tag name and attributes, so its result is keyed
     * by that full signature. Every other selector reads the element's
     * position and keeps the element key. Both are interned to one id space.
     *
     * @param array<string, mixed> $selector
     */
    private function matchElementId(DOMElement $element, int $selectorId, array $selector): int
    {
        $elementKey = $this->elementKey($element);
        if ( ! $this->subjectOnlySelectors[$selectorId] || ! ($selector['supported'] ?? false) ) {
            return $this->matchElementIds[$elementKey] ??= count($this->matchElementIds);
        }
        ++$this->subjectMatchLookups;
        if ( isset($this->subjectSignatures[$elementKey]) ) {
            return $this->subjectSignatures[$elementKey];
        }

        ++$this->subjectSignatureBuilds;
        // Attribute reads go through hasAttribute()/getAttribute() by
        // qualified name. Namespaced nodes can answer those for names they do
        // not list as plain attributes, so they keep their element key.
        if ( null !== $element->namespaceURI ) {
            return $this->subjectSignatures[$elementKey] = $this->matchElementIds[$elementKey] ??= count($this->matchElementIds);
        }
        $attributes = array();
        foreach ( $element->attributes ?? array() as $attribute ) {
            if ( null !== $attribute->namespaceURI || array_key_exists($attribute->nodeName, $attributes) ) {
                return $this->subjectSignatures[$elementKey] = $this->matchElementIds[$elementKey] ??= count($this->matchElementIds);
            }
            $attributes[$attribute->nodeName] = $attribute->value;
        }
        ksort($attributes, SORT_STRING);
        $signature = 'subject:' . $this->signatureId(serialize(array( strtolower($element->tagName), $attributes )));
        return $this->subjectSignatures[$elementKey] = $this->matchElementIds[$signature] ??= count($this->matchElementIds);
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

<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

use DOMElement;
use DOMNode;
use WeakMap;

/** Caches immutable-DOM selector inputs for one author-selector discovery pass. */
final class CssSelectorMatchCache
{
    public const MAX_MATCHES = 4096;

    public const MAX_CANDIDATE_RULES = 4096;

    public const MAX_CANDIDATE_LISTS = 4096;

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

    /** @var WeakMap<DOMElement, int> */
    private WeakMap $detachedElementKeys;

    /** @var WeakMap<DOMElement, string> */
    private WeakMap $connectedElementKeys;

    private int $nextDetachedElementKey = 0;

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

    public int $connectedElementKeyBuilds = 0;

    public int $connectedElementKeyHits = 0;

    public function __construct()
    {
        $this->detachedElementKeys = new WeakMap();
        $this->connectedElementKeys = new WeakMap();
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
        $key = $this->elementKey($element) . "\0" . $selectorText . "\0" . ($accountForPseudoStateSuffix ? '1' : '0');
        if ( isset($this->matches[$key]) ) {
            ++$this->matchHits;
            $match = $this->matches[$key];
            // PHP arrays retain insertion order, making this a compact LRU queue.
            unset($this->matches[$key]);
            $this->matches[$key] = $match;
            return $match;
        }

        ++$this->matchMisses;
        if ( count($this->matches) >= self::MAX_MATCHES ) {
            unset($this->matches[array_key_first($this->matches)]);
            ++$this->matchEvictions;
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
        $key = $collection . "\0" . $this->elementKey($element);
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
        }
        $candidates = array_merge($candidates, $index['tags'][strtolower($element->tagName)] ?? array());
        foreach ( $this->attributeNames($element) as $name ) {
            $candidates = array_merge($candidates, $index['attributes'][$name] ?? array());
        }

        uasort($candidates, static fn (array $left, array $right): int => $left['order'] <=> $right['order'] ?: (($left['sequence'] ?? $left['order']) <=> ($right['sequence'] ?? $right['order'])));
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
        $this->detachedElementKeys = new WeakMap();
        $this->connectedElementKeys = new WeakMap();
        $this->nextDetachedElementKey = 0;
    }

    private function elementKey(DOMElement $element): string
    {
        if ( isset($this->connectedElementKeys[$element]) ) {
            ++$this->connectedElementKeyHits;
            return $this->connectedElementKeys[$element];
        }

        // PHP may return a new wrapper each time the same native DOM node is
        // fetched, and it reuses spl_object_id() as soon as an old wrapper is
        // released. A connected node's document path is stable across wrappers
        // and unique within this per-document cache revision.
        for ( $ancestor = $element; $ancestor instanceof DOMNode; $ancestor = $ancestor->parentNode ) {
            if ( $ancestor instanceof \DOMDocument ) {
                ++$this->connectedElementKeyBuilds;
                return $this->connectedElementKeys[$element] = 'path:' . $element->getNodePath();
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

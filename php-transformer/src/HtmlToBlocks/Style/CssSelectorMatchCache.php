<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use DOMElement;

/** Caches immutable-DOM selector inputs for one author-selector discovery pass. */
final class CssSelectorMatchCache
{
    private const MAX_MATCHES = 4096;

    /** @var array<string, list<string>> */
    private array $classTokens = array();

    /** @var array<string, array<string, string|null>> */
    private array $attributes = array();

    /** @var array<string, array{supported: bool, matches: bool}> */
    private array $matches = array();

    /** @var array<string, list<array<string, mixed>>> */
    private array $ruleCandidates = array();

    public int $classTokenBuilds = 0;

    public int $classTokenHits = 0;

    public int $attributeReads = 0;

    public int $matchExecutions = 0;

    public int $matchHits = 0;

    public int $candidateRuleChecks = 0;

    public int $candidateRulesSkipped = 0;

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

    /** @param array<string, mixed> $selector @return array{supported: bool, matches: bool} */
    public function matches(DOMElement $element, string $selectorText, array $selector, bool $accountForPseudoStateSuffix = false): array
    {
        $key = $this->elementKey($element) . "\0" . $selectorText . "\0" . ($accountForPseudoStateSuffix ? '1' : '0');
        if ( isset($this->matches[$key]) ) {
            ++$this->matchHits;
            return $this->matches[$key];
        }

        if ( count($this->matches) >= self::MAX_MATCHES ) {
            // Keep input tokens for this immutable revision, but bound selector
            // result retention when a single element sees a huge stylesheet.
            $this->matches = array();
        }
        ++$this->matchExecutions;
        return $this->matches[$key] = CssSelectorMatcher::matches($element, $selector, $accountForPseudoStateSuffix, $this);
    }

    /**
     * @param array{universal: list<array{order: int, rule: array<string, mixed>}>, ids: array<string, list<array{order: int, rule: array<string, mixed>}>>, classes: array<string, list<array{order: int, rule: array<string, mixed>}>>, tags: array<string, list<array{order: int, rule: array<string, mixed>}>>, total: int} $index
     * @return list<array<string, mixed>>
     */
    public function styleRuleCandidates(DOMElement $element, string $collection, array $index): array
    {
        $key = $collection . "\0" . $this->elementKey($element);
        if ( isset($this->ruleCandidates[$key]) ) {
            return $this->ruleCandidates[$key];
        }

        $candidates = $index['universal'];
        $id = $this->attribute($element, 'id');
        if ( null !== $id ) {
            $candidates = array_merge($candidates, $index['ids'][$id] ?? array());
        }
        foreach ( $this->classTokens($element) as $class ) {
            $candidates = array_merge($candidates, $index['classes'][$class] ?? array());
        }
        $candidates = array_merge($candidates, $index['tags'][strtolower($element->tagName)] ?? array());

        $ordered = array();
        foreach ( $candidates as $candidate ) {
            $ordered[$candidate['order']] = $candidate['rule'];
        }
        ksort($ordered, SORT_NUMERIC);
        $rules = array_values($ordered);
        $this->candidateRuleChecks += count($rules);
        $this->candidateRulesSkipped += $index['total'] - count($rules);

        return $this->ruleCandidates[$key] = $rules;
    }

    /** Clear results and selector inputs after a source-DOM mutation. */
    public function clear(): void
    {
        $this->classTokens = array();
        $this->attributes = array();
        $this->matches = array();
        $this->ruleCandidates = array();
    }

    private function elementKey(DOMElement $element): string
    {
        return (string) spl_object_id($element);
    }
}

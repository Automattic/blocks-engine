<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;
use DOMElement;

/** Context-free rightmost-compound prefilter; candidates still need selector matching. */
final class CssSubjectIndex
{
    /** Rules carry parsed_selector; their input positions preserve cascade order. */
    public static function build(array $rules): array
    {
        $index = array('universal' => array(), 'ids' => array(), 'classes' => array(), 'tags' => array(), 'attributes' => array(), 'total' => count($rules));
        foreach ($rules as $order => $rule) self::insert($index, self::buckets($rule['parsed_selector']), array('order' => (int) $order, 'rule' => $rule));
        return $index;
    }
    /** Files one entry under every subject key; entries() returns it once. */
    public static function insert(array &$index, array $buckets, array $entry): void
    {
        foreach ($buckets as [$target, $key]) {
            if ('universal' === $target) $index['universal'][] = $entry;
            else $index[$target][$key][] = $entry;
        }
    }
    /**
     * Subject keys an element must carry to match. An `:is()`/`:where()` subject
     * matches only through one of its alternatives, so it is filed under each
     * alternative's key; a subject with no key anywhere stays universal.
     *
     * Author structural selectors can also be matched without their state suffix.
     *
     * @return list<array{0:string,1:string}>
     */
    public static function buckets(array $parsed, bool $allowStateSuffix = false): array
    {
        $compounds = $parsed['compounds'] ?? array();
        $subject = array() === $compounds ? null : $compounds[array_key_last($compounds)];
        if (($parsed['supported'] ?? false) && ($allowStateSuffix || null === ($parsed['pseudo_state_suffix_span'] ?? null)) && is_array($subject)) {
            return self::compoundBuckets($subject) ?? array(array('universal', ''));
        }
        return array(array('universal', ''));
    }
    /** @return list<array{0:string,1:string}>|null */
    private static function compoundBuckets(array $subject): ?array
    {
        foreach (array('ids', 'classes') as $bucket) {
            if (array() !== ($subject[$bucket] ?? array())) return array(array($bucket, (string) $subject[$bucket][0]));
        }
        if (is_string($subject['type'] ?? null) && '' !== $subject['type']) return array(array('tags', strtolower($subject['type'])));
        $name = (string) ($subject['attributes'][0]['name'] ?? '');
        if (1 === preg_match('/^[a-z][a-z0-9_-]*$/', $name)) return array(array('attributes', $name));
        foreach ($subject['any'] ?? array() as $group) {
            $buckets = array();
            foreach ($group['alternatives'] ?? array(null) as $alternative) {
                // The parser keeps only alternatives it can match.
                $compounds = is_array($alternative) ? ($alternative['compounds'] ?? array()) : array();
                $keys = array() === $compounds ? null : self::compoundBuckets($compounds[array_key_last($compounds)]);
                if (null === $keys) continue 2;
                foreach ($keys as $key) $buckets[implode("\0", $key)] = $key;
            }
            if (array() !== $buckets) return array_values($buckets);
        }
        return null;
    }
    /** Ordered entries, including sequence ties used by author-style indexes. */
    public static function entries(DOMElement $element, array $index, ?CssSelectorMatchCache $cache = null): array
    {
        $candidates = $index['universal'];
        $id = null !== $cache ? $cache->attribute($element, 'id') : ($element->hasAttribute('id') ? $element->getAttribute('id') : null);
        if (null !== $id) $candidates = array_merge($candidates, $index['ids'][$id] ?? array());
        $classes = null !== $cache ? $cache->classTokens($element) : (preg_split('/[\x09\x0A\x0C\x0D\x20]+/', trim($element->getAttribute('class'))) ?: array());
        foreach ($classes as $class) {
            $candidates = array_merge($candidates, $index['classes'][$class] ?? array());
            $anchoredId = EngineMarker::editorAnchorId($class);
            if (null !== $anchoredId && $anchoredId !== $id) $candidates = array_merge($candidates, $index['ids'][$anchoredId] ?? array());
        }
        $candidates = array_merge($candidates, $index['tags'][strtolower($element->tagName)] ?? array());
        $names = null !== $cache ? $cache->attributeNames($element) : array_map(static fn (\DOMAttr $attribute): string => strtolower($attribute->nodeName), iterator_to_array($element->attributes));
        foreach ($names as $name) $candidates = array_merge($candidates, $index['attributes'][$name] ?? array());
        $unique = array();
        foreach ($candidates as $candidate) $unique[$candidate['order'] . ':' . ($candidate['sequence'] ?? '')] = $candidate;
        $candidates = $unique;
        uasort($candidates, static fn (array $left, array $right): int => $left['order'] <=> $right['order'] ?: (($left['sequence'] ?? $left['order']) <=> ($right['sequence'] ?? $right['order'])));
        return $candidates;
    }
}

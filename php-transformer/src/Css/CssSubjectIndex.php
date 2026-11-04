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
        foreach ($rules as $order => $rule) {
            [$target, $key] = self::bucket($rule['parsed_selector']);
            if ('universal' === $target) $index['universal'][] = array('order' => (int) $order, 'rule' => $rule);
            else $index[$target][$key][] = array('order' => (int) $order, 'rule' => $rule);
        }
        return $index;
    }
    /** Author structural selectors can also be matched without their state suffix. */
    public static function bucket(array $parsed, bool $allowStateSuffix = false): array
    {
        $compounds = $parsed['compounds'] ?? array();
        $subject = array() === $compounds ? null : $compounds[array_key_last($compounds)];
        if ($parsed['supported'] && ($allowStateSuffix || null === ($parsed['pseudo_state_suffix_span'] ?? null)) && is_array($subject)) {
            foreach (array('ids', 'classes') as $bucket) {
                if (array() !== ($subject[$bucket] ?? array())) return array($bucket, (string) $subject[$bucket][0]);
            }
            if (is_string($subject['type'] ?? null) && '' !== $subject['type']) return array('tags', strtolower($subject['type']));
            $name = (string) ($subject['attributes'][0]['name'] ?? '');
            if (1 === preg_match('/^[a-z][a-z0-9_-]*$/', $name)) return array('attributes', $name);
        }
        return array('universal', '');
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
        uasort($candidates, static fn (array $left, array $right): int => $left['order'] <=> $right['order'] ?: (($left['sequence'] ?? $left['order']) <=> ($right['sequence'] ?? $right['order'])));
        return $candidates;
    }
}

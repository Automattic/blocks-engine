<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use DOMElement;
use InvalidArgumentException;

/** Emits bounded declared layout facts; it never computes browser geometry. */
final class FormLayoutGraphBuilder
{
    private const MAX_NODES = 128;
    private const MAX_DEPTH = 16;
    private const MAX_RULES_PER_NODE = 16;
    private const MAX_CSS_BYTES = 262144;
    private const MAX_RULES = 512;
    private const MAX_SELECTORS = 1024;
    // Parsing work and retained cascade candidates have independent bounds.
    private const MAX_SCANNED_SELECTORS = 4096;
    private const MAX_CONDITION_DEPTH = 8;
    private const MAX_VARIANTS = 256;
    private const MAX_PROVENANCE = 16;
    private const PROPERTIES = array( 'display', 'width', 'grid-template-columns', 'grid-template-rows', 'gap', 'row-gap', 'column-gap', 'grid-column', 'grid-row', 'grid-area', 'flex-direction', 'flex-wrap', 'align-items', 'align-content', 'justify-content', 'align-self', 'justify-self', 'order', 'flex', 'flex-grow', 'flex-shrink', 'flex-basis' );
    private const LAYOUT_KEYS = array( 'display', 'width', 'columns', 'rows', 'gap', 'row_gap', 'column_gap', 'column', 'row', 'area', 'direction', 'wrap', 'align_items', 'align_content', 'justify_content', 'align_self', 'justify_self', 'order', 'flex', 'flex_grow', 'flex_shrink', 'flex_basis' );
    private const V1_PROPERTIES = array( 'display', 'grid-template-columns', 'grid-template-rows', 'gap', 'row-gap', 'column-gap', 'grid-column', 'grid-row', 'grid-area', 'flex-direction', 'flex-wrap', 'align-items', 'align-content', 'justify-content', 'align-self', 'justify-self', 'order', 'flex', 'flex-grow', 'flex-shrink', 'flex-basis' );
    private const V1_LAYOUT_KEYS = array( 'display', 'columns', 'rows', 'gap', 'row_gap', 'column_gap', 'column', 'row', 'area', 'direction', 'wrap', 'align_items', 'align_content', 'justify_content', 'align_self', 'justify_self', 'order', 'flex', 'flex_grow', 'flex_shrink', 'flex_basis' );

    private array $diagnostics = array();
    private bool $truncated = false;

    /** @param list<array<string, mixed>> $stylesheets @return array<string, mixed> */
    public function build(DOMElement $form, array $stylesheets, string $inlineCss = ''): array
    {
        $this->diagnostics = array();
        $this->truncated = false;
        $controls = array();
        $relevant = array();
        foreach ( $this->controls($form) as $index => $control ) {
            $controls[$control->getNodePath()] = $index;
            for ( $node = $control->parentNode; $node instanceof DOMElement && $node !== $form; $node = $node->parentNode ) {
                $relevant[$node->getNodePath()] = true;
            }
        }

        $entries = array();
        $wrapper = 0;
        // The form root is outside the topology depth coordinate; its children are depth zero.
        $this->collect($form, null, 0, -1, $controls, $relevant, $entries, $wrapper);
        $analysis = (new CssRuleAnalyzer())->analyze(
            $stylesheets,
            $inlineCss,
            self::PROPERTIES,
            self::MAX_CSS_BYTES,
            self::MAX_RULES,
            self::MAX_SELECTORS,
            self::MAX_CONDITION_DEPTH,
            static function (array $selector) use ($entries): bool {
                foreach ( $entries as $entry ) {
                    $match = CssSelectorMatcher::matches($entry['element'], $selector);
                    if ( ! $match['supported'] || $match['matches'] ) {
                        return true;
                    }
                }
                return false;
            },
            self::MAX_SCANNED_SELECTORS
        );
        $this->diagnostics = array_merge($this->diagnostics, $analysis['diagnostics']);
        $this->truncated = $this->truncated || $analysis['truncated'];
        $nodes = array();
        $variants = array();
        foreach ( $entries as $entry ) {
            $matched = $this->matched($entry['element'], $analysis['rules']);
            $layout = $this->layout($matched['base']);
            $conditional = $this->effectiveConditional($matched['conditional'], $matched['base']);
            if ( array() === $layout && array() === $conditional ) {
                continue;
            }

            $nodes[$entry['id']] = $this->node($entry, $layout, $this->provenance($matched['base'], null));
            foreach ( $conditional as $encoded => $facts ) {
                if ( count($variants) >= self::MAX_VARIANTS ) {
                    $this->truncated = true;
                    $this->diagnostics[] = 'variant_limit';
                    break;
                }
                $condition = json_decode($encoded, true);
                $patch = $this->layout($facts);
                if ( array() !== $patch ) {
                    $variants[] = array( 'node' => $entry['id'], 'condition' => $condition, 'layout_patch' => $patch, 'precedence' => $this->precedence($facts), 'provenance' => $this->provenance($facts, $condition) );
                }
            }
        }

        // Layout-bearing descendants retain structural ancestry even when the ancestor has no layout declaration.
        foreach ( $entries as $entry ) {
            if ( isset($nodes[$entry['id']]) ) {
                continue;
            }
            foreach ( array_keys($nodes) as $nodeId ) {
                if ( $this->hasAncestor($entries, $nodeId, $entry['id']) ) {
                    $nodes[$entry['id']] = $this->node($entry, array(), array());
                    break;
                }
            }
        }

        $graph = array(
            'schema' => 'generic/computed-layout-graph/v2',
            'basis' => 'source_css_cascade',
            'truncated' => $this->truncated,
            'limits' => array( 'nodes' => self::MAX_NODES, 'depth' => self::MAX_DEPTH, 'rules_per_node' => self::MAX_RULES_PER_NODE ),
            'nodes' => array_values(array_filter($entries, static fn (array $entry): bool => isset($nodes[$entry['id']]))),
            'variants' => $variants,
            'diagnostics' => array_values(array_unique($this->diagnostics)),
        );
        $graph['nodes'] = array_map(static fn (array $entry): array => $nodes[$entry['id']], $graph['nodes']);
        self::assertValid($graph);
        return $graph;
    }

    /** @param array<string, mixed> $entry @param array<string, string> $layout @param list<array<string, mixed>> $provenance */
    private function node(array $entry, array $layout, array $provenance): array
    {
        return array_filter(array( 'id' => $entry['id'], 'kind' => $entry['kind'], 'parent' => $entry['parent'], 'order' => $entry['order'], 'source' => $this->source($entry['element']), 'layout' => $layout, 'provenance' => $provenance ), static fn (mixed $value): bool => null !== $value);
    }

    /** @param array<string, mixed> $graph */
    public static function assertValid(array $graph): void
    {
        $schema = $graph['schema'] ?? null;
        $v1 = 'generic/computed-layout-graph/v1' === $schema;
        $depth = $v1 ? 8 : self::MAX_DEPTH;
        $properties = $v1 ? self::V1_PROPERTIES : self::PROPERTIES;
        $layoutKeys = $v1 ? self::V1_LAYOUT_KEYS : self::LAYOUT_KEYS;
        if ( (! $v1 && 'generic/computed-layout-graph/v2' !== $schema) || 'source_css_cascade' !== ($graph['basis'] ?? null) || ! is_bool($graph['truncated'] ?? null) || ! is_array($graph['limits'] ?? null) || self::MAX_NODES !== ($graph['limits']['nodes'] ?? null) || $depth !== ($graph['limits']['depth'] ?? null) || self::MAX_RULES_PER_NODE !== ($graph['limits']['rules_per_node'] ?? null) || ! is_array($graph['nodes'] ?? null) || ! is_array($graph['variants'] ?? null) || ! is_array($graph['diagnostics'] ?? null) ) {
            throw new InvalidArgumentException('Form layout graph envelope is invalid.');
        }
        if ( count($graph['nodes']) > self::MAX_NODES ) {
            throw new InvalidArgumentException('Form layout graph exceeds its node limit.');
        }
        $ids = array();
        foreach ( $graph['nodes'] as $node ) {
            if ( ! is_array($node) || ! is_string($node['id'] ?? null) || isset($ids[$node['id']]) || ! in_array($node['kind'] ?? null, array( 'container', 'control' ), true) || ! is_int($node['order'] ?? null) || ! is_array($node['source'] ?? null) || ! is_string($node['source']['tag'] ?? null) || ! is_array($node['source']['classes'] ?? null) || ! is_array($node['layout'] ?? null) || ! is_array($node['provenance'] ?? null) ) {
                throw new InvalidArgumentException('Form layout graph node is invalid.');
            }
            $ids[$node['id']] = true;
        }
        foreach ( $graph['nodes'] as $node ) {
            if ( null !== ($node['parent'] ?? null) && ! isset($ids[$node['parent']]) ) {
                throw new InvalidArgumentException('Form layout graph has a dangling parent.');
            }
            for ( $parent = $node['parent'] ?? null, $seen = array( $node['id'] => true ); null !== $parent; ) {
                if ( isset($seen[$parent]) ) {
                    throw new InvalidArgumentException('Form layout graph has a parent cycle.');
                }
                $seen[$parent] = true;
                foreach ( $graph['nodes'] as $candidate ) {
                    if ( $candidate['id'] === $parent ) {
                        $parent = $candidate['parent'] ?? null;
                        continue 2;
                    }
                }
                break;
            }
            self::assertFacts($node['layout'], $node['provenance'], null, $properties, $layoutKeys);
        }
        if ( count($graph['variants']) > self::MAX_VARIANTS ) {
            throw new InvalidArgumentException('Form layout graph exceeds its variant limit.');
        }
        foreach ( $graph['variants'] as $variant ) {
            if ( ! is_array($variant) || ! isset($ids[$variant['node'] ?? '']) || ! is_array($variant['condition'] ?? null) || ! self::validCondition($variant['condition']) || ! is_array($variant['layout_patch'] ?? null) || ! is_array($variant['precedence'] ?? null) || ! is_array($variant['provenance'] ?? null) ) {
                throw new InvalidArgumentException('Form layout graph variant is invalid.');
            }
            foreach ( $variant['precedence'] as $property => $precedence ) {
                if ( ! is_string($property) || ! in_array($property, $properties, true) || ! isset($variant['layout_patch'][self::layoutKey($property)]) || ! is_array($precedence) || ! is_int($precedence['source_order'] ?? null) || ! is_int($precedence['specificity'] ?? null) || ! is_bool($precedence['important'] ?? null) ) {
                    throw new InvalidArgumentException('Form layout graph variant precedence is invalid.');
                }
            }
            self::assertFacts($variant['layout_patch'], $variant['provenance'], $variant['condition'], $properties, $layoutKeys);
        }
    }

    private static function assertFacts(array $layout, array $provenance, ?array $condition, array $properties, array $layoutKeys): void
    {
        foreach ( $layout as $key => $value ) {
            if ( ! is_string($key) || ! in_array($key, $layoutKeys, true) || ! is_string($value) || '' === trim($value) ) {
                throw new InvalidArgumentException('Form layout graph value is invalid.');
            }
        }
        if ( count($provenance) > self::MAX_PROVENANCE ) {
            throw new InvalidArgumentException('Form layout graph provenance exceeds its limit.');
        }
        foreach ( $provenance as $fact ) {
            if ( ! is_array($fact) || ! is_string($fact['source_path'] ?? null) || ! preg_match('~^(?!.*(?:^|/)\.\.(?:/|$))[A-Za-z0-9._/-]+$~', $fact['source_path']) || ! preg_match('/^[a-f0-9]{64}$/', $fact['source_sha256'] ?? '') || ! is_string($fact['selector'] ?? null) || '' === trim($fact['selector']) || strlen($fact['selector']) > 1024 || ! is_array($fact['properties'] ?? null) || array() === $fact['properties'] || count($fact['properties']) > count($properties) || array_filter($fact['properties'], static fn (mixed $property): bool => ! is_string($property) || ! in_array($property, $properties, true) || ! isset($layout[self::layoutKey($property)])) || ($condition !== null && $fact['condition'] !== $condition) || ($condition === null && ($fact['condition'] ?? null) !== null) ) {
                throw new InvalidArgumentException('Form layout graph provenance is invalid.');
            }
        }
    }

    private static function validCondition(array $condition, int $depth = 0): bool
    {
        if ( $depth > self::MAX_CONDITION_DEPTH ) {
            return false;
        }
        if ( 'all' === ($condition['kind'] ?? null) ) {
            return is_array($condition['conditions'] ?? null) && array() !== $condition['conditions'] && count($condition['conditions']) <= self::MAX_CONDITION_DEPTH && array_reduce($condition['conditions'], static fn (bool $ok, mixed $item): bool => $ok && is_array($item) && self::validCondition($item, $depth + 1), true);
        }
        return in_array($condition['kind'] ?? null, array( 'media', 'container', 'supports' ), true) && is_string($condition['query'] ?? null) && '' !== trim($condition['query']) && strlen($condition['query']) <= 1024;
    }

    /** @param array<string, int> $controls @param array<string, bool> $relevant @param list<array<string, mixed>> $entries */
    private function collect(DOMElement $element, ?string $parent, int $order, int $depth, array $controls, array $relevant, array &$entries, int &$wrapper): void
    {
        $path = $element->getNodePath();
        $root = 'form' === strtolower($element->tagName) && null === $parent;
        if ( ! $root && ! isset($controls[$path]) && ! isset($relevant[$path]) ) {
            return;
        }
        if ( $depth > self::MAX_DEPTH || count($entries) >= self::MAX_NODES ) {
            $this->truncated = true;
            $this->diagnostics[] = 'node_or_depth_limit';
            return;
        }
        $id = $root ? 'form' : (isset($controls[$path]) ? 'control-' . $controls[$path] : 'wrapper-' . $wrapper++);
        $entries[] = array( 'id' => $id, 'kind' => isset($controls[$path]) ? 'control' : 'container', 'parent' => $parent, 'order' => $order, 'element' => $element );
        $childOrder = 0;
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            $before = count($entries);
            $this->collect($child, $id, $childOrder, $depth + 1, $controls, $relevant, $entries, $wrapper);
            if ( count($entries) > $before ) {
                ++$childOrder;
            }
        }
    }

    /** @param list<array<string, mixed>> $entries */
    private function hasAncestor(array $entries, string $node, string $ancestor): bool
    {
        foreach ( $entries as $entry ) {
            if ( $entry['id'] !== $node ) {
                continue;
            }
            for ( $parent = $entry['parent']; null !== $parent; ) {
                if ( $parent === $ancestor ) {
                    return true;
                }
                foreach ( $entries as $candidate ) {
                    if ( $candidate['id'] === $parent ) {
                        $parent = $candidate['parent'];
                        continue 2;
                    }
                }
                return false;
            }
        }
        return false;
    }

    /** @return list<DOMElement> */
    private function controls(DOMElement $form): array
    {
        $result = array();
        foreach ( $form->getElementsByTagName('*') as $element ) {
            if ( in_array(strtolower($element->tagName), array( 'input', 'select', 'textarea', 'button' ), true) ) {
                $result[] = $element;
            }
        }
        return $result;
    }

    /** @param list<array<string, mixed>> $rules @return array{base: array<string, array<string, mixed>>, conditional: array<string, array<string, array<string, mixed>>>} */
    private function matched(DOMElement $element, array $rules): array
    {
        $base = array();
        if ( $element->hasAttribute('style') ) {
            $inline = $element->getAttribute('style');
            foreach ( CssRuleAnalyzer::declarations($inline, self::PROPERTIES) as $declaration ) {
                $important = 1 === preg_match('/\s*!important\s*$/i', $declaration['value']);
                $value = preg_replace('/\s*!important\s*$/i', '', $declaration['value']) ?? $declaration['value'];
                CssCascade::apply($base, $declaration['name'], array( 'value' => $value, 'path' => 'inline-style', 'hash' => hash('sha256', $inline), 'selector' => '[style]', 'order' => PHP_INT_MAX, 'specificity' => PHP_INT_MAX, 'important' => $important ));
            }
        }
        $conditional = array();
        $matched = 0;
        foreach ( $rules as $rule ) {
            $match = ! empty($rule['inline']) ? array( 'supported' => true, 'matches' => true ) : CssSelectorMatcher::matches($element, $rule['parsed_selector']);
            if ( ! $match['supported'] ) {
                $this->diagnostics[] = 'unsupported_selector:' . $rule['selector'];
                continue;
            }
            if ( ! $match['matches'] ) {
                continue;
            }
            if ( $matched++ >= self::MAX_RULES_PER_NODE ) {
                $this->truncated = true;
                $this->diagnostics[] = 'rules_per_node_limit';
                break;
            }
            foreach ( $rule['declarations'] as $declaration ) {
                $important = 1 === preg_match('/\s*!important\s*$/i', $declaration['value']);
                $value = preg_replace('/\s*!important\s*$/i', '', $declaration['value']) ?? $declaration['value'];
                $fact = array( 'value' => $value, 'path' => $rule['path'], 'hash' => $rule['hash'], 'selector' => $rule['selector'], 'order' => $rule['order'], 'specificity' => $rule['specificity'], 'important' => $important );
                $key = null === $rule['condition'] ? null : json_encode($rule['condition']);
                if ( null === $key ) {
                    $target =& $base;
                } else {
                    $conditional[$key] ??= array();
                    $target =& $conditional[$key];
                }
                $current = $target[$declaration['name']] ?? null;
                if ( is_array($current) && $current['value'] !== $value ) {
                    $this->diagnostics[] = 'cascade_conflict:' . $declaration['name'];
                }
                CssCascade::apply($target, $declaration['name'], $fact);
                unset($target);
            }
        }
        return array( 'base' => $base, 'conditional' => $conditional );
    }

    /** @return array<string, string> */
    private function layout(array $facts): array
    {
        $result = array();
        foreach ( $facts as $property => $fact ) {
            $result[self::layoutKey($property)] = $fact['value'];
        }
        ksort($result);
        return $result;
    }

    private static function layoutKey(string $property): string
    {
        return array( 'grid-template-columns' => 'columns', 'grid-template-rows' => 'rows', 'row-gap' => 'row_gap', 'column-gap' => 'column_gap', 'grid-column' => 'column', 'grid-row' => 'row', 'grid-area' => 'area', 'flex-direction' => 'direction', 'flex-wrap' => 'wrap', 'align-items' => 'align_items', 'align-content' => 'align_content', 'justify-content' => 'justify_content', 'align-self' => 'align_self', 'justify-self' => 'justify_self', 'flex-grow' => 'flex_grow', 'flex-shrink' => 'flex_shrink', 'flex-basis' => 'flex_basis' )[$property] ?? $property;
    }

    /** @return array<string, array<string, array<string, mixed>>> */
    private function effectiveConditional(array $conditional, array $base): array
    {
        foreach ( $conditional as $condition => &$facts ) {
            foreach ( $facts as $property => $fact ) {
                if ( isset($base[$property]) && ! CssCascade::wins($fact, $base[$property]) ) {
                    unset($facts[$property]);
                }
            }
            if ( array() === $facts ) {
                unset($conditional[$condition]);
            }
        }
        unset($facts);
        return $conditional;
    }

    /** @return array<string, array<string, int|bool>> */
    private function precedence(array $facts): array
    {
        $result = array();
        foreach ( $facts as $property => $fact ) {
            $result[$property] = array( 'source_order' => $fact['order'], 'specificity' => $fact['specificity'], 'important' => $fact['important'] );
        }
        ksort($result);
        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function provenance(array $facts, ?array $condition): array
    {
        $grouped = array();
        foreach ( $facts as $property => $fact ) {
            $key = $fact['path'] . "\n" . $fact['selector'];
            $grouped[$key] ??= array( 'source_path' => $fact['path'], 'source_sha256' => $fact['hash'], 'selector' => $fact['selector'], 'condition' => $condition, 'properties' => array() );
            $grouped[$key]['properties'][] = $property;
        }
        foreach ( $grouped as &$item ) {
            sort($item['properties'], SORT_STRING);
        }
        unset($item);
        return array_values($grouped);
    }

    /** @return array<string, mixed> */
    private function source(DOMElement $element): array
    {
        $classes = array_values(array_filter(preg_split('/\s+/', trim($element->getAttribute('class'))) ?: array(), static fn (string $class): bool => 1 === preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,79}$/D', $class)));
        return array( 'tag' => strtolower($element->tagName), 'id' => 1 === preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,79}$/D', $element->getAttribute('id')) ? $element->getAttribute('id') : null, 'classes' => array_slice($classes, 0, 8) );
    }
}

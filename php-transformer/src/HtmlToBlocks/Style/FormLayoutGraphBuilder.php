<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Css\CssAnalysisLimits;
use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;
use DOMElement;
use InvalidArgumentException;

/** Emits bounded declared layout facts; it never computes browser geometry. */
final class FormLayoutGraphBuilder
{
    private const MAX_NODES = 128;
    private const MAX_DEPTH = 16;
    private const MAX_RULES_PER_NODE = 16;
    private const MAX_RULES = 512;
    private const MAX_SELECTORS = 1024;
    private const MAX_CONDITION_DEPTH = 8;
    private const MAX_VARIANTS = 256;
    private const MAX_PROVENANCE = 16;
    private const SOURCE_BOX_PROPERTIES = array('margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'position', 'top', 'right', 'bottom', 'left', 'place-self');
    // Shorthands read from source and expanded into their longhands before the
    // cascade; they never appear as graph keys themselves.
    private const SHORTHAND_PROPERTIES = array('flex-flow');
    private const SOURCE_BOX_KEYS = array('margin_top', 'margin_right', 'margin_bottom', 'margin_left', 'position', 'top', 'right', 'bottom', 'left');
    private const PROPERTIES = array( 'display', 'width', 'height', 'min-height', 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'padding-block-start', 'padding-block-end', 'padding-inline-start', 'padding-inline-end', 'grid-template-columns', 'grid-template-rows', 'gap', 'row-gap', 'column-gap', 'grid-column', 'grid-row', 'grid-area', 'flex-direction', 'flex-wrap', 'align-items', 'align-content', 'justify-content', 'align-self', 'justify-items', 'justify-self', 'order', 'flex', 'flex-grow', 'flex-shrink', 'flex-basis', 'margin-block-start', 'margin-block-end', 'margin-inline-start', 'margin-inline-end' );
    private const LAYOUT_KEYS = array( 'display', 'width', 'height', 'min_height', 'padding', 'padding_top', 'padding_right', 'padding_bottom', 'padding_left', 'padding_block_start', 'padding_block_end', 'padding_inline_start', 'padding_inline_end', 'columns', 'rows', 'gap', 'row_gap', 'column_gap', 'column', 'row', 'area', 'direction', 'wrap', 'align_items', 'align_content', 'justify_content', 'align_self', 'justify_items', 'justify_self', 'order', 'flex', 'flex_grow', 'flex_shrink', 'flex_basis', 'margin_block_start', 'margin_block_end', 'margin_inline_start', 'margin_inline_end' );
    private const V1_PROPERTIES = array( 'display', 'grid-template-columns', 'grid-template-rows', 'gap', 'row-gap', 'column-gap', 'grid-column', 'grid-row', 'grid-area', 'flex-direction', 'flex-wrap', 'align-items', 'align-content', 'justify-content', 'align-self', 'justify-self', 'order', 'flex', 'flex-grow', 'flex-shrink', 'flex-basis' );
    private const V1_LAYOUT_KEYS = array( 'display', 'columns', 'rows', 'gap', 'row_gap', 'column_gap', 'column', 'row', 'area', 'direction', 'wrap', 'align_items', 'align_content', 'justify_content', 'align_self', 'justify_self', 'order', 'flex', 'flex_grow', 'flex_shrink', 'flex_basis' );
    // Cascade layers are not unlayered flex/grid structure, but a field-list
    // gap still has to reach the provider form that owns the stacked fields.
    private const SPACING_PROPERTIES = array( 'gap', 'row-gap', 'column-gap' );
    // An element holding any of these paints content even without text, so
    // it is never an empty box.
    private const VISIBLE_CONTENT_TAGS = array( 'audio', 'br', 'button', 'canvas', 'embed', 'hr', 'iframe', 'img', 'input', 'link', 'meta', 'meter', 'noscript', 'object', 'picture', 'progress', 'script', 'select', 'style', 'svg', 'template', 'textarea', 'video' );
    // Non-replaced inline boxes ignore `height`, so they reserve no block space.
    private const INLINE_TAGS = array( 'a', 'abbr', 'b', 'bdi', 'bdo', 'cite', 'code', 'data', 'dfn', 'em', 'i', 'kbd', 'label', 'mark', 'q', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var' );
    private const EMPTY_BOX_KEYS = array( 'content', 'in_flow', 'reserves_space' );

    private array $diagnostics = array();
    private bool $truncated = false;
    private array $nodeIds = array();

    /**
     * @param list<array<string, mixed>> $stylesheets
     * @param list<array<string, mixed>>|null $structure In-process node list including DOM elements; not part of the serialized graph.
     * @return array<string, mixed>
     */
    public function build(DOMElement $form, array $stylesheets, string $inlineCss = '', ?array &$structure = null): array
    {
        $this->diagnostics = array();
        $this->truncated = false;
        $controls = array();
        $relevant = array();
        $this->nodeIds = array();
        foreach ( $this->controls($form) as $index => $control ) {
            $controls[$control->getNodePath()] = $index;
            for ( $node = $control->parentNode; $node instanceof DOMElement && $node !== $form; $node = $node->parentNode ) {
                $relevant[$node->getNodePath()] = true;
            }
        }

        // Reserve the existing control-topology wrapper coordinate before
        // inserting copy-only nodes. Adding an introduction must not renumber
        // a field or submit wrapper used by the paired provider target map.
        $controlEntries = array();
        $wrapper = 0;
        $this->collect($form, null, 0, -1, $controls, $relevant, $controlEntries, $wrapper);
        foreach ($controlEntries as $entry) $this->nodeIds[$entry['element']->getNodePath()] = $entry['id'];

        // Copy-only boxes participate in the source tree independently of
        // control topology. Control indices retain their shared enumeration.
        foreach ( $form->getElementsByTagName('*') as $copy ) {
            if ( ! $copy instanceof DOMElement || ! in_array(strtolower($copy->tagName), array( 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true) || '' === trim($copy->textContent) ) {
                continue;
            }
            for ( $node = $copy; $node instanceof DOMElement && $node !== $form; $node = $node->parentNode ) {
                $relevant[$node->getNodePath()] = true;
            }
        }

        $entries = array();
        $wrapper = 0;
        // The graph root is outside the topology depth coordinate; its children are depth zero.
        $this->collect($form, null, 0, -1, $controls, $relevant, $entries, $wrapper);
        if ( null !== $structure ) {
            // Taken before empty boxes join: they are layout evidence only,
            // and block conversion keeps treating them as flow content.
            $structure = $entries;
        }
        // Child counts include boxes omitted from the control/copy topology.
        $scope = array_column($entries, 'element');
        foreach ( $entries as $entry ) {
            if ( 'container' !== $entry['kind'] ) continue;
            foreach ( $entry['element']->childNodes as $child ) {
                if ( $child instanceof DOMElement ) $scope[] = $child;
            }
        }
        $analysis = (new CssRuleAnalyzer())->analyze(
            $stylesheets,
            $inlineCss,
            array_merge(self::PROPERTIES, self::SOURCE_BOX_PROPERTIES, self::SHORTHAND_PROPERTIES),
            CssAnalysisLimits::MAX_STYLESHEET_BYTES,
            self::MAX_RULES,
            self::MAX_SELECTORS,
            self::MAX_CONDITION_DEPTH,
            static function (array $selector) use ($scope): bool {
                foreach ( $scope as $element ) {
                    $match = CssSelectorMatcher::matches($element, $selector);
                    if ( ! $match['supported'] || $match['matches'] ) {
                        return true;
                    }
                }
                return false;
            },
            CssAnalysisLimits::MAX_SCANNED_SELECTORS
        );
        $this->diagnostics = array_merge($this->diagnostics, $analysis['diagnostics']);
        $this->truncated = $this->truncated || $analysis['truncated'];
        $customPropertyAnalysis = (new CssRuleAnalyzer())->analyze(
            $stylesheets,
            $inlineCss,
            array('--*'),
            CssAnalysisLimits::MAX_STYLESHEET_BYTES,
            self::MAX_RULES,
            self::MAX_SELECTORS,
            self::MAX_CONDITION_DEPTH,
            static function (array $selector) use ($scope): bool {
                foreach ( $scope as $element ) {
                    for ( $ancestor = $element; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode instanceof DOMElement ? $ancestor->parentNode : null ) {
                        if ( CssSelectorMatcher::matches($ancestor, $selector)['matches'] ) {
                            return true;
                        }
                    }
                }
                return false;
            },
            CssAnalysisLimits::MAX_SCANNED_SELECTORS
        );
        $this->diagnostics = array_merge($this->diagnostics, $customPropertyAnalysis['diagnostics']);
        $this->truncated = $this->truncated || $customPropertyAnalysis['truncated'];

        // Empty in-flow boxes between fields (a reserved validation slot)
        // occupy space without content. They take their own `empty-N`
        // coordinate, so existing wrapper, control and context ids are stable.
        $emptyBoxes = $this->emptyBoxes($form, $entries, $controls, $relevant, $analysis['rules'], self::MAX_NODES - count($entries));
        if ( array() !== $emptyBoxes ) {
            $entries = array();
            $wrapper = 0;
            $this->collect($form, null, 0, -1, $controls, $relevant, $entries, $wrapper, $emptyBoxes);
        }
        $nodes = array();
        $presentationBuilder = new FormPresentationGraphBuilder(scopeElements: array_column($entries, 'element'));
        $variants = array();
        $resolved = array();
        $hidden = array();
        foreach ( $entries as $entry ) {
            $matched = $this->matched($entry['element'], $analysis['rules']);
            $conditional = $this->effectiveConditional($matched['conditional'], $matched['base']);
            $resolved[$entry['id']] = array( $matched, $conditional );
            foreach ( $conditional as $encoded => $facts ) {
                if ( 'none' === strtolower(trim((string) ($facts['display']['value'] ?? ''))) ) {
                    $hidden[$entry['id']][$encoded] = true;
                }
            }
        }
        $parents = array_column($entries, 'parent', 'id');
        foreach ( $entries as $entry ) {
            [ $matched, $conditional ] = $resolved[$entry['id']];
            // Under a condition where an ancestor is display:none, a descendant's
            // conditional layout never renders; it is not a responsive variant.
            for ( $ancestor = $parents[$entry['id']] ?? null; null !== $ancestor; $ancestor = $parents[$ancestor] ?? null ) {
                $conditional = array_diff_key($conditional, $hidden[$ancestor] ?? array());
            }
            $base = $this->withoutAmbiguousCustomProperties($matched['base'], $entry['element'], $conditional, $customPropertyAnalysis['rules']);
            $layout = $this->layout($base, $entry['element'], null, $customPropertyAnalysis['rules']);
            // Copy-only context nodes are always identity-bearing, because form
            // context items join them by selector. Other nodes need own facts;
            // their structural ancestors are restored below.
            if ( array() === $layout && array() === $conditional && ! str_starts_with($entry['id'], 'context-') ) {
                continue;
            }
            $nodes[$entry['id']] = $this->node($entry, $layout, $this->provenance($base, null));
            if ( 'empty_box' === $entry['kind'] ) {
                $nodes[$entry['id']] += $this->emptyBoxFacts($entry['element'], $layout);
            }
            // Base facts declared `!important` override every conflicting rule
            // and later runtime style toggles, unlike a plain declaration.
            $important = array_values(array_filter(array_keys($base), static fn (string $property): bool => true === ($base[$property]['important'] ?? false) && isset($layout[self::layoutKey($property)])));
            if ( array() !== $important ) {
                sort($important, SORT_STRING);
                $nodes[$entry['id']]['important'] = $important;
            }
            if ( 'container' === $entry['kind'] && 'form' !== $entry['id'] ) {
                $presentation = $presentationBuilder->buildElement($entry['element'], $stylesheets, $inlineCss);
                $nodes[$entry['id']]['presentation'] = $presentation;
                if ( $presentation['truncated'] ) {
                    $this->truncated = true;
                    $this->diagnostics[] = 'element_presentation_limit';
                }
            }
            foreach ( $conditional as $encoded => $facts ) {
                if ( count($variants) >= self::MAX_VARIANTS ) {
                    $this->truncated = true;
                    $this->diagnostics[] = 'variant_limit';
                    break;
                }
                $condition = json_decode($encoded, true);
                $patch = $this->layout($facts, $entry['element'], $condition, $customPropertyAnalysis['rules']);
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
        foreach ( $entries as $entry ) {
            if ( ! isset($nodes[$entry['id']]) ) {
                continue;
            }
            $sizing = $this->sizing($nodes[$entry['id']], $nodes);
            if ( null !== $sizing ) {
                $nodes[$entry['id']]['sizing'] = $sizing;
            }
        }
        $this->hoistFieldListSpacing($entries, $nodes, $analysis['rules'], $customPropertyAnalysis['rules']);
        $this->hoistFieldGroupSpacing($entries, $nodes, $analysis['rules'], $customPropertyAnalysis['rules']);
        // Structural ancestors restored without layout facts of their own still
        // carry resolved presentation (inherited alignment and typography), so a
        // consumer reading any box on the path sees what that box renders. This
        // runs after the spacing hoists, so ancestors they restore through
        // ensureAncestorNodes() carry presentation too.
        foreach ( $entries as $entry ) {
            if ( ! isset($nodes[$entry['id']]) || isset($nodes[$entry['id']]['presentation']) || 'container' !== $entry['kind'] || 'form' === $entry['id'] ) {
                continue;
            }
            $presentation = $presentationBuilder->buildElement($entry['element'], $stylesheets, $inlineCss);
            $nodes[$entry['id']]['presentation'] = $presentation;
            if ( $presentation['truncated'] ) {
                $this->truncated = true;
                $this->diagnostics[] = 'element_presentation_limit';
            }
        }

        $nonRendered = array();
        foreach ( FormControlClassifier::honeypotCopyElements($form) as $element ) {
            $nonRendered[$element->getNodePath()] = true;
        }
        foreach ( $entries as $entry ) {
            if ( isset($nodes[$entry['id']]) && 'container' === $entry['kind'] ) {
                $nodes[$entry['id']]['in_flow_children'] = $this->inFlowChildren($entry['element'], $analysis['rules'], $customPropertyAnalysis['rules'], $nonRendered);
            }
        }

        $graph = array(
            'schema' => 'generic/computed-layout-graph/v3',
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

    /** Count direct element boxes and non-whitespace anonymous text items using the base cascade. */
    private function inFlowChildren(DOMElement $element, array $rules, array $customPropertyRules, array $nonRendered): int
    {
        $count = 0;
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                // Reuse the same non-authored trap ownership as form projection.
                if ( isset($nonRendered[$child->getNodePath()]) || (FormControlClassifier::isControlElement($child) && FormControlClassifier::isNonAuthoredControl($child)) ) continue;
                $matched = $this->matched($child, $rules);
                $layout = $this->layout($matched['base'], $child, null, $customPropertyRules);
                if ( 'none' === strtolower(trim($layout['display'] ?? '')) || in_array(strtolower(trim($layout['position'] ?? '')), array( 'absolute', 'fixed' ), true) ) {
                    continue;
                }
                ++$count;
            } elseif ( $child instanceof \DOMText && '' !== trim($child->textContent) ) {
                ++$count;
            }
        }
        return $count;
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
        $v2 = 'generic/computed-layout-graph/v2' === $schema;
        $depth = $v1 ? 8 : self::MAX_DEPTH;
        $properties = $v1 ? self::V1_PROPERTIES : ($v2 ? array_diff(self::PROPERTIES, array( 'min-height', 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'padding-block-start', 'padding-block-end', 'padding-inline-start', 'padding-inline-end' )) : self::PROPERTIES);
        $layoutKeys = $v1 ? self::V1_LAYOUT_KEYS : ($v2 ? array_diff(self::LAYOUT_KEYS, array( 'min_height', 'padding', 'padding_top', 'padding_right', 'padding_bottom', 'padding_left', 'padding_block_start', 'padding_block_end', 'padding_inline_start', 'padding_inline_end' )) : self::LAYOUT_KEYS);
        if (!$v1 && !$v2) {
            $properties = array_merge($properties, self::SOURCE_BOX_PROPERTIES);
            $layoutKeys = array_merge($layoutKeys, self::SOURCE_BOX_KEYS);
        }
        if ( (! $v1 && ! $v2 && 'generic/computed-layout-graph/v3' !== $schema) || 'source_css_cascade' !== ($graph['basis'] ?? null) || ! is_bool($graph['truncated'] ?? null) || ! is_array($graph['limits'] ?? null) || self::MAX_NODES !== ($graph['limits']['nodes'] ?? null) || $depth !== ($graph['limits']['depth'] ?? null) || self::MAX_RULES_PER_NODE !== ($graph['limits']['rules_per_node'] ?? null) || ! is_array($graph['nodes'] ?? null) || ! is_array($graph['variants'] ?? null) || ! is_array($graph['diagnostics'] ?? null) ) {
            throw new InvalidArgumentException('Form layout graph envelope is invalid.');
        }
        if ( count($graph['nodes']) > self::MAX_NODES ) {
            throw new InvalidArgumentException('Form layout graph exceeds its node limit.');
        }
        $v3 = ! $v1 && ! $v2;
        if ( array_diff(array_keys($graph), array( 'schema', 'basis', 'truncated', 'limits', 'nodes', 'variants', 'diagnostics' )) || array_diff(array_keys($graph['limits']), array( 'nodes', 'depth', 'rules_per_node' )) || ! array_is_list($graph['nodes']) || ! array_is_list($graph['variants']) ) {
            throw new InvalidArgumentException('Form layout graph envelope has unknown keys.');
        }
        $ids = array();
        foreach ( $graph['nodes'] as $node ) {
            if ( is_array($node) ) self::assertNodeIdentity($node, $v1 ? 8 : ($v2 ? 16 : 64), $v3);
            if ( is_array($node) && null !== ($node['parent'] ?? null) && ! isset($ids[$node['parent']]) ) {
                throw new InvalidArgumentException('Form layout graph parents must precede their children.');
            }
            if ( is_array($node) && null !== ($node['parent'] ?? null) && 'empty_box' === $ids[$node['parent']] ) {
                throw new InvalidArgumentException('Form layout graph empty boxes are leaves.');
            }
            if ( ! is_array($node) || ! is_string($node['id'] ?? null) || isset($ids[$node['id']]) || ! in_array($node['kind'] ?? null, $v3 ? array( 'container', 'control', 'empty_box' ) : array( 'container', 'control' ), true) || ! is_int($node['order'] ?? null) || ! is_array($node['source'] ?? null) || ! is_string($node['source']['tag'] ?? null) || ! is_array($node['source']['classes'] ?? null) || ! is_array($node['layout'] ?? null) || ! is_array($node['provenance'] ?? null) || (isset($node['sizing']) && ! is_array($node['sizing'])) ) {
                throw new InvalidArgumentException('Form layout graph node is invalid.');
            }
            $ids[$node['id']] = $node['kind'];
            if ('generic/computed-layout-graph/v3' === $schema) {
                if (!is_string($node['source']['selector'] ?? null) || '' === trim($node['source']['selector']) || strlen($node['source']['selector']) > 2048 || count($node['source']['classes']) > 64) throw new InvalidArgumentException('Form source element identity is invalid.');
                if (isset($node['presentation'])) {
                    if ('container' !== $node['kind'] || !is_array($node['presentation'])) throw new InvalidArgumentException('Form source element presentation owner is invalid.');
                    FormPresentationGraphBuilder::assertElement($node['presentation']);
                }
            }
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
            if ( isset($node['important']) && ( ! is_array($node['important']) || ! array_is_list($node['important']) || array() === $node['important'] || array_filter($node['important'], static fn (mixed $property): bool => ! is_string($property) || ! in_array($property, $properties, true) || ! isset($node['layout'][self::layoutKey($property)])) ) ) {
                throw new InvalidArgumentException('Form layout graph important facts are invalid.');
            }
            if ( isset($node['sizing']) && ( $v1 || ! self::validSizing($node, $node['sizing'], $graph['nodes']) ) ) {
                throw new InvalidArgumentException('Form layout graph sizing evidence is invalid.');
            }
        }
        if ( count($graph['variants']) > self::MAX_VARIANTS ) {
            throw new InvalidArgumentException('Form layout graph exceeds its variant limit.');
        }
        foreach ( $graph['variants'] as $variant ) {
            if ( ! is_array($variant) || array_diff(array_keys($variant), array( 'node', 'condition', 'layout_patch', 'precedence', 'provenance' )) || ! isset($ids[$variant['node'] ?? '']) || ! is_array($variant['condition'] ?? null) || ! self::validCondition($variant['condition']) || ! is_array($variant['layout_patch'] ?? null) || array() === $variant['layout_patch'] || ! is_array($variant['precedence'] ?? null) || ! is_array($variant['provenance'] ?? null) ) {
                throw new InvalidArgumentException('Form layout graph variant is invalid.');
            }
            foreach ( $variant['precedence'] as $property => $precedence ) {
                if ( ! is_string($property) || ! in_array($property, $properties, true) || ! isset($variant['layout_patch'][self::layoutKey($property)]) || ! is_array($precedence) || array_diff(array_keys($precedence), array( 'source_order', 'specificity', 'important' )) || ! is_int($precedence['source_order'] ?? null) || ! is_int($precedence['specificity'] ?? null) || ! is_bool($precedence['important'] ?? null) ) {
                    throw new InvalidArgumentException('Form layout graph variant precedence is invalid.');
                }
            }
            self::assertFacts($variant['layout_patch'], $variant['provenance'], $variant['condition'], $properties, $layoutKeys);
        }
    }

    /**
     * A node's identity and source element must be safe to project into markup
     * and selectors: closed keys, canonical ids, and tag, id, class and selector
     * tokens that cannot break out of an attribute or a rule.
     *
     * @param array<string,mixed> $node
     */
    private static function assertNodeIdentity(array $node, int $classLimit, bool $v3): void
    {
        $nodeKeys = array( 'id', 'kind', 'parent', 'order', 'source', 'layout', 'provenance', 'sizing' );
        if ( $v3 ) array_push($nodeKeys, 'presentation', 'important', 'in_flow_children', ...self::EMPTY_BOX_KEYS);
        // Empty boxes carry exactly their content classification and flow facts.
        $emptyBox = 'empty_box' === ($node['kind'] ?? null);
        $emptyKeys = array_intersect(array_keys($node), self::EMPTY_BOX_KEYS);
        if ( ($emptyBox ? count($emptyKeys) !== count(self::EMPTY_BOX_KEYS) : array() !== $emptyKeys) || ($emptyBox && ('no_visible_content' !== $node['content'] || ! is_bool($node['in_flow']) || ! is_bool($node['reserves_space']) || ($node['reserves_space'] && ! $node['in_flow']) || isset($node['sizing']))) ) {
            throw new InvalidArgumentException('Form layout graph empty box is invalid.');
        }
        if ( array_key_exists('in_flow_children', $node) && ( 'container' !== ($node['kind'] ?? null) || ! is_int($node['in_flow_children']) || $node['in_flow_children'] < 0 ) ) {
            throw new InvalidArgumentException('Form layout graph in-flow child count is invalid.');
        }
        $id = $node['id'] ?? null;
        if ( array_diff(array_keys($node), $nodeKeys) || ! is_string($id) || 1 !== preg_match($v3 ? '/^(?:form|wrapper-[0-9]+|control-[0-9]+|context-[0-9]+|empty-[0-9]+)$/D' : '/^(?:form|wrapper-[0-9]+|control-[0-9]+)$/D', $id) || (str_starts_with($id, 'context-') && 'container' !== ($node['kind'] ?? null)) || (str_starts_with($id, 'empty-') !== $emptyBox) || ! is_int($node['order'] ?? null) || $node['order'] < 0 ) {
            throw new InvalidArgumentException('Form layout graph node identity is invalid.');
        }
        $source = $node['source'] ?? null;
        if ( ! is_array($source) || array_diff(array_keys($source), $v3 ? array( 'tag', 'selector', 'id', 'classes' ) : array( 'tag', 'id', 'classes' )) || ! is_string($source['tag'] ?? null) || 1 !== preg_match('/^[a-z][a-z0-9-]{0,30}$/D', $source['tag']) || (isset($source['id']) && (! is_string($source['id']) || 1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,79}$/D', $source['id']))) || ! is_array($source['classes'] ?? null) || ! array_is_list($source['classes']) || count($source['classes']) > $classLimit || array_filter($source['classes'], static fn (mixed $class): bool => ! is_string($class) || 1 !== preg_match('/^[^\s"\'<>\x00-\x1f]{1,128}$/D', $class)) || ($v3 && (! is_string($source['selector'] ?? null) || 1 === preg_match('/[\x00-\x1f{};]/', $source['selector']))) ) {
            throw new InvalidArgumentException('Form source element identity is unsafe.');
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
            if ( ! is_array($fact) || array_diff(array_keys($fact), array( 'source_path', 'source_sha256', 'selector', 'condition', 'properties' )) || ! is_string($fact['source_path'] ?? null) || '' === ArtifactPath::safeRelativePath($fact['source_path']) || ArtifactPath::safeRelativePath($fact['source_path']) !== $fact['source_path'] || ! preg_match('/^[a-f0-9]{64}$/', $fact['source_sha256'] ?? '') || ! is_string($fact['selector'] ?? null) || '' === trim($fact['selector']) || strlen($fact['selector']) > 1024 || ! is_array($fact['properties'] ?? null) || array() === $fact['properties'] || count($fact['properties']) > count($properties) || array_filter($fact['properties'], static fn (mixed $property): bool => ! is_string($property) || ! in_array($property, $properties, true) || ! isset($layout[self::layoutKey($property)])) || ($condition !== null && $fact['condition'] !== $condition) || ($condition === null && ($fact['condition'] ?? null) !== null) ) {
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

    /** @param array<string, mixed> $node @param array<string, mixed> $sizing @param list<array<string, mixed>> $nodes */
    private static function validSizing(array $node, array $sizing, array $nodes): bool
    {
        if ( 'control' !== $node['kind'] || 'grid_track' !== ($sizing['kind'] ?? null) || 'inline' !== ($sizing['axis'] ?? null) || ! is_string($sizing['container'] ?? null) || ! is_string($sizing['grid_column'] ?? null) || '' === trim($sizing['grid_column']) || array_diff(array_keys($sizing), array( 'kind', 'axis', 'container', 'grid_column' )) ) {
            return false;
        }
        foreach ( $nodes as $container ) {
            if ( $sizing['container'] === ($container['id'] ?? null) ) {
                if ( $node['parent'] !== $sizing['container'] || 'container' !== ($container['kind'] ?? null) || isset($node['layout']['width']) || 'grid' !== ($container['layout']['display'] ?? null) || ! is_string($container['layout']['columns'] ?? null) || ! self::stretchesInline($node['layout']['justify_self'] ?? null) || ! self::stretchesInline($container['layout']['justify_items'] ?? null) ) {
                    return false;
                }
                if ( is_string($node['layout']['column'] ?? null) ) {
                    return $sizing['grid_column'] === $node['layout']['column'];
                }
                return 'auto' === $sizing['grid_column'] && 1 === count(CssValueSplitter::splitTopLevelWhitespace($container['layout']['columns']));
            }
        }
        return false;
    }

    /** @param array<string, int> $controls @param array<string, bool> $relevant @param list<array<string, mixed>> $entries @param array<string, int> $emptyBoxes */
    private function collect(DOMElement $element, ?string $parent, int $order, int $depth, array $controls, array $relevant, array &$entries, int &$wrapper, array $emptyBoxes = array()): void
    {
        $path = $element->getNodePath();
        $root = null === $parent;
        if ( ! $root && ! isset($controls[$path]) && ! isset($relevant[$path]) && ! isset($emptyBoxes[$path]) ) {
            return;
        }
        if ( $depth > self::MAX_DEPTH || count($entries) >= self::MAX_NODES ) {
            $this->truncated = true;
            $this->diagnostics[] = 'node_or_depth_limit';
            return;
        }
        if ( ! $root && isset($emptyBoxes[$path]) ) {
            // An empty box is a leaf: nothing inside it is form structure.
            $entries[] = array( 'id' => 'empty-' . $emptyBoxes[$path], 'kind' => 'empty_box', 'parent' => $parent, 'order' => $order, 'element' => $element );
            return;
        }
        $id = $root ? 'form' : (isset($controls[$path]) ? 'control-' . $controls[$path] : ($this->nodeIds[$path] ?? (array() === $this->nodeIds ? 'wrapper-' : 'context-') . $wrapper++));
        $entries[] = array( 'id' => $id, 'kind' => isset($controls[$path]) ? 'control' : 'container', 'parent' => $parent, 'order' => $order, 'element' => $element );
        $childOrder = 0;
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            $before = count($entries);
            $this->collect($child, $id, $childOrder, $depth + 1, $controls, $relevant, $entries, $wrapper, $emptyBoxes);
            if ( count($entries) > $before ) {
                ++$childOrder;
            }
        }
    }

    /**
     * Direct children of collected containers that render no visible content,
     * hold no control, and declare a height or min-height (base or
     * conditional), keyed by node path to their `empty-N` coordinate.
     *
     * @param list<array<string, mixed>> $entries
     * @param array<string, int> $controls
     * @param array<string, bool> $relevant
     * @param list<array<string, mixed>> $rules
     * @return array<string, int>
     */
    private function emptyBoxes(DOMElement $form, array $entries, array $controls, array $relevant, array $rules, int $budget): array
    {
        $containers = array();
        foreach ( $entries as $entry ) {
            if ( 'container' === $entry['kind'] ) $containers[$entry['element']->getNodePath()] = true;
        }
        $boxes = array();
        // Document order gives each box a stable coordinate.
        foreach ( $form->getElementsByTagName('*') as $child ) {
            if ( ! $child instanceof DOMElement || ! $child->parentNode instanceof DOMElement || ! isset($containers[$child->parentNode->getNodePath()]) || isset($controls[$child->getNodePath()]) || isset($relevant[$child->getNodePath()]) || ! self::hasNoVisibleContent($child) ) {
                continue;
            }
            $matched = $this->matched($child, $rules);
            $sized = isset($matched['base']['height']) || isset($matched['base']['min-height']);
            foreach ( $matched['conditional'] as $facts ) {
                $sized = $sized || isset($facts['height']) || isset($facts['min-height']);
            }
            if ( ! $sized ) continue;
            $depth = -1;
            for ( $node = $child; $node instanceof DOMElement && $node !== $form; $node = $node->parentNode ) ++$depth;
            if ( $depth > self::MAX_DEPTH ) continue;
            if ( count($boxes) >= $budget ) {
                $this->truncated = true;
                $this->diagnostics[] = 'empty_box_limit';
                break;
            }
            $boxes[$child->getNodePath()] = count($boxes);
        }
        return $boxes;
    }

    /** No text (beyond whitespace and no-break spaces) and no content-painting descendants. */
    private static function hasNoVisibleContent(DOMElement $element): bool
    {
        if ( in_array(strtolower($element->tagName), self::VISIBLE_CONTENT_TAGS, true) || '' !== preg_replace('/[\s\x{00A0}\x{200B}\x{FEFF}]+/u', '', $element->textContent) ) {
            return false;
        }
        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( in_array(strtolower($descendant->tagName), self::VISIBLE_CONTENT_TAGS, true) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Base-cascade facts a consumer needs to decide whether an empty box takes
     * space. Conditional changes stay on the node's variants.
     *
     * @param array<string, string> $layout
     * @return array{content: string, in_flow: bool, reserves_space: bool}
     */
    private function emptyBoxFacts(DOMElement $element, array $layout): array
    {
        $display = strtolower(trim($layout['display'] ?? ''));
        if ( '' === $display ) {
            $display = $element->hasAttribute('hidden') ? 'none' : (in_array(strtolower($element->tagName), self::INLINE_TAGS, true) ? 'inline' : 'block');
        }
        $inFlow = ! in_array($display, array( 'none', 'contents' ), true) && ! in_array(strtolower(trim($layout['position'] ?? '')), array( 'absolute', 'fixed' ), true);
        $sized = false;
        foreach ( array( 'height', 'min_height' ) as $key ) {
            $value = strtolower(trim($layout[$key] ?? ''));
            if ( 1 === preg_match('/^(\d+(?:\.\d*)?|\.\d+)(px|r?em|pt|pc|cm|mm|in|q|ex|ch|r?lh|[sld]?v(?:h|w|min|max)|vb|vi)$/D', $value, $match) && (float) $match[1] > 0 ) {
                $sized = true;
            }
        }
        return array( 'content' => 'no_visible_content', 'in_flow' => $inFlow, 'reserves_space' => $inFlow && $sized && 'inline' !== $display );
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
        // One shared enumeration keeps `control-N` graph ids aligned with the
        // reported controls, and keeps non-authored controls (and the wrappers
        // that exist only to hide them) out of the projected layout. Each id
        // describes the box that paints its control.
        return FormControlClassifier::presentedControlElements($form);
    }

    /**
     * A provider form flattens shared field-list wrappers, so authored stack
     * spacing has to live on the form node the provider actually renders.
     *
     * @param list<array<string, mixed>> $entries
     * @param array<string, array<string, mixed>> $nodes
     * @param list<array<string, mixed>> $rules
     * @param list<array<string, mixed>> $customPropertyRules
     */
    private function hoistFieldListSpacing(array $entries, array &$nodes, array $rules, array $customPropertyRules): void
    {
        $formEntry = null;
        foreach ( $entries as $entry ) {
            if ( 'form' === $entry['id'] ) {
                $formEntry = $entry;
                break;
            }
        }
        if ( null === $formEntry ) {
            return;
        }
        $formLayout = is_array($nodes['form']['layout'] ?? null) ? $nodes['form']['layout'] : array();
        if ( isset($formLayout['gap']) || isset($formLayout['row_gap']) ) {
            return;
        }

        $best = null;
        $bestDepth = -1;
        foreach ( $entries as $entry ) {
            if ( 'control' === $entry['kind'] || $this->dataEntryDescendants($entries, $entry['id']) < 2 ) {
                continue;
            }
            $spacing = $this->fieldListGap($entry['element'], $rules, $customPropertyRules);
            if ( null === $spacing ) {
                continue;
            }
            $depth = 0;
            for ( $parent = $entry['parent']; null !== $parent && $depth < self::MAX_DEPTH; ++$depth ) {
                $next = null;
                foreach ( $entries as $candidate ) {
                    if ( $candidate['id'] === $parent ) {
                        $next = $candidate['parent'];
                        break;
                    }
                }
                $parent = $next;
            }
            if ( $depth < $bestDepth ) {
                continue;
            }
            $best = $spacing;
            $bestDepth = $depth;
        }
        if ( null === $best ) {
            return;
        }

        if ( ! isset($nodes['form']) ) {
            $nodes['form'] = $this->node($formEntry, $best['layout'], $best['provenance']);
            return;
        }
        $nodes['form']['layout'] = array_merge($formLayout, $best['layout']);
        ksort($nodes['form']['layout']);
        $nodes['form']['provenance'] = array_merge(is_array($nodes['form']['provenance'] ?? null) ? $nodes['form']['provenance'] : array(), $best['provenance']);
        if ( count($nodes['form']['provenance']) > self::MAX_PROVENANCE ) {
            $nodes['form']['provenance'] = array_slice($nodes['form']['provenance'], 0, self::MAX_PROVENANCE);
            $this->truncated = true;
            $this->diagnostics[] = 'provenance_limit';
        }
    }

    /**
     * A provider field flattens the exclusive label/control wrapper, so the
     * authored intra-field gap has to live on the control the provider renders.
     * Margin-block-start on that control is the spacing a Jetpack field shell
     * keeps between its label and the native value.
     *
     * @param list<array<string, mixed>> $entries
     * @param array<string, array<string, mixed>> $nodes
     * @param list<array<string, mixed>> $rules
     * @param list<array<string, mixed>> $customPropertyRules
     */
    private function hoistFieldGroupSpacing(array $entries, array &$nodes, array $rules, array $customPropertyRules): void
    {
        foreach ( $entries as $entry ) {
            if ( 'control' === $entry['kind'] || 'label' === strtolower($entry['element']->tagName) || 1 !== $this->dataEntryDescendants($entries, $entry['id']) ) {
                continue;
            }
            $spacing = $this->fieldListGap($entry['element'], $rules, $customPropertyRules);
            if ( null === $spacing ) {
                continue;
            }
            $gap = $spacing['layout']['row_gap'] ?? $spacing['layout']['gap'] ?? null;
            if ( ! is_string($gap) || '' === trim($gap) ) {
                continue;
            }
            $controlId = $this->dataEntryControlId($entries, $entry['id']);
            if ( null === $controlId ) {
                continue;
            }
            $controlEntry = null;
            foreach ( $entries as $candidate ) {
                if ( $candidate['id'] === $controlId ) {
                    $controlEntry = $candidate;
                    break;
                }
            }
            if ( null === $controlEntry || $controlEntry['parent'] !== $entry['id'] ) {
                continue;
            }
            $controlLayout = is_array($nodes[$controlId]['layout'] ?? null) ? $nodes[$controlId]['layout'] : array();
            if ( isset($controlLayout['margin_block_start']) ) {
                continue;
            }
            $provenance = array();
            foreach ( $spacing['provenance'] as $fact ) {
                $fact['properties'] = array( 'margin-block-start' );
                $provenance[] = $fact;
            }
            $layout = array( 'margin_block_start' => $gap );
            $this->ensureAncestorNodes($entries, $controlEntry, $nodes);
            if ( ! isset($nodes[$controlId]) ) {
                $nodes[$controlId] = $this->node($controlEntry, $layout, $provenance);
                continue;
            }
            $nodes[$controlId]['layout'] = array_merge($controlLayout, $layout);
            ksort($nodes[$controlId]['layout']);
            $nodes[$controlId]['provenance'] = array_merge(is_array($nodes[$controlId]['provenance'] ?? null) ? $nodes[$controlId]['provenance'] : array(), $provenance);
            if ( count($nodes[$controlId]['provenance']) > self::MAX_PROVENANCE ) {
                $nodes[$controlId]['provenance'] = array_slice($nodes[$controlId]['provenance'], 0, self::MAX_PROVENANCE);
                $this->truncated = true;
                $this->diagnostics[] = 'provenance_limit';
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param array<string, mixed> $entry
     * @param array<string, array<string, mixed>> $nodes
     */
    private function ensureAncestorNodes(array $entries, array $entry, array &$nodes): void
    {
        for ( $parent = $entry['parent']; null !== $parent; ) {
            if ( isset($nodes[$parent]) ) {
                return;
            }
            $parentEntry = null;
            foreach ( $entries as $candidate ) {
                if ( $candidate['id'] === $parent ) {
                    $parentEntry = $candidate;
                    break;
                }
            }
            if ( null === $parentEntry ) {
                return;
            }
            $nodes[$parent] = $this->node($parentEntry, array(), array());
            $parent = $parentEntry['parent'];
        }
    }

    /** @param list<array<string, mixed>> $entries */
    private function dataEntryControlId(array $entries, string $id): ?string
    {
        foreach ( $entries as $entry ) {
            if ( 'control' !== $entry['kind'] || ! FormControlClassifier::isDataEntryControl($entry['element']) ) {
                continue;
            }
            if ( $entry['id'] === $id || $this->hasAncestor($entries, $entry['id'], $id) ) {
                return $entry['id'];
            }
        }
        return null;
    }

    /** @param list<array<string, mixed>> $entries */
    private function dataEntryDescendants(array $entries, string $id): int
    {
        $count = 0;
        foreach ( $entries as $entry ) {
            if ( 'control' !== $entry['kind'] || ! FormControlClassifier::isDataEntryControl($entry['element']) ) {
                continue;
            }
            if ( $entry['id'] === $id || $this->hasAncestor($entries, $entry['id'], $id) ) {
                ++$count;
            }
        }
        return $count;
    }

    /**
     * @param list<array<string, mixed>> $rules
     * @param list<array<string, mixed>> $customPropertyRules
     * @return array{layout: array<string, string>, provenance: list<array<string, mixed>>}|null
     */
    private function fieldListGap(DOMElement $element, array $rules, array $customPropertyRules): ?array
    {
        $matched = $this->matched($element, $rules, true);
        $base = array_intersect_key($matched['base'], array_flip(self::SPACING_PROPERTIES));
        // Hoisted spacing has no conditional-variant counterpart of its own,
        // so any condition that would change a custom-property-derived
        // value here is unrepresented by construction.
        $base = $this->withoutAmbiguousCustomProperties($base, $element, array(), $customPropertyRules);
        $layout = array_intersect_key($this->layout($base, $element, null, $customPropertyRules), array_flip(array( 'gap', 'row_gap' )));
        if ( array() === $layout ) {
            return null;
        }
        foreach ( $layout as $key => $value ) {
            $layout[$key] = self::providerSafeLength($value);
        }
        $properties = array();
        foreach ( array_keys($layout) as $key ) {
            $properties[] = 'row_gap' === $key ? 'row-gap' : $key;
        }
        $base = array_intersect_key($base, array_flip($properties));

        return array( 'layout' => $layout, 'provenance' => $this->provenance($base, null) );
    }

    /**
     * An unlayered declaration whose value resolves through a custom
     * property that some media/container/supports condition would change
     * is only ambiguous for that one property on this one node, not for
     * the rest of the graph. Emitting a value that is silently wrong under
     * some other condition would be worse than omitting it, but a budget
     * or scan limit elsewhere could be hiding anything, so this narrow,
     * exhaustively-identified ambiguity must not carry that same
     * document-wide `truncated` consequence: it drops just the affected
     * property here instead.
     *
     * @param array<string, array<string, mixed>> $base
     * @param array<string, array<string, array<string, mixed>>> $conditional Keyed by encoded condition, then property; a property already represented here for a changing condition is not ambiguous.
     * @param list<array<string, mixed>> $customPropertyRules
     * @return array<string, array<string, mixed>>
     */
    private function withoutAmbiguousCustomProperties(array $base, DOMElement $element, array $conditional, array $customPropertyRules): array
    {
        foreach ( $base as $property => $fact ) {
            foreach ( FormCustomPropertyResolver::conditionsChanging($fact['value'], $element, $customPropertyRules) as $condition ) {
                if ( ! isset($conditional[ json_encode($condition) ][ $property ]) ) {
                    unset($base[$property]);
                    $this->diagnostics[] = 'conditional_custom_property_layout';
                    break;
                }
            }
        }
        return $base;
    }

    /**
     * Leading-dot numbers are valid CSS (`.25rem`) but provider overlays that
     * admit calc() lengths require a digit before the decimal.
     */
    private static function providerSafeLength(string $value): string
    {
        $normalized = preg_replace('/(?<![0-9])\.(\d+)/', '0.$1', $value);
        return is_string($normalized) ? $normalized : $value;
    }

    /** @param list<array<string, mixed>> $rules @return array{base: array<string, array<string, mixed>>, conditional: array<string, array<string, array<string, mixed>>>} */
    private function matched(DOMElement $element, array $rules, bool $spacingFromLayers = false): array
    {
        $base = array();
        $gaps = array();
        if ( $element->hasAttribute('style') ) {
            $inline = $element->getAttribute('style');
            foreach ( $this->boxDeclarations(CssRuleAnalyzer::declarations($inline, array_merge(self::PROPERTIES, self::SOURCE_BOX_PROPERTIES, self::SHORTHAND_PROPERTIES))) as $declaration ) {
                $important = 1 === preg_match('/\s*!important\s*$/i', $declaration['value']);
                $value = preg_replace('/\s*!important\s*$/i', '', $declaration['value']) ?? $declaration['value'];
                $fact = array( 'value' => $value, 'path' => 'inline-style', 'hash' => hash('sha256', $inline), 'selector' => '[style]', 'order' => PHP_INT_MAX, 'specificity' => PHP_INT_MAX, 'important' => $important );
                CssCascade::apply($base, $declaration['name'], $fact);
                self::applyGapLonghands($gaps[''], $declaration['name'], $fact);
            }
        }
        $conditional = array();
        $matched = 0;
        foreach ( $rules as $rule ) {
            // Cascade layers reorder precedence; they are not unlayered
            // structural facts. Emitting them here as if they were unlayered
            // made optional layout graphs look complete, so providers declined
            // forms they can still materialize. Layered presentation stays on
            // the presentation graph, which overlays as CSS. Field-list gap is
            // the exception: a provider form flattens that wrapper, so the
            // authored stack spacing has to ride on the form node itself.
            // A layered rule that is itself media/container/supports-gated is
            // not an unlayered base fact — it is a stated condition, so it
            // still belongs on variants.
            $layered = null !== ( $rule['layer'] ?? null );
            $conditionalRule = null !== ( $rule['condition'] ?? null );
            if ( $layered && ! $spacingFromLayers && ! $conditionalRule ) {
                continue;
            }
            $ruleDeclarations = $rule['declarations'];
            if ( $layered && ! $conditionalRule ) {
                $ruleDeclarations = array_values(array_filter(
                    $ruleDeclarations,
                    static fn (array $declaration): bool => in_array($declaration['name'], self::SPACING_PROPERTIES, true)
                ));
                if ( array() === $ruleDeclarations ) {
                    continue;
                }
            }
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
            foreach ( $this->boxDeclarations($ruleDeclarations) as $declaration ) {
                $important = 1 === preg_match('/\s*!important\s*$/i', $declaration['value']);
                $value = preg_replace('/\s*!important\s*$/i', '', $declaration['value']) ?? $declaration['value'];
                $fact = array( 'value' => $value, 'path' => $rule['path'], 'hash' => $rule['hash'], 'selector' => $rule['selector'], 'order' => $rule['order'], 'specificity' => $rule['specificity'], 'important' => $important, 'layer' => $rule['layer'] ?? null );
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
                self::applyGapLonghands($gaps[$key ?? ''], $declaration['name'], $fact);
            }
        }
        $base = self::resolvedGap($base, $gaps[''] ?? array());
        foreach ( $conditional as $key => $facts ) {
            $conditional[$key] = self::resolvedGap($facts, $gaps[$key] ?? array());
        }
        return array( 'base' => $base, 'conditional' => $conditional );
    }

    /**
     * Cascade `gap` as the two longhands it sets, beside the facts themselves.
     *
     * The `gap` shorthand and `row-gap`/`column-gap` are separate facts, but they
     * set the same two values, so which one holds depends on the cascade between
     * them. Declaration order inside a rule decides it too. This mirror records
     * the winner per longhand, applied in declaration order.
     *
     * @param array<string, array<string, mixed>>|null $gaps
     * @param array<string, mixed>                     $fact
     */
    private static function applyGapLonghands(?array &$gaps, string $property, array $fact): void
    {
        $gaps ??= array();
        if ( 'row-gap' === $property || 'column-gap' === $property ) {
            CssCascade::apply($gaps, $property, $fact);
            return;
        }
        if ( 'gap' !== $property ) {
            return;
        }
        $parts = CssValueSplitter::splitTopLevelWhitespace($fact['value']);
        if ( 1 > count($parts) || 2 < count($parts) || ( 1 === count($parts) && str_contains($parts[0], 'var(') ) ) {
            // A custom property can stand for both values, so its longhands are unknown.
            $gaps['unresolved'] = $fact;
            return;
        }
        CssCascade::apply($gaps, 'row-gap', array( 'value' => $parts[0] ) + $fact);
        CssCascade::apply($gaps, 'column-gap', array( 'value' => $parts[1] ?? $parts[0] ) + $fact);
    }

    /**
     * Replace a shorthand that meets a longhand with the longhands that hold.
     *
     * Read apart, `gap: 40px` and `column-gap: 10px` do not say which column gap
     * the box has: it is 10px when the longhand comes later and 40px when the
     * shorthand does. Where both reach a node, the node states the resolved row
     * and column gaps instead. A shorthand alone, or one its longhands agree with,
     * stays as authored.
     *
     * @param array<string, array<string, mixed>> $facts
     * @param array<string, array<string, mixed>> $gaps
     * @return array<string, array<string, mixed>>
     */
    private static function resolvedGap(array $facts, array $gaps): array
    {
        if ( ! isset($facts['gap']) || ( ! isset($facts['row-gap']) && ! isset($facts['column-gap']) ) || isset($gaps['unresolved']) ) {
            return $facts;
        }
        $parts = CssValueSplitter::splitTopLevelWhitespace($facts['gap']['value']);
        if ( ( $facts['row-gap']['value'] ?? $parts[0] ) === $parts[0] && ( $facts['column-gap']['value'] ?? $parts[1] ?? $parts[0] ) === ( $parts[1] ?? $parts[0] ) ) {
            // A longhand that agrees with the shorthand leaves one reading.
            return $facts;
        }
        unset($facts['gap']);
        foreach ( array( 'row-gap', 'column-gap' ) as $property ) {
            if ( isset($gaps[$property]) ) {
                $facts[$property] = $gaps[$property];
            }
        }
        return $facts;
    }

    /** @return array<string, string> */
    private function layout(array $facts, DOMElement $element, ?array $condition, array $customPropertyRules): array
    {
        $result = array();
        foreach ( $facts as $property => $fact ) {
            $key = in_array($property, self::SOURCE_BOX_PROPERTIES, true) ? str_replace('-', '_', $property) : self::layoutKey($property);
            $result[$key] = FormCustomPropertyResolver::resolve($fact['value'], $element, $condition, $customPropertyRules);
        }
        ksort($result);
        return $result;
    }

    /** Expand source shorthands before the shared per-property cascade. */
    private function boxDeclarations(array $declarations): array
    {
        $expanded = array();
        foreach ($declarations as $declaration) {
            $name = $declaration['name'];
            if (!in_array($name, array('margin', 'place-self', 'flex-flow'), true)) {
                $expanded[] = $declaration;
                continue;
            }
            $important = preg_match('/\s*!important\s*$/i', $declaration['value']) ? ' !important' : '';
            $value = preg_replace('/\s*!important\s*$/i', '', $declaration['value']) ?? $declaration['value'];
            $parts = CssValueSplitter::splitTopLevelWhitespace($value);
            if ('margin' === $name && count($parts) >= 1 && count($parts) <= 4) {
                foreach (array_combine(array('top', 'right', 'bottom', 'left'), CssValueInspector::expandBoxShorthand($value)) as $side => $part) $expanded[] = array('name' => 'margin-' . $side, 'value' => $part . $important);
            } elseif ('flex-flow' === $name && null !== ($flow = self::flexFlow($parts))) {
                // `flex-flow` resets both longhands; an omitted one is its initial value.
                $expanded[] = array('name' => 'flex-direction', 'value' => $flow[0] . $important);
                $expanded[] = array('name' => 'flex-wrap', 'value' => $flow[1] . $important);
            } elseif ('place-self' === $name && count($parts) >= 1 && count($parts) <= 2) {
                $expanded[] = array('name' => 'align-self', 'value' => $parts[0] . $important);
                $expanded[] = array('name' => 'justify-self', 'value' => ($parts[1] ?? $parts[0]) . $important);
            } elseif ('flex-flow' === $name) {
                // An unresolved flow (a custom property, say) names its gap
                // without discarding every other proven fact in the graph.
                $this->diagnostics[] = 'unsupported_flex_flow';
            } else {
                $this->truncated = true;
                $this->diagnostics[] = 'unsupported_box_shorthand:' . $name;
            }
        }
        return $expanded;
    }

    /**
     * @param list<string> $parts
     * @return array{0: string, 1: string}|null Direction and wrap, or null when a token is not a keyword.
     */
    private static function flexFlow(array $parts): ?array
    {
        $direction = null;
        $wrap = null;
        foreach ( $parts as $part ) {
            $token = strtolower(trim($part));
            if ( null === $direction && in_array($token, array( 'row', 'row-reverse', 'column', 'column-reverse' ), true) ) {
                $direction = $token;
            } elseif ( null === $wrap && in_array($token, array( 'nowrap', 'wrap', 'wrap-reverse' ), true) ) {
                $wrap = $token;
            } else {
                return null;
            }
        }
        return array() === $parts || count($parts) > 2 ? null : array( $direction ?? 'row', $wrap ?? 'nowrap' );
    }

        /** @param array<string, mixed> $node @param array<string, array<string, mixed>> $nodes @return array<string, string>|null */
    private function sizing(array $node, array $nodes): ?array
    {
        if ( 'control' !== $node['kind'] || isset($node['layout']['width']) || ! is_string($node['parent'] ?? null) || ! isset($nodes[$node['parent']]) ) {
            return null;
        }
        $container = $nodes[$node['parent']];
        if ( 'grid' !== ($container['layout']['display'] ?? null) || ! is_string($container['layout']['columns'] ?? null) || ! $this->stretchesInline($node['layout']['justify_self'] ?? null) || ! $this->stretchesInline($container['layout']['justify_items'] ?? null) ) {
            return null;
        }
        $column = $node['layout']['column'] ?? null;
        if ( ! is_string($column) ) {
            $tracks = CssValueSplitter::splitTopLevelWhitespace($container['layout']['columns']);
            if ( 1 !== count($tracks) ) {
                return null;
            }
            $column = 'auto';
        }
        return array( 'kind' => 'grid_track', 'axis' => 'inline', 'container' => $container['id'], 'grid_column' => $column );
    }

    private static function stretchesInline(?string $value): bool
    {
        return null === $value || in_array(strtolower(trim($value)), array( 'normal', 'stretch' ), true);
    }

    private static function layoutKey(string $property): string
    {
        if (in_array($property, self::SOURCE_BOX_PROPERTIES, true)) return str_replace('-', '_', $property);
        return array( 'grid-template-columns' => 'columns', 'grid-template-rows' => 'rows', 'row-gap' => 'row_gap', 'column-gap' => 'column_gap', 'grid-column' => 'column', 'grid-row' => 'row', 'grid-area' => 'area', 'flex-direction' => 'direction', 'flex-wrap' => 'wrap', 'align-items' => 'align_items', 'align-content' => 'align_content', 'justify-content' => 'justify_content', 'align-self' => 'align_self', 'justify-items' => 'justify_items', 'justify-self' => 'justify_self', 'flex-grow' => 'flex_grow', 'flex-shrink' => 'flex_shrink', 'flex-basis' => 'flex_basis', 'margin-block-start' => 'margin_block_start', 'margin-block-end' => 'margin_block_end', 'margin-inline-start' => 'margin_inline_start', 'margin-inline-end' => 'margin_inline_end', 'min-height' => 'min_height', 'padding-top' => 'padding_top', 'padding-right' => 'padding_right', 'padding-bottom' => 'padding_bottom', 'padding-left' => 'padding_left', 'padding-block-start' => 'padding_block_start', 'padding-block-end' => 'padding_block_end', 'padding-inline-start' => 'padding_inline_start', 'padding-inline-end' => 'padding_inline_end' )[$property] ?? $property;
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
        $classes = SourceDom::boundedClassTokens($element->getAttribute('class'), 65);
        if ( count($classes) > 64 ) {
            $this->truncated = true;
            $this->diagnostics[] = 'source_class_limit';
        }
        return array( 'tag' => strtolower($element->tagName), 'selector' => SourceDom::elementSelector($element), 'id' => 1 === preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,79}$/D', $element->getAttribute('id')) ? $element->getAttribute('id') : null, 'classes' => array_slice($classes, 0, 64) );
    }
}

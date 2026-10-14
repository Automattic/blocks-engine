<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssAnalysisLimits;
use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;
use InvalidArgumentException;

/**
 * Declares a multi-step form track from source CSS.
 *
 * Site builders ship a multi-step form as one form whose pages sit side by
 * side in a clipped row; a script slides the next page into view. The track is
 * a non-wrapping flex row whose non-shrinking children each fill the inline
 * size of a clipping ancestor, so exactly one child is visible at a time. Each
 * child holding data-entry controls is a step; one trailing copy-only child is
 * the completion panel shown after submit. Facts come from the declared
 * cascade and the form layout graph; geometry and label text are never used.
 */
final class FormStepTrackDetector
{
    public const SCHEMA = 'generic/form-steps/v1';
    private const MAX_STEPS = 16;
    private const PROPERTIES = array( 'overflow', 'overflow-x', 'overflow-inline', 'flex-flow', 'flex-direction', 'flex-wrap', 'box-sizing', 'border', 'border-width', 'border-left', 'border-right', 'border-left-width', 'border-right-width', 'border-inline', 'border-inline-width', 'border-inline-start', 'border-inline-end' );
    private const FLEX_DIRECTIONS = array( 'row', 'row-reverse', 'column', 'column-reverse' );
    private const FLEX_WRAPS = array( 'nowrap', 'wrap', 'wrap-reverse' );
    // A variant touching any of these on the track, a panel or the clipping
    // chain could unpage the track under that condition.
    private const PAGING_LAYOUT_KEYS = array( 'display', 'direction', 'wrap', 'width', 'flex', 'flex_shrink', 'flex_basis', 'padding', 'padding_left', 'padding_right', 'padding_inline_start', 'padding_inline_end' );

    /**
     * @param array<string, mixed> $graph A validated, untruncated form layout graph for `$form`.
     * @param list<array<string, mixed>> $stylesheets
     * @return array<string, mixed>|null
     */
    public function detect(DOMElement $form, array $graph, array $stylesheets, string $inlineCss = ''): ?array
    {
        if ( true !== ($graph['truncated'] ?? null) && is_array($graph['nodes'] ?? null) ) {
            $nodes = array_column($graph['nodes'], null, 'id');
        } else {
            return null;
        }
        $children = array();
        foreach ( $graph['nodes'] as $node ) {
            if ( is_string($node['parent'] ?? null) ) {
                $children[$node['parent']][] = $node['id'];
            }
        }
        $elements = $this->elements($form, $nodes);
        if ( null === $elements ) {
            return null;
        }
        $dataEntry = $this->dataEntryOwners($form, $elements);
        if ( $dataEntry['controls'] < 2 ) {
            return null;
        }
        $variantNodes = array();
        foreach ( $graph['variants'] ?? array() as $variant ) {
            if ( array() !== array_intersect(array_keys($variant['layout_patch'] ?? array()), self::PAGING_LAYOUT_KEYS) ) {
                $variantNodes[$variant['node']] = true;
            }
        }

        $cascade = null;
        foreach ( $nodes as $id => $node ) {
            if ( 'container' !== $node['kind'] || 'form' === $id || ! in_array(strtolower(trim((string) ($node['layout']['display'] ?? ''))), array( 'flex', 'inline-flex' ), true) || count($children[$id] ?? array()) < 2 ) {
                continue;
            }
            $cascade ??= $this->cascade($elements, $stylesheets, $inlineCss);
            if ( null === $cascade ) {
                return null;
            }
            $steps = $this->track($id, $nodes, $children, $elements, $cascade, $dataEntry, $variantNodes);
            if ( null !== $steps ) {
                return $steps;
            }
        }

        return null;
    }

    /**
     * Validate a declared step track against the layout graph it references.
     *
     * @param array<string, mixed> $steps
     * @param array<string, mixed> $graph
     */
    public static function assertValid(array $steps, array $graph): void
    {
        $ids = array();
        $parents = array();
        foreach ( is_array($graph['nodes'] ?? null) ? $graph['nodes'] : array() as $node ) {
            if ( is_array($node) && is_string($node['id'] ?? null) ) {
                $ids[$node['id']] = $node['kind'] ?? null;
                $parents[$node['id']] = $node['parent'] ?? null;
            }
        }
        $track = $steps['track'] ?? null;
        if ( self::SCHEMA !== ($steps['schema'] ?? null) || array_diff(array_keys($steps), array( 'schema', 'track', 'steps', 'completion', 'navigation' )) || ! is_string($track) || 'container' !== ($ids[$track] ?? null) || 'form' === $track || ! is_array($steps['steps'] ?? null) || ! array_is_list($steps['steps']) || count($steps['steps']) < 2 || count($steps['steps']) > self::MAX_STEPS ) {
            throw new InvalidArgumentException('Form step track envelope is invalid.');
        }
        $seen = array();
        foreach ( $steps['steps'] as $step ) {
            $wrapper = is_array($step) ? ($step['wrapper'] ?? null) : null;
            if ( ! is_array($step) || array( 'wrapper' ) !== array_keys($step) || ! is_string($wrapper) || 'container' !== ($ids[$wrapper] ?? null) || $track !== $parents[$wrapper] || isset($seen[$wrapper]) ) {
                throw new InvalidArgumentException('Form step track steps must be distinct container children of the track.');
            }
            $seen[$wrapper] = true;
        }
        $completion = $steps['completion'] ?? null;
        if ( array_key_exists('completion', $steps) && (! is_string($completion) || 'container' !== ($ids[$completion] ?? null) || $track !== $parents[$completion] || isset($seen[$completion])) ) {
            throw new InvalidArgumentException('Form step track completion must be a container child of the track.');
        }
        $navigation = $steps['navigation'] ?? null;
        if ( array_key_exists('navigation', $steps) && (! is_string($navigation) || 'container' !== ($ids[$navigation] ?? null) || 'form' !== $parents[$navigation] || $navigation === $track) ) {
            throw new InvalidArgumentException('Form step track navigation must be a form-level container beside the track.');
        }
    }

    /**
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, list<string>> $children
     * @param array<string, DOMElement> $elements
     * @param array<string, array{base: array<string, string>, ambiguous: array<string, bool>}> $cascade
     * @param array{controls: int, owners: array<string, int>} $dataEntry
     * @param array<string, bool> $variantNodes
     * @return array<string, mixed>|null
     */
    private function track(string $id, array $nodes, array $children, array $elements, array $cascade, array $dataEntry, array $variantNodes): ?array
    {
        $flow = $this->flow($cascade[$id]);
        if ( null === $flow || 'row' !== $flow['direction'] || 'nowrap' !== $flow['wrap'] || isset($variantNodes[$id]) ) {
            return null;
        }
        // The nearest clipping box at or above the track; every box between it
        // and the track spans that box's full inline size.
        $clip = null;
        $chain = array();
        for ( $current = $id; is_string($current); $current = $nodes[$current]['parent'] ?? null ) {
            $overflow = $this->clips($cascade[$current]);
            if ( null === $overflow ) {
                return null;
            }
            if ( $overflow ) {
                $clip = $current;
                break;
            }
            if ( 'form' === $current ) {
                break;
            }
            $chain[] = $current;
        }
        if ( null === $clip || isset($variantNodes[$clip]) ) {
            return null;
        }
        foreach ( $chain as $box ) {
            if ( isset($variantNodes[$box]) || '100%' !== $this->value($nodes[$box]['layout']['width'] ?? null) ) {
                return null;
            }
        }
        $clipContent = $this->contentInlineSize($nodes[$clip]['layout'], $cascade[$clip]);

        $steps = array();
        $completion = null;
        foreach ( $children[$id] as $panel ) {
            $layout = $nodes[$panel]['layout'];
            if ( 'container' !== $nodes[$panel]['kind'] || isset($variantNodes[$panel]) || ! $this->nonShrinking($layout) || ! $this->fills($layout, $clipContent) ) {
                return null;
            }
            if ( 0 < ($dataEntry['owners'][$panel] ?? 0) ) {
                if ( null !== $completion ) {
                    return null;
                }
                $steps[] = array( 'wrapper' => $panel );
                continue;
            }
            if ( null !== $completion ) {
                return null;
            }
            $completion = $panel;
        }
        $inSteps = array_sum(array_map(static fn (array $step): int => $dataEntry['owners'][$step['wrapper']] ?? 0, $steps));
        if ( count($steps) < 2 || count($steps) > self::MAX_STEPS || $inSteps !== $dataEntry['controls'] ) {
            return null;
        }

        $declaration = array( 'schema' => self::SCHEMA, 'track' => $id, 'steps' => $steps );
        if ( null !== $completion ) {
            $declaration['completion'] = $completion;
        }
        $navigation = $this->navigation($id, $nodes, $children, $dataEntry);
        if ( null !== $navigation ) {
            $declaration['navigation'] = $navigation;
        }
        return $declaration;
    }

    /**
     * The single copy-only form-level box after the track carries the source's
     * own step controls (page dots, back and next affordances).
     *
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, list<string>> $children
     * @param array{controls: int, owners: array<string, int>} $dataEntry
     */
    private function navigation(string $track, array $nodes, array $children, array $dataEntry): ?string
    {
        $top = $track;
        while ( 'form' !== ($nodes[$top]['parent'] ?? null) ) {
            $top = $nodes[$top]['parent'] ?? null;
            if ( ! is_string($top) ) {
                return null;
            }
        }
        $after = false;
        $candidates = array();
        foreach ( $children['form'] ?? array() as $child ) {
            if ( $child === $top ) {
                $after = true;
                continue;
            }
            if ( $after && 'container' === $nodes[$child]['kind'] && 0 === ($dataEntry['owners'][$child] ?? 0) ) {
                $candidates[] = $child;
            }
        }
        return 1 === count($candidates) ? $candidates[0] : null;
    }

    /**
     * @param array<string, array<string, mixed>> $nodes
     * @return array<string, DOMElement>|null
     */
    private function elements(DOMElement $form, array $nodes): ?array
    {
        $bySelector = array( SourceDom::elementSelector($form) => $form );
        foreach ( $form->getElementsByTagName('*') as $element ) {
            if ( $element instanceof DOMElement ) {
                $bySelector[SourceDom::elementSelector($element)] = $element;
            }
        }
        $elements = array();
        foreach ( $nodes as $id => $node ) {
            $element = 'form' === $id ? $form : ($bySelector[$node['source']['selector'] ?? ''] ?? null);
            if ( ! $element instanceof DOMElement ) {
                return null;
            }
            $elements[$id] = $element;
        }
        return $elements;
    }

    /**
     * Count the form's data-entry controls inside each graph node. The graph
     * keeps only layout-bearing boxes, so containment is read from the source
     * tree rather than from graph control nodes.
     *
     * @param array<string, DOMElement> $elements
     * @return array{controls: int, owners: array<string, int>}
     */
    private function dataEntryOwners(DOMElement $form, array $elements): array
    {
        $ids = array();
        foreach ( $elements as $id => $element ) {
            $ids[$element->getNodePath()] = $id;
        }
        $controls = 0;
        $owners = array();
        foreach ( FormControlClassifier::controlElements($form) as $control ) {
            if ( ! FormControlClassifier::isDataEntryControl($control) ) {
                continue;
            }
            ++$controls;
            for ( $ancestor = $control->parentNode; $ancestor instanceof DOMElement && $ancestor !== $form; $ancestor = $ancestor->parentNode ) {
                $id = $ids[$ancestor->getNodePath()] ?? null;
                if ( null !== $id ) {
                    $owners[$id] = ($owners[$id] ?? 0) + 1;
                }
            }
        }
        return array( 'controls' => $controls, 'owners' => $owners );
    }

    /**
     * Unconditional, unlayered declared values per graph node. A property that a
     * layered or conditional rule also declares is ambiguous at that node.
     *
     * @param array<string, DOMElement> $elements
     * @param list<array<string, mixed>> $stylesheets
     * @return array<string, array{base: array<string, string>, ambiguous: array<string, bool>}>|null
     */
    private function cascade(array $elements, array $stylesheets, string $inlineCss): ?array
    {
        $retain = static function (array $selector) use ($elements): bool {
            foreach ( $elements as $element ) {
                $match = CssSelectorMatcher::matches($element, $selector);
                if ( ! $match['supported'] || $match['matches'] ) {
                    return true;
                }
            }
            return false;
        };
        $analysis = (new CssRuleAnalyzer())->analyze($stylesheets, $inlineCss, self::PROPERTIES, CssAnalysisLimits::MAX_STYLESHEET_BYTES, 512, 1024, 8, $retain, CssAnalysisLimits::MAX_SCANNED_SELECTORS);
        if ( $analysis['truncated'] ) {
            return null;
        }
        $customProperties = (new CssRuleAnalyzer())->analyze($stylesheets, $inlineCss, array( '--*' ), CssAnalysisLimits::MAX_STYLESHEET_BYTES, 512, 1024, 8, static function (array $selector) use ($elements): bool {
            foreach ( $elements as $element ) {
                for ( $ancestor = $element; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode instanceof DOMElement ? $ancestor->parentNode : null ) {
                    if ( CssSelectorMatcher::matches($ancestor, $selector)['matches'] ) {
                        return true;
                    }
                }
            }
            return false;
        }, CssAnalysisLimits::MAX_SCANNED_SELECTORS);
        if ( $customProperties['truncated'] ) {
            return null;
        }

        $result = array();
        foreach ( $elements as $id => $element ) {
            $facts = array();
            $ambiguous = array();
            foreach ( $analysis['rules'] as $rule ) {
                $match = CssSelectorMatcher::matches($element, $rule['parsed_selector']);
                if ( ! $match['matches'] ) {
                    continue;
                }
                foreach ( $rule['declarations'] as $declaration ) {
                    if ( null !== ($rule['condition'] ?? null) || null !== ($rule['layer'] ?? null) ) {
                        $ambiguous[$declaration['name']] = true;
                        continue;
                    }
                    CssCascade::apply($facts, $declaration['name'], $this->fact($declaration['value'], $rule['order'], $rule['specificity']));
                }
            }
            if ( $element->hasAttribute('style') ) {
                foreach ( CssRuleAnalyzer::declarations($element->getAttribute('style'), self::PROPERTIES) as $declaration ) {
                    CssCascade::apply($facts, $declaration['name'], $this->fact($declaration['value'], PHP_INT_MAX, PHP_INT_MAX) + array( 'inline' => true ));
                }
            }
            $base = array();
            foreach ( $facts as $property => $fact ) {
                $base[$property] = strtolower(FormCustomPropertyResolver::resolve($fact['value'], $element, null, $customProperties['rules']));
            }
            $result[$id] = array( 'base' => $base, 'ambiguous' => $ambiguous );
        }
        return $result;
    }

    /** @return array<string, mixed> */
    private function fact(string $value, int $order, mixed $specificity): array
    {
        return array(
            'value' => preg_replace('/\s*!important\s*$/i', '', $value) ?? $value,
            'order' => $order,
            'specificity' => $specificity,
            'important' => 1 === preg_match('/\s*!important\s*$/i', $value),
        );
    }

    /**
     * @param array{base: array<string, string>, ambiguous: array<string, bool>} $facts
     * @return array{direction: string, wrap: string}|null
     */
    private function flow(array $facts): ?array
    {
        if ( isset($facts['ambiguous']['flex-flow']) || isset($facts['ambiguous']['flex-direction']) || isset($facts['ambiguous']['flex-wrap']) ) {
            return null;
        }
        $direction = null;
        $wrap = null;
        foreach ( CssValueSplitter::splitTopLevelWhitespace($facts['base']['flex-flow'] ?? '') as $token ) {
            if ( in_array($token, self::FLEX_DIRECTIONS, true) ) {
                $direction = $token;
            } elseif ( in_array($token, self::FLEX_WRAPS, true) ) {
                $wrap = $token;
            } else {
                return null;
            }
        }
        foreach ( array( 'flex-direction' => &$direction, 'flex-wrap' => &$wrap ) as $property => &$value ) {
            $longhand = $facts['base'][$property] ?? null;
            if ( null !== $longhand ) {
                if ( null !== $value && $value !== $longhand ) {
                    return null;
                }
                $value = $longhand;
            }
        }
        unset($value);
        return array( 'direction' => $direction ?? 'row', 'wrap' => $wrap ?? 'nowrap' );
    }

    /** @param array{base: array<string, string>, ambiguous: array<string, bool>} $facts */
    private function clips(array $facts): ?bool
    {
        foreach ( array( 'overflow', 'overflow-x', 'overflow-inline' ) as $property ) {
            if ( isset($facts['ambiguous'][$property]) ) {
                return null;
            }
        }
        $inline = $facts['base']['overflow-x'] ?? $facts['base']['overflow-inline'] ?? null;
        if ( null === $inline && isset($facts['base']['overflow']) ) {
            $inline = CssValueSplitter::splitTopLevelWhitespace($facts['base']['overflow'])[0] ?? null;
        }
        return in_array($inline, array( 'hidden', 'clip' ), true);
    }

    /** @param array<string, string> $layout */
    private function nonShrinking(array $layout): bool
    {
        if ( isset($layout['flex_shrink']) ) {
            return '0' === $this->value($layout['flex_shrink']);
        }
        $flex = $this->value($layout['flex'] ?? null);
        if ( 'none' === $flex ) {
            return true;
        }
        $parts = CssValueSplitter::splitTopLevelWhitespace((string) $flex);
        return 3 === count($parts) && '0' === $parts[1];
    }

    /**
     * Whether a panel spans the clipping box's content inline size: the full
     * width of the track (which spans that box), or that exact pixel length.
     *
     * @param array<string, string> $layout
     */
    private function fills(array $layout, ?float $clipContent): bool
    {
        $width = $this->value($layout['width'] ?? null);
        if ( '100%' === $width ) {
            return true;
        }
        $pixels = $this->pixels($width);
        return null !== $pixels && null !== $clipContent && abs($pixels - $clipContent) < 0.5;
    }

    /**
     * @param array<string, string> $layout
     * @param array{base: array<string, string>, ambiguous: array<string, bool>} $facts
     */
    private function contentInlineSize(array $layout, array $facts): ?float
    {
        $width = $this->pixels($this->value($layout['width'] ?? null));
        if ( null === $width || isset($facts['ambiguous']['box-sizing']) ) {
            return null;
        }
        if ( 'border-box' !== ($facts['base']['box-sizing'] ?? 'content-box') ) {
            return $width;
        }
        foreach ( array_keys($facts['base']) as $property ) {
            if ( str_starts_with($property, 'border') ) {
                return null;
            }
        }
        $padding = array_map(fn (string $value): ?float => $this->pixels($value), CssValueInspector::expandBoxShorthand((string) ($layout['padding'] ?? '0')));
        $left = array_key_exists('padding_left', $layout) ? $this->pixels($layout['padding_left']) : ($padding[3] ?? null);
        $right = array_key_exists('padding_right', $layout) ? $this->pixels($layout['padding_right']) : ($padding[1] ?? null);
        $left = array_key_exists('padding_inline_start', $layout) ? $this->pixels($layout['padding_inline_start']) : $left;
        $right = array_key_exists('padding_inline_end', $layout) ? $this->pixels($layout['padding_inline_end']) : $right;
        return null === $left || null === $right ? null : $width - $left - $right;
    }

    private function value(mixed $value): ?string
    {
        return is_string($value) ? strtolower(trim($value)) : null;
    }

    private function pixels(?string $value): ?float
    {
        if ( null === $value ) {
            return null;
        }
        $value = strtolower(trim($value));
        if ( '0' === $value ) {
            return 0.0;
        }
        return 1 === preg_match('/^(\d+(?:\.\d+)?|\.\d+)px$/D', $value, $match) ? (float) $match[1] : null;
    }
}

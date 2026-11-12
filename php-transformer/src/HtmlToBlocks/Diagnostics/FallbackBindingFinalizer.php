<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics;

use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlTagScanner;

/**
 * The post-serialization pass that settles fallback bindings: groups qualified
 * purchase control runs under one anchor, then resolves each fallback's
 * binding to the serialized block markup it addresses.
 */
final class FallbackBindingFinalizer
{
    public function __construct(
        private readonly SourceBlockCreator $blocks,
        private readonly HtmlTransformerSession $session,
        private readonly CommerceFallbackReporter $commerceReporter
    ) {
    }

    /** Group only qualified purchase control runs, without changing source qualification. */
    public function anchorPurchaseControlRuns(array &$blocks, array &$fallbacks): void
    {
        $provenanceIndexes = array();
        $index = 0;
        $this->bindingProvenanceIndexes($blocks, $provenanceIndexes, $index);
        $anchors = $this->bindingAnchorSelectorIndexes($provenanceIndexes);
        $index = 0;
        $this->sourceAnchorSelectorIndexes($blocks, $anchors, $index);
        $runs = array();
        foreach ( $fallbacks as &$fallback ) {
            $selectors = $fallback['binding']['_binding_control_run_selectors'] ?? null;
            if ( ! is_array($selectors) ) continue;
            $indexes = array_map(static fn (string $selector): ?int => $anchors[$selector] ?? null, $selectors);
            unset($fallback['binding']['_binding_control_run_selectors']);
            if ( in_array(null, $indexes, true) ) {
                $fallback['binding'] = array();
                continue;
            }
            $selector = count($indexes) > 1 ? 'purchase-control-run:' . $selectors[0] : $selectors[0];
            $fallback['binding']['_binding_anchor_selector'] = $selector;
            $runs[$indexes[0]] = array('end' => $indexes[count($indexes) - 1], 'selector' => $selector);
        }
        unset($fallback);
        $index = 0;
        $walk = function (array &$siblings, ?array &$innerContent = null) use (&$walk, &$index, $runs): void {
            $positions = array();
            foreach ( $siblings as &$block ) {
                $positions[] = $index++;
                if ( is_array($block['innerBlocks'] ?? null) ) $walk($block['innerBlocks'], $block['innerContent']);
            }
            unset($block);
            for ( $start = count($siblings) - 1; $start >= 0; --$start ) {
                $run = $runs[$positions[$start]] ?? null;
                if ( null === $run ) continue;
                $end = array_search($run['end'], $positions, true);
                if ( false === $end || $end < $start ) continue;
                if ( $end === $start ) continue;
                $group = $this->blocks->createBlock('core/group', array('layout' => array('type' => 'default')), array_slice($siblings, $start, $end - $start + 1));
                $group['_source_anchor_selector'] = $run['selector'];
                array_splice($siblings, $start, $end - $start + 1, array($group));
                // The run now occupies its first child's slot. Remove the
                // other slots at this same mutation boundary, preserving all
                // literal wrapper markup and inter-child whitespace.
                if ( null !== $innerContent ) {
                    $childIndex = 0;
                    $innerContent = array_values(array_filter($innerContent, static function ($part) use (&$childIndex, $start, $end): bool {
                        if ( null !== $part ) return true;
                        $slot = $childIndex++;
                        return $slot <= $start || $slot > $end;
                    }));
                }
            }
        };
        $walk($blocks);
    }

    /** @param array<int,array<string,mixed>> $fallbacks @param array<int,array<string,mixed>> $blocks */
    public function finalize(array &$fallbacks, array $blocks, string $markup): void
    {
        $provenanceIndexes = array(); $index = 0;
        $this->bindingProvenanceIndexes($blocks, $provenanceIndexes, $index);
        $anchorIndexes = $this->bindingAnchorSelectorIndexes($provenanceIndexes);
        $index = 0;
        $this->sourceAnchorSelectorIndexes($blocks, $anchorIndexes, $index);
        $ranges = $this->serializedBlockRanges($markup);
        $finalize = function (array &$binding) use ($markup, $provenanceIndexes, $anchorIndexes, $ranges): void {
            $provenanceId = $binding['_binding_provenance_id'] ?? null;
            $blockIndex = is_int($provenanceId) ? ($provenanceIndexes[$provenanceId] ?? null) : null;
            $anchorSelector = $binding['_binding_anchor_selector'] ?? null;
            if ( ! is_int($blockIndex) && is_string($anchorSelector) ) $blockIndex = $anchorIndexes[$anchorSelector] ?? null;
            // Cart links address the control, not its layout-only buttons
            // wrapper, which shell extraction may merge or remove.
            if ( 'commerce_cart_link' === ($binding['role'] ?? null) && is_int($blockIndex) && isset($ranges[$blockIndex + 1]) && str_starts_with(substr($markup, $ranges[$blockIndex]['offset']), '<!-- wp:buttons ') ) {
                ++$blockIndex;
            }
            $range = is_int($blockIndex) ? ($ranges[$blockIndex] ?? null) : null;
            if (is_array($range)) {
                $search = substr($markup, $range['offset'], $range['length']);
                if (is_string($search) && '' !== $search) {
                    $binding['search_block_markup'] = $search;
                    $binding['occurrence'] = HtmlTagScanner::occurrenceAtOffset($markup, $search, $range['offset']);
                    $binding['position'] = array('schema' => 'blocks-engine/runtime-binding-position/v1', 'block_index' => $blockIndex, 'offset' => $range['offset'], 'length' => $range['length']);
                } else {
                    $binding = array();
                }
            } else {
                $binding = array();
            }
            unset($binding['_binding_provenance_id'], $binding['_binding_anchor_selector'], $binding['_binding_control_run_selectors']);
        };
        foreach ( $fallbacks as &$fallback ) {
            if (is_array($fallback['binding'] ?? null)) $finalize($fallback['binding']);
            if (is_array($fallback['listing'] ?? null)) {
                // A listing control a provider cannot locate is dropped; the
                // listing itself needs its region and at least one control.
                $finalize($fallback['listing']['binding']);
                $controls = array();
                foreach ($fallback['listing']['controls'] as $control) {
                    $finalize($control['binding']);

                    if (array() !== $control['binding']) $controls[] = $control;
                }
                $fallback['listing']['controls'] = $controls;
                if (array() === $fallback['listing']['binding'] || array() === $controls) unset($fallback['listing']);
            }
            if (is_array($fallback['products'] ?? null)) {
                $cardMarkups = array();
                foreach ($fallback['products'] as $product) {
                    $cardIndex = is_int($product['_card_provenance_id'] ?? null) ? ($provenanceIndexes[$product['_card_provenance_id']] ?? null) : null;
                    $cardRange = is_int($cardIndex) ? ($ranges[$cardIndex] ?? null) : null;
                    $cardMarkups[] = is_array($cardRange) ? substr($markup, $cardRange['offset'], $cardRange['length']) : '';
                }
                foreach ($fallback['products'] as &$product) {
                    if (is_array($product['binding'] ?? null)) {
                        // A `commerce_collection` anchor that fails to resolve
                        // here (its container has no emitted block a selector
                        // can identify) is declined rather than left dangling:
                        // `ArtifactCompiler` reports why via a diagnostic
                        // instead of silently omitting the whole grid's
                        // binding intent. See `_binding_anchor_selector` above
                        // for the only anchor shape that can hit this path.
                        $isCollectionAnchor = 'commerce_collection' === ($product['binding']['role'] ?? null) && is_string($product['binding']['_binding_anchor_selector'] ?? null);
                        $finalize($product['binding']);
                        if ( $isCollectionAnchor && array() === $product['binding'] ) {
                            unset($product['binding']);
                            $fallback['_collection_binding_declined'] = true;
                        }
                    }
                    unset($product['_card_provenance_id']);
                }
                unset($product);
                // The grid binding is only useful with an item template a
                // provider can repeat in its place, and vice versa.
                $template = is_array($fallback['binding'] ?? null) && array() !== $fallback['binding'] && ! in_array('', $cardMarkups, true)
                    ? $this->commerceReporter->itemTemplate($cardMarkups, $fallback['products'])
                    : array();
                if ( array() === $template ) {
                    unset($fallback['binding']);
                } else {
                    $fallback['item_template'] = $template['item_template'];
                    foreach ($template['items'] as $productIndex => $slotValues) {
                        $fallback['products'][$productIndex]['template_slots'] = $slotValues;
                    }
                }
            }
        }
        unset($fallback);
    }

    /** @param array<int,array<string,mixed>> $blocks @param array<int,int|null> $provenanceIndexes */
    private function bindingProvenanceIndexes(array $blocks, array &$provenanceIndexes, int &$index): void
    {
        foreach ( $blocks as $block ) {
            if ( ! is_array($block) ) continue;
            $provenanceIds = is_array($block['_source_provenance_ids'] ?? null)
                ? $block['_source_provenance_ids']
                : array($block['_source_provenance_id'] ?? null);
            foreach (array_unique(array_filter($provenanceIds, 'is_int')) as $provenanceId) {
                if (!array_key_exists($provenanceId, $provenanceIndexes)) {
                    $provenanceIndexes[$provenanceId] = $index;
                } elseif ($index !== $provenanceIndexes[$provenanceId]) {
                    $provenanceIndexes[$provenanceId] = null;
                }
            }
            ++$index;
            $this->bindingProvenanceIndexes(ArrayField::array($block, 'innerBlocks'), $provenanceIndexes, $index);
        }
    }

    /**
     * Maps a source selector onto the outermost emitted block converted from
     * that element. `$provenanceIndexes` is populated in document pre-order, so
     * the first entry for a selector is the block that owns the whole converted
     * subtree. A selector whose outermost block is itself ambiguous stays
     * unresolved rather than anchoring on an arbitrary sibling.
     *
     * @param array<int,int|null> $provenanceIndexes
     * @return array<string,int|null>
     */
    private function bindingAnchorSelectorIndexes(array $provenanceIndexes): array
    {
        $anchorIndexes = array();
        foreach ( $provenanceIndexes as $provenanceId => $blockIndex ) {
            $selector = $this->session->transformationProvenanceState()->source($provenanceId)['selector'] ?? null;
            if ( ! is_string($selector) || '' === $selector || array_key_exists($selector, $anchorIndexes) ) continue;
            $anchorIndexes[$selector] = $blockIndex;
        }
        return $anchorIndexes;
    }

    /**
     * Adds anchor-only block identities (`_source_anchor_selector`) for
     * selectors no provenance source already claims.
     *
     * @param array<int,array<string,mixed>> $blocks
     * @param array<string,int|null> $anchorIndexes
     */
    private function sourceAnchorSelectorIndexes(array $blocks, array &$anchorIndexes, int &$index): void
    {
        foreach ( $blocks as $block ) {
            if ( ! is_array($block) ) continue;
            $selector = $block['_source_anchor_selector'] ?? null;
            if ( is_string($selector) && '' !== $selector && ! array_key_exists($selector, $anchorIndexes) ) $anchorIndexes[$selector] = $index;
            ++$index;
            $this->sourceAnchorSelectorIndexes(ArrayField::array($block, 'innerBlocks'), $anchorIndexes, $index);
        }
    }

    /** @return array<int,array{offset:int,length:int}> */
    private function serializedBlockRanges(string $markup): array
    {
        $ranges = array(); $stack = array();
        if (!preg_match_all('/<!--\s*(\/?)wp:.*?-->/s', $markup, $matches, PREG_OFFSET_CAPTURE)) return $ranges;
        foreach ($matches[0] as $match) {
            $token = $match[0]; $offset = $match[1];
            if (str_starts_with($token, '<!-- /wp:')) {
                $open = array_pop($stack);
                if (is_array($open)) $ranges[$open['index']]['length'] = $offset + strlen($token) - $open['offset'];
            } elseif (str_ends_with(rtrim($token), '/-->')) {
                $ranges[] = array('offset' => $offset, 'length' => strlen($token));
            } else {
                $index = count($ranges); $ranges[] = array('offset' => $offset, 'length' => 0); $stack[] = array('index' => $index, 'offset' => $offset);
            }
        }
        return array_values(array_filter($ranges, static fn(array $range): bool => 0 < $range['length']));
    }

}

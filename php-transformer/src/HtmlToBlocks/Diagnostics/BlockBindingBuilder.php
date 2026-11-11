<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\TransformationProvenanceState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMElement;

/**
 * Builds the `generic/block-binding/v1` record that ties a fallback to the
 * block a provider replaces. The binding resolves against the emitted block
 * tree during fallback-binding finalization.
 */
final class BlockBindingBuilder
{
    public function __construct(
        private readonly Runtime $runtime,
        private readonly TransformationProvenanceState $provenance
    ) {
    }

    /**
     * `$block` is the preferred anchor when the caller owns a block the page
     * actually emits; it is marked as a binding anchor so no wrapper coalesces
     * into it. `$anchorElement` is the source element whose own emitted block
     * anchors the binding when the caller has no such block. That covers a
     * div pseudo-form (blocks-engine#718/#1975), or a product card with no
     * cart control that shares its container's anchor.
     *
     * @param array<string, mixed> $block
     * @param array<int, mixed>    $supersededRuntimeSelectors
     * @return array<string, mixed>
     */
    public function binding(array $block, string $role, array $supersededRuntimeSelectors = array(), ?DOMElement $anchorElement = null): array
    {
        $provenanceId = $block['_source_provenance_id'] ?? null;
        $markup = array() !== $block ? $this->runtime->serializeBlocks(array($block)) : '';
        $anchorSelector = null !== $anchorElement ? SourceDom::elementSelector($anchorElement) : '';
        if ( ( '' === trim($markup) || ! is_int($provenanceId) ) && '' === $anchorSelector ) {
            return array();
        }
        $binding = array('schema' => 'generic/block-binding/v1', 'search_block_markup' => $markup, 'occurrence' => 1, 'role' => $role);
        if ( is_int($provenanceId) ) {
            $binding['_binding_provenance_id'] = $provenanceId;
            $this->provenance->markBindingAnchor($provenanceId);
        }
        if ( '' !== $anchorSelector ) {
            $binding['_binding_anchor_selector'] = $anchorSelector;
        }
        $supersededRuntimeSelectors = array_values(array_unique(array_filter($supersededRuntimeSelectors, static fn(mixed $selector): bool => is_string($selector) && '' !== trim($selector))));
        if ( array() !== $supersededRuntimeSelectors ) {
            $binding['superseded_runtime_selectors'] = $supersededRuntimeSelectors;
        }
        return $binding;
    }
}

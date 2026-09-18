<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\RuntimeIslandAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CommerceStructureRecognizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\TransformationProvenanceState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMElement;
use DOMNode;

/**
 * Additive commerce diagnostics for recognized product grids and cart controls.
 *
 * Layout block output is unchanged. This only appends
 * `html_product_grid_fallback` / `html_commerce_controls_fallback` findings so a
 * shop provider can materialize products or bind cart runtime. Evidence stays
 * generic (schema.org / structural triad) — never a shop-provider name.
 */
final class CommerceFallbackReporter
{
    private const MAX_CANDIDATES = 100;

    /**
     * When every qualifying card in a grid was admitted only through the
     * weaker image-corroborated catalog path (no schema.org evidence and no
     * cart control anywhere in the grid), require more repeated cards than the
     * authoritative-signal minimum before trusting the grid as commerce. A
     * small (2-3 card) pricing/plan comparison is a much more common false
     * positive at low repetition than a real product catalog is.
     */
    private const MIN_IMAGE_ONLY_QUALIFIED_CARDS = 4;

    public function __construct(
        private readonly CommerceStructureRecognizer $recognizer,
        private readonly Runtime $runtime,
        private readonly TransformationProvenanceState $provenance,
        private readonly RuntimeIslandAnalyzer $runtimeIslands
    ) {
    }

    /**
     * Surface a generic product-grid finding so a downstream consumer can
     * materialize the recognized products (e.g. as commerce products) without the
     * transformer carrying any provider or plugin knowledge.
     *
     * This is purely ADDITIVE: the layout block output (grid -> group/columns) is
     * unchanged; this only appends an `html_product_grid_fallback` diagnostic that
     * a consumer may act on or ignore.
     *
     * Detection composes the existing commerce-recognition primitives
     * (grid_like / repeated card children / price + name tokens) with schema.org
     * microdata (`itemtype` Product / `itemprop` name|price|Offer). A product card
     * is a repeated sibling (>= 2 under a grid_like or list container) that either
     * declares schema.org Product/Offer structure OR carries the full structural
     * triad: a name (heading or name token), a currency-formatted price, and an
     * add-to-cart/buy control. Detection reads only structural/semantic signals
     * and schema.org vocabulary — never fixture names or specific class strings.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @param array<int, array<string, mixed>> $blocks
     */
    public function appendProductGridFallbacks(DOMElement $body, array &$fallbacks, array $blocks): void
    {
        $emitted = 0;
        $coveredPaths = array();
        foreach ( $body->getElementsByTagName('*') as $element ) {
            if ( ! $element instanceof DOMElement ) {
                continue;
            }

            if ( $emitted >= self::MAX_CANDIDATES ) {
                return;
            }

            if ( ! $this->recognizer->isProductGridContainer($element) ) {
                continue;
            }

            // Prefer the innermost qualifying container: skip a grid whose products
            // were already attributed to a nested grid emitted earlier in the walk.
            $path = $element->getNodePath() ?? '';
            foreach ( $coveredPaths as $coveredPath ) {
                if ( '' !== $path && '' !== $coveredPath && str_starts_with($coveredPath, $path . '/') ) {
                    continue 2;
                }
            }

            $products = $this->productCardsForContainer($element, $blocks);
            if ( count($products) < 2 ) {
                continue;
            }

            // A card only reaches here via schema.org evidence, a cart control,
            // or the weaker image-corroborated catalog path (see
            // `CommerceStructureRecognizer::productCardData()`). When every
            // qualifying card in this grid used only the weaker path, require
            // more repetition before trusting it as commerce, since a small
            // pricing/plan comparison is a far more common false positive at
            // low repetition than a real product catalog is.
            $imageOnlyQualified = array_filter($products, static fn (array $product): bool => true === ($product['_catalog_image_only'] ?? false));
            if ( count($imageOnlyQualified) === count($products) && count($products) < self::MIN_IMAGE_ONLY_QUALIFIED_CARDS ) {
                continue;
            }
            foreach ( $products as &$product ) {
                unset($product['_catalog_image_only']);
            }
            unset($product);

            $coveredPaths[] = $path;

            $fallbacks[] = FallbackDiagnostic::build(array_filter(array(
                'type'              => 'html',
                'reason'            => 'commerce_product_grid_detected',
                'diagnostic_code'   => 'html_product_grid_fallback',
                'kind'              => 'html_product_grid_fallback',
                'message'           => 'A product grid was detected; per-card commerce structure was extracted so a shop provider can materialize the products.',
                'source_format'     => 'html',
                'tag'               => strtolower($element->tagName),
                'selector'          => SourceDom::elementSelector($element),
                'container_selector' => SourceDom::elementSelector($element),
                'context'           => $this->sourceContext($element),
                'products'          => $products,
                'product_count'     => count($products),
            ), static fn (mixed $value): bool => null !== $value && '' !== $value && array() !== $value), $this->provenance->fallback());
            ++$emitted;
        }
    }

    /**
     * Surface commerce-specific runtime controls separately from the surrounding
     * product-grid structure. The transformer can emit editable layout/product
     * metadata, but quantity and add-to-cart controls require a commerce runtime.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     */
    public function appendCommerceControlsFallbacks(DOMElement $body, array &$fallbacks): void
    {
        $emitted = 0;
        foreach ( $body->getElementsByTagName('*') as $element ) {
            if ( ! $element instanceof DOMElement ) {
                continue;
            }

            if ( $emitted >= self::MAX_CANDIDATES ) {
                return;
            }

            if ( ! $this->recognizer->isProductGridContainer($element) ) {
                continue;
            }

            $controlGroups = $this->recognizer->commerceControlGroupsForContainer($element);
            if ( array() === $controlGroups ) {
                continue;
            }

            $fallbacks[] = FallbackDiagnostic::build(array_filter(array(
                'type'              => 'html',
                'reason'            => 'commerce_controls_require_runtime',
                'diagnostic_code'   => 'html_commerce_controls_fallback',
                'kind'              => 'html_commerce_controls_fallback',
                'message'           => 'Commerce quantity and add-to-cart controls were detected; product data can be seeded by a shop provider, but these controls need cart runtime binding rather than a static core block approximation.',
                'source_format'     => 'html',
                'tag'               => strtolower($element->tagName),
                'selector'          => SourceDom::elementSelector($element),
                'container_selector' => SourceDom::elementSelector($element),
                'context'           => $this->sourceContext($element),
                'controls'          => $controlGroups,
                'control_count'     => count($controlGroups),
            ), static fn (mixed $value): bool => null !== $value && '' !== $value && array() !== $value), $this->provenance->fallback());
            ++$emitted;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    private function productCardsForContainer(DOMElement $container, array $blocks = array()): array
    {
        $products = array();
        foreach ( $container->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $product = $this->recognizer->productCardData($child);
            if ( null !== $product ) {
                // Internal marker consumed and stripped by the caller
                // (`appendProductGridFallbacks()`) before this reaches a
                // published finding; never part of the diagnostic contract.
                $product['_catalog_image_only'] = ! $this->recognizer->isSchemaProductCard($child) && null === $this->recognizer->cartControlElement($child);
                $binding = $this->commerceBindingForCard($child, $blocks);
                if ( array() !== $binding ) {
                    $product['binding'] = $binding;
                }
                $products[] = $product;
            }
        }

        return $products;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<string, mixed>
     */
    private function commerceBindingForCard(DOMElement $card, array $blocks): array
    {
        if ( array() === $blocks ) {
            return array();
        }
        $control = $this->recognizer->cartControlElement($card);
        if ( null === $control ) {
            return array();
        }
        $block = $this->blockForSourceSelector($blocks, SourceDom::elementSelector($control));
        if ( null === $block ) {
            return array();
        }
        return $this->blockBinding($block, 'commerce_controls', $this->runtimeIslands->runtimeDomSelectorsForElement($control));
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<string, mixed>|null
     */
    private function blockForSourceSelector(array $blocks, string $selector): ?array
    {
        foreach ( $blocks as $block ) {
            if ( ! is_array($block) ) {
                continue;
            }
            $provenanceId = $block['_source_provenance_id'] ?? null;
            if ( is_int($provenanceId) && $selector === ($this->provenance->source($provenanceId)['selector'] ?? null) ) {
                return $block;
            }
            $nested = $this->blockForSourceSelector(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array(), $selector);
            if ( null !== $nested ) {
                return $nested;
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function blockBinding(array $block, string $role, array $supersededRuntimeSelectors = array()): array
    {
        $provenanceId = $block['_source_provenance_id'] ?? null;
        $markup = $this->runtime->serializeBlocks(array($block));
        if ( '' === trim($markup) || ! is_int($provenanceId) ) {
            return array();
        }
        $binding = array('schema' => 'generic/block-binding/v1', 'search_block_markup' => $markup, 'occurrence' => 1, 'role' => $role, '_binding_provenance_id' => $provenanceId);
        $supersededRuntimeSelectors = array_values(array_unique(array_filter($supersededRuntimeSelectors, static fn(mixed $selector): bool => is_string($selector) && '' !== trim($selector))));
        if ( array() !== $supersededRuntimeSelectors ) {
            $binding['superseded_runtime_selectors'] = $supersededRuntimeSelectors;
        }
        return $binding;
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceContext(DOMElement $element): array
    {
        $parent = $element->parentNode;
        return array_filter(array(
            'selector'                => SourceDom::elementSelector($element),
            'parent_tag'              => $parent instanceof DOMElement && 'body' !== strtolower($parent->tagName) ? strtolower($parent->tagName) : '',
            'ancestor_tags'           => SourceDom::ancestorTags($element),
            'nearest_heading'         => $this->nearestPreviousHeadingText($element),
            'role'                    => SourceDom::attr($element, 'role'),
            'id'                      => SourceDom::attr($element, 'id'),
            'class_names'             => SourceDom::classNames($element),
            'ancestor_class_names'    => $this->ancestorClassNames($element),
            'data_attributes'         => $this->safeDataAttributes($element),
            'interactive_attributes'  => $this->interactiveAttributes($element),
        ), static fn (mixed $value): bool => '' !== $value && array() !== $value);
    }

    /** @return list<string> */
    private function ancestorClassNames(DOMElement $element): array
    {
        $classes = array();
        for ( $ancestor = $element->parentNode; $ancestor instanceof DOMElement && 'body' !== strtolower($ancestor->tagName); $ancestor = $ancestor->parentNode ) {
            array_push($classes, ...SourceDom::classNames($ancestor));
        }

        return array_values(array_unique($classes));
    }

    private function nearestPreviousHeadingText(DOMElement $element): string
    {
        for ( $node = $element->previousSibling; $node instanceof DOMNode; $node = $node->previousSibling ) {
            if ( $node instanceof DOMElement && preg_match('/^h[1-6]$/i', $node->tagName) ) {
                return trim(preg_replace('/\s+/', ' ', $node->textContent ?? '') ?? '');
            }
        }

        return '';
    }

    /**
     * @return array<string, string>
     */
    private function safeDataAttributes(DOMElement $element): array
    {
        $data = array();
        foreach ( SourceDom::htmlAttributes($element) as $name => $value ) {
            if ( preg_match('/^data-[a-z0-9_-]+$/i', $name) && strlen($value) <= 300 && ! preg_match('/javascript\s*:/i', $value) ) {
                $data[$name] = $value;
            }
        }

        return $data;
    }

    /**
     * @return array<string, bool|string>
     */
    private function interactiveAttributes(DOMElement $element): array
    {
        return array_filter(array(
            'tabindex'      => SourceDom::attr($element, 'tabindex'),
            'aria-expanded' => SourceDom::attr($element, 'aria-expanded'),
            'aria-controls' => SourceDom::attr($element, 'aria-controls'),
            'has_events'    => array() !== SourceDom::eventMetadata($element),
        ), static fn (mixed $value): bool => false !== $value && '' !== $value);
    }
}

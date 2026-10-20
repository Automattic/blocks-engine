<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\RuntimeIslandAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CommerceStructureRecognizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\TransformationProvenanceState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ListingControlsRecognizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMElement;
use DOMNode;

/**
 * Additive commerce diagnostics for recognized product grids and cart controls.
 *
 * Layout block output is unchanged. This only appends
 * `html_product_grid_fallback` / `html_commerce_controls_fallback` /
 * `html_cart_view_fallback` findings so a
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
            $listing = $this->listingControls($element, $products);

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
                // The whole grid as one provider-replaceable region. Its
                // runtime selectors are the hooks a source script uses to
                // redraw the cards, so a provider that renders its own product
                // loop here retires that redraw. The item template that loop
                // repeats is built from the cards' final block markup in
                // `itemTemplate()` once the page is serialized.
                'binding'           => $this->blockBinding(array(), 'commerce_product_grid', $this->runtimeIslands->runtimeDomSelectorsForElement($element), $element),
                'listing'           => $listing,
            ), static fn (mixed $value): bool => null !== $value && '' !== $value && array() !== $value), $this->provenance->fallback());
            ++$emitted;
        }
    }

    /**
     * The controls that facet, bound, sort, or count this grid's products, and
     * the region that holds them with the grid, each anchored on its own
     * emitted block. Facet values land on the products they describe: a
     * category facet as the product's `category`, any other facet as one of
     * its `attributes`.
     *
     * @param list<array<string,mixed>> $products
     * @return array<string,mixed>
     */
    private function listingControls(DOMElement $grid, array &$products): array
    {
        $cards = array();
        foreach ( $grid->childNodes as $child ) {
            if ( $child instanceof DOMElement && null !== $this->recognizer->productCardData($child) ) {
                $cards[] = $child;
            }
        }
        if ( count($cards) !== count($products) ) {
            return array();
        }
        $prices = array_map(static function (array $product): ?float {
            $digits = preg_replace('/[^0-9.]/', '', str_replace(',', '', (string) ($product['price'] ?? '')));
            return is_string($digits) && is_numeric($digits) ? (float) $digits : null;
        }, $products);
        $recognized = (new ListingControlsRecognizer())->recognize($grid, $cards, $prices);
        if ( null === $recognized ) {
            return array();
        }

        $controls = array();
        foreach ( $recognized['controls'] as $control ) {
            $element = $control['element'];
            unset($control['element']);
            $control['selector'] = SourceDom::elementSelector($element);
            $control['binding'] = $this->blockBinding(array(), 'commerce_listing_control', $this->runtimeIslands->runtimeDomSelectorsForElement($element), $element);
            $controls[] = $control;
            if ( 'facet' !== $control['kind'] ) {
                continue;
            }
            $labels = array_column($control['options'], 'label', 'value');
            foreach ( $recognized['card_facets'] as $index => $values ) {
                if ( ! isset($values[$control['key']]) ) {
                    continue;
                }
                $value = $values[$control['key']];
                $valueLabel = (string) ($labels[$value] ?? $value);
                if ( 'category' === $control['role'] ) {
                    // The filter's option names the category as shoppers pick it.
                    $products[$index]['category'] = $valueLabel;
                    continue;
                }
                $products[$index]['attributes'][] = array('key' => $control['key'], 'label' => $control['label'], 'value' => $value, 'value_label' => $valueLabel);
            }
        }

        return array(
            'schema'   => 'generic/listing-controls/v1',
            'selector' => SourceDom::elementSelector($recognized['region']),
            'binding'  => $this->blockBinding(array(), 'commerce_listing', array(), $recognized['region']),
            'controls' => $controls,
        );
    }

    /**
     * Describe one product card as a provider-neutral item template.
     *
     * `$cardMarkups[i]` is the final serialized block markup of the card that
     * shows `$products[i]`. The template is the card with the most leaf blocks;
     * every other card must fit inside it (a card may leave a container empty,
     * which makes the slots below it optional). A slot is a leaf block whose
     * markup differs between cards, or that shows a recognized product field:
     * `name`, `price`, `description`, `image`, or the `add_to_cart` control.
     * Any other varying leaf is a generic `field`. Leaves that are identical in
     * every card stay template literals.
     *
     * Returns `array()` when the cards do not share one shape, else
     * `{ item_template: {schema, block_markup, slots}, items: list<slot values> }`
     * with `items` aligned to `$products`.
     *
     * @param list<string> $cardMarkups
     * @param list<array<string,mixed>> $products
     * @return array<string,mixed>
     */
    public function itemTemplate(array $cardMarkups, array $products): array
    {
        if ( count($cardMarkups) < 2 || count($cardMarkups) !== count($products) ) {
            return array();
        }
        $cards = array();
        foreach ( $cardMarkups as $index => $markup ) {
            // A card's own add-to-cart control is one slot even when it is a
            // wrapper block (buttons > button).
            $control = 'commerce_controls' === ($products[$index]['binding']['role'] ?? null) && is_string($products[$index]['binding']['search_block_markup'] ?? null) ? $products[$index]['binding']['search_block_markup'] : '';
            $leaves = $this->leafBlocks($markup, $control);
            if ( array() === $leaves ) {
                return array();
            }
            $cards[] = $leaves;
        }
        // A price one card draws as one block and another as several (a
        // "from " qualifier beside the amount) is one slot: every card reads
        // that block whole.
        $atomicPaths = $this->priceGroupPaths($cards, $products);
        if ( array() !== $atomicPaths ) {
            foreach ( $cardMarkups as $index => $markup ) {
                $control = 'commerce_controls' === ($products[$index]['binding']['role'] ?? null) && is_string($products[$index]['binding']['search_block_markup'] ?? null) ? $products[$index]['binding']['search_block_markup'] : '';
                $cards[$index] = $this->leafBlocks($markup, $control, $atomicPaths);
                if ( array() === $cards[$index] ) {
                    return array();
                }
            }
        }
        $templateIndex = 0;
        foreach ( $cards as $index => $leaves ) {
            if ( count($leaves) > count($cards[$templateIndex]) ) {
                $templateIndex = $index;
            }
        }
        $templatePaths = array_map('strval', array_keys($cards[$templateIndex]));
        foreach ( $cards as $leaves ) {
            foreach ( array_keys($leaves) as $path ) {
                $path = (string) $path;
                if ( in_array($path, $templatePaths, true) ) {
                    continue;
                }
                // A leaf the template lacks is only acceptable as an empty
                // container whose children the template does have.
                $isEmptyContainer = '' === trim(strip_tags($leaves[$path]['inner'])) && array() !== array_filter($templatePaths, static fn (string $templatePath): bool => str_starts_with($templatePath, $path . '.'));
                if ( ! $isEmptyContainer ) {
                    return array();
                }
            }
        }

        $slots = array();
        $items = array_fill(0, count($cards), array());
        // A role that fits more than one leaf names none of them.
        $roleCounts = array();
        foreach ( $templatePaths as $path ) {
            $values = array();
            foreach ( $cards as $index => $leaves ) {
                if ( isset($leaves[$path]) ) {
                    $values[$index] = $leaves[$path];
                }
            }
            $role = $this->slotRole($values, $products);
            if ( null !== $role ) {
                $roleCounts[$role] = ($roleCounts[$role] ?? 0) + 1;
            }
        }
        foreach ( $templatePaths as $path ) {
            $path = (string) $path;
            $values = array();
            foreach ( $cards as $index => $leaves ) {
                if ( isset($leaves[$path]) ) {
                    $values[$index] = $leaves[$path];
                }
            }
            $role = $this->slotRole($values, $products);
            $roleTaken = null !== $role && isset($roleCounts[$role]) && 1 < $roleCounts[$role];
            $role = $roleTaken ? null : $role;
            $varies = count(array_unique(array_column($values, 'markup'))) > 1 || count($values) < count($cards);
            if ( null === $role && ! $varies ) {
                continue;
            }
            $id = 'slot-' . ( count($slots) + 1 );
            $classVaries = count(array_unique(array_column($values, 'class_name'))) > 1;
            $slots[] = array_filter(array(
                'id'                  => $id,
                'path'                => array_map('intval', explode('.', $path)),
                'block_name'          => $cards[$templateIndex][$path]['block_name'],
                'role'                => $role ?? 'field',
                'optional'            => count($values) < count($cards),
                'varies_class_name'   => $classVaries,
            ), static fn (mixed $value): bool => false !== $value);
            // A product field slot is filled from the provider's own product
            // data, so only generic fields and images carry their markup.
            $keepsMarkup = in_array($role ?? 'field', array( 'field', 'image' ), true);
            foreach ( $values as $index => $value ) {
                $items[$index][$id] = array_filter(array(
                    'text'       => $value['text'],
                    'markup'     => $keepsMarkup ? $value['markup'] : '',
                    'class_name' => $classVaries ? $value['class_name'] : '',
                ), static fn (string $field): bool => '' !== $field);
            }
        }
        if ( array() === $slots ) {
            return array();
        }

        return array(
            'item_template' => array(
                'schema'       => 'generic/collection-item-template/v1',
                'block_markup' => $cardMarkups[$templateIndex],
                'slots'        => $slots,
            ),
            'items'         => $items,
        );
    }

    /**
     * Leaf blocks of one card keyed by their dotted inner-block path.
     *
     * @return array<string, array{block_name:string, markup:string, inner:string, text:string, class_name:string}>
     */
    /**
     * The shallowest price paths that are one block in some cards and a group
     * of blocks in others ("from " beside the amount, say).
     *
     * A path qualifies only when, in every card, the text under it is short
     * and holds that card's own price, so cards of different shapes stay apart.
     *
     * @param list<array<string,array<string,string>>> $cards
     * @param list<array<string,mixed>> $products
     * @return array<string,true>
     */
    private function priceGroupPaths(array $cards, array $products): array
    {
        $compact = static fn (string $text): string => (string) preg_replace('/\s+/u', '', $text);
        $candidates = array();
        foreach ( $cards as $leaves ) {
            foreach ( array_keys($leaves) as $path ) {
                $path = (string) $path;
                foreach ( $cards as $other ) {
                    foreach ( array_keys($other) as $otherPath ) {
                        if ( str_starts_with((string) $otherPath, $path . '.') ) {
                            $candidates[$path] = true;
                            break 2;
                        }
                    }
                }
            }
        }
        $paths = array();
        foreach ( array_keys($candidates) as $path ) {
            $path = (string) $path;
            $holdsPrice = true;
            foreach ( $cards as $index => $leaves ) {
                $price = $compact(is_string($products[$index]['price'] ?? null) ? $products[$index]['price'] : '');
                $text = '';
                foreach ( $leaves as $leafPath => $leaf ) {
                    if ( (string) $leafPath === $path || str_starts_with((string) $leafPath, $path . '.') ) {
                        $text .= $leaf['text'];
                    }
                }
                // Short, like the price slot: the amount and its qualifier.
                if ( '' === $price || ! str_contains($compact($text), $price) || mb_strlen($compact($text)) > 3 * mb_strlen($price) + 8 ) {
                    $holdsPrice = false;
                    break;
                }
            }
            if ( $holdsPrice ) {
                $paths[$path] = true;
            }
        }
        foreach ( array_keys($paths) as $path ) {
            foreach ( array_keys($paths) as $other ) {
                if ( str_starts_with((string) $path, $other . '.') ) {
                    unset($paths[$path]);
                    break;
                }
            }
        }

        return $paths;
    }

    /**
     * @param array<string,true> $atomicPaths Paths read as one leaf block.
     */
    private function leafBlocks(string $markup, string $atomicMarkup = '', array $atomicPaths = array()): array
    {
        $parsed = array_values(array_filter($this->runtime->parseBlocks($markup), static fn (mixed $block): bool => is_array($block) && is_string($block['blockName'] ?? null)));
        if ( 1 !== count($parsed) ) {
            return array();
        }
        // Parsed blocks and comment-delimited ranges are both in document
        // order, so the n-th block visited is the n-th range.
        $ranges = array();
        $stack = array();
        if ( preg_match_all('/<!--\s*(\/?)wp:.*?-->/s', $markup, $matches, PREG_OFFSET_CAPTURE) ) {
            foreach ( $matches[0] as $match ) {
                if ( str_starts_with($match[0], '<!-- /wp:') ) {
                    $open = array_pop($stack);
                    if ( null !== $open ) {
                        $ranges[$open] = substr($markup, $ranges[$open], $match[1] + strlen($match[0]) - $ranges[$open]);
                    }
                } elseif ( str_ends_with(rtrim($match[0]), '/-->') ) {
                    $ranges[] = $match[0];
                } else {
                    $stack[] = count($ranges);
                    $ranges[] = $match[1];
                }
            }
        }
        $leaves = array();
        $order = 0;
        $walk = function (array $block, string $path) use (&$walk, &$leaves, &$order, $ranges, $atomicMarkup, $atomicPaths): void {
            $blockMarkup = $ranges[$order] ?? '';
            ++$order;
            $children = array_values(array_filter($block['innerBlocks'] ?? array(), static fn (mixed $child): bool => is_array($child) && is_string($child['blockName'] ?? null)));
            if ( ( '' !== $atomicMarkup && $blockMarkup === $atomicMarkup ) || ( array() !== $children && '' !== $path && isset($atomicPaths[$path]) ) ) {
                // Skip the descendants' ranges so later blocks stay aligned.
                $order += substr_count($blockMarkup, '<!-- wp:') - 1;
                $children = array();
            }
            if ( array() === $children ) {
                // A block read whole keeps its descendants' text.
                $inner = is_string($block['innerHTML'] ?? null) && '' === $blockMarkup ? $block['innerHTML'] : (string) preg_replace('/<!--\s*\/?wp:.*?-->/s', '', (string) $blockMarkup);
                $leaves[$path] = array(
                    'block_name' => (string) $block['blockName'],
                    'markup'     => is_string($blockMarkup) ? $blockMarkup : '',
                    'inner'      => is_string($blockMarkup) ? (string) preg_replace('/<!--\\s*\\/?wp:.*?-->/s', '', $blockMarkup) : $inner,
                    'text'       => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? ''),
                    'class_name' => is_string($block['attrs']['className'] ?? null) ? $block['attrs']['className'] : '',
                );
                return;
            }
            foreach ( $children as $index => $child ) {
                $walk($child, '' === $path ? (string) $index : $path . '.' . $index);
            }
        };
        $walk($parsed[0], '');

        return array() === array_filter(array_column($leaves, 'markup'), static fn (string $leafMarkup): bool => '' === $leafMarkup) ? $leaves : array();
    }

    /**
     * The product field one template leaf shows in every card that has it.
     *
     * @param array<int, array{markup:string, inner:string, text:string}> $values
     * @param list<array<string,mixed>> $products
     */
    private function slotRole(array $values, array $products): ?string
    {
        $tests = array(
            'add_to_cart' => static fn (array $value, array $product): bool => is_string($product['binding']['search_block_markup'] ?? null) && 'commerce_controls' === ($product['binding']['role'] ?? null) && $value['markup'] === $product['binding']['search_block_markup'],
            'name'        => static fn (array $value, array $product): bool => is_string($product['name'] ?? null) && '' !== $product['name'] && $value['text'] === $product['name'],
            // A sale card shows both prices in one leaf, so the price leaf is
            // the short leaf that contains the regular price.
            'price'       => static fn (array $value, array $product): bool => is_string($product['price'] ?? null) && '' !== trim($product['price']) && str_contains($value['text'], trim($product['price'])) && mb_strlen($value['text']) <= 3 * mb_strlen(trim($product['price'])) + 8,
            'description' => static fn (array $value, array $product): bool => is_string($product['description'] ?? null) && '' !== $product['description'] && $value['text'] === trim($product['description']),
            'image'       => static fn (array $value, array $product): bool => is_string($product['image']['src'] ?? null) && '' !== basename((string) parse_url($product['image']['src'], PHP_URL_PATH)) && str_contains($value['inner'], basename((string) parse_url($product['image']['src'], PHP_URL_PATH))),
        );
        foreach ( $tests as $role => $test ) {
            $matches = true;
            foreach ( $values as $index => $value ) {
                if ( ! $test($value, $products[$index] ?? array()) ) {
                    $matches = false;
                    break;
                }
            }
            if ( $matches && array() !== $values ) {
                return $role;
            }
        }

        return null;
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
     * Surface each cart view — a script-owned region holding the order summary
     * (amount rows, a total and a checkout control) and the line items the
     * script renders into it — so a shop provider can put its own cart where
     * the source's client-side cart was. The layout blocks are unchanged.
     *
     * The binding anchors on the cart view's script scope: the outermost
     * runtime DOM target around the summary, below the page landmarks. That is
     * the element the source script looks up before rendering, so a provider
     * that replaces it also retires the source's cart rendering instead of
     * leaving it to draw a second, empty cart beside the provider's. A summary
     * outside any runtime scope anchors on itself.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     */
    public function appendCartViewFallbacks(DOMElement $body, array &$fallbacks): void
    {
        $scopes = array();
        foreach ( $this->recognizer->cartSummaries($body) as $summary ) {
            if ( count($scopes) >= self::MAX_CANDIDATES ) {
                break;
            }
            $scope = $this->cartViewScope($summary);
            $id = spl_object_id($scope);
            if ( isset($scopes[$id]) ) {
                continue;
            }
            $scopes[$id] = true;
            $runtimeSelectors = $this->runtimeIslands->runtimeDomSelectorsForElement($scope);
            $fallbacks[] = FallbackDiagnostic::build(array_filter(array(
                'type'              => 'html',
                'reason'            => 'cart_view_requires_commerce_runtime',
                'diagnostic_code'   => 'html_cart_view_fallback',
                'kind'              => 'html_cart_view_fallback',
                'message'           => 'A cart view (order summary with totals and a checkout control) was detected; its line items and totals come from a client-side cart, so a shop provider must supply the cart in its place.',
                'source_format'     => 'html',
                'tag'               => strtolower($scope->tagName),
                'selector'          => SourceDom::elementSelector($scope),
                'summary_selector'  => SourceDom::elementSelector($summary),
                'runtime_selectors' => $runtimeSelectors,
                'context'           => $this->sourceContext($scope),
                'binding'           => $this->blockBinding(array(), 'commerce_cart', $runtimeSelectors, $scope),
            ), static fn (mixed $value): bool => null !== $value && '' !== $value && array() !== $value), $this->provenance->fallback());
        }
    }

    /** The outermost runtime DOM target around `$summary` below the page landmarks, else the summary. */
    private function cartViewScope(DOMElement $summary): DOMElement
    {
        $scope = $summary;
        for ( $node = $summary; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( in_array(strtolower($node->tagName), array( 'body', 'main', 'header', 'footer', 'nav' ), true) ) {
                break;
            }
            if ( array() !== $this->runtimeIslands->runtimeDomSelectorsForElement($node) ) {
                $scope = $node;
            }
        }

        return $scope;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    private function productCardsForContainer(DOMElement $container, array $blocks = array()): array
    {
        $products = array();
        // A card with its own cart control anchors on that control (existing,
        // proven path). A card with no cart control at all — the buy action
        // lives on a per-item detail page, not the browse grid — has nothing
        // per-card to anchor on, so every such card in this grid instead
        // shares one anchor: the grid container's own emitted block, resolved
        // later against the page's actual block tree during fallback-binding
        // finalization, exactly like the div-pseudo-form anchor
        // (blocks-engine#718/#1975). Computed lazily and once per container
        // since most grids never need it. `null` means "not computed yet";
        // `array()` means "computed and could not be resolved" (declined).
        $collectionBinding = null;
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
                $card = $this->blockForSourceSelector($blocks, SourceDom::elementSelector($child));
                if ( is_int($card['_source_provenance_id'] ?? null) ) {
                    // Internal marker consumed by fallback-binding
                    // finalization to build the grid's item template.
                    $product['_card_provenance_id'] = $card['_source_provenance_id'];
                }
                $binding = $this->commerceBindingForCard($child, $blocks);
                if ( array() === $binding ) {
                    $collectionBinding ??= $this->blockBinding(array(), 'commerce_collection', array(), $container);
                    $binding = $collectionBinding;
                }
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

    /**
     * `$block` is the preferred anchor when the caller owns a block the page
     * actually emits (the per-card cart-control path). `$anchorElement` is the
     * source element whose own emitted block anchors the binding when the
     * caller has no such block (a card with no cart control at all, so every
     * card in the grid shares its container's anchor instead). The anchor
     * selector is resolved against the page's actual emitted block tree during
     * fallback-binding finalization, exactly like the div-pseudo-form anchor
     * (blocks-engine#718/#1975) — see that finalization step's docblock for
     * why an anchor selector, not a synthesized block, is what keeps a binding
     * page-owned. A container shared by many cards resolves to exactly one
     * canonical position; the site-plan composer recognizes the
     * `commerce_collection` role as a single anchor legitimately shared by
     * many entities, rather than N entities racing for one position.
     *
     * @return array<string,mixed>
     */
    private function blockBinding(array $block, string $role, array $supersededRuntimeSelectors = array(), ?DOMElement $anchorElement = null): array
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

<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormControlTopologyBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormPresentationGraphBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormStepTrackDetector;
use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;

/**
 * One promotion run: reads the artifact's product evidence, collects the
 * provider-materializable entities its conversion fallbacks describe, then
 * settles which surface owns each product and form before listing the
 * runtime collections RuntimeDeclarationPromotion declares.
 */
final class RuntimeEntityCollector
{
    private readonly \Closure $slug;
    private readonly \Closure $price;
    private readonly string $entryRoot;
    /** @var array<string,string> */
    private array $productRoutes = array();
    /** @var array<string,list<string>> */
    private array $pageProducts = array();
    /** @var array<string,string> */
    private array $detailPages = array();
    /** @var array<string,mixed> */
    private array $pageEvidence = array();
    /** @var array<string,array<string,mixed>> */
    private array $schemaProducts;
    /** @var array<string,string> */
    private array $pageRoutes = array();
    /** @var array<string,list<string>> */
    private array $compiledPages = array();
    /** @var array<string,array<string,mixed>> */
    private array $products = array();
    /** @var array<string,list<string>> */
    private array $cardPageProducts = array();
    /** @var array<string,array<string,mixed>> */
    private array $forms = array();
    /** @var array<string,array<string,mixed>> */
    private array $orderLookups = array();
    /** @var array<string,array<string,mixed>> */
    private array $carts = array();
    /** @var array<string,array<string,mixed>> */
    private array $checkouts = array();
    /** @var array<string,array<string,mixed>> */
    private array $productGrids = array();
    /** @var array<string,array<string,mixed>> */
    private array $productListings = array();
    /** @var array<string,list<string>> */
    private array $listingControlSelectors = array();

    /**
     * @param array<int,array<string,mixed>> $files
     * @param array<int,array<string,mixed>> $diagnostics Findings so far; the run appends its own.
     */
    public function __construct(
        private readonly array $files,
        private readonly string $entryPath,
        \Closure $sourceHasPurchaseAffordance,
        public array $diagnostics
    ) {
        $this->slug = SchemaProductEvidence::productSlug(...);
        $this->price = SchemaProductEvidence::productPrice(...);
        $entryRoot = dirname($entryPath);
        $this->entryRoot = $entryRoot;
        $this->schemaProducts = $sourceHasPurchaseAffordance()
            ? SchemaProductEvidence::rows($files, $this->slug, $this->price, $this->diagnostics, $this->productRoutes, $this->pageProducts, $this->detailPages, '.' === $entryRoot ? '' : $entryRoot, $this->pageEvidence)
            : array();
        // Match the plan's page route, including explicit routes and entry roots.
        foreach ($files as $file) {
            $path = $file['path'] ?? '';
            $relative = '.' !== $entryRoot && str_starts_with($path, $entryRoot . '/') ? substr($path, strlen($entryRoot) + 1) : $path;
            $this->pageRoutes[$path] = $file['metadata']['route_path'] ?? SchemaProductEvidence::pageRoute('/' . $relative, $path, '.' === $entryRoot ? '' : $entryRoot);
            if ('page' !== ($file['metadata']['post_type'] ?? 'page')) unset($this->detailPages[$path]);
        }
        // Card URLs identify pages by the same entry-root-relative route as the plan.
        foreach ($files as $file) {
            if (ArtifactFileIndex::isMaterializedHtmlDocument($file) && 'page' === ($file['metadata']['post_type'] ?? 'page')) {
                $path = (string) $file['path'];
                $relative = '.' !== $entryRoot && str_starts_with($path, $entryRoot . '/') ? substr($path, strlen($entryRoot) + 1) : $path;
                $routes = array($this->pageRoutes[$path] ?? '', SchemaProductEvidence::pageRoute('/' . rawurldecode($relative), $path, '.' === $entryRoot ? '' : $entryRoot));
                foreach (array_unique(array_filter($routes)) as $route) $this->compiledPages[$route][] = $path;
            }
        }
    }

    /**
     * Collect every entity the fallbacks describe, settle product and form
     * ownership, and list the collections in declaration order.
     *
     * @param array<int,mixed> $fallbacks
     * @return list<array<string,mixed>>
     */
    public function collections(array $fallbacks): array
    {
        foreach ( $fallbacks as $fallback ) {
            if ( ! is_array($fallback) ) continue;
            $code = (string) ($fallback['diagnostic_code'] ?? $fallback['kind'] ?? '');
            $sourcePath = is_string($fallback['source'] ?? null) ? $fallback['source'] : $this->entryPath;
            if ( 'html_product_grid_fallback' === $code ) {
                $this->collectProductGrid($fallback, $sourcePath);
            } elseif ( 'html_cart_view_fallback' === $code ) {
                // A cart view is provider-bindable only through its page-owned
                // anchor; without one a provider cannot locate it in the page.
                $cart = $this->anchoredView($fallback, $sourcePath);
                if ( null !== $cart ) $this->carts[$sourcePath . "\n" . $cart['selector']] = $cart;
            } elseif ( 'html_checkout_view_fallback' === $code ) {
                // A checkout view binds through its page-owned anchor exactly
                // like a cart view; the reporter already superseded the cart
                // finding over the same region, so the two never co-declare.
                $checkout = $this->anchoredView($fallback, $sourcePath);
                if ( null !== $checkout ) $this->checkouts[$sourcePath . "\n" . $checkout['selector']] = $checkout;
            } elseif ( 'html_form_fallback' === $code && is_array($fallback['controls'] ?? null) ) {
                $this->collectForm($fallback, $sourcePath);
            }
        }
        $this->inheritCardPageProducts();
        $this->claimPurchaseForms($fallbacks);
        $this->mergeSchemaProducts();
        $this->releaseShopOwnedForms();
        foreach ($this->products as $productSlug => &$product) {
            $product['id'] = 'product:' . hash('sha256', (string) $productSlug);
            // Only a claimed detail page names the product's route; others keep their URL.
            if (isset($product['whole_page_candidate'])) $product['source_route'] = $this->pageRoutes[$product['source_path']];
        }
        unset($product);
        ksort($this->products, SORT_STRING); ksort($this->forms, SORT_STRING); ksort($this->orderLookups, SORT_STRING); ksort($this->carts, SORT_STRING); ksort($this->checkouts, SORT_STRING); ksort($this->productGrids, SORT_STRING); ksort($this->productListings, SORT_STRING);
        return array(
            array('capability' => 'shop', 'type' => 'products', 'aliases' => array('product', 'products'), 'entities' => array_values($this->products), 'schema' => 'generic/products/v1'),
            // A cart holds the store's products, so a cart view is declared only
            // beside a products collection: on its own it would ask a provider
            // to install a store with nothing to sell.
            array('capability' => 'shop', 'type' => 'carts', 'aliases' => array('cart', 'carts'), 'entities' => array_values($this->carts), 'schema' => 'generic/carts/v1', 'requires' => array('product', 'products')),
            // A checkout is the store's purchase surface over the same
            // products, so like a cart view it is declared only beside a
            // products collection, and never beside a cart for the same region.
            array('capability' => 'shop', 'type' => 'checkouts', 'aliases' => array('checkout', 'checkouts'), 'entities' => array_values($this->checkouts), 'schema' => 'generic/checkouts/v1', 'requires' => array('product', 'products')),
            // Empty controlled listings still declare their replaceable grid
            // region, even when the site has no products yet.
            array('capability' => 'shop', 'type' => 'product_grids', 'aliases' => array('product_grid', 'product_grids'), 'entities' => array_values($this->productGrids), 'schema' => 'generic/product-grids/v1'),
            // The filters, sort, and count around a grid narrow or order that
            // grid, so they are declared only beside the grids.
            array('capability' => 'shop', 'type' => 'product_listings', 'aliases' => array('product_listing', 'product_listings'), 'entities' => array_values($this->productListings), 'schema' => 'generic/product-listings/v1', 'requires' => array('product_grid', 'product_grids')),
            // An order lookup asks the store for one past order by its number
            // and the buyer's email, so like carts it is declared for the shop
            // capability: a form provider could not answer it. The same form is
            // never also declared in `forms`.
            array('capability' => 'shop', 'type' => 'order_lookups', 'aliases' => array('order_lookup', 'order_lookups'), 'entities' => array_values($this->orderLookups), 'schema' => 'generic/order-lookups/v1'),
            array('capability' => 'form', 'type' => 'forms', 'aliases' => array('form', 'forms'), 'entities' => array_values($this->forms), 'schema' => 'generic/forms/v1'),
        );
    }

    /** @param array<string,mixed> $fallback */
    private function collectProductGrid(array $fallback, string $sourcePath): void
    {
        $container = is_string($fallback['container_selector'] ?? null) ? $fallback['container_selector'] : (ArrayField::string($fallback, 'selector'));
        if ( true === ($fallback['_collection_binding_declined'] ?? false) ) {
            $this->diagnostics[] = $this->declinedProductGridBindingDiagnostic($fallback, $sourcePath, $container);
        }
        $gridItems = $this->collectGridProducts($fallback, $sourcePath, $container);
        // A grid a provider can replace with its own product loop: the
        // grid-scope anchor plus the card as an item template and each
        // product's value for every template slot.
        $gridBinding = $fallback['binding'] ?? null;
        $emptyListing = 0 === ($fallback['product_count'] ?? null) && ! empty($fallback['listing']['controls']);
        if ( '' !== $container && ($emptyListing || (is_array($fallback['item_template'] ?? null) && array() !== $gridItems)) && is_array($gridBinding) && 'generic/block-binding/v1' === ($gridBinding['schema'] ?? null) && is_string($gridBinding['search_block_markup'] ?? null) && '' !== trim($gridBinding['search_block_markup']) ) {
            $this->productGrids[$sourcePath . "\n" . $container] = array('source_path' => $sourcePath, 'selector' => $container, 'items' => $gridItems, 'bindings' => array(array_merge($gridBinding, array('source_path' => $sourcePath))));
            if ( isset($fallback['item_template']) ) $this->productGrids[$sourcePath . "\n" . $container]['item_template'] = $fallback['item_template'];
            // The controls that facet, bound, sort, or count this grid:
            // the region holding them with the grid is the first
            // binding, then one binding per control placement naming its
            // id. A control rendered twice (a sort bar and the same sort
            // in a filter form) is one control with two placements.
            $listing = $fallback['listing'] ?? null;
            if ( is_array($listing) && is_string($listing['selector'] ?? null) && is_array($listing['binding'] ?? null) && is_array($listing['controls'] ?? null) && array() !== $listing['controls'] ) {
                $controls = array();
                $bindings = array(array_merge($listing['binding'], array('source_path' => $sourcePath)));
                foreach ( $listing['controls'] as $control ) {
                    $id = $control['kind'] . (isset($control['key']) ? ':' . $control['key'] : (isset($control['field']) ? ':' . $control['field'] : ''));
                    $bindings[] = array_merge($control['binding'], array('source_path' => $sourcePath, 'control' => $id));
                    unset($control['binding']);
                    if ( is_string($control['selector'] ?? null) ) $this->listingControlSelectors[$sourcePath][] = $control['selector'];
                    $controls[$id] ??= array('id' => $id) + $control;
                }
                $this->productListings[$sourcePath . "\n" . $listing['selector']] = array('source_path' => $sourcePath, 'selector' => $listing['selector'], 'grid_selector' => $container, 'controls' => array_values($controls), 'bindings' => $bindings);
            }
        }
    }

    /**
     * Record each card's product, merging repeat sightings, and return the
     * grid's item-template rows.
     *
     * @param array<string,mixed> $fallback
     * @return list<array<string,mixed>>
     */
    private function collectGridProducts(array $fallback, string $sourcePath, string $container): array
    {
        $gridItems = array();
        foreach ( ArrayField::array($fallback, 'products') as $product ) {
            if ( ! is_array($product) ) continue;
            if (is_string($product['url'] ?? null)) {
                $route = SchemaProductEvidence::urlRoute($product['url'], $sourcePath);
                if (isset($this->productRoutes[$route])) $product['slug'] = $this->productRoutes[$route];
            }
            if ( is_array($product['template_slots'] ?? null) && is_scalar($product['name'] ?? null) && '' !== trim((string) $product['name']) ) {
                $gridItems[] = array('product' => ($this->slug)(is_scalar($product['slug'] ?? null) ? (string) $product['slug'] : trim((string) $product['name'])), 'slots' => $product['template_slots']);
            }
            $name = is_scalar($product['name'] ?? null) ? trim((string) $product['name']) : '';
            $productSlug = ($this->slug)(is_scalar($product['slug'] ?? null) ? (string) $product['slug'] : $name);
            $regularPrice = ($this->price)($product['price'] ?? null);
            // A card vouched for by its priced siblings but publishing no
            // price is a price-on-request product: its row omits the price.
            $priceLabel = '' === $regularPrice && is_string($product['price_label'] ?? null) ? trim($product['price_label']) : '';
            $priceOnRequest = '' !== $priceLabel;
            if ( '' === $name || '' === $productSlug || ('' === $regularPrice && ! $priceOnRequest) ) continue;
            // Count card claims before product identity merging: two cards
            // pointing at one page are not unique page ownership evidence.
            if (is_string($product['url'] ?? null)) {
                $urlPath = parse_url(html_entity_decode($product['url'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), PHP_URL_PATH);
                if (is_string($urlPath) && '' !== $urlPath) {
                    $target = SchemaProductEvidence::pageRoute(rawurldecode($urlPath), $sourcePath, '.' === $this->entryRoot ? '' : $this->entryRoot);
                    if (1 === count($this->compiledPages[$target] ?? array())) {
                        $this->cardPageProducts[$this->compiledPages[$target][0]][] = $productSlug;
                    }
                }
            }
            $row = array('name' => $name, 'slug' => $productSlug) + ($priceOnRequest ? array('price_label' => $priceLabel) : array('regular_price' => $regularPrice));
            if (is_string($product['url'] ?? null)) $row['url'] = $product['url'];
            $salePrice = ($this->price)($product['sale_price'] ?? null); if ( '' !== $salePrice ) $row['sale_price'] = $salePrice;
            if ( is_scalar($product['description'] ?? null) && '' !== trim((string) $product['description']) ) $row['description'] = (string) $product['description'];
            $image = is_string($product['image'] ?? null) ? $product['image'] : (is_array($product['image'] ?? null) && is_string($product['image']['src'] ?? null) ? $product['image']['src'] : '');
            if ( '' !== trim($image) ) $row['image'] = $image;
            if ( is_string($product['category'] ?? null) && '' !== trim($product['category']) ) $row['category'] = trim($product['category']);
            if ( is_array($product['attributes'] ?? null) && array() !== $product['attributes'] ) $row['attributes'] = $product['attributes'];
            $sourceSelector = is_string($product['source_selector'] ?? null) ? trim($product['source_selector']) : '';
            $selectors = array_values(array_unique(array_filter(array($sourceSelector, $container), static fn(mixed $selector): bool => is_string($selector) && '' !== trim($selector))));
            if ( array() !== $selectors ) $row['source_selectors'] = $selectors;
            // This is the compiler's exact product-card identity. Consumers
            // must not infer a leaf selector from diagnostic presentation data.
            if ( '' !== $sourceSelector ) {
                $row['source_path'] = $sourcePath;
                $row['selector'] = $sourceSelector;
            }
            if ( is_array($product['binding'] ?? null) && 'generic/block-binding/v1' === ($product['binding']['schema'] ?? null) && is_string($product['binding']['search_block_markup'] ?? null) && '' !== trim($product['binding']['search_block_markup']) ) {
                $row['bindings'] = array(array_merge($product['binding'], array('source_path' => $sourcePath)));
            }
            if ( isset($this->products[$productSlug]) ) {
                // The same product on another page may be the one its filters describe.
                foreach ( array('category', 'attributes') as $field ) if ( ! isset($this->products[$productSlug][$field]) && isset($row[$field]) ) $this->products[$productSlug][$field] = $row[$field];
                if ( ! isset($row['bindings'][0]) ) {
                    continue;
                }
                $binding = $row['bindings'][0];
                $claim = $binding['source_path'] . "\n" . hash('sha256', $binding['search_block_markup']) . "\n" . $binding['occurrence'];
                $existingBindings = is_array($this->products[$productSlug]['bindings'] ?? null) ? $this->products[$productSlug]['bindings'] : array();
                $existingClaims = array_map(static fn(array $existing): string => $existing['source_path'] . "\n" . hash('sha256', $existing['search_block_markup']) . "\n" . $existing['occurrence'], $existingBindings);
                if (!in_array($claim, $existingClaims, true)) $this->products[$productSlug]['bindings'][] = $binding;
                continue;
            }
            $this->products[$productSlug] = $row;
        }
        return $gridItems;
    }

    /**
     * @param array<string,mixed> $fallback
     * @return array<string,mixed>|null
     */
    private function anchoredView(array $fallback, string $sourcePath): ?array
    {
        $selector = ArrayField::string($fallback, 'selector');
        $binding = $fallback['binding'] ?? null;
        if ( '' === $selector || ! is_array($binding) || 'generic/block-binding/v1' !== ($binding['schema'] ?? null) || ! is_string($binding['search_block_markup'] ?? null) || '' === trim($binding['search_block_markup']) ) return null;
        return array('source_path' => $sourcePath, 'selector' => $selector, 'bindings' => array(array_merge($binding, array('source_path' => $sourcePath))));
    }

    /** @param array<string,mixed> $fallback */
    private function collectForm(array $fallback, string $sourcePath): void
    {
        $selector = ArrayField::string($fallback, 'selector');
        // Only a finding carrying data-entry metadata describes a form a
        // provider could materialize, so only that finding is worth
        // reporting when a contract stops it from being declared.
        $declarable = array() !== array_filter(
            array_filter($fallback['controls'], 'is_array'),
            static fn (array $control): bool => in_array(strtolower((string) ($control['tag'] ?? '')), array('input', 'select', 'textarea'), true)
                && ! in_array(strtolower((string) ($control['type'] ?? '')), array('button', 'image', 'reset', 'submit'), true)
        );
        $choiceGroups = array_values(array_filter(ArrayField::array($fallback, 'choice_groups'), 'is_array'));
        if ( array() !== $choiceGroups ) $declarable = true;
        if ( true === ($fallback['control_topology']['truncated'] ?? false) ) { if ( $declarable ) $this->diagnostics[] = $this->declinedFormDeclarationDiagnostic($fallback, $sourcePath, $selector, 'control_topology_truncated', 'its bounded control topology was truncated, so the control graph is incomplete'); return; }
        // A control's own field-description candidates that could not be
        // safely attributed to it (see `FormControlMetadataBuilder`) are an
        // internal marker, not published schema: collect them for a
        // diagnostic and strip them before the control reaches the entity.
        $rawControls = array_values(array_filter($fallback['controls'], 'is_array'));
        $unrepresentedText = array();
        foreach ( $rawControls as $index => $rawControl ) {
            foreach ( ArrayField::array($rawControl, '_unresolved_description_candidates') as $text ) {
                if ( is_string($text) && '' !== trim($text) ) $unrepresentedText[] = array('selector' => is_string($rawControl['selector'] ?? null) ? $rawControl['selector'] : $selector, 'text' => $text);
            }
            unset($rawControls[$index]['_unresolved_description_candidates']);
        }
        // Context with no safe placement (for example an unassociated
        // label heading several fields) stays named and loud. Positioned
        // prose and resolved labels never enter this loss collection.
        foreach ( is_array($fallback['form']['unrepresented_context'] ?? null) ? $fallback['form']['unrepresented_context'] : array() as $item ) {
            if ( is_array($item) && is_string($item['text'] ?? null) && '' !== trim($item['text']) ) $unrepresentedText[] = array('selector' => $selector, 'text' => $item['text']);
        }
        $form = array('selector' => $selector, 'source_path' => $sourcePath, 'form' => ArrayField::array($fallback, 'form'), 'controls' => $rawControls);
        if ( array() !== $choiceGroups ) $form['choice_groups'] = $choiceGroups;
        foreach (array('fallback_identity', 'reconciliation_identity') as $identityKey) if (is_string($fallback[$identityKey] ?? null) && preg_match('/^[a-f0-9]{64}$/', $fallback[$identityKey])) $form[$identityKey] = $fallback[$identityKey];
        // An order-lookup form (order number + the email used at checkout
        // + a submit) is a shop surface: a form provider can never answer
        // it, while a shop provider owns order tracking. Recognized forms
        // are pulled out of the detected-forms path here — the path that
        // would otherwise declare them in `forms` — and declared beside
        // the other shop collections below, reusing the finding's
        // finalized form anchor under the `order_lookup` role. The
        // `html_form_fallback` finding itself keeps carrying the
        // extracted controls with its fallback identity, so a consumer
        // can reconcile the form against its materialization. A form
        // without a page-owned anchor stays on the generic path, which
        // declines it loudly instead of silently.
        $orderLookupBinding = is_array($fallback['binding'] ?? null) && 'generic/block-binding/v1' === ($fallback['binding']['schema'] ?? null) && is_string($fallback['binding']['search_block_markup'] ?? null) && '' !== trim($fallback['binding']['search_block_markup']) ? $fallback['binding'] : null;
        $orderLookup = array() === $choiceGroups ? OrderLookupFormRecognizer::recognize($rawControls) : null;
        if ( null !== $orderLookup && null !== $orderLookupBinding ) {
            // The entity carries the same source form evidence and fallback
            // identity a `forms` entity would, so a consumer reconciles
            // it against the `html_form_fallback` finding unchanged.
            $this->orderLookups[$sourcePath . "\n" . $selector] = array_filter($form + array(
                'fields' => $orderLookup['fields'],
                'submit_label' => '' !== $orderLookup['submit_label'] ? $orderLookup['submit_label'] : null,
                'bindings' => array(array_merge($orderLookupBinding, array('role' => 'order_lookup', 'source_path' => $sourcePath))),
            ), static fn (mixed $value): bool => null !== $value && array() !== $value);
            return;
        }
        // The control topology is the provider's map of where each flat
        // control sits, and consumers enforce the same producer contract
        // against this entity's control list. A topology that fails it
        // would make the whole forms declaration unusable downstream, so
        // the form is declined here and named, leaving the converted
        // source form on the page.
        if ( is_array($fallback['control_topology'] ?? null) ) {
            try {
                FormControlTopologyBuilder::assertValid($fallback['control_topology'], count($rawControls));
            } catch ( \InvalidArgumentException $error ) {
                if ( $declarable ) $this->diagnostics[] = $this->declinedFormDeclarationDiagnostic($fallback, $sourcePath, $selector, 'control_topology_invalid', 'its control topology violates the producer contract (' . $error->getMessage() . ')');
                return;
            }
            $form['control_topology'] = $fallback['control_topology'];
        }
        if ( is_array($fallback['sibling_relations'] ?? null) && true !== ($fallback['sibling_relations']['truncated'] ?? false) ) {
            try {
                FormControlTopologyBuilder::assertSiblingRelations($fallback['sibling_relations'], count($rawControls));
                $form['sibling_relations'] = $fallback['sibling_relations'];
            } catch ( \InvalidArgumentException $error ) {
                if ( $declarable ) $this->diagnostics[] = $this->rejectedFormGraphDiagnostic($fallback, $sourcePath, $selector, 'sibling_relations', $error->getMessage());
            }
        }
        // `truncated` still means the builder's own budget or scan
        // limits could be hiding facts anywhere (see
        // FormLayoutGraphBuilder::MAX_NODES/MAX_RULES and friends),
        // so a graph flagged for one of those reasons is correctly
        // excluded here rather than risk an incomplete picture. A
        // structurally invalid graph is a distinct, genuine
        // rejection: instead of letting that corruption crash the
        // whole compile (or silently vanishing), it is named.
        if ( is_array($fallback['layout_graph'] ?? null) && true !== ($fallback['layout_graph']['truncated'] ?? false) ) {
            try {
                FormLayoutGraphBuilder::assertValid($fallback['layout_graph']);
                $form['layout_graph'] = $fallback['layout_graph'];
            } catch ( \InvalidArgumentException $error ) {
                if ( $declarable ) $this->diagnostics[] = $this->rejectedFormGraphDiagnostic($fallback, $sourcePath, $selector, 'layout_graph', $error->getMessage());
            }
        }
        // A step track names layout graph nodes, so it travels only with
        // the graph it was read from.
        if ( isset($form['layout_graph']) && is_array($fallback['steps'] ?? null) ) {
            try {
                FormStepTrackDetector::assertValid($fallback['steps'], $form['layout_graph']);
                $form['steps'] = $fallback['steps'];
            } catch ( \InvalidArgumentException $error ) {
                if ( $declarable ) $this->diagnostics[] = $this->rejectedFormGraphDiagnostic($fallback, $sourcePath, $selector, 'steps', $error->getMessage());
            }
        }
        // A truncated graph is withheld whole; name why so the form's provider
        // reports the presentation or layout it could not reproduce.
        $losses = array();
        foreach (array('layout_graph', 'presentation_graph') as $graph) {
            if (true === ($fallback[$graph]['truncated'] ?? false)) {
                array_push($losses, ...array_filter($fallback[$graph]['diagnostics'] ?? array(), 'is_string'));
            }
        }
        if (array() !== $losses) {
            $form['source_contract_losses'] = array_slice(array_values(array_unique($losses)), 0, 32);
        }
        if ( is_array($fallback['presentation_graph'] ?? null) && true !== ($fallback['presentation_graph']['truncated'] ?? false) ) {
            try {
                FormPresentationGraphBuilder::assertValid($fallback['presentation_graph']);
                $form['presentation_graph'] = $fallback['presentation_graph'];
            } catch ( \InvalidArgumentException $error ) {
                if ( $declarable ) $this->diagnostics[] = $this->rejectedFormGraphDiagnostic($fallback, $sourcePath, $selector, 'presentation_graph', $error->getMessage());
            }
        }
        if ( is_array($fallback['binding'] ?? null) && 'generic/block-binding/v1' === ($fallback['binding']['schema'] ?? null) && is_string($fallback['binding']['search_block_markup'] ?? null) && '' !== trim($fallback['binding']['search_block_markup']) ) {
            $form['bindings'] = array(array_merge($fallback['binding'], array('source_path' => $sourcePath)));
        }
        if ( ! isset($form['bindings']) ) { if ( $declarable ) $this->diagnostics[] = $this->declinedFormDeclarationDiagnostic($fallback, $sourcePath, $selector, 'page_owned_binding_anchor_missing', 'it has no page-owned block binding anchor, so a provider cannot locate the converted form in the page'); return; }
        $supersededScripts = $this->supersededFormScripts($fallback, $this->files, $sourcePath);
        if ( array() !== $supersededScripts ) $form['superseded_scripts'] = $supersededScripts;
        if ( $declarable && array() !== $unrepresentedText ) $this->diagnostics[] = $this->unrepresentedFormTextDiagnostic($sourcePath, $selector, $unrepresentedText);
        $this->forms[$sourcePath . "\n" . $selector] = $form;
    }

    private function inheritCardPageProducts(): void
    {
        // Schema claims are authoritative, including ambiguous claims. Only
        // otherwise unclaimed compiled pages may inherit a unique card product.
        // Ownership includes every contributing schema page, not only the
        // chosen canonical row's source path after identity/language merging.
        foreach ($this->cardPageProducts as $path => $claims) {
            if (empty($this->pageProducts[$path]) && 1 === count($claims)) $this->pageProducts[$path] = $claims;
        }
    }

    /**
     * A page selling exactly one product hands its add-to-cart controls to
     * that product instead of a form provider.
     *
     * @param array<int,mixed> $fallbacks
     */
    private function claimPurchaseForms(array $fallbacks): void
    {
        // Card descendants retain their existing card bindings.
        $cardSelectors = array();
        foreach ( $fallbacks as $fallback ) {
            if ( 'html_product_grid_fallback' !== ($fallback['diagnostic_code'] ?? '') ) continue;
            foreach ( $fallback['products'] ?? array() as $card ) {
                if ( is_string($card['source_selector'] ?? null) ) $cardSelectors[$fallback['source'] ?? $this->entryPath][] = $card['source_selector'];
            }
        }
        foreach ( $fallbacks as $fallback ) {
            $formlessPurchase = 'html_commerce_controls_fallback' === ($fallback['diagnostic_code'] ?? '')
                && 'commerce_add_to_cart_form' === ($fallback['binding']['role'] ?? '');
            if ( 'commerce_add_to_cart' !== ($fallback['form_intent'] ?? '') && ! $formlessPurchase ) continue;
            $sourcePath = $fallback['source'] ?? $this->entryPath;
            if ( 1 !== count($this->pageProducts[$sourcePath] ?? array()) ) continue;
            $selector = $fallback['selector'] ?? '';
            foreach ( $cardSelectors[$sourcePath] ?? array() as $cardSelector ) {
                if ( $selector === $cardSelector || str_starts_with($selector, $cardSelector . ' > ') ) continue 2;
            }
            $binding = $fallback['binding'] ?? null;
            if ( ! is_array($binding) || 'generic/block-binding/v1' !== ($binding['schema'] ?? '') || '' === trim($binding['search_block_markup'] ?? '') ) continue;
            $productSlug = $this->pageProducts[$sourcePath][0];
            if (isset($this->schemaProducts[$productSlug])) $purchaseProduct =& $this->schemaProducts[$productSlug];
            else $purchaseProduct =& $this->products[$productSlug];
            $purchaseBinding = array_merge($binding, array('role' => 'commerce_add_to_cart_form', 'source_path' => $sourcePath));
            // Multiple recognizers can claim the same placement. Coalesce only
            // identical bindings; distinct page anchors remain separate placements.
            if ( ! in_array($purchaseBinding, $purchaseProduct['bindings'] ?? array(), true) ) {
                $purchaseProduct['bindings'][] = $purchaseBinding;
            }
            // Preserve the same flat control indexes and validated presentation
            // facts as a form entity when shop takes ownership of the controls.
            if ( is_array($fallback['controls'] ?? null) ) {
                $controls = array_values(array_filter($fallback['controls'], 'is_array'));
                foreach ( $controls as &$control ) unset($control['_unresolved_description_candidates']);
                unset($control);
                $purchaseProduct['controls'] = $controls;
                unset($purchaseProduct['presentation_graph']);
                if ( is_array($fallback['presentation_graph'] ?? null) && true !== ($fallback['presentation_graph']['truncated'] ?? false) ) {
                    try {
                        FormPresentationGraphBuilder::assertValid($fallback['presentation_graph']);
                        $purchaseProduct['presentation_graph'] = $fallback['presentation_graph'];
                    } catch ( \InvalidArgumentException $error ) {
                        $this->diagnostics[] = $this->rejectedFormGraphDiagnostic($fallback, $sourcePath, $selector, 'presentation_graph', $error->getMessage());
                    }
                }
            }
            if (isset($this->detailPages[$sourcePath]) && $this->detailPages[$sourcePath] === ($this->pageRoutes[$sourcePath] ?? null)) {
                ProductDetailPageEvidence::fill($purchaseProduct, $this->pageEvidence[$sourcePath] ?? array(), $fallback, $this->files, $this->pageRoutes, '.' === $this->entryRoot ? '' : $this->entryRoot, $this->diagnostics);
            }
            unset($purchaseProduct);
            // A purchase form belongs to shop, not a competing form provider.
            unset($this->forms[$sourcePath . "\n" . $selector]);
            // Different source-DOM selectors may finalize to the same canonical
            // placement. Ownership follows that page anchor, not the recognizer's
            // selector or role; repeated anchors elsewhere remain independent.
            foreach ( $this->forms as $key => $form ) {
                if ( $sourcePath !== $form['source_path'] ) continue;
                foreach ( $form['bindings'] ?? array() as $formBinding ) {
                    if ( $purchaseBinding['search_block_markup'] === ($formBinding['search_block_markup'] ?? null)
                        && ($purchaseBinding['occurrence'] ?? 1) === ($formBinding['occurrence'] ?? 1)
                        && ($purchaseBinding['position'] ?? null) === ($formBinding['position'] ?? null) ) {
                        unset($this->forms[$key]);
                        break;
                    }
                }
            }
        }
    }

    private function mergeSchemaProducts(): void
    {
        foreach ( $this->schemaProducts as $productSlug => $row ) {
            if ( isset($row['bindings']) ) usort($row['bindings'], static fn (array $a, array $b): int => strcmp($a['source_path'], $b['source_path']) ?: strcmp($a['search_block_markup'], $b['search_block_markup']));
            if (!isset($row['url'])) $row['url'] = '/' . $row['source_path'];
            $source = $row['source_path'];
            if (isset($this->detailPages[$source]) && $this->detailPages[$source] === ($this->pageRoutes[$source] ?? null)) {
                foreach ($row['bindings'] ?? array() as $binding) {
                    if ('commerce_add_to_cart_form' === ($binding['role'] ?? null) && $source === ($binding['source_path'] ?? null)) {
                        $row['whole_page_candidate'] = 'blocks-engine/whole-page-candidate/v1';
                        break;
                    }
                }
            }
            if ( ! isset($this->products[$productSlug]) ) { $this->products[$productSlug] = $row; continue; }
            foreach ( $row['bindings'] ?? array() as $binding ) $this->products[$productSlug]['bindings'][] = $binding;
            // Detail evidence owns identity and source URL; card bindings stay intact.
            foreach (array('name', 'slug', 'url') as $field) $this->products[$productSlug][$field] = $row[$field];
            if (isset($row['whole_page_candidate'])) {
                // The detail page, not an earlier listing card, owns the claim.
                $this->products[$productSlug]['source_path'] = $source;
                $this->products[$productSlug]['whole_page_candidate'] = $row['whole_page_candidate'];
            }
            // Detail-page descriptive evidence wins; cards fill only absent fields.
            foreach ( array('description', 'image', 'images', 'category', 'sku', 'variants', 'stock_status', 'translations', 'controls', 'presentation_graph') as $field ) if ( isset($row[$field]) ) $this->products[$productSlug][$field] = $row[$field];
            foreach ( array('tags', 'sale_price') as $field ) if ( ! isset($this->products[$productSlug][$field]) && isset($row[$field]) ) $this->products[$productSlug][$field] = $row[$field];
            // A detail price replaces a card's price-on-request label.
            if ( ! isset($this->products[$productSlug]['regular_price']) ) {
                $this->products[$productSlug]['regular_price'] = $row['regular_price'];
                unset($this->products[$productSlug]['price_label']);
            }
        }
    }

    private function releaseShopOwnedForms(): void
    {
        // Listing-owned forms are shop controls, not a second form-provider
        // surface. In particular a sort-only form and its select may serialize
        // to the very same block anchor. Retain mixed/data-entry forms.
        foreach ( $this->forms as $key => $form ) {
            // Every placement of a listing control claims its form, including
            // a repeated control declared once on the listing.
            $owned = $this->listingControlSelectors[$form['source_path']] ?? array();
            $unclaimed = array_filter($form['controls'] ?? array(), static function (array $control) use ($owned): bool {
                $selector = $control['selector'] ?? '';
                foreach ( $owned as $owner ) {
                    if ( $selector === $owner || str_starts_with($selector, $owner . ' > ') ) return false;
                }
                return ! in_array($control['type'] ?? '', array('submit', 'button', 'reset'), true);
            });
            if ( array() !== $owned && array() === $unclaimed ) unset($this->forms[$key]);
        }
        // A form the checkout view region owns is part of the client-side
        // checkout rendering, not an independent form-provider surface: the
        // shop provider replacing the region covers it, so it leaves `forms`
        // like the order-lookup form above instead of racing that provider for
        // the same block anchor.
        foreach ( $this->checkouts as $checkout ) {
            foreach ( $this->forms as $key => $form ) {
                if ( ($form['source_path'] ?? '') !== $checkout['source_path'] ) continue;
                $selector = (string) ($form['selector'] ?? '');
                if ( '' !== $selector && ( $selector === $checkout['selector'] || str_starts_with($selector, $checkout['selector'] . ' > ') ) ) unset($this->forms[$key]);
            }
        }
    }

    /**
     * Name the contract that stopped prepared form metadata from reaching the
     * runtime declarations. A declined form is otherwise invisible: the
     * `html_form_fallback` finding still reports extracted controls while the
     * `generic/forms/v1` entity that a provider materializes never appears.
     *
     * @param array<string,mixed> $fallback
     * @return array<string,mixed>
     */
    private function declinedFormDeclarationDiagnostic(array $fallback, string $sourcePath, string $selector, string $failedCheck, string $because): array
    {
        $controls = array_values(array_filter(ArrayField::array($fallback, 'controls'), 'is_array'));
        return array(
            'code' => 'runtime_form_declaration_declined',
            'severity' => 'warning',
            'message' => mb_strcut('Extracted form metadata was declined before the runtime form entity declaration because ' . $because . '.', 0, 256, 'UTF-8'),
            'source_path' => mb_strcut($sourcePath, 0, 256, 'UTF-8'),
            'selector' => mb_strcut($selector, 0, 256, 'UTF-8'),
            'failed_check' => $failedCheck,
            'control_count' => count($controls),
            'entity_schema' => 'generic/forms/v1',
            'reason_code' => 'runtime_form_declaration_declined',
            'pattern_family' => 'interactive_form',
            'repair_bucket' => 'materialize_form_provider',
            'suggested_repair_class' => 'materialize_form_provider',
            'source' => RuntimeDeclarationPromotion::class,
        );
    }

    /**
     * Name the contract that stopped one optional generic form graph from
     * reaching the runtime entity, without declining the form declaration
     * itself. Unlike a missing control topology or binding anchor, a
     * rejected layout or presentation graph never invalidates the rest of
     * the entity a provider still needs — dropping only the failed key,
     * loudly, keeps that entity declarable while naming what was lost.
     *
     * @param array<string,mixed> $fallback
     * @return array<string,mixed>
     */
    private function rejectedFormGraphDiagnostic(array $fallback, string $sourcePath, string $selector, string $graph, string $reason): array
    {
        $controls = array_values(array_filter(ArrayField::array($fallback, 'controls'), 'is_array'));
        return array(
            'code' => 'runtime_form_graph_rejected',
            'severity' => 'warning',
            'message' => mb_strcut('A generic form ' . $graph . ' failed its structural contract and was omitted from the runtime entity because ' . $reason, 0, 256, 'UTF-8'),
            'source_path' => mb_strcut($sourcePath, 0, 256, 'UTF-8'),
            'selector' => mb_strcut($selector, 0, 256, 'UTF-8'),
            'graph' => $graph,
            'control_count' => count($controls),
            'entity_schema' => 'generic/forms/v1',
            'reason_code' => 'runtime_form_graph_rejected',
            'pattern_family' => 'interactive_form',
            'repair_bucket' => 'materialize_form_provider',
            'suggested_repair_class' => 'materialize_form_provider',
            'source' => RuntimeDeclarationPromotion::class,
        );
    }

    /**
     * Name the contract that stopped a product grid's shared collection
     * anchor from reaching the runtime product entities it covers. A grid
     * whose cards have no cart control of their own shares one anchor — the
     * grid container's own emitted block (see `CommerceFallbackReporter`) —
     * resolved against the page's actual block tree the same way
     * blocks-engine#718/#1975 resolves a div pseudo-form's anchor. When that
     * resolution fails (the container has no block a selector can identify),
     * the grid's products are declined rather than emitting an anchor that
     * does not genuinely, uniquely identify the grid's own source region: a
     * declined grid leaves a working site with unresolved products; an
     * ambiguous anchor destroys the whole import (blocks-engine#2024/#2026).
     *
     * @param array<string,mixed> $fallback
     * @return array<string,mixed>
     */
    private function declinedProductGridBindingDiagnostic(array $fallback, string $sourcePath, string $selector): array
    {
        $productCount = is_array($fallback['products'] ?? null) ? count($fallback['products']) : 0;
        return array(
            'code' => 'runtime_product_grid_binding_declined',
            'severity' => 'warning',
            'message' => 'A product grid\'s shared collection binding anchor was declined because its container could not be resolved to one page-owned emitted block.',
            'source_path' => mb_strcut($sourcePath, 0, 256, 'UTF-8'),
            'selector' => mb_strcut($selector, 0, 256, 'UTF-8'),
            'failed_check' => 'page_owned_collection_anchor_unresolved',
            'product_count' => $productCount,
            'entity_schema' => 'generic/products/v1',
            'reason_code' => 'runtime_product_grid_binding_declined',
            'pattern_family' => 'commerce_product_grid',
            'repair_bucket' => 'materialize_shop_provider',
            'suggested_repair_class' => 'materialize_shop_provider',
            'source' => RuntimeDeclarationPromotion::class,
        );
    }

    /**
     * Name source copy that reached a declared `generic/forms/v1` entity but
     * could not be carried into it: a field's own helper text sitting beside
     * more than one candidate element in its wrapper, or copy positioned
     * between two controls with no positional anchor. The entity itself still
     * declares successfully — this names what it could not bring with it, in
     * the same taxonomy and repair bucket as a declined declaration, so the
     * loss is never silent even when the form otherwise materializes.
     *
     * @param array<int, array{selector: string, text: string}> $items
     * @return array<string,mixed>
     */
    private function unrepresentedFormTextDiagnostic(string $sourcePath, string $selector, array $items): array
    {
        $texts = array_values(array_unique(array_map(
            static fn (array $item): string => mb_strcut((string) $item['text'], 0, 200, 'UTF-8'),
            $items
        )));
        return array(
            'code' => 'form_field_context_unrepresented',
            'severity' => 'warning',
            'message' => substr('Source copy inside a materialized form could not be attached to a control or placed as form context, so it did not reach the generic/forms/v1 entity.', 0, 256),
            'source_path' => mb_strcut($sourcePath, 0, 256, 'UTF-8'),
            'selector' => mb_strcut($selector, 0, 256, 'UTF-8'),
            'unrepresented_text' => array_slice($texts, 0, 8),
            'unrepresented_count' => count($texts),
            'entity_schema' => 'generic/forms/v1',
            'reason_code' => 'form_field_context_unrepresented',
            'pattern_family' => 'interactive_form',
            'repair_bucket' => 'materialize_form_provider',
            'suggested_repair_class' => 'materialize_form_provider',
            'source' => RuntimeDeclarationPromotion::class,
        );
    }

    /** @param array<string,mixed> $fallback @param array<int,array<string,mixed>> $files @return array<int,array<string,string>> */
    private function supersededFormScripts(array $fallback, array $files, string $sourcePath): array
    {
        $files = ArtifactNormalizer::inlineOccurrences($files);
        $ownedIds = array();
        foreach ( array_merge(array($fallback['form'] ?? array()), ArrayField::array($fallback, 'controls')) as $row ) {
            if ( is_array($row) && is_string($row['id'] ?? null) && '' !== trim($row['id']) ) $ownedIds[$row['id']] = true;
        }
        if ( is_string($fallback['html'] ?? null) && preg_match_all('/\bid\s*=\s*["\']([^"\']+)["\']/i', $fallback['html'], $matches) ) foreach ( $matches[1] as $id ) $ownedIds[(string) $id] = true;
        if ( array() === $ownedIds ) return array();
        $formId = is_array($fallback['form'] ?? null) && is_string($fallback['form']['id'] ?? null) ? trim($fallback['form']['id']) : '';
        if ( '' === $formId ) return array();
        $targetSelector = '#' . $formId;

        $superseded = array();
        foreach ( $files as $file ) {
            if ( !is_array($file) || 'inline-script' !== ($file['source'] ?? null) || $sourcePath !== ($file['source_path'] ?? null) || $targetSelector !== ($file['superseded_by'] ?? null) || !is_string($file['selector'] ?? null) || !is_string($file['content'] ?? null) ) continue;
            $script = trim($file['content']);
            if ( '' === $script || preg_match('/\b(?:window|globalThis|fetch|XMLHttpRequest|WebSocket|EventSource|navigator|localStorage|sessionStorage|indexedDB|eval|import|createElement|appendChild|insertBefore)\b|document\s*\.\s*(?:cookie|location)/i', $script) ) continue;
            if ( !preg_match_all('/document\s*\.\s*getElementById\s*\(\s*["\']([^"\']+)["\']\s*\)/i', $script, $lookups) || array() !== array_diff(array_unique($lookups[1]), array_keys($ownedIds)) ) continue;
            $ownedVariables = array();
            if ( preg_match_all('/\b(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*document\s*\.\s*getElementById\s*\(\s*["\']([^"\']+)["\']\s*\)/i', $script, $assignments, PREG_SET_ORDER) ) foreach ( $assignments as $assignment ) if ( isset($ownedIds[$assignment[2]]) ) $ownedVariables[$assignment[1]] = true;
            $withoutOwnedLookups = preg_replace('/document\s*\.\s*getElementById\s*\(\s*["\'][^"\']+["\']\s*\)/i', '', $script) ?? $script;
            if ( preg_match('/\[\s*["\'][A-Za-z_$][A-Za-z0-9_$]*["\']\s*\]/', $withoutOwnedLookups) ) continue;
            if ( preg_match('/\bdocument\s*\./i', $withoutOwnedLookups) ) continue;
            if ( preg_match_all('/(?<![.\w$])([A-Za-z_$][A-Za-z0-9_$]*)\s*\(/', $withoutOwnedLookups, $calls) && array_diff(array_unique($calls[1]), array('if', 'for', 'while', 'switch', 'catch', 'function')) ) continue;
            if ( preg_match_all('/\.\s*([A-Za-z_$][A-Za-z0-9_$]*)\s*\(/', $withoutOwnedLookups, $memberCalls) && array_diff(array_unique($memberCalls[1]), array('addEventListener', 'preventDefault', 'querySelector', 'querySelectorAll', 'trim', 'forEach')) ) continue;
            if ( preg_match('/\.\s*(?:parentElement|parentNode|ownerDocument|children|firstElementChild|lastElementChild|nextElementSibling|previousElementSibling)\b/i', $withoutOwnedLookups) ) continue;
            if ( preg_match_all('/([A-Za-z_$][A-Za-z0-9_$]*(?:\s*\.\s*[A-Za-z_$][A-Za-z0-9_$]*)*)\s*\.\s*querySelector(?:All)?\s*\(/', $withoutOwnedLookups, $queries) && array_diff(array_map(static fn(string $receiver): string => preg_replace('/\s+/', '', $receiver) ?? $receiver, array_unique($queries[1])), array_keys($ownedVariables)) ) continue;
            if ( preg_match_all('/\.\s*([A-Za-z_$][A-Za-z0-9_$]*(?:\s*\.\s*[A-Za-z_$][A-Za-z0-9_$]*)?)\s*=/', $withoutOwnedLookups, $writes) && array_diff(array_map(static fn(string $property): string => preg_replace('/\s+/', '', $property) ?? $property, array_unique($writes[1])), array('textContent', 'style.background', 'style.borderColor', 'style.display', 'value')) ) continue;
            $superseded[] = array('schema' => 'blocks-engine/provider-script-supersession/v1', 'source_path' => $sourcePath, 'selector' => $file['selector'], 'asset_source_path' => $file['path'], 'body_hash' => hash('sha256', $script), 'target_selector' => $targetSelector, 'reason' => 'provider_binding_replaces_form_behavior');
        }
        usort($superseded, static fn(array $left, array $right): int => strcmp($left['body_hash'], $right['body_hash']));
        return $superseded;
    }
}

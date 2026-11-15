<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use Automattic\BlocksEngine\PhpTransformer\Support\ShellLandmarkPolicy;
use DOMElement;
use SplObjectStorage;

/**
 * Generic commerce-structure recognition: schema.org Product/Offer evidence,
 * currency-formatted prices, and the name + price + cart-control triad.
 *
 * Detection reads structural/semantic signals and schema.org vocabulary — never
 * a shop-provider name, fixture name, or specific class string.
 */
final class CommerceStructureRecognizer
{
    /** Commerce words grouped by their recognition role, shared by controls and identity checks. */
    private const COMMERCE_WORDS = array(
        'cart' => array( 'cart', 'carrito', 'cesta', 'carrinho', 'cesto', 'panier', 'warenkorb', 'carrello', 'winkelwagen', 'winkelmand' ),
        'container' => array( 'bag', 'basket' ),
        'add' => array( 'add', 'agregar', 'añadir', 'adicionar', 'adiciona', 'ajouter', 'ajoutez', 'hinzufügen', 'aggiungi', 'aggiungere', 'toevoegen' ),
        'buy' => array( 'buy', 'purchase', 'comprar', 'compra', 'compre', 'acheter', 'achetez', 'kaufen', 'kaufe', 'acquista', 'acquistare', 'kopen', 'koop' ),
        'checkout' => array( 'checkout' ),
        'order' => array( 'order' ),
        'compact' => array( 'addtocart', 'add-to-cart' ),
        'close' => array( 'close', 'dismiss', 'cerrar', 'fechar', 'fermer', 'schließen', 'chiudi', 'sluiten', '×', '✕' ),
    );

    /** @var SplObjectStorage<DOMElement, array<string, mixed>|null> Retains wrappers for stable native identity during this compilation. */
    private SplObjectStorage $singleProductCards;

    public function __construct(
        private readonly SourceElementClassifier $classifier = new SourceElementClassifier(),
        /** @var \Closure(DOMElement): ?array|null */
        private readonly ?\Closure $svgImageReference = null
    ) {
        $this->singleProductCards = new SplObjectStorage();
    }

    public function looksLikePriceText(string $text): bool
    {
        return (bool) preg_match('/(?:\p{Sc}[\s\x{a0}]?\d|\d[\d\s\x{a0}.,]*[\s\x{a0}]?\p{Sc}|\d+(?:[.,]\d{2})?[\s\x{a0}]?(?:usd|eur|gbp|cad|aud)\b)/iu', self::withoutAggregateAmounts(trim($text)));
    }

    /**
     * Remove aggregate figures such as "$370M+ total sales" or "$2.5 billion":
     * a magnitude suffix makes the amount a statistic, not a price a shopper pays.
     */
    private static function withoutAggregateAmounts(string $text): string
    {
        return preg_replace('/\p{Sc}[\s\x{a0}]?\d[\d.,]*(?:[\s\x{a0}]?(?:k|m|mm|b|bn|thousand|million|billion|trillion)\b\+?)/iu', ' ', $text) ?? $text;
    }

    /**
     * Whether an element is a plausible product-grid container: a list (ul/ol) or
     * an element the structure classifier already flags as grid_like.
     */
    public function isProductGridContainer(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( in_array($tagName, array( 'ul', 'ol' ), true) ) {
            return true;
        }

        return $this->isGridLike($element);
    }

    public function isPriceElement(DOMElement $element): bool
    {
        return $this->classifier->hasCommerceToken($element, array( 'price', 'amount', 'cost' )) || $this->looksLikePriceText($element->textContent ?? '');
    }

    /**
     * Extract the qualifying product cards directly under a grid container. A card
     * is a direct child element that declares schema.org Product/Offer structure or
     * carries the full structural commerce triad (name + price + cart control).
     *
     * @return array<int, array<string, mixed>>
     */
    public function productCardsForContainer(DOMElement $container): array
    {
        $products = array();
        foreach ( $container->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $product = $this->productCardData($child);
            if ( null !== $product ) {
                $products[] = $product;
            }
        }

        return $products;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function commerceControlGroupsForContainer(DOMElement $container): array
    {
        $groups = array();
        foreach ( $container->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $product = $this->productCardData($child);
            if ( null === $product || empty($product['has_cart_control']) ) {
                continue;
            }

            $hasQuantity = $this->hasQuantityControl($child);
            $groups[] = array_filter(array(
                'product_name'         => $product['name'] ?? '',
                'source_selector'      => SourceDom::elementSelector($child),
                'has_quantity_control' => $hasQuantity,
                'has_cart_control'     => true,
                'runtime_requirement'  => 'commerce_cart_runtime',
            ), static fn (mixed $value): bool => null !== $value && '' !== $value);
        }

        return $groups;
    }

    /**
     * Build the per-card product payload when a card qualifies, else null.
     *
     * A card qualifies when it declares schema.org Product/Offer structure
     * (microdata `itemtype` Product or both `itemprop` name and price), OR carries
     * the structural triad: a name, a currency-formatted price, and an
     * add-to-cart/buy control, OR carries the structural quad: a name, a
     * one-time (non-recurring) currency-formatted price, and a per-card
     * photographic image — the pattern a browse/marketplace grid uses when the
     * purchase action lives on a detail page rather than the grid card itself,
     * so no cart-control token is present in the card's own markup. The image
     * is required for this third path so a bare pricing-tier/plan card (see
     * `isRecurringPriceCard()`) is never treated as a product merely for
     * pairing a name with a price. This image path requires currency-formatted
     * text; a price class with a bare numeric amount is not enough evidence.
     *
     * @return array<string, mixed>|null
     */
    public function productCardData(DOMElement $card): ?array
    {
        if ( $this->containsMultipleProducts($card) ) {
            return null;
        }

        return $this->singleProductCardData($card);
    }

    /** Reject containers before their first heading can become a product name. */
    private function containsMultipleProducts(DOMElement $card): bool
    {
        $urls = array();
        foreach ( $card->getElementsByTagName('a') as $link ) {
            $path = parse_url(trim(SourceDom::attr($link, 'href')), PHP_URL_PATH);
            if ( is_string($path) && preg_match('~/(?:products?|product-page)/[^/]+~i', $path) ) {
                $urls[rtrim($path, '/')] = true;
            }
        }
        if ( count($urls) > 1 ) {
            return true;
        }

        // Walk down to independent qualifying cards, not their nested wrappers.
        $count = 0;
        $visit = function (DOMElement $node) use (&$visit, &$count): void {
            $before = $count;
            foreach ( $node->childNodes as $child ) {
                if ( $child instanceof DOMElement ) {
                    $visit($child);
                    if ( $count > 1 ) {
                        return;
                    }
                }
            }
            if ( $count === $before && null !== $this->singleProductCardData($node) ) {
                ++$count;
            }
        };
        foreach ( $card->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                $visit($child);
                if ( $count > 1 ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function singleProductCardData(DOMElement $card): ?array
    {
        if ( isset($this->singleProductCards[$card]) ) {
            return $this->singleProductCards[$card];
        }
        return $this->singleProductCards[$card] = $this->recognizeSingleProductCard($card);
    }

    private function recognizeSingleProductCard(DOMElement $card): ?array
    {
        $name = $this->productNameText($card);
        $prices = $this->productPriceTexts($card);
        $hasCart = $this->hasCartControl($card);
        $isSchemaProduct = $this->isSchemaProductCard($card);
        $image = $this->productImage($card);

        if ( '' === $name || array() === $prices || $this->isCartSummary($card) ) {
            return null;
        }

        // A price class also admits bare numeric amounts (e.g. menu prices).
        // Only currency-formatted text corroborates the weaker image path;
        // declared amounts remain valid with schema.org or purchase controls.
        $isCatalogImageCard = null !== $image
            && $this->looksLikePriceText($prices['price'])
            && ! $this->isRecurringPriceCard($card);
        if ( ! $isSchemaProduct && ! $hasCart && ! $isCatalogImageCard ) {
            return null;
        }

        return array_filter(array(
            'name'             => $name,
            'slug'             => $this->productLinkSlug($card, $name),
            'url'              => $this->productLinkUrl($card, $name),
            'price'            => $prices['price'],
            'sale_price'       => $prices['sale_price'] ?? null,
            'description'      => $this->productDescriptionText($card, $name),
            'image'            => $image,
            'category'         => $this->productCategoryText($card),
            'has_cart_control' => $hasCart,
            'source_selector'  => SourceDom::elementSelector($card),
        ), static fn (mixed $value, string $key): bool => in_array($key, array( 'sale_price', 'description', 'image' ), true) || ( null !== $value && '' !== $value ), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Whether the card declares schema.org Product/Offer structure via microdata.
     *
     * Public so a container-level aggregator (`CommerceFallbackReporter`) can
     * distinguish an authoritative qualification (schema.org or an explicit
     * cart control) from the weaker image-corroborated qualification in
     * `productCardData()` and require more repetition before trusting the
     * weaker signal alone.
     */
    public function isSchemaProductCard(DOMElement $card): bool
    {
        $itemtype = strtolower(SourceDom::attr($card, 'itemtype'));
        if ( str_contains($itemtype, 'schema.org/product') ) {
            return true;
        }

        $hasName = null !== $this->firstDescendantWithItemprop($card, array( 'name' ));
        $hasPrice = null !== $this->firstDescendantWithItemprop($card, array( 'price' ));
        if ( $hasName && $hasPrice ) {
            return true;
        }

        foreach ( $card->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement && str_contains(strtolower(SourceDom::attr($descendant, 'itemtype')), 'schema.org/offer') ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the card reads as recurring/subscription (e.g. "$9/mo", "$29 per
     * month", "Billed monthly") rather than a one-time purchase. Scans the
     * card's whole text rather than only its price element so a billing-cadence
     * label placed next to (rather than inside) the price element still counts.
     * A recurring price disqualifies the image-corroborated catalog path so a
     * subscription/plan card that happens to carry a decorative image is still
     * never treated as a product; an occasional unrelated product description
     * that mentions e.g. "restocked monthly" only forfeits this weakest
     * qualifying path; schema.org or an explicit cart control still qualify it.
     */
    private function isRecurringPriceCard(DOMElement $card): bool
    {
        $text = $this->spacedLeafText($card);

        return '' !== $text && (bool) preg_match('/\b(?:per|\/)\s*(?:mo|month|months|yr|year|years|wk|week|weeks|day|days)\b|\b(?:monthly|yearly|annually|billed|subscription|recurring)\b/i', $text);
    }

    /**
     * The card's text with each leaf element's text joined by a space, unlike
     * raw `textContent` concatenation which abuts adjacent inline elements
     * with no separator (e.g. "$29/mo" immediately followed by "Get started"
     * becomes "...moGet..." and silently breaks a `\b` word-boundary match).
     */
    private function spacedLeafText(DOMElement $card): string
    {
        $parts = array();
        foreach ( $card->getElementsByTagName('*') as $descendant ) {
            if ( ! $descendant instanceof DOMElement || SourceDom::childElementCount($descendant) > 0 ) {
                continue;
            }
            $text = $this->collapsedText($descendant);
            if ( '' !== $text ) {
                $parts[] = $text;
            }
        }

        return implode(' ', $parts);
    }

    /** Purchase evidence for a store, including navigation to its cart or checkout. */
    public function hasPurchaseAffordance(DOMElement $root): bool
    {
        if ( null !== $this->cartControlElement($root, true) || array() !== $this->checkoutRegions($root) ) {
            return true;
        }
        foreach ( $root->getElementsByTagName('form') as $form ) {
            if ( 'commerce_add_to_cart' === \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormRuntimeRequirementAnalyzer::formIntent($form) ) {
                return true;
            }
        }
        return false;
    }

    /** Whether any source document supplies purchase evidence (schema alone does not). */
    public static function sourceHasPurchaseAffordance(array $files): bool
    {
        $recognizer = new self();
        foreach ( $files as $file ) {
            if ( ! is_array($file) || ! is_string($file['content'] ?? null) || ! preg_match('/\.html?$/i', (string) ($file['path'] ?? '')) ) {
                continue;
            }
            $document = HtmlSerialization::parseDocument($file['content']);
            if ( $document->documentElement instanceof DOMElement && $recognizer->hasPurchaseAffordance($document->documentElement) ) {
                return true;
            }
        }
        return false;
    }

    public function cartControlElement(DOMElement $card, bool $includeNavigation = false): ?DOMElement
    {
        foreach ( $card->getElementsByTagName('*') as $descendant ) {
            if ( ! $descendant instanceof DOMElement ) {
                continue;
            }

            $tagName = strtolower($descendant->tagName);
            $role = strtolower(SourceDom::attr($descendant, 'role'));
            $isControl = in_array($tagName, array( 'button', 'a', 'input' ), true) || 'button' === $role;
            // Only action inputs are controls; other inputs carry data.
            if ( ! $isControl || ('input' === $tagName && ! in_array(strtolower(SourceDom::attr($descendant, 'type')), array( 'submit', 'button', 'image' ), true)) ) {
                continue;
            }
            if ( ! $includeNavigation && $this->isInCardNavigation($descendant, $card) ) {
                continue;
            }

            // Whole-card prose and identity attributes are not action labels.
            if ( $includeNavigation && 'a' === $tagName && $this->navigatesAway(SourceDom::attr($descendant, 'href')) ) {
                if ( $this->isPurchaseNavigation($descendant) ) {
                    return $descendant;
                }
                continue;
            }

            $haystack = strtolower(implode(' ', array(
                SourceDom::attr($descendant, 'class'),
                SourceDom::attr($descendant, 'id'),
                SourceDom::attr($descendant, 'name'),
                SourceDom::attr($descendant, 'aria-label'),
                SourceDom::attr($descendant, 'value'),
                $includeNavigation ? SourceDom::attr($descendant, 'href') : '',
                implode(' ', SourceDom::safeDataAttributes($descendant)),
                $this->collapsedText($descendant),
            )));

            // A link that navigates to a cart or checkout page ("view cart") is
            // navigation unless it also names a purchase action.
            $navigates = 'a' === $tagName && 'button' !== $role && $this->navigatesAway(SourceDom::attr($descendant, 'href'));
            $hasCart = self::hasCommerceWord($haystack, array( 'cart' ));
            $hasCheckout = self::hasCommerceWord($haystack, array( 'checkout' ));
            $hasAdd = self::hasCommerceWord($haystack, array( 'add' ));
            $hasBuy = self::hasCommerceWord($haystack, array( 'buy' ));
            if ( $includeNavigation && $this->isInCardNavigation($descendant, $card) && ! $hasCart && ! $hasCheckout ) {
                continue;
            }
            // Cart/checkout/order labels alone are navigation on links that leave
            // the page. An explicit add or buy action retains the English rule.
            if ( ( $includeNavigation || ! $navigates || $hasAdd || $hasBuy )
                && ( $hasCart || $hasCheckout || self::hasCommerceWord($haystack, array( 'order' )) ) ) {
                return $descendant;
            }
            if ( $hasBuy || self::hasCommerceWord($haystack, array( 'compact' )) ) {
                return $descendant;
            }

            // An add verb alone is ambiguous: require a cart/bag/basket noun.
            if ( $hasAdd && self::hasCommerceWord($haystack, array( 'cart', 'container' )) ) {
                return $descendant;
            }
        }

        return null;
    }

    /** A document link needs an action label or an explicit cart/checkout target. */
    private function isPurchaseNavigation(DOMElement $link): bool
    {
        $path = (string) parse_url(SourceDom::attr($link, 'href'), PHP_URL_PATH);
        if ( self::hasCommerceWord(rawurldecode($path), array( 'cart', 'checkout', 'compact' )) ) {
            return true;
        }

        $labels = array(SourceDom::attr($link, 'aria-label'), SourceDom::attr($link, 'value'));
        // Inline wrappers may decorate a label; headings and paragraphs describe
        // a linked document rather than the action of following the link.
        $hasProse = false;
        foreach ( $link->getElementsByTagName('*') as $child ) {
            if ( in_array(strtolower($child->tagName), array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p' ), true) ) {
                $hasProse = true;
                break;
            }
        }
        if ( ! $hasProse ) {
            $labels[] = $this->collapsedText($link);
        }
        foreach ( $labels as $label ) {
            $label = trim($label);
            if ( '' === $label || mb_strlen($label) > 80 ) {
                continue;
            }
            if ( self::hasCommerceWord($label, array( 'buy', 'checkout', 'compact' ))
                || ( self::hasCommerceWord($label, array( 'add' )) && self::hasCommerceWord($label, array( 'cart', 'container' )) )
                || 1 === preg_match('/^(?:order\b|place\s+(?:your\s+|an?\s+)?order\b)/i', $label) ) {
                return true;
            }
        }
        return false;
    }

    /** A cart navigation control, not a purchase action or a product-card control. */
    public function isCartOpener(DOMElement $element): bool
    {
        if ( ! in_array(strtolower($element->tagName), array('button', 'a'), true) || SourceDom::hasNonAuthoredAncestor($element) ) {
            return false;
        }
        $identity = implode(' ', array(
            SourceDom::authorIdentityAttribute($element, 'class'),
            SourceDom::authorIdentityAttribute($element, 'id'),
            $element->getAttribute('name'),
            $element->getAttribute('aria-label'),
            $element->getAttribute('title'),
            $this->collapsedText($element),
        ));
        foreach ( SourceDom::tokens($element->getAttribute('aria-labelledby')) as $id ) {
            $label = $element->ownerDocument?->getElementById($id);
            if ( $label instanceof DOMElement ) $identity .= ' ' . $this->collapsedText($label);
        }
        foreach ( $element->attributes as $attribute ) {
            if ( str_starts_with($attribute->name, 'data-') ) {
                $identity .= ' ' . $attribute->name . ' ' . $attribute->value;
            }
        }
        if ( ! self::hasCommerceWord($identity, array('cart')) || self::hasCommerceWord($identity, array('add', 'buy', 'compact', 'close')) ) {
            return false;
        }
        // Only actual card candidates count: a page wrapper can also contain
        // a name, price and purchase action belonging to a descendant grid.
        for ( $node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( 'dialog' === strtolower($node->tagName) && ( $this->hasCommerceIdentity($node, array('cart')) || self::hasCommerceWord($node->getAttribute('aria-label'), array('cart')) || $this->isCartSummary($node) ) ) {
                return false;
            }
            if ( $node->parentNode instanceof DOMElement && $this->isProductGridContainer($node->parentNode) && null !== $this->productCardData($node) ) {
                return false;
            }
        }
        return true;
    }

    /** @param array<int, string> $groups */
    private static function hasCommerceWord(string $text, array $groups): bool
    {
        $words = array_merge(...array_map(static fn (string $group): array => self::COMMERCE_WORDS[$group], $groups));
        return 1 === preg_match('/(?<![\p{L}\p{N}])(?:' . implode('|', array_map(static fn (string $word): string => preg_quote($word, '/'), $words)) . ')(?![\p{L}\p{N}])/iu', $text);
    }

    /** Identity attributes only: do not mistake product prose for a control. */
    private function hasCommerceIdentity(DOMElement $element, array $groups): bool
    {
        foreach ( array( 'class', 'id', 'itemprop' ) as $attribute ) {
            if ( self::hasCommerceWord(SourceDom::authorIdentityAttribute($element, $attribute), $groups) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * A control inside a navigation landmark within the card is site navigation
     * (a real-estate "Buy" menu item, say), not a purchase action on the card.
     */
    private function isInCardNavigation(DOMElement $control, DOMElement $card): bool
    {
        for ( $node = $control->parentNode; $node instanceof DOMElement && $node !== $card; $node = $node->parentNode ) {
            if ( 'nav' === strtolower($node->tagName) || 'navigation' === strtolower(SourceDom::attr($node, 'role')) ) {
                return true;
            }
        }

        return false;
    }

    /** Whether an href leaves the current document (not empty, a fragment or script). */
    private function navigatesAway(string $href): bool
    {
        $href = strtolower(trim($href));
        return '' !== $href && ! str_starts_with($href, '#') && ! str_starts_with($href, 'javascript:');
    }

    private function isGridLike(DOMElement $element): bool
    {
        if ( in_array(strtolower($element->tagName), array( 'html', 'body' ), true) ) {
            return false;
        }
        // A document wrapper around a real grid is not itself another grid.
        if ( $element->parentNode instanceof DOMElement && 'body' === strtolower($element->parentNode->tagName) ) {
            foreach ( $element->getElementsByTagName('*') as $descendant ) {
                if ( $this->isProductGridContainer($descendant) && $this->containsMultipleProducts($descendant) ) {
                    return false;
                }
            }
        }

        $className = strtolower(trim(SourceDom::attr($element, 'class')));
        $declarations = SourceDeclarations::inline($element);

        return (bool) preg_match('/(?:^|[\s_-])(?:cards|features|services|providers|testimonials|resources|posts|projects|stats|badges|grid|grid-[0-9]+|tiles|columns|collection|gallery)(?:$|[\s_-])/', $className)
            || 'grid' === CssValueInspector::declared($declarations, 'display')
            || isset($declarations['grid-template-columns']);
    }

    /**
     * The card's title link supplies its authored URL identity when available.
     * A link to a directory index names its directory ("/products/blue-shirt/index.html"
     * → "blue-shirt"), a page file names itself without its extension
     * ("/products/blue-shirt.html" → "blue-shirt"), and a bare directory names
     * itself ("/products/blue-shirt/" → "blue-shirt"). A path that reduces to
     * nothing ("/index.html" at the root) is skipped like an empty path so the
     * next candidate link can still supply the slug.
     */
    private function productLinkSlug(DOMElement $card, string $name): ?string
    {
        $url = $this->productLinkUrl($card, $name);
        if (null === $url) return null;
        $path = parse_url($url, PHP_URL_PATH);
        $directory = rtrim((string) preg_replace('/(?:^|\/)index\.(?:html?|php)$/i', '', rtrim((string) $path, '/')), '/');
        return rawurldecode((string) preg_replace('/\.(?:html?|php)$/i', '', basename($directory)));
    }

    private function productLinkUrl(DOMElement $card, string $name): ?string
    {
        $links = $card->getElementsByTagName('a');
        foreach ( $links as $link ) {
            if ( 1 !== $links->length && $this->collapsedText($link) !== $name && SourceDom::attr($link, 'title') !== $name && ! $this->linkContainsProductName($link, $name) ) {
                continue;
            }
            $href = trim(SourceDom::attr($link, 'href'));
            if ( '' === $href || preg_match('/^(?:#|javascript:|mailto:|tel:)/i', $href) ) {
                continue;
            }
            $path = parse_url($href, PHP_URL_PATH);
            if ( ! is_string($path) || '' === trim($path, '/') ) {
                continue;
            }
            $directory = rtrim((string) preg_replace('/(?:^|\/)index\.(?:html?|php)$/i', '', rtrim($path, '/')), '/');
            $base = basename($directory);
            if ( '' === $base ) {
                continue;
            }
            $base = (string) preg_replace('/\.(?:html?|php)$/i', '', $base);
            if ( '' === $base ) {
                continue;
            }

            return $href;
        }

        return null;
    }

    /**
     * Prefer schema.org name, then a heading, then the most specific name/title
     * carrier. Candidates containing prices, badges or controls are not titles.
     */
    private function productNameText(DOMElement $card): string
    {
        $schemaName = $this->firstDescendantWithItemprop($card, array( 'name' ));
        if ( null !== $schemaName && $this->isProductNameElement($schemaName) ) {
            $text = $this->collapsedText($schemaName);
            if ( '' !== $text ) {
                return $text;
            }
        }

        foreach ( $card->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement && preg_match('/^h[1-6]$/', strtolower($descendant->tagName)) && $this->isProductNameElement($descendant) ) {
                $text = $this->collapsedText($descendant);
                if ( '' !== $text ) {
                    return $text;
                }
            }
        }

        $candidates = array();
        foreach ( $card->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement && $this->classifier->hasCommerceToken($descendant, array( 'name', 'title' )) && $this->isProductNameElement($descendant) ) {
                $candidates[] = $descendant;
            }
        }
        foreach ( $candidates as $candidate ) {
            foreach ( $candidates as $other ) {
                if ( $candidate !== $other && in_array($other, iterator_to_array($candidate->getElementsByTagName('*')), true) ) {
                    continue 2;
                }
            }
            return $this->collapsedText($candidate);
        }

        // A detail link may wrap a paragraph title alongside a price. Accept
        // only an unambiguous, standalone text carrier inside a real link;
        // arbitrary card prose is not evidence of a product name.
        $linkedNames = array();
        foreach ( $card->getElementsByTagName('a') as $link ) {
            $href = trim(SourceDom::attr($link, 'href'));
            if ( '' === $href || preg_match('/^(?:#|javascript:|mailto:|tel:)/i', $href) ) {
                continue;
            }
            foreach ( $link->getElementsByTagName('p') as $paragraph ) {
                if ( $this->isProductNameElement($paragraph) ) {
                    $linkedNames[$this->collapsedText($paragraph)] = true;
                }
            }
        }
        return 1 === count($linkedNames) ? (string) array_key_first($linkedNames) : '';
    }

    private function linkContainsProductName(DOMElement $link, string $name): bool
    {
        foreach ( $link->getElementsByTagName('*') as $descendant ) {
            if ( $this->collapsedText($descendant) === $name && $this->isProductNameElement($descendant) ) {
                return true;
            }
        }
        return false;
    }

    private function isProductNameElement(DOMElement $element): bool
    {
        if ( '' === $this->collapsedText($element) ) {
            return false;
        }
        foreach ( SourceDom::subtreeElements($element) as $node ) {
            if ( $this->isPriceElement($node)
                || $this->classifier->hasCommerceToken($node, array( 'badge', 'badges', 'quickview', 'quick', 'controls' ))
                || $this->hasCommerceIdentity($node, array( 'cart', 'buy' ))
                || in_array(strtolower($node->tagName), array( 'button', 'input', 'select', 'textarea' ), true)
                || 'button' === strtolower(SourceDom::attr($node, 'role')) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Currency-formatted price text for the card, returning the regular price and
     * an optional sale price. schema.org `itemprop="price"` is preferred; otherwise
     * elements whose text is currency-formatted or carry a price token are used.
     * A sale/special/reduced token marks the sale price. The ambiguous
     * discount/now tokens also name qualified alternative prices (e.g. a
     * payment-method discount), so they mark a sale only beside a visible,
     * nonzero prior price.
     *
     * @return array{price: string, sale_price?: string}
     */
    private function hasPriceDescendant(DOMElement $element): bool
    {
        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement && '' !== $this->collapsedText($descendant) && $this->isPriceElement($descendant) ) {
                return true;
            }
        }

        return false;
    }

    /** Token-based sales require an actual, visible prior amount in the card. */
    private function hasPriorPrice(DOMElement $card): bool
    {
        foreach ( SourceDom::subtreeElements($card) as $candidate ) {
            if ( ! $this->isPriceElement($candidate) || $this->hasPriceDescendant($candidate) ) {
                continue;
            }
            $amount = $this->currencyFormattedText($this->collapsedText($candidate), $candidate);
            if ( ! preg_match('/[1-9]/', $amount) ) {
                continue;
            }
            $prior = false;
            $hidden = false;
            for ( $node = $candidate; $node instanceof DOMElement; $node = $node->parentNode ) {
                $style = SourceDom::attr($node, 'style');
                if ( $node->hasAttribute('hidden') || preg_match('/(?:display\s*:\s*none|visibility\s*:\s*hidden)/i', $style) ) {
                    $hidden = true;
                }
                if ( in_array(strtolower($node->tagName), array( 'del', 's' ), true)
                    || $this->classifier->hasCommerceToken($node, array( 'compare', 'was', 'old', 'original' ))
                    || preg_match('/text-decoration(?:-line)?\s*:[^;]*\bline-through\b/i', $style)
                ) {
                    $prior = true;
                }
                if ( $node === $card ) {
                    break;
                }
            }
            if ( $prior && ! $hidden ) {
                return true;
            }
        }
        return false;
    }

    private function productPriceTexts(DOMElement $card): array
    {
        $regular = '';
        $sale = '';
        $fallback = '';
        $hasPriorPrice = $this->hasPriorPrice($card);

        $schemaPrice = $this->firstDescendantWithItemprop($card, array( 'price' ));
        if ( null !== $schemaPrice ) {
            $content = trim(SourceDom::attr($schemaPrice, 'content'));
            $regular = $this->currencyFormattedText('' !== $content ? $content : ($schemaPrice->textContent ?? ''), $schemaPrice);
        }

        $declared = array();
        foreach ( SourceDom::subtreeElements($card) as $node ) {
            if ( $this->classifier->hasCommerceToken($node, array( 'price', 'amount', 'cost' )) || 'price' === strtolower(SourceDom::attr($node, 'itemprop')) ) {
                $declared[] = $node;
            }
        }

        foreach ( $card->getElementsByTagName('*') as $descendant ) {
            if ( ! $descendant instanceof DOMElement ) {
                continue;
            }
            $scope = null;
            for ( $node = $descendant; $node instanceof DOMElement; $node = $node->parentNode ) {
                if ( in_array($node, $declared, true) ) {
                    $scope = $node;
                }
                if ( $node === $card ) {
                    break;
                }
            }
            if ( array() !== $declared && null === $scope ) {
                continue;
            }

            $text = $this->collapsedText($descendant);
            if ( '' === $text || ! $this->isPriceElement($descendant) ) {
                continue;
            }
            // Only consider leaf-ish price elements so a wrapper's concatenated
            // text does not shadow the individual regular/sale amounts. A price
            // whose only children are qualifiers rather than amounts (e.g.
            // `<small>from </small>$60.00` on a variable product) is still the
            // leaf that carries the amount.
            if ( SourceDom::childElementCount($descendant) > 0 && $this->hasPriceDescendant($descendant) ) {
                continue;
            }

            $formatted = $this->currencyFormattedText($text, $descendant);
            if ( '' === $formatted ) {
                continue;
            }

            $isSale = false;
            for ( $node = $descendant; $node instanceof DOMElement && $node !== $card; $node = $node->parentNode ) {
                $priceScope = $scope ?? $node->parentNode;
                $hasStruckPrice = false;
                if ( $priceScope instanceof DOMElement ) {
                    foreach ( array( 'del', 's' ) as $tag ) {
                        foreach ( $priceScope->getElementsByTagName($tag) as $struck ) {
                            if ( $this->looksLikePriceText($struck->textContent ?? '') || $this->hasPriceDescendant($struck) ) {
                                $hasStruckPrice = true;
                            }
                        }
                    }
                }
                if ( ('ins' === strtolower($node->tagName) && $hasStruckPrice) || ('ins' !== strtolower($node->tagName) && ($this->classifier->hasCommerceToken($node, array( 'sale', 'special', 'reduced' )) || ($hasPriorPrice && $this->classifier->hasCommerceToken($node, array( 'discount', 'now' ))))) ) {
                    $isSale = true;
                    break;
                }
            }
            if ( $isSale ) {
                $sale = '' === $sale ? $formatted : $sale;
                continue;
            }

            if ( '' === $regular ) {
                $regular = $formatted;
            } elseif ( '' === $fallback ) {
                $fallback = $formatted;
            }
        }

        if ( '' === $regular ) {
            $regular = '' !== $fallback ? $fallback : $sale;
            $sale = '' !== $fallback ? $sale : '';
        }

        if ( '' === $regular ) {
            return array();
        }

        $result = array( 'price' => $regular );
        if ( '' !== $sale && $sale !== $regular ) {
            $result['sale_price'] = $sale;
        }

        return $result;
    }

    /**
     * Reduce raw text to its currency-formatted price token (e.g. "$24"), keeping
     * the trimmed source when no currency token is present but the element is a
     * declared price (schema.org / price token).
     */
    private function currencyFormattedText(string $text, DOMElement $element): string
    {
        $text = trim(preg_replace('/\s+/', ' ', self::withoutAggregateAmounts($text)) ?? '');
        if ( '' === $text ) {
            return '';
        }

        if ( preg_match('/\p{Sc}[\s\x{a0}]?\d[\d.,]*|\d[\d\s\x{a0}.,]*[\s\x{a0}]?\p{Sc}|\d[\d.,]*[\s\x{a0}]?(?:usd|eur|gbp|cad|aud)\b/iu', $text, $matches) ) {
            return trim($matches[0]);
        }

        // A schema.org price content attribute is bare numeric (e.g. "24.00");
        // keep it as-is when the element is a declared price.
        if ( $this->classifier->hasCommerceToken($element, array( 'price', 'amount', 'cost' )) && preg_match('/^\d+(?:[.,]\d+)*$/', $text) ) {
            return $text;
        }

        return '';
    }

    /** A card's purchase form is superseded by its provider-owned cart control. */
    public function cartControlForCardForm(DOMElement $form): ?DOMElement
    {
        // Only purchase options belong to the card provider; never swallow a
        // contact/customization form merely because it shares a product card.
        if ( $form->getElementsByTagName('textarea')->length > 0 ) {
            return null;
        }
        foreach ( $form->getElementsByTagName('input') as $input ) {
            $type = strtolower(SourceDom::attr($input, 'type'));
            if ( ! in_array($type, array( 'hidden', 'submit', 'button', 'image', 'number', 'radio', 'checkbox' ), true) ) {
                return null;
            }
        }
        for ( $card = $form->parentNode; $card instanceof DOMElement; $card = $card->parentNode ) {
            $container = $card->parentNode;
            if ( ! $container instanceof DOMElement || ! $this->isProductGridContainer($container) || null === $this->productCardData($card) ) {
                continue;
            }
            $control = $this->cartControlElement($card);
            return null !== $control && SourceDom::elementContains($form, $control) ? $control : null;
        }
        return null;
    }

    private function hasCartControl(DOMElement $card): bool
    {
        return null !== $this->cartControlElement($card);
    }

    /**
     * The order summaries of cart views inside `$root`, in document order.
     *
     * A cart view's order summary is the innermost element that holds a
     * checkout control together with at least two amount rows (subtotal,
     * shipping, total) and a total label. At a form, runtime target or landmark
     * boundary, a single subtotal also qualifies an empty cart; prices outside
     * that boundary must never contribute to its totals. Detection uses structure
     * and generic commerce vocabulary on the control and row text, class, id and
     * `data-*` attribute names — never a shop-provider name or fixture class.
     *
     * @param null|callable(DOMElement): bool $isRuntimeScope Source runtime-target evidence.
     * @return array<int, DOMElement>
     */
    public function cartSummaries(DOMElement $root, ?callable $isRuntimeScope = null): array
    {
        $summaries = array();
        foreach ( $root->getElementsByTagName('*') as $element ) {
            if ( ! $element instanceof DOMElement || ! $this->isCheckoutControl($element) ) {
                continue;
            }
            for ( $node = $element->parentNode; $node instanceof DOMElement && $node !== $root->parentNode; $node = $node->parentNode ) {
                $tag = strtolower($node->tagName);
                if ( in_array($tag, array( 'html', 'body' ), true) || $node->hasAttribute(CaptureEvidence::DOCUMENT_SCOPE) ) {
                    break;
                }
                $boundary = 'form' === $tag
                    || '' !== ShellLandmarkPolicy::landmarkKind($tag, SourceDom::attr($node, 'role'))
                    || in_array($tag, array( 'aside', 'dialog' ), true)
                    || in_array(strtolower(SourceDom::attr($node, 'role')), array( 'region', 'complementary', 'dialog' ), true)
                    || (null !== $isRuntimeScope && $isRuntimeScope($node));
                if ( $this->hasCartTotals($node, $boundary ? 1 : 2) ) {
                    $summaries[spl_object_id($node)] = $node;
                    break;
                }
                if ( $boundary ) {
                    break;
                }
            }
        }

        return array_values($summaries);
    }

    /**
     * Whether `$element` is, or contains, a cart view's order summary. A grid
     * child that reads as an order summary is never a product card: its heading
     * names the summary and its amounts are cart totals, not a product price.
     */
    public function isCartSummary(DOMElement $element): bool
    {
        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement && $this->isCheckoutControl($descendant) ) {
                return $this->hasCartTotals($element);
            }
        }

        return false;
    }

    private function isCheckoutControl(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( ! in_array($tagName, array( 'button', 'a', 'input' ), true) && 'button' !== strtolower(SourceDom::attr($element, 'role')) ) {
            return false;
        }

        // A control's submitted name is action evidence, not total-row vocabulary.
        $name = in_array($tagName, array( 'button', 'input' ), true)
            ? (string) preg_replace('/[^a-z0-9]+/i', ' ', SourceDom::attr($element, 'name'))
            : '';

        return self::hasCommerceWord($this->vocabulary($element) . ' ' . $name, array( 'checkout' ));
    }

    /**
     * Amount leaves and a total label: subtotal/shipping/total rows. The total
     * label must head an amount row, so its row (the label's parent) holds an
     * amount; the word "total" elsewhere on a page labels nothing.
     */
    private function hasCartTotals(DOMElement $element, int $minimumAmounts = 2): bool
    {
        $amounts = 0;
        $hasTotal = false;
        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( ! $descendant instanceof DOMElement || SourceDom::childElementCount($descendant) > 0 ) {
                continue;
            }
            if ( $this->looksLikePriceText($this->collapsedText($descendant)) ) {
                ++$amounts;
            }
            if ( ! $hasTotal ) {
                $vocabulary = $this->vocabulary($descendant);
                $hasTotal = ($this->classifier->hasTokenBoundary($vocabulary, 'total') || $this->classifier->hasTokenBoundary($vocabulary, 'subtotal'))
                    && $descendant->parentNode instanceof DOMElement && $this->holdsAmount($descendant->parentNode);
            }
        }

        return $hasTotal && $amounts >= $minimumAmounts;
    }

    private function holdsAmount(DOMElement $row): bool
    {
        foreach ( $row->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement && 0 === SourceDom::childElementCount($descendant) && $this->looksLikePriceText($this->collapsedText($descendant)) ) {
                return true;
            }
        }

        return false;
    }

    /** Text, class, id, aria-label and `data-*` attribute names, tokenized on non-alphanumerics. */
    private function vocabulary(DOMElement $element): string
    {
        $dataNames = array_map(static fn (string $name): string => substr($name, 5), array_keys(SourceDom::safeDataAttributes($element)));

        return strtolower((string) preg_replace('/[^a-z0-9]+/i', ' ', implode(' ', array(
            SourceDom::attr($element, 'class'),
            SourceDom::attr($element, 'id'),
            SourceDom::attr($element, 'aria-label'),
            SourceDom::attr($element, 'value'),
            implode(' ', $dataNames),
            $this->collapsedText($element),
        ))));
    }

    /**
     * The script-owned checkout regions under `$root`, outermost first.
     *
     * A checkout view is recognized from the role of the region, never a
     * vendor tag name: a region a source script owns (see `$isRuntimeScope`)
     * holding customer contact fields, an address group, a payment mount and
     * an order summary or total. Page landmarks never own the region — a
     * provider replaces the checkout inside them, not the chrome — and an
     * outer region that qualifies claims its nested runtime targets, so one
     * checkout is never split into several regions.
     *
     * @param null|callable(DOMElement): bool $isRuntimeScope Source runtime-target evidence.
     * @return array<int, DOMElement>
     */
    public function checkoutRegions(DOMElement $root, ?callable $isRuntimeScope = null): array
    {
        $regions = array();
        if ( null === $isRuntimeScope ) {
            return $regions;
        }
        foreach ( $root->getElementsByTagName('*') as $element ) {
            if ( ! $element instanceof DOMElement || ! $isRuntimeScope($element) ) {
                continue;
            }
            $tag = strtolower($element->tagName);
            if ( in_array($tag, array( 'html', 'body', 'main', 'header', 'footer', 'nav' ), true)
                || '' !== ShellLandmarkPolicy::landmarkKind($tag, SourceDom::attr($element, 'role')) ) {
                continue;
            }
            foreach ( $regions as $region ) {
                if ( SourceDom::elementContains($region, $element) ) {
                    continue 2;
                }
            }
            if ( $this->isCheckoutRegion($element) ) {
                $regions[] = $element;
            }
        }

        return $regions;
    }

    /**
     * Whether `$region` is a checkout view by role: a customer email and name,
     * an address group, a payment mount, and an order summary or total row. A
     * field's role is read from the attributes a script-defined element
     * carries (`label`, `placeholder`, `name`, `type` — a captured checkout
     * has no `<input>` at all) as well as from native controls, so a checkout
     * built from custom elements is recognized like a native one. The summary
     * rows qualify while their amounts are still empty — a client-side
     * checkout fills them in only after it runs.
     */
    public function isCheckoutRegion(DOMElement $region): bool
    {
        $email = false;
        $name = false;
        $address = false;
        $payment = false;
        $totalRow = false;
        foreach ( $region->getElementsByTagName('*') as $descendant ) {
            if ( ! $descendant instanceof DOMElement ) {
                continue;
            }
            $vocabulary = $this->checkoutFieldVocabulary($descendant);
            $email = $email || $this->classifier->hasTokenBoundary($vocabulary, 'email');
            $name = $name || $this->classifier->hasTokenBoundary($vocabulary, 'name');
            $address = $address || $this->classifier->hasTokenBoundary($vocabulary, 'address')
                || $this->classifier->hasTokenBoundary($vocabulary, 'street')
                || $this->classifier->hasTokenBoundary($vocabulary, 'postal')
                || $this->classifier->hasTokenBoundary($vocabulary, 'zipcode');
            $payment = $payment || $this->classifier->hasTokenBoundary($vocabulary, 'payment');
            $totalRow = $totalRow || $this->classifier->hasTokenBoundary($vocabulary, 'subtotal')
                || $this->classifier->hasTokenBoundary($vocabulary, 'total');
        }

        return $email && $name && $address && $payment && $totalRow;
    }

    /** Tag, class, id, field attributes (label, placeholder, name, type), aria-label, `data-*` attribute names and text, tokenized on non-alphanumerics. */
    private function checkoutFieldVocabulary(DOMElement $element): string
    {
        $dataNames = array_map(static fn (string $name): string => substr($name, 5), array_keys(SourceDom::safeDataAttributes($element)));

        return strtolower((string) preg_replace('/[^a-z0-9]+/i', ' ', implode(' ', array(
            $element->tagName,
            SourceDom::attr($element, 'class'),
            SourceDom::attr($element, 'id'),
            SourceDom::attr($element, 'label'),
            SourceDom::attr($element, 'placeholder'),
            SourceDom::attr($element, 'name'),
            SourceDom::attr($element, 'type'),
            SourceDom::attr($element, 'aria-label'),
            SourceDom::attr($element, 'value'),
            implode(' ', $dataNames),
            $this->collapsedText($element),
        ))));
    }

    /**
     * Whether the card contains quantity UI: number input, spinbutton, +/- controls,
     * or explicit quantity labels/classes/ARIA. This is diagnostic only.
     */
    private function hasQuantityControl(DOMElement $card): bool
    {
        foreach ( $card->getElementsByTagName('*') as $descendant ) {
            if ( ! $descendant instanceof DOMElement ) {
                continue;
            }

            $tagName = strtolower($descendant->tagName);
            $role = strtolower(SourceDom::attr($descendant, 'role'));
            if ( 'input' === $tagName && 'number' === strtolower(SourceDom::attr($descendant, 'type')) ) {
                return true;
            }
            if ( 'spinbutton' === $role ) {
                return true;
            }

            $haystack = strtolower(implode(' ', array(
                SourceDom::attr($descendant, 'class'),
                SourceDom::attr($descendant, 'id'),
                SourceDom::attr($descendant, 'name'),
                SourceDom::attr($descendant, 'aria-label'),
                implode(' ', SourceDom::safeDataAttributes($descendant)),
                $this->collapsedText($descendant),
            )));

            if ( preg_match('/\b(?:qty|quantity|decrease|increase)\b/', $haystack) ) {
                return true;
            }
            if ( in_array($tagName, array( 'button', 'a' ), true) && preg_match('/^[+\x{2212}-]$/u', trim($this->collapsedText($descendant))) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * An optional short product description: the first paragraph in the card whose
     * text is neither the name nor a price. Returns null when none is present.
     */
    private function productDescriptionText(DOMElement $card, string $name): ?string
    {
        foreach ( $card->getElementsByTagName('p') as $paragraph ) {
            if ( ! $paragraph instanceof DOMElement ) {
                continue;
            }

            $text = $this->collapsedText($paragraph);
            if ( '' === $text || $text === $name || $this->looksLikePriceText($text) ) {
                continue;
            }

            // A paragraph may be inside a stock/availability badge. Judge its
            // source, not words that could also occur in genuine product copy.
            for ( $source = $paragraph; $source instanceof DOMElement && $source !== $card; $source = $source->parentNode ) {
                if ( $this->classifier->hasCommerceToken($source, array( 'badge', 'badges', 'stock', 'availability', 'soldout' )) || 'availability' === strtolower(SourceDom::attr($source, 'itemprop')) ) {
                    continue 2;
                }
            }

            return mb_strlen($text) > 280 ? mb_substr($text, 0, 277) . '...' : $text;
        }

        return null;
    }

    /**
     * The card's primary image as a generic { src, alt } pair, or null.
     *
     * @return array<string, string>|null
     */
    private function productImage(DOMElement $card): ?array
    {
        foreach ( $card->getElementsByTagName('img') as $image ) {
            if ( ! $image instanceof DOMElement ) {
                continue;
            }

            $src = trim(SourceDom::attr($image, 'src'));
            if ( '' === $src && '' !== trim(SourceDom::attr($image, 'data-src')) ) {
                $src = trim(SourceDom::attr($image, 'data-src'));
            }
            if ( '' === $src || preg_match('/^\s*javascript\s*:/i', $src) ) {
                continue;
            }

            return array_filter(array(
                'src' => $src,
                'alt' => trim(SourceDom::attr($image, 'alt')),
            ), static fn (mixed $value): bool => '' !== $value);
        }

        if (null !== $this->svgImageReference) {
            $candidates = iterator_to_array($card->getElementsByTagName('svg'));
            // A media wrapper is stronger evidence than artwork elsewhere in
            // the card. Preserve document order within each group.
            $media = array();
            $other = array();
            foreach ($candidates as $svg) {
                $inMedia = false;
                for ($node = $svg->parentNode; $node instanceof DOMElement && $node !== $card; $node = $node->parentNode) {
                    if ('figure' === strtolower($node->tagName) || $this->classifier->hasCommerceToken($node, array('media', 'image', 'visual'))) {
                        $inMedia = true;
                        break;
                    }
                }
                if ($inMedia) $media[] = $svg;
                else $other[] = $svg;
            }
            foreach (array_merge($media, $other) as $svg) {
                if (!$svg instanceof DOMElement || !$this->isProductMediaSvg($svg, $card)) {
                    continue;
                }
                $image = ($this->svgImageReference)($svg);
                if (null !== $image) {
                    return $image;
                }
            }
        }

        return null;
    }

    /** Exclude control glyphs and decorative/hidden branches before materializing. */
    private function isProductMediaSvg(DOMElement $svg, DOMElement $card): bool
    {
        for ($node = $svg; $node instanceof DOMElement; $node = $node->parentNode) {
            if ($node->hasAttribute('hidden')
                || SourceDom::isAriaHidden($node)
                || in_array(strtolower($node->tagName), array('button', 'input', 'select', 'textarea'), true)
                || in_array(strtolower(SourceDom::attr($node, 'role')), array('button', 'checkbox', 'switch'), true)
                || $this->classifier->hasCommerceToken($node, array('wishlist', 'quickview'))
                || $this->hasCommerceIdentity($node, array( 'cart' ))
                || preg_match('/(?:display\s*:\s*none|visibility\s*:\s*hidden)/i', SourceDom::attr($node, 'style'))
            ) {
                return false;
            }
            if ($node === $card) break;
        }
        if (in_array(strtolower(SourceDom::attr($svg, 'role')), array('none', 'presentation'), true)) {
            return false;
        }
        // Explicit icon-sized viewports are not product artwork. A viewBox is
        // intrinsic coordinate space, not a rendered size, so do not use it here.
        foreach (array('width', 'height') as $axis) {
            $size = trim(SourceDom::attr($svg, $axis));
            if (preg_match('/^([\d.]+)(?:px)?$/i', $size, $match) && (float) $match[1] <= 32) {
                return false;
            }
        }
        return true;
    }

    /**
     * Find the nearest descendant (or the element itself) declaring one of the
     * given schema.org `itemprop` values.
     *
     * @param array<int, string> $itemprops
     */
    private function firstDescendantWithItemprop(DOMElement $element, array $itemprops): ?DOMElement
    {
        if ( in_array(strtolower(SourceDom::attr($element, 'itemprop')), $itemprops, true) ) {
            return $element;
        }

        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement && in_array(strtolower(SourceDom::attr($descendant, 'itemprop')), $itemprops, true) ) {
                return $descendant;
            }
        }

        return null;
    }

    /**
     * The card's category label: the schema.org `category` property, or a
     * short leaf whose class or id names a category.
     */
    private function productCategoryText(DOMElement $card): ?string
    {
        $schema = $this->firstDescendantWithItemprop($card, array( 'category' ));
        $candidates = null !== $schema ? array( $schema ) : array();
        foreach ( $card->getElementsByTagName('*') as $element ) {
            if ( $element instanceof DOMElement && SourceDom::childElementCount($element) === 0 && $this->classifier->hasCommerceToken($element, array( 'category', 'cat', 'collection' )) ) {
                $candidates[] = $element;
            }
        }
        foreach ( $candidates as $element ) {
            $text = '' !== SourceDom::attr($element, 'content') ? trim(SourceDom::attr($element, 'content')) : $this->collapsedText($element);
            if ( '' !== $text && mb_strlen($text) <= 60 ) {
                return $text;
            }
        }

        return null;
    }

    private function collapsedText(DOMElement $element): string
    {
        return trim(preg_replace('/\s+/', ' ', $element->textContent ?? '') ?? '');
    }

}

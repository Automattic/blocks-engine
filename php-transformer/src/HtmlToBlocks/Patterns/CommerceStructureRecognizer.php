<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Support\ShellLandmarkPolicy;
use DOMElement;

/**
 * Generic commerce-structure recognition: schema.org Product/Offer evidence,
 * currency-formatted prices, and the name + price + cart-control triad.
 *
 * Detection reads structural/semantic signals and schema.org vocabulary — never
 * a shop-provider name, fixture name, or specific class string.
 */
final class CommerceStructureRecognizer
{
    public function __construct(
        private readonly SourceElementClassifier $classifier = new SourceElementClassifier()
    ) {
    }

    public function looksLikePriceText(string $text): bool
    {
        return (bool) preg_match('/(?:\p{Sc}\s?\d|\d+(?:[.,]\d{2})?\s?(?:usd|eur|gbp|cad|aud)\b)/iu', trim($text));
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
     * pairing a name with a price.
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
        $name = $this->productNameText($card);
        $prices = $this->productPriceTexts($card);
        $hasCart = $this->hasCartControl($card);
        $isSchemaProduct = $this->isSchemaProductCard($card);
        $image = $this->productImage($card);

        if ( '' === $name || array() === $prices || $this->isCartSummary($card) ) {
            return null;
        }

        $isCatalogImageCard = null !== $image && ! $this->isRecurringPriceCard($card);
        if ( ! $isSchemaProduct && ! $hasCart && ! $isCatalogImageCard ) {
            return null;
        }

        return array_filter(array(
            'name'             => $name,
            'slug'             => $this->productLinkSlug($card, $name),
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

    public function cartControlElement(DOMElement $card): ?DOMElement
    {
        $tokens = array( 'cart', 'buy', 'purchase', 'checkout', 'order', 'addtocart', 'add-to-cart' );
        foreach ( $card->getElementsByTagName('*') as $descendant ) {
            if ( ! $descendant instanceof DOMElement ) {
                continue;
            }

            $tagName = strtolower($descendant->tagName);
            $role = strtolower(SourceDom::attr($descendant, 'role'));
            $isControl = in_array($tagName, array( 'button', 'a', 'input' ), true) || 'button' === $role;
            if ( ! $isControl ) {
                continue;
            }

            $haystack = strtolower(implode(' ', array(
                SourceDom::attr($descendant, 'class'),
                SourceDom::attr($descendant, 'id'),
                SourceDom::attr($descendant, 'name'),
                SourceDom::attr($descendant, 'aria-label'),
                SourceDom::attr($descendant, 'value'),
                implode(' ', $this->safeDataAttributes($descendant)),
                $this->collapsedText($descendant),
            )));

            foreach ( $tokens as $token ) {
                if ( $this->classifier->hasTokenBoundary($haystack, $token) ) {
                    return $descendant;
                }
            }

            // "add" alone is ambiguous, so require it to co-occur with a commerce
            // context word ("cart"/"bag"/"basket") to count as a cart control.
            if ( preg_match('/\badd\b/', $haystack) && preg_match('/\b(?:cart|bag|basket)\b/', $haystack) ) {
                return $descendant;
            }
        }

        return null;
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
        $style = strtolower(trim(SourceDom::attr($element, 'style')));

        return (bool) preg_match('/(?:^|[\s_-])(?:cards|features|services|providers|testimonials|resources|posts|projects|stats|badges|grid|grid-[0-9]+|tiles|columns|collection|gallery)(?:$|[\s_-])/', $className)
            || (bool) preg_match('/(?:^|;)\s*(?:display\s*:\s*grid|grid-template-columns\s*:)/', $style);
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
        foreach ( $card->getElementsByTagName('a') as $link ) {
            if ( $this->collapsedText($link) !== $name && SourceDom::attr($link, 'title') !== $name ) {
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

            return rawurldecode($base);
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

        return '';
    }

    private function isProductNameElement(DOMElement $element): bool
    {
        if ( '' === $this->collapsedText($element) ) {
            return false;
        }
        foreach ( array_merge(array( $element ), iterator_to_array($element->getElementsByTagName('*'))) as $node ) {
            if ( $this->isPriceElement($node)
                || $this->classifier->hasCommerceToken($node, array( 'badge', 'badges', 'cart', 'buy', 'purchase', 'quickview', 'quick', 'controls' ))
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
     * A price element marked with a sale/discount token is treated as the sale
     * price and the other as the regular price.
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

    private function productPriceTexts(DOMElement $card): array
    {
        $regular = '';
        $sale = '';
        $fallback = '';

        $schemaPrice = $this->firstDescendantWithItemprop($card, array( 'price' ));
        if ( null !== $schemaPrice ) {
            $content = trim(SourceDom::attr($schemaPrice, 'content'));
            $regular = $this->currencyFormattedText('' !== $content ? $content : ($schemaPrice->textContent ?? ''), $schemaPrice);
        }

        $declared = array();
        foreach ( array_merge(array( $card ), iterator_to_array($card->getElementsByTagName('*'))) as $node ) {
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
                if ( ('ins' === strtolower($node->tagName) && $hasStruckPrice) || ('ins' !== strtolower($node->tagName) && $this->classifier->hasCommerceToken($node, array( 'sale', 'discount', 'special', 'reduced', 'now' ))) ) {
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
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ( '' === $text ) {
            return '';
        }

        if ( preg_match('/\p{Sc}\s?\d[\d.,]*|\d[\d.,]*\s?(?:usd|eur|gbp|cad|aud)\b/iu', $text, $matches) ) {
            return trim($matches[0]);
        }

        // A schema.org price content attribute is bare numeric (e.g. "24.00");
        // keep it as-is when the element is a declared price.
        if ( $this->classifier->hasCommerceToken($element, array( 'price', 'amount', 'cost' )) && preg_match('/^\d+(?:[.,]\d+)*$/', $text) ) {
            return $text;
        }

        return '';
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
                if ( in_array($tag, array( 'html', 'body' ), true) ) {
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

        return $this->classifier->hasTokenBoundary($this->vocabulary($element), 'checkout');
    }

    /** Amount leaves and a total label: subtotal/shipping/total rows. */
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
            $vocabulary = $this->vocabulary($descendant);
            if ( $this->classifier->hasTokenBoundary($vocabulary, 'total') || $this->classifier->hasTokenBoundary($vocabulary, 'subtotal') ) {
                $hasTotal = true;
            }
        }

        return $hasTotal && $amounts >= $minimumAmounts;
    }

    /** Text, class, id, aria-label and `data-*` attribute names, tokenized on non-alphanumerics. */
    private function vocabulary(DOMElement $element): string
    {
        $dataNames = array_map(static fn (string $name): string => substr($name, 5), array_keys($this->safeDataAttributes($element)));

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
                implode(' ', $this->safeDataAttributes($descendant)),
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

        return null;
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
}

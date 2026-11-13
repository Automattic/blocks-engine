<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\SchemaProductEvidence;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormRuntimeRequirementAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Support\ShellLandmarkPolicy;
use DOMDocument;
use DOMElement;
use WeakMap;

/**
 * Recognizes the controls that narrow, order, or count a repeated listing.
 *
 * A listing is a container of repeated item cards. Its controls are found by
 * evidence about those cards rather than by vocabulary alone:
 *
 * - `facet`: an option group (a `select`, sibling buttons that share one
 *   `data-*` attribute name, or same-named radios/checkboxes) whose option values
 *   partition the cards. A card carries an option's value as the text of one
 *   of its leaves, a segment of one of its class tokens, or a `data-*` value.
 *   Explicit checkbox facets also qualify without card partition evidence,
 *   including on one-item and empty collections.
 * - `range`: a slider or numeric bound inputs that constrain the cards' prices.
 * - `sort`: a `select` whose options all read as orderings of the cards.
 * - `result_count`: a short text whose number is the card count.
 *
 * The region is the common ancestor of the listing and each control's nearest
 * placement. Equivalent placements outside that region still receive bindings.
 * A candidate is claimed only when it does not sit in a sibling branch that
 * holds another listing of the same shape — on a page that repeats one
 * category-group shape, each group's controls are its own.
 * Everything here is provider-neutral evidence: what each control does to
 * the cards and which card carries which value.
 */
final class ListingControlsRecognizer
{
    private const BOUNDARY_TAGS = array( 'body', 'main', 'header', 'footer', 'nav', 'aside', 'html' );
    private const SORT_KEYS = array( 'default', 'popularity', 'rating', 'newest', 'oldest', 'price_asc', 'price_desc', 'name_asc', 'name_desc' );

    /** @var WeakMap<DOMDocument, array<string, bool>>|null Results live only as long as their source document. */
    private static ?WeakMap $numericBoundsByForm = null;

    /**
     * @param list<DOMElement> $cards
     * @param list<float|null> $prices Card prices aligned with `$cards`.
     * @return array{region: DOMElement, controls: list<array<string,mixed>>, card_facets: list<array<string,string>>}|null
     */
    public function recognize(DOMElement $listing, array $cards, array $prices): ?array
    {
        if ( $this->containsChrome($listing) ) {
            return null;
        }
        $scope = $listing;
        for ( $node = $listing->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
            $scope = $node;
            if ( in_array(strtolower($node->tagName), self::BOUNDARY_TAGS, true) ) {
                break;
            }
        }
        if ( $scope === $listing ) {
            return null;
        }

        $cardTokens = array_map(fn (DOMElement $card): array => $this->cardTokens($card), $cards);
        $controls = array();
        $cardFacets = array_fill(0, count($cards), array());
        $claimed = array();

        foreach ( $this->optionGroups($scope, $listing) as $group ) {
            if ( isset($claimed[spl_object_id($group['element'])]) ) {
                continue;
            }
            $sort = 'select' === $group['shape'] ? $this->sortOptions($group['options'], $group['element']) : null;
            if ( null !== $sort ) {
                $controls[] = array('kind' => 'sort', 'element' => $group['element'], 'label' => $this->controlLabel($group['element']), 'options' => $sort);
                $claimed[spl_object_id($group['element'])] = true;
                continue;
            }
            $facet = $this->facet($group, $cardTokens);
            if ( null === $facet ) {
                continue;
            }
            $controls[] = $facet;
            $claimed[spl_object_id($group['element'])] = true;
        }

        array_push($controls, ...$this->priceRange($scope, $listing, $prices));

        array_push($controls, ...$this->resultCounts($scope, $listing, count($cards)));

        // Document order is not ownership: responsive copies can precede the
        // controls beside the grid. Compare their common ancestors instead.
        $nearest = array();
        foreach ( $controls as $control ) {
            $key = $this->controlKey($control);
            $ancestor = $this->commonAncestor($listing, $control['element']);
            if ( ! isset($nearest[$key]) || $this->contains($this->commonAncestor($listing, $nearest[$key]['element']), $ancestor) ) {
                $nearest[$key] = $control;
            }
        }

        // Never let an outlying control pull page chrome into the collection.
        // The highest chrome-free ancestor is the limit of safe widening.
        $limit = $listing;
        for ( $node = $listing; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( $this->containsChrome($node) ) {
                break;
            }
            $limit = $node;
            if ( $node === $scope ) {
                break;
            }
        }
        $nearest = array_filter($nearest, fn (array $control): bool => $control['element'] === $limit || $this->contains($limit, $control['element']));
        // Region ownership and placement ownership are separate: once a
        // logical control is accepted, keep all its responsive placements.
        // The publisher declares it once and binds every placement, including
        // outlying modal forms that must not remain standalone form entities.
        $controls = array_values(array_filter($controls, fn (array $control): bool => isset($nearest[$this->controlKey($control)])));
        foreach ( $controls as &$control ) {
            foreach ( $control['card_values'] ?? array() as $cardIndex => $value ) {
                $cardFacets[$cardIndex][$control['key']] = $value;
            }
            unset($control['card_values']);
        }
        unset($control);

        // An empty collection's only evidence would be a zero count, and any
        // "0 likes" or "0 comments" on the page reads the same; it needs a
        // control that selects among products.
        if ( array() === $controls || ( array() === $cards && array('result_count') === array_unique(array_column($controls, 'kind')) ) ) {
            return null;
        }

        $region = $listing;
        foreach ( $nearest as $control ) {
            $region = $this->commonAncestor($region, $control['element']);
        }

        return array('region' => $region, 'controls' => $controls, 'card_facets' => $cardFacets);
    }

    /**
     * @return list<array{shape: string, element: DOMElement, options: list<array{value: string, label: string}>}>
     */
    private function optionGroups(DOMElement $scope, DOMElement $listing): array
    {
        $groups = array();
        $radios = array();
        foreach ( $scope->getElementsByTagName('*') as $element ) {
            if ( ! $element instanceof DOMElement || $this->contains($listing, $element) || self::inSearchContext($element) || $this->inSubmissionForm($element) || $this->inSiblingListingBranch($listing, $element) ) {
                continue;
            }
            $tag = strtolower($element->tagName);
            if ( 'select' === $tag && ! $element->hasAttribute('multiple') ) {
                $options = array();
                foreach ( $element->getElementsByTagName('option') as $option ) {
                    $label = $this->text($option);
                    $options[] = array('value' => $option->hasAttribute('value') ? SourceDom::attr($option, 'value') : $label, 'label' => $label);
                }
                if ( count($options) >= 2 && ! $this->inSiblingListingBranch($listing, $element) ) {
                    $groups[] = array('shape' => 'select', 'element' => $element, 'options' => $options);
                }
                continue;
            }
            if ( 'input' === $tag && in_array(strtolower(SourceDom::attr($element, 'type')), array('radio', 'checkbox'), true) && '' !== SourceDom::attr($element, 'name') ) {
                $label = SourceDom::associatedLabel($element);
                // Same-named inputs in separate responsive forms/fieldsets
                // are candidates, not one document-wide option group.
                $owner = $scope;
                for ( $node = $element->parentNode; $node instanceof DOMElement && $node !== $scope; $node = $node->parentNode ) {
                    if ( in_array(strtolower($node->tagName), array('fieldset', 'form', 'details'), true) ) {
                        $owner = $node;
                        break;
                    }
                }
                $groupKey = SourceDom::attr($element, 'name') . ':' . ($owner?->getNodePath() ?? '');
                $radios[$groupKey][] = array('element' => $element, 'option' => array('value' => SourceDom::attr($element, 'value'), 'label' => null !== $label ? $this->text($label) : SourceDom::attr($element, 'value')));
                continue;
            }
            $buttons = $this->dataKeyedButtons($element);
            if ( null !== $buttons && ! $this->inSiblingListingBranch($listing, $element) ) {
                $groups[] = array('shape' => 'buttons', 'element' => $element, 'options' => $buttons);
            }
        }
        foreach ( $radios as $members ) {
            if ( count($members) < 2 && 'checkbox' !== strtolower(SourceDom::attr($members[0]['element'], 'type')) ) {
                continue;
            }
            $container = $members[0]['element'];
            foreach ( $members as $member ) {
                $container = $this->commonAncestor($container, $member['element']);
            }
            if ( $this->inSiblingListingBranch($listing, $container) ) {
                continue;
            }
            $shape = 'checkbox' === strtolower(SourceDom::attr($members[0]['element'], 'type')) ? 'checkboxes' : 'radios';
            $groups[] = array('shape' => $shape, 'element' => $container, 'name' => SourceDom::attr($members[0]['element'], 'name'), 'options' => array_column($members, 'option'));
        }

        return $groups;
    }

    /**
     * Child buttons of `$element` that all carry one `data-*` attribute with
     * distinct values: a button group whose values a script reads.
     *
     * @return list<array{value: string, label: string}>|null
     */
    private function dataKeyedButtons(DOMElement $element): ?array
    {
        $buttons = array();
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            if ( 'button' !== strtolower($child->tagName) && 'button' !== strtolower(SourceDom::attr($child, 'role')) ) {
                return null;
            }
            $buttons[] = $child;
        }
        if ( count($buttons) < 2 ) {
            return null;
        }
        $shared = null;
        foreach ( $buttons as $button ) {
            $names = array();
            foreach ( $button->attributes ?? array() as $attribute ) {
                if ( str_starts_with($attribute->nodeName, 'data-') && '' !== trim($attribute->nodeValue ?? '') ) {
                    $names[] = $attribute->nodeName;
                }
            }
            $shared = null === $shared ? $names : array_values(array_intersect($shared, $names));
        }
        foreach ( $shared ?? array() as $name ) {
            $options = array_map(fn (DOMElement $button): array => array('value' => SourceDom::attr($button, $name), 'label' => $this->text($button)), $buttons);
            if ( count(array_unique(array_column($options, 'value'))) === count($options) ) {
                return $options;
            }
        }

        return null;
    }

    /**
     * @param array{shape: string, element: DOMElement, options: list<array{value: string, label: string}>} $group
     * @param list<list<string>> $cardTokens
     * @return array<string,mixed>|null
     */
    private function facet(array $group, array $cardTokens): ?array
    {
        $options = array();
        $cardValues = array();
        $matchedOptions = 0;
        foreach ( $group['options'] as $option ) {
            $needle = $this->normalize($option['value']);
            $matches = array();
            foreach ( $cardTokens as $cardIndex => $tokens ) {
                if ( '' !== $needle && in_array($needle, $tokens, true) ) {
                    if ( isset($cardValues[$cardIndex]) ) {
                        return null;
                    }
                    $matches[] = $cardIndex;
                }
            }
            if ( array() === $matches ) {
                if ( $this->isResetOption($option) ) {
                    $options[] = $option + array('reset' => true);
                    continue;
                }
            } else {
                ++$matchedOptions;
                foreach ( $matches as $cardIndex ) {
                    $cardValues[$cardIndex] = $option['value'];
                }
            }
            $options[] = $option;
        }
        // A group whose only options reset the listing selects nothing, such
        // as a lone valueless disclosure checkbox.
        if ( array() === array_filter($options, static fn (array $option): bool => ! isset($option['reset'])) ) {
            return null;
        }
        // Explicit checkbox filters remain meaningful on a one-item or empty
        // collection, where partitioning two cards is impossible.
        $explicitFacet = 'checkboxes' === $group['shape'];
        if ( ! $explicitFacet && ($matchedOptions < 2 || count($cardValues) * 2 < count($cardTokens)) ) {
            return null;
        }
        $label = $this->controlLabel($group['element']);
        if ( '' === $label ) {
            $name = $group['name'] ?? SourceDom::attr($group['element'], 'name');
            $label = (string) preg_replace('/^.*[.\[\]]([^.[\]]+)\]?$/', '$1', $name);
        }
        $key = trim((string) preg_replace('/[^a-z0-9]+/', '_', $this->normalize($label)), '_');
        // A facet needs a name both to show shoppers and to key its attribute.
        if ( '' === $key ) {
            return null;
        }
        ksort($cardValues);

        return array(
            'kind'        => 'facet',
            'element'     => $group['element'],
            'shape'       => $group['shape'],
            'key'         => $key,
            'label'       => $label,
            'role'        => preg_match('/\b(categor|collection|department|type|kind)/i', $label) ? 'category' : 'attribute',
            'options'     => $options,
            'card_values' => $cardValues,
        );
    }

    /**
     * Whether a candidate control sits in a sibling branch that holds another
     * listing of the same shape, making it that listing's control. The scope
     * widens past the listing's own ancestors, so on a page that repeats one
     * category-group shape (heading, count, grid), every group's controls are
     * in range; without this check a grid whose own count label does not match
     * its card count would take the first matching label anywhere in scope —
     * one inside a sibling group's branch — and two listings would bind the
     * same block, which the site plan rejects as ambiguous. Take the common
     * ancestor of the listing and the candidate, then the child of that
     * ancestor on the candidate's side: when that branch holds an element with
     * the same tag name and class attribute as the listing — a peer listing —
     * the candidate is not this listing's.
     */
    private function inSiblingListingBranch(DOMElement $listing, DOMElement $candidate): bool
    {
        $ancestor = $this->commonAncestor($listing, $candidate);
        if ( $ancestor === $listing || $ancestor === $candidate ) {
            return false;
        }
        $branch = $candidate;
        while ( $branch->parentNode instanceof DOMElement && $branch->parentNode !== $ancestor ) {
            $branch = $branch->parentNode;
        }
        if ( ! $branch instanceof DOMElement ) {
            return false;
        }
        $tag = strtolower($listing->tagName);
        $class = $listing->getAttribute('class');
        foreach ( array_merge(array($branch), iterator_to_array($branch->getElementsByTagName('*'), false)) as $element ) {
            if ( $element instanceof DOMElement && $element !== $listing && strtolower($element->tagName) === $tag && $element->getAttribute('class') === $class ) {
                return true;
            }
        }

        return false;
    }

    /** @param array{value: string, label: string} $option */
    private function isResetOption(array $option): bool
    {
        return in_array($this->normalize($option['value']), array( '', 'all', 'any', '*' ), true)
            || (bool) preg_match('/^(all|any|every)\b/i', $option['label']);
    }

    /**
     * @param list<array{value: string, label: string}> $options
     * @return list<array{value: string, label: string, order?: string}>|null
     */
    private function sortOptions(array $options, DOMElement $select): ?array
    {
        $sorted = array();
        foreach ( $options as $option ) {
            // Machine values are independent of the storefront's language.
            $order = $this->sortOrder($option['value']) ?? $this->sortOrder($option['label']);
            if ( null === $order && ! preg_match('/sort|order/i', SourceDom::attr($select, 'name')) ) {
                return null;
            }
            $sorted[] = null === $order ? $option : $option + array('order' => $order);
        }
        $orders = array_column($sorted, 'order');
        if ( ! preg_match('/sort|order/i', SourceDom::attr($select, 'name')) && count(array_unique($orders)) < 2 ) {
            return null;
        }

        return $sorted;
    }

    private function sortOrder(string $text): ?string
    {
        $text = $this->normalize($text);
        $order = match (true) {
            str_contains($text, 'price') && (bool) preg_match('/high\W*(to|-|→)\W*low|desc|highest|most expensive/', $text) => 'price_desc',
            str_contains($text, 'price') && (bool) preg_match('/low\W*(to|-|→)\W*high|asc|lowest|cheapest/', $text) => 'price_asc',
            (bool) preg_match('/\bz\W*(to|-|→)\W*a\b/', $text) => 'name_desc',
            (bool) preg_match('/\b(title|name)\b.*desc/', $text) => 'name_desc',
            (bool) preg_match('/\b(title|name)\b.*asc|\bname\b|\ba\W*(to|-|→)\W*z\b|alphabet/', $text) => 'name_asc',
            (bool) preg_match('/\b(date|created)\b.*asc|\boldest\b/', $text) => 'oldest',
            (bool) preg_match('/\b(new|newest|latest|recent|date|created)\b/', $text) => 'newest',
            (bool) preg_match('/\b(popular|popularity|best.?sell\w*|trending)\b/', $text) => 'popularity',
            (bool) preg_match('/\b(rating|rated|reviews?)\b/', $text) => 'rating',
            (bool) preg_match('/\b(featured|default|relevance|recommended|manual)\b/', $text) => 'default',
            default => null,
        };

        return in_array($order, self::SORT_KEYS, true) ? $order : null;
    }

    /**
     * @param list<float|null> $prices
     * @return list<array<string,mixed>>
     */
    private function priceRange(DOMElement $scope, DOMElement $listing, array $prices): array
    {
        $known = array_values(array_filter($prices, 'is_float'));
        if ( array() === $known ) {
            return array();
        }
        $ranges = array();
        foreach ( $scope->getElementsByTagName('input') as $input ) {
            if ( 'number' === strtolower(SourceDom::attr($input, 'type')) ) {
                if ( $this->contains($listing, $input) || $this->inSiblingListingBranch($listing, $input) || self::inSearchContext($input) || $this->inSubmissionForm($input) || $input->hasAttribute('disabled') ) {
                    continue;
                }
                $form = $input->parentNode;
                while ( $form instanceof DOMElement && 'form' !== strtolower($form->tagName) ) {
                    $form = $form->parentNode;
                }
                if ( ! $form instanceof DOMElement || ! self::isGetFilterForm($form) ) {
                    continue;
                }
                $label = $this->controlLabel($input);
                $tokens = strtolower((string) preg_replace('/[^a-z]+/i', ' ', SourceDom::attr($input, 'name') . ' ' . $label));
                // Explicit field/bound semantics take priority. Otherwise a
                // numeric endpoint must bracket the observed card prices.
                $bound = preg_match('/\b(min|max|from|to|minimum|maximum)\b/', $tokens, $match) ? (in_array($match[1], array('min', 'from', 'minimum'), true) ? 'min' : 'max') : null;
                $price = (bool) preg_match('/\bprice\b/', $tokens);
                $endpoint = SourceDom::attr($input, 'placeholder');
                if ( ! is_numeric($endpoint) ) {
                    $endpoint = SourceDom::attr($input, 'max');
                }
                if ( ! is_numeric($endpoint) ) {
                    $endpoint = SourceDom::attr($input, 'min');
                }
                $numericBound = is_numeric($endpoint) ? ((float) $endpoint <= min($known) && (float) $endpoint < max($known) ? 'min' : ((float) $endpoint >= max($known) && (float) $endpoint > min($known) ? 'max' : null)) : null;
                if ( null === $bound ) {
                    $bound = $numericBound;
                }
                if ( null === $bound || (! $price && $bound !== $numericBound) ) {
                    continue;
                }
                // Do not reinterpret explicitly named non-price fields.
                if ( ! $price && preg_match('/\b(quantity|qty|age|weight|length|width|height|amount)\b/', $tokens) ) {
                    continue;
                }
                $ranges[] = array('kind' => 'range', 'element' => $input, 'key' => 'price:' . $bound, 'field' => 'price', 'label' => $label, 'bound' => $bound);
                continue;
            }
            if ( ! $input instanceof DOMElement || 'range' !== strtolower(SourceDom::attr($input, 'type')) || $this->contains($listing, $input) || $this->inSiblingListingBranch($listing, $input) ) {
                continue;
            }
            $min = SourceDom::attr($input, 'min');
            $max = SourceDom::attr($input, 'max');
            if ( ! is_numeric($min) || ! is_numeric($max) || (float) $min > min($known) || (float) $max < max($known) ) {
                continue;
            }
            $label = $this->controlLabel($input);

            $ranges[] = array(
                'kind'    => 'range',
                'element' => $input,
                'field'   => 'price',
                'label'   => $label,
                'bound'   => preg_match('/\b(min|from|over|at least)\b/i', $label) ? 'min' : 'max',
                'min'     => (float) $min,
                'max'     => (float) $max,
            );
        }

        return $ranges;
    }

    /** GET bounds submit to this page, not to an unrelated workflow. */
    private static function isGetFilterForm(DOMElement $form): bool
    {
        $method = strtolower(trim(SourceDom::attr($form, 'method')));
        $action = trim(SourceDom::attr($form, 'action'));
        return in_array($method, array('', 'get'), true)
            && (in_array($action, array('', '#'), true) || $action === $form->ownerDocument?->documentURI);
    }

    /**
     * Reuse listing evidence for intent: every free-entry control must be an
     * adopted numeric bound, with both ends of one field represented.
     */
    public function hasNumericBoundsForForm(DOMElement $form): bool
    {
        $document = $form->ownerDocument;
        if ( null === $document ) {
            return false;
        }
        self::$numericBoundsByForm ??= new WeakMap();
        $path = (string) $form->getNodePath();
        $results = self::$numericBoundsByForm[$document] ?? array();
        if ( array_key_exists($path, $results) ) {
            return $results[$path];
        }
        $results[$path] = $this->recognizeNumericBoundsForForm($form);
        self::$numericBoundsByForm[$document] = $results;
        return $results[$path];
    }

    private function recognizeNumericBoundsForForm(DOMElement $form): bool
    {
        // Reject ordinary forms before inspecting any surrounding grid.
        if ( ! self::isGetFilterForm($form) ) {
            return false;
        }
        $entries = array();
        foreach ( FormRuntimeRequirementAnalyzer::intentControls($form) as $control ) {
            $tag = strtolower($control->tagName);
            $type = strtolower(SourceDom::attr($control, 'type'));
            if ( 'button' === $tag || 'select' === $tag || in_array($type, array('submit', 'reset', 'button', 'image', 'checkbox', 'radio'), true) ) {
                continue;
            }
            if ( 'input' !== $tag || 'number' !== $type ) {
                return false;
            }
            $entries[] = $control;
        }
        if ( count($entries) < 2 ) {
            return false;
        }
        $scope = $form;
        for ( $node = $form->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
            $scope = $node;
            if ( in_array(strtolower($node->tagName), self::BOUNDARY_TAGS, true) && 'aside' !== strtolower($node->tagName) ) {
                break;
            }
        }
        $commerce = new CommerceStructureRecognizer();
        foreach ( $scope->getElementsByTagName('*') as $grid ) {
            if ( ! $commerce->isProductGridContainer($grid) ) {
                continue;
            }
            $cards = array();
            $prices = array();
            foreach ( $grid->childNodes as $card ) {
                if ( ! $card instanceof DOMElement || null === ($product = $commerce->productCardData($card)) ) {
                    continue;
                }
                $cards[] = $card;
                $digits = SchemaProductEvidence::productPrice($product['price'] ?? '');
                $prices[] = is_numeric($digits) ? (float) $digits : null;
            }
            if ( array() === $cards ) {
                continue;
            }
            // Intent needs only the same price-bound evidence used by the
            // listing path, not facets, sort options, or result counts.
            $bounds = array();
            foreach ( $this->priceRange($scope, $grid, $prices) as $control ) {
                if ( 'range' === $control['kind'] && in_array($control['element'], $entries, true) ) {
                    $bounds[spl_object_id($control['element'])] = $control['bound'];
                }
            }
            if ( count($bounds) === count($entries) && in_array('min', $bounds, true) && in_array('max', $bounds, true) ) {
                return true;
            }
        }
        return false;
    }

    /** @return list<array<string,mixed>> */
    private function resultCounts(DOMElement $scope, DOMElement $listing, int $cardCount): array
    {
        $counts = array();
        foreach ( $scope->getElementsByTagName('*') as $element ) {
            if ( ! $element instanceof DOMElement || SourceDom::childElementCount($element) > 0 || $this->contains($listing, $element) || in_array(strtolower($element->tagName), array( 'option', 'button', 'label', 'script', 'style' ), true) || $this->inSiblingListingBranch($listing, $element) ) {
                continue;
            }
            if ( preg_match('/^(\d+)\s+\p{L}+(?:\s+\p{L}+)?$/u', $this->text($element), $match) && (int) $match[1] === $cardCount ) {
                $counts[] = array('kind' => 'result_count', 'element' => $element, 'label' => $this->text($element));
            }
        }

        return $counts;
    }

    /** @param array<string,mixed> $control */
    private function controlKey(array $control): string
    {
        return $control['kind'] . ':' . ($control['key'] ?? (isset($control['field']) ? $control['field'] . ':' . $control['bound'] : ''));
    }

    private function containsChrome(DOMElement $region): bool
    {
        foreach ( array_merge(array($region), iterator_to_array($region->getElementsByTagName('*'), false)) as $element ) {
            if ( in_array(ShellLandmarkPolicy::landmarkKind($element->tagName, SourceDom::attr($element, 'role')), array('header', 'footer', 'nav'), true) ) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private function cardTokens(DOMElement $card): array
    {
        $tokens = array();
        foreach ( array_merge(array($card), iterator_to_array($card->getElementsByTagName('*'), false)) as $element ) {
            if ( ! $element instanceof DOMElement ) {
                continue;
            }
            if ( SourceDom::childElementCount($element) === 0 ) {
                $tokens[] = $this->normalize($this->text($element));
            }
            foreach ( SourceDom::classNames($element) as $class ) {
                $segments = preg_split('/[-_]+/', strtolower($class)) ?: array();
                if ( count($segments) >= 2 ) {
                    array_push($tokens, ...$segments);
                }
            }
            foreach ( $element->attributes ?? array() as $attribute ) {
                if ( str_starts_with($attribute->nodeName, 'data-') ) {
                    $tokens[] = $this->normalize($attribute->nodeValue ?? '');
                }
            }
        }

        return array_values(array_unique(array_filter($tokens, static fn (string $token): bool => '' !== $token)));
    }

    private function controlLabel(DOMElement $control): string
    {
        // Only a group's own disclosure/fieldset supplies its caption. Never
        // use a broad ancestor's text as a label.
        for ( $node = $control; $node instanceof DOMElement; $node = $node->parentNode ) {
            $tag = strtolower($node->tagName);
            if ( in_array($tag, array('form', 'body', 'main', 'section'), true) ) {
                break;
            }
            if ( in_array($tag, array('fieldset', 'details'), true) ) {
                foreach ( $node->childNodes as $child ) {
                    if ( $child instanceof DOMElement && in_array(strtolower($child->tagName), array('legend', 'summary'), true) ) {
                        return $this->text($child);
                    }
                }
                break;
            }
        }
        $label = SourceDom::associatedLabel($control, true);
        if ( null !== $label ) {
            // A wrapping label's caption excludes the control (and its options).
            $caption = $label->cloneNode(true);
            if ( $caption instanceof DOMElement ) {
                foreach ( iterator_to_array($caption->getElementsByTagName(strtolower($control->tagName))) as $nested ) {
                    $nested->parentNode?->removeChild($nested);
                }
                return $this->text($caption);
            }
        }
        $document = $control->ownerDocument;
        foreach ( SourceDom::tokens(SourceDom::attr($control, 'aria-labelledby')) as $id ) {
            if ( '' === $id ) {
                continue;
            }
            $labelElement = '' !== $id && null !== $document ? $document->getElementById($id) : null;
            if ( null === $labelElement && null !== $document ) {
                foreach ( $document->getElementsByTagName('*') as $candidate ) {
                    if ( $candidate instanceof DOMElement && $id === SourceDom::attr($candidate, 'id') ) {
                        $labelElement = $candidate;
                        break;
                    }
                }
            }
            if ( $labelElement instanceof DOMElement ) {
                return $this->text($labelElement);
            }
        }

        return trim(SourceDom::attr($control, 'aria-label'));
    }

    /**
     * A form that asks for the visitor's details (email, phone, password, a file,
     * or free text) submits answers; its choices never filter a listing.
     */
    private function inSubmissionForm(DOMElement $element): bool
    {
        for ( $form = $element->parentNode; $form instanceof DOMElement && 'form' !== strtolower($form->tagName); $form = $form->parentNode );
        if ( ! $form instanceof DOMElement ) {
            return false;
        }
        if ( $form->getElementsByTagName('textarea')->length > 0 ) {
            return true;
        }
        foreach ( $form->getElementsByTagName('input') as $input ) {
            if ( in_array(strtolower(SourceDom::attr($input, 'type')), array('email', 'tel', 'password', 'file'), true) ) {
                return true;
            }
        }
        return false;
    }

    /** Search suggestions are not the page's browsable collection. */
    public static function inSearchContext(DOMElement $element): bool
    {
        for ( $node = $element; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( 'search' === strtolower(SourceDom::attr($node, 'role')) || 'search' === strtolower($node->tagName)
                || preg_match('/(?:^|[\s_-])search(?:[\s_-]|$)/i', SourceDom::attr($node, 'id') . ' ' . SourceDom::attr($node, 'class')) ) {
                return true;
            }
        }
        return false;
    }

    private function commonAncestor(DOMElement $a, DOMElement $b): DOMElement
    {
        for ( $node = $a; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( $node === $b || $this->contains($node, $b) ) {
                return $node;
            }
        }

        return $a;
    }

    private function contains(DOMElement $ancestor, DOMElement $element): bool
    {
        for ( $node = $element->parentNode; null !== $node; $node = $node->parentNode ) {
            if ( $node === $ancestor ) {
                return true;
            }
        }

        return false;
    }

    private function text(DOMElement $element): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($element->textContent ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private function normalize(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    }
}

<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

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
 * - `range`: an `input[type=range]` whose bounds contain every card's price.
 * - `sort`: a `select` whose options all read as orderings of the cards.
 * - `result_count`: a short text whose number is the card count.
 *
 * The region is the nearest common ancestor of the listing and its controls.
 * A candidate is claimed only when it does not sit in a sibling branch that
 * holds another listing of the same shape — on a page that repeats one
 * category-group shape, each group's controls are its own.
 * Everything here is provider-neutral evidence: what each control does to
 * the cards and which card carries which value.
 */
final class ListingControlsRecognizer
{
    private const BOUNDARY_TAGS = array( 'body', 'main', 'header', 'footer', 'nav', 'html' );
    private const SORT_KEYS = array( 'default', 'popularity', 'rating', 'newest', 'price_asc', 'price_desc', 'name_asc', 'name_desc' );

    /**
     * @param list<DOMElement> $cards
     * @param list<float|null> $prices Card prices aligned with `$cards`.
     * @return array{region: DOMElement, controls: list<array<string,mixed>>, card_facets: list<array<string,string>>}|null
     */
    public function recognize(DOMElement $listing, array $cards, array $prices): ?array
    {
        $scope = $listing;
        for ( $node = $listing->parentNode; $node instanceof DOMElement && ! in_array(strtolower($node->tagName), self::BOUNDARY_TAGS, true); $node = $node->parentNode ) {
            $scope = $node;
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
            $sort = 'select' === $group['shape'] ? $this->sortOptions($group['options']) : null;
            if ( null !== $sort ) {
                $controls[] = array('kind' => 'sort', 'element' => $group['element'], 'label' => $this->controlLabel($group['element']), 'options' => $sort);
                $claimed[spl_object_id($group['element'])] = true;
                continue;
            }
            $facet = $this->facet($group, $cardTokens);
            if ( null === $facet ) {
                continue;
            }
            foreach ( $facet['card_values'] as $cardIndex => $value ) {
                $cardFacets[$cardIndex][$facet['key']] = $value;
            }
            unset($facet['card_values']);
            $controls[] = $facet;
            $claimed[spl_object_id($group['element'])] = true;
        }

        $range = $this->priceRange($scope, $listing, $prices);
        if ( null !== $range ) {
            $controls[] = $range;
        }

        $count = $this->resultCount($scope, $listing, count($cards));
        if ( null !== $count ) {
            $controls[] = $count;
        }

        if ( array() === $controls ) {
            return null;
        }

        $region = $listing;
        foreach ( $controls as $control ) {
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
            if ( ! $element instanceof DOMElement || $this->contains($listing, $element) ) {
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
                $radios[SourceDom::attr($element, 'name')][] = array('element' => $element, 'option' => array('value' => SourceDom::attr($element, 'value'), 'label' => null !== $label ? $this->text($label) : SourceDom::attr($element, 'value')));
                continue;
            }
            $buttons = $this->dataKeyedButtons($element);
            if ( null !== $buttons && ! $this->inSiblingListingBranch($listing, $element) ) {
                $groups[] = array('shape' => 'buttons', 'element' => $element, 'options' => $buttons);
            }
        }
        foreach ( $radios as $members ) {
            if ( count($members) < 2 ) {
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
            $groups[] = array('shape' => $shape, 'element' => $container, 'options' => array_column($members, 'option'));
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
        // Explicit checkbox filters remain meaningful on a one-item or empty
        // collection, where partitioning two cards is impossible.
        $explicitFacet = 'checkboxes' === $group['shape'];
        if ( ! $explicitFacet && ($matchedOptions < 2 || count($cardValues) * 2 < count($cardTokens)) ) {
            return null;
        }
        $label = $this->controlLabel($group['element']);
        $key = trim((string) preg_replace('/[^a-z0-9]+/', '_', $this->normalize('' !== $label ? $label : 'facet')), '_');
        ksort($cardValues);

        return array(
            'kind'        => 'facet',
            'element'     => $group['element'],
            'shape'       => $group['shape'],
            'key'         => '' !== $key ? $key : 'facet',
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
        foreach ( $branch->getElementsByTagName('*') as $element ) {
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
     * @return list<array{value: string, label: string, order: string}>|null
     */
    private function sortOptions(array $options): ?array
    {
        $sorted = array();
        foreach ( $options as $option ) {
            $order = $this->sortOrder($option['label'] . ' ' . $option['value']);
            if ( null === $order ) {
                return null;
            }
            $sorted[] = $option + array('order' => $order);
        }
        $orders = array_column($sorted, 'order');
        if ( count(array_unique($orders)) < 2 || count(array_intersect($orders, array( 'price_asc', 'price_desc', 'name_asc', 'name_desc', 'newest' ))) < 1 ) {
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
            (bool) preg_match('/\bname\b|\ba\W*(to|-|→)\W*z\b|alphabet/', $text) => 'name_asc',
            (bool) preg_match('/\b(new|newest|latest|recent|date)\b/', $text) => 'newest',
            (bool) preg_match('/\b(popular|popularity|best.?sell\w*|trending)\b/', $text) => 'popularity',
            (bool) preg_match('/\b(rating|rated|reviews?)\b/', $text) => 'rating',
            (bool) preg_match('/\b(featured|default|relevance|recommended)\b/', $text) => 'default',
            default => null,
        };

        return in_array($order, self::SORT_KEYS, true) ? $order : null;
    }

    /**
     * @param list<float|null> $prices
     * @return array<string,mixed>|null
     */
    private function priceRange(DOMElement $scope, DOMElement $listing, array $prices): ?array
    {
        $known = array_values(array_filter($prices, 'is_float'));
        if ( array() === $known ) {
            return null;
        }
        foreach ( $scope->getElementsByTagName('input') as $input ) {
            if ( ! $input instanceof DOMElement || 'range' !== strtolower(SourceDom::attr($input, 'type')) || $this->contains($listing, $input) || $this->inSiblingListingBranch($listing, $input) ) {
                continue;
            }
            $min = SourceDom::attr($input, 'min');
            $max = SourceDom::attr($input, 'max');
            if ( ! is_numeric($min) || ! is_numeric($max) || (float) $min > min($known) || (float) $max < max($known) ) {
                continue;
            }
            $label = $this->controlLabel($input);

            return array(
                'kind'    => 'range',
                'element' => $input,
                'field'   => 'price',
                'label'   => $label,
                'bound'   => preg_match('/\b(min|from|over|at least)\b/i', $label) ? 'min' : 'max',
                'min'     => (float) $min,
                'max'     => (float) $max,
            );
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    private function resultCount(DOMElement $scope, DOMElement $listing, int $cardCount): ?array
    {
        foreach ( $scope->getElementsByTagName('*') as $element ) {
            if ( ! $element instanceof DOMElement || SourceDom::childElementCount($element) > 0 || $this->contains($listing, $element) || in_array(strtolower($element->tagName), array( 'option', 'button', 'label', 'script', 'style' ), true) || $this->inSiblingListingBranch($listing, $element) ) {
                continue;
            }
            if ( preg_match('/^(\d+)\s+\p{L}+(?:\s+\p{L}+)?$/u', $this->text($element), $match) && (int) $match[1] === $cardCount ) {
                return array('kind' => 'result_count', 'element' => $element, 'label' => $this->text($element));
            }
        }

        return null;
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
        if ( 'fieldset' === strtolower($control->tagName) ) {
            foreach ( $control->childNodes as $child ) {
                if ( $child instanceof DOMElement && 'legend' === strtolower($child->tagName) ) {
                    return $this->text($child);
                }
            }
        }
        $label = SourceDom::associatedLabel($control);
        if ( null !== $label ) {
            return $this->text($label);
        }
        $document = $control->ownerDocument;
        foreach ( preg_split('/\s+/', trim(SourceDom::attr($control, 'aria-labelledby'))) ?: array() as $id ) {
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

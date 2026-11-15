<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CommerceStructureRecognizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

final class SourceElementDescription
{
    public function __construct(
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly CommerceStructureRecognizer $commercePattern
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function sourceContext(DOMElement $element, bool $includeStructureSignals = true, bool $retainLinkTabindex = false): array
    {
        return array_filter(array(
            'selector'                => SourceDom::elementSelector($element),
            'parent_tag'              => $element->parentNode instanceof DOMElement && 'body' !== strtolower($element->parentNode->tagName) ? strtolower($element->parentNode->tagName) : '',
            'ancestor_tags'           => SourceDom::ancestorTags($element),
            'nearest_heading'         => SourceDom::nearestPreviousHeadingText($element),
            'role'                    => SourceDom::attr($element, 'role'),
            'id'                      => SourceDom::attr($element, 'id'),
            'class_names'             => SourceDom::classNames($element),
            'ancestor_class_names'    => $this->ancestorClassNames($element),
            'data_attributes'         => SourceDom::safeDataAttributes($element),
            'structure_signals'       => $includeStructureSignals ? $this->structureSignals($element, array()) : array(),
            'interactive_attributes'  => $this->interactiveAttributes($element, $retainLinkTabindex),
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

    /**
     * @return array<string, bool|string>
     */
    public function interactiveAttributes(DOMElement $element, bool $retainLinkTabindex = false): array
    {
        $tabindex = SourceDom::attr($element, 'tabindex');
        // A link with href already participates in sequential focus order.
        // Explicit zero adds no behavior that needs a retained media boundary.
        if ( ! $retainLinkTabindex && '0' === trim($tabindex) && 'a' === strtolower($element->tagName) && $element->hasAttribute('href') ) {
            $tabindex = '';
        }
        return array_filter(array(
            'tabindex'      => $tabindex,
            'aria-expanded' => SourceDom::attr($element, 'aria-expanded'),
            'aria-controls' => SourceDom::attr($element, 'aria-controls'),
            'has_events'    => array() !== SourceDom::eventMetadata($element),
        ), static fn (mixed $value): bool => false !== $value && '' !== $value);
    }

    /**
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    public function structureSignals(DOMElement $element, array $attrs = array()): array
    {
        $className = strtolower(trim(SourceDom::attr($element, 'class') . ' ' . (string) ($attrs['className'] ?? '')));
        $declarations = SourceDeclarations::parse(SourceDom::attr($element, 'style') . ';' . ArrayField::string($attrs, 'style'));
        $signals = array();

        if ( preg_match('/(?:^|[\s_-])(?:card|feature|service|provider|resource|post|project|stat|badge|tile|panel|item)(?:$|[\s_-])/', $className) || 'article' === strtolower($element->tagName) ) {
            $signals['card_like'] = true;
        }
        if ( preg_match('/(?:^|[\s_-])(?:cards|features|services|providers|testimonials|resources|posts|projects|stats|badges|grid|grid-[0-9]+|tiles|columns|collection|gallery)(?:$|[\s_-])/', $className) || 'grid' === CssValueInspector::declared($declarations, 'display') || isset($declarations['grid-template-columns']) ) {
            $signals['grid_like'] = true;
        }
        if ( preg_match('/(?:^|[\s_-])(?:hero|masthead|intro|banner|container|wrap|wrapper|inner|shell)(?:$|[\s_-])/', $className) ) {
            $signals['section_container_like'] = true;
        }
        if ( $this->sourceElementClassifier->isVisualLayerElement($element) ) {
            $signals['visual_layer'] = true;
        }
        if ( $this->sourceElementClassifier->hasCommerceToken($element, array( 'badge', 'featured', 'popular', 'recommended' )) ) {
            $signals['featured_badge_like'] = true;
        }
        $priceText = 'div' === strtolower($element->tagName) ? $this->directTextContent($element) : ($element->textContent ?? '');
        if ( $this->sourceElementClassifier->hasCommerceToken($element, array( 'price', 'pricing', 'amount', 'cost' )) || $this->commercePattern->looksLikePriceText($priceText) ) {
            $signals['price_like'] = true;
        }
        if ( $this->sourceElementClassifier->hasCommerceToken($element, array( 'product', 'menu', 'dish', 'plan', 'tier', 'name', 'title' )) ) {
            $signals['commerce_content_like'] = true;
        }
        if ( $this->looksLikeNamePriceRow($element) ) {
            $signals['name_price_row'] = true;
        }

        $itemCount = $this->cardLikeChildCount($element);
        if ( 1 < $itemCount ) {
            $signals['repeated_card_children'] = $itemCount;
        }

        return $signals;
    }

    private function cardLikeChildCount(DOMElement $element): int
    {
        $itemCount = 0;
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && $this->sourceElementClassifier->isCardLikeElement($child) ) {
                ++$itemCount;
            }
        }

        return $itemCount;
    }

    private function directTextContent(DOMElement $element): string
    {
        $text = '';
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                $text .= ' ' . ($child->textContent ?? '');
            }
        }
        return trim($text);
    }

    private function looksLikeNamePriceRow(DOMElement $element): bool
    {
        return null !== $this->namePriceChildren($element);
    }

    /**
     * @return array<int, DOMElement>|null
     */
    public function namePriceChildren(DOMElement $element): ?array
    {
        if ( ! in_array(strtolower($element->tagName), array( 'div', 'header', 'section' ), true) ) {
            return null;
        }

        $children = array();
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }
            if ( ! $child instanceof DOMElement ) {
                return null;
            }
            if ( ! $this->sourceElementClassifier->isInlineCommerceRowChild($child) ) {
                return null;
            }
            $children[] = $child;
        }

        if ( 2 !== count($children) ) {
            return null;
        }

        $first = $children[0];
        $second = $children[1];
        $firstIsPrice = $this->isPriceElement($first);
        $secondIsPrice = $this->isPriceElement($second);
        if ( $firstIsPrice !== $secondIsPrice ) {
            $other = $firstIsPrice ? $second : $first;
            if ( $this->sourceElementClassifier->isNameElement($other) || $this->sourceElementClassifier->hasCommerceToken($element, array( 'menu', 'product', 'pricing', 'price', 'plan', 'tier', 'dish', 'item', 'row' )) ) {
                return $children;
            }
        }

        if ( $this->looksLikeHoursRow($element, $first, $second) ) {
            return $children;
        }

        if ( $this->looksLikeLabelValueRow($element, $first, $second) ) {
            return $children;
        }

        return null;
    }

    private function isPriceElement(DOMElement $element): bool
    {
        return $this->commercePattern->isPriceElement($element);
    }

    private function looksLikeHoursRow(DOMElement $row, DOMElement $first, DOMElement $second): bool
    {
        if ( ! $this->sourceElementClassifier->hasCommerceToken($row, array( 'hours', 'hour', 'schedule', 'time', 'row' )) ) {
            return false;
        }

        return ( $this->sourceElementClassifier->isDayElement($first) && $this->sourceElementClassifier->isTimeValueElement($second) )
            || ( $this->sourceElementClassifier->isDayElement($second) && $this->sourceElementClassifier->isTimeValueElement($first) );
    }

    private function looksLikeLabelValueRow(DOMElement $row, DOMElement $first, DOMElement $second): bool
    {
        if ( ! $this->sourceElementClassifier->hasCommerceToken($row, array( 'row', 'item', 'pair', 'line', 'entry', 'schedule', 'session', 'meta', 'detail' )) ) {
            return false;
        }

        $firstIsLabel = $this->isLabelValueLabelElement($first);
        $secondIsLabel = $this->isLabelValueLabelElement($second);
        $firstIsValue = $this->sourceElementClassifier->isLabelValueValueElement($first);
        $secondIsValue = $this->sourceElementClassifier->isLabelValueValueElement($second);

        return ( $firstIsLabel && $secondIsValue ) || ( $secondIsLabel && $firstIsValue );
    }

    private function isLabelValueLabelElement(DOMElement $element): bool
    {
        return $this->sourceElementClassifier->hasCommerceToken($element, array( 'label', 'term', 'key', 'day', 'date', 'time', 'hour', 'hours', 'duration' ))
            || 'time' === strtolower($element->tagName)
            || $this->looksLikeDateOrTimeText($element->textContent ?? '');
    }

    private function looksLikeDateOrTimeText(string $text): bool
    {
        return (bool) preg_match('/\b(?:\d{1,2}(?::\d{2})?\s*(?:am|pm)?|\d{1,2}\s*(?:min|mins|minutes|hr|hrs|hours)|mon(?:day)?|tue(?:sday)?|wed(?:nesday)?|thu(?:rsday)?|fri(?:day)?|sat(?:urday)?|sun(?:day)?|day\s+\d+)\b/i', trim($text));
    }

}

<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

final class AccordionPattern implements PatternRecognizerInterface
{
    use PatternDomHelpersTrait;

    public function recognize(DOMElement $element, PatternContext $context): ?PatternRecognitionResult
    {
        $createBlock = $context->createBlock(...);
        $converter = $context->recursiveConverter();
        $presentationAttributes = $context->presentationAttributes(...);
        $innerHtml = $context->innerHtml(...);

        if ( null === $converter || $this->hasRuntimeHeavyDescendant($element) ) {
            return null;
        }

        $itemElements = $this->accordionItemElements($element);
        if ( count($itemElements) < 2 ) {
            return null;
        }

        $fallbacks = array();
        $items = array();
        foreach ( $itemElements as $child ) {
            $item = $this->accordionItem($child, $fallbacks, $innerHtml, $converter, $createBlock, $presentationAttributes);
            if ( null === $item ) {
                return null;
            }
            $items[] = $item;
        }

        if ( count($items) < 2 ) {
            return null;
        }

        return new PatternRecognitionResult(
            $createBlock('core/accordion', $presentationAttributes($element), $items, $element),
            $fallbacks
        );
    }

    /** @param list<array<string, mixed>> $fallbacks */
    private function accordionItem(DOMElement $item, array &$fallbacks, callable $innerHtml, PatternRecursiveConverter $converter, callable $createBlock, callable $presentationAttributes): ?array
    {
        if ( ! $this->isAccordionItemElement($item) || $this->hasRuntimeHeavyDescendant($item) ) {
            return null;
        }

        $title = $this->titleElement($item);
        if ( ! $title instanceof DOMElement ) {
            return null;
        }

        $panel = $this->panelElement($item, $title);
        if ( null !== $panel && $panel->isSameNode($title) ) {
            return null;
        }

        $titleHtml = $this->disclosureLabelHtml($title, $innerHtml);
        if ( '' === trim(strip_tags($titleHtml)) ) {
            return null;
        }

        $panelBlocks = $panel instanceof DOMElement
            ? $converter->children($panel, $fallbacks, true)
            : $converter->childrenWithoutTags($item, $fallbacks, array( 'summary' ));
        if ( array() === $panelBlocks && ! $panel instanceof DOMElement ) {
            return null;
        }

        $headingAttrs = array(
            'title' => $titleHtml,
            'level' => $this->headingLevel($title),
        );

        return $createBlock('core/accordion-item', array_filter(array_merge($presentationAttributes($item), array(
            'openByDefault' => $this->isOpen($item, $title, $panel) ? true : '',
        )), static fn ($value): bool => '' !== $value), array(
            $createBlock('core/accordion-heading', $headingAttrs, array(), $title),
            $createBlock('core/accordion-panel', $panel instanceof DOMElement ? $presentationAttributes($panel) : array(), $panelBlocks, $panel),
        ), $item);
    }

    /**
     * @return list<DOMElement>
     */
    private function accordionItemElements(DOMElement $element): array
    {
        $signalRoots = array( $element );
        $children = $this->directChildElements($element);
        if ( 1 === count($children) ) {
            $signalRoots[] = $children[0];
            $nested = $this->directChildElements($children[0]);
            if ( count($nested) >= 2 ) {
                $children = $nested;
            }
        }

        if ( $this->allDisclosureItemElements($children) ) {
            return $children;
        }

        foreach ( $signalRoots as $root ) {
            if ( $this->hasAccordionSignal($root) && $this->allAccordionItemElements($children) ) {
                return $children;
            }
        }

        return array();
    }

    private function hasAccordionSignal(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        $class = strtolower($this->trimmedAttribute($element, 'class'));
        $role = strtolower($this->trimmedAttribute($element, 'role'));

        return str_contains($class, 'accordion')
            || str_contains($class, 'faq')
            || 'accordion' === $role
            || in_array($tagName, array( 'section', 'div', 'ul', 'ol' ), true) && str_contains(strtolower($this->trimmedAttribute($element, 'aria-label')), 'faq');
    }

    /**
     * @param list<DOMElement> $elements
     */
    private function allDisclosureItemElements(array $elements): bool
    {
        if ( count($elements) < 2 ) {
            return false;
        }

        foreach ( $elements as $element ) {
            if ( 'details' === strtolower($element->tagName) ) {
                continue;
            }

            $title = $this->titleElement($element);
            if ( ! $title instanceof DOMElement || ! $this->isDisclosureTitle($title) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<DOMElement> $elements
     */
    private function allAccordionItemElements(array $elements): bool
    {
        if ( count($elements) < 2 ) {
            return false;
        }

        foreach ( $elements as $element ) {
            if ( ! $this->isAccordionItemElement($element) ) {
                return false;
            }
        }

        return true;
    }

    private function isAccordionItemElement(DOMElement $element): bool
    {
        if ( 'details' === strtolower($element->tagName) ) {
            return true;
        }

        $class = strtolower($this->trimmedAttribute($element, 'class'));
        if ( str_contains($class, 'item')
            || str_contains($class, 'accordion')
            || str_contains($class, 'faq')
            || str_contains($class, 'question')
        ) {
            return true;
        }

        $title = $this->titleElement($element);

        return $title instanceof DOMElement && $this->isDisclosureTitle($title);
    }

    private function isDisclosureTitle(DOMElement $title): bool
    {
        $tagName = strtolower($title->tagName);

        return 'summary' === $tagName
            || $title->hasAttribute('aria-expanded')
            || $title->hasAttribute('aria-controls');
    }

    private function titleElement(DOMElement $item): ?DOMElement
    {
        foreach ( $this->directChildElements($item) as $child ) {
            $title = $this->asTitleElement($child);
            if ( $title instanceof DOMElement ) {
                return $title;
            }

            if ( ! in_array(strtolower($child->tagName), array( 'div', 'span', 'header' ), true) ) {
                continue;
            }

            foreach ( $this->directChildElements($child) as $grandchild ) {
                $title = $this->asTitleElement($grandchild);
                if ( $title instanceof DOMElement ) {
                    return $title;
                }
            }
        }

        return null;
    }

    private function asTitleElement(DOMElement $element): ?DOMElement
    {
        $tagName = strtolower($element->tagName);
        if ( 'summary' === $tagName || 'button' === $tagName || preg_match('/^h[1-6]$/', $tagName) ) {
            return $element;
        }

        if ( $this->isDisclosureTitle($element) ) {
            return $element;
        }

        $class = strtolower($this->trimmedAttribute($element, 'class'));
        if ( str_contains($class, 'title') || str_contains($class, 'heading') || str_contains($class, 'question') || str_contains($class, 'trigger') ) {
            return $element;
        }

        return null;
    }

    private function panelElement(DOMElement $item, DOMElement $title): ?DOMElement
    {
        $controlledId = $this->trimmedAttribute($title, 'aria-controls');
        if ( '' !== $controlledId ) {
            foreach ( $item->getElementsByTagName('*') as $candidate ) {
                if ( $candidate instanceof DOMElement && $candidate->getAttribute('id') === $controlledId ) {
                    return $candidate;
                }
            }
        }

        foreach ( $this->directChildElements($item) as $child ) {
            if ( $child->isSameNode($title) ) {
                continue;
            }

            $class = strtolower($this->trimmedAttribute($child, 'class'));
            $role = strtolower($this->trimmedAttribute($child, 'role'));
            if ( 'region' === $role || str_contains($class, 'panel') || str_contains($class, 'content') || str_contains($class, 'body') || str_contains($class, 'answer') ) {
                return $child;
            }
        }

        return null;
    }

    private function headingLevel(DOMElement $title): int
    {
        return preg_match('/^h([1-6])$/', strtolower($title->tagName), $matches) ? (int) $matches[1] : 3;
    }

    private function isOpen(DOMElement $item, DOMElement $title, ?DOMElement $panel): bool
    {
        if ( $item->hasAttribute('open') || 'true' === strtolower($this->trimmedAttribute($title, 'aria-expanded')) ) {
            return true;
        }

        foreach ( array_filter(array( $item, $title, $panel )) as $element ) {
            if ( $element instanceof DOMElement && preg_match('/(?:^|\s)(?:active|open|is-active|is-open|expanded)(?:\s|$)/i', $this->trimmedAttribute($element, 'class')) ) {
                return true;
            }
        }

        return false;
    }

}

<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Closure;
use DOMElement;

/**
 * Reads the shape of a source list: its members, item wrappers, nested
 * lists, and whether its items carry structure core/list cannot hold.
 */
final class ListStructure
{
    /**
     * @param (Closure(DOMElement): array<string, array<string, mixed>>)|null $boxTreeEvidence
     *        Captured per-viewport observations of a source element, when the
     *        route carries box-tree evidence.
     */
    public function __construct(
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly ?Closure $boxTreeEvidence = null
    ) {
    }

    /**
     * The element members of a list, with presentational item wrappers
     * flattened. Component libraries emit `ul > div > li` (one div per
     * carousel or ticker slot); browsers render those items as list items,
     * so each wrapped `li` is a member of the list like a direct child.
     *
     * @return list<DOMElement>
     */
    public function listMemberElements(DOMElement $list): array
    {
        $members = array();
        foreach ( $list->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            if ( ! $this->isListItemWrapper($child) ) {
                $members[] = $child;
                continue;
            }
            foreach ( $child->childNodes as $wrapped ) {
                if ( $wrapped instanceof DOMElement ) {
                    $members[] = $wrapped;
                }
            }
        }

        return $members;
    }

    /** Whether a list child is a `div` holding only list items and whitespace. */
    public function isListItemWrapper(DOMElement $element): bool
    {
        $parent = $element->parentNode;
        if ( 'div' !== strtolower($element->tagName)
            || ! $parent instanceof DOMElement
            || ! in_array(strtolower($parent->tagName), array( 'ul', 'ol', 'menu' ), true)
        ) {
            return false;
        }
        $hasItem = false;
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && 'li' === strtolower($child->tagName) ) {
                $hasItem = true;
                continue;
            }
            if ( XML_COMMENT_NODE === $child->nodeType || ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) ) {
                continue;
            }
            return false;
        }

        return $hasItem;
    }

    /** @return list<DOMElement> */
    public function nestedListRoots(DOMElement $item): array
    {
        $lists = array();
        foreach ( $item->childNodes as $child ) {
            if ( $child instanceof DOMElement && in_array(strtolower($child->tagName), array( 'ul', 'ol', 'menu' ), true) ) {
                $lists[] = $child;
            }
        }

        return $lists;
    }

    /**
     * Whether a non-item child of a list carries content a reader would see.
     * Scripts, templates and empty wrappers are not content.
     */
    public function listNonItemChildCarriesContent(DOMElement $child): bool
    {
        $tagName = strtolower($child->tagName);
        if ( in_array($tagName, array( 'script', 'style', 'template', 'link', 'meta' ), true) ) {
            return false;
        }

        if ( '' !== trim($child->textContent ?? '') ) {
            return true;
        }

        foreach ( array( 'img', 'svg', 'video', 'audio', 'iframe', 'canvas', 'picture', 'input', 'select', 'textarea', 'button' ) as $embedded ) {
            if ( 0 < $child->getElementsByTagName($embedded)->length ) {
                return true;
            }
        }

        return in_array($tagName, array( 'img', 'svg', 'video', 'audio', 'iframe', 'canvas', 'picture', 'input', 'select', 'textarea', 'button' ), true);
    }

    public function listContainsStructuralItemContent(DOMElement $list): bool
    {
        foreach ( $this->listMemberElements($list) as $child ) {
            if ( ! $child instanceof DOMElement || 'li' !== strtolower($child->tagName) ) {
                // A source that puts content directly in a list still renders
                // it. core/list only carries list items, so such a list has to
                // decompose or that content is dropped on the floor.
                if ( $child instanceof DOMElement && $this->listNonItemChildCarriesContent($child) ) {
                    return true;
                }
                continue;
            }

            // A paragraph the capture proves renders as its list item's own
            // text is the item's content, not structure inside it.
            $paragraph = $this->neutralItemParagraph($child);
            $content   = null !== $paragraph ? $paragraph->cloneNode(true) : $child->cloneNode(true);
            if ( ! $content instanceof DOMElement ) {
                continue;
            }

            foreach ( $this->nestedListRoots($content) as $nestedList ) {
                $content->removeChild($nestedList);
            }

            foreach ( $content->getElementsByTagName('*') as $descendant ) {
                if ( ! $descendant instanceof DOMElement ) {
                    continue;
                }

                $tagName = strtolower($descendant->tagName);
                if ( 'a' !== $tagName && 'br' !== $tagName && ! $this->sourceElementClassifier->isInlineContentElement($tagName) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The sole paragraph of a list item when captured evidence proves it is
     * render-neutral there: it carries no authored attributes, no box of its
     * own (zero margins and padding, nothing painted) and the item's
     * geometry and text styles at every captured viewport. Such an item is
     * `<li>text</li>` to a reader, so core/list-item holds it. Without
     * box-tree evidence the paragraph's margins are unproven and it stays.
     */
    public function neutralItemParagraph(DOMElement $item): ?DOMElement
    {
        if ( null === $this->boxTreeEvidence || 'li' !== strtolower($item->tagName) ) {
            return null;
        }
        $paragraph = null;
        foreach ( $item->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                if ( null !== $paragraph || 'p' !== strtolower($child->tagName) ) {
                    return null;
                }
                $paragraph = $child;
                continue;
            }
            if ( XML_TEXT_NODE === $child->nodeType && '' !== trim($child->textContent ?? '') ) {
                return null;
            }
        }
        if ( null === $paragraph || array() !== BoxTree::authoredAttributes($paragraph) ) {
            return null;
        }

        $itemObservations      = ($this->boxTreeEvidence)($item);
        $paragraphObservations = ($this->boxTreeEvidence)($paragraph);
        if ( array() === $itemObservations || array_keys($itemObservations) !== array_keys($paragraphObservations) ) {
            return null;
        }
        foreach ( $itemObservations as $viewport => $observation ) {
            $own       = $paragraphObservations[$viewport];
            $itemStyle = $observation['used_styles'] ?? array();
            $style     = $own['used_styles'] ?? array();
            if ( ! empty($own['pseudo']) || 'block' !== ($style['display'] ?? null) || 'static' !== ($style['position'] ?? null) ) {
                return null;
            }
            foreach ( array( 'margin', 'padding' ) as $box ) {
                foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
                    if ( '0px' !== ($style[$box . '-' . $side] ?? null) ) {
                        return null;
                    }
                }
            }
            foreach ( array( 'border-top', 'border-right', 'border-bottom', 'border-left' ) as $border ) {
                if ( ! str_starts_with((string) ($style[$border] ?? ''), '0px') ) {
                    return null;
                }
            }
            foreach ( array( 'background-color' => 'rgba(0, 0, 0, 0)', 'background-image' => 'none', 'box-shadow' => 'none', 'transform' => 'none', 'filter' => 'none', 'opacity' => '1' ) as $property => $neutral ) {
                if ( $neutral !== ($style[$property] ?? null) ) {
                    return null;
                }
            }
            foreach ( array_merge(BoxTree::INHERITED_PROPERTIES, array( 'text-decoration-line', 'text-decoration-color', 'text-decoration-style', 'vertical-align' )) as $property ) {
                if ( ($style[$property] ?? null) !== ($itemStyle[$property] ?? null) ) {
                    return null;
                }
            }
            foreach ( array( 'x', 'y', 'width', 'height' ) as $axis ) {
                if ( ! isset($own['border_box'][$axis], $observation['border_box'][$axis]) || abs((float) $own['border_box'][$axis] - (float) $observation['border_box'][$axis]) > 0.01 ) {
                    return null;
                }
            }
        }

        return $paragraph;
    }

    public function isStructuralListItem(DOMElement $item): bool
    {
        $list = $item->parentNode;
        if ( $list instanceof DOMElement && $this->isListItemWrapper($list) ) {
            $list = $list->parentNode;
        }
        return $list instanceof DOMElement
            && in_array(strtolower($list->tagName), array( 'ul', 'ol', 'menu' ), true)
            && $this->listContainsStructuralItemContent($list);
    }
}

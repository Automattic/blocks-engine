<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextMaterializer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorLayoutOwnership;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMElement;

/**
 * Lowers the members of a source list into core/list-item blocks, with
 * nested lists as inner blocks.
 */
final class ListItemLowering
{
    public function __construct(
        private readonly SourceBlockCreator $blocks,
        private readonly PatternTreeConverter $tree,
        private readonly Runtime $runtime,
        private readonly HtmlTransformerSession $session,
        private readonly StyleResolver $styleResolver,
        private readonly ListStructure $listStructure,
        private readonly RichTextMaterializer $richTextMaterializer,
        private readonly WrapperCoalescer $wrapperCoalescer,
        private readonly AuthorLayoutOwnership $authorLayoutOwnership
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks @return array<string, mixed> */
    public function decomposeStructuralList(DOMElement $list, array &$fallbacks): array
    {
        $items = array();
        foreach ( $this->listStructure->listMemberElements($list) as $child ) {
            if ( 'li' !== strtolower($child->tagName) ) {
                if ( ! $this->listStructure->listNonItemChildCarriesContent($child) ) {
                    continue;
                }

                $block = $this->tree->element($child, $fallbacks, true);
                if ( null !== $block ) {
                    $items[] = $block;
                }
                continue;
            }

            $children = $this->tree->children($child, $fallbacks, true);
            if ( array() === $children ) {
                continue;
            }
            if ( 1 === count($children) ) {
                $coalesced = $this->wrapperCoalescer->coalescedSingleGroupWrapper($child, $children[0]);
                if ( null !== $coalesced ) {
                    $items[] = $coalesced;
                    continue;
                }
            }
            $items[] = $this->blocks->createBlock(
                'core/group',
                array_merge($this->authorLayoutOwnership->cssOwnedGroupAttributes($child), array( 'tagName' => 'li' )),
                $children,
                $child
            );
        }

        return $this->blocks->createBlock(
            'core/group',
            // This list is already materializing as a Group, so carrying its own
            // alignment cannot introduce the extra topology avoided by the
            // generic presentation resolver's text-align gate.
            array_merge($this->authorLayoutOwnership->cssOwnedGroupAttributes($list, true), array( 'tagName' => strtolower($list->tagName) )),
            $items,
            $list
        );
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<int, array<string, mixed>>
     */
    public function listItems(DOMElement $list, array &$fallbacks): array
    {
        $items = array();
        foreach ( $this->listStructure->listMemberElements($list) as $child ) {
            if ( 'li' !== strtolower($child->tagName) ) {
                continue;
            }

            $nested = array();
            foreach ( $this->listStructure->nestedListRoots($child) as $nestedList ) {
                $nestedBlock = $this->tree->element($nestedList, $fallbacks, true);
                if ( null !== $nestedBlock ) {
                    $nested[] = $nestedBlock;
                }
            }

            $content = $this->listItemContentWithoutNestedLists($child);
            if ( '' === trim($this->runtime->stripAllTags($content)) && array() === $nested ) {
                continue;
            }

            $items[] = $this->blocks->createBlock('core/list-item', array_merge($this->styleResolver->presentationAttributes($child), array( 'content' => $content )), $nested, $child);
        }

        return $items;
    }

    private function listItemContentWithoutNestedLists(DOMElement $item): string
    {
        $content = $item->cloneNode(true);
        if ( ! $content instanceof DOMElement ) {
            return SourceDom::innerHtmlWithoutTags($item, array( 'ul', 'ol', 'menu' ));
        }

        $directLists = array();
        foreach ( $content->childNodes as $child ) {
            if ( $child instanceof DOMElement && in_array(strtolower($child->tagName), array( 'ul', 'ol', 'menu' ), true) ) {
                $directLists[] = $child;
            }
        }
        foreach ( $directLists as $directList ) {
            $content->removeChild($directList);
        }

        // Wrapped rich HTML remains inside core/list-item content so its authored
        // topology survives. Materialize every source-tag marker that author CSS
        // rewrites, not just nested list leaves.
        foreach ( $content->getElementsByTagName('*') as $descendant ) {
            if ( ! $descendant instanceof DOMElement ) {
                continue;
            }
            $marker = $this->session->authorSelectorProjectionState()->tagMarker($descendant->tagName);
            if ( '' !== $marker ) {
                $descendant->setAttribute('class', SourceDom::mergeClassNames(SourceDom::attr($descendant, 'class'), $marker));
            }
        }

        return $this->richTextMaterializer->content($content);
    }
}

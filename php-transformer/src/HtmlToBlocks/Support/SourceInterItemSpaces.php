<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use DOMElement;

final class SourceInterItemSpaces
{
    public function __construct(
        private readonly StyleResolver $styleResolver,
        private readonly SourceElementClassifier $sourceElementClassifier
    ) {
    }

    /**
     * Inline-level items that the source separated by collapsible whitespace
     * share their line with one space of the parent's font between each pair.
     * A container block's inner blocks serialize back to back, so the items
     * lost that space. Keep one between their placeholders, where WordPress
     * renders it as the source did. Only adjacent inline-level pairs get one:
     * a block-level sibling (a heading above a row of icon links) breaks the
     * line, and the whitespace beside it collapses in the source as well.
     *
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    public function apply(array $block, ?DOMElement $sourceElement): array
    {
        $innerContent = $block['innerContent'] ?? null;
        if ( ! $sourceElement instanceof DOMElement || ! is_array($innerContent) ) {
            return $block;
        }
        $gaps = $this->spacedSourceGaps($sourceElement, count($block['innerBlocks'] ?? array()));
        if ( array() === $gaps ) {
            return $block;
        }
        $spaced = array();
        $previousWasBlock = false;
        $blockIndex = -1;
        foreach ( $innerContent as $part ) {
            if ( null === $part && $previousWasBlock && isset($gaps[$blockIndex]) ) {
                $spaced[] = ' ';
            }
            $spaced[] = $part;
            $previousWasBlock = null === $part;
            if ( null === $part ) {
                ++$blockIndex;
            }
        }
        $block['innerContent'] = $spaced;
        $block['innerHTML'] = implode('', array_filter($spaced, 'is_string'));

        return $block;
    }

    /**
     * Indexes `i` of source items whose gap to item `i + 1` holds a space the
     * source drew: both items inline-level and in flow, collapsible
     * whitespace between them.
     *
     * @return array<int, true>
     */
    private function spacedSourceGaps(DOMElement $parent, int $innerBlockCount): array
    {
        if ( $innerBlockCount < 2 ) {
            return array();
        }
        // The source markup first: one element per inner block, with only
        // collapsible whitespace (and comments) around and between them.
        // `</li><li>` has no space to give back.
        $items = array();
        $whitespace = array();
        $pending = null;
        foreach ( $parent->childNodes as $node ) {
            if ( $node instanceof DOMElement ) {
                if ( array() !== $items ) {
                    $whitespace[] = '' !== $pending;
                }
                $items[] = $node;
                $pending = '';
            } elseif ( XML_TEXT_NODE === $node->nodeType ) {
                if ( 1 !== preg_match('/^[ \t\n\r\f]*$/', (string) $node->nodeValue) ) {
                    return array();
                }
                $pending .= (string) $node->nodeValue;
            } elseif ( XML_COMMENT_NODE !== $node->nodeType ) {
                return array();
            }
        }
        if ( count($items) !== $innerBlockCount
            || 1 === preg_match('/flex|grid|box|contents|none|table/', $this->styleResolver->resolvedDeclaration($parent, 'display'))
            || ! in_array($this->styleResolver->resolvedDeclaration($parent, 'white-space'), array( '', 'normal', 'nowrap' ), true) ) {
            return array();
        }
        // Then each pair shares the parent's line box: inline-level and in flow.
        $inline = array_map(fn (DOMElement $item): bool => $this->isInFlowInline($item), $items);
        $gaps = array();
        foreach ( $whitespace as $index => $spaced ) {
            if ( $spaced && $inline[$index] && $inline[$index + 1] ) {
                $gaps[$index] = true;
            }
        }

        return $gaps;
    }

    private function isInFlowInline(DOMElement $item): bool
    {
        $display = $this->styleResolver->resolvedDeclaration($item, 'display');
        $inline = '' === $display
            ? $this->sourceElementClassifier->isInlineSourceElement(strtolower($item->tagName)) || in_array(strtolower($item->tagName), array( 'button', 'img', 'input', 'select', 'textarea' ), true)
            : 1 === preg_match('/^inline(?:-[a-z-]+)?$/', $display);

        return $inline
            && in_array($this->styleResolver->resolvedDeclaration($item, 'float'), array( '', 'none' ), true)
            && ! in_array($this->styleResolver->resolvedDeclaration($item, 'position'), array( 'absolute', 'fixed' ), true);
    }
}

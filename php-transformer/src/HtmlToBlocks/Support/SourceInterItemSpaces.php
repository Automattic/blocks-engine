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
     * renders it as the source did.
     *
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    public function apply(array $block, ?DOMElement $sourceElement): array
    {
        $innerContent = $block['innerContent'] ?? null;
        if ( ! $sourceElement instanceof DOMElement || ! is_array($innerContent) || ! $this->sourceChildrenAreSpaceSeparatedInline($sourceElement, count($block['innerBlocks'] ?? array())) ) {
            return $block;
        }
        $spaced = array();
        $previousWasBlock = false;
        foreach ( $innerContent as $part ) {
            if ( null === $part && $previousWasBlock ) {
                $spaced[] = ' ';
            }
            $spaced[] = $part;
            $previousWasBlock = null === $part;
        }
        $block['innerContent'] = $spaced;
        $block['innerHTML'] = implode('', array_filter($spaced, 'is_string'));

        return $block;
    }

    private function sourceChildrenAreSpaceSeparatedInline(DOMElement $parent, int $innerBlockCount): bool
    {
        if ( $innerBlockCount < 2 ) {
            return false;
        }
        // The source markup first: one element per inner block, with only
        // collapsible whitespace (and comments) around them and some between
        // every pair. `</li><li>` has no space to give back.
        $items = array();
        $whitespace = null;
        foreach ( $parent->childNodes as $node ) {
            if ( $node instanceof DOMElement ) {
                if ( array() !== $items && '' === $whitespace ) {
                    return false;
                }
                $items[] = $node;
                $whitespace = '';
            } elseif ( XML_TEXT_NODE === $node->nodeType ) {
                if ( 1 !== preg_match('/^[ \t\n\r\f]*$/', (string) $node->nodeValue) ) {
                    return false;
                }
                $whitespace .= (string) $node->nodeValue;
            } elseif ( XML_COMMENT_NODE !== $node->nodeType ) {
                return false;
            }
        }
        if ( count($items) !== $innerBlockCount
            || 1 === preg_match('/flex|grid|box|contents|none|table/', $this->styleResolver->resolvedDeclaration($parent, 'display'))
            || ! in_array($this->styleResolver->resolvedDeclaration($parent, 'white-space'), array( '', 'normal', 'nowrap' ), true) ) {
            return false;
        }
        // Then each item shares the parent's line box: inline-level and in flow.
        foreach ( $items as $item ) {
            $display = $this->styleResolver->resolvedDeclaration($item, 'display');
            $inline = '' === $display
                ? $this->sourceElementClassifier->isInlineSourceElement(strtolower($item->tagName)) || in_array(strtolower($item->tagName), array( 'button', 'img', 'input', 'select', 'textarea' ), true)
                : 1 === preg_match('/^inline(?:-[a-z-]+)?$/', $display);
            if ( ! $inline
                || ! in_array($this->styleResolver->resolvedDeclaration($item, 'float'), array( '', 'none' ), true)
                || in_array($this->styleResolver->resolvedDeclaration($item, 'position'), array( 'absolute', 'fixed' ), true) ) {
                return false;
            }
        }

        return true;
    }
}

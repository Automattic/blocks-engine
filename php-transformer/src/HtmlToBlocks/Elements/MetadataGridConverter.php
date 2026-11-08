<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextMaterializer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMElement;

final class MetadataGridConverter
{
    public function __construct(
        private readonly SourceBlockCreator $blocks,
        private readonly StyleResolver $styleResolver,
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly RichTextMaterializer $richTextMaterializer,
        private readonly Runtime $runtime
    ) {
    }

    /**
     * Convert compact label/value grids into native blocks without letting the
     * paragraph block's default margins turn each record into prose flow.
     *
     * A definition list provides the relationship semantically. Generic wrappers
     * need both a grid/flex layout and repeated, visually distinguished labels;
     * this keeps ordinary text wrappers out of the recognizer.
     *
     * @return array<string, mixed>|null
     */
    public function convert(DOMElement $element): ?array
    {
        $children = $this->directMetadataCells($element);
        if ( count($children) < 2 || 0 !== count($children) % 2 ) {
            return null;
        }

        $isDefinitionList = 'dl' === strtolower($element->tagName);
        if ( $isDefinitionList ) {
            if ( count($children) < 4 ) {
                return null;
            }
            foreach ( $children as $index => $child ) {
                if ( (0 === $index % 2 && 'dt' !== strtolower($child->tagName)) || (1 === $index % 2 && 'dd' !== strtolower($child->tagName)) ) {
                    return null;
                }
            }
        } elseif ( ! $this->isRepeatedMetadataRow($element, $children) ) {
            return null;
        }

        $style = $this->metadataPresentationStyle($element);
        if ( ! $this->sourceElementClassifier->isMetadataLayoutStyle($style) ) {
            return null;
        }

        if ( $this->sourceElementClassifier->isFlexMetadataStyle($style) && ! $this->hasStrongFlexMetadataEvidence($element, $children, $isDefinitionList, $style) ) {
            return null;
        }

        $blocks = array();
        foreach ( $children as $child ) {
            $content = $this->metadataCellContent($child);
            if ( '' === trim($this->runtime->stripAllTags($content)) ) {
                return null;
            }
            $blocks[] = $this->blocks->createBlock('core/paragraph', $this->metadataCellAttributes($child, $content), array(), $child);
        }

        $attrs = $this->styleResolver->presentationAttributes($element);
        // The source stylesheet owns the grid tracks and independent gaps. Core's
        // layout support emits classes and a gap shorthand that can override both.
        unset($attrs['layout'], $attrs['style']['spacing']['blockGap']);
        if ( empty($attrs['style']['spacing']) ) {
            unset($attrs['style']['spacing']);
        }
        if ( empty($attrs['style']) ) {
            unset($attrs['style']);
        }

        return $this->blocks->createBlock('core/group', $attrs, $blocks, $element);
    }

    /** @return array<int, DOMElement> */
    private function directMetadataCells(DOMElement $element): array
    {
        $cells = array();
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }
            if ( ! $child instanceof DOMElement ) {
                return array();
            }
            if ( $this->sourceElementClassifier->hasBlockContentChildren($child) ) {
                return array();
            }
            $cells[] = $child;
        }

        return $cells;
    }

    /** @param array<int, DOMElement> $children */
    private function isRepeatedMetadataRow(DOMElement $element, array $children): bool
    {
        if ( 2 !== count($children) || ! $this->hasMetadataLabelPresentation($children[0]) ) {
            return false;
        }

        $parent = $element->parentNode;
        if ( ! $parent instanceof DOMElement ) {
            return false;
        }

        $matchingRows = 0;
        foreach ( $parent->childNodes as $sibling ) {
            if ( ! $sibling instanceof DOMElement || ! $this->sourceElementClassifier->isMetadataLayoutStyle($this->metadataPresentationStyle($sibling)) ) {
                continue;
            }
            $cells = $this->directMetadataCells($sibling);
            if ( 2 === count($cells) && $this->hasMetadataLabelPresentation($cells[0]) ) {
                ++$matchingRows;
            }
        }

        return 2 <= $matchingRows;
    }

    private function hasMetadataLabelPresentation(DOMElement $element): bool
    {
        if ( in_array(strtolower($element->tagName), array( 'b', 'strong' ), true) ) {
            return true;
        }

        $style = $this->styleResolver->cssDeclarations($this->metadataPresentationStyle($element));
        $weight = (int) preg_replace('/\D.*/', '', (string) ($style['font-weight'] ?? ''));
        if ( 600 <= $weight || in_array(strtolower(trim((string) ($style['text-transform'] ?? ''))), array( 'uppercase', 'capitalize' ), true) ) {
            return true;
        }

        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement && in_array(strtolower($descendant->tagName), array( 'b', 'strong' ), true) ) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, DOMElement> $children */
    private function hasStrongFlexMetadataEvidence(DOMElement $element, array $children, bool $isDefinitionList, string $style): bool
    {
        if ( 1 !== preg_match('/(?:^|;)\s*flex-wrap\s*:\s*wrap(?:-reverse)?\b/i', $style) ) {
            return false;
        }

        // A definition list supplies repeated term/description records. Generic
        // rows additionally need the repeated labelled-row evidence above.
        return $isDefinitionList
            ? 4 <= count($children)
            : $this->isRepeatedMetadataRow($element, $children);
    }

    private function metadataPresentationStyle(DOMElement $element): string
    {
        // Layout is structural evidence, so inspect matching stylesheet rules even
        // when the element is not otherwise a high-value style boundary.
        return $this->styleResolver->cssDeclarationString($this->styleResolver->structuralPresentationDeclarations($element));
    }

    /** @return array<string, mixed> */
    private function metadataCellAttributes(DOMElement $element, string $content): array
    {
        $attrs = $this->styleResolver->presentationAttributes($element);
        $attrs['content'] = $content;
        $attrs['style']['spacing']['margin']['top'] = '0';
        $attrs['style']['spacing']['margin']['bottom'] = '0';

        return $attrs;
    }

    private function metadataCellContent(DOMElement $element): string
    {
        $content = $this->richTextMaterializer->content($element);
        if ( in_array(strtolower($element->tagName), array( 'dt', 'b', 'strong' ), true) ) {
            return '<strong>' . $content . '</strong>';
        }

        return $content;
    }
}

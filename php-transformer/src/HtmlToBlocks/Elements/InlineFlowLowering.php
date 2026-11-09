<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextMaterializer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Support\ShellLandmarkPolicy;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMElement;

/**
 * Lowers inline flow wrappers: a div of inline content split by line breaks
 * becomes one paragraph, and an inline token group becomes a group of
 * paragraphs.
 */
final class InlineFlowLowering
{
    public function __construct(
        private readonly SourceBlockCreator $blocks,
        private readonly PatternTreeConverter $tree,
        private readonly StyleResolver $styleResolver,
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly RichTextMaterializer $richTextMaterializer,
        private readonly Runtime $runtime
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function textFlowBlockFromElement(DOMElement $element): ?array
    {
        if ( 'div' !== strtolower($element->tagName) || '' !== trim(SourceDom::attr($element, 'id')) || '' !== trim(SourceDom::attr($element, 'role')) ) {
            return null;
        }

        $hasLineBreak = false;
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $tagName = strtolower($child->tagName);
            if ( 'br' === $tagName ) {
                $hasLineBreak = true;
            }
            if ( 'br' !== $tagName && ! $this->sourceElementClassifier->isInlineContentElement($tagName) && 'a' !== $tagName ) {
                return null;
            }
        }

        if ( ! $hasLineBreak ) {
            return null;
        }

        $content = $this->richTextMaterializer->contentWithoutDecorativeSvg($element);
        if ( '' === trim($this->runtime->stripAllTags($content)) ) {
            return null;
        }

        return $this->blocks->createBlock('core/paragraph', array_merge($this->styleResolver->presentationAttributes($element), array( 'content' => $content )), array(), $element);
    }

    public function inlineTokenGroupBlockFromElement(DOMElement $element, array &$fallbacks): ?array
    {
        if ( ! ShellLandmarkPolicy::isInlineTokenContainerTag($element->tagName) ) {
            return null;
        }

        if ( ! $this->sourceElementClassifier->hasInlineTokenGroupSignal($element) ) {
            return null;
        }

        $children = array();
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                if ( '' !== trim($child->textContent ?? '') ) {
                    return null;
                }
                continue;
            }

            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            if ( ! $this->sourceElementClassifier->isInlineTokenItemElement($child) ) {
                return null;
            }

            $tagName = strtolower($child->tagName);
            if ( in_array($tagName, array( 'a', 'button' ), true) ) {
                $block = $this->tree->element($child, $fallbacks, true);
                if ( null === $block ) {
                    return null;
                }
                $children[] = $block;
                continue;
            }

            $content = $this->styleResolver->innerHtml($child);
            if ( '' === trim($this->runtime->stripAllTags($content)) ) {
                return null;
            }
            $children[] = $this->blocks->createBlock('core/paragraph', array_merge($this->styleResolver->presentationAttributes($child), array( 'content' => $content )), array(), $child);
        }

        if ( count($children) < 2 ) {
            return null;
        }

        return $this->blocks->createBlock('core/group', $this->styleResolver->presentationAttributes($element), $children, $element);
    }
}

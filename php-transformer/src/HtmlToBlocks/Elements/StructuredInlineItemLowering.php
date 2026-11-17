<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMElement;

/**
 * Lowers a wrapper of classed phrasing items into one paragraph per item.
 */
final class StructuredInlineItemLowering
{
    public function __construct(
        private readonly SourceBlockCreator $blocks,
        private readonly Runtime $runtime,
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly StyleResolver $styleResolver,
        private readonly InlineSvgTextGroupLowering $inlineSvgTextGroups,
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextMaterialization $richTextMaterializer
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function structuredInlineItemBlocks(DOMElement $element): ?array
    {
        $blocks = array();

        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                if ( '' !== trim($child->textContent ?? '') ) {
                    return null;
                }
                continue;
            }

            if ( XML_COMMENT_NODE === $child->nodeType ) {
                continue;
            }

            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            if ( ! $this->sourceElementClassifier->isClassedPhrasingItem($child) ) {
                return null;
            }

            $inlineSvgTextGroup = $this->inlineSvgTextGroups->fromElement($child);
            if ( null !== $inlineSvgTextGroup ) {
                $blocks[] = $inlineSvgTextGroup;
                continue;
            }

            // The paragraph inherits a span's class for its layout role, but
            // semantic inline elements (time, links, emphasis, etc.) must retain
            // their source markup inside the editable RichText content.
            $content = $this->richTextMaterializer->content($child, array(), 'span' !== strtolower($child->tagName));
            if ( ! $this->runtime->hasVisibleText($content) ) {
                return null;
            }
            // Items share the wrapper's inline flow, so a span's boundary
            // space separates it from its neighbours ("Home / Product").
            if ( 'span' === strtolower($child->tagName) ) {
                $text = $child->textContent;
                $content = (1 === preg_match('/^\s/u', $text) ? ' ' : '') . $content . (1 === preg_match('/\s$/u', $text) ? ' ' : '');
            }

            $attrs = $this->styleResolver->presentationAttributes($child);
            if ( 'span' !== strtolower($child->tagName) && self::contentRetainsItemClasses($child, $content) ) {
                $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), SourceBlockAttributeProjector::RETAINED_ITEM_CARRIER_CLASS);
            }
            $blocks[] = $this->blocks->createBlock('core/paragraph', array_merge($attrs, array( 'content' => $content )), array(), $child);
        }

        return 1 < count($blocks) ? $blocks : null;
    }

    /**
     * Whether the RichText content keeps the item itself, with every one of
     * its classes. The paragraph then carries those classes only so sibling
     * selectors (`.phone + .email`) still find the item among its source
     * siblings; the retained element is the item's one box.
     */
    private static function contentRetainsItemClasses(DOMElement $item, string $content): bool
    {
        $classes = SourceDom::tokens(SourceDom::attr($item, 'class'));
        if ( array() === $classes
            || 1 !== preg_match('/^\s*<' . preg_quote(strtolower($item->tagName), '/') . '\b[^>]*?\sclass="([^"]*)"/i', $content, $match) ) {
            return false;
        }
        return array() === array_diff($classes, SourceDom::tokens($match[1]));
    }
}

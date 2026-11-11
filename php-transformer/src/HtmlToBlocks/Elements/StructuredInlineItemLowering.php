<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
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
        private readonly InlineSvgTextGroupLowering $inlineSvgTextGroups
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
            $content = 'span' === strtolower($child->tagName) ? $this->styleResolver->innerHtml($child) : SourceDom::outerHtml($child);
            if ( ! $this->runtime->hasVisibleText($content) ) {
                return null;
            }
            // Items share the wrapper's inline flow, so a span's boundary
            // space separates it from its neighbours ("Home / Product").
            if ( 'span' === strtolower($child->tagName) ) {
                $text = $child->textContent;
                $content = (1 === preg_match('/^\s/u', $text) ? ' ' : '') . $content . (1 === preg_match('/\s$/u', $text) ? ' ' : '');
            }

            $blocks[] = $this->blocks->createBlock('core/paragraph', array_merge($this->styleResolver->presentationAttributes($child), array( 'content' => $content )), array(), $child);
        }

        return 1 < count($blocks) ? $blocks : null;
    }
}

<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
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
    /** Inline-axis margins the author sets on an item at any viewport. */
    private const INLINE_SPACING_PROPERTIES = array( 'margin', 'margin-left', 'margin-right', 'margin-inline', 'margin-inline-start', 'margin-inline-end' );

    public function __construct(
        private readonly SourceBlockCreator $blocks,
        private readonly Runtime $runtime,
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly StyleResolver $styleResolver,
        private readonly InlineSvgTextGroupLowering $inlineSvgTextGroups,
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextMaterialization $richTextMaterializer,
        private readonly RuntimeDomTargets $runtimeDomTargets
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function structuredInlineItemBlocks(DOMElement $element): ?array
    {
        $blocks = array();
        // The previous item when nothing separates it from the next one:
        // null after whitespace, a block-level item or the run's start.
        $abutting = null;
        $itemsBlockified = $this->blockifiesItems($element);

        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                if ( '' !== trim($child->textContent ?? '') ) {
                    return null;
                }
                if ( '' !== ( $child->textContent ?? '' ) ) {
                    $abutting = null;
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

            // Inline items the author's stylesheet spaces apart along one line
            // with nothing between them in the text ("New York(212)
            // 555-0123") are one text run: a paragraph per item would break
            // the line between them where the source text has none. The run
            // stays one paragraph whose RichText keeps each item's class.
            // Items without that inline spacing keep the item-per-paragraph
            // structure their classes name, and a script-targeted item keeps
            // its own addressable block.
            $inlineItem = ! $itemsBlockified && $this->isInlineLevel($child) && ! $this->runtimeDomTargets->isRuntimeDomTarget($child);
            $text = $child->textContent;
            if ( $inlineItem && null !== $abutting && 1 !== preg_match('/^\s/u', $text)
                && ( $this->hasAuthorInlineSpacing($abutting) || $this->hasAuthorInlineSpacing($child) ) ) {
                return null;
            }
            $abutting = $inlineItem && 1 !== preg_match('/\s$/u', $text) ? $child : null;

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
            if ( 'a' === strtolower($child->tagName) && self::contentRetainsItemClasses($child, $content) ) {
                $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), SourceBlockAttributeProjector::RETAINED_ITEM_CARRIER_CLASS);
            }
            $blocks[] = $this->blocks->createBlock('core/paragraph', array_merge($attrs, array( 'content' => $content )), array(), $child);
        }

        return 1 < count($blocks) ? $blocks : null;
    }

    /** A flex or grid wrapper blockifies its items: each is its own text box. */
    private function blockifiesItems(DOMElement $element): bool
    {
        return in_array($this->styleResolver->resolvedDeclaration($element, 'display'), array( 'flex', 'inline-flex', 'grid', 'inline-grid' ), true);
    }

    private function hasAuthorInlineSpacing(DOMElement $element): bool
    {
        foreach ( $this->styleResolver->authorDeclaredValuesAtAnyViewport($element, self::INLINE_SPACING_PROPERTIES) as $property => $values ) {
            foreach ( $values as $value ) {
                $value = CssValueInspector::withoutImportant($value);
                if ( 'margin' === $property ) {
                    // The shorthand's inline sides: right, then left.
                    $sides = preg_split('/\s+/', trim($value)) ?: array();
                    $value = ( $sides[1] ?? $sides[0] ?? '' ) . ' ' . ( $sides[3] ?? $sides[1] ?? $sides[0] ?? '' );
                }
                if ( CssValueInspector::isNonZero($value) ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isInlineLevel(DOMElement $element): bool
    {
        // A float or out-of-flow item is blockified like a flex item.
        if ( in_array($this->styleResolver->resolvedDeclaration($element, 'float'), array( 'left', 'right', 'inline-start', 'inline-end' ), true)
            || in_array($this->styleResolver->resolvedDeclaration($element, 'position'), array( 'absolute', 'fixed' ), true) ) {
            return false;
        }

        return in_array($this->styleResolver->resolvedDeclaration($element, 'display'), array( '', 'inline', 'inline-block' ), true);
    }

    /**
     * Whether the RichText content keeps the item link itself, with every one
     * of its classes. The paragraph then carries those classes only so sibling
     * selectors (`.phone + .email`) still find the item among its source
     * siblings; the retained link is the item's one box. Other semantic items
     * (time, emphasis) keep their existing paragraph carrier unchanged.
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

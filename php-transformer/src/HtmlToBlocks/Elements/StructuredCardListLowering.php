<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextMaterializer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextStylingHookNormalizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorLayoutOwnership;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMElement;

/**
 * Recognizes source lists whose items are self-contained cards and
 * decomposes them into core/group card blocks instead of core/list items.
 */
final class StructuredCardListLowering
{
    private const CSS_OWNED_GRID_CLASS = 'blocks-engine-css-owned-grid';

    public function __construct(
        private readonly SourceBlockCreator $blocks,
        private readonly StyleResolver $styleResolver,
        private readonly AuthorLayoutOwnership $authorLayoutOwnership,
        private readonly RichTextMaterializer $richTextMaterializer,
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly Runtime $runtime,
        private readonly PatternTreeConverter $tree,
        private readonly RichTextStylingHookNormalizer $richTextStylingHooks
    ) {
    }

    /**
     * Whether a `<ul>`/`<ol>` is a stack of "structured inline cards" rather than
     * a normal list.
     *
     * A structured card list is one whose every content-bearing `<li>` is built
     * from MULTIPLE class/style-carrying inline fragments — the universal
     * blog/news/essay-index row of a title link plus dek/meta spans
     * (`<a class>` + `<span class>` + `<span class>`). core/list-item stores its
     * content as RichText, which only preserves a fixed set of inline formats, so
     * the class on an inner `<a>`/`<span>` is dropped on parse (saved markup
     * diverges from the regenerated block) and the per-fragment styling hooks the
     * materialized CSS targets are lost. A single list item also cannot carry the
     * distinct class of each fragment, so the row is really a mini-card.
     *
     * Keys off STRUCTURE (multiple styling-hook inline fragments), never on any
     * specific class name. A plain-text list, a simple link list, a flowing
     * sentence with one inline link, or a list item that carries block-level
     * children (an image/heading/paragraph product card owned by the commerce
     * path) is NOT a structured card and stays a normal core/list.
     */
    public function isStructuredCardList(DOMElement $list): bool
    {
        $cardItems = 0;
        foreach ( $list->childNodes as $child ) {
            if ( ! $child instanceof DOMElement || 'li' !== strtolower($child->tagName) ) {
                continue;
            }
            if ( '' === trim($this->runtime->stripAllTags(SourceDom::innerHtmlWithoutTags($child, array( 'ul', 'ol' )))) ) {
                continue;
            }
            if ( ! $this->isStructuredCardItem($child) ) {
                return false;
            }
            ++$cardItems;
        }

        return $cardItems > 0;
    }

    /**
     * A `<li>` that is a structured inline card: all of its content is inline
     * (text + inline formats/links — no block-level children), and it carries at
     * least two class/style styling-hook inline fragments (e.g. a classed title
     * link plus dek/meta spans). The "two hooks" threshold distinguishes a
     * stacked card from flowing text that merely contains a single inline link.
     */
    private function isStructuredCardItem(DOMElement $item): bool
    {
        $stylingHookFragments = 0;
        foreach ( $item->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                continue;
            }
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $tag = strtolower($child->tagName);
            if ( in_array($tag, array( 'ul', 'ol' ), true) ) {
                continue;
            }

            // A block-level child means this is not an inline card (e.g. a
            // product card with <img>/<h3>/<p>); leave it to the normal path.
            if ( 'br' !== $tag && 'a' !== $tag && ! $this->sourceElementClassifier->isInlineContentElement($tag) ) {
                return false;
            }

            if ( $this->sourceElementClassifier->hasBlockContentChildren($child) || $this->richTextMaterializer->hasStructuralHtml($this->styleResolver->innerHtml($child)) ) {
                return false;
            }

            if ( $this->isStylingHookInline($child) ) {
                ++$stylingHookFragments;
            }
        }

        return $stylingHookFragments >= 2;
    }

    /**
     * An inline element carrying a class/style styling hook RichText cannot
     * store: a styling-hook `<span>` (class/style only), or any link/inline
     * format element (`<a>`, `<strong>`, …) with a non-empty class or style.
     */
    private function isStylingHookInline(DOMElement $element): bool
    {
        $tag = strtolower($element->tagName);
        if ( 'span' === $tag ) {
            return $this->richTextStylingHooks->isStylingHookSpan($element);
        }
        if ( 'a' !== $tag && ! $this->sourceElementClassifier->isInlineContentElement($tag) ) {
            return false;
        }

        return '' !== trim(SourceDom::attr($element, 'class')) || '' !== trim(SourceDom::attr($element, 'style'));
    }

    /**
     * Decompose a structured card `<ul>`/`<ol>` into a `core/group` of per-item
     * `core/group`s. Each fragment of an item becomes its own block carrying its
     * hoisted styling hook, so the result is fully valid (group/paragraph
     * round-trip and store a custom className) while the per-fragment styling
     * hooks and the working link survive — which a single core/list-item cannot
     * represent. The outer group inherits the list's CSS-owned layout (grid/flex
     * and its gap) and keeps the source list tag; each inner group inherits its
     * `<li>`'s layout. Core flow spacing is neutralized so WordPress does not
     * double the source vertical rhythm with default block/paragraph margins.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    public function decomposeStructuredCardList(DOMElement $list, array &$fallbacks): ?array
    {
        $itemGroups = array();
        foreach ( $list->childNodes as $child ) {
            if ( ! $child instanceof DOMElement || 'li' !== strtolower($child->tagName) ) {
                continue;
            }

            $itemGroup = $this->structuredCardItemGroup($child, $fallbacks);
            if ( null !== $itemGroup ) {
                $itemGroups[] = $itemGroup;
            }
        }

        if ( array() === $itemGroups ) {
            return null;
        }

        return $this->blocks->createBlock(
            'core/group',
            array_merge(
                $this->withStructuredCardFlowNeutralization($this->authorLayoutOwnership->cssOwnedGroupAttributes($list, true)),
                array( 'tagName' => strtolower($list->tagName) )
            ),
            $itemGroups,
            $list
        );
    }

    /**
     * Structured card lowering replaces inline fragments with blocks, so core
     * flow spacing is never part of the source contract. Grid containers already
     * neutralize child margins; remaining flex/flow lists need the same.
     *
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function withStructuredCardFlowNeutralization(array $attrs): array
    {
        $className = (string) ($attrs['className'] ?? '');
        if ( str_contains($className, self::CSS_OWNED_GRID_CLASS) || str_contains($className, AuthorLayoutOwnership::CSS_OWNED_FLOW_CLASS) ) {
            return $attrs;
        }

        $attrs['className'] = SourceDom::mergeClassNames($className, AuthorLayoutOwnership::CSS_OWNED_FLOW_CLASS);
        $style = is_array($attrs['style'] ?? null) ? $attrs['style'] : array();
        $spacing = is_array($style['spacing'] ?? null) ? $style['spacing'] : array();
        if ( ! isset($spacing['blockGap']) ) {
            $spacing['blockGap'] = '0';
            $style['spacing'] = $spacing;
            $attrs['style'] = $style;
        }

        return $attrs;
    }

    /**
     * Build the per-item `core/group` for one structured card `<li>`: a paragraph
     * per inline fragment (the title link, the dek, the meta), each carrying the
     * fragment's hoisted styling hook, plus any nested list converted in place.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function structuredCardItemGroup(DOMElement $item, array &$fallbacks): ?array
    {
        $fragmentBlocks = array();
        foreach ( $item->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                $text = trim($child->textContent ?? '');
                if ( '' !== $text ) {
                    $fragmentBlocks[] = $this->blocks->createBlock('core/paragraph', array(
                        'content' => $this->runtime->escapeHtml($text),
                        'className' => SourceBlockAttributeProjector::SYNTHETIC_PARAGRAPH_CLASS,
                    ));
                }
                continue;
            }
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $tag = strtolower($child->tagName);
            if ( in_array($tag, array( 'ul', 'ol' ), true) ) {
                $nested = $this->tree->element($child, $fallbacks, true);
                if ( null !== $nested ) {
                    $fragmentBlocks[] = $nested;
                }
                continue;
            }

            $block = $this->cardFragmentBlock($child);
            if ( null !== $block ) {
                $fragmentBlocks[] = $block;
            }
        }

        if ( array() === $fragmentBlocks ) {
            return null;
        }

        return $this->blocks->createBlock(
            'core/group',
            array_merge(
                $this->withStructuredCardFlowNeutralization($this->authorLayoutOwnership->cssOwnedGroupAttributes($item)),
                array( 'tagName' => 'li' )
            ),
            $fragmentBlocks,
            $item
        );
    }

    /**
     * Turn one inline card fragment into a `core/paragraph` that round-trips
     * through RichText while keeping the fragment's styling hook on the block.
     *
     *   - A link fragment stays a valid RichText anchor (`<a href>` with its
     *     RichText-dropped class/style stripped) and its class/style are hoisted
     *     onto the paragraph, so the styling hook survives and the link works.
     *   - A styling-hook `<span>` is unwrapped to its inner content and its
     *     class/style are hoisted onto the paragraph.
     *   - Any other inline fragment is kept verbatim inside the paragraph;
     *     createBlock's span hoisting normalizes any nested styling-hook spans.
     *
     * @return array<string, mixed>|null
     */
    private function cardFragmentBlock(DOMElement $element): ?array
    {
        $tag = strtolower($element->tagName);

        if ( 'a' === $tag ) {
            $content = $this->anchorWithoutStylingAttributes($element);
            if ( '' === trim($this->runtime->stripAllTags($content)) ) {
                return null;
            }

            return $this->blocks->createBlock('core/paragraph', array_merge(
                $this->hoistedStylingAttributes($element),
                array( 'content' => $content )
            ), array(), $element);
        }

        if ( 'span' === $tag && $this->richTextStylingHooks->isStylingHookSpan($element) ) {
            $content = $this->styleResolver->innerHtml($element);
            if ( '' === trim($this->runtime->stripAllTags($content)) ) {
                return null;
            }

            return $this->blocks->createBlock('core/paragraph', array_merge(
                $this->hoistedStylingAttributes($element),
                array( 'content' => $content )
            ), array(), $element);
        }

        $content = SourceDom::outerHtml($element);
        if ( '' === trim($this->runtime->stripAllTags($content)) ) {
            return null;
        }

        return $this->blocks->createBlock('core/paragraph', array( 'content' => $content ), array(), $element);
    }

    /**
     * Map an element's source class/style into the block `className` + canonical
     * `style` object attributes, so the styling hook rides where the block save()
     * reproduces it.
     *
     * @return array<string, mixed>
     */
    private function hoistedStylingAttributes(DOMElement $element): array
    {
        $attrs = array();

        $className = $this->styleResolver->promotedClassName(SourceDom::attr($element, 'class'));
        if ( '' !== trim($className) ) {
            $attrs['className'] = $className;
        }

        $style = trim(SourceDom::attr($element, 'style'));
        if ( '' !== $style ) {
            $mapped = $this->styleResolver->styleAttributeMapper()->map($this->styleResolver->cssDeclarations($style))['style'];
            if ( array() !== $mapped ) {
                $attrs['style'] = $mapped;
            }
        }

        return $attrs;
    }

    /**
     * Serialize an `<a>` with its RichText-dropped presentational attributes
     * (class/style) removed and an unsafe href dropped, leaving a clean anchor
     * RichText preserves. When no safe href remains, the link text is returned
     * without the anchor so no broken/empty link is emitted.
     */
    private function anchorWithoutStylingAttributes(DOMElement $anchor): string
    {
        $attributes = array();
        foreach ( SourceDom::htmlAttributes($anchor) as $name => $value ) {
            if ( in_array(strtolower($name), array( 'class', 'style' ), true) ) {
                continue;
            }
            $attributes[$name] = $value;
        }

        $href = SourceDom::safeNavigationUrl(SourceDom::attr($anchor, 'href'));
        $inner = $this->styleResolver->innerHtml($anchor);
        if ( '' === $href ) {
            return $inner;
        }

        $attributes['href'] = $href;
        return '<a' . SourceDom::htmlAttributeString($attributes) . '>' . $inner . '</a>';
    }
}

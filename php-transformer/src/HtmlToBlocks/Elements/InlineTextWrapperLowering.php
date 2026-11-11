<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\Contract\RichTextInlineTags;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlCompilation;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextMaterializer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextStylingHookNormalizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorLayoutOwnership;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorStylesheetProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Support\ShellLandmarkPolicy;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMElement;

/**
 * Lowers inline-content wrappers (`div`/`span` text wrappers, phrasing-only
 * containers and addressable inline runs) to a paragraph, or to a group around
 * one, keeping the wrapper's own box on the emitted block.
 */
final class InlineTextWrapperLowering
{
    public function __construct(
        private readonly PatternTreeConverter $tree,
        private readonly SourceBlockCreator $blocks,
        private readonly HtmlTransformerSession $session,
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly StyleResolver $styleResolver,
        private readonly RichTextMaterializer $richTextMaterializer,
        private readonly RichTextStylingHookNormalizer $richTextStylingHooks,
        private readonly StructuredInlineItemLowering $structuredInlineItemLowering,
        private readonly AuthorLayoutOwnership $authorLayoutOwnership,
        private readonly EmptyVisualRetentionPolicy $emptyVisualRetention,
        private readonly Runtime $runtime
    ) {
    }

    public function visualTextWrapperBlockFromElement(DOMElement $element): ?array
    {
        if ( ! in_array(strtolower($element->tagName), array( 'div', 'span' ), true) || $this->sourceElementClassifier->hasBlockContentChildren($element) ) {
            return null;
        }

        if ( $this->session->authorSelectorProjectionState()->hasSemanticMarkedChild($element) || $this->hasRichTextMarkedDescendant($element) ) {
            return null;
        }

        if ( $this->emptyVisualRetention->hasEmptyVisualInlineChild($element) ) {
            $fallbacks = array();
            $children = $this->tree->children($element, $fallbacks, true);
            if ( array() !== $children ) {
                return $this->blocks->createBlock('core/group', $this->styleResolver->presentationAttributes($element), $children, $element);
            }
        }

        $content = $this->richTextMaterializer->content($element);
        if ( ! $this->runtime->hasVisibleText($content) || $this->richTextMaterializer->requiresHtmlFallback($content) ) {
            return null;
        }

        if ( ! $this->hasVisualTextWrapperSignal($element) ) {
            return null;
        }

        // A childless styled text wrapper round-trips as a single styled
        // `core/paragraph` carrying the wrapper class and presentation supports.
        // Keeping its box chrome on that same element preserves the source paint
        // structure; a core/group plus inner paragraph produces a separate text
        // paint layer that can differ around rounded borders. Unsupported geometry
        // remains on the generated CSS carrier attached to the paragraph. Real flex
        // containers hold child elements and are already excluded above by the
        // `childElementCount === 0` guard (e.g. `.tier-price` wrapping a `<span>`).
        //
        // Descendant paragraph rules the source used a non-`p` tag to escape (e.g.
        // `.page-header p { font-size: ... }` styling body copy while an eyebrow
        // authored as `<div class="label">` avoided it) do not capture the collapsed
        // paragraph: author `p` type selectors are projected through the source-`p`
        // tag marker, which only elements that were `<p>` in the source carry.
        if ( 0 === SourceDom::childElementCount($element) ) {
            $attrs = array_merge($this->styleResolver->presentationAttributes($element), array( 'content' => $content ));
            // The paragraph is the source div's own block box. The
            // lowered-paragraph reset removes only the UA paragraph margins;
            // it precedes author CSS (and sits in the engine reset layer when
            // the author uses cascade layers), so authored margins still win.
            if ( 'div' === strtolower($element->tagName) ) {
                $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), HtmlCompilation::LOWERED_PARAGRAPH_CLASS);
                return $this->blocks->createBlock('core/paragraph', $attrs, array(), $element);
            }
            // A non-`p` source did not have paragraph margins. Mark the host so
            // engine-support CSS can neutralize them without touching authored <p>.
            if ( 'p' !== strtolower($element->tagName) ) {
                $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), SourceBlockAttributeProjector::SYNTHETIC_PARAGRAPH_CLASS);
            }

            return $this->blocks->createBlock('core/paragraph', $attrs, array(), $element);
        }

        return $this->blocks->createBlock(
            'core/group',
            $this->styleResolver->presentationAttributes($element),
            array( $this->blocks->createBlock('core/paragraph', array( 'content' => $content )) ),
            $element
        );
    }

    /**
     * Box-model CSS declarations that give a text wrapper block-level geometry
     * (padding, border, explicit sizing, or flex/grid layout) which mark it as a
     * visual text wrapper worth preserving as a distinct block.
     *
     * @var array<int, string>
     */
    private const BOX_MODEL_WRAPPER_PROPERTIES = array( 'display', 'gap', 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'border', 'border-color', 'border-radius', 'width', 'height', 'min-width', 'max-width', 'min-height' );

    /**
     * Box chrome that requires a paragraph with empty visual inline children to
     * retain a structural wrapper rather than flattening those children.
     *
     * @var array<int, string>
     */
    private const MARGIN_WRAPPER_PROPERTIES = array( 'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left' );

    private function hasVisualTextWrapperSignal(DOMElement $element): bool
    {
        $className = strtolower(SourceDom::attr($element, 'class'));
        if ( preg_match('/(?:^|[\s_-])(?:badge|tag|label|eyebrow|kicker|meta|pill|chip|stat|num|price|amount|result|caption|title|name)(?:$|[\s_-])/', $className) ) {
            return true;
        }

        if ( 0 < SourceDom::childElementCount($element) ) {
            return false;
        }

        return $this->styleResolver->hasNonZeroStructuralDeclaration($element, self::BOX_MODEL_WRAPPER_PROPERTIES);
    }

    public function paragraphBlockFromInlineContentWrapper(DOMElement $element): ?array
    {
        // An unknown element in block position is a generic container too
        // (see isTransparentUnknownElement()): its phrasing run is one paragraph.
        if ( ! ShellLandmarkPolicy::isInlineContentWrapperTag($element->tagName) && ! RichTextInlineTags::isUnknownHtmlElement($element) ) {
            return null;
        }

        // A direct inline child with its own layout geometry needs its source
        // tag as the flex/grid item. Ordinary inline runs still stay RichText.
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && $this->authorLayoutOwnership->requiresStandaloneInlineLayoutLeaf($child) ) {
                return null;
            }
        }

        if ( ! $this->sourceElementClassifier->hasOnlyPhrasingChildren($element) ) {
            return null;
        }

        if ( $this->emptyVisualRetention->hasEmptyVisualInlineChild($element) ) {
            $fallbacks = array();
            $children = $this->tree->children($element, $fallbacks, true);
            if ( array() !== $children ) {
                return $this->blocks->createBlock('core/group', $this->styleResolver->presentationAttributes($element), $children, $element);
            }
        }

        $content = $this->richTextMaterializer->content($element);
        $withLowered = $this->richTextMaterializer->contentWithInlineSafeButtonsLowered($element, $content);

        // A lone marked descendant normally needs an independent carrier.
        // Inside a safe inline button it belongs to the paragraph's RichText,
        // just as under a source <p>; splitting it off strands SVG descendants
        // before the existing carrier and image materialization can run. Beside
        // its own text it is part of one line box: splitting there would turn
        // each text run into a paragraph and drop the spaces between them.
        if ( $this->session->authorSelectorProjectionState()->hasSemanticCarrierChild($element) || ( null === $withLowered && $this->hasRichTextMarkedDescendant($element) && 2 > SourceDom::childElementCount($element) && ! SourceDom::hasDirectText($element) ) ) {
            return null;
        }

        $structuredInlineItems = $this->structuredInlineItemLowering->structuredInlineItemBlocks($element);
        if ( null !== $structuredInlineItems ) {
            return $this->blocks->createBlock('core/group', $this->styleResolver->presentationAttributes($element), $structuredInlineItems, $element);
        }

        if ( null !== $withLowered ) {
            // Reuse label RichText preparation so wrapper hooks become carriers
            // before button marks are introduced (the normalizer avoids nesting
            // marks when replacing spans). Then use the paragraph primitives.
            $content = $this->richTextStylingHooks->inlineContent($element);
            $content = $this->richTextMaterializer->contentWithInlineSafeButtonsLowered($element, $content) ?? $content;
            $content = $this->richTextMaterializer->contentWithMaterializedSvgImages($element, $content) ?? $content;
        }
        if ( ! $this->runtime->hasVisibleText($content) ) {
            return null;
        }

        if ( 'div' === strtolower($element->tagName) && $this->styleResolver->hasNonZeroStructuralDeclaration($element, self::MARGIN_WRAPPER_PROPERTIES) ) {
            $attrs = $this->styleResolver->presentationAttributes($element);
            $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), HtmlCompilation::LOWERED_PARAGRAPH_CLASS);
            return $this->blocks->createBlock(
                'core/paragraph',
                array_merge($attrs, array( 'content' => $content )),
                array(),
                $element
            );
        }

        $attrs = $this->styleResolver->presentationAttributes($element);
        // The paragraph is this block-level wrapper's own box (it carries the
        // wrapper's identity and classes), so it must not become transparent.
        $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), SourceBlockAttributeProjector::SYNTHETIC_PARAGRAPH_CLASS . ' ' . SourceBlockAttributeProjector::SOURCE_BOX_PARAGRAPH_CLASS);
        $attrs['content'] = $content;
        return $this->blocks->createBlock('core/paragraph', $attrs, array(), $element);
    }

    /** @return array<string, mixed>|null */
    public function inlineAddressableRunGroupBlock(DOMElement $element): ?array
    {
        if ( ! $this->sourceElementClassifier->hasOnlyPhrasingChildren($element) ) return null;

        $addressable = 0;
        foreach ($element->childNodes as $child) {
            if ( ! $child instanceof DOMElement ) continue;
            if ($this->authorLayoutOwnership->requiresStandaloneInlineLayoutLeaf($child)) return null;
            if ('' !== trim(SourceDom::attr($child, 'id'))) ++$addressable;
        }
        if ($addressable < 2) return null;
        if ($this->authorLayoutOwnership->isAuthorOwnedLayout($element) || $this->emptyVisualRetention->hasEmptyVisualInlineChild($element)) return null;

        $content = $this->richTextMaterializer->content($element);
        if (! $this->runtime->hasVisibleText($content) || $this->richTextMaterializer->requiresHtmlFallback($content)) return null;

        $paragraph = $this->blocks->createBlock('core/paragraph', array('className' => AuthorStylesheetProjector::INLINE_LAYOUT_CARRIER_CLASS, 'content' => $content));
        return $this->blocks->createBlock('core/group', $this->styleResolver->presentationAttributes($element), array($paragraph), $element);
    }

    private function hasRichTextMarkedDescendant(DOMElement $element): bool
    {
        foreach ( $element->getElementsByTagName('span') as $span ) {
            if ( $span instanceof DOMElement && '' !== $this->authorLayoutOwnership->richTextMarker($span) && ! $this->emptyVisualRetention->shouldPreserveEmptyVisualElement($span) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    public function positionedInlineCarrierBlock(DOMElement $element, array &$fallbacks): ?array
    {
        if ( $this->authorLayoutOwnership->positionedCarrierHasStructuredContent($element) ) {
            $children = $this->positionedInlineCarrierChildren($element, $fallbacks);
            return array() === $children
                ? null
                : $this->blocks->createBlock('core/group', $this->styleResolver->positionedInlineCarrierAttributes($element), $children, $element);
        }

        $content = $this->richTextMaterializer->content($element);
        if ( ! $this->runtime->hasVisibleText($content) ) {
            return null;
        }

        return $this->blocks->createBlock('core/group', $this->styleResolver->positionedInlineCarrierAttributes($element), array(
            $this->blocks->createBlock('core/paragraph', array(
                'content' => $content,
                'className' => SourceBlockAttributeProjector::SYNTHETIC_PARAGRAPH_CLASS,
            )),
        ), $element);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<int, array<string, mixed>>
     */
    private function positionedInlineCarrierChildren(DOMElement $element, array &$fallbacks): array
    {
        $blocks = array();
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                $text = trim($child->textContent ?? '');
                if ( '' !== $text ) {
                    $blocks = array_merge($blocks, $this->tree->text($text));
                }
                continue;
            }
            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            if ( FormControlClassifier::isControlElement($child) ) {
                $blocks[] = $this->blocks->htmlPreservationBlock($child);
                continue;
            }
            $block = $this->tree->element($child, $fallbacks, true);
            if ( null !== $block ) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }
}

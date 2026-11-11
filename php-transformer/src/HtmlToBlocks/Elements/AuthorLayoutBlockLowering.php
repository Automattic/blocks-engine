<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthorLayoutBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlCompilation;
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
 * Lowers an author layout container to a group (or a lowered paragraph for a
 * text-only `div`), recording topology evidence when author CSS owns the
 * layout and the block children no longer match the source children.
 */
final class AuthorLayoutBlockLowering implements AuthorLayoutLowering
{
    public function __construct(
        private readonly PatternTreeConverter $tree,
        private readonly SourceBlockCreator $blocks,
        private readonly HtmlTransformerSession $session,
        private readonly StyleResolver $styleResolver,
        private readonly RichTextMaterializer $richTextMaterializer,
        private readonly Runtime $runtime,
        private readonly EmptyVisualRetentionPolicy $emptyVisualRetention,
        private readonly LayoutShellLowering $layoutShells,
        private readonly WrapperCoalescer $wrapperCoalescer,
        private readonly AuthorLayoutOwnership $authorLayoutOwnership
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function authorLayoutBlockFromElement(DOMElement $element, array &$fallbacks): array
    {
        if ( ! $this->emptyVisualRetention->preservesScriptStateWrapper($element) ) {
            $paragraph = $this->textOnlyLayoutDivParagraph($element);
            if ( null !== $paragraph ) {
                return $paragraph;
            }
        }
        $children = $this->tree->children($element, $fallbacks, true);
        if ( $this->emptyVisualRetention->preservesScriptStateWrapper($element) ) {
            return $this->layoutShells->blockForElements(array( $element ), $children, $element);
        }
        $isAuthorOwnedLayout = $this->authorLayoutOwnership->isAuthorOwnedLayout($element);
        $sourceChildCount = $isAuthorOwnedLayout ? SourceDom::childElementCount($element) : 0;
        $sourceTags = $isAuthorOwnedLayout ? SourceDom::directChildTags($element) : array();
        $blockTags = $isAuthorOwnedLayout ? $this->directBlockTags($children) : array();
        $topologyChanged = $isAuthorOwnedLayout
            && 0 < $sourceChildCount
            && ( $sourceChildCount !== count($children) || $sourceTags !== $blockTags );
        if ( 1 === count($children) && ! $topologyChanged ) {
            $coalesced = $this->wrapperCoalescer->coalescedSingleGroupWrapper($element, $children[0]);
            if ( null !== $coalesced ) {
                return $coalesced;
            }
        }
        $attrs = $this->authorLayoutOwnership->cssOwnedGroupAttributes($element, false, $topologyChanged);
        if ( 0 === count($children) && '' === SourceDom::renderedTextContent($element) ) {
            // Empty source layout containers are visual boundaries, not new
            // authoring targets. Mark them so the editor suppresses Core's
            // layout picker without changing their saved frontend box.
            $attrs['className'] = SourceDom::mergeClassNames(
                (string) ($attrs['className'] ?? ''),
                HtmlCompilation::EMPTY_VISUAL_GROUP_CLASS
            );
            $this->session->runtimeBehaviorState()->markEmptyVisualGroupGenerated();
            // An inline background paint is the reason an empty boundary box
            // exists. The standard carrier declines backgrounds for childless
            // elements because the flow-container path lowers those to a
            // background image block; this path keeps the box itself, so
            // restate the paint on a dedicated carrier.
            $attrs['className'] = SourceDom::mergeClassNames(
                (string) $attrs['className'],
                $this->styleResolver->emptyElementBackgroundCarrierClassName($element)
            );
        }
        $block = $this->blocks->createBlock('core/group', $attrs, $children, $element);
        if ( $isAuthorOwnedLayout ) {
            $this->session->transformationEvidenceState()->recordAuthorLayoutTopology(
                SourceDom::elementSelector($element),
                $sourceChildCount,
                count($children),
                $sourceTags,
                $blockTags,
                $block['_source_provenance_id'] ?? null
            );
        }
        if ($topologyChanged && $this->styleResolver->hasTopologyUnsafeFixedHeight($element)) {
            $this->session->layoutGeometryState()->removeTopologyUnsafeFixedHeightRules(
                $this->styleResolver->geometryStructuralPath($element)
            );
        }
        return $block;
    }

    /** @param array<int, array<string, mixed>> $blocks @return list<string> */
    private function directBlockTags(array $blocks): array
    {
        $tags = array();
        foreach ($blocks as $block) {
            $name = $block['blockName'] ?? '';
            $attrs = $block['attrs'] ?? array();
            // A lowered paragraph is its source div's own box; author tag
            // selectors reach it through the source-tag projection.
            if ( 'core/paragraph' === $name && in_array(HtmlCompilation::LOWERED_PARAGRAPH_CLASS, preg_split('/\s+/', (string) ($attrs['className'] ?? '')) ?: array(), true) ) {
                $tags[] = 'div';
                continue;
            }
            $tags[] = $this->session->requireGeneratedBlockRegistry()->blockName(AuthorLayoutBlockGenerator::LOCAL_NAME) === $name ? (string) ($attrs['tagName'] ?? 'div') : ('core/group' === $name ? (string) ($attrs['tagName'] ?? 'div') : ('core/image' === $name ? 'img' : ('core/paragraph' === $name ? 'p' : ('core/heading' === $name ? 'h' . (string) ($attrs['level'] ?? 2) : ''))));
        }
        return $tags;
    }

    /**
     * A layout `div` that holds only text is one box in the source: its text is
     * an anonymous box laid out by the div's own display. A paragraph that
     * carries the div's classes and presentation is that same box, so the text
     * keeps its source parent instead of gaining a group plus an added
     * paragraph. The lowered-paragraph reset removes only the UA paragraph
     * margins, below every author rule.
     *
     * @return array<string, mixed>|null
     */
    private function textOnlyLayoutDivParagraph(DOMElement $element): ?array
    {
        if ( 'div' !== strtolower($element->tagName) || 0 !== SourceDom::childElementCount($element) || SourceDom::documentVariantRoot($element) === $element ) {
            return null;
        }
        $content = $this->richTextMaterializer->content($element);
        if ( '' === trim($this->runtime->stripAllTags($content)) || $this->richTextMaterializer->requiresHtmlFallback($content) ) {
            return null;
        }
        $attrs = $this->styleResolver->presentationAttributes($element);
        $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), HtmlCompilation::LOWERED_PARAGRAPH_CLASS);
        $attrs['content'] = $content;

        return $this->blocks->createBlock('core/paragraph', $attrs, array(), $element);
    }
}

<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\EmptyElementFootprint;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/**
 * Lowers an empty source element that still occupies visual space into a
 * spacer or empty group that keeps its box.
 */
final class EmptyVisualSpacerLowering
{
    public const EMPTY_FLEX_ITEM_CLASS = 'blocks-engine-empty-flex-item';

    /**
     * Marks a childless flex item inside a column-direction (or
     * column-reverse) container. The main axis there is height, not width,
     * so the compatibility box this class carries zeroes the opposite
     * properties from {@see EMPTY_FLEX_ITEM_CLASS}'s row-axis assumption.
     */
    public const EMPTY_FLEX_ITEM_COLUMN_CLASS = 'blocks-engine-empty-flex-column-item';

    public function __construct(
        private readonly SourceBlockCreator $blocks,
        private readonly PatternTreeConverter $tree,
        private readonly HtmlTransformerSession $session,
        private readonly StyleResolver $styleResolver,
        private readonly EmptyElementFootprint $emptyElementFootprint,
        private readonly RuntimeIslandAnalyzer $runtimeIslands
    ) {
    }

    /** @return array<string, mixed> */
    private function emptyVisualElementAttributes(DOMElement $element): array
    {
        $attrs = $this->styleResolver->presentationAttributes($element);
        $parent = $element->parentNode;
        if ( ! $parent instanceof DOMElement ) {
            return $attrs;
        }

        $parentDeclarations = $this->styleResolver->structuralPresentationDeclarations($parent);
        $parentDisplay = CssValueInspector::declared($parentDeclarations, 'display');
        if ( ! in_array($parentDisplay, array( 'flex', 'inline-flex' ), true) ) {
            return $attrs;
        }

        // Resolve the element's full matched CSS, not only its inline style:
        // a utility-class divider (e.g. Tailwind's `h-px w-24`) declares its
        // geometry on a stylesheet rule that the narrower inline-only
        // resolver used here previously never saw, so an authored box was
        // misread as sizeless.
        $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
        $position = strtolower(trim((string) ($declarations['position'] ?? 'static')));
        if ( in_array($position, array( 'absolute', 'fixed' ), true) ) {
            return $attrs;
        }

        // The zero-collapse compatibility box below only removes the flex
        // main-axis footprint core would otherwise add to a childless flex
        // item. Which CSS properties own that axis depends on the parent's
        // flex-direction: a row container's main axis is width, a column
        // container's is height. Checking width alone let an authored
        // height survive the escape hatch but still be crushed by the
        // row-shaped compatibility box below.
        $isColumnAxis = in_array(strtolower(trim((string) ($parentDeclarations['flex-direction'] ?? 'row'))), array( 'column', 'column-reverse' ), true);
        $mainAxisSizeProperties = $isColumnAxis
            ? array( 'height', 'min-height', 'max-height', 'flex', 'flex-basis' )
            : array( 'width', 'min-width', 'max-width', 'flex', 'flex-basis' );
        // An authored main-axis size counts at any viewport: page builders
        // commonly state a column's width only inside a media-scoped rule
        // (for example `@media (min-width:768px){#col{width:48%}}`), so the
        // unconditional cascade alone misreads a sized, painted column as
        // sizeless and the compatibility box crushes it at every viewport.
        foreach ( $this->styleResolver->authorDeclaredValuesAtAnyViewport($element, $mainAxisSizeProperties) as $values ) {
            foreach ( $values as $value ) {
                if ( '' !== trim($value) && 'auto' !== strtolower(trim($value)) ) {
                    return $attrs;
                }
            }
        }
        foreach ( $mainAxisSizeProperties as $property ) {
            if ( isset($declarations[$property]) && '' !== trim($declarations[$property]) && 'auto' !== strtolower(trim($declarations[$property])) ) {
                return $attrs;
            }
        }
        foreach ( $this->session->sourceStyleResolutionState()->pseudoElementRules() as $rule ) {
            if ( $this->session->sourceStyleResolutionState()->matches($element, $rule['selector']) && array_intersect_key($rule['declarations'], array_flip(array( 'content', 'display', 'width', 'min-width' ))) ) {
                return $attrs;
            }
        }

        $attrs['className'] = trim((string) ($attrs['className'] ?? '') . ' ' . ( $isColumnAxis ? self::EMPTY_FLEX_ITEM_COLUMN_CLASS : self::EMPTY_FLEX_ITEM_CLASS ));
        if ( $this->runtimeIslands->isRuntimeDomTarget($element) ) {
            $attrs['className'] = trim($attrs['className'] . ' ' . HtmlTransformer::EMPTY_RUNTIME_TARGET_CLASS);
            $this->session->runtimeBehaviorState()->markEmptyRuntimeTargetGenerated();
        }
        return $attrs;
    }

    /** @return array<string, mixed> */
    public function emptyVisualSpacerBlock(DOMElement $element): array
    {
        $attrs = $this->emptyVisualElementAttributes($element);
        if ( ! $this->emptyElementFootprint->isEmptyVisualInlineCandidate($element) ) {
            $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), HtmlTransformer::EMPTY_VISUAL_GROUP_CLASS);
            $this->session->runtimeBehaviorState()->markEmptyVisualGroupGenerated();
            // Carry complex paint, but let native background support own a
            // CSS block box's solid colour so editor recolouring can win.
            $attrs['className'] = SourceDom::mergeClassNames(
                (string) $attrs['className'],
                $this->styleResolver->emptyElementBackgroundCarrierClassName($element, $this->emptyElementFootprint->hasEmptyVisualBlockDisplay($element) && isset($attrs['style']['color']['background']))
            );
            // Empty, nested layout wrappers can still own measurable grid or
            // flex tracks (fluid-engine sections commonly put those tracks on
            // a child below a data-bearing presentation wrapper). Lowering the
            // outer visual carrier as an empty group used to discard that
            // child and its selector identity, collapsing its authored tracks.
            $innerBlocks = array();
            if ( 0 < SourceDom::childElementCount($element)
                && '' === SourceDom::renderedTextContent($element)
                && $this->hasEmptyGridTrackDescendant($element)
            ) {
                $ignoredFallbacks = array();
                $innerBlocks = $this->tree->children($element, $ignoredFallbacks, true);
            }
            $block = $this->blocks->createBlock('core/group', $attrs, $innerBlocks, $element);
            $block['_editability_visual_owned'] = true;
            return $block;
        }

        $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
        $attrs['height'] = $this->styleResolver->resolveCssVariablesInValue($declarations['height']);
        $attrs['width'] = $this->styleResolver->resolveCssVariablesInValue($declarations['width']);

        return $this->blocks->createBlock('core/spacer', $attrs, array(), $element);
    }

    private function hasEmptyGridTrackDescendant(DOMElement $element): bool
    {
        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement
                && '' === SourceDom::renderedTextContent($descendant)
                && $this->emptyElementFootprint->hasAuthoredGridTracks($descendant)
            ) {
                return true;
            }
        }
        return false;
    }
}

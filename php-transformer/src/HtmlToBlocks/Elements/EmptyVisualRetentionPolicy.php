<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\Contract\RichTextInlineTags;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\SourceElementDescription;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\EmptyElementFootprint;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceElementVisibility;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\StyledSourceClassBoundary;
use Automattic\BlocksEngine\PhpTransformer\Support\ShellLandmarkPolicy;
use DOMElement;

/**
 * Decides whether an element with no rendered text keeps a block: the empty
 * box paints, holds space, carries runtime or fragment identity, or wraps
 * authored structure. The style footprint itself is EmptyElementFootprint's;
 * this policy feeds it the non-style evidence.
 */
final class EmptyVisualRetentionPolicy implements EmptyVisualRetention
{
    public function __construct(
        private readonly StyleResolver $styleResolver,
        private readonly HtmlTransformerSession $session,
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly SourceElementDescription $sourceElementDescription,
        private readonly RuntimeIslandAnalyzer $runtimeIslands,
        private readonly EmptyElementFootprint $emptyElementFootprint,
        private readonly SourceElementVisibility $sourceVisibility,
        private readonly RenderNeutralElements $renderNeutral,
        private readonly StyledSourceClassBoundary $styledClassBoundaries,
        private readonly GeneratedComponentLowering $generatedComponents
    ) {
    }

    public function shouldPreserveEmptyVisualElement(DOMElement $element): bool
    {
        return $this->emptyVisualDisposition($element)->isPreserve();
    }

    /**
     * Whether, and why, an element with no rendered content keeps a block:
     * {@see WrapperDecision::emptyVisualRetention()} owns the step order (#7471).
     */
    public function emptyVisualDisposition(DOMElement $element): WrapperDisposition
    {
        return WrapperDecision::emptyVisualRetention($this, $this->emptyElementFootprint, $element);
    }

    public function isInlineContentElement(DOMElement $element): bool
    {
        return $this->sourceElementClassifier->isInlineContentElement(strtolower($element->tagName));
    }

    /** An empty inline element is addressed by a runtime script or declared presentational. */
    public function inlineEmptyVisualIdentity(DOMElement $element): bool
    {
        return $this->runtimeIslands->isRuntimeDomTarget($element)
            || in_array(strtolower(SourceDom::attr($element, 'role')), array( 'presentation', 'none' ), true)
            || 'true' === strtolower(SourceDom::attr($element, 'aria-hidden'));
    }

    /** An empty block element is a runtime or fragment target, carries events, or has structure signals. */
    public function blockEmptyVisualIdentity(DOMElement $element): bool
    {
        $id = SourceDom::namedFragmentTargetId($element);
        return $this->runtimeIslands->isRuntimeDomTarget($element)
            || ( '' !== $id && SourceDom::documentReferencesFragmentId($element, $id) )
            || array() !== SourceDom::eventMetadata($element)
            || array() !== $this->sourceElementDescription->structureSignals($element, array());
    }

    /**
     * Page builders lay click-capture layers over media: an empty, positioned,
     * fully transparent box their own script listens on. It takes no flex gap
     * or grid cell, paints nothing at rest or in any state, and nothing in
     * WordPress interacts with it, so it is not a box the author can see or edit.
     */
    public function isInertOutOfFlowEmptyElement(DOMElement $element): bool
    {
        if ( 0 !== SourceDom::childElementCount($element)
            || $this->runtimeIslands->isRuntimeDomTarget($element)
            || array() !== $this->sourceElementDescription->interactiveAttributes($element)
            || $this->hasStaticPseudoElementRule($element)
        ) {
            return false;
        }
        foreach ( array( 'opacity', 'visibility', 'background', 'border', 'outline', 'box-shadow', 'filter', 'layout' ) as $family ) {
            if ( $this->styleResolver->hasStateStyleFamily($element, $family)
                || $this->styleResolver->hasConditionalStyleFamily($element, $family)
            ) {
                return false;
            }
        }
        $declarations = array_merge(
            $this->styleResolver->structuralPresentationDeclarations($element),
            $this->styleResolver->cascade->declarations($element),
            SourceDeclarations::inline($element)
        );
        $position = CssValueInspector::declared($declarations, 'position');
        $opacity = CssValueInspector::comparable($this->styleResolver->customProperties->resolve((string) ($declarations['opacity'] ?? ''), $element));
        $authoredPaint = array() !== array_intersect_key($declarations, array_flip(array( 'background', 'background-color', 'background-image', 'border', 'border-top', 'border-right', 'border-bottom', 'border-left', 'box-shadow', 'outline', 'mask', 'mask-image' )));

        return in_array($position, array( 'absolute', 'fixed' ), true)
            && is_numeric($opacity) && 0.0 === (float) $opacity
            && ! $authoredPaint;
    }

    public function isInertHiddenEmptyElement(DOMElement $element): bool
    {
        if ( 0 !== SourceDom::childElementCount($element)
            || '' !== trim($element->textContent ?? '')
            || ! $this->sourceVisibility->startsHidden($element)
            || $this->emptyElementFootprint->occupiesInFlowSpace($element)
            || $this->runtimeIslands->isRuntimeDomTarget($element)
            || $this->styleResolver->hasConditionalStyleFamily($element, 'layout')
            || $this->styleResolver->hasConditionalStyleFamily($element, 'visibility')
            || $this->styleResolver->hasConditionalStyleFamily($element, 'opacity')
        ) {
            return false;
        }

        foreach ( array( 'id', 'role', 'title', 'aria-label', 'aria-labelledby', 'aria-describedby' ) as $attribute ) {
            if ( '' !== trim(SourceDom::attr($element, $attribute)) ) {
                return false;
            }
        }

        return true;
    }

    public function hasSubstantiveSourceDescendant(DOMElement $element): bool
    {
        if ( '' !== SourceDom::renderedTextContent($element) ) {
            return true;
        }
        foreach ( array( 'audio', 'button', 'canvas', 'form', 'iframe', 'img', 'input', 'object', 'picture', 'select', 'svg', 'textarea', 'video' ) as $tagName ) {
            if ( 0 < $element->getElementsByTagName($tagName)->length ) {
                return true;
            }
        }
        return false;
    }

    /**
     * The one element child that carries content, skipping whitespace,
     * comments, and empty elements that render nothing.
     */
    public function soleElementChild(DOMElement $element): ?DOMElement
    {
        $child = null;
        foreach ( $element->childNodes as $node ) {
            // {@see WrapperDecision::soleChildSkip()} names the nodes that
            // carry no rendered, selector, or runtime boundary.
            if ( null !== WrapperDecision::soleChildSkip($this, $node) ) {
                continue;
            }
            if ( ! $node instanceof DOMElement || $child instanceof DOMElement ) {
                return null;
            }
            $child = $node;
        }

        return $child;
    }

    public function isExplicitlyDisplayNoneEmptyElement(DOMElement $element): bool
    {
        return 0 === SourceDom::childElementCount($element)
            && '' === trim($element->textContent ?? '')
            && 'none' === CssValueInspector::comparable((string) (SourceDeclarations::inline($element)['display'] ?? ''))
            && '' === trim(SourceDom::attr($element, 'class'))
            && '' === trim(SourceDom::attr($element, 'id'))
            && '' === trim(SourceDom::attr($element, 'role'))
            && array() === $this->sourceElementDescription->interactiveAttributes($element)
            && array() === SourceDom::safeDataAttributes($element);
    }

    public function shouldPreserveWrapper(DOMElement $element, ?string $asTag = null): bool
    {
        return $this->wrapperDisposition($element, $asTag)->isPreserve();
    }

    /** Whether, and why, a flow wrapper keeps its own block: {@see WrapperDecision::wrapperRetention()} (#7471). */
    public function wrapperDisposition(DOMElement $element, ?string $asTag = null): WrapperDisposition
    {
        return WrapperDecision::wrapperRetention($this, $element, $asTag);
    }

    /**
     * Elide only a neutral chain, not a media shell whose positioned child
     * requires an intrinsic-size carrier. Keep identity on its real box.
     */
    public function isNeutralGeneratedComponentChain(DOMElement $element, string $tag): bool
    {
        $child = 1 === SourceDom::childElementCount($element) ? $element->firstElementChild : null;
        return 'div' === strtolower($tag) && '' === trim(SourceDom::attr($element, 'id'))
            && $child instanceof DOMElement && 'div' === strtolower($child->tagName)
            && !$this->session->authorSelectorProjectionState()->hasSemanticMarker($element) && !$this->styledClassBoundaries->hasStyledSourceClassBoundary($element)
            && $this->renderNeutral->isRenderNeutralWrapper($element) && $this->renderNeutral->isRenderNeutralWrapper($child)
            && $this->containsGeneratedComponentCandidate($element);
    }

    public function isWrapperPreservingTag(DOMElement $element, string $tag): bool
    {
        return ShellLandmarkPolicy::isWrapperPreservingTag($tag) || RichTextInlineTags::isUnknownHtmlElement($element);
    }

    public function isRuntimeDomTarget(DOMElement $element): bool
    {
        return $this->runtimeIslands->isRuntimeDomTarget($element);
    }

    public function hasSemanticSelectorMarker(DOMElement $element): bool
    {
        return $this->session->authorSelectorProjectionState()->hasSemanticMarker($element);
    }

    /** Resolving presentation attributes registers style carriers, so its position in the guard order is output. */
    public function hasPresentationAttributes(DOMElement $element): bool
    {
        return array() !== $this->styleResolver->presentationAttributes($element);
    }

    public function hasStructureSignals(DOMElement $element): bool
    {
        return array() !== $this->sourceElementDescription->structureSignals($element, array());
    }

    public function hasStyledSourceClassBoundary(DOMElement $element): bool
    {
        return $this->styledClassBoundaries->hasStyledSourceClassBoundary($element);
    }

    /**
     * A wrapper styled only through a type or combinator selector
     * (`.card>div{aspect-ratio:16/9;overflow:hidden}`) has no class boundary
     * and no presentation attributes: the stylesheet projector rewrites that
     * rule onto the tag marker carried by whichever block represents this
     * element. Eliding the wrapper leaves the marker without a carrier, so
     * the rule's box (ratio, clip, padding, paint) silently disappears.
     * Zero lengths, `display:block` and a bare `position:relative` are
     * resets, not an owned box (a containing block is owned by the
     * positioned-descendant policies).
     */
    public function tagMarkerOwnsWrapperBox(DOMElement $element): bool
    {
        if ( '' === $this->session->authorSelectorProjectionState()->tagMarker(strtolower($element->tagName)) ) {
            return false;
        }
        foreach ( $this->emptyVisualTopologyEvidence($element) as $evidence ) {
            foreach ( $evidence['declarations'] as $property => $value ) {
                $value = CssValueInspector::comparable($value);
                if ( preg_match('/^-?0(?:\.0+)?(?:[a-z%]+)?(?:\s+-?0(?:\.0+)?(?:[a-z%]+)?)*$/', $value)
                    || ('display' === $property && in_array($value, array( 'block', 'flow-root' ), true))
                    || ('position' === $property && 'relative' === $value) ) {
                    continue;
                }
                return true;
            }
        }
        return false;
    }

    /**
     * A box styled only by declarations that have media/container variants
     * stays stylesheet-owned, so presentationAttributes() is empty. The rule
     * still paints the source wrapper: keep it as its own editable group,
     * without freezing one breakpoint's value inline (#6346). A styled class
     * boundary already owns its wrapper through the class hook.
     */
    public function responsiveStylesheetOwnsWrapperBox(DOMElement $element): bool
    {
        return array() !== $this->styleResolver->responsiveStylesheetOwnedBoxDeclarations($element)
            && ! $this->styledClassBoundaries->hasStyledSourceClassBoundary($element);
    }

    private function containsGeneratedComponentCandidate(DOMElement $element): bool
    {
        foreach (SourceDom::descendantElements($element) as $descendant) {
            if ($this->generatedComponents->isCandidate($descendant)) return true;
        }
        return false;
    }

    /**
     * Return resting stylesheet declarations which prove an otherwise empty group
     * is authored layout, paint, or control topology. Stateful selectors never
     * contribute: source capture has no interaction state to prove.
     *
     * @return list<array{selector: string, declarations: array<string, string>, specificity: int, order: int, source_path: string, source_hash: string}>
     */
    public function emptyVisualTopologyEvidence(DOMElement $element): array
    {
        $evidence = array();
        $matchedRules = array();
        foreach ( $this->session->authorStyleRuleCandidates($element) as $candidate ) {
            $ruleOrder = (int) $candidate['rule_order'];
            if ( isset($matchedRules[$ruleOrder])
                || ! ($candidate['parsed']['supported'] ?? false)
                || null !== ($candidate['parsed']['pseudo_state_suffix_span'] ?? null)
                || ! $this->session->sourceStyleResolutionState()->selectorMatchCache->matches($element, $candidate['selector'], $candidate['parsed'])['matches']
            ) {
                continue;
            }
            $matchedRules[$ruleOrder] = true;
            $declarations = array_filter(
                $candidate['declarations'],
                fn (string $value, string $property): bool => $this->sourceElementClassifier->isNonNeutralVisualTopologyDeclaration($property, $value),
                ARRAY_FILTER_USE_BOTH
            );
            if ( array() !== $declarations ) {
                $evidence[] = array(
                    'selector' => $candidate['selector'],
                    'declarations' => $declarations,
                    'specificity' => CssSelectorMatcher::specificity($candidate['parsed']),
                    'order' => $ruleOrder,
                    'source_path' => (string) ($candidate['source_path'] ?? ''),
                    'source_hash' => (string) ($candidate['source_hash'] ?? ''),
                );
            }
        }
        return $evidence;
    }

    public function hasStaticPseudoElementRule(DOMElement $element): bool
    {
        foreach ( $this->session->sourceStyleResolutionState()->pseudoElementRules() as $rule ) {
            if ( $this->session->sourceStyleResolutionState()->matches($element, $rule['selector']) ) {
                return true;
            }
        }
        return false;
    }

    public function retainsEmptyRichTextInline(DOMElement $sourceInline): bool
    {
        return $this->runtimeIslands->isRuntimeDomTarget($sourceInline)
            || $this->shouldPreserveEmptyVisualElement($sourceInline)
            || $this->hasStaticPseudoElementRule($sourceInline);
    }

    public function hasEmptyVisualInlineChild(DOMElement $element): bool
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && $this->sourceElementClassifier->isInlineContentElement(strtolower($child->tagName)) && $this->shouldPreserveEmptyVisualElement($child) ) {
                return true;
            }
        }

        return false;
    }

    public function preservesScriptStateWrapper(DOMElement $element): bool
    {
        return $this->scriptStateDisposition($element)->isPreserve();
    }

    /** Whether, and why, a wrapper holds script-observed state: {@see WrapperDecision::scriptState()} (#7471). */
    public function scriptStateDisposition(DOMElement $element): WrapperDisposition
    {
        return WrapperDecision::scriptState($this, $element);
    }
}

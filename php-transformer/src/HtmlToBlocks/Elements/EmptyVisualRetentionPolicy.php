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
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\LayoutShellBlockGenerator;
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
        return $this->emptyElementFootprint->shouldPreserveEmptyVisualElement($element, $this->emptyVisualRetentionPolicy($element));
    }

    /** Non-style retention decisions, evaluated only when the footprint query needs them. */
    private function emptyVisualRetentionPolicy(DOMElement $element): iterable
    {
        if ( '' !== SourceDom::renderedTextContent($element) || $this->isInertOutOfFlowEmptyElement($element) ) {
            yield 'drop' => true;
            return;
        }
        yield 'hidden' => $this->isInertHiddenEmptyElement($element);
        if ( $this->hasSubstantiveSourceDescendant($element) && $this->shouldPreserveWrapper($element) ) {
            yield 'keep' => true;
        }
        yield 'layout' => true;
        if ( $this->sourceElementClassifier->isInlineContentElement(strtolower($element->tagName)) ) {
            yield 'inline' => $this->runtimeIslands->isRuntimeDomTarget($element)
                || in_array(strtolower(SourceDom::attr($element, 'role')), array( 'presentation', 'none' ), true)
                || 'true' === strtolower(SourceDom::attr($element, 'aria-hidden'));
            return;
        }
        $id = SourceDom::namedFragmentTargetId($element);
        yield 'block' => $this->runtimeIslands->isRuntimeDomTarget($element)
            || ( '' !== $id && SourceDom::documentReferencesFragmentId($element, $id) )
            || array() !== SourceDom::eventMetadata($element)
            || array() !== $this->sourceElementDescription->structureSignals($element, array());
        yield 'keep' => $this->hasStaticPseudoElementRule($element);
        yield 'viewport' => $this->emptyVisualTopologyEvidence($element);
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
            $this->session->sourceCascade()->declarations($element),
            SourceDeclarations::inline($element)
        );
        $position = CssValueInspector::declared($declarations, 'position');
        $opacity = CssValueInspector::comparable($this->styleResolver->resolveCssVariablesInValue((string) ($declarations['opacity'] ?? ''), $element));
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

    private function hasSubstantiveSourceDescendant(DOMElement $element): bool
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
            if ( XML_TEXT_NODE === $node->nodeType && '' === trim($node->textContent ?? '') ) {
                continue;
            }
            // Source comments carry no rendered, selector, or runtime boundary.
            // Treating them as transparent lets generated exports shed an otherwise
            // inert authoring wrapper without interpreting product-specific markup.
            if ( XML_COMMENT_NODE === $node->nodeType ) {
                continue;
            }
            if ( $node instanceof DOMElement && ($this->isInertHiddenEmptyElement($node) || $this->isExplicitlyDisplayNoneEmptyElement($node)) ) {
                continue;
            }
            if ( ! $node instanceof DOMElement || $child instanceof DOMElement ) {
                return null;
            }
            $child = $node;
        }

        return $child;
    }

    private function isExplicitlyDisplayNoneEmptyElement(DOMElement $element): bool
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
        $tag = $asTag ?? $element->tagName;

        // Elide only a neutral chain, not a media shell whose positioned child
        // requires an intrinsic-size carrier. Keep identity on its real box.
        $child = 1 === SourceDom::childElementCount($element) ? $element->firstElementChild : null;
        if ('div' === strtolower($tag) && '' === trim(SourceDom::attr($element, 'id'))
            && $child instanceof DOMElement && 'div' === strtolower($child->tagName)
            && !$this->session->authorSelectorProjectionState()->hasSemanticMarker($element) && !$this->styledClassBoundaries->hasStyledSourceClassBoundary($element)
            && $this->renderNeutral->isRenderNeutralWrapper($element) && $this->renderNeutral->isRenderNeutralWrapper($child)
            && $this->containsGeneratedComponentCandidate($element)
        ) {
            return false;
        }

        return ( ShellLandmarkPolicy::isWrapperPreservingTag($tag) || RichTextInlineTags::isUnknownHtmlElement($element) ) && ( $this->runtimeIslands->isRuntimeDomTarget($element) || $this->session->authorSelectorProjectionState()->hasSemanticMarker($element) || array() !== $this->styleResolver->presentationAttributes($element) || array() !== $this->sourceElementDescription->structureSignals($element, array()) || $this->styledClassBoundaries->hasStyledSourceClassBoundary($element) );
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

    private function hasStaticPseudoElementRule(DOMElement $element): bool
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
        if (SourceDom::isCaptureDeviceVisibilityCarrier($element)) return true;
        if ( $this->isInertHiddenEmptyElement($element) ) {
            return false;
        }
        foreach ( $element->attributes ?? array() as $attribute ) {
            $name = strtolower($attribute->nodeName);
            if ( LayoutShellBlockGenerator::isBooleanAttribute($name) ) {
                return true;
            }
            if ( $this->runtimeIslands->isRuntimeDomTarget($element) && ( str_starts_with($name, 'data-') || str_starts_with($name, 'aria-') || 'role' === $name ) ) {
                return true;
            }
        }
        return false;
    }
}

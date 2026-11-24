<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\EmptyElementFootprint;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMElement;
use Generator;
use stdClass;

/**
 * The one owner of the keep/drop decision for a single-child source wrapper
 * (#7471, completing #1935's {@see WrapperDisposition} vocabulary).
 *
 * `decide()` answers what becomes of `$wrapper` around `$childBlock`. Every
 * check is a named guard that belongs to exactly one stage:
 *
 *  1. {@see self::STAGE_GEOMETRY}: does the wrapper's own box change
 *     rendering? Today's CSS proofs (inline/author declaration neutrality,
 *     structural-layout parents, layout-geometry proofs) are this stage's
 *     input; box-tree evidence (ADR 0006) becomes its primary input later.
 *  2. {@see self::STAGE_IDENTITY}: id or anchor target, role or landmark,
 *     saved block/document edge, interactive attributes, consumed data,
 *     motion and runtime targets.
 *  3. {@see self::STAGE_RESTATEMENT}: can the wrapper's identity (tag,
 *     classes, matched rules) be restated on the element that keeps it?
 *
 * Stage 1 of #7471 is byte-identical: guards are evaluated in exactly the
 * order, and with exactly the input gates, the inlined predicate used
 * before. Two inputs are side-effecting or nullable and therefore gate the
 * guards behind them: presentation attributes (resolving them registers
 * style carriers) are only resolved once every wrapper-only guard passed,
 * and the matching source child is only resolved after that. The stage a
 * guard belongs to is recorded on the resulting disposition, so callers and
 * tests can see which stage refused a wrapper without the evaluation order
 * (and thus the emitted output) changing.
 *
 * The signals themselves are still read from {@see WrapperCoalescer}, which
 * also uses them for the inherited-text and neutral-child absorption paths;
 * this class owns which signals decide, in what order, and under what name.
 *
 * Two more decision tables live here (#7471 stage 1, slice 2), each over the
 * signals of the class that used to decide locally:
 *
 *  - {@see self::inertScaffolding()}: which elements
 *    {@see InertScaffoldingSuppressor} drops before content dispatch;
 *  - {@see self::wrapperRetention()} and {@see self::emptyVisualRetention()}:
 *    which wrappers and empty boxes {@see EmptyVisualRetentionPolicy} keeps.
 *
 * Those tables are static and take their signal source as an argument
 * because both signal classes are built before the {@see WrapperCoalescer}
 * that this class's instance reads (the coalescer consumes
 * {@see EmptyVisualRetentionPolicy::soleElementChild()}). The guard order and
 * input gates are exactly those of the predicates they replace.
 */
final class WrapperDecision
{
    public const STAGE_GEOMETRY = 'geometry';
    public const STAGE_IDENTITY = 'identity';
    public const STAGE_RESTATEMENT = 'restatement';

    public const STAGES = array(self::STAGE_GEOMETRY, self::STAGE_IDENTITY, self::STAGE_RESTATEMENT);

    /**
     * @param Closure(DOMElement): bool $preservesScriptStateWrapper
     */
    public function __construct(
        private readonly WrapperCoalescer $signals,
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly RuntimeIslandAnalyzer $runtimeIslands,
        private readonly StyleResolver $styleResolver,
        private readonly Closure $preservesScriptStateWrapper
    ) {
    }

    /**
     * The complete answer for `$wrapper`: the preflight guards that keep it
     * before any coalescing path runs, then the single-child coalescing
     * guards.
     *
     * @param array<string, mixed> $childBlock
     */
    public function decide(DOMElement $wrapper, array $childBlock): WrapperDisposition
    {
        return $this->preflight($wrapper, $childBlock)
            ?? $this->resolve($wrapper, $childBlock)['disposition'];
    }

    /**
     * Guards that keep the wrapper before any coalescing path (inherited
     * text, neutral-child absorption or single-child coalescing) is tried.
     *
     * @param array<string, mixed> $childBlock
     */
    public function preflight(DOMElement $wrapper, array $childBlock): ?WrapperDisposition
    {
        return $this->firstRefusal(array(
            array(self::STAGE_IDENTITY, 'preserves_script_state', fn (): bool => ($this->preservesScriptStateWrapper)($wrapper)),
            array(self::STAGE_IDENTITY, 'child_has_runtime_attributes', fn (): bool => !empty($childBlock['attrs']['metadata']['blocksEngineRuntimeAttributes'])),
            array(self::STAGE_GEOMETRY, 'layout_geometry_corrective_target', fn (): bool => $this->signals->isLayoutGeometryCorrectiveTarget($wrapper)),
        ));
    }

    /**
     * The single-child coalescing decision together with the inputs the
     * coalesced block is built from.
     *
     * @param array<string, mixed> $childBlock
     * @return array{disposition: WrapperDisposition, proof: ?array<string, mixed>, attrs: array<string, mixed>, source_child: ?DOMElement}
     */
    public function resolve(DOMElement $wrapper, array $childBlock): array
    {
        $proof = $this->signals->layoutGeometryProofFor($wrapper);
        $fullWidthTransparentShell = $this->signals->hasOnlyFullWidthTransparentInlineGeometry($wrapper);
        $redundantNestedLayout = $this->signals->isRedundantNestedLayoutWrapper($wrapper, $childBlock);

        $refusal = $this->firstRefusal($this->wrapperGuards($wrapper, $childBlock, $proof, $fullWidthTransparentShell, $redundantNestedLayout));
        if (null !== $refusal) {
            return array('disposition' => $refusal, 'proof' => $proof, 'attrs' => array(), 'source_child' => null);
        }

        $attrs = $this->styleResolver->presentationAttributes($wrapper);
        $refusal = $this->firstRefusal($this->presentationGuards($attrs, $redundantNestedLayout));
        if (null !== $refusal) {
            return array('disposition' => $refusal, 'proof' => $proof, 'attrs' => $attrs, 'source_child' => null);
        }

        $sourceChild = $this->signals->matchingSourceChild($wrapper, $childBlock);
        $refusal = $this->firstRefusal($this->sourceChildGuards($wrapper, $childBlock, $sourceChild, $proof, $fullWidthTransparentShell, $redundantNestedLayout));
        if (null !== $refusal) {
            return array('disposition' => $refusal, 'proof' => $proof, 'attrs' => $attrs, 'source_child' => $sourceChild);
        }

        return array('disposition' => WrapperDisposition::coalesce('single_child_wrapper_absorbed'), 'proof' => $proof, 'attrs' => $attrs, 'source_child' => $sourceChild);
    }

    /**
     * Every guard name, in evaluation order, keyed to its stage. This is the
     * inventory of what `resolve()` checks; tests pin it so a guard cannot be
     * added, dropped or moved between stages silently.
     *
     * @return array<string, string>
     */
    public static function guardStages(): array
    {
        return array(
            'preserves_script_state' => self::STAGE_IDENTITY,
            'child_has_runtime_attributes' => self::STAGE_IDENTITY,
            'layout_geometry_corrective_target' => self::STAGE_GEOMETRY,
            'saved_group_boundary' => self::STAGE_IDENTITY,
            'child_is_binding_anchor' => self::STAGE_IDENTITY,
            'unrepresentable_wrapper_tag' => self::STAGE_RESTATEMENT,
            'unsupported_child_block_name' => self::STAGE_RESTATEMENT,
            'full_width_shell_requires_group_child' => self::STAGE_GEOMETRY,
            'runtime_dom_target' => self::STAGE_IDENTITY,
            'structural_layout_child_without_proof' => self::STAGE_GEOMETRY,
            'has_id_attribute' => self::STAGE_IDENTITY,
            'has_role_attribute' => self::STAGE_IDENTITY,
            'non_neutral_geometry_without_proof' => self::STAGE_GEOMETRY,
            'has_interactive_attributes' => self::STAGE_IDENTITY,
            'has_data_attributes_without_proof' => self::STAGE_IDENTITY,
            'has_structure_signals_without_proof' => self::STAGE_IDENTITY,
            'has_motion_structure_token' => self::STAGE_IDENTITY,
            'unsupported_presentation_attributes' => self::STAGE_GEOMETRY,
            'missing_matching_source_child' => self::STAGE_RESTATEMENT,
            'image_source_child_type_mismatch' => self::STAGE_RESTATEMENT,
            'source_child_has_motion_structure_token' => self::STAGE_IDENTITY,
            'box_affecting_declarations_not_neutral' => self::STAGE_GEOMETRY,
            'full_width_shell_child_not_normal_flow' => self::STAGE_GEOMETRY,
            'containing_block_dependent_declarations' => self::STAGE_GEOMETRY,
            'selector_matching_does_not_survive_coalescing' => self::STAGE_RESTATEMENT,
        );
    }

    /**
     * Wrapper-only guards: is this the shape coalescing applies to, with no
     * identity of its own and no box that changes rendering (unless a
     * layout-geometry proof or a redundant-nested-layout finding accounts
     * for it)?
     *
     * @param array<string, mixed> $childBlock
     * @param array<string, mixed>|null $proof
     * @return array<int, array{0: string, 1: string, 2: Closure(): bool}>
     */
    private function wrapperGuards(DOMElement $element, array $childBlock, ?array $proof, bool $fullWidthTransparentShell, bool $redundantNestedLayout): array
    {
        return array(
            // A saved core/group boundary is a coalescing decision already made:
            // its save shape no longer carries the source signals (data
            // attributes, an anchor tag) that kept it, so re-deciding from the
            // saved markup would fold a wrapper the first transform preserved.
            array(self::STAGE_IDENTITY, 'saved_group_boundary', fn (): bool => SourceDom::hasClass($element, 'wp-block-group')),
            array(self::STAGE_IDENTITY, 'child_is_binding_anchor', fn (): bool => $this->signals->isBindingAnchor($childBlock)),
            array(self::STAGE_RESTATEMENT, 'unrepresentable_wrapper_tag', fn (): bool => ! $this->signals->wrapperTagIsRepresentableOnChild($element, $childBlock)),
            array(self::STAGE_RESTATEMENT, 'unsupported_child_block_name', fn (): bool => ! in_array($childBlock['blockName'] ?? null, array( 'core/group', 'core/image' ), true)),
            array(self::STAGE_GEOMETRY, 'full_width_shell_requires_group_child', fn (): bool => $fullWidthTransparentShell && 'core/group' !== ($childBlock['blockName'] ?? null)),
            array(self::STAGE_IDENTITY, 'runtime_dom_target', fn (): bool => $this->runtimeIslands->isRuntimeDomTarget($element)),
            array(self::STAGE_GEOMETRY, 'structural_layout_child_without_proof', fn (): bool => null === $proof && $this->signals->isDirectChildOfStructuralLayout($element) && ! $redundantNestedLayout),
            array(self::STAGE_IDENTITY, 'has_id_attribute', fn (): bool => '' !== trim(SourceDom::attr($element, 'id'))),
            array(self::STAGE_IDENTITY, 'has_role_attribute', fn (): bool => '' !== trim(SourceDom::attr($element, 'role'))),
            array(self::STAGE_GEOMETRY, 'non_neutral_geometry_without_proof', fn (): bool => null === $proof && ! $fullWidthTransparentShell && ! $this->signals->hasOnlyRenderNeutralInlineGeometry($element) && ! $redundantNestedLayout),
            array(self::STAGE_IDENTITY, 'has_interactive_attributes', fn (): bool => array() !== $this->signals->interactiveAttributes($element)),
            array(self::STAGE_IDENTITY, 'has_data_attributes_without_proof', fn (): bool => null === $proof && array() !== SourceDom::safeDataAttributes($element)),
            array(self::STAGE_IDENTITY, 'has_structure_signals_without_proof', fn (): bool => null === $proof && $this->signals->hasBlockingStructureSignals($element, $childBlock) && ! $redundantNestedLayout),
            array(self::STAGE_IDENTITY, 'has_motion_structure_token', fn (): bool => $this->sourceElementClassifier->hasMotionStructureToken($element)),
        );
    }

    /**
     * @param array<string, mixed> $attrs
     * @return array<int, array{0: string, 1: string, 2: Closure(): bool}>
     */
    private function presentationGuards(array $attrs, bool $redundantNestedLayout): array
    {
        return array(
            array(self::STAGE_GEOMETRY, 'unsupported_presentation_attributes', fn (): bool => ! $redundantNestedLayout && array() !== array_diff(array_keys($attrs), array( 'className', 'style' ))),
        );
    }

    /**
     * Once the wrapper itself qualifies, a single source child must carry the
     * child block's identity forward cleanly. The selector-survival check is
     * the expensive one and stays last.
     *
     * @param array<string, mixed> $childBlock
     * @param array<string, mixed>|null $proof
     * @return array<int, array{0: string, 1: string, 2: Closure(): bool}>
     */
    private function sourceChildGuards(DOMElement $element, array $childBlock, ?DOMElement $sourceChild, ?array $proof, bool $fullWidthTransparentShell, bool $redundantNestedLayout): array
    {
        return array(
            array(self::STAGE_RESTATEMENT, 'missing_matching_source_child', fn (): bool => ! $sourceChild instanceof DOMElement),
            array(self::STAGE_RESTATEMENT, 'image_source_child_type_mismatch', fn (): bool => 'core/image' === ($childBlock['blockName'] ?? null) && ! in_array(strtolower($sourceChild->tagName), array( 'img', 'svg' ), true) && ! str_contains($sourceChild->tagName, '-')),
            array(self::STAGE_IDENTITY, 'source_child_has_motion_structure_token', fn (): bool => $this->sourceElementClassifier->hasMotionStructureToken($sourceChild)),
            array(self::STAGE_GEOMETRY, 'box_affecting_declarations_not_neutral', fn (): bool => null === $proof && ! $redundantNestedLayout && ($fullWidthTransparentShell ? ! $this->signals->hasOnlyFullWidthTransparentBoxAffectingDeclarations($element) : ! $this->signals->hasOnlyRenderNeutralBoxAffectingDeclarations($element))),
            array(self::STAGE_GEOMETRY, 'full_width_shell_child_not_normal_flow', fn (): bool => null === $proof && $fullWidthTransparentShell && ! $this->signals->isNormalFlowFullWidthShellChild($sourceChild)),
            array(self::STAGE_GEOMETRY, 'containing_block_dependent_declarations', fn (): bool => 'core/image' !== ($childBlock['blockName'] ?? null) && ! $redundantNestedLayout && $this->signals->hasContainingBlockDependentAuthorDeclarations($sourceChild)),
            array(self::STAGE_RESTATEMENT, 'selector_matching_does_not_survive_coalescing', fn (): bool => null === $proof && ! $this->signals->syntheticImageGeometryLeaf($childBlock) && ! $this->signals->selectorMatchingSurvivesWrapperCoalescing($element, $sourceChild, $fullWidthTransparentShell)),
        );
    }

    /** @param array<int, array{0: string, 1: string, 2: Closure(): bool}> $guards */
    private function firstRefusal(array $guards): ?WrapperDisposition
    {
        return self::firstMatch($guards, WrapperDisposition::preserve(...));
    }

    /**
     * The first guard that matches, in table order, named by `$outcome`.
     * Guards after the first match are never evaluated: several read the
     * cascade or register style carriers, so evaluation order is output.
     *
     * @param array<int, array{0: string, 1: string, 2: Closure(): bool}> $guards
     * @param Closure(string, string): WrapperDisposition $outcome
     */
    private static function firstMatch(array $guards, Closure $outcome): ?WrapperDisposition
    {
        foreach ( $guards as [$stage, $reason, $matches] ) {
            if ( $matches() ) {
                return $outcome($reason, $stage);
            }
        }
        return null;
    }

    /**
     * Inert scaffolding that is dropped before content dispatch, or null when
     * the element is not scaffolding and continues to its converter. Formerly
     * the inlined `if` chain of {@see InertScaffoldingSuppressor::convert()}.
     */
    public static function inertScaffolding(InertScaffoldingSuppressor $signals, DOMElement $element, string $tagName): ?WrapperDisposition
    {
        return self::firstMatch(array(
            // Capture selection notices belong to its runtime, not source
            // content, regardless of the selected device or runtime CSS.
            array(self::STAGE_IDENTITY, 'capture_device_unavailable_notice', fn (): bool => $element->hasAttribute(CaptureEvidence::DEVICE_UNAVAILABLE)),
            // A closed, script-populated dialog shell has no source content to
            // lose. Open dialogs render even when empty (including user-agent
            // border and padding); they and dialogs with descendants retain
            // their existing path.
            array(self::STAGE_GEOMETRY, 'closed_empty_dialog', fn (): bool => 'dialog' === $tagName
                && ! $element->hasAttribute('open')
                && 0 === SourceDom::childElementCount($element)
                && '' === trim($element->textContent ?? '')),
            array(self::STAGE_GEOMETRY, 'inert_hidden_capture_iframe', fn (): bool => 'iframe' === $tagName && $signals->isInertHiddenCaptureIframe($element)),
            array(self::STAGE_GEOMETRY, 'measurement_copy', fn (): bool => $signals->isCascadedMeasurementCopy($element)),
            array(self::STAGE_GEOMETRY, 'hidden_presentation_scaffolding', fn (): bool => $signals->isHiddenPresentationScaffolding($element)),
            array(self::STAGE_IDENTITY, 'inert_live_region_scaffolding', fn (): bool => $signals->isInertLiveRegionScaffolding($element)),
            array(self::STAGE_GEOMETRY, 'inert_empty_custom_element', fn (): bool => str_contains($tagName, '-') && $signals->isInertEmptyCustomElement($element)),
            array(self::STAGE_GEOMETRY, 'inert_empty_container', fn (): bool => 'div' === $tagName && $signals->isInertEmptyContainer($element)),
        ), WrapperDisposition::drop(...));
    }

    /**
     * Does a flow wrapper keep its own block boundary (`preserve`), or are its
     * children emitted in its place (`fold`)? Formerly the single boolean of
     * {@see EmptyVisualRetentionPolicy::shouldPreserveWrapper()}.
     */
    public static function wrapperRetention(EmptyVisualRetentionPolicy $signals, DOMElement $element, ?string $asTag = null): WrapperDisposition
    {
        $tag = $asTag ?? $element->tagName;

        return self::firstMatch(array(
            array(self::STAGE_GEOMETRY, 'neutral_generated_component_chain', fn (): bool => $signals->isNeutralGeneratedComponentChain($element, $tag)),
            array(self::STAGE_IDENTITY, 'tag_does_not_preserve_wrapper', fn (): bool => ! $signals->isWrapperPreservingTag($element, $tag)),
        ), WrapperDisposition::fold(...))
            ?? self::firstMatch(array(
                array(self::STAGE_IDENTITY, 'runtime_dom_target', fn (): bool => $signals->isRuntimeDomTarget($element)),
                array(self::STAGE_RESTATEMENT, 'semantic_selector_marker', fn (): bool => $signals->hasSemanticSelectorMarker($element)),
                array(self::STAGE_GEOMETRY, 'presentation_attributes', fn (): bool => $signals->hasPresentationAttributes($element)),
                array(self::STAGE_GEOMETRY, 'responsive_stylesheet_box', fn (): bool => $signals->responsiveStylesheetOwnsWrapperBox($element)),
                array(self::STAGE_IDENTITY, 'structure_signals', fn (): bool => $signals->hasStructureSignals($element)),
                array(self::STAGE_RESTATEMENT, 'styled_source_class_boundary', fn (): bool => $signals->hasStyledSourceClassBoundary($element)),
                array(self::STAGE_RESTATEMENT, 'tag_marker_box', fn (): bool => $signals->tagMarkerOwnsWrapperBox($element)),
            ), WrapperDisposition::preserve(...))
            ?? WrapperDisposition::fold('no_wrapper_box_or_identity');
    }

    /**
     * Does an element with no rendered content keep a block (`preserve`) or
     * not (`drop`)? The non-style steps are named here, in the order
     * {@see EmptyVisualRetentionPolicy} yielded them; the style footprint
     * that consumes each step stays {@see EmptyElementFootprint}'s. The
     * disposition names the step at which the footprint decided.
     */
    public static function emptyVisualRetention(EmptyVisualRetentionPolicy $signals, EmptyElementFootprint $footprint, DOMElement $element): WrapperDisposition
    {
        $step = new stdClass();
        $step->reason = null;
        $step->stage = null;
        $steps = self::emptyVisualSteps($signals, $element, $step);
        $keep = $footprint->shouldPreserveEmptyVisualElement($element, $steps);
        if ( ! $steps->valid() ) {
            // Every step passed without deciding: the footprint's own
            // inline-candidate fallback answered.
            $step->reason = 'empty_visual_inline_candidate';
            $step->stage = self::STAGE_GEOMETRY;
        }

        return $keep
            ? WrapperDisposition::preserve((string) $step->reason, $step->stage)
            : WrapperDisposition::drop((string) $step->reason, $step->stage);
    }

    /**
     * Lazy, so a step's evidence is only gathered when the footprint reaches
     * it; `$step` names the step the footprint is consuming.
     *
     * @return Generator<string, bool>
     */
    private static function emptyVisualSteps(EmptyVisualRetentionPolicy $signals, DOMElement $element, stdClass $step): Generator
    {
        $at = static function (string $stage, string $reason) use ($step): void {
            $step->stage = $stage;
            $step->reason = $reason;
        };

        if ( '' !== SourceDom::renderedTextContent($element) ) {
            $at(self::STAGE_IDENTITY, 'has_rendered_text');
            yield 'drop' => true;
            return;
        }
        if ( $signals->isInertOutOfFlowEmptyElement($element) ) {
            $at(self::STAGE_GEOMETRY, 'inert_out_of_flow_empty_element');
            yield 'drop' => true;
            return;
        }
        $hidden = $signals->isInertHiddenEmptyElement($element);
        $at(self::STAGE_GEOMETRY, 'inert_hidden_empty_element');
        yield 'hidden' => $hidden;
        if ( $signals->hasSubstantiveSourceDescendant($element) && $signals->shouldPreserveWrapper($element) ) {
            $at(self::STAGE_IDENTITY, 'preserved_wrapper_with_substantive_descendant');
            yield 'keep' => true;
        }
        $at(self::STAGE_GEOMETRY, 'empty_layout_item_spacer');
        yield 'layout' => true;
        if ( $signals->isInlineContentElement($element) ) {
            $inline = $signals->inlineEmptyVisualIdentity($element);
            $at(self::STAGE_GEOMETRY, 'inline_empty_visual');
            yield 'inline' => $inline;
            return;
        }
        $block = $signals->blockEmptyVisualIdentity($element);
        $at(self::STAGE_IDENTITY, 'block_identity_or_box');
        yield 'block' => $block;
        $pseudo = $signals->hasStaticPseudoElementRule($element);
        $at(self::STAGE_GEOMETRY, 'static_pseudo_element_rule');
        yield 'keep' => $pseudo;
        $topology = $signals->emptyVisualTopologyEvidence($element);
        $at(self::STAGE_GEOMETRY, 'renderable_box_at_any_viewport');
        yield 'viewport' => $topology;
    }

    /**
     * Guard names of the scaffolding and retention tables, in evaluation
     * order, keyed to their stage. Pinned by tests like {@see guardStages()}.
     *
     * @return array<string, array<string, string>>
     */
    public static function retentionGuardStages(): array
    {
        return array(
            'inert_scaffolding' => array(
                'capture_device_unavailable_notice' => self::STAGE_IDENTITY,
                'closed_empty_dialog' => self::STAGE_GEOMETRY,
                'inert_hidden_capture_iframe' => self::STAGE_GEOMETRY,
                'measurement_copy' => self::STAGE_GEOMETRY,
                'hidden_presentation_scaffolding' => self::STAGE_GEOMETRY,
                'inert_live_region_scaffolding' => self::STAGE_IDENTITY,
                'inert_empty_custom_element' => self::STAGE_GEOMETRY,
                'inert_empty_container' => self::STAGE_GEOMETRY,
            ),
            'wrapper_retention' => array(
                'neutral_generated_component_chain' => self::STAGE_GEOMETRY,
                'tag_does_not_preserve_wrapper' => self::STAGE_IDENTITY,
                'runtime_dom_target' => self::STAGE_IDENTITY,
                'semantic_selector_marker' => self::STAGE_RESTATEMENT,
                'presentation_attributes' => self::STAGE_GEOMETRY,
                'responsive_stylesheet_box' => self::STAGE_GEOMETRY,
                'structure_signals' => self::STAGE_IDENTITY,
                'styled_source_class_boundary' => self::STAGE_RESTATEMENT,
                'tag_marker_box' => self::STAGE_RESTATEMENT,
            ),
            'empty_visual_retention' => array(
                'has_rendered_text' => self::STAGE_IDENTITY,
                'inert_out_of_flow_empty_element' => self::STAGE_GEOMETRY,
                'inert_hidden_empty_element' => self::STAGE_GEOMETRY,
                'preserved_wrapper_with_substantive_descendant' => self::STAGE_IDENTITY,
                'empty_layout_item_spacer' => self::STAGE_GEOMETRY,
                'inline_empty_visual' => self::STAGE_GEOMETRY,
                'block_identity_or_box' => self::STAGE_IDENTITY,
                'static_pseudo_element_rule' => self::STAGE_GEOMETRY,
                'renderable_box_at_any_viewport' => self::STAGE_GEOMETRY,
                'empty_visual_inline_candidate' => self::STAGE_GEOMETRY,
            ),
        );
    }
}

<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\Contract\RichTextInlineTags;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\LayoutShellBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\EmptyElementFootprint;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMElement;
use DOMNode;
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
 *
 * Slice 3 adds the remaining coalescer and flow-container tables (see
 * {@see self::loweringGuardStages()}): saved group-chain compression, the
 * proof-backed, inherited-text and neutral-child coalescing paths, the
 * single-child candidate and retention reads shared by flow containers,
 * author-layout lowering and list items, the author-layout empty child, the
 * script-state guard and the sole-element-child skip.
 *
 * Slice 4 adds the conversion-site tables (see
 * {@see self::conversionGuardStages()}): custom wrapper conventions, the
 * render-neutral wrapper, custom flow wrapper and transparent unknown
 * element decisions, unproven custom elements, custom image hosts, empty
 * search/cart shells, inline text wrappers, figure captions and anchor
 * wrappers. Signals that need the session stay in their classes and are
 * passed in as closures or signal objects, evaluated lazily in today's order.
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

    /**
     * Should a run of single-child saved `core/group` ancestors fold into one
     * layout shell before their descendants normalize, because native
     * nesting would breach the saved depth budget? Formerly the first
     * inlined condition of {@see WrapperCoalescer::compressProjectedGroupChains()}.
     * Returns a `fold` when the chain folds and a `preserve` naming the
     * exemption otherwise.
     *
     * @param Closure(): bool $exceedsDepthBudget
     */
    public static function ancestorChainCompression(bool $nativeNavigationChain, int $chainLength, Closure $exceedsDepthBudget): WrapperDisposition
    {
        return self::firstMatch(array(
            array(self::STAGE_IDENTITY, 'native_navigation_chain', fn (): bool => $nativeNavigationChain),
            array(self::STAGE_RESTATEMENT, 'single_wrapper_ancestry', fn (): bool => 2 > $chainLength),
        ), WrapperDisposition::preserve(...))
            ?? self::firstMatch(array(
                array(self::STAGE_RESTATEMENT, 'saved_depth_exceeds_nesting_budget', $exceedsDepthBudget),
            ), WrapperDisposition::fold(...))
            ?? WrapperDisposition::preserve('within_nesting_budget', self::STAGE_RESTATEMENT);
    }

    /**
     * Does a collected saved group chain fold into a generated layout shell,
     * or stay native nested groups? Formerly the second inlined condition of
     * {@see WrapperCoalescer::compressProjectedGroupChains()}.
     */
    public static function projectedChainFold(bool $nativeGroupTree, int $chainLength, bool $terminalIsShell): WrapperDisposition
    {
        return self::firstMatch(array(
            array(self::STAGE_RESTATEMENT, 'native_group_tree_within_budget', fn (): bool => $nativeGroupTree),
        ), WrapperDisposition::preserve(...))
            ?? self::firstMatch(array(
                array(self::STAGE_RESTATEMENT, 'wrapper_chain', fn (): bool => 2 <= $chainLength),
                array(self::STAGE_RESTATEMENT, 'wrapper_around_layout_shell', fn (): bool => 0 < $chainLength && $terminalIsShell),
            ), WrapperDisposition::fold(...))
            ?? WrapperDisposition::preserve('no_foldable_chain', self::STAGE_RESTATEMENT);
    }

    /**
     * Wrapper guards of the proof-backed path, evaluated before its children
     * are converted. Formerly the inlined condition of
     * {@see WrapperCoalescer::proofBackedWrapperCoalescing()}.
     *
     * @param array<string, mixed>|null $proof
     */
    public function proofBackedWrapper(DOMElement $element, ?array $proof): ?WrapperDisposition
    {
        return $this->firstRefusal(array(
            array(self::STAGE_GEOMETRY, 'no_layout_geometry_proof', fn (): bool => null === $proof),
            array(self::STAGE_GEOMETRY, 'layout_geometry_corrective_target', fn (): bool => $this->signals->isLayoutGeometryCorrectiveTarget($element)),
            array(self::STAGE_IDENTITY, 'runtime_dom_target', fn (): bool => $this->runtimeIslands->isRuntimeDomTarget($element)),
            array(self::STAGE_IDENTITY, 'has_id_attribute', fn (): bool => '' !== trim(SourceDom::attr($element, 'id'))),
            array(self::STAGE_IDENTITY, 'has_role_attribute', fn (): bool => '' !== trim(SourceDom::attr($element, 'role'))),
            array(self::STAGE_IDENTITY, 'has_interactive_attributes', fn (): bool => array() !== $this->signals->describedInteractiveAttributes($element)),
            array(self::STAGE_IDENTITY, 'has_motion_structure_token', fn (): bool => $this->sourceElementClassifier->hasMotionStructureToken($element)),
        ));
    }

    /**
     * Child guards of the proof-backed path, once the wrapper's children are
     * converted: one native child block whose source element is the proof's
     * target.
     *
     * @param array<int, array<string, mixed>> $children
     * @param array<string, mixed> $proof
     * @return array{disposition: WrapperDisposition, source_child: ?DOMElement}
     */
    public function proofBackedChild(DOMElement $element, array $children, array $proof): array
    {
        $refusal = $this->firstRefusal(array(
            array(self::STAGE_RESTATEMENT, 'not_single_child_block', fn (): bool => 1 !== count($children)),
            array(self::STAGE_RESTATEMENT, 'unsupported_child_block_name', fn (): bool => ! in_array($children[0]['blockName'] ?? null, array('core/group', 'core/image'), true)),
            array(self::STAGE_IDENTITY, 'child_is_binding_anchor', fn (): bool => $this->signals->isBindingAnchor($children[0])),
        ));
        if (null !== $refusal) {
            return array('disposition' => $refusal, 'source_child' => null);
        }
        $sourceChild = $this->signals->soleElementChildOf($element);
        $refusal = $this->firstRefusal(array(
            array(self::STAGE_RESTATEMENT, 'source_child_is_not_proof_target', fn (): bool => ! $sourceChild instanceof DOMElement || SourceDom::elementSelector($sourceChild) !== ($proof['target_selector'] ?? null)),
        ));

        return array(
            'disposition' => $refusal ?? WrapperDisposition::coalesce('layout_geometry_proof', self::STAGE_GEOMETRY),
            'source_child' => $sourceChild,
        );
    }

    /**
     * Wrapper guards of the inherited-text path: a text-only wrapper whose
     * inherited declarations can be restated on the child block. Formerly
     * the leading checks of `WrapperCoalescer::coalescedInheritedTextWrapper()`.
     *
     * @param array<string, mixed> $childBlock
     */
    public function inheritedTextWrapper(DOMElement $element, array $childBlock): ?WrapperDisposition
    {
        return $this->firstRefusal(array(
            array(self::STAGE_IDENTITY, 'child_is_binding_anchor', fn (): bool => $this->signals->isBindingAnchor($childBlock)),
            array(self::STAGE_GEOMETRY, 'not_inherited_text_wrapper', fn (): bool => ! $this->signals->isInheritedTextWrapper($element)),
            array(self::STAGE_RESTATEMENT, 'unsupported_child_block_name', fn (): bool => ! in_array($childBlock['blockName'] ?? '', array('core/paragraph', 'core/heading', 'core/group', 'core/image', 'core/quote', 'core/list'), true)),
        ));
    }

    /**
     * Leaf guards of the inherited-text path, after the text-wrapper chain
     * has been walked to `$leaf`.
     *
     * @param array<string, mixed> $childBlock
     */
    public function inheritedTextLeaf(DOMElement $element, array $childBlock, ?DOMElement $leaf): ?WrapperDisposition
    {
        return $this->firstRefusal(array(
            array(self::STAGE_RESTATEMENT, 'missing_inherited_text_leaf', fn (): bool => ! $leaf instanceof DOMElement),
            array(self::STAGE_RESTATEMENT, 'paragraph_leaf_is_not_p', fn (): bool => 'core/paragraph' === ($childBlock['blockName'] ?? '') && 'p' !== strtolower($leaf->tagName)),
            array(self::STAGE_RESTATEMENT, 'selector_matching_does_not_survive_coalescing', fn (): bool => ! $this->signals->selectorMatchingSurvivesWrapperCoalescing($element, $leaf, true)),
        ));
    }

    /**
     * The last inherited-text guard: something is left to restate.
     *
     * @param array<string, string> $values
     */
    public static function inheritedTextValues(array $values): WrapperDisposition
    {
        return self::firstMatch(array(
            array(self::STAGE_RESTATEMENT, 'no_inherited_text_declarations', fn (): bool => array() === $values),
        ), WrapperDisposition::preserve(...))
            ?? WrapperDisposition::coalesce('inherited_text_restated', self::STAGE_RESTATEMENT);
    }

    /**
     * May a padded wrapper absorb its render-neutral child (the child's box
     * disappears, the wrapper's stays)? Formerly the inlined `if` chains of
     * `WrapperCoalescer::absorbedNeutralChild()`, in the same order and with
     * the same input gates: the wrapper's author declarations are read only
     * once the wrapper guards pass, the source child only once the wrapper's
     * box qualifies, and the child's declarations only once the child does.
     *
     * @param array<string, mixed> $childBlock
     * @return array{disposition: WrapperDisposition, source_child: ?DOMElement}
     */
    public function neutralChildAbsorption(DOMElement $element, array $childBlock): array
    {
        $name = $childBlock['blockName'] ?? '';
        $refusal = $this->firstRefusal(array(
            array(self::STAGE_RESTATEMENT, 'unsupported_child_block_name', fn (): bool => ! in_array($name, array('core/group', 'core/paragraph'), true)),
            array(self::STAGE_RESTATEMENT, 'wrapper_is_not_div', fn (): bool => 'div' !== strtolower($element->tagName)),
            array(self::STAGE_IDENTITY, 'saved_group_boundary', fn (): bool => SourceDom::hasClass($element, 'wp-block-group')),
            array(self::STAGE_IDENTITY, 'child_is_binding_anchor', fn (): bool => $this->signals->isBindingAnchor($childBlock)),
            array(self::STAGE_IDENTITY, 'runtime_dom_target', fn (): bool => $this->runtimeIslands->isRuntimeDomTarget($element)),
            array(self::STAGE_IDENTITY, 'has_motion_structure_token', fn (): bool => $this->sourceElementClassifier->hasMotionStructureToken($element)),
            array(self::STAGE_IDENTITY, 'has_id_attribute', fn (): bool => '' !== SourceDom::attr($element, 'id')),
            array(self::STAGE_IDENTITY, 'has_role_attribute', fn (): bool => '' !== SourceDom::attr($element, 'role')),
            array(self::STAGE_IDENTITY, 'has_interactive_attributes', fn (): bool => array() !== $this->signals->interactiveAttributes($element)),
            array(self::STAGE_IDENTITY, 'has_data_attributes', fn (): bool => array() !== SourceDom::safeDataAttributes($element)),
            array(self::STAGE_GEOMETRY, 'structural_layout_element', fn (): bool => $this->signals->isStructuralLayoutElement($element)),
        ));
        if (null !== $refusal) {
            return array('disposition' => $refusal, 'source_child' => null);
        }

        $parentDeclarations = $this->signals->matchingAuthorDeclarations($element);
        $refusal = $this->firstRefusal(array(
            array(self::STAGE_GEOMETRY, 'wrapper_has_no_padded_box', fn (): bool => ! $this->signals->hasPaddedBox($parentDeclarations)),
            array(self::STAGE_GEOMETRY, 'paragraph_wrapper_paints_no_background', fn (): bool => 'core/paragraph' === $name && ! isset($parentDeclarations['background']) && ! isset($parentDeclarations['background-color'])),
            array(self::STAGE_RESTATEMENT, 'wrapper_declares_inherited_text', fn (): bool => $this->signals->declaresInheritedText($parentDeclarations)),
        ));
        if (null !== $refusal) {
            return array('disposition' => $refusal, 'source_child' => null);
        }

        $child = $this->signals->soleElementChildOf($element);
        $refusal = $this->firstRefusal(array(
            array(self::STAGE_RESTATEMENT, 'missing_sole_child', fn (): bool => ! $child instanceof DOMElement),
            // Conversion may already have promoted a deeper styled descendant
            // through this neutral source node. Its declarations, not this
            // node's neutrality, own the converted block's box.
            array(self::STAGE_RESTATEMENT, 'child_block_not_from_sole_child', fn (): bool => $this->signals->matchingSourceChild($element, $childBlock) !== $child),
            array(self::STAGE_RESTATEMENT, 'child_tag_mismatch', fn (): bool => ! in_array(strtolower($child->tagName), 'core/paragraph' === $name ? array('p') : array('div'), true)),
            array(self::STAGE_IDENTITY, 'child_runtime_dom_target', fn (): bool => $this->runtimeIslands->isRuntimeDomTarget($child)),
            array(self::STAGE_IDENTITY, 'child_has_motion_structure_token', fn (): bool => $this->sourceElementClassifier->hasMotionStructureToken($child)),
            array(self::STAGE_IDENTITY, 'child_has_id_attribute', fn (): bool => '' !== SourceDom::attr($child, 'id')),
            array(self::STAGE_IDENTITY, 'child_has_role_attribute', fn (): bool => '' !== SourceDom::attr($child, 'role')),
            array(self::STAGE_IDENTITY, 'child_has_interactive_attributes', fn (): bool => array() !== $this->signals->interactiveAttributes($child)),
            array(self::STAGE_IDENTITY, 'child_has_data_attributes', fn (): bool => array() !== SourceDom::safeDataAttributes($child)),
            array(self::STAGE_GEOMETRY, 'child_box_affecting_declarations_not_neutral', fn (): bool => ! $this->signals->hasOnlyRenderNeutralBoxAffectingDeclarations($child)),
            array(self::STAGE_RESTATEMENT, 'selector_matching_does_not_survive_coalescing', fn (): bool => ! $this->signals->selectorMatchingSurvivesWrapperCoalescing($element, $child, true)),
        ));
        if (null !== $refusal) {
            return array('disposition' => $refusal, 'source_child' => null);
        }

        // Neutral on the child does not mean neutral on the merged box:
        // a child's zero margin/padding can reset the parent's spacing once
        // their selector carriers share a host. Keep these two box owners
        // separate rather than letting carrier specificity choose geometry.
        $childDeclarations = $this->signals->matchingAuthorDeclarations($child);
        $refusal = $this->firstRefusal(array(
            array(self::STAGE_RESTATEMENT, 'child_text_cascade_moves_onto_box', fn (): bool => $this->signals->childTextCascadeMovesOntoBox((string) $name, $parentDeclarations, $childDeclarations)),
            array(self::STAGE_GEOMETRY, 'box_sizing_conflict', fn (): bool => $this->signals->boxSizingConflicts($parentDeclarations, $childDeclarations)),
            array(self::STAGE_GEOMETRY, 'spacing_owners_overlap', fn (): bool => $this->signals->spacingOwnersOverlap($parentDeclarations, $childDeclarations)),
            array(self::STAGE_GEOMETRY, 'border_owners_overlap', fn (): bool => $this->signals->borderOwnersOverlap($parentDeclarations, $childDeclarations)),
            // Paragraphs have UA block margins even without author declarations.
            array(self::STAGE_GEOMETRY, 'paragraph_user_agent_margins', fn (): bool => 'core/paragraph' === $name && $this->signals->hasUserAgentParagraphMargins($child)),
        ));

        return array(
            'disposition' => $refusal ?? WrapperDisposition::coalesce('neutral_child_absorbed', self::STAGE_GEOMETRY),
            'source_child' => $child,
        );
    }

    /**
     * Is a container with these converted children a single-child wrapper
     * candidate at all? A `preserve` names why not; null means the caller
     * asks the coalescing decision. Shared by flow containers, author-layout
     * lowering and list items.
     *
     * @param array<int, array<string, mixed>> $children
     */
    public static function singleChildCandidate(array $children, bool $topologyChanged = false): ?WrapperDisposition
    {
        return self::firstMatch(array(
            array(self::STAGE_RESTATEMENT, 'not_single_child_block', fn (): bool => 1 !== count($children)),
            array(self::STAGE_GEOMETRY, 'author_layout_topology_changed', fn (): bool => $topologyChanged),
        ), WrapperDisposition::preserve(...));
    }

    /**
     * A single-child flow wrapper that did not coalesce: keep its group
     * (`preserve`) or emit the child in its place (`fold`)? Formerly inlined
     * in {@see FlowContainerElementConverter::convert()}. The author-owned
     * layout check is only evaluated when retention folds.
     *
     * @param Closure(): bool $isAuthorOwnedLayoutChild
     */
    public static function singleChildRetention(WrapperDisposition $retention, Closure $isAuthorOwnedLayoutChild): WrapperDisposition
    {
        if ($retention->isPreserve()) {
            return $retention;
        }

        return self::firstMatch(array(
            array(self::STAGE_GEOMETRY, 'author_owned_layout_child', $isAuthorOwnedLayoutChild),
        ), WrapperDisposition::preserve(...))
            ?? WrapperDisposition::fold($retention->reason, $retention->stage);
    }

    /**
     * An empty direct child of an author-owned layout: dropped when it is an
     * inert out-of-flow box, kept as a spacer when its empty visual box is
     * retained, otherwise null (it continues to the layout lowering).
     *
     * @param Closure(): bool $isInertOutOfFlow
     * @param Closure(): WrapperDisposition $emptyVisual
     */
    public static function authorLayoutEmptyChild(Closure $isInertOutOfFlow, Closure $emptyVisual): ?WrapperDisposition
    {
        $drop = self::firstMatch(array(
            // An out-of-flow layout item that paints nothing in any state
            // and takes no input is not a box the author can see or edit.
            array(self::STAGE_GEOMETRY, 'inert_out_of_flow_empty_element', $isInertOutOfFlow),
        ), WrapperDisposition::drop(...));
        if (null !== $drop) {
            return $drop;
        }
        $retention = $emptyVisual();

        return $retention->isPreserve() ? $retention : null;
    }

    /**
     * Does the wrapper hold state a script observes (so it stays its own
     * layout shell)? Formerly the body of
     * {@see EmptyVisualRetentionPolicy::preservesScriptStateWrapper()}. The
     * attribute guards run per attribute, in source attribute order.
     */
    public static function scriptState(EmptyVisualRetentionPolicy $signals, DOMElement $element): WrapperDisposition
    {
        $preserve = self::firstMatch(array(
            array(self::STAGE_IDENTITY, 'capture_device_visibility_carrier', fn (): bool => SourceDom::isCaptureDeviceVisibilityCarrier($element)),
        ), WrapperDisposition::preserve(...));
        if (null !== $preserve) {
            return $preserve;
        }
        $fold = self::firstMatch(array(
            array(self::STAGE_GEOMETRY, 'inert_hidden_empty_element', fn (): bool => $signals->isInertHiddenEmptyElement($element)),
        ), WrapperDisposition::fold(...));
        if (null !== $fold) {
            return $fold;
        }
        foreach ( $element->attributes ?? array() as $attribute ) {
            $name = strtolower($attribute->nodeName);
            $preserve = self::firstMatch(array(
                array(self::STAGE_IDENTITY, 'boolean_state_attribute', fn (): bool => LayoutShellBlockGenerator::isBooleanAttribute($name)),
                array(self::STAGE_IDENTITY, 'runtime_target_state_attribute', fn (): bool => $signals->isRuntimeDomTarget($element) && ( str_starts_with($name, 'data-') || str_starts_with($name, 'aria-') || 'role' === $name )),
            ), WrapperDisposition::preserve(...));
            if (null !== $preserve) {
                return $preserve;
            }
        }

        return WrapperDisposition::fold('no_script_state', self::STAGE_IDENTITY);
    }

    /**
     * Why a child node is ignored when looking for a wrapper's sole element
     * child, or null when it counts. Formerly inlined in
     * {@see EmptyVisualRetentionPolicy::soleElementChild()}.
     */
    public static function soleChildSkip(EmptyVisualRetentionPolicy $signals, DOMNode $node): ?string
    {
        return self::firstMatch(array(
            array(self::STAGE_GEOMETRY, 'whitespace_text', fn (): bool => XML_TEXT_NODE === $node->nodeType && '' === trim($node->textContent ?? '')),
            // Source comments carry no rendered, selector, or runtime boundary.
            // Treating them as transparent lets generated exports shed an otherwise
            // inert authoring wrapper without interpreting product-specific markup.
            array(self::STAGE_IDENTITY, 'source_comment', fn (): bool => XML_COMMENT_NODE === $node->nodeType),
            array(self::STAGE_GEOMETRY, 'inert_hidden_empty_element', fn (): bool => $node instanceof DOMElement && $signals->isInertHiddenEmptyElement($node)),
            array(self::STAGE_GEOMETRY, 'display_none_empty_element', fn (): bool => $node instanceof DOMElement && $signals->isExplicitlyDisplayNoneEmptyElement($node)),
        ), WrapperDisposition::drop(...))?->reason;
    }

    /**
     * Guard names of the slice-3 tables (#7471), in evaluation order, keyed
     * to their stage. Pinned by tests like {@see guardStages()}.
     *
     * @return array<string, array<string, string>>
     */
    public static function loweringGuardStages(): array
    {
        return array(
            'ancestor_chain_compression' => array(
                'native_navigation_chain' => self::STAGE_IDENTITY,
                'single_wrapper_ancestry' => self::STAGE_RESTATEMENT,
                'saved_depth_exceeds_nesting_budget' => self::STAGE_RESTATEMENT,
            ),
            'projected_chain_fold' => array(
                'native_group_tree_within_budget' => self::STAGE_RESTATEMENT,
                'wrapper_chain' => self::STAGE_RESTATEMENT,
                'wrapper_around_layout_shell' => self::STAGE_RESTATEMENT,
            ),
            'proof_backed_coalescing' => array(
                'no_layout_geometry_proof' => self::STAGE_GEOMETRY,
                'layout_geometry_corrective_target' => self::STAGE_GEOMETRY,
                'runtime_dom_target' => self::STAGE_IDENTITY,
                'has_id_attribute' => self::STAGE_IDENTITY,
                'has_role_attribute' => self::STAGE_IDENTITY,
                'has_interactive_attributes' => self::STAGE_IDENTITY,
                'has_motion_structure_token' => self::STAGE_IDENTITY,
                'not_single_child_block' => self::STAGE_RESTATEMENT,
                'unsupported_child_block_name' => self::STAGE_RESTATEMENT,
                'child_is_binding_anchor' => self::STAGE_IDENTITY,
                'source_child_is_not_proof_target' => self::STAGE_RESTATEMENT,
            ),
            'inherited_text_coalescing' => array(
                'child_is_binding_anchor' => self::STAGE_IDENTITY,
                'not_inherited_text_wrapper' => self::STAGE_GEOMETRY,
                'unsupported_child_block_name' => self::STAGE_RESTATEMENT,
                'missing_inherited_text_leaf' => self::STAGE_RESTATEMENT,
                'paragraph_leaf_is_not_p' => self::STAGE_RESTATEMENT,
                'selector_matching_does_not_survive_coalescing' => self::STAGE_RESTATEMENT,
                'no_inherited_text_declarations' => self::STAGE_RESTATEMENT,
            ),
            'neutral_child_absorption' => array(
                'unsupported_child_block_name' => self::STAGE_RESTATEMENT,
                'wrapper_is_not_div' => self::STAGE_RESTATEMENT,
                'saved_group_boundary' => self::STAGE_IDENTITY,
                'child_is_binding_anchor' => self::STAGE_IDENTITY,
                'runtime_dom_target' => self::STAGE_IDENTITY,
                'has_motion_structure_token' => self::STAGE_IDENTITY,
                'has_id_attribute' => self::STAGE_IDENTITY,
                'has_role_attribute' => self::STAGE_IDENTITY,
                'has_interactive_attributes' => self::STAGE_IDENTITY,
                'has_data_attributes' => self::STAGE_IDENTITY,
                'structural_layout_element' => self::STAGE_GEOMETRY,
                'wrapper_has_no_padded_box' => self::STAGE_GEOMETRY,
                'paragraph_wrapper_paints_no_background' => self::STAGE_GEOMETRY,
                'wrapper_declares_inherited_text' => self::STAGE_RESTATEMENT,
                'missing_sole_child' => self::STAGE_RESTATEMENT,
                'child_block_not_from_sole_child' => self::STAGE_RESTATEMENT,
                'child_tag_mismatch' => self::STAGE_RESTATEMENT,
                'child_runtime_dom_target' => self::STAGE_IDENTITY,
                'child_has_motion_structure_token' => self::STAGE_IDENTITY,
                'child_has_id_attribute' => self::STAGE_IDENTITY,
                'child_has_role_attribute' => self::STAGE_IDENTITY,
                'child_has_interactive_attributes' => self::STAGE_IDENTITY,
                'child_has_data_attributes' => self::STAGE_IDENTITY,
                'child_box_affecting_declarations_not_neutral' => self::STAGE_GEOMETRY,
                'selector_matching_does_not_survive_coalescing' => self::STAGE_RESTATEMENT,
                'child_text_cascade_moves_onto_box' => self::STAGE_RESTATEMENT,
                'box_sizing_conflict' => self::STAGE_GEOMETRY,
                'spacing_owners_overlap' => self::STAGE_GEOMETRY,
                'border_owners_overlap' => self::STAGE_GEOMETRY,
                'paragraph_user_agent_margins' => self::STAGE_GEOMETRY,
            ),
            'single_child_candidate' => array(
                'not_single_child_block' => self::STAGE_RESTATEMENT,
                'author_layout_topology_changed' => self::STAGE_GEOMETRY,
            ),
            'single_child_retention' => array(
                'author_owned_layout_child' => self::STAGE_GEOMETRY,
            ),
            'author_layout_empty_child' => array(
                'inert_out_of_flow_empty_element' => self::STAGE_GEOMETRY,
            ),
            'script_state' => array(
                'capture_device_visibility_carrier' => self::STAGE_IDENTITY,
                'inert_hidden_empty_element' => self::STAGE_GEOMETRY,
                'boolean_state_attribute' => self::STAGE_IDENTITY,
                'runtime_target_state_attribute' => self::STAGE_IDENTITY,
            ),
            'sole_element_child' => array(
                'whitespace_text' => self::STAGE_GEOMETRY,
                'source_comment' => self::STAGE_IDENTITY,
                'inert_hidden_empty_element' => self::STAGE_GEOMETRY,
                'display_none_empty_element' => self::STAGE_GEOMETRY,
            ),
        );
    }

    /**
     * Is a recurring custom tag a wrapping convention (`fold`, its instances
     * may be unwrapped) or a component (`preserve`)? Formerly
     * {@see CustomWrapperConventions::isWrapperConventionTag()}.
     *
     * @param Closure(): bool $cssIsRenderNeutral
     * @param Closure(): bool $contentShapesDiverge
     */
    public static function conventionTag(int $instances, int $minInstances, Closure $cssIsRenderNeutral, Closure $contentShapesDiverge): WrapperDisposition
    {
        return self::firstMatch(array(
            array(self::STAGE_IDENTITY, 'too_few_instances', fn (): bool => $minInstances > $instances),
            // A tag that carries presentation of its own is not render-neutral.
            // `display:contents` is the exception that proves the rule.
            array(self::STAGE_GEOMETRY, 'tag_css_not_render_neutral', fn (): bool => ! $cssIsRenderNeutral()),
            // A genuine component repeats its internal structure; a wrapper does not.
            array(self::STAGE_IDENTITY, 'uniform_content_shape', fn (): bool => ! $contentShapesDiverge()),
        ), WrapperDisposition::preserve(...))
            ?? WrapperDisposition::fold('wrapper_convention_tag', self::STAGE_IDENTITY);
    }

    /**
     * One instance of a wrapping-convention tag: unwrapped (`fold`), kept as
     * an author attribute-selector hook, or kept because it is not
     * render-neutral. Formerly inlined in
     * {@see CustomWrapperConventions::unwrapConventionWrappers()}.
     *
     * @param Closure(): bool $renderNeutral
     * @param Closure(): bool $addressedByAttributeSelector
     */
    public static function conventionInstance(Closure $renderNeutral, Closure $addressedByAttributeSelector): WrapperDisposition
    {
        return self::firstMatch(array(
            array(self::STAGE_GEOMETRY, 'not_render_neutral', fn (): bool => ! $renderNeutral()),
            array(self::STAGE_RESTATEMENT, 'author_attribute_selector_hook', $addressedByAttributeSelector),
        ), WrapperDisposition::preserve(...))
            ?? WrapperDisposition::fold('wrapper_convention_instance', self::STAGE_GEOMETRY);
    }

    /**
     * Does a wrapper carry no identity, behavior or presentation of its own
     * (`fold`: it can be replaced by its children)? Formerly the inlined
     * chains of {@see RenderNeutralElements::isRenderNeutralWrapper()} and
     * {@see RenderNeutralElements::isBoxlessWrapper()}. Before selector
     * preparation the conservative attribute-only contract applies; after it
     * the matched declarations (`$neutralDeclarations`) are the geometry input.
     *
     * @param Closure(): bool $neutralDeclarations
     */
    public static function renderNeutralWrapper(RenderNeutralElements $signals, DOMElement $element, bool $stylesPrepared, Closure $neutralDeclarations): WrapperDisposition
    {
        if ( ! $stylesPrepared ) {
            // Custom-element convention removal precedes selector preparation.
            return self::firstMatch(array(
                array(self::STAGE_IDENTITY, 'detached_element', fn (): bool => null === $element->parentNode),
                array(self::STAGE_IDENTITY, 'has_id_attribute', fn (): bool => '' !== trim(SourceDom::attr($element, 'id'))),
                array(self::STAGE_RESTATEMENT, 'has_class_attribute', fn (): bool => '' !== trim(SourceDom::attr($element, 'class'))),
                array(self::STAGE_GEOMETRY, 'has_style_attribute', fn (): bool => '' !== trim(SourceDom::attr($element, 'style'))),
                array(self::STAGE_IDENTITY, 'has_role_attribute', fn (): bool => '' !== trim(SourceDom::attr($element, 'role'))),
                array(self::STAGE_IDENTITY, 'has_interactive_attributes', fn (): bool => array() !== $signals->interactiveAttributes($element)),
                array(self::STAGE_IDENTITY, 'runtime_dom_target', fn (): bool => $signals->isRuntimeDomTarget($element)),
                array(self::STAGE_IDENTITY, 'has_motion_structure_token', fn (): bool => $signals->hasMotionStructureToken($element)),
            ), WrapperDisposition::preserve(...))
                ?? WrapperDisposition::fold('attribute_neutral_wrapper', self::STAGE_IDENTITY);
        }

        return self::firstMatch(array(
            array(self::STAGE_IDENTITY, 'detached_element', fn (): bool => null === $element->parentNode),
            array(self::STAGE_IDENTITY, 'has_role_attribute', fn (): bool => '' !== trim(SourceDom::attr($element, 'role'))),
            array(self::STAGE_IDENTITY, 'has_interactive_attributes', fn (): bool => array() !== $signals->interactiveAttributes($element)),
            array(self::STAGE_IDENTITY, 'runtime_dom_target', fn (): bool => $signals->isRuntimeDomTarget($element)),
            array(self::STAGE_IDENTITY, 'has_motion_structure_token', fn (): bool => $signals->hasMotionStructureToken($element)),
            array(self::STAGE_GEOMETRY, 'box_declarations_not_neutral', fn (): bool => ! $neutralDeclarations()),
        ), WrapperDisposition::preserve(...))
            ?? WrapperDisposition::fold('render_neutral_wrapper', self::STAGE_GEOMETRY);
    }

    /**
     * Is a custom host a render-neutral flow wrapper around ordinary light
     * DOM (`fold`: it lowers as an unknown flow element)? Formerly
     * {@see RenderNeutralElements::isRenderNeutralCustomFlowWrapper()}; read by
     * {@see RenderNeutralFlowWrapperConverter}.
     */
    public static function renderNeutralCustomFlowWrapper(RenderNeutralElements $signals, DOMElement $element): WrapperDisposition
    {
        $children = null;
        $lightDom = static function () use ($element, $signals, &$children): ?array {
            if ( null !== $children ) {
                return false === $children ? null : $children;
            }
            $children = array();
            foreach ( $element->childNodes as $child ) {
                if ( XML_COMMENT_NODE === $child->nodeType || ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) ) {
                    continue;
                }
                // Native buttons (including icon-only buttons) are ordinary light
                // DOM content too; their own converter retains control semantics.
                if ( ! $child instanceof DOMElement || ( 'button' !== strtolower($child->tagName) && ! $signals->isStructuralTransparentCustomWrapperChild($child) ) ) {
                    $children = false;
                    return null;
                }
                $children[] = $child;
            }
            return $children;
        };

        return self::firstMatch(array(
            array(self::STAGE_IDENTITY, 'not_unknown_element', fn (): bool => ! RichTextInlineTags::isUnknownHtmlElement($element)),
            array(self::STAGE_IDENTITY, 'not_custom_element_tag', fn (): bool => ! CustomElementRuntimeDependency::isCustomElementTag(strtolower($element->tagName))),
            array(self::STAGE_RESTATEMENT, 'has_class_attribute', fn (): bool => '' !== trim(SourceDom::attr($element, 'class'))),
            array(self::STAGE_IDENTITY, 'has_id_attribute', fn (): bool => '' !== trim(SourceDom::attr($element, 'id'))),
            array(self::STAGE_GEOMETRY, 'hidden_attribute', fn (): bool => $element->hasAttribute('hidden')),
            array(self::STAGE_GEOMETRY, 'not_render_neutral', fn (): bool => ! $signals->isRenderNeutralWrapper($element)),
            array(self::STAGE_IDENTITY, 'unsafe_transparent_custom_element', fn (): bool => ! $signals->isSafeTransparentCustomElement($element, false, true)),
            array(self::STAGE_GEOMETRY, 'descendant_display_none', static function () use ($element): bool {
                foreach ( $element->getElementsByTagName('*') as $descendant ) {
                    if ( 'none' === (SourceDeclarations::inline($descendant)['display'] ?? '') ) {
                        return true;
                    }
                }
                return false;
            }),
            array(self::STAGE_RESTATEMENT, 'light_dom_child_not_transparent', fn (): bool => null === $lightDom()),
            // Repeated siblings may be a cohesive component; retain that existing
            // disposition rather than reclassifying collections as wrappers.
            array(self::STAGE_RESTATEMENT, 'not_single_light_dom_child', fn (): bool => 1 !== count($lightDom())),
            array(self::STAGE_IDENTITY, 'textless_non_button_child', fn (): bool => '' === trim($element->textContent ?? '') && 'button' !== strtolower($lightDom()[0]->tagName)),
        ), WrapperDisposition::preserve(...))
            ?? WrapperDisposition::fold('render_neutral_custom_flow_wrapper', self::STAGE_GEOMETRY);
    }

    /**
     * Does an unknown or custom element lower as a generic container
     * (`fold`), or keep its preservation path (`preserve`)? Formerly
     * {@see RenderNeutralElements::isTransparentUnknownElement()}; read by
     * {@see TransparentUnknownElementConverter}.
     */
    public static function transparentUnknownElement(RenderNeutralElements $signals, DOMElement $element): WrapperDisposition
    {
        $preserve = self::firstMatch(array(
            array(self::STAGE_IDENTITY, 'not_unknown_or_custom_element', fn (): bool => ! RichTextInlineTags::isUnknownHtmlElement($element) && ! str_contains(strtolower($element->tagName), '-')),
            array(self::STAGE_IDENTITY, 'shadow_insertion_point', fn (): bool => in_array(strtolower($element->tagName), array( 'content', 'shadow' ), true)),
            // Motion topology names alone do not make static content runtime-owned.
            array(self::STAGE_IDENTITY, 'runtime_media_surface', fn (): bool => $signals->isRuntimeMediaSurfaceElement($element)),
            array(self::STAGE_IDENTITY, 'unsafe_transparent_custom_element', fn (): bool => ! $signals->isSafeTransparentCustomElement($element)),
            // A landmark host only names a section; a proven runtime definition
            // may still give it widget behaviour, so it keeps the widget path.
            array(self::STAGE_IDENTITY, 'widget_role_or_accessible_name', static function () use ($signals, $element): bool {
                $landmark = null !== $signals->namedLandmark($element) && ! $signals->hasProvenRuntimeDependency($element);
                foreach ( array( 'role', 'aria-label', 'aria-labelledby' ) as $attribute ) {
                    if ( ! $landmark && '' !== trim(SourceDom::attr($element, $attribute)) ) {
                        return true;
                    }
                }
                return false;
            }),
        ), WrapperDisposition::preserve(...));
        if ( null !== $preserve ) {
            return $preserve;
        }

        $fold = self::firstMatch(array(
            array(self::STAGE_IDENTITY, 'unknown_element_container', fn (): bool => ! str_contains($element->tagName, '-')),
            array(self::STAGE_IDENTITY, 'custom_element_with_text', fn (): bool => '' !== trim($element->textContent ?? '')),
        ), WrapperDisposition::fold(...));
        if ( null !== $fold ) {
            return $fold;
        }

        // A textless custom element may still be upgraded into rendered shadow
        // content, unless its light DOM already renders without a script.
        $runtimeDependency = new CustomElementRuntimeDependency();
        $paintOnly = SourceDom::backgroundPaintOnlyCustomElement($element);

        return self::firstMatch(array(
            array(self::STAGE_GEOMETRY, 'textless_without_static_rendering', fn (): bool => ! ( $paintOnly || SourceDom::staticGraphicOnlyCustomElement($element) || SourceDom::hasRenderableMediaContent($element) )),
            array(self::STAGE_IDENTITY, 'configuration_attribute', fn (): bool => $runtimeDependency->hasConfigurationAttribute($element, $paintOnly || SourceDom::hasOnlyRenderableMediaContent($element))),
            array(self::STAGE_IDENTITY, 'proven_runtime_definition', fn (): bool => 'preserve' === $signals->customElementRuntimeDisposition($element)),
        ), WrapperDisposition::preserve(...))
            ?? WrapperDisposition::fold('static_rendering_custom_element', self::STAGE_GEOMETRY);
    }

    /**
     * A custom element whose defining runtime is unproven: dropped as an
     * empty render-neutral host, kept as a paint-free spacing group, dropped
     * because it is hidden at every viewport, or dropped with a recorded
     * unproven-runtime finding. Formerly inlined in
     * {@see CustomElementRuntimeConverter::convert()}.
     *
     * @param Closure(): bool $emptyConfiguredHost
     * @param Closure(): bool $renderNeutral
     * @param Closure(): bool $hiddenAtEveryViewport
     */
    public static function unprovenCustomElement(Closure $emptyConfiguredHost, Closure $renderNeutral, Closure $hiddenAtEveryViewport): WrapperDisposition
    {
        if ( $emptyConfiguredHost() ) {
            return $renderNeutral()
                ? WrapperDisposition::drop('empty_render_neutral_configured_host', self::STAGE_GEOMETRY)
                : WrapperDisposition::preserve('paint_free_runtime_placeholder', self::STAGE_GEOMETRY);
        }

        return self::firstMatch(array(
            // A host the source cascade hides at every viewport renders
            // nothing there, so omitting it loses no content or behavior.
            array(self::STAGE_GEOMETRY, 'display_none_at_every_viewport', $hiddenAtEveryViewport),
        ), WrapperDisposition::drop(...))
            ?? WrapperDisposition::drop('unproven_runtime_dependency', self::STAGE_IDENTITY);
    }

    /**
     * Does a source-less `<picture>` between a custom image host and its image
     * contribute nothing beyond the image (`coalesce`), or keep its own native
     * container (`preserve`)? Formerly `CustomImageHostLowering::isNeutralPictureBox()`.
     *
     * @param Closure(string): bool $dataAttributeReferenced
     * @param Closure(): bool $renderNeutral
     * @param Closure(): bool $selectorReachesImageThroughPicture
     */
    public static function pictureBox(DOMElement $picture, Closure $dataAttributeReferenced, Closure $renderNeutral, Closure $selectorReachesImageThroughPicture): WrapperDisposition
    {
        $preserve = self::firstMatch(array(
            array(self::STAGE_RESTATEMENT, 'not_single_child', fn (): bool => 1 !== SourceDom::childElementCount($picture)),
            array(self::STAGE_IDENTITY, 'has_id_attribute', fn (): bool => '' !== trim(SourceDom::attr($picture, 'id'))),
        ), WrapperDisposition::preserve(...));
        if ( null !== $preserve ) {
            return $preserve;
        }
        foreach ( $picture->attributes as $attribute ) {
            $name = strtolower($attribute->name);
            if ( in_array($name, array( 'class', 'style' ), true) ) {
                continue;
            }
            $preserve = self::firstMatch(array(
                array(self::STAGE_IDENTITY, 'non_data_attribute', fn (): bool => ! str_starts_with($name, 'data-')),
                array(self::STAGE_IDENTITY, 'referenced_data_attribute', fn (): bool => $dataAttributeReferenced($name)),
            ), WrapperDisposition::preserve(...));
            if ( null !== $preserve ) {
                return $preserve;
            }
        }

        return self::firstMatch(array(
            array(self::STAGE_GEOMETRY, 'not_render_neutral', fn (): bool => ! $renderNeutral()),
            // Rules addressed through the picture (`picture > img`) would stop
            // matching the image once the box is gone.
            array(self::STAGE_RESTATEMENT, 'selector_reaches_image_through_picture', $selectorReachesImageThroughPicture),
        ), WrapperDisposition::preserve(...))
            ?? WrapperDisposition::coalesce('neutral_picture_box', self::STAGE_GEOMETRY);
    }

    /**
     * Does a custom image host carry no identity of its own, so its box may
     * be grouped or erased around the image? Formerly
     * `CustomImageHostLowering::hasOnlyInertImageHostAttributes()`. A
     * `preserve` names the identity that keeps it.
     *
     * @param Closure(): bool $runtimeDomTarget
     * @param Closure(string): bool $dataAttributeReferenced
     * @param Closure(): bool $interactive
     * @param Closure(): bool $semanticSelectorMarker
     */
    public static function imageHostIdentity(DOMElement $host, Closure $runtimeDomTarget, Closure $dataAttributeReferenced, Closure $interactive, Closure $semanticSelectorMarker): ?WrapperDisposition
    {
        foreach ( $host->attributes as $attribute ) {
            $name = strtolower($attribute->name);
            if ( in_array($name, array( 'class', 'style' ), true) ) {
                continue;
            }
            $preserve = self::firstMatch(array(
                array(self::STAGE_IDENTITY, 'non_data_attribute', fn (): bool => ! str_starts_with($name, 'data-')),
                array(self::STAGE_IDENTITY, 'runtime_dom_target', $runtimeDomTarget),
                array(self::STAGE_IDENTITY, 'referenced_data_attribute', fn (): bool => $dataAttributeReferenced($name)),
            ), WrapperDisposition::preserve(...));
            if ( null !== $preserve ) {
                return $preserve;
            }
        }

        return self::firstMatch(array(
            array(self::STAGE_IDENTITY, 'runtime_dom_target', $runtimeDomTarget),
            array(self::STAGE_IDENTITY, 'has_interactive_attributes', $interactive),
            array(self::STAGE_RESTATEMENT, 'semantic_selector_marker', $semanticSelectorMarker),
        ), WrapperDisposition::preserve(...));
    }

    /**
     * Is an empty search or cart shell dead platform chrome (`drop`), or
     * authored layout/content that keeps its conversion path (`preserve`)?
     * Formerly {@see EmptyInteractiveFeatureShell::isEmptyInteractiveFeatureShell()}.
     */
    public static function emptyInteractiveFeatureShell(EmptyInteractiveFeatureShell $signals, DOMElement $element): WrapperDisposition
    {
        return self::firstMatch(array(
            array(self::STAGE_IDENTITY, 'no_search_or_cart_token', fn (): bool => ! $signals->namesSearchOrCartFeature($element)),
            array(self::STAGE_IDENTITY, 'has_rendered_text', fn (): bool => '' !== SourceDom::renderedTextContent($element)),
            array(self::STAGE_IDENTITY, 'runtime_dom_target', fn (): bool => $signals->isRuntimeDomTarget($element)),
            array(self::STAGE_GEOMETRY, 'structural_layout_child', fn (): bool => $signals->isDirectChildOfStructuralLayout($element)),
            array(self::STAGE_GEOMETRY, 'author_inline_alignment', fn (): bool => $signals->hasAuthorInlineAlignment($element)),
            array(self::STAGE_GEOMETRY, 'reserves_layout_slot', fn (): bool => $signals->reservesLayoutSlot($element)),
            array(self::STAGE_IDENTITY, 'content_or_control_descendant', fn (): bool => $signals->hasContentOrControlDescendant($element)),
            array(self::STAGE_IDENTITY, 'runtime_target_descendant', fn (): bool => $signals->hasRuntimeTargetDescendant($element)),
        ), WrapperDisposition::preserve(...))
            ?? WrapperDisposition::drop('empty_interactive_feature_shell', self::STAGE_IDENTITY);
    }

    /**
     * May a `div`/`span` lower as a styled text wrapper at all? A `preserve`
     * names why not (the element continues to other lowerings). Formerly the
     * leading checks of {@see InlineTextWrapperLowering::visualTextWrapperBlockFromElement()}.
     *
     * @param Closure(): bool $hasBlockContentChildren
     * @param Closure(): bool $hasSemanticMarkedChild
     * @param Closure(): bool $hasRichTextMarkedDescendant
     */
    public static function visualTextWrapperCandidate(DOMElement $element, Closure $hasBlockContentChildren, Closure $hasSemanticMarkedChild, Closure $hasRichTextMarkedDescendant): ?WrapperDisposition
    {
        return self::firstMatch(array(
            array(self::STAGE_RESTATEMENT, 'not_div_or_span', fn (): bool => ! in_array(strtolower($element->tagName), array( 'div', 'span' ), true)),
            array(self::STAGE_RESTATEMENT, 'has_block_content_children', $hasBlockContentChildren),
            array(self::STAGE_RESTATEMENT, 'semantic_marked_child', $hasSemanticMarkedChild),
            array(self::STAGE_RESTATEMENT, 'rich_text_marked_descendant', $hasRichTextMarkedDescendant),
        ), WrapperDisposition::preserve(...));
    }

    /**
     * An inline text wrapper whose phrasing run holds an empty visual inline
     * child keeps its own group (`preserve`) so that box survives; otherwise
     * null (the run may fold into one paragraph). Shared by both
     * {@see InlineTextWrapperLowering} paths.
     *
     * @param Closure(): bool $hasEmptyVisualInlineChild
     */
    public static function inlineTextWrapperEmptyVisualChild(Closure $hasEmptyVisualInlineChild): ?WrapperDisposition
    {
        return self::firstMatch(array(
            array(self::STAGE_GEOMETRY, 'inline_empty_visual_child', $hasEmptyVisualInlineChild),
        ), WrapperDisposition::preserve(...));
    }

    /**
     * The last guard before a styled text wrapper folds into one paragraph.
     *
     * @param Closure(): bool $hasVisualTextWrapperSignal
     */
    public static function visualTextWrapperFold(Closure $hasVisualTextWrapperSignal): WrapperDisposition
    {
        return self::firstMatch(array(
            array(self::STAGE_GEOMETRY, 'no_visual_text_wrapper_signal', fn (): bool => ! $hasVisualTextWrapperSignal()),
        ), WrapperDisposition::preserve(...))
            ?? WrapperDisposition::fold('visual_text_wrapper_paragraph', self::STAGE_RESTATEMENT);
    }

    /**
     * A generic figure's caption: kept as a paragraph when it has visible
     * text, otherwise the empty-visual disposition decides whether it keeps a
     * spacer. Formerly inlined in {@see GenericFigureLowering::block()}.
     *
     * @param Closure(): WrapperDisposition $emptyVisual
     */
    public static function figureCaption(bool $hasVisibleText, Closure $emptyVisual): WrapperDisposition
    {
        return $hasVisibleText
            ? WrapperDisposition::preserve('caption_text', self::STAGE_IDENTITY)
            : $emptyVisual();
    }

    /**
     * A non-navigating anchor whose children all render nothing (`drop`), or
     * one with something to render (`preserve`)? Formerly
     * `AnchorElementConverter::isNonRenderingNonNavigatingAnchor()`; the
     * empty-visual disposition of each descendant is read lazily, in
     * document order.
     *
     * @param Closure(DOMElement): bool $isEmptyNonNavigatingAnchor
     * @param Closure(DOMElement): WrapperDisposition $emptyVisual
     */
    public static function nonRenderingAnchor(DOMElement $element, Closure $isEmptyNonNavigatingAnchor, Closure $emptyVisual): WrapperDisposition
    {
        $preserve = self::firstMatch(array(
            array(self::STAGE_RESTATEMENT, 'no_child_elements', fn (): bool => 0 === SourceDom::childElementCount($element)),
            array(self::STAGE_IDENTITY, 'navigating_or_named_anchor', static function () use ($element, $isEmptyNonNavigatingAnchor): bool {
                $clone = $element->cloneNode(false);
                return ! $clone instanceof DOMElement || ! $isEmptyNonNavigatingAnchor($clone);
            }),
            array(self::STAGE_IDENTITY, 'has_text', fn (): bool => '' !== trim($element->textContent ?? '')),
        ), WrapperDisposition::preserve(...));
        if ( null !== $preserve ) {
            return $preserve;
        }
        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            $tag = strtolower($descendant->tagName);
            // A srcless `<img>` converts to nothing on its own, whatever box
            // authored styles give it, so the anchor around it follows that
            // same disposition.
            if ( 'img' === $tag ) {
                $preserve = self::firstMatch(array(
                    array(self::STAGE_IDENTITY, 'sourced_image_descendant', fn (): bool => '' !== trim(SourceDom::attr($descendant, 'src')) || '' !== trim(SourceDom::attr($descendant, 'srcset'))),
                ), WrapperDisposition::preserve(...));
                if ( null !== $preserve ) {
                    return $preserve;
                }
                continue;
            }
            $preserve = self::firstMatch(array(
                array(self::STAGE_GEOMETRY, 'empty_visual_descendant', fn (): bool => $emptyVisual($descendant)->isPreserve()),
                array(self::STAGE_RESTATEMENT, 'non_box_descendant', fn (): bool => ! in_array($tag, array( 'div', 'span' ), true)),
            ), WrapperDisposition::preserve(...));
            if ( null !== $preserve ) {
                return $preserve;
            }
        }

        return WrapperDisposition::drop('non_rendering_non_navigating_anchor', self::STAGE_GEOMETRY);
    }

    /**
     * A link wrapper around block content: keep its containers and the exact
     * wrapper (`preserve`), or fold its destination onto the card's own
     * RichText as a stretched link (`fold`)? Formerly the eligibility check
     * of {@see AnchorLowering::convertLinkWrapperGroup()}.
     *
     * @param Closure(): bool $isStretchedLinkCard
     */
    public static function linkWrapper(DOMElement $anchor, Closure $isStretchedLinkCard): WrapperDisposition
    {
        return self::firstMatch(array(
            array(self::STAGE_IDENTITY, 'interactive_descendant', static function () use ($anchor): bool {
                foreach ( array( 'a', 'button', 'input', 'select', 'textarea' ) as $tag ) {
                    if ( 0 < $anchor->getElementsByTagName($tag)->length ) {
                        return true;
                    }
                }
                return false;
            }),
            array(self::STAGE_RESTATEMENT, 'not_stretched_link_card', fn (): bool => ! $isStretchedLinkCard()),
        ), WrapperDisposition::preserve(...))
            ?? WrapperDisposition::fold('stretched_link_card', self::STAGE_RESTATEMENT);
    }

    /**
     * Once a link wrapper did not stretch, is its link restated on the
     * children (`fold`), or dropped with a recorded finding? Null when there
     * is no link to move. Formerly inlined in
     * {@see AnchorLowering::convertLinkWrapperGroup()}.
     *
     * @param Closure(): bool $propagate
     */
    public static function linkWrapperPropagation(bool $stretched, bool $hasLinkAttributes, Closure $propagate): ?WrapperDisposition
    {
        if ( $stretched || ! $hasLinkAttributes ) {
            return null;
        }

        return $propagate()
            ? WrapperDisposition::fold('link_restated_on_children', self::STAGE_RESTATEMENT)
            : WrapperDisposition::fold('link_wrapper_dropped', self::STAGE_RESTATEMENT);
    }

    /**
     * Guard names of the slice-4 tables (#7471), in evaluation order, keyed
     * to their stage. Pinned by tests like {@see guardStages()}.
     *
     * @return array<string, array<string, string>>
     */
    public static function conversionGuardStages(): array
    {
        return array(
            'convention_tag' => array(
                'too_few_instances' => self::STAGE_IDENTITY,
                'tag_css_not_render_neutral' => self::STAGE_GEOMETRY,
                'uniform_content_shape' => self::STAGE_IDENTITY,
            ),
            'convention_instance' => array(
                'not_render_neutral' => self::STAGE_GEOMETRY,
                'author_attribute_selector_hook' => self::STAGE_RESTATEMENT,
            ),
            'render_neutral_wrapper' => array(
                'detached_element' => self::STAGE_IDENTITY,
                'has_id_attribute' => self::STAGE_IDENTITY,
                'has_class_attribute' => self::STAGE_RESTATEMENT,
                'has_style_attribute' => self::STAGE_GEOMETRY,
                'has_role_attribute' => self::STAGE_IDENTITY,
                'has_interactive_attributes' => self::STAGE_IDENTITY,
                'runtime_dom_target' => self::STAGE_IDENTITY,
                'has_motion_structure_token' => self::STAGE_IDENTITY,
                'box_declarations_not_neutral' => self::STAGE_GEOMETRY,
            ),
            'render_neutral_custom_flow_wrapper' => array(
                'not_unknown_element' => self::STAGE_IDENTITY,
                'not_custom_element_tag' => self::STAGE_IDENTITY,
                'has_class_attribute' => self::STAGE_RESTATEMENT,
                'has_id_attribute' => self::STAGE_IDENTITY,
                'hidden_attribute' => self::STAGE_GEOMETRY,
                'not_render_neutral' => self::STAGE_GEOMETRY,
                'unsafe_transparent_custom_element' => self::STAGE_IDENTITY,
                'descendant_display_none' => self::STAGE_GEOMETRY,
                'light_dom_child_not_transparent' => self::STAGE_RESTATEMENT,
                'not_single_light_dom_child' => self::STAGE_RESTATEMENT,
                'textless_non_button_child' => self::STAGE_IDENTITY,
            ),
            'transparent_unknown_element' => array(
                'not_unknown_or_custom_element' => self::STAGE_IDENTITY,
                'shadow_insertion_point' => self::STAGE_IDENTITY,
                'runtime_media_surface' => self::STAGE_IDENTITY,
                'unsafe_transparent_custom_element' => self::STAGE_IDENTITY,
                'widget_role_or_accessible_name' => self::STAGE_IDENTITY,
                'unknown_element_container' => self::STAGE_IDENTITY,
                'custom_element_with_text' => self::STAGE_IDENTITY,
                'textless_without_static_rendering' => self::STAGE_GEOMETRY,
                'configuration_attribute' => self::STAGE_IDENTITY,
                'proven_runtime_definition' => self::STAGE_IDENTITY,
            ),
            'unproven_custom_element' => array(
                'empty_render_neutral_configured_host' => self::STAGE_GEOMETRY,
                'paint_free_runtime_placeholder' => self::STAGE_GEOMETRY,
                'display_none_at_every_viewport' => self::STAGE_GEOMETRY,
                'unproven_runtime_dependency' => self::STAGE_IDENTITY,
            ),
            'picture_box' => array(
                'not_single_child' => self::STAGE_RESTATEMENT,
                'has_id_attribute' => self::STAGE_IDENTITY,
                'non_data_attribute' => self::STAGE_IDENTITY,
                'referenced_data_attribute' => self::STAGE_IDENTITY,
                'not_render_neutral' => self::STAGE_GEOMETRY,
                'selector_reaches_image_through_picture' => self::STAGE_RESTATEMENT,
            ),
            'image_host_identity' => array(
                'non_data_attribute' => self::STAGE_IDENTITY,
                'runtime_dom_target' => self::STAGE_IDENTITY,
                'referenced_data_attribute' => self::STAGE_IDENTITY,
                'has_interactive_attributes' => self::STAGE_IDENTITY,
                'semantic_selector_marker' => self::STAGE_RESTATEMENT,
            ),
            'empty_interactive_feature_shell' => array(
                'no_search_or_cart_token' => self::STAGE_IDENTITY,
                'has_rendered_text' => self::STAGE_IDENTITY,
                'runtime_dom_target' => self::STAGE_IDENTITY,
                'structural_layout_child' => self::STAGE_GEOMETRY,
                'author_inline_alignment' => self::STAGE_GEOMETRY,
                'reserves_layout_slot' => self::STAGE_GEOMETRY,
                'content_or_control_descendant' => self::STAGE_IDENTITY,
                'runtime_target_descendant' => self::STAGE_IDENTITY,
            ),
            'inline_text_wrapper' => array(
                'not_div_or_span' => self::STAGE_RESTATEMENT,
                'has_block_content_children' => self::STAGE_RESTATEMENT,
                'semantic_marked_child' => self::STAGE_RESTATEMENT,
                'rich_text_marked_descendant' => self::STAGE_RESTATEMENT,
                'inline_empty_visual_child' => self::STAGE_GEOMETRY,
                'no_visual_text_wrapper_signal' => self::STAGE_GEOMETRY,
            ),
            'figure_caption' => array(
                'caption_text' => self::STAGE_IDENTITY,
            ),
            'non_rendering_anchor' => array(
                'no_child_elements' => self::STAGE_RESTATEMENT,
                'navigating_or_named_anchor' => self::STAGE_IDENTITY,
                'has_text' => self::STAGE_IDENTITY,
                'sourced_image_descendant' => self::STAGE_IDENTITY,
                'empty_visual_descendant' => self::STAGE_GEOMETRY,
                'non_box_descendant' => self::STAGE_RESTATEMENT,
            ),
            'link_wrapper' => array(
                'interactive_descendant' => self::STAGE_IDENTITY,
                'not_stretched_link_card' => self::STAGE_RESTATEMENT,
                'link_restated_on_children' => self::STAGE_RESTATEMENT,
                'link_wrapper_dropped' => self::STAGE_RESTATEMENT,
            ),
        );
    }
}

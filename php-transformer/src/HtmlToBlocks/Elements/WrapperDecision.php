<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\Contract\RichTextInlineTags;
use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\LayoutShellBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\EmptyElementFootprint;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\LayoutParticipation;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Support\ShellLandmarkPolicy;
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
 *
 * Slice 5 adds the layout and RichText tables (see
 * {@see self::layoutGuardStages()}): author-layout box chrome and lone
 * styled inlines, the synthesized button save wrappers (the `css-owned`
 * outcome), layout-shell wrapper restatement, the element conversion
 * prelude's wrapper dispatch, and RichText styling-hook unwrapping and
 * hoisting.
 *
 * Slice 6 adds the last local deciders (see {@see self::patternGuardStages()}):
 * button label and control wrappers, navigation carrier, flattened and
 * support wrappers, media/text wrappers, captured menu trigger groups,
 * navigation duplicates and capture close helpers, carousel stage wrappers,
 * and retained custom wrapper hooks. Site-plan chrome identity and listing
 * card wrappers stay in `WordPressSitePlan`: materialization must not import
 * translation (tests/contract/layer-direction.php).
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
     * May a paint-free wrapper whose only box is vertical padding (a
     * `section.py-20` spacing band) hand that padding to its sole group
     * child (a centered `div.container`)? The wrapper spans its containing
     * block and paints nothing, so the band is the child's box grown by the
     * same padding: the child must paint nothing that the padding would
     * enlarge, size nothing the padding would eat into, and keep no vertical
     * margin the padding used to sit outside of. The wrapper's identity and
     * tag move onto the child, which stays a native group.
     *
     * @param array<string, mixed> $childBlock
     * @return array{disposition: WrapperDisposition, source_child: ?DOMElement}
     */
    public function paddingShellAbsorption(DOMElement $element, array $childBlock): array
    {
        $tag = strtolower($element->tagName);
        $refusal = $this->firstRefusal(array(
            array(self::STAGE_RESTATEMENT, 'unsupported_child_block_name', fn (): bool => 'core/group' !== ($childBlock['blockName'] ?? '')),
            array(self::STAGE_RESTATEMENT, 'unsupported_wrapper_tag', fn (): bool => ! in_array($tag, array('div', 'section'), true)),
            array(self::STAGE_RESTATEMENT, 'child_group_has_tag', fn (): bool => 'div' !== $tag && ! in_array((string) ($childBlock['attrs']['tagName'] ?? 'div'), array('', 'div'), true)),
            array(self::STAGE_IDENTITY, 'saved_group_boundary', fn (): bool => SourceDom::hasClass($element, 'wp-block-group')),
            array(self::STAGE_IDENTITY, 'child_is_binding_anchor', fn (): bool => $this->signals->isBindingAnchor($childBlock)),
            array(self::STAGE_IDENTITY, 'runtime_dom_target', fn (): bool => $this->runtimeIslands->isRuntimeDomTarget($element)),
            array(self::STAGE_IDENTITY, 'has_motion_structure_token', fn (): bool => $this->sourceElementClassifier->hasMotionStructureToken($element)),
            array(self::STAGE_IDENTITY, 'has_id_attribute', fn (): bool => '' !== SourceDom::attr($element, 'id')),
            array(self::STAGE_IDENTITY, 'has_role_attribute', fn (): bool => '' !== SourceDom::attr($element, 'role')),
            array(self::STAGE_IDENTITY, 'has_accessible_name', fn (): bool => '' !== SourceDom::attr($element, 'aria-label') || '' !== SourceDom::attr($element, 'aria-labelledby') || '' !== SourceDom::attr($element, 'title')),
            array(self::STAGE_IDENTITY, 'has_interactive_attributes', fn (): bool => array() !== $this->signals->interactiveAttributes($element)),
            array(self::STAGE_IDENTITY, 'has_data_attributes', fn (): bool => array() !== SourceDom::safeDataAttributes($element)),
            array(self::STAGE_GEOMETRY, 'structural_layout_element', fn (): bool => $this->signals->isStructuralLayoutElement($element)),
            array(self::STAGE_GEOMETRY, 'structural_layout_child', fn (): bool => $this->signals->isDirectChildOfStructuralLayout($element)),
        ));
        if (null !== $refusal) {
            return array('disposition' => $refusal, 'source_child' => null);
        }

        $parentDeclarations = $this->signals->matchingAuthorDeclarations($element);
        $refusal = $this->firstRefusal(array(
            array(self::STAGE_GEOMETRY, 'wrapper_has_no_vertical_padding', fn (): bool => ! $this->signals->hasVerticalPadding($parentDeclarations)),
            array(self::STAGE_GEOMETRY, 'wrapper_box_beyond_vertical_padding', fn (): bool => ! $this->signals->isVerticalPaddingOnlyBox($parentDeclarations)),
            array(self::STAGE_RESTATEMENT, 'wrapper_declares_inherited_text', fn (): bool => $this->signals->declaresInheritedText($parentDeclarations)),
            array(self::STAGE_RESTATEMENT, 'wrapper_state_or_pseudo_element_rule', fn (): bool => $this->signals->hasStateOrPseudoElementRule($element)),
        ));
        if (null !== $refusal) {
            return array('disposition' => $refusal, 'source_child' => null);
        }

        $child = $this->signals->soleElementChildOf($element);
        $refusal = $this->firstRefusal(array(
            array(self::STAGE_RESTATEMENT, 'missing_sole_child', fn (): bool => ! $child instanceof DOMElement),
            array(self::STAGE_RESTATEMENT, 'child_block_not_from_sole_child', fn (): bool => $this->signals->matchingSourceChild($element, $childBlock) !== $child),
            array(self::STAGE_RESTATEMENT, 'child_tag_mismatch', fn (): bool => 'div' !== strtolower($child->tagName)),
            array(self::STAGE_IDENTITY, 'child_runtime_dom_target', fn (): bool => $this->runtimeIslands->isRuntimeDomTarget($child)),
            array(self::STAGE_IDENTITY, 'child_has_motion_structure_token', fn (): bool => $this->sourceElementClassifier->hasMotionStructureToken($child)),
        ));
        if (null !== $refusal) {
            return array('disposition' => $refusal, 'source_child' => null);
        }

        $childDeclarations = $this->signals->matchingAuthorDeclarations($child);
        $refusal = $this->firstRefusal(array(
            array(self::STAGE_GEOMETRY, 'child_box_grows_with_padding', fn (): bool => $this->signals->boxChangesWithAddedVerticalPadding($childDeclarations)),
            array(self::STAGE_GEOMETRY, 'spacing_owners_overlap', fn (): bool => $this->signals->spacingOwnersOverlap($parentDeclarations, $childDeclarations)),
            array(self::STAGE_RESTATEMENT, 'custom_property_owners_overlap', fn (): bool => $this->signals->customPropertyOwnersOverlap($parentDeclarations, $childDeclarations)),
            array(self::STAGE_RESTATEMENT, 'selector_matching_does_not_survive_coalescing', fn (): bool => ! $this->signals->selectorMatchingSurvivesWrapperCoalescing($element, $child, true, 'div' === $tag ? null : $tag)),
        ));

        return array(
            'disposition' => $refusal ?? WrapperDisposition::coalesce('padding_shell_absorbed', self::STAGE_GEOMETRY),
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

    /** The fold reason of a wrapper whose consumed class and id were restated on its child. */
    public const RESTATED_WRAPPER_FOLD = 'restated_consumed_identity';

    /**
     * A single-child wrapper that did not coalesce, kept only because emitted
     * CSS consumes its class or id (#7505, #7471 stage 2): does it `fold`
     * into its child by restating that class and id there, or is it kept
     * (`preserve`)? Geometry and paint neutrality come from box-tree
     * evidence ({@see BoxTreeWrapperEvidence}); without evidence the wrapper
     * is kept, as before. What nothing reads is dropped with the wrapper: an
     * inert `data-*` attribute, an unreferenced id. What something reads
     * moves onto the child ({@see RestatedWrapperIdentity}). The restatement
     * proof ({@see RestatedWrapperFold}) runs last, once, and each of its
     * failures is a guard of its own. A refusal's `detail` names the failing
     * property, selector or attribute.
     *
     * @param array<string, mixed> $childBlock
     * A `data-*` attribute only author CSS reads is projected: the child
     * takes an engine-owned class in its place and every rule reading the
     * attribute through the wrapper gains the arm naming that class. A
     * child block converted from a deeper element is accepted when the
     * elements between it and the wrapper form a sole-child chain that
     * already folded into it; the proof runs against that element.
     *
     * @param Closure(DOMElement, DOMElement, string, array<string, string>, array<string, string>, array{elements: list<DOMElement>, classes: list<string>, anchor: string}): array{failure: ?string, detail: string, arms: array<string, list<string>>} $prove
     * @param Closure(DOMElement): bool $isDocumentEdge
     * @return array{disposition: WrapperDisposition, source_child: ?DOMElement, moved_id: string, moved_attributes: array<string, string>, projected_attributes: array<string, string>, folded_chain: list<DOMElement>, attrs: array<string, mixed>, arms: array<string, list<string>>}
     */
    public function restatedWrapperFold(DOMElement $wrapper, array $childBlock, BoxTreeWrapperEvidence $evidence, RestatedWrapperIdentity $identity, Closure $prove, Closure $isDocumentEdge): array
    {
        $sourceChild = null;
        $attrs = array();
        $proof = null;
        $detail = '';
        $blockName = (string) ($childBlock['blockName'] ?? '');
        $wrapperId = trim(SourceDom::attr($wrapper, 'id'));
        // The id the child takes: the wrapper's, unless nothing references it.
        $movedId = $wrapperId;
        /** @var array<string, string> $movedAttributes */
        $movedAttributes = array();
        /** @var array<string, string> $projectedAttributes */
        $projectedAttributes = array();
        /** @var array{elements: list<DOMElement>, classes: list<string>, anchor: string} $chain */
        $chain = array('elements' => array(), 'classes' => array(), 'anchor' => '');
        $proven = function () use (&$proof, &$sourceChild, &$movedId, &$movedAttributes, &$projectedAttributes, &$chain, $wrapper, $prove): array {
            return $proof ??= $prove($wrapper, $sourceChild, $movedId, $movedAttributes, $projectedAttributes, $chain);
        };
        $proofFails = function (string $failure) use ($proven, &$detail): bool {
            if ($failure !== $proven()['failure']) return false;
            $detail = $proven()['detail'];
            return true;
        };
        $guards = array(
            array(self::STAGE_GEOMETRY, 'no_box_tree_evidence', fn (): bool => ! $evidence->hasEvidence()),
            array(self::STAGE_RESTATEMENT, 'wrapper_is_not_div', fn (): bool => 'div' !== strtolower($wrapper->tagName)),
            // Any child block whose saved root takes a class can carry the wrapper's.
            array(self::STAGE_RESTATEMENT, 'child_block_cannot_take_class', static function () use ($identity, $blockName, &$detail): bool {
                if ($identity->childBlockTakesIdentity($blockName, false)) return false;
                $detail = $blockName;
                return true;
            }),
            array(self::STAGE_IDENTITY, 'saved_group_boundary', fn (): bool => SourceDom::hasClass($wrapper, 'wp-block-group')),
            array(self::STAGE_IDENTITY, 'child_is_binding_anchor', fn (): bool => $this->signals->isBindingAnchor($childBlock)),
            array(self::STAGE_IDENTITY, 'runtime_dom_target', fn (): bool => $this->runtimeIslands->isRuntimeDomTarget($wrapper)),
            array(self::STAGE_IDENTITY, 'has_motion_structure_token', fn (): bool => $this->sourceElementClassifier->hasMotionStructureToken($wrapper)),
            array(self::STAGE_IDENTITY, 'has_interactive_attributes', fn (): bool => array() !== $this->signals->interactiveAttributes($wrapper)),
            // Besides the class and the id, an inert attribute is dropped, a
            // read one moves, and one only author CSS reads is projected to
            // an engine-owned class; any other keeps the wrapper.
            array(self::STAGE_IDENTITY, 'unrestatable_wrapper_attribute', static function () use ($wrapper, $identity, &$movedAttributes, &$projectedAttributes, &$detail): bool {
                foreach (\Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree::authoredAttributes($wrapper) as $attribute) {
                    $name = strtolower($attribute->nodeName);
                    if (in_array($name, array('class', 'id'), true)) continue;
                    [$use, $useDetail] = $identity->attributeUse($wrapper, $name);
                    if (RestatedWrapperIdentity::DROP === $use) continue;
                    if (RestatedWrapperIdentity::PROJECT === $use) {
                        $projectedAttributes[$name] = (string) $attribute->nodeValue;
                        continue;
                    }
                    if (RestatedWrapperIdentity::MOVE === $use) {
                        $movedAttributes[$name] = (string) $attribute->nodeValue;
                        continue;
                    }
                    $detail = $useDetail;
                    return true;
                }
                return false;
            }),
            // The child block comes from the wrapper's sole element child, or
            // from a deeper element whose ancestors up to the wrapper are a
            // sole-child chain already folded into that block.
            array(self::STAGE_RESTATEMENT, 'child_block_not_from_sole_child', function () use ($wrapper, $childBlock, &$sourceChild, &$chain, &$detail): bool {
                $found = $this->signals->foldedChainSourceChild($wrapper, $childBlock);
                if (null === $found) {
                    $sourceChild = $this->signals->matchingSourceChild($wrapper, $childBlock);
                    $detail = null === $this->signals->soleElementChildOf($wrapper) ? 'no_sole_child' : (null === $sourceChild ? 'no_source_match' : 'not_in_chain');
                    return true;
                }
                [$sourceChild, $folded] = $found;
                $carried = SourceDom::tokens((string) ($childBlock['attrs']['className'] ?? ''));
                $classes = array();
                foreach ($folded as $element) {
                    foreach (SourceDom::classNames($element) as $class) {
                        if (in_array($class, $carried, true)) $classes[] = $class;
                    }
                }
                $chain = array('elements' => $folded, 'classes' => array_values(array_unique($classes)), 'anchor' => trim((string) ($childBlock['attrs']['anchor'] ?? '')));
                return false;
            }),
            array(self::STAGE_IDENTITY, 'child_runtime_dom_target', function () use (&$sourceChild): bool {
                return $this->runtimeIslands->isRuntimeDomTarget($sourceChild) || $this->sourceElementClassifier->hasMotionStructureToken($sourceChild);
            }),
            // A moved attribute lands only where the child does not already
            // carry a different value of it.
            array(self::STAGE_IDENTITY, 'child_has_moved_attribute', static function () use (&$sourceChild, &$movedAttributes, &$detail): bool {
                foreach ($movedAttributes as $name => $value) {
                    if (! $sourceChild->hasAttribute($name)) continue;
                    if ($value === $sourceChild->getAttribute($name)) {
                        unset($movedAttributes[$name]);
                        continue;
                    }
                    $detail = $name;
                    return true;
                }
                return false;
            }),
            // An unreferenced wrapper id is dropped. A referenced one moves
            // onto the child, replacing the child's own id only when nothing
            // references that one.
            array(self::STAGE_IDENTITY, 'child_has_own_id', static function () use (&$sourceChild, &$movedId, &$detail, $wrapperId, $childBlock, $identity, $wrapper): bool {
                if ('' === $wrapperId) return false;
                if (null === $identity->idReference($wrapper, $wrapperId)) {
                    $movedId = '';
                    return false;
                }
                foreach (array_unique(array_filter(array(trim(SourceDom::attr($sourceChild, 'id')), trim((string) ($childBlock['attrs']['anchor'] ?? ''))))) as $childId) {
                    $reference = $identity->idReference($sourceChild, $childId);
                    if (null === $reference) continue;
                    $detail = $childId . ':' . $reference;
                    return true;
                }
                return false;
            }),
            array(self::STAGE_IDENTITY, 'child_block_cannot_take_anchor', static function () use ($identity, $blockName, &$movedId, &$detail): bool {
                if ('' === $movedId || $identity->childBlockTakesIdentity($blockName, true)) return false;
                $detail = $blockName;
                return true;
            }),
            // Shell extraction reads page chrome from the groups at the
            // document's edges; an id there names the shell.
            array(self::STAGE_IDENTITY, 'document_edge_wrapper_id', fn (): bool => '' !== $wrapperId && $isDocumentEdge($wrapper)),
            array(self::STAGE_GEOMETRY, 'geometry_not_neutral', static function () use ($evidence, $wrapper, &$detail): bool {
                $answer = $evidence->classify($wrapper, true);
                if (BoxTreeWrapperEvidence::NEUTRAL === $answer['state']) return false;
                $detail = $answer['reason'];
                return true;
            }),
            // A flex, grid or flow-root wrapper is a formatting context its
            // child is laid out in, even when their boxes coincide.
            array(self::STAGE_GEOMETRY, 'wrapper_formatting_context', static function () use ($evidence, $wrapper, &$detail): bool {
                foreach ($evidence->usedValues($wrapper, 'display') ?? array('unobserved' => '') as $display) {
                    if ('block' === $display) continue;
                    $detail = '' === $display ? 'unobserved' : $display;
                    return true;
                }
                return false;
            }),
            array(self::STAGE_GEOMETRY, 'unsupported_presentation_attributes', function () use ($wrapper, &$attrs, &$detail): bool {
                $attrs = $this->styleResolver->presentationAttributes($wrapper);
                $extra = array_diff(array_keys($attrs), array('className', 'anchor', 'style'));
                if (array() === $extra) return false;
                $detail = implode(',', $extra);
                return true;
            }),
        );
        foreach (self::restatementProofFailures() as $failure) {
            $guards[] = array(self::STAGE_RESTATEMENT, $failure, fn (): bool => $proofFails($failure));
        }
        $kept = static function (WrapperDisposition $disposition) use (&$sourceChild, &$attrs): array {
            return array('disposition' => $disposition, 'source_child' => $sourceChild, 'moved_id' => '', 'moved_attributes' => array(), 'projected_attributes' => array(), 'folded_chain' => array(), 'attrs' => $attrs, 'arms' => array());
        };
        foreach ($guards as [$stage, $reason, $matches]) {
            $detail = '';
            if ($matches()) {
                return $kept(WrapperDisposition::preserve($reason, $stage)->withDetail('' === $detail ? $reason : $detail));
            }
        }

        // The id the wrapper would emit must be its source id.
        $anchor = (string) ($attrs['anchor'] ?? '');
        if ($anchor !== ('' === $wrapperId ? '' : SourceDom::anchorAttributeValue($wrapperId))) {
            return $kept(WrapperDisposition::preserve('anchor_not_restatable', self::STAGE_IDENTITY)->withDetail('' === $anchor ? 'empty_anchor' : $anchor));
        }
        if (null !== $proven()['failure']) {
            return $kept(WrapperDisposition::preserve('restatement_not_proven', self::STAGE_RESTATEMENT)->withDetail((string) $proven()['failure']));
        }

        return array(
            'disposition' => WrapperDisposition::fold(self::RESTATED_WRAPPER_FOLD, self::STAGE_RESTATEMENT),
            'source_child' => $sourceChild,
            'moved_id' => $movedId,
            'moved_attributes' => $movedAttributes,
            'projected_attributes' => $projectedAttributes,
            'folded_chain' => $chain['elements'],
            'attrs' => $attrs,
            'arms' => $proven()['arms'],
        );
    }

    /** @return list<string> The {@see RestatedWrapperFold} failures, in guard order. */
    private static function restatementProofFailures(): array
    {
        return array(
            RestatedWrapperFold::UNVERIFIABLE_SELECTOR,
            RestatedWrapperFold::SELECTOR_CHECK_BUDGET,
            RestatedWrapperFold::WRAPPER_RULE_NOT_RESTATED,
            RestatedWrapperFold::SELECTOR_MATCH_CHANGED,
            RestatedWrapperFold::INHERITED_TEXT_OVERRIDE,
            RestatedWrapperFold::WRAPPER_DECLARATION_CONTESTED,
            RestatedWrapperFold::WRAPPER_DECLARATION_UNRESTATABLE,
            RestatedWrapperFold::CHILD_RELATIVE_TO_WRAPPER,
        );
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
            'padding_shell_absorption' => array(
                'unsupported_child_block_name' => self::STAGE_RESTATEMENT,
                'unsupported_wrapper_tag' => self::STAGE_RESTATEMENT,
                'child_group_has_tag' => self::STAGE_RESTATEMENT,
                'saved_group_boundary' => self::STAGE_IDENTITY,
                'child_is_binding_anchor' => self::STAGE_IDENTITY,
                'runtime_dom_target' => self::STAGE_IDENTITY,
                'has_motion_structure_token' => self::STAGE_IDENTITY,
                'has_id_attribute' => self::STAGE_IDENTITY,
                'has_role_attribute' => self::STAGE_IDENTITY,
                'has_accessible_name' => self::STAGE_IDENTITY,
                'has_interactive_attributes' => self::STAGE_IDENTITY,
                'has_data_attributes' => self::STAGE_IDENTITY,
                'structural_layout_element' => self::STAGE_GEOMETRY,
                'structural_layout_child' => self::STAGE_GEOMETRY,
                'wrapper_has_no_vertical_padding' => self::STAGE_GEOMETRY,
                'wrapper_box_beyond_vertical_padding' => self::STAGE_GEOMETRY,
                'wrapper_declares_inherited_text' => self::STAGE_RESTATEMENT,
                'wrapper_state_or_pseudo_element_rule' => self::STAGE_RESTATEMENT,
                'missing_sole_child' => self::STAGE_RESTATEMENT,
                'child_block_not_from_sole_child' => self::STAGE_RESTATEMENT,
                'child_tag_mismatch' => self::STAGE_RESTATEMENT,
                'child_runtime_dom_target' => self::STAGE_IDENTITY,
                'child_has_motion_structure_token' => self::STAGE_IDENTITY,
                'child_box_grows_with_padding' => self::STAGE_GEOMETRY,
                'spacing_owners_overlap' => self::STAGE_GEOMETRY,
                'custom_property_owners_overlap' => self::STAGE_RESTATEMENT,
                'selector_matching_does_not_survive_coalescing' => self::STAGE_RESTATEMENT,
            ),
            'restated_wrapper_fold' => array(
                'no_box_tree_evidence' => self::STAGE_GEOMETRY,
                'wrapper_is_not_div' => self::STAGE_RESTATEMENT,
                'child_block_cannot_take_class' => self::STAGE_RESTATEMENT,
                'saved_group_boundary' => self::STAGE_IDENTITY,
                'child_is_binding_anchor' => self::STAGE_IDENTITY,
                'runtime_dom_target' => self::STAGE_IDENTITY,
                'has_motion_structure_token' => self::STAGE_IDENTITY,
                'has_interactive_attributes' => self::STAGE_IDENTITY,
                'unrestatable_wrapper_attribute' => self::STAGE_IDENTITY,
                'child_block_not_from_sole_child' => self::STAGE_RESTATEMENT,
                'child_runtime_dom_target' => self::STAGE_IDENTITY,
                'child_has_moved_attribute' => self::STAGE_IDENTITY,
                'child_has_own_id' => self::STAGE_IDENTITY,
                'child_block_cannot_take_anchor' => self::STAGE_IDENTITY,
                'document_edge_wrapper_id' => self::STAGE_IDENTITY,
                'geometry_not_neutral' => self::STAGE_GEOMETRY,
                'wrapper_formatting_context' => self::STAGE_GEOMETRY,
                'unsupported_presentation_attributes' => self::STAGE_GEOMETRY,
                RestatedWrapperFold::UNVERIFIABLE_SELECTOR => self::STAGE_RESTATEMENT,
                RestatedWrapperFold::SELECTOR_CHECK_BUDGET => self::STAGE_RESTATEMENT,
                RestatedWrapperFold::WRAPPER_RULE_NOT_RESTATED => self::STAGE_RESTATEMENT,
                RestatedWrapperFold::SELECTOR_MATCH_CHANGED => self::STAGE_RESTATEMENT,
                RestatedWrapperFold::INHERITED_TEXT_OVERRIDE => self::STAGE_RESTATEMENT,
                RestatedWrapperFold::WRAPPER_DECLARATION_CONTESTED => self::STAGE_RESTATEMENT,
                RestatedWrapperFold::WRAPPER_DECLARATION_UNRESTATABLE => self::STAGE_RESTATEMENT,
                RestatedWrapperFold::CHILD_RELATIVE_TO_WRAPPER => self::STAGE_RESTATEMENT,
                'anchor_not_restatable' => self::STAGE_IDENTITY,
                'restatement_not_proven' => self::STAGE_RESTATEMENT,
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

    /**
     * Does a wrapper's own box paint chrome (padding, border, sizing) that a
     * lone styled inline inside it must not absorb? Formerly
     * {@see AuthorLayoutOwnership::hasBoxChromeWrapperStyling()}; the
     * declarations are the wrapper's structural presentation.
     *
     * @param array<string, mixed> $declarations
     */
    public static function boxChromeWrapper(array $declarations): ?WrapperDisposition
    {
        return self::firstMatch(array(
            array(self::STAGE_GEOMETRY, 'box_chrome_styling', static function () use ($declarations): bool {
                foreach ( array( 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'border', 'border-color', 'border-radius', 'width', 'height', 'min-width', 'max-width', 'min-height' ) as $property ) {
                    if ( isset($declarations[$property]) && CssValueInspector::isNonZero((string) $declarations[$property]) ) {
                        return true;
                    }
                }
                return false;
            }),
        ), WrapperDisposition::preserve(...));
    }

    /**
     * Is a selector-addressed styled inline the only content of a flow
     * wrapper that paints box chrome? Then the wrapper keeps its own box
     * (`preserve`) and the inline lowers as a standalone leaf rather than a
     * paragraph adding its own line box and spacing. A `fold` names why the
     * inline takes the ordinary path. Formerly
     * {@see AuthorLayoutOwnership::isLoneStyledInlineInFlowWrapper()}.
     *
     * @param Closure(): bool $richTextInlineContext
     * @param Closure(DOMElement): bool $inlineParent
     * @param Closure(DOMElement): bool $boxChromeParent
     * @param Closure(): bool $selectorAddressed
     */
    public static function loneStyledInlineInFlowWrapper(DOMElement $element, Closure $richTextInlineContext, Closure $inlineParent, Closure $boxChromeParent, Closure $selectorAddressed): WrapperDisposition
    {
        $parent = $element->parentNode;

        return self::firstMatch(array(
            array(self::STAGE_RESTATEMENT, 'no_parent_element', fn (): bool => ! $parent instanceof DOMElement),
            array(self::STAGE_RESTATEMENT, 'rich_text_inline_context', $richTextInlineContext),
            array(self::STAGE_GEOMETRY, 'inline_parent', fn (): bool => $inlineParent($parent)),
            array(self::STAGE_GEOMETRY, 'parent_not_inline_content_wrapper', fn (): bool => ! ShellLandmarkPolicy::isInlineContentWrapperTag($parent->tagName)),
            array(self::STAGE_GEOMETRY, 'parent_without_box_chrome', fn (): bool => ! $boxChromeParent($parent)),
            array(self::STAGE_RESTATEMENT, 'not_selector_addressed', fn (): bool => ! $selectorAddressed()),
            array(self::STAGE_RESTATEMENT, 'parent_has_other_content', static function () use ($parent, $element): bool {
                foreach ( $parent->childNodes as $child ) {
                    if ( $child === $element || XML_COMMENT_NODE === $child->nodeType ) {
                        continue;
                    }
                    if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                        continue;
                    }
                    return true;
                }
                return false;
            }),
        ), WrapperDisposition::fold(...))
            ?? WrapperDisposition::preserve('lone_styled_inline_in_box_chrome_wrapper', self::STAGE_GEOMETRY);
    }

    /**
     * The inner `.wp-block-button` save wrapper of a native control that is
     * a flex/grid item: its box is flattened and the control's layout is
     * owned by CSS on the link (`css-owned`), or it participates
     * (`preserve`). Formerly {@see LayoutParticipation::flattensInnerSaveWrapper()}.
     */
    public static function innerSaveWrapper(LayoutParticipation $participation): WrapperDisposition
    {
        return self::firstMatch(array(
            array(self::STAGE_GEOMETRY, 'native_control_layout_item', fn (): bool => $participation->isLayoutItem() && $participation->sourceIsNativeControl),
        ), WrapperDisposition::cssOwned(...))
            ?? WrapperDisposition::preserve('inner_save_wrapper_participates', self::STAGE_GEOMETRY);
    }

    /**
     * Synthesized `core/buttons`: neutralized (`css-owned`) when its box
     * would change the source control's participation or steal a positioned
     * child's containing block; otherwise it keeps its flex box. Formerly
     * {@see LayoutParticipation::neutralizeButtonsWrapper()}.
     */
    public static function buttonsSaveWrapper(LayoutParticipation $participation): WrapperDisposition
    {
        return self::firstMatch(array(
            array(self::STAGE_GEOMETRY, 'positioned_control', fn (): bool => $participation->positioned),
            // An inline control that fills an aligned line alone keeps the
            // wrapper's flex box: it is the only box left to hold the
            // restated justifyContent.
            array(self::STAGE_GEOMETRY, 'inline_control_without_aligned_line', fn (): bool => $participation->isInline() && ! $participation->ownsAlignedLine),
            array(self::STAGE_GEOMETRY, 'native_control_layout_item', fn (): bool => self::innerSaveWrapper($participation)->isCssOwned()),
        ), WrapperDisposition::cssOwned(...))
            ?? WrapperDisposition::preserve('buttons_wrapper_participates', self::STAGE_GEOMETRY);
    }

    /**
     * Synthesized inner `.wp-block-button` wrapper: kept as the positioning
     * box of an out-of-flow control, neutralized (`css-owned`) for inline
     * controls and flattened native-control layout items, otherwise kept.
     * Formerly {@see LayoutParticipation::neutralizeButtonWrapper()}.
     */
    public static function buttonSaveWrapper(LayoutParticipation $participation): WrapperDisposition
    {
        $preserve = self::firstMatch(array(
            array(self::STAGE_GEOMETRY, 'positioning_box_for_out_of_flow_control', fn (): bool => $participation->positioned),
        ), WrapperDisposition::preserve(...));
        if ( null !== $preserve ) {
            return $preserve;
        }

        return self::firstMatch(array(
            array(self::STAGE_GEOMETRY, 'inline_control', fn (): bool => $participation->isInline()),
            array(self::STAGE_GEOMETRY, 'native_control_layout_item', fn (): bool => self::innerSaveWrapper($participation)->isCssOwned()),
        ), WrapperDisposition::cssOwned(...))
            ?? WrapperDisposition::preserve('button_wrapper_participates', self::STAGE_GEOMETRY);
    }

    /**
     * One source element kept as a layout-shell wrapper (`preserve`): the
     * reason names whether its tag is restated (`div` for a custom element,
     * or for a field-box `<label>` whose provider field renders its own
     * label) or kept. Formerly inlined in
     * {@see LayoutShellLowering::layoutShellWrapper()}.
     *
     * @return array{disposition: WrapperDisposition, tagName: string}
     */
    public static function layoutShellWrapper(DOMElement $element, bool $fieldBox): array
    {
        $sourceTagName = strtolower($element->tagName);
        $disposition = self::firstMatch(array(
            array(self::STAGE_RESTATEMENT, 'custom_element_restated_as_div', fn (): bool => str_contains($sourceTagName, '-')),
            array(self::STAGE_RESTATEMENT, 'field_box_label_restated_as_div', fn (): bool => $fieldBox && 'label' === $sourceTagName),
        ), WrapperDisposition::preserve(...))
            ?? WrapperDisposition::preserve('source_tag', self::STAGE_RESTATEMENT);

        return array(
            'disposition' => $disposition,
            'tagName' => 'source_tag' === $disposition->reason ? $sourceTagName : 'div',
        );
    }

    /**
     * A layout-shell wrapper that keeps every source class verbatim
     * (`preserve`), or null when only its promoted, marker, runtime and
     * engine classes are restated. Formerly inlined in
     * `LayoutShellLowering::layoutShellClassName()`.
     */
    public static function layoutShellSourceClasses(DOMElement $element): ?WrapperDisposition
    {
        return self::firstMatch(array(
            array(self::STAGE_IDENTITY, 'capture_device_visibility_carrier', fn (): bool => SourceDom::isCaptureDeviceVisibilityCarrier($element)),
        ), WrapperDisposition::preserve(...));
    }

    /**
     * Why a raw source class is restated on a layout-shell wrapper, or null
     * when it is not (promoted classes are restated separately).
     *
     * @param array<string, true> $runtimeClasses
     */
    public static function layoutShellRestatedClass(string $class, array $runtimeClasses): ?string
    {
        return self::firstMatch(array(
            array(self::STAGE_IDENTITY, 'runtime_dom_selector_class', fn (): bool => isset($runtimeClasses[$class])),
            array(self::STAGE_RESTATEMENT, 'engine_attribute_class', fn (): bool => 1 === preg_match('/^blocks-engine-(?:attribute|editor-anchor)-/', $class)),
        ), WrapperDisposition::preserve(...))?->reason;
    }

    /**
     * The wrapper decisions of the element conversion prelude, in dispatch
     * order: inert scaffolding is dropped, a `<span>` around a form keeps a
     * group of its own, and a standalone inline layout leaf folds its
     * wrapper's paragraph away. Null continues the prelude. Formerly inlined
     * in {@see ElementConversionPrelude::convert()}.
     *
     * @param Closure(): ?WrapperDisposition $inertScaffolding
     * @param Closure(): bool $spanHoldsForm
     * @param Closure(): bool $standaloneInlineLayoutLeaf
     */
    public static function preludeWrapper(Closure $inertScaffolding, Closure $spanHoldsForm, Closure $standaloneInlineLayoutLeaf): ?WrapperDisposition
    {
        $drop = $inertScaffolding();
        if ( null !== $drop && $drop->isDrop() ) {
            return $drop;
        }

        return self::firstMatch(array(
            array(self::STAGE_IDENTITY, 'span_holds_form', $spanHoldsForm),
        ), WrapperDisposition::preserve(...))
            ?? self::firstMatch(array(
                array(self::STAGE_GEOMETRY, 'standalone_inline_layout_leaf', $standaloneInlineLayoutLeaf),
            ), WrapperDisposition::fold(...));
    }

    /**
     * A RichText inline styling hook: unwrapped when it carries nothing
     * (`fold`), restated on a RichText-safe mark (`preserve`), unwrapped
     * because a span or unknown element is not representable (`fold`), or
     * kept as an inline format. Formerly inlined in
     * {@see RichTextStylingHookNormalizer::normalize()}. Restating on a mark
     * mutates the DOM, so it runs only once the attribute-less guard passed.
     *
     * @param Closure(): bool $restatedOnMark
     */
    public static function richTextStylingHook(DOMElement $inline, Closure $restatedOnMark): WrapperDisposition
    {
        $tagName = strtolower($inline->tagName);

        return self::firstMatch(array(
            array(self::STAGE_IDENTITY, 'attributeless_font', fn (): bool => 'font' === $tagName && ! $inline->hasAttributes()),
        ), WrapperDisposition::fold(...))
            ?? self::firstMatch(array(
                array(self::STAGE_RESTATEMENT, 'restated_on_mark', $restatedOnMark),
            ), WrapperDisposition::preserve(...))
            ?? self::firstMatch(array(
                array(self::STAGE_RESTATEMENT, 'unrepresentable_inline_wrapper', fn (): bool => 'span' === $tagName || RichTextInlineTags::isUnknownHtmlElement($inline)),
            ), WrapperDisposition::fold(...))
            ?? WrapperDisposition::preserve('rich_text_inline_format', self::STAGE_RESTATEMENT);
    }

    /**
     * May a styling-hook span that wraps a block's whole RichText content be
     * hoisted onto the block (`fold`, its classes and declarations restated
     * there)? A list item with block content keeps its topology, and a span
     * whose identity must stay on the saved carrier is kept (`preserve`).
     * Formerly inlined in {@see RichTextStylingHookNormalizer::hoistContentWrappingSpans()}.
     *
     * @param array<string, string> $declarations
     * @param Closure(): bool $hasSafeIdentity
     */
    public static function contentWrappingSpan(array $declarations, Closure $hasSafeIdentity): WrapperDisposition
    {
        return self::firstMatch(array(
            array(self::STAGE_IDENTITY, 'rich_text_safe_identity', $hasSafeIdentity),
            array(self::STAGE_IDENTITY, 'rich_text_marker_declaration', fn (): bool => isset($declarations['--blocks-engine-richtext-marker'])),
        ), WrapperDisposition::preserve(...))
            ?? WrapperDisposition::fold('content_wrapping_span_hoisted', self::STAGE_RESTATEMENT);
    }

    /**
     * A list item whose content carries block-level children keeps that
     * topology: no wrapper is hoisted onto it (`preserve`). Null when
     * hoisting may proceed.
     *
     * @param Closure(): bool $hasBlockContentChildren
     */
    public static function listItemContentHoisting(bool $isListItem, Closure $hasBlockContentChildren): ?WrapperDisposition
    {
        return self::firstMatch(array(
            array(self::STAGE_RESTATEMENT, 'list_item_has_block_content', fn (): bool => $isListItem && $hasBlockContentChildren()),
        ), WrapperDisposition::preserve(...));
    }

    /**
     * Guard names of the slice-5 tables (#7471), in evaluation order, keyed
     * to their stage. Pinned by `tests/unit/wrapper-decision-layout.php`.
     *
     * @return array<string, array<string, string>>
     */
    public static function layoutGuardStages(): array
    {
        return array(
            'box_chrome_wrapper' => array(
                'box_chrome_styling' => self::STAGE_GEOMETRY,
            ),
            'lone_styled_inline_in_flow_wrapper' => array(
                'no_parent_element' => self::STAGE_RESTATEMENT,
                'rich_text_inline_context' => self::STAGE_RESTATEMENT,
                'inline_parent' => self::STAGE_GEOMETRY,
                'parent_not_inline_content_wrapper' => self::STAGE_GEOMETRY,
                'parent_without_box_chrome' => self::STAGE_GEOMETRY,
                'not_selector_addressed' => self::STAGE_RESTATEMENT,
                'parent_has_other_content' => self::STAGE_RESTATEMENT,
                'lone_styled_inline_in_box_chrome_wrapper' => self::STAGE_GEOMETRY,
            ),
            'inner_save_wrapper' => array(
                'native_control_layout_item' => self::STAGE_GEOMETRY,
                'inner_save_wrapper_participates' => self::STAGE_GEOMETRY,
            ),
            'buttons_save_wrapper' => array(
                'positioned_control' => self::STAGE_GEOMETRY,
                'inline_control_without_aligned_line' => self::STAGE_GEOMETRY,
                'native_control_layout_item' => self::STAGE_GEOMETRY,
                'buttons_wrapper_participates' => self::STAGE_GEOMETRY,
            ),
            'button_save_wrapper' => array(
                'positioning_box_for_out_of_flow_control' => self::STAGE_GEOMETRY,
                'inline_control' => self::STAGE_GEOMETRY,
                'native_control_layout_item' => self::STAGE_GEOMETRY,
                'button_wrapper_participates' => self::STAGE_GEOMETRY,
            ),
            'layout_shell_wrapper' => array(
                'custom_element_restated_as_div' => self::STAGE_RESTATEMENT,
                'field_box_label_restated_as_div' => self::STAGE_RESTATEMENT,
                'source_tag' => self::STAGE_RESTATEMENT,
                'capture_device_visibility_carrier' => self::STAGE_IDENTITY,
                'runtime_dom_selector_class' => self::STAGE_IDENTITY,
                'engine_attribute_class' => self::STAGE_RESTATEMENT,
            ),
            'prelude_wrapper' => array(
                'span_holds_form' => self::STAGE_IDENTITY,
                'standalone_inline_layout_leaf' => self::STAGE_GEOMETRY,
            ),
            'rich_text_styling_hook' => array(
                'attributeless_font' => self::STAGE_IDENTITY,
                'restated_on_mark' => self::STAGE_RESTATEMENT,
                'unrepresentable_inline_wrapper' => self::STAGE_RESTATEMENT,
                'rich_text_inline_format' => self::STAGE_RESTATEMENT,
            ),
            'content_wrapping_span' => array(
                'list_item_has_block_content' => self::STAGE_RESTATEMENT,
                'rich_text_safe_identity' => self::STAGE_IDENTITY,
                'rich_text_marker_declaration' => self::STAGE_IDENTITY,
                'content_wrapping_span_hoisted' => self::STAGE_RESTATEMENT,
            ),
        );
    }

    /**
     * Does a label wrapper between a button control and its label paint a
     * box of its own (fill, border, shadow or outline)? Then it is kept
     * (`preserve`) and the label is not lowered through it; otherwise it
     * folds into the button's RichText. Formerly
     * `ButtonsPattern::labelWrapperPaints()`.
     */
    public static function buttonLabelWrapper(string $resolvedStyle): WrapperDisposition
    {
        $style = strtolower($resolvedStyle);
        $sides = static fn (string $prefix, array $suffixes): array => array_merge(...array_map(
            static fn (string $side): array => array_map(static fn (string $suffix): string => $prefix . '-' . $side . '-' . $suffix, $suffixes),
            array( 'top', 'right', 'bottom', 'left' )
        ));
        $absent = '/^(?:0|none)\s*[a-z%]*\s*$/';
        $firstPaints = static function (array $properties, string $negation) use ($style): bool {
            $first = CssRuleAnalyzer::declarations($style, $properties)[0] ?? null;
            return null !== $first && 1 !== preg_match($negation, $first['value']);
        };

        return self::firstMatch(array(
            array(self::STAGE_GEOMETRY, 'paints_background', static function () use ($style): bool {
                foreach ( CssRuleAnalyzer::declarations($style, array( 'background', 'background-color', 'background-image' )) as [ 'value' => $background ] ) {
                    if ( CssValueInspector::backgroundPaints($background) ) {
                        return true;
                    }
                }
                return false;
            }),
            array(self::STAGE_GEOMETRY, 'paints_border', fn (): bool => $firstPaints(array_merge(array( 'border' ), $sides('border', array( 'width', 'style', 'color' ))), $absent)),
            array(self::STAGE_GEOMETRY, 'paints_box_shadow', fn (): bool => $firstPaints(array( 'box-shadow' ), '/^none\s*$/')),
            array(self::STAGE_GEOMETRY, 'paints_outline', fn (): bool => $firstPaints(array( 'outline', 'outline-width', 'outline-style', 'outline-color' ), $absent)),
        ), WrapperDisposition::preserve(...))
            ?? WrapperDisposition::fold('paint_free_label_wrapper', self::STAGE_GEOMETRY);
    }

    /**
     * A wrapper around a bare anchor that is itself the button's control
     * surface: it folds into `core/buttons` (its presentation becomes the
     * button's) when it has a button role or a button style signal. Null
     * keeps it. Formerly `ButtonsPattern::hasWrapperButtonSignal()`.
     *
     * @param Closure(): bool $styleSignal
     */
    public static function buttonControlWrapper(DOMElement $wrapper, Closure $styleSignal): ?WrapperDisposition
    {
        return self::firstMatch(array(
            array(self::STAGE_IDENTITY, 'button_role', fn (): bool => 'button' === strtolower($wrapper->hasAttribute('role') ? $wrapper->getAttribute('role') : '')),
            array(self::STAGE_GEOMETRY, 'wrapper_button_style_signal', $styleSignal),
        ), WrapperDisposition::fold(...));
    }

    /**
     * The anchor's only child, when it is a span or div wrapping the whole
     * label with attributes the accessible-link RichText element can carry:
     * that wrapper is restated as the RichText element (`preserve`).
     * Otherwise a `fold` names why the anchor's own label is used. Formerly
     * `ButtonsPattern::accessibleLinkContentWrapper()`.
     *
     * @param Closure(string): bool $contentAttribute
     * @return array{disposition: WrapperDisposition, wrapper: ?DOMElement}
     */
    public static function accessibleLinkContentWrapper(DOMElement $anchor, Closure $contentAttribute): array
    {
        $wrapper = null;
        $disposition = self::firstMatch(array(
            array(self::STAGE_RESTATEMENT, 'not_single_child_element', static function () use ($anchor, &$wrapper): bool {
                foreach ( $anchor->childNodes as $child ) {
                    if ( $child instanceof DOMElement ) {
                        if ( null !== $wrapper ) {
                            return true;
                        }
                        $wrapper = $child;
                    } elseif ( $child instanceof \DOMText || $child instanceof \DOMCdataSection ) {
                        if ( '' !== trim($child->textContent) ) {
                            return true;
                        }
                    }
                }
                return ! $wrapper instanceof DOMElement;
            }),
            array(self::STAGE_RESTATEMENT, 'not_span_or_div', static function () use (&$wrapper): bool {
                return ! in_array(strtolower($wrapper->tagName), array( 'span', 'div' ), true);
            }),
            array(self::STAGE_IDENTITY, 'no_attributes', static function () use (&$wrapper): bool {
                return ! $wrapper->hasAttributes();
            }),
            array(self::STAGE_IDENTITY, 'non_content_attribute', static function () use (&$wrapper, $contentAttribute): bool {
                foreach ( $wrapper->attributes ?? array() as $attribute ) {
                    if ( ! $contentAttribute(strtolower($attribute->name)) ) {
                        return true;
                    }
                }
                return false;
            }),
        ), WrapperDisposition::fold(...));

        return null === $disposition
            ? array( 'disposition' => WrapperDisposition::preserve('restated_on_rich_text_element', self::STAGE_RESTATEMENT), 'wrapper' => $wrapper )
            : array( 'disposition' => $disposition, 'wrapper' => null );
    }

    /**
     * The `<span>` chain wrapping a whole button label: unwrapped (`fold`)
     * unless every span carries a source identity and the label holds its
     * own source elements, which the wrapper lays out (`preserve`). Formerly
     * the tail of `ButtonsPattern::unwrapPresentationalSpan()`.
     *
     * @param bool|null $identified Null when no span wraps the label; false when a bare span is in the chain.
     * @param Closure(): bool $retainedSourceElement
     * @param Closure(): bool $sourceItemSequence
     */
    public static function buttonLabelSpan(?bool $identified, Closure $retainedSourceElement, Closure $sourceItemSequence): WrapperDisposition
    {
        return self::firstMatch(array(
            array(self::STAGE_RESTATEMENT, 'no_wrapping_span', fn (): bool => null === $identified),
            array(self::STAGE_IDENTITY, 'bare_span_not_restorable', fn (): bool => false === $identified),
        ), WrapperDisposition::fold(...))
            ?? self::firstMatch(array(
                array(self::STAGE_RESTATEMENT, 'retained_source_element', $retainedSourceElement),
                array(self::STAGE_RESTATEMENT, 'source_item_sequence', $sourceItemSequence),
            ), WrapperDisposition::preserve(...))
            ?? WrapperDisposition::fold('presentational_label_span', self::STAGE_RESTATEMENT);
    }

    /**
     * A navigation landmark's sole layout box, when the brand row sits one
     * level down: kept as the carrier's own group (`preserve`). A `fold`
     * names why the landmark is read directly. Formerly
     * `NavigationPattern::carrierLayoutWrapper()`.
     *
     * @param Closure(DOMElement): bool $navigationChrome
     * @return array{disposition: WrapperDisposition, wrapper: ?DOMElement}
     */
    public static function navigationCarrierLayoutWrapper(DOMElement $nav, Closure $navigationChrome): array
    {
        $wrapper = null;
        $disposition = self::firstMatch(array(
            array(self::STAGE_RESTATEMENT, 'not_single_child_element', static function () use ($nav, &$wrapper): bool {
                foreach ( $nav->childNodes as $child ) {
                    if ( XML_COMMENT_NODE === $child->nodeType
                        || ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) ) {
                        continue;
                    }
                    if ( ! $child instanceof DOMElement || $wrapper instanceof DOMElement ) {
                        return true;
                    }
                    $wrapper = $child;
                }
                return ! $wrapper instanceof DOMElement;
            }),
            array(self::STAGE_IDENTITY, 'not_div', static function () use (&$wrapper): bool {
                return 'div' !== strtolower($wrapper->tagName);
            }),
            array(self::STAGE_IDENTITY, 'navigation_chrome', static function () use (&$wrapper, $navigationChrome): bool {
                return $navigationChrome($wrapper);
            }),
            array(self::STAGE_GEOMETRY, 'fewer_than_two_element_children', static function () use (&$wrapper): bool {
                $elements = 0;
                foreach ( $wrapper->childNodes as $child ) {
                    if ( $child instanceof DOMElement ) {
                        ++$elements;
                    }
                }
                return 2 > $elements;
            }),
        ), WrapperDisposition::fold(...));

        return null === $disposition
            ? array( 'disposition' => WrapperDisposition::preserve('carrier_layout_row_box', self::STAGE_GEOMETRY), 'wrapper' => $wrapper )
            : array( 'disposition' => $disposition, 'wrapper' => null );
    }

    /**
     * A link-run wrapper core/navigation flattens into its menu (`fold`):
     * the reason names whether its responsive visibility is restated on each
     * item through a carrier class (`visibility_restated_per_item`) or why it
     * is not. Formerly `NavigationPattern::withFlattenedWrapperVisibility()`.
     * The carrier class records the wrapper as a projection subject, so it
     * is requested only once every other guard passed.
     *
     * @param Closure(): bool $displayNoneAtEveryViewport
     * @param Closure(): array<string, list<string>> $declaredAtAnyViewport
     * @param Closure(string, list<string>): bool $distributable
     * @param Closure(): string $carrierClass
     * @return array{disposition: WrapperDisposition, className: string}
     */
    public static function flattenedNavigationWrapper(bool $hasContext, Closure $displayNoneAtEveryViewport, Closure $declaredAtAnyViewport, Closure $distributable, Closure $carrierClass): array
    {
        $declared = null;
        $declarations = static function () use (&$declared, $declaredAtAnyViewport): array {
            return $declared ??= $declaredAtAnyViewport();
        };
        $className = '';
        $disposition = self::firstMatch(array(
            array(self::STAGE_IDENTITY, 'no_navigation_context', fn (): bool => ! $hasContext),
            array(self::STAGE_GEOMETRY, 'display_none_at_every_viewport', $displayNoneAtEveryViewport),
            array(self::STAGE_GEOMETRY, 'not_hidden_at_any_viewport', static fn (): bool => ! in_array('none', array_map(static fn (string $value): string => strtolower(trim(preg_replace('/!\s*important\s*$/i', '', $value) ?? $value)), $declarations()['display'] ?? array()), true)),
            array(self::STAGE_RESTATEMENT, 'undistributable_declaration', static function () use ($declarations, $distributable): bool {
                foreach ( $declarations() as $property => $values ) {
                    if ( ! $distributable((string) $property, $values) ) {
                        return true;
                    }
                }
                return false;
            }),
            array(self::STAGE_RESTATEMENT, 'no_carrier_class', static function () use (&$className, $carrierClass): bool {
                $className = $carrierClass();
                return '' === $className;
            }),
        ), WrapperDisposition::fold(...));

        return null === $disposition
            ? array( 'disposition' => WrapperDisposition::fold('visibility_restated_per_item', self::STAGE_RESTATEMENT), 'className' => $className )
            : array( 'disposition' => $disposition, 'className' => '' );
    }

    /**
     * A neutral `div`/`span` beside a list-backed menu whose descendants are
     * all hidden from assistive technology and cannot be focused or navigate
     * is dropped as navigation support (`drop`); otherwise it is kept as
     * content. Formerly `NavigationPattern::isInertNavigationSupportWrapper()`.
     *
     * @param Closure(): bool $inertDescendants
     */
    public static function inertNavigationSupportWrapper(DOMElement $element, Closure $inertDescendants): WrapperDisposition
    {
        return self::firstMatch(array(
            array(self::STAGE_IDENTITY, 'not_div_or_span', fn (): bool => ! in_array(strtolower($element->tagName), array( 'div', 'span' ), true)),
            array(self::STAGE_IDENTITY, 'text_leaf', fn (): bool => '' !== trim($element->textContent ?? '') && 0 === $element->childElementCount),
        ), WrapperDisposition::preserve(...))
            ?? self::firstMatch(array(
                array(self::STAGE_IDENTITY, 'inert_support_descendants', $inertDescendants),
            ), WrapperDisposition::drop(...))
            ?? WrapperDisposition::preserve('navigable_or_named_descendant', self::STAGE_IDENTITY);
    }

    /**
     * A wrapper between a media/text section and its media: core/media-text
     * replaces it with its own figure (`fold`), so a wrapper whose box paints
     * or shapes the media is kept and the section is not recognized
     * (`preserve`). Formerly inlined in `MediaTextPattern::recognize()`.
     *
     * @param array<string, mixed> $declarations
     */
    public static function mediaTextMediaWrapper(array $declarations): WrapperDisposition
    {
        return self::firstMatch(array(
            array(self::STAGE_GEOMETRY, 'decorated_media_wrapper', static function () use ($declarations): bool {
                foreach ( array_keys($declarations) as $property ) {
                    if ( in_array($property, array( 'perspective', 'transform', 'filter', 'box-shadow', 'aspect-ratio', 'overflow', 'overflow-x', 'overflow-y' ), true)
                        || preg_match('/^(?:background|border|padding)(?:-|$)/', (string) $property) ) {
                        return true;
                    }
                }
                return false;
            }),
        ), WrapperDisposition::preserve(...))
            ?? WrapperDisposition::fold('media_wrapper_replaced_by_figure', self::STAGE_GEOMETRY);
    }

    /**
     * The converted text side's wrapper group inside core/media-text: hoisted
     * into the content pane (`fold`) when it carries nothing the block would
     * lose, otherwise kept (`preserve`). Formerly
     * `MediaTextPattern::isHoistableTextSideGroupAttrs()`.
     *
     * @param array<string, mixed> $attrs
     */
    public static function mediaTextTextSideGroup(array $attrs, DOMElement $textSide): WrapperDisposition
    {
        if ( array() === $attrs ) {
            return WrapperDisposition::fold('no_group_attributes', self::STAGE_RESTATEMENT);
        }

        return self::firstMatch(array(
            array(self::STAGE_RESTATEMENT, 'attributes_beyond_class_name', fn (): bool => array( 'className' ) !== array_keys($attrs) || ! is_string($attrs['className'])),
            array(self::STAGE_IDENTITY, 'authored_class', static function () use ($attrs, $textSide): bool {
                $sourceClasses = SourceDom::tokens(SourceDom::attr($textSide, 'class'));
                foreach ( SourceDom::tokens($attrs['className']) as $class ) {
                    if ( '' === $class ) {
                        continue;
                    }
                    if ( ! str_starts_with($class, 'blocks-engine-css-owned-') || in_array($class, $sourceClasses, true) ) {
                        return true;
                    }
                }
                return false;
            }),
        ), WrapperDisposition::preserve(...))
            ?? WrapperDisposition::fold('generated_layout_markers_only', self::STAGE_RESTATEMENT);
    }

    /**
     * The hamburger's own wrapper when a captured menu dialog folds into the
     * row's navigation: dropped with the hamburger (`drop`) when everything
     * else in it repeats a control the row already shows; otherwise kept
     * and only the hamburger goes. Formerly
     * `CapturedMenuDialogFold::redundantTriggerGroup()`; the ancestor walk
     * that finds the wrapper stays there.
     *
     * @param list<DOMElement> $triggers
     * @param Closure(): bool $unrepeatedControl
     * @param Closure(): bool $onlyPunctuationOutsideControls
     */
    public static function capturedMenuTriggerGroup(DOMElement $group, array $triggers, DOMElement $dialog, DOMElement $row, Closure $unrepeatedControl, Closure $onlyPunctuationOutsideControls): WrapperDisposition
    {
        return self::firstMatch(array(
            array(self::STAGE_RESTATEMENT, 'trigger_is_its_own_group', fn (): bool => $group->isSameNode($triggers[0])),
            array(self::STAGE_IDENTITY, 'group_holds_dialog', fn (): bool => SourceDom::elementContains($group, $dialog)),
            array(self::STAGE_IDENTITY, 'group_holds_row', fn (): bool => SourceDom::elementContains($group, $row)),
            array(self::STAGE_IDENTITY, 'trigger_outside_group', static function () use ($group, $triggers): bool {
                foreach ( $triggers as $other ) {
                    if ( ! SourceDom::elementContains($group, $other) ) {
                        return true;
                    }
                }
                return false;
            }),
            array(self::STAGE_IDENTITY, 'unrepeated_control', $unrepeatedControl),
            array(self::STAGE_IDENTITY, 'non_punctuation_text', fn (): bool => ! $onlyPunctuationOutsideControls()),
        ), WrapperDisposition::preserve(...))
            ?? WrapperDisposition::drop('trigger_group_repeats_row', self::STAGE_IDENTITY);
    }

    /**
     * A hidden capture close helper for a panel the native navigation
     * overlay replaces closes nothing (`drop`). Formerly inlined in
     * `NavigationToggleSuppressor::suppressCaptureCloseHelpers()`.
     */
    public static function captureCloseHelper(DOMNode $button, string $panelId, DOMElement $panel): WrapperDisposition
    {
        return self::firstMatch(array(
            array(self::STAGE_IDENTITY, 'not_close_helper_for_panel', fn (): bool => ! $button instanceof DOMElement || $panelId !== trim(SourceDom::attr($button, CaptureEvidence::DIALOG_CLOSE))),
            array(self::STAGE_GEOMETRY, 'not_hidden', fn (): bool => ! $button->hasAttribute('hidden')),
            array(self::STAGE_IDENTITY, 'inside_panel', fn (): bool => SourceDom::elementContains($panel, $button)),
        ), WrapperDisposition::preserve(...))
            ?? WrapperDisposition::drop('superseded_capture_close_helper', self::STAGE_IDENTITY);
    }

    /**
     * A second source navigation with the same destinations as the one a
     * menu control projects is dropped (`drop`). Formerly inlined in
     * `NavigationToggleSuppressor::suppressEquivalentNavigationDuplicates()`.
     *
     * @param Closure(): bool $alreadySuppressed
     * @param Closure(): bool $navigationTarget
     * @param Closure(): bool $sameSignature
     */
    public static function equivalentNavigationDuplicate(DOMNode $candidate, DOMElement $navigation, DOMElement $control, Closure $alreadySuppressed, Closure $navigationTarget, Closure $sameSignature): WrapperDisposition
    {
        return self::firstMatch(array(
            array(self::STAGE_IDENTITY, 'not_element', fn (): bool => ! $candidate instanceof DOMElement),
            array(self::STAGE_IDENTITY, 'projected_navigation', fn (): bool => $candidate->isSameNode($navigation)),
            array(self::STAGE_IDENTITY, 'already_suppressed', $alreadySuppressed),
            array(self::STAGE_IDENTITY, 'holds_menu_control', fn (): bool => SourceDom::elementContains($candidate, $control)),
            array(self::STAGE_IDENTITY, 'not_navigation_target', fn (): bool => ! $navigationTarget()),
            array(self::STAGE_IDENTITY, 'different_navigation_signature', fn (): bool => ! $sameSignature()),
        ), WrapperDisposition::preserve(...))
            ?? WrapperDisposition::drop('equivalent_navigation_duplicate', self::STAGE_IDENTITY);
    }

    /**
     * A source ancestor between a carousel root and its slide list: restated
     * as a stage wrapper around the generated viewport (`preserve`) when it
     * has a safe class, identity attributes or geometry; otherwise folded
     * into the carousel shell. Formerly inlined in
     * `AuthoredCarouselBlockGenerator::stageWrappers()`.
     *
     * @param array<string, string> $attributes
     */
    public static function carouselStageWrapper(string $className, array $attributes, string $inlineStyle): WrapperDisposition
    {
        return self::firstMatch(array(
            array(self::STAGE_RESTATEMENT, 'stage_class', fn (): bool => '' !== $className),
            array(self::STAGE_IDENTITY, 'stage_identity_attributes', fn (): bool => array() !== $attributes),
            array(self::STAGE_GEOMETRY, 'stage_geometry_style', fn (): bool => '' !== $inlineStyle),
        ), WrapperDisposition::preserve(...))
            ?? WrapperDisposition::fold('no_stage_identity_or_geometry', self::STAGE_RESTATEMENT);
    }

    /**
     * Which source attributes of a carousel stage ancestor are restated on
     * its stage wrapper: identity and data/aria attributes that are not
     * runtime controller state.
     */
    public static function carouselStageWrapperAttribute(string $name, string $value): bool
    {
        return (bool) preg_match('/^(?:id|tabindex|role|(?:data|aria)-[a-zA-Z0-9_-]+)$/D', $name)
            && ! str_starts_with($name, 'data-wp-')
            && ! preg_match('/(?:controller|current-context|current-styles|animation-state|controllers-bound)/i', $name)
            && strlen($value) <= 512;
    }

    /**
     * A retained custom-wrapper hook (kept in the source DOM only so author
     * selectors keyed on its data attributes match during selector
     * preparation): its sole child replaces it (`fold`) when the hook is a
     * safe, boxless transparent custom element; otherwise it keeps its
     * Group (`preserve`). Null when the element is not a retained hook.
     * Formerly `HtmlCompilation::boxlessWrapperHookChild()`.
     *
     * @param Closure(): bool $safeTransparentCustomElement
     * @param Closure(): bool $boxless
     * @return array{disposition: ?WrapperDisposition, child: ?DOMElement}
     */
    public static function retainedWrapperHook(DOMElement $element, bool $retainedHook, Closure $safeTransparentCustomElement, Closure $boxless): array
    {
        if ( ! $retainedHook ) {
            return array( 'disposition' => null, 'child' => null );
        }
        $child = null;
        $disposition = self::firstMatch(array(
            array(self::STAGE_RESTATEMENT, 'not_single_child_element', static function () use ($element, &$child): bool {
                foreach ( $element->childNodes as $node ) {
                    if ( $node instanceof DOMElement ) {
                        if ( null !== $child ) {
                            return true;
                        }
                        $child = $node;
                    } elseif ( $node instanceof \DOMText && '' !== trim($node->textContent) ) {
                        return true;
                    }
                }
                return null === $child;
            }),
            array(self::STAGE_IDENTITY, 'unsafe_transparent_custom_element', fn (): bool => ! $safeTransparentCustomElement()),
            array(self::STAGE_GEOMETRY, 'hook_has_box', fn (): bool => ! $boxless()),
        ), WrapperDisposition::preserve(...));

        return null === $disposition
            ? array( 'disposition' => WrapperDisposition::fold('boxless_retained_hook', self::STAGE_GEOMETRY), 'child' => $child )
            : array( 'disposition' => $disposition, 'child' => null );
    }

    /**
     * Guard names of the slice-6 tables (#7471): patterns, navigation
     * support, the carousel generator and retained wrapper hooks, in
     * evaluation order, keyed to their stage. Pinned by
     * `tests/unit/wrapper-decision-patterns.php`.
     *
     * @return array<string, array<string, string>>
     */
    public static function patternGuardStages(): array
    {
        return array(
            'button_label_wrapper' => array(
                'paints_background' => self::STAGE_GEOMETRY,
                'paints_border' => self::STAGE_GEOMETRY,
                'paints_box_shadow' => self::STAGE_GEOMETRY,
                'paints_outline' => self::STAGE_GEOMETRY,
                'paint_free_label_wrapper' => self::STAGE_GEOMETRY,
            ),
            'button_control_wrapper' => array(
                'button_role' => self::STAGE_IDENTITY,
                'wrapper_button_style_signal' => self::STAGE_GEOMETRY,
            ),
            'accessible_link_content_wrapper' => array(
                'not_single_child_element' => self::STAGE_RESTATEMENT,
                'not_span_or_div' => self::STAGE_RESTATEMENT,
                'no_attributes' => self::STAGE_IDENTITY,
                'non_content_attribute' => self::STAGE_IDENTITY,
                'restated_on_rich_text_element' => self::STAGE_RESTATEMENT,
            ),
            'button_label_span' => array(
                'no_wrapping_span' => self::STAGE_RESTATEMENT,
                'bare_span_not_restorable' => self::STAGE_IDENTITY,
                'retained_source_element' => self::STAGE_RESTATEMENT,
                'source_item_sequence' => self::STAGE_RESTATEMENT,
                'presentational_label_span' => self::STAGE_RESTATEMENT,
            ),
            'navigation_carrier_layout_wrapper' => array(
                'not_single_child_element' => self::STAGE_RESTATEMENT,
                'not_div' => self::STAGE_IDENTITY,
                'navigation_chrome' => self::STAGE_IDENTITY,
                'fewer_than_two_element_children' => self::STAGE_GEOMETRY,
                'carrier_layout_row_box' => self::STAGE_GEOMETRY,
            ),
            'flattened_navigation_wrapper' => array(
                'no_navigation_context' => self::STAGE_IDENTITY,
                'display_none_at_every_viewport' => self::STAGE_GEOMETRY,
                'not_hidden_at_any_viewport' => self::STAGE_GEOMETRY,
                'undistributable_declaration' => self::STAGE_RESTATEMENT,
                'no_carrier_class' => self::STAGE_RESTATEMENT,
                'visibility_restated_per_item' => self::STAGE_RESTATEMENT,
            ),
            'inert_navigation_support_wrapper' => array(
                'not_div_or_span' => self::STAGE_IDENTITY,
                'text_leaf' => self::STAGE_IDENTITY,
                'inert_support_descendants' => self::STAGE_IDENTITY,
                'navigable_or_named_descendant' => self::STAGE_IDENTITY,
            ),
            'media_text_media_wrapper' => array(
                'decorated_media_wrapper' => self::STAGE_GEOMETRY,
                'media_wrapper_replaced_by_figure' => self::STAGE_GEOMETRY,
            ),
            'media_text_text_side_group' => array(
                'no_group_attributes' => self::STAGE_RESTATEMENT,
                'attributes_beyond_class_name' => self::STAGE_RESTATEMENT,
                'authored_class' => self::STAGE_IDENTITY,
                'generated_layout_markers_only' => self::STAGE_RESTATEMENT,
            ),
            'captured_menu_trigger_group' => array(
                'trigger_is_its_own_group' => self::STAGE_RESTATEMENT,
                'group_holds_dialog' => self::STAGE_IDENTITY,
                'group_holds_row' => self::STAGE_IDENTITY,
                'trigger_outside_group' => self::STAGE_IDENTITY,
                'unrepeated_control' => self::STAGE_IDENTITY,
                'non_punctuation_text' => self::STAGE_IDENTITY,
                'trigger_group_repeats_row' => self::STAGE_IDENTITY,
            ),
            'capture_close_helper' => array(
                'not_close_helper_for_panel' => self::STAGE_IDENTITY,
                'not_hidden' => self::STAGE_GEOMETRY,
                'inside_panel' => self::STAGE_IDENTITY,
                'superseded_capture_close_helper' => self::STAGE_IDENTITY,
            ),
            'equivalent_navigation_duplicate' => array(
                'not_element' => self::STAGE_IDENTITY,
                'projected_navigation' => self::STAGE_IDENTITY,
                'already_suppressed' => self::STAGE_IDENTITY,
                'holds_menu_control' => self::STAGE_IDENTITY,
                'not_navigation_target' => self::STAGE_IDENTITY,
                'different_navigation_signature' => self::STAGE_IDENTITY,
                'equivalent_navigation_duplicate' => self::STAGE_IDENTITY,
            ),
            'carousel_stage_wrapper' => array(
                'stage_class' => self::STAGE_RESTATEMENT,
                'stage_identity_attributes' => self::STAGE_IDENTITY,
                'stage_geometry_style' => self::STAGE_GEOMETRY,
                'no_stage_identity_or_geometry' => self::STAGE_RESTATEMENT,
            ),
            'retained_wrapper_hook' => array(
                'not_single_child_element' => self::STAGE_RESTATEMENT,
                'unsafe_transparent_custom_element' => self::STAGE_IDENTITY,
                'hook_has_box' => self::STAGE_GEOMETRY,
                'boxless_retained_hook' => self::STAGE_GEOMETRY,
            ),
        );
    }
}

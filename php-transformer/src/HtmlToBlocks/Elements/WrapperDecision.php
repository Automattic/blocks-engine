<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMElement;

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
        foreach ( $guards as [$stage, $reason, $refuses] ) {
            if ( $refuses() ) {
                return WrapperDisposition::preserve($reason, $stage);
            }
        }
        return null;
    }
}

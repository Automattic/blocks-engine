<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatchCache;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\GeneratedBlockRegistry;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\LayoutShellBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMDocument;
use DOMElement;
use DOMNode;
use LogicException;

/**
 * Sole owner of wrapper coalescing: deciding whether a source `div` wrapper
 * around a single child block disappears in favor of that child
 * ({@see self::coalescedSingleGroupWrapper()}), whether a chain of such
 * wrappers folds into one generated layout-shell block
 * ({@see self::foldWrapperChain()}), and the structural bookkeeping both of
 * those depend on ({@see self::groupWrapperDescriptor()},
 * {@see self::selectorMatchingSurvivesWrapperCoalescing()}).
 *
 * `coalescedSingleGroupWrapper()`'s eligibility test used to be a single
 * multi-term boolean expression that returned a bare `null` on
 * disqualification, so nothing recorded *why* a wrapper survived. It is now
 * built from named, individually-inspectable disqualification reasons
 * ({@see self::coalescingDisposition()}) evaluated in the same order — and
 * with the same short-circuiting — as the original expression, so output is
 * unchanged: only the reason a decision was made became visible.
 *
 * Collaborators here are either genuinely stateless (`SourceDom` statics),
 * shared immutable services also used elsewhere in element conversion
 * (`StyleResolver`, `SourceElementClassifier`, `RuntimeIslandAnalyzer`,
 * `SourceBlockCreator`), the per-transform session (`HtmlTransformerSession`,
 * the same collaborator {@see HtmlTransformerSession} is injected into
 * several other `Elements/*` classes), or explicit constructor closures for
 * the handful of predicates that remain on `HtmlCompilation` because they are
 * themselves shared by call sites outside wrapper coalescing. This class
 * never holds a reference to `HtmlCompilation` itself.
 */
final class WrapperCoalescer
{
    /**
     * @param Closure(DOMElement): bool $isDirectChildOfStructuralLayout
     * @param Closure(DOMElement): array<string, mixed> $structureSignals
     * @param Closure(DOMElement): bool $hasOnlyRenderNeutralInlineGeometry
     * @param Closure(DOMElement): bool $hasOnlyFullWidthTransparentInlineGeometry
     * @param Closure(DOMElement): bool $hasOnlyFullWidthTransparentBoxAffectingDeclarations
     * @param Closure(DOMElement): bool $hasOnlyRenderNeutralBoxAffectingDeclarations
     * @param Closure(DOMElement): bool $isNormalFlowFullWidthShellChild
     * @param Closure(DOMElement): bool $hasContainingBlockDependentAuthorDeclarations
     * @param Closure(DOMElement, array<string, mixed>): bool $isRedundantNestedLayoutWrapper
     * @param Closure(DOMElement, string): ?DOMElement $sameSourceGroupChainLeaf
     * @param Closure(DOMElement): ?DOMElement $imageLeafInGroupChain
     * @param Closure(DOMElement): ?array<string, mixed> $layoutGeometryProofFor
     * @param Closure(array<string, mixed>): string $layoutGeometryProofCarrier
     * @param Closure(array<int, array<string, mixed>>): array<int, array<string, mixed>> $truncateWrappersAfterAuthoredGrid
     * @param Closure(array<string, string>): bool $hasOnlyRenderNeutralDeclarations
     */
    public function __construct(
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly RuntimeIslandAnalyzer $runtimeIslands,
        private readonly StyleResolver $styleResolver,
        private readonly SourceBlockCreator $createBlock,
        private readonly HtmlTransformerSession $session,
        private readonly Closure $isDirectChildOfStructuralLayout,
        private readonly Closure $structureSignals,
        private readonly Closure $hasOnlyRenderNeutralInlineGeometry,
        private readonly Closure $hasOnlyFullWidthTransparentInlineGeometry,
        private readonly Closure $hasOnlyFullWidthTransparentBoxAffectingDeclarations,
        private readonly Closure $hasOnlyRenderNeutralBoxAffectingDeclarations,
        private readonly Closure $isNormalFlowFullWidthShellChild,
        private readonly Closure $hasContainingBlockDependentAuthorDeclarations,
        private readonly Closure $isRedundantNestedLayoutWrapper,
        private readonly Closure $sameSourceGroupChainLeaf,
        private readonly Closure $imageLeafInGroupChain,
        private readonly Closure $layoutGeometryProofFor,
        private readonly Closure $layoutGeometryProofCarrier,
        private readonly Closure $truncateWrappersAfterAuthoredGrid,
        private readonly Closure $hasOnlyRenderNeutralDeclarations
    ) {
    }

    /**
     * @param array<int,array{block:array<string,mixed>,descriptor:array<string,mixed>}> $chain
     * @param array<string,mixed> $terminal
     * @return array<string,mixed>
     */
    public function foldWrapperChain(array $chain, array $terminal): array
    {
        $terminalIsShell = $this->sourceElementClassifier->isLayoutShellBlock($terminal);
        $terminalBlocks = $terminalIsShell ? $terminal['innerBlocks'] : (is_array($terminal['innerBlocks'] ?? null) && 'core/freeform' === ($terminal['blockName'] ?? null) ? $terminal['innerBlocks'] : array($terminal));
        $wrappers = array_column($chain, 'descriptor');
        if ($terminalIsShell) $wrappers = array_merge($wrappers, is_array($terminal['_layout_shell_wrappers'] ?? null) ? $terminal['_layout_shell_wrappers'] : array());
        if ( 2 <= count($terminalBlocks) ) {
            $wrappers = ($this->truncateWrappersAfterAuthoredGrid)($wrappers);
        }
        $opening = implode('', array_column($wrappers, 'opening'));
        $closing = implode('', array_reverse(array_column($wrappers, 'closing')));
        $provenanceIds = array_values(array_filter(array_map(static fn (array $entry): mixed => $entry['block']['_source_provenance_id'] ?? null, $chain), 'is_int'));
        if ($terminalIsShell) $provenanceIds = array_merge($provenanceIds, is_array($terminal['_source_provenance_ids'] ?? null) ? $terminal['_source_provenance_ids'] : array());
        $blockName = $this->generatedBlocks()->blockName('layout-shell');
        $this->generatedBlocks()->register(LayoutShellBlockGenerator::class, (new LayoutShellBlockGenerator())->definition($blockName));
        return array_filter(array(
            'blockName' => $blockName,
            'attrs' => array('wrappers' => array_map(static fn (array $wrapper): array => array('tagName' => $wrapper['tagName'], 'attributes' => $wrapper['attributes']), $wrappers)),
            'innerBlocks' => $terminalBlocks,
            'innerHTML' => $opening . $closing,
            'innerContent' => array_merge(array($opening), array_fill(0, count($terminalBlocks), null), array($closing)),
            '_source_provenance_ids' => $provenanceIds,
            '_layout_shell_wrappers' => $wrappers,
            '_editability_runtime_owned' => (bool) array_filter($chain, static fn (array $entry): bool => !empty($entry['block']['_editability_runtime_owned'])) || ($terminalIsShell && !empty($terminal['_editability_runtime_owned'])),
            '_editability_visual_owned' => (bool) array_filter($chain, static fn (array $entry): bool => !empty($entry['block']['_editability_visual_owned'])) || ($terminalIsShell && !empty($terminal['_editability_visual_owned'])),
        ), static fn (mixed $value): bool => false !== $value && array() !== $value);
    }

    /** @param array<string, mixed> $block @return array{tagName: string, attributes: array<string, string>, opening: string, closing: string}|null */
    public function groupWrapperDescriptor(array $block): ?array
    {
        $content = is_array($block['innerContent'] ?? null) ? $block['innerContent'] : array();
        $opening = is_string($content[0] ?? null) ? $content[0] : '';
        $closing = is_string($content[array_key_last($content)] ?? null) ? $content[array_key_last($content)] : '';
        $children = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array();
        if (count($content) !== count($children) + 2 || array_slice($content, 1, -1) !== array_fill(0, count($children), null)) {
            return null;
        }
        if (! preg_match('/^<([a-z][a-z0-9-]*)\b/i', $opening, $match) || '' === $closing) {
            return null;
        }
        $tagName = strtolower($match[1]);
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $opening . $closing . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $element = $loaded ? $document->getElementsByTagName($tagName)->item(0) : null;
        if (! $element instanceof DOMElement) {
            return null;
        }
        $attributes = array();
        foreach ($element->attributes ?? array() as $attribute) {
            $attributes[strtolower($attribute->nodeName)] = (string) $attribute->nodeValue;
        }
        if (!$this->sourceElementClassifier->isLayoutShellSerializableStyle((string) ($attributes['style'] ?? ''))) {
            return null;
        }
        return array('tagName' => $tagName, 'attributes' => $attributes, 'opening' => $opening, 'closing' => $closing);
    }

    /**
     * The disposition {@see coalescedSingleGroupWrapper()} would act on,
     * without building the replacement block. Exposed so each named
     * disqualification reason is directly inspectable and testable on its
     * own, instead of only observable indirectly through a null/array
     * return.
     *
     * @param array<string, mixed> $childBlock
     */
    public function coalescingDisposition(DOMElement $element, array $childBlock): WrapperDisposition
    {
        return $this->coalescingResolution($element, $childBlock)['disposition'];
    }

    /** @param array<string, mixed> $childBlock @return array<string, mixed>|null */
    public function coalescedSingleGroupWrapper(DOMElement $element, array $childBlock): ?array
    {
        $resolution = $this->coalescingResolution($element, $childBlock);
        if ( ! $resolution['disposition']->isCoalesce() ) {
            return null;
        }
        $proof = $resolution['proof'];
        $attrs = $resolution['attrs'];
        $sourceChild = $resolution['source_child'];

        $childAttrs = is_array($childBlock['attrs'] ?? null) ? $childBlock['attrs'] : array();
        $childAttrs['className'] = null === $proof
            ? SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), (string) ($childAttrs['className'] ?? ''), ...SourceDom::classNames($element))
            : SourceDom::mergeClassNames((string) ($childAttrs['className'] ?? ''), ($this->layoutGeometryProofCarrier)($proof));
        $childAttrs = array_filter($childAttrs, static fn (mixed $value): bool => ! is_string($value) || '' !== trim($value));
        if (null !== $proof) $this->session->layoutGeometryState()->recordProof($proof);

        return $this->createBlock->createBlock((string) $childBlock['blockName'], $childAttrs, $childBlock['innerBlocks'] ?? array(), $sourceChild);
    }

    /**
     * Runs the same three disqualification phases, in the same
     * short-circuiting order, that the original single boolean expression
     * evaluated — so a disqualification reason here changes nothing about
     * which wrappers coalesce, only whether the decision is named.
     *
     * @param array<string, mixed> $childBlock
     * @return array{disposition: WrapperDisposition, proof: ?array<string, mixed>, attrs: array<string, mixed>, source_child: ?DOMElement}
     */
    private function coalescingResolution(DOMElement $element, array $childBlock): array
    {
        $proof = ($this->layoutGeometryProofFor)($element);
        $fullWidthTransparentShell = ($this->hasOnlyFullWidthTransparentInlineGeometry)($element);
        $redundantNestedLayout = ($this->isRedundantNestedLayoutWrapper)($element, $childBlock);

        $reason = $this->firstDisqualification($this->eligibilityDisqualifications($element, $childBlock, $proof, $fullWidthTransparentShell, $redundantNestedLayout));
        if (null !== $reason) {
            return array('disposition' => WrapperDisposition::preserve($reason), 'proof' => $proof, 'attrs' => array(), 'source_child' => null);
        }

        $attrs = $this->styleResolver->presentationAttributes($element);
        $reason = $this->firstDisqualification($this->presentationAttributeDisqualifications($attrs, $redundantNestedLayout));
        if (null !== $reason) {
            return array('disposition' => WrapperDisposition::preserve($reason), 'proof' => $proof, 'attrs' => $attrs, 'source_child' => null);
        }

        $sourceChild = $this->matchingSourceChild($element, $childBlock);
        $reason = $this->firstDisqualification($this->sourceChildDisqualifications($element, $childBlock, $sourceChild, $proof, $fullWidthTransparentShell, $redundantNestedLayout));
        if (null !== $reason) {
            return array('disposition' => WrapperDisposition::preserve($reason), 'proof' => $proof, 'attrs' => $attrs, 'source_child' => $sourceChild);
        }

        return array('disposition' => WrapperDisposition::coalesce('single_child_wrapper_absorbed'), 'proof' => $proof, 'attrs' => $attrs, 'source_child' => $sourceChild);
    }

    /**
     * The original gate: is this even the shape wrapper coalescing applies
     * to (a plain `div` around a single supported child, with no id/role/
     * interactivity/data/structure signal of its own — unless a layout-
     * geometry proof or a redundant-nested-layout finding already accounts
     * for it)? Each disjunct of the original boolean expression is named
     * here in the same order, so the first one that matches is the reason
     * the wrapper is preserved.
     *
     * @param array<string, mixed> $childBlock
     * @param array<string, mixed>|null $proof
     * @return array<int, array{0: string, 1: Closure(): bool}>
     */
    private function eligibilityDisqualifications(DOMElement $element, array $childBlock, ?array $proof, bool $fullWidthTransparentShell, bool $redundantNestedLayout): array
    {
        return array(
            array('not_a_div_element', fn (): bool => 'div' !== strtolower($element->tagName)),
            array('unsupported_child_block_name', fn (): bool => ! in_array($childBlock['blockName'] ?? null, array( 'core/group', 'core/image' ), true)),
            array('full_width_shell_requires_group_child', fn (): bool => $fullWidthTransparentShell && 'core/group' !== ($childBlock['blockName'] ?? null)),
            array('runtime_dom_target', fn (): bool => $this->runtimeIslands->isRuntimeDomTarget($element)),
            array('structural_layout_child_without_proof', fn (): bool => null === $proof && ($this->isDirectChildOfStructuralLayout)($element)),
            array('has_id_attribute', fn (): bool => '' !== trim(SourceDom::attr($element, 'id'))),
            array('has_role_attribute', fn (): bool => '' !== trim(SourceDom::attr($element, 'role'))),
            array('non_neutral_geometry_without_proof', fn (): bool => null === $proof && ! $fullWidthTransparentShell && ! ($this->hasOnlyRenderNeutralInlineGeometry)($element) && ! $redundantNestedLayout),
            array('has_interactive_attributes', fn (): bool => array() !== $this->interactiveAttributes($element)),
            array('has_data_attributes_without_proof', fn (): bool => null === $proof && array() !== $this->safeDataAttributes($element)),
            array('has_structure_signals_without_proof', fn (): bool => null === $proof && array() !== ($this->structureSignals)($element) && ! $redundantNestedLayout),
            array('has_motion_structure_token', fn (): bool => $this->sourceElementClassifier->hasMotionStructureToken($element)),
        );
    }

    /**
     * @param array<string, mixed> $attrs
     * @return array<int, array{0: string, 1: Closure(): bool}>
     */
    private function presentationAttributeDisqualifications(array $attrs, bool $redundantNestedLayout): array
    {
        return array(
            array('unsupported_presentation_attributes', fn (): bool => ! $redundantNestedLayout && array() !== array_diff(array_keys($attrs), array( 'className', 'style' ))),
        );
    }

    /**
     * Once eligibility holds, the wrapper still only coalesces if a single
     * source child actually carries the child block's identity forward
     * cleanly: it exists, its tag matches what the child block expects, it
     * carries no motion token of its own, its box-affecting declarations are
     * neutral (or already proven/redundant), and — the expensive check,
     * evaluated last exactly as before — removing the wrapper would not
     * change which author selectors match.
     *
     * @param array<string, mixed> $childBlock
     * @param array<string, mixed>|null $proof
     * @return array<int, array{0: string, 1: Closure(): bool}>
     */
    private function sourceChildDisqualifications(DOMElement $element, array $childBlock, ?DOMElement $sourceChild, ?array $proof, bool $fullWidthTransparentShell, bool $redundantNestedLayout): array
    {
        return array(
            array('missing_matching_source_child', fn (): bool => ! $sourceChild instanceof DOMElement),
            array('image_source_child_type_mismatch', fn (): bool => 'core/image' === ($childBlock['blockName'] ?? null) && ! in_array(strtolower($sourceChild->tagName), array( 'img', 'svg' ), true) && ! str_contains($sourceChild->tagName, '-')),
            array('source_child_has_motion_structure_token', fn (): bool => $this->sourceElementClassifier->hasMotionStructureToken($sourceChild)),
            array('box_affecting_declarations_not_neutral', fn (): bool => null === $proof && ! $redundantNestedLayout && ($fullWidthTransparentShell ? ! ($this->hasOnlyFullWidthTransparentBoxAffectingDeclarations)($element) : ! ($this->hasOnlyRenderNeutralBoxAffectingDeclarations)($element))),
            array('full_width_shell_child_not_normal_flow', fn (): bool => null === $proof && $fullWidthTransparentShell && ! ($this->isNormalFlowFullWidthShellChild)($sourceChild)),
            array('containing_block_dependent_declarations', fn (): bool => 'core/image' !== ($childBlock['blockName'] ?? null) && ! $redundantNestedLayout && ($this->hasContainingBlockDependentAuthorDeclarations)($sourceChild)),
            array('selector_matching_does_not_survive_coalescing', fn (): bool => null === $proof && ! $this->syntheticImageGeometryLeaf($childBlock) && ! $this->selectorMatchingSurvivesWrapperCoalescing($element, $sourceChild, $fullWidthTransparentShell)),
        );
    }

    /** @param array<int, array{0: string, 1: Closure(): bool}> $checks */
    private function firstDisqualification(array $checks): ?string
    {
        foreach ( $checks as [$reason, $isDisqualified] ) {
            if ( $isDisqualified() ) {
                return $reason;
            }
        }
        return null;
    }

    /** @param array<string, mixed> $childBlock */
    private function matchingSourceChild(DOMElement $element, array $childBlock): ?DOMElement
    {
        $provenanceId = $childBlock['_source_provenance_id'] ?? null;
        $sourceChild = is_int($provenanceId)
            ? ($this->sameSourceGroupChainLeaf)($element, (string) ($this->session->transformationProvenanceState()->source($provenanceId)['source_digest'] ?? ''))
            : null;
        if ( ! $sourceChild instanceof DOMElement && 'core/image' === ($childBlock['blockName'] ?? null) ) {
            $sourceChild = ($this->imageLeafInGroupChain)($element);
        }
        return $sourceChild;
    }

    /** @param array<string, mixed> $block */
    private function syntheticImageGeometryLeaf(array $block): bool
    {
        $className = (string) ($block['attrs']['className'] ?? '');
        return 'core/image' === ($block['blockName'] ?? null)
            && str_contains($className, SourceBlockAttributeProjector::SYNTHETIC_IMAGE_FIGURE_CLASS)
            && (bool) preg_match('/(?:^|\s)be-inline-geometry-[a-f0-9-]+(?:\s|$)/', $className);
    }

    public function selectorMatchingSurvivesWrapperCoalescing(DOMElement $element, DOMElement $child, bool $exact = false): bool
    {
        $parent = $element->parentNode;
        if ( ! $parent instanceof DOMElement ) {
            return false;
        }

        $chain = array();
        for ( $node = $child; $node instanceof DOMElement; $node = $node->parentNode instanceof DOMElement ? $node->parentNode : null ) {
            $chain[] = $node;
            if ( $node === $element ) {
                break;
            }
        }
        if ( $element !== end($chain) ) {
            return false;
        }

        $beforeCandidatesByKey = array();
        foreach ( $chain as $node ) {
            foreach ( $this->authorStyleRuleCandidates($node) as $selector ) {
                $beforeCandidatesByKey[$selector['key']] = $selector;
            }
        }
        $beforeCandidates = array_values($beforeCandidatesByKey);
        $matchesBefore = array();
        foreach ( $beforeCandidates as $selector ) {
            $matchesBefore[$selector['key']] = $selector['parsed']['supported'] && (bool) array_filter(
                $chain,
                fn (DOMElement $node): bool => $this->session->sourceStyleResolutionState()->selectorMatchCache->matches($node, $selector['selector'], $selector['parsed'], true)['matches']
            );
        }

        $childClass = SourceDom::attr($child, 'class');
        $chainClasses = array_map(fn (DOMElement $node): string => SourceDom::attr($node, 'class'), $chain);
        $childParent = $child->parentNode;
        $childNextSibling = $child->nextSibling;
        $parent->insertBefore($child, $element);
        $parent->removeChild($element);
        $child->setAttribute('class', SourceDom::mergeClassNames(...$chainClasses));

        $survives = true;
        $temporarySelectorCache = new CssSelectorMatchCache();
        $afterCandidates = $this->authorStyleRuleCandidates($child, $temporarySelectorCache);
        $candidates = array();
        foreach ( array_merge($beforeCandidates, $afterCandidates) as $selector ) {
            $candidates[$selector['key']] = $selector;
        }
        foreach ( $candidates as $key => $selector ) {
            $matchesAfter = $selector['parsed']['supported']
                && $temporarySelectorCache->matches($child, $selector['selector'], $selector['parsed'], true)['matches'];
            if ( ($matchesBefore[$key] ?? false) !== $matchesAfter && ($exact || ! ($this->hasOnlyRenderNeutralDeclarations)($selector['declarations'])) ) {
                $survives = false;
                break;
            }
        }

        $parent->insertBefore($element, $child);
        $parent->removeChild($child);
        if ( $childParent instanceof DOMNode ) {
            $childParent->insertBefore($child, $childNextSibling);
        }
        if ( '' === $childClass ) {
            $child->removeAttribute('class');
        } else {
            $child->setAttribute('class', $childClass);
        }
        return $survives;
    }

    /** @return list<array{key: string, selector: string, parsed: array<string, mixed>, direct_child_parsed: array<string, mixed>, declarations: array<string, string>, rule_order: int}> */
    private function authorStyleRuleCandidates(DOMElement $element, ?CssSelectorMatchCache $selectorCache = null): array
    {
        $index = $this->session->authorStyleAnalysis()->styleRuleCandidateIndex();
        $selectorCache ??= $this->session->sourceStyleResolutionState()->selectorMatchCache;
        return $selectorCache->styleRuleCandidates($element, 'author-rules', $index);
    }

    /**
     * These two are pure `SourceDom` reads with no HtmlCompilation coupling,
     * so — unlike the predicates above that stay behind closures because
     * HtmlCompilation itself shares them widely — they are safe to duplicate
     * verbatim rather than inject.
     *
     * @return array<string, bool|string>
     */
    private function interactiveAttributes(DOMElement $element): array
    {
        return array_filter(array(
            'tabindex'      => SourceDom::attr($element, 'tabindex'),
            'aria-expanded' => SourceDom::attr($element, 'aria-expanded'),
            'aria-controls' => SourceDom::attr($element, 'aria-controls'),
            'has_events'    => array() !== SourceDom::eventMetadata($element),
        ), static fn (mixed $value): bool => false !== $value && '' !== $value);
    }

    /** @return array<string, string> */
    private function safeDataAttributes(DOMElement $element): array
    {
        $data = array();
        foreach ( SourceDom::htmlAttributes($element) as $name => $value ) {
            if ( preg_match('/^data-[a-z0-9_-]+$/i', $name) && strlen($value) <= 300 && ! preg_match('/javascript\s*:/i', $value) ) {
                $data[$name] = $value;
            }
        }
        return $data;
    }

    private function generatedBlocks(): GeneratedBlockRegistry
    {
        return $this->session->generatedBlockRegistry()
            ?? throw new LogicException('Generated block registry has not been prepared for this transform.');
    }
}

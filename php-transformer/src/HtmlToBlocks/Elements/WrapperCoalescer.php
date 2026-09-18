<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatchCache;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\GeneratedBlockRegistry;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\LayoutShellBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
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
 * Every geometry/layout predicate the disqualification checks need —
 * `isDirectChildOfStructuralLayout()`, `hasOnlyRenderNeutralInlineGeometry()`,
 * `hasOnlyFullWidthTransparentInlineGeometry()`,
 * `hasOnlyFullWidthTransparentBoxAffectingDeclarations()`,
 * `hasOnlyRenderNeutralBoxAffectingDeclarations()`,
 * `isNormalFlowFullWidthShellChild()`,
 * `hasContainingBlockDependentAuthorDeclarations()`,
 * `isRedundantNestedLayoutWrapper()`, plus the chain-walking helpers
 * `imageLeafInGroupChain()`/`sameSourceGroupChainLeaf()` and the layout-proof
 * bookkeeping `layoutGeometryProofFor()`/`layoutGeometryProofCarrier()` —
 * lives here now, computed from `SourceDom`, the injected `StyleResolver`,
 * and `SourceElementClassifier` this class already holds. None of them read
 * `HtmlCompilation` state; `isDirectChildOfStructuralLayout()`,
 * `hasOnlyRenderNeutralInlineGeometry()`,
 * `hasOnlyRenderNeutralBoxAffectingDeclarations()`, `matchingAuthorDeclarations()`,
 * `layoutGeometryProofFor()`, and `layoutGeometryProofCarrier()` are `public`
 * because `HtmlCompilation` itself now calls back into this class for the
 * handful of unrelated features (empty-shell detection, inline-alignment
 * detection, standalone-inline-leaf detection, ...) that also need them,
 * instead of keeping a second copy.
 *
 * Three closures remain, each because the primitive it wraps is genuinely
 * shared by call sites with no wrapper-coalescing relationship at all, so
 * moving *those* primitives here — rather than duplicating them — would
 * recreate the coupling this refactor removes:
 *
 *  - `$structureSignals`: commerce/name-price-row/card-count heuristics
 *    (`commercePattern`, `cardLikeChildCount()`, `looksLikeNamePriceRow()`)
 *    that only exist on `HtmlCompilation` and are used by several unrelated
 *    conversions (`shouldPreserveWrapper()`, `shouldPreserveEmptyVisualElement()`,
 *    provenance recording, ...).
 *  - `$soleElementChild`: DOM traversal that skips inert/hidden empty
 *    children; its own dependency chain (`isInertHiddenEmptyElement()` /
 *    `sourceElementStartsHidden()`) is called from a dozen unrelated sites
 *    across `HtmlCompilation` and is also injected into
 *    `InertScaffoldingSuppressor`.
 *  - `$isImageOnlyAnchor`: image-anchor classification reused across image
 *    conversion (`imageBlockFromAnchor()`, image-in-anchor promotion, ...)
 *    well outside wrapper coalescing.
 *
 * The fallback-HTML serializer used to be a fourth closure here
 * (`$safeFallbackHtml`), but it was only ever a `HtmlCompilation` instance
 * method forwarding to the stateless {@see SourceDom::safeFallbackHtml()}.
 * This class already holds `$session`, which is where that state (projected
 * author tag markers) actually lives, so {@see self::sameSourceGroupChainLeaf()}
 * calls `SourceDom::safeFallbackHtml()` directly instead.
 *
 * This class never holds a reference to `HtmlCompilation` itself.
 */
final class WrapperCoalescer
{
    /**
     * @param Closure(DOMElement): array<string, mixed> $structureSignals
     * @param Closure(DOMElement): ?DOMElement $soleElementChild
     * @param Closure(DOMElement): bool $isImageOnlyAnchor
     */
    public function __construct(
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly RuntimeIslandAnalyzer $runtimeIslands,
        private readonly StyleResolver $styleResolver,
        private readonly SourceBlockCreator $createBlock,
        private readonly HtmlTransformerSession $session,
        private readonly Closure $structureSignals,
        private readonly Closure $soleElementChild,
        private readonly Closure $isImageOnlyAnchor
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
            $wrappers = $this->truncateWrappersAfterAuthoredGrid($wrappers);
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
        foreach ( $element->attributes ?? array() as $attribute ) {
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
            : SourceDom::mergeClassNames((string) ($childAttrs['className'] ?? ''), $this->layoutGeometryProofCarrier($proof));
        $transferredTag = $this->transferableGroupTag(strtolower($element->tagName));
        if ( null !== $transferredTag && 'core/group' === ($childBlock['blockName'] ?? null) && ! isset($childAttrs['tagName']) ) {
            $childAttrs['tagName'] = $transferredTag;
        }
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
        $proof = $this->layoutGeometryProofFor($element);
        $fullWidthTransparentShell = $this->hasOnlyFullWidthTransparentInlineGeometry($element);
        $redundantNestedLayout = $this->isRedundantNestedLayoutWrapper($element, $childBlock);

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
     * to (a representable wrapper around a single supported child, with no
     * id/role/interactivity/data/structure signal of its own — unless a
     * layout-geometry proof or a redundant-nested-layout finding already
     * accounts for it)? Each disjunct of the original boolean expression is
     * named here in the same order, so the first one that matches is the
     * reason the wrapper is preserved.
     *
     * @param array<string, mixed> $childBlock
     * @param array<string, mixed>|null $proof
     * @return array<int, array{0: string, 1: Closure(): bool}>
     */
    private function eligibilityDisqualifications(DOMElement $element, array $childBlock, ?array $proof, bool $fullWidthTransparentShell, bool $redundantNestedLayout): array
    {
        return array(
            array('unrepresentable_wrapper_tag', fn (): bool => ! $this->wrapperTagIsRepresentableOnChild($element, $childBlock)),
            array('unsupported_child_block_name', fn (): bool => ! in_array($childBlock['blockName'] ?? null, array( 'core/group', 'core/image' ), true)),
            array('full_width_shell_requires_group_child', fn (): bool => $fullWidthTransparentShell && 'core/group' !== ($childBlock['blockName'] ?? null)),
            array('runtime_dom_target', fn (): bool => $this->runtimeIslands->isRuntimeDomTarget($element)),
            array('structural_layout_child_without_proof', fn (): bool => null === $proof && $this->isDirectChildOfStructuralLayout($element) && ! $redundantNestedLayout),
            array('has_id_attribute', fn (): bool => '' !== trim(SourceDom::attr($element, 'id'))),
            array('has_role_attribute', fn (): bool => '' !== trim(SourceDom::attr($element, 'role'))),
            array('non_neutral_geometry_without_proof', fn (): bool => null === $proof && ! $fullWidthTransparentShell && ! $this->hasOnlyRenderNeutralInlineGeometry($element) && ! $redundantNestedLayout),
            array('has_interactive_attributes', fn (): bool => array() !== $this->interactiveAttributes($element)),
            array('has_data_attributes_without_proof', fn (): bool => null === $proof && array() !== $this->safeDataAttributes($element)),
            array('has_structure_signals_without_proof', fn (): bool => null === $proof && $this->hasBlockingStructureSignals($element, $childBlock) && ! $redundantNestedLayout),
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
            array('box_affecting_declarations_not_neutral', fn (): bool => null === $proof && ! $redundantNestedLayout && ($fullWidthTransparentShell ? ! $this->hasOnlyFullWidthTransparentBoxAffectingDeclarations($element) : ! $this->hasOnlyRenderNeutralBoxAffectingDeclarations($element))),
            array('full_width_shell_child_not_normal_flow', fn (): bool => null === $proof && $fullWidthTransparentShell && ! $this->isNormalFlowFullWidthShellChild($sourceChild)),
            array('containing_block_dependent_declarations', fn (): bool => 'core/image' !== ($childBlock['blockName'] ?? null) && ! $redundantNestedLayout && $this->hasContainingBlockDependentAuthorDeclarations($sourceChild)),
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
            ? $this->sameSourceGroupChainLeaf($element, (string) ($this->session->transformationProvenanceState()->source($provenanceId)['source_digest'] ?? ''))
            : null;
        if ( ! $sourceChild instanceof DOMElement && 'core/image' === ($childBlock['blockName'] ?? null) ) {
            $sourceChild = $this->imageLeafInGroupChain($element);
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
        $mergedClass = SourceDom::mergeClassNames(...$chainClasses);
        $childParent = $child->parentNode;
        $childNextSibling = $child->nextSibling;
        $promotedTag = $this->transferableGroupTag(strtolower($element->tagName));
        $standIn = null;
        $matchNode = $child;
        if ( null !== $promotedTag && $promotedTag !== strtolower($child->tagName) && $child->ownerDocument instanceof DOMDocument ) {
            $standIn = $child->ownerDocument->createElement($promotedTag);
            foreach ( $child->attributes ?? array() as $attribute ) {
                $standIn->setAttribute($attribute->nodeName, (string) $attribute->nodeValue);
            }
            $standIn->setAttribute('class', $mergedClass);
            while ( null !== $child->firstChild ) {
                $standIn->appendChild($child->firstChild);
            }
            $parent->insertBefore($standIn, $element);
            $parent->removeChild($element);
            $matchNode = $standIn;
        } else {
            $parent->insertBefore($child, $element);
            $parent->removeChild($element);
            $child->setAttribute('class', $mergedClass);
        }

        $survives = true;
        $temporarySelectorCache = new CssSelectorMatchCache();
        $afterCandidates = $this->authorStyleRuleCandidates($matchNode, $temporarySelectorCache);
        $candidates = array();
        foreach ( array_merge($beforeCandidates, $afterCandidates) as $selector ) {
            $candidates[$selector['key']] = $selector;
        }
        foreach ( $candidates as $key => $selector ) {
            $matchesAfter = $selector['parsed']['supported']
                && $temporarySelectorCache->matches($matchNode, $selector['selector'], $selector['parsed'], true)['matches'];
            if ( ($matchesBefore[$key] ?? false) !== $matchesAfter && ($exact || ! $this->hasOnlyRenderNeutralDeclarations($selector['declarations'])) ) {
                $survives = false;
                break;
            }
        }

        if ( $standIn instanceof DOMElement ) {
            $parent->insertBefore($element, $standIn);
            while ( null !== $standIn->firstChild ) {
                $child->appendChild($standIn->firstChild);
            }
            if ( $child->parentNode !== $element ) {
                $element->appendChild($child);
            }
            $parent->removeChild($standIn);
        } else {
            $parent->insertBefore($element, $child);
            $parent->removeChild($child);
            if ( $childParent instanceof DOMNode ) {
                $childParent->insertBefore($child, $childNextSibling);
            }
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
     * A source `div` establishing flex/grid is structural layout; a direct
     * child of one loses its own eligibility for coalescing (its geometry is
     * governed by the parent's layout, not its own declarations) unless a
     * layout-geometry proof already accounts for that.
     *
     * `HtmlCompilation` calls this directly for unrelated features
     * (standalone-inline-leaf detection, the neutral-group-chain-wrapper
     * check inside {@see sameSourceGroupChainLeaf()}, empty-interactive-shell
     * detection) instead of keeping a second copy.
     */
    public function isDirectChildOfStructuralLayout(DOMElement $element): bool
    {
        return $element->parentNode instanceof DOMElement && $this->isStructuralLayoutElement($element->parentNode);
    }

    public function isStructuralLayoutElement(DOMElement $element): bool
    {
        $declarations = array_merge($this->styleResolver->presentationDeclarations($element), $this->authorSemanticDeclarations($element));
        return in_array(strtolower(trim((string) ($declarations['display'] ?? ''))), array( 'flex', 'inline-flex', 'grid', 'inline-grid' ), true);
    }

    /** @return array<string, string> */
    private function authorSemanticDeclarations(DOMElement $element): array
    {
        $declarations = array();
        foreach ( $this->styleResolver->styleRuleCandidates($element, 'static') as $rule ) {
            if ( $this->styleResolver->matchesCssSelector($element, $rule['selector']) ) {
                $declarations = array_merge($declarations, $rule['declarations']);
            }
        }
        return $declarations;
    }

    public function hasOnlyRenderNeutralInlineGeometry(DOMElement $element): bool
    {
        foreach ($this->styleResolver->cssDeclarations(SourceDom::attr($element, 'style')) as $property => $value) {
            if (! $this->isRenderNeutralGeometryDeclaration($property, $value)) return false;
        }
        return true;
    }

    private function hasOnlyFullWidthTransparentInlineGeometry(DOMElement $element): bool
    {
        $declarations = $this->styleResolver->cssDeclarations(SourceDom::attr($element, 'style'));
        if ( '100%' !== strtolower(trim(CssValueInspector::withoutImportant((string) ($declarations['width'] ?? '')))) ) {
            return false;
        }
        unset($declarations['width']);
        foreach ($declarations as $property => $value) {
            if (! $this->isRenderNeutralGeometryDeclaration($property, $value)) {
                return false;
            }
        }
        return true;
    }

    private function hasOnlyFullWidthTransparentBoxAffectingDeclarations(DOMElement $element): bool
    {
        $declarations = $this->matchingAuthorDeclarations($element);
        if ( '100%' !== strtolower(trim(CssValueInspector::withoutImportant((string) ($declarations['width'] ?? '')))) ) {
            return false;
        }
        unset($declarations['width']);
        return $this->hasOnlyRenderNeutralBoxAffectingDeclarationMap($declarations);
    }

    private function isNormalFlowFullWidthShellChild(DOMElement $element): bool
    {
        if ( $this->hasContainingBlockDependentAuthorDeclarations($element) ) {
            return false;
        }
        $declarations = $this->styleResolver->presentationDeclarations($element);
        return ! isset($declarations['width'])
            && ! isset($declarations['min-width'])
            && ! isset($declarations['max-width']);
    }

    public function hasOnlyRenderNeutralBoxAffectingDeclarations(DOMElement $element): bool
    {
        return $this->hasOnlyRenderNeutralBoxAffectingDeclarationMap($this->matchingAuthorDeclarations($element));
    }

    /** @param array<string,string> $declarations */
    private function hasOnlyRenderNeutralBoxAffectingDeclarationMap(array $declarations): bool
    {
        foreach ($declarations as $property => $value) {
            if (! preg_match('/^(?:align-content|align-items|align-self|background|border|bottom|column|contain|display|filter|flex|float|gap|grid|height|inset|isolation|left|margin|max-|min-|opacity|outline|overflow|padding|perspective|position|right|row-gap|top|transform|width|z-index)/', $property)) continue;
            if (! $this->isRenderNeutralGeometryDeclaration($property, $value)) return false;
        }
        return true;
    }

    private function isRenderNeutralGeometryDeclaration(string $property, string $value): bool
    {
        $value = strtolower(trim(CssValueInspector::withoutImportant($value)));
        if (preg_match('/^(?:margin|padding)(?:-(?:top|right|bottom|left))?$/', $property)) return in_array($value, array('0', '0px', '0em', '0rem', '0%'), true);
        if (str_starts_with($property, 'border') || 'outline' === $property) return in_array($value, array('0', '0 none', 'none'), true);
        return 'text-align' === $property && 'left' === $value;
    }

    /** @param array<string,string> $declarations */
    private function hasOnlyRenderNeutralDeclarations(array $declarations): bool
    {
        foreach ($declarations as $property => $value) if (! $this->isRenderNeutralGeometryDeclaration($property, $value)) return false;
        return array() !== $declarations;
    }

    private function hasContainingBlockDependentAuthorDeclarations(DOMElement $element): bool
    {
        $declarations = $this->matchingAuthorDeclarations($element);
        foreach ( array_keys($declarations) as $property ) {
            if ( preg_match('/^(?:align-self|bottom|flex|float|grid-column|grid-row|height|inset|left|margin|max-height|max-width|min-height|min-width|order|position|right|top|transform|width)$/', $property) ) {
                return true;
            }
        }
        $display = strtolower(trim((string) ($declarations['display'] ?? '')));
        return '' !== $display && ! in_array($display, array( 'block', 'flow-root' ), true);
    }

    /**
     * The resting-cascade declarations that actually apply to `$element`:
     * its own inline style plus whichever matched author rules win, merged
     * in specificity/document order. `HtmlCompilation` calls this directly
     * for the unrelated `hasAuthorInlineAlignment()` check instead of
     * keeping a second copy.
     *
     * @return array<string, string>
     */
    public function matchingAuthorDeclarations(DOMElement $element): array
    {
        $declarations = $this->styleResolver->presentationDeclarations($element);
        $matchedRules = array();
        foreach ( $this->authorStyleRuleCandidates($element) as $selector ) {
            $ruleOrder = $selector['rule_order'];
            if ( isset($matchedRules[$ruleOrder]) || ! $selector['parsed']['supported'] ) {
                continue;
            }
            if ( $this->session->sourceStyleResolutionState()->selectorMatchCache->matches($element, $selector['selector'], $selector['parsed'], true)['matches'] ) {
                $matchedRules[$ruleOrder] = true;
                $declarations = $this->styleResolver->mergeCssDeclarationMaps($declarations, $selector['declarations']);
            }
        }
        return $declarations;
    }

    /**
     * A sole nested flex/grid wrapper is redundant when it only restates display
     * and the child group already carries its own geometry carrier.
     *
     * @param array<string, mixed> $childBlock
     */
    private function isRedundantNestedLayoutWrapper(DOMElement $element, array $childBlock): bool
    {
        if ( 'core/group' !== ($childBlock['blockName'] ?? null) ) {
            return false;
        }

        $childClass = (string) ($childBlock['attrs']['className'] ?? '');
        if ( ! str_contains($childClass, 'blocks-engine-css-owned-layout') ) {
            return false;
        }

        $declarations = $this->matchingAuthorDeclarations($element);
        $display = strtolower(trim(CssValueInspector::withoutImportant((string) ($declarations['display'] ?? ''))));
        if ( ! in_array($display, array( 'flex', 'inline-flex', 'grid', 'inline-grid' ), true)
            || ! $this->childGroupOwnsDisplay($element, $childClass, $display)
        ) {
            return false;
        }

        unset($declarations['display']);
        $child = ($this->soleElementChild)($element);
        if ( $child instanceof DOMElement ) {
            $childDeclarations = $this->matchingAuthorDeclarations($child);
            foreach ( $declarations as $property => $value ) {
                if ( isset($childDeclarations[$property]) && $childDeclarations[$property] === $value ) {
                    unset($declarations[$property]);
                }
            }
        }

        return $this->hasOnlyRenderNeutralBoxAffectingDeclarationMap($declarations);
    }

    /** @param array<string, mixed> $childBlock */
    private function wrapperTagIsRepresentableOnChild(DOMElement $element, array $childBlock): bool
    {
        $tag = strtolower($element->tagName);
        if ( 'div' === $tag ) {
            return true;
        }

        return 'core/group' === ($childBlock['blockName'] ?? null) && null !== $this->transferableGroupTag($tag);
    }

    private function transferableGroupTag(string $tag): ?string
    {
        return in_array($tag, array( 'li', 'ul', 'ol' ), true) ? $tag : null;
    }

    private function childGroupOwnsDisplay(DOMElement $wrapper, string $childClass, string $display): bool
    {
        $flex = in_array($display, array( 'flex', 'inline-flex' ), true);
        $grid = in_array($display, array( 'grid', 'inline-grid' ), true);
        if ( $grid && ( str_contains($childClass, 'blocks-engine-css-owned-grid') || $this->classListHasExactToken($childClass, 'grid') ) ) {
            return true;
        }
        if ( $flex && ( $this->classListHasExactToken($childClass, 'flex') || $this->classListHasExactToken($childClass, 'inline-flex') ) ) {
            return true;
        }
        if ( (bool) preg_match('/(?:^|\s)be-inline-geometry-[a-f0-9-]+(?:\s|$)/', $childClass) ) {
            return true;
        }
        $child = ($this->soleElementChild)($wrapper);
        if ( ! $child instanceof DOMElement ) {
            return false;
        }
        $childDisplay = strtolower(trim(CssValueInspector::withoutImportant(
            (string) ($this->matchingAuthorDeclarations($child)['display'] ?? '')
        )));

        return $childDisplay === $display;
    }

    private function classListHasExactToken(string $className, string $token): bool
    {
        foreach ( preg_split('/\s+/', trim($className)) ?: array() as $class ) {
            if ( $token === $class ) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $childBlock */
    private function hasBlockingStructureSignals(DOMElement $element, array $childBlock): bool
    {
        $signals = ($this->structureSignals)($element);
        if ( 'core/image' === ($childBlock['blockName'] ?? null) ) {
            unset($signals['card_like'], $signals['grid_like'], $signals['section_container_like']);
        }

        return array() !== $signals;
    }

    /**
     * @param array<int, array<string, mixed>> $wrappers
     * @return array<int, array<string, mixed>>
     */
    private function truncateWrappersAfterAuthoredGrid(array $wrappers): array
    {
        $trimmed = array();
        $seenGrid = false;
        foreach ( $wrappers as $wrapper ) {
            $className = (string) (is_array($wrapper['attributes'] ?? null) ? ($wrapper['attributes']['class'] ?? '') : '');
            if ( ! $seenGrid ) {
                $trimmed[] = $wrapper;
                $seenGrid = str_contains($className, 'blocks-engine-css-owned-grid');
                continue;
            }
            if ( $this->classListHasAuthorToken($className) ) {
                $trimmed[] = $wrapper;
                continue;
            }
            break;
        }
        return $trimmed;
    }

    /**
     * Duplicated verbatim from `HtmlCompilation::classListHasAuthorToken()`,
     * which keeps its own copy for `groupCarriesAuthorClass()` (an authored-
     * grid check unrelated to wrapper coalescing). Pure string classification
     * with no collaborators, so duplication costs less than injecting it.
     */
    private function classListHasAuthorToken(string $className): bool
    {
        foreach ( preg_split('/\s+/', trim($className)) ?: array() as $class ) {
            if ( '' === $class
                || str_starts_with($class, 'blocks-engine-')
                || str_starts_with($class, 'wp-block-')
                || str_starts_with($class, 'is-layout-')
                || str_starts_with($class, 'be-inline-')
            ) {
                continue;
            }
            return true;
        }
        return false;
    }

    /**
     * The layout-geometry proof this wrapper/child pair already satisfies, if
     * the normalizer recorded one. `HtmlCompilation` injects this into
     * `ElementConversionPrelude` as `fn (DOMElement $element): ?array =>
     * $this->wrapperCoalescer->layoutGeometryProofFor($element)` because that
     * prelude needs the same lookup before author-layout lowering runs, not
     * because the lookup itself belongs anywhere but here.
     *
     * @return array<string, mixed>|null
     */
    public function layoutGeometryProofFor(DOMElement $element): ?array
    {
        foreach ($this->session->layoutGeometryState()->proofReductions() as $proof) {
            // The normalizer binds the document digest. This lookup uses the
            // canonical structural selector, not a reusable author class.
            if (!is_array($proof) || SourceDom::elementSelector($element) !== ($proof['wrapper_selector'] ?? null)) continue;
            $child = ($this->soleElementChild)($element);
            if ($child instanceof DOMElement && SourceDom::elementSelector($child) === ($proof['target_selector'] ?? null)) return $proof;
        }
        return null;
    }

    /**
     * Registers (idempotently) the corrective-CSS rule a layout-geometry
     * proof carries and returns the class name that applies it. Public so
     * `HtmlCompilation::proofBackedWrapperCoalescing()` — a distinct, proof-
     * only coalescing path used before author-layout lowering — can call the
     * same carrier instead of keeping a second copy.
     *
     * @param array<string,mixed> $proof
     */
    public function layoutGeometryProofCarrier(array $proof): string
    {
        $declarations = $proof['corrective_css']['declarations'] ?? array();
        if (!is_array($declarations)) return '';
        $parts = array();
        foreach ($declarations as $declaration) if (is_array($declaration)) $parts[] = $declaration['property'] . ':' . $declaration['value'];
        if (array() === $parts) return '';
        $className = 'be-layout-proof-' . substr(hash('sha256', (string) $proof['source_hash'] . "\n" . (string) $proof['wrapper_selector'] . "\n" . implode(';', $parts)), 0, 32);
        $this->session->layoutGeometryState()->registerRule($className, ':root .' . $className . '{' . implode(';', $parts) . '}');
        return $className;
    }

    private function sameSourceGroupChainLeaf(DOMElement $element, string $sourceDigest): ?DOMElement
    {
        if ( '' === $sourceDigest ) {
            return null;
        }

        $child = ($this->soleElementChild)($element);
        while ( $child instanceof DOMElement && hash('sha256', SourceDom::safeFallbackHtml($child, $this->session->authorSelectorProjectionState()->tagMarkers())) !== $sourceDigest ) {
            // A native image block may take its source provenance from the img
            // while retaining an image-only anchor as block attributes.
            $anchorChild = 'a' === strtolower($child->tagName) ? ($this->soleElementChild)($child) : null;
            if ( $anchorChild instanceof DOMElement
                && 'a' === strtolower($child->tagName)
                && (($this->isImageOnlyAnchor)($child) || in_array(strtolower($anchorChild->tagName), array('img', 'picture'), true))
            ) {
                $child = $anchorChild;
                continue;
            }
            if ( ! $this->isNeutralGroupChainWrapper($child) ) {
                return null;
            }
            $child = ($this->soleElementChild)($child);
        }

        return $child;
    }

    private function imageLeafInGroupChain(DOMElement $element): ?DOMElement
    {
        for ($child = ($this->soleElementChild)($element); $child instanceof DOMElement; $child = ($this->soleElementChild)($child)) {
            $tagName = strtolower($child->tagName);
            if (in_array($tagName, array('img', 'svg'), true)) return $child;
            // Captured media exports commonly place their native image behind a
            // passive custom-element carrier. Its own conversion already proves
            // it has no retained block boundary. Use the carrier as the source
            // leaf so selector survival is checked against its actual identity.
            if (str_contains($tagName, '-')) {
                $mediaChild = ($this->soleElementChild)($child);
                if ($mediaChild instanceof DOMElement && in_array(strtolower($mediaChild->tagName), array('img', 'svg'), true)) return $child;
            }
            if (! in_array($tagName, array('div', 'a'), true) && ! str_contains($tagName, '-')) return null;
        }
        return null;
    }

    private function isNeutralGroupChainWrapper(DOMElement $element): bool
    {
        if ( 'div' !== strtolower($element->tagName)
            || $this->runtimeIslands->isRuntimeDomTarget($element)
            || $this->isDirectChildOfStructuralLayout($element)
            || '' !== trim(SourceDom::attr($element, 'id'))
            || '' !== trim(SourceDom::attr($element, 'role'))
            || ! $this->hasOnlyRenderNeutralInlineGeometry($element)
            || array() !== $this->interactiveAttributes($element)
            || array() !== $this->safeDataAttributes($element)
            || array() !== ($this->structureSignals)($element)
            || $this->sourceElementClassifier->hasMotionStructureToken($element)
            || ! $this->hasOnlyRenderNeutralBoxAffectingDeclarations($element)
        ) {
            return false;
        }

        $attrs = $this->styleResolver->presentationAttributes($element);
        return ! array_diff(array_keys($attrs), array( 'className', 'style' )) && ($this->soleElementChild)($element) instanceof DOMElement;
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

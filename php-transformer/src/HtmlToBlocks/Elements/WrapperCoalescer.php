<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use Automattic\BlocksEngine\PhpTransformer\Support\ShellLandmarkPolicy;
use Automattic\BlocksEngine\PhpTransformer\Contract\EditabilityPolicy;
use Automattic\BlocksEngine\PhpTransformer\Contract\EditabilityReport;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatchCache;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\GeneratedBlockRegistry;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\LayoutShellBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleAttributeMapper;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlAttributeName;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\SourceElementDescription;
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
 * Eligibility is built from named, individually inspectable
 * disqualification reasons ({@see self::coalescingDisposition()}), so the
 * reason a wrapper survived is visible.
 *
 * The proof-backed path ({@see self::proofBackedWrapperCoalescing()}) runs
 * before author-layout lowering and only carries a recorded layout-geometry
 * proof onto the wrapper's sole native child.
 *
 * Collaborators that are not wrapper-specific arrive injected: the sole
 * element child walk, image-only anchor classification and the script-state
 * guard as closures, and child conversion and block creation through the
 * `PatternTreeConverter` and `SourceBlockCreator` interfaces.
 */
final class WrapperCoalescer
{
    /**
     * @param Closure(DOMElement): ?DOMElement $soleElementChild
     * @param Closure(DOMElement): bool $isImageOnlyAnchor
     * @param Closure(DOMElement): bool $preservesScriptStateWrapper
     */
    public function __construct(
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly RuntimeIslandAnalyzer $runtimeIslands,
        private readonly StyleResolver $styleResolver,
        private readonly SourceBlockCreator $createBlock,
        private readonly HtmlTransformerSession $session,
        private readonly SourceElementDescription $sourceElementDescription,
        private readonly Closure $soleElementChild,
        private readonly Closure $isImageOnlyAnchor,
        private readonly Closure $preservesScriptStateWrapper,
        private readonly PatternTreeConverter $tree
    ) {
    }

    private const LANDMARK_GROUP_TAGS = array('header', 'footer');

    // Charge shared ancestry against the saved List View depth, not only
    // against the length of each uninterrupted single-child run.
    private const MAX_NATIVE_WRAPPER_DEPTH = 8;

    private bool $wrapperBudgetExceeded = false;

    /** @param array<int, array<string, mixed>> $blocks @return array<int, array<string, mixed>> */
    public function compressProjectedGroupChains(array $blocks, int $depth = 1): array
    {
        if (1 === $depth) {
            $metrics = (new EditabilityReport())->fromBlocks($blocks)['metrics'];
            $this->wrapperBudgetExceeded = $metrics['wrapper_to_content_ratio'] > EditabilityPolicy::THRESHOLDS['wrapper_to_content_ratio'];
        }
        return array_values(array_map(fn (array $block): array => $this->normalizeWrapperChain($block, $depth), $blocks));
    }

    /** @param array<string, mixed> $block @return array<string, mixed> */
    private function normalizeWrapperChain(array $block, int $depth = 1): array
    {
        // Spend shared ancestry once before charging its depth to each branch.
        $ancestorChain = array();
        $cursor = $block;
        while (1 === count(ArrayField::array($cursor, 'innerBlocks')) && null !== ($descriptor = $this->foldableWrapperDescriptor($cursor))) {
            $ancestorChain[] = array('block' => $cursor, 'descriptor' => $descriptor);
            $cursor = $cursor['innerBlocks'][0];
        }
        if (2 <= count($ancestorChain)
            && ($depth - 1 + $this->nativeBlockHeight($block) > self::MAX_NATIVE_WRAPPER_DEPTH
                || count($ancestorChain) >= self::MAX_NATIVE_WRAPPER_DEPTH)) {
            return $this->foldWrapperChain($ancestorChain, $this->normalizeWrapperChain($cursor, $depth + 1));
        }

        $candidate = $this->collectWrapperChain($block, $depth);
        $chain = $candidate['chain'];
        $terminal = $candidate['terminal'];
        // A group chain around a core block already has a native representation:
        // nested core/group wrappers keep every id and class, editable with
        // core tools. Fold it into a generated companion only when native
        // nesting would breach an editability budget, or around
        // non-core terminals that need shell transport.
        $terminalName = (string) ($terminal['blockName'] ?? '');
        $nativeGroupTree = (('core/freeform' === $terminalName
                && (array() === ($terminal['innerBlocks'] ?? array())
                    || array() !== array_filter($terminal['innerBlocks'] ?? array(), static fn (array $child): bool => 'core/group' === ($child['blockName'] ?? null))))
            || (str_starts_with($terminalName, 'core/') && ! in_array($terminalName, array( 'core/freeform', 'core/html' ), true)))
            && ('core/freeform' === $terminalName
                || ('core/group' === $terminalName && array() === ($terminal['innerBlocks'] ?? array()))
                || array() === array_filter($chain, static fn (array $entry): bool => 'div' !== ($entry['descriptor']['tagName'] ?? null)))
            && $depth + count($chain) - 1 + $this->nativeBlockHeight($terminal) <= EditabilityPolicy::THRESHOLDS['max_nesting_depth'];
        // Div ancestors remain editable even when their terminal is a generated
        // block or an extraction slot. The terminal's transport does not require
        // transporting its native parent boxes in a generated shell too.
        // Inline-only fragments retain their existing shell transport. Source
        // boxes and authored blocks, unlike synthetic inline paragraphs, supply
        // independently editable structure for native ancestor Groups.
        $hasSourceBox = array() !== array_filter($terminal['innerBlocks'] ?? array(), static fn (array $child): bool =>
            ! str_contains((string) ($child['attrs']['className'] ?? ''), 'blocks-engine-synthetic-paragraph')
            || str_contains((string) ($child['attrs']['className'] ?? ''), 'blocks-engine-source-box-paragraph'));
        $nativeGroupTree = $nativeGroupTree || (('core/freeform' !== $terminalName || $hasSourceBox)
            && array() === array_filter($chain, static fn (array $entry): bool => 'div' !== ($entry['descriptor']['tagName'] ?? null))
            && $depth + count($chain) - 1 + $this->nativeBlockHeight($terminal) <= EditabilityPolicy::THRESHOLDS['max_nesting_depth']);
        // The ratio budget belongs to the document, not each component. An
        // empty visual branch can have no content leaves while the complete
        // document is comfortably within budget; folding it buys no policy gain.
        if ($nativeGroupTree && $this->wrapperBudgetExceeded && 'core/freeform' === $terminalName && array() !== ($terminal['innerBlocks'] ?? array()) && 2 <= count($chain)) {
            $nativeMetrics = (new EditabilityReport())->fromBlocks(array($block))['metrics'];
            $nativeGroupTree = $nativeMetrics['wrapper_to_content_ratio'] <= EditabilityPolicy::THRESHOLDS['wrapper_to_content_ratio'];
        }
        $terminalIsShell = $this->sourceElementClassifier->isLayoutShellBlock($terminal);
        if (!$nativeGroupTree && (2 <= count($chain) || (array() !== $chain && $terminalIsShell))) {
            // Decide the parent's saved shape before normalizing descendants.
            // The chain becomes one level; using its old depth can needlessly
            // turn otherwise native descendants into generated shells.
            $folded = $this->foldWrapperChain($chain, $terminal);
            $folded['innerBlocks'] = $this->compressProjectedGroupChains($folded['innerBlocks'] ?? array(), $depth + 1);
            return $folded;
        }

        if (array() !== $chain) {
            $block['_wrapper_normalization_boundary'] = 'single_wrapper';
        } elseif (null !== $candidate['boundary']) {
            $block['_wrapper_normalization_boundary'] = $candidate['boundary'];
        }
        // Freeform is a conversion fragment, not a saved block level. Its
        // children serialize in place, just as nativeBlockHeight accounts for.
        if (is_array($block['innerBlocks'] ?? null)) $block['innerBlocks'] = $this->compressProjectedGroupChains($block['innerBlocks'], $depth + ('core/freeform' === ($block['blockName'] ?? null) ? 0 : 1));
        return $block;
    }

    /**
     * Levels a block occupies in the saved tree. A freeform chain terminal is
     * not saved itself: its children take its place.
     *
     * @param array<string,mixed> $block
     */
    private function nativeBlockHeight(array $block): int
    {
        $height = 0;
        foreach (ArrayField::array($block, 'innerBlocks') as $child) {
            if (is_array($child)) $height = max($height, $this->nativeBlockHeight($child));
        }

        return 'core/freeform' === ($block['blockName'] ?? null) ? $height : $height + 1;
    }

    /** @param array<string,mixed> $block @return array{chain:array<int,array{block:array<string,mixed>,descriptor:array<string,mixed>}>,terminal:array<string,mixed>,boundary:?string} */
    private function collectWrapperChain(array $block, int $depth = 1): array
    {
        $chain = array();
        $cursor = $block;
        while (1 === count(ArrayField::array($cursor, 'innerBlocks')) && null !== ($descriptor = $this->foldableWrapperDescriptor($cursor))) {
            $chain[] = array('block' => $cursor, 'descriptor' => $descriptor);
            $cursor = $cursor['innerBlocks'][0];
        }
        if (null !== ($descriptor = $this->foldableWrapperDescriptor($cursor))) {
            $chain[] = array('block' => $cursor, 'descriptor' => $descriptor);
            $terminal = array(
                'blockName' => 'core/freeform',
                'innerBlocks' => ArrayField::array($cursor, 'innerBlocks'),
            );
            return array('chain' => $chain, 'terminal' => $terminal, 'boundary' => null);
        }
        if (array() === $chain) return array('chain' => array(), 'terminal' => $cursor, 'boundary' => $this->wrapperBoundaryReason($cursor));
        return array('chain' => $chain, 'terminal' => $cursor, 'boundary' => $this->wrapperBoundaryReason($cursor));
    }

    /** @param array<string,mixed> $block */
    private function wrapperBoundaryReason(array $block): string
    {
        if (!in_array($block['blockName'] ?? null, array('core/group', 'core/columns', 'core/column'), true)) return 'non_wrapper_terminal';
        if (isset($block['_binding_token']) || $this->session->transformationProvenanceState()->isBindingAnchor($block['_source_provenance_id'] ?? null)) return 'binding_boundary';
        if ($this->hasIndependentWrapperOwnership($block)) return 'owned_wrapper';
        if (in_array(strtolower((string) ($block['attrs']['tagName'] ?? 'div')), array('ul', 'ol', 'li'), true)) return 'list_semantics';
        if (in_array(strtolower((string) ($block['attrs']['tagName'] ?? 'div')), self::LANDMARK_GROUP_TAGS, true)) return 'landmark_semantics';
        return 'serialization_unsafe';
    }

    /** @param array<string,mixed> $block @return array{tagName: string, attributes: array<string, string>, opening: string, closing: string}|null */
    private function foldableWrapperDescriptor(array $block): ?array
    {
        // A landmark stays its own group block: folded into a layout shell's
        // wrapper list, a header or footer (or a `role="main"` region, which
        // only its block name records) loses its block identity for the editor
        // and for shell extraction. A native grid or flex
        // layout is likewise an editor control the wrapper list cannot carry.
        if (!in_array($block['blockName'] ?? null, array('core/group'), true) || in_array($block['attrs']['layout']['type'] ?? null, array('grid', 'flex'), true) || isset($block['_binding_token']) || $this->session->transformationProvenanceState()->isBindingAnchor($block['_source_provenance_id'] ?? null) || $this->hasIndependentWrapperOwnership($block) || in_array(strtolower((string) ($block['attrs']['tagName'] ?? 'div')), array_merge(array('ul', 'ol', 'li'), self::LANDMARK_GROUP_TAGS), true) || ShellLandmarkPolicy::namesRoleOnlyLandmark(ArrayField::array($block, 'attrs'))) return null;
        return $this->groupWrapperDescriptor($block);
    }

    /** @param array<string,mixed> $block */
    private function hasIndependentWrapperOwnership(array $block): bool
    {
        $attrs = ArrayField::array($block, 'attrs');
        // A dialog Group's identity includes its runtime shell and bindings.
        return isset($attrs['metadata']['blocksEngineDialog'])
            || !empty($block['_editability_runtime_owned'])
            || !empty($block['_editability_visual_owned'])
            || !empty($attrs['backgroundColor'])
            || !empty($attrs['gradient'])
            || !empty($attrs['style'])
            || in_array($block['blockName'] ?? null, array('core/columns', 'core/column'), true);
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
        if ($terminalIsShell) $wrappers = array_merge($wrappers, ArrayField::array($terminal, '_layout_shell_wrappers'));
        $opening = implode('', array_column($wrappers, 'opening'));
        $closing = implode('', array_reverse(array_column($wrappers, 'closing')));
        $provenanceIds = array_values(array_filter(array_map(static fn (array $entry): mixed => $entry['block']['_source_provenance_id'] ?? null, $chain), 'is_int'));
        if ($terminalIsShell) $provenanceIds = array_merge($provenanceIds, ArrayField::array($terminal, '_source_provenance_ids'));
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

    /** @var array<string, array{tagName: string, attributes: array<string, string>}|null> */
    private array $parsedWrapperCache = array();

    /** @param array<string, mixed> $block @return array{tagName: string, attributes: array<string, string>, opening: string, closing: string}|null */
    public function groupWrapperDescriptor(array $block): ?array
    {
        // Selector-hook hosts must stay independently editable, not become a
        // generated layout-shell together with their grid/flex item child.
        if ( ! empty($block['_native_custom_wrapper_hook']) ) {
            return null;
        }
        $content = ArrayField::array($block, 'innerContent');
        $opening = is_string($content[0] ?? null) ? $content[0] : '';
        $last = array() === $content ? null : $content[array_key_last($content)];
        $closing = is_string($last) ? $last : '';
        $children = ArrayField::array($block, 'innerBlocks');
        if (count($content) !== count($children) + 2 || array_slice($content, 1, -1) !== array_fill(0, count($children), null)) {
            return null;
        }
        if (! preg_match('/^<([a-z][a-z0-9-]*)\b/i', $opening, $match) || '' === $closing) {
            return null;
        }
        $cacheKey = $opening . "\0" . $closing;
        if (! array_key_exists($cacheKey, $this->parsedWrapperCache)) {
            $tagName = strtolower($match[1]);
            $document = HtmlSerialization::parseFragment('<body>' . $opening . $closing . '</body>');
            $element = $document->getElementsByTagName($tagName)->item(0);
            $parsed = null;
            if ($element instanceof DOMElement) {
                $attributes = array();
                foreach ( $element->attributes ?? array() as $attribute ) {
                    if ( ! HtmlAttributeName::isIntegerKey($attribute->nodeName) ) {
                        $attributes[strtolower($attribute->nodeName)] = (string) $attribute->nodeValue;
                    }
                }
                $parsed = array('tagName' => $tagName, 'attributes' => $attributes);
            }
            // Bound lifetime memory for long-lived compile sessions.
            if (count($this->parsedWrapperCache) >= 256) array_shift($this->parsedWrapperCache);
            $this->parsedWrapperCache[$cacheKey] = $parsed;
        }
        $parsed = $this->parsedWrapperCache[$cacheKey];
        if (null === $parsed) return null;
        $tagName = $parsed['tagName'];
        $attributes = $parsed['attributes'];
        // Attribute-only carriers may project core editor styles. A shell must
        // already render every geometry token on this exact saved wrapper.
        preg_match_all('/(?:^|\s)(be-inline-geometry-[^\s]+)/', (string) ($block['attrs']['className'] ?? ''), $geometryClasses);
        $savedClasses = SourceDom::tokens((string) ($attributes['class'] ?? ''));
        if (array_diff($geometryClasses[1], $savedClasses)) {
            return null;
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

    /** @var array<string, true> */
    private array $inheritedTextCarriers = array();

    /**
     * Text-only wrappers have no box to project. Resolve along the source
     * chain, rather than merging carrier classes: inheritance is not a
     * specificity contest between the disappearing ancestors.
     *
     * @param array<string, mixed> $childBlock
     * @return array<string, mixed>|null
     */
    private function coalescedInheritedTextWrapper(DOMElement $element, array $childBlock): ?array
    {
        if ($this->isBindingAnchor($childBlock) || ! $this->isInheritedTextWrapper($element)) return null;
        if (! in_array($childBlock['blockName'] ?? '', array('core/paragraph', 'core/heading', 'core/group', 'core/image', 'core/quote', 'core/list'), true)) return null;
        $chain = array($element);
        $leaf = ($this->soleElementChild)($element);
        while ($leaf instanceof DOMElement && $this->isInheritedTextWrapper($leaf)) {
            $next = ($this->soleElementChild)($leaf);
            // Keep the last wrapper boundary needed by descendant selectors.
            if (! $next instanceof DOMElement || ! $this->selectorMatchingSurvivesWrapperCoalescing($element, $next, true)) break;
            $chain[] = $leaf;
            $leaf = $next;
        }
        if (! $leaf instanceof DOMElement) return null;
        if ('core/paragraph' === ($childBlock['blockName'] ?? '') && 'p' !== strtolower($leaf->tagName)) return null;
        if (! $this->selectorMatchingSurvivesWrapperCoalescing($element, $leaf, true)) return null;
        $chain[] = $leaf;
        $values = array();
        foreach ($chain as $node) {
            foreach ($this->inheritedWrapperDeclarations($node) as $name => $value) {
                if (! $this->isInheritedTextProperty($name)) continue;
                if (in_array(CssValueInspector::comparable($value), array('inherit', 'unset'), true)) continue;
                $values = $this->styleResolver->mergeCssDeclarationMaps($values, array($name => $value));
            }
        }
        $attrs = ArrayField::array($childBlock, 'attrs');
        $textAlign = $attrs['style']['typography']['textAlign'] ?? $attrs['align'] ?? null;
        if (isset($textAlign, $values['text-align']) && $textAlign === CssValueInspector::comparable($values['text-align'])) unset($values['text-align']);
        if (array() === $values) return null;
        $carrier = $this->styleResolver->inheritedTextCarrier($values);
        $wrapperAttrs = $this->styleResolver->presentationAttributes($element);
        foreach (array('fontSize', 'fontFamily', 'textColor', 'align') as $attribute) {
            if (! isset($attrs[$attribute]) && isset($wrapperAttrs[$attribute])) $attrs[$attribute] = $wrapperAttrs[$attribute];
        }
        $classes = SourceDom::tokens((string) ($attrs['className'] ?? ''));
        $classes = array_values(array_filter($classes, fn (string $name): bool => ! isset($this->inheritedTextCarriers[$name])));
        $attrs['className'] = SourceDom::mergeClassNames(implode(' ', $classes), $carrier, ...array_map(static fn (DOMElement $node): string => SourceDom::attr($node, 'class'), array_slice($chain, 0, -1)));
        if ('' !== $carrier) $this->inheritedTextCarriers[$carrier] = true;
        return $this->createBlock->createBlock((string) $childBlock['blockName'], $attrs, $childBlock['innerBlocks'] ?? array(), $leaf);
    }

    /** @return array<string, string> */
    private function inheritedWrapperDeclarations(DOMElement $element): array
    {
        return $this->styleResolver->mergeCssDeclarationMaps(
            $this->matchingAuthorDeclarations($element),
            $this->styleResolver->cssDeclarations($this->styleResolver->specificityResolvedPresentationStyle($element))
        );
    }

    private function isInheritedTextProperty(string $name): bool
    {
        return str_starts_with($name, 'font-') || in_array($name, array('font', 'white-space', 'text-wrap', 'text-wrap-mode', 'text-wrap-style', 'white-space-collapse', 'color', 'line-height', 'letter-spacing', 'word-spacing', 'text-align', 'text-transform', 'text-indent'), true);
    }

    private function isInheritedTextWrapper(DOMElement $element): bool
    {
        if ('div' !== strtolower($element->tagName)
            || SourceDom::hasClass($element, 'wp-block-group')
            || ! ($this->soleElementChild)($element) instanceof DOMElement
            || $this->runtimeIslands->isRuntimeDomTarget($element)
            || $this->sourceElementClassifier->hasMotionStructureToken($element)
            || $this->isDirectChildOfStructuralLayout($element)
            || array() !== $this->interactiveAttributes($element)
        ) return false;
        foreach ($element->childNodes as $node) {
            if (XML_TEXT_NODE === $node->nodeType && '' !== trim((string) $node->nodeValue)) return false;
        }
        foreach ($element->attributes as $attribute) {
            if (! in_array(strtolower($attribute->nodeName), array('class', 'style', 'data-node-view-wrapper', 'data-node-view-content', 'data-node-view-content-inner'), true)) return false;
        }
        foreach ($this->session->authorStyleRuleCandidates($element) as $rule) {
            if (str_contains($rule['selector'], '>')) return false;
        }
        $declarations = $this->inheritedWrapperDeclarations($element);
        // Editor node-view wrappers remain transparent after source normalization
        // removes an empty style attribute. Do not use reset declarations as the
        // only evidence that such a wrapper can fold.
        if (array() === $declarations
            && ! ($this->soleElementChild)($element)->hasAttribute('data-node-view-wrapper')
            && ! $element->hasAttribute('data-node-view-wrapper')
            && ! $element->hasAttribute('data-node-view-content')
            && ! $element->hasAttribute('data-node-view-content-inner')
            && ! $element->hasAttribute('style')) return false;
        // A zero-width border paints nothing, and with no border or padding
        // box-sizing cannot change the box, so a universal reset is inert here.
        $zeroWidthBorder = (new StyleAttributeMapper())->allBorderWidthsZero($declarations);
        foreach ($declarations as $name => $value) {
            // This invalid spelling has no rendering effect and no carrier.
            if ('whitespace' === $name || $this->isInheritedTextProperty($name)) continue;
            if ('box-sizing' === $name || ($zeroWidthBorder && preg_match('/^border(?:-(?:top|right|bottom|left))?(?:-(?:width|style|color))?$/', $name))) continue;
            if (in_array($name, array('width', 'height'), true) && '100%' === CssValueInspector::comparable($value)) {
                $parent = $element->parentNode;
                if (! $parent instanceof DOMElement) return false;
                $parentStyle = $this->styleResolver->structuralPresentationDeclarations($parent);
                if (! in_array(CssValueInspector::comparable((string) ($parentStyle['display'] ?? 'block')), array('', 'block'), true)
                    || ! in_array(CssValueInspector::comparable((string) ($parentStyle['position'] ?? 'static')), array('', 'static', 'relative'), true)) return false;
                continue;
            }
            return false;
        }
        return true;
    }

    /**
     * A wrapper that preserves script-observed state stays its own group.
     *
     * @param array<string, mixed> $childBlock @return array<string, mixed>|null
     * @see self::coalescingDisposition() for the named disqualification reasons.
     */
    public function coalescedSingleGroupWrapper(DOMElement $element, array $childBlock): ?array
    {
        if ( ($this->preservesScriptStateWrapper)($element) ) {
            return null;
        }
        $textWrapper = $this->coalescedInheritedTextWrapper($element, $childBlock);
        if (null !== $textWrapper) return $textWrapper;
        $resolution = $this->coalescingResolution($element, $childBlock);
        if ( ! $resolution['disposition']->isCoalesce() ) {
            return null;
        }
        $proof = $resolution['proof'];
        $attrs = $resolution['attrs'];
        $sourceChild = $resolution['source_child'];

        $childAttrs = ArrayField::array($childBlock, 'attrs');
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
            // A saved core/group boundary is a coalescing decision already made:
            // its save shape no longer carries the source signals (data
            // attributes, an anchor tag) that kept it, so re-deciding from the
            // saved markup would fold a wrapper the first transform preserved.
            array('saved_group_boundary', fn (): bool => SourceDom::hasClass($element, 'wp-block-group')),
            array('child_is_binding_anchor', fn (): bool => $this->isBindingAnchor($childBlock)),
            array('unrepresentable_wrapper_tag', fn (): bool => ! $this->wrapperTagIsRepresentableOnChild($element, $childBlock)),
            array('unsupported_child_block_name', fn (): bool => ! in_array($childBlock['blockName'] ?? null, array( 'core/group', 'core/image' ), true)),
            array('full_width_shell_requires_group_child', fn (): bool => $fullWidthTransparentShell && 'core/group' !== ($childBlock['blockName'] ?? null)),
            array('runtime_dom_target', fn (): bool => $this->runtimeIslands->isRuntimeDomTarget($element)),
            array('structural_layout_child_without_proof', fn (): bool => null === $proof && $this->isDirectChildOfStructuralLayout($element) && ! $redundantNestedLayout),
            array('has_id_attribute', fn (): bool => '' !== trim(SourceDom::attr($element, 'id'))),
            array('has_role_attribute', fn (): bool => '' !== trim(SourceDom::attr($element, 'role'))),
            array('non_neutral_geometry_without_proof', fn (): bool => null === $proof && ! $fullWidthTransparentShell && ! $this->hasOnlyRenderNeutralInlineGeometry($element) && ! $redundantNestedLayout),
            array('has_interactive_attributes', fn (): bool => array() !== $this->interactiveAttributes($element)),
            array('has_data_attributes_without_proof', fn (): bool => null === $proof && array() !== SourceDom::safeDataAttributes($element)),
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

    /**
     * A provider replaces a bound block wholesale, so a wrapper absorbed into
     * it would be deleted by that replacement.
     *
     * @param array<string, mixed> $block
     */
    private function isBindingAnchor(array $block): bool
    {
        return $this->session->transformationProvenanceState()->isBindingAnchor($block['_source_provenance_id'] ?? null);
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

        // Descendants retain their identity, but lose ancestors too. Checking
        // only the merged wrapper misses rules whose subject is below it.
        $descendants = SourceDom::descendantElements($child);
        $beforeCandidatesByKey = array();
        foreach ( array_merge($chain, $descendants) as $node ) {
            foreach ( $this->session->authorStyleRuleCandidates($node) as $selector ) {
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

        $descendantMatchesBefore = array();
        foreach ( $descendants as $index => $node ) {
            foreach ( $beforeCandidates as $selector ) {
                $descendantMatchesBefore[$index][$selector['key']] = $selector['parsed']['supported']
                    && $this->session->sourceStyleResolutionState()->selectorMatchCache->matches($node, $selector['selector'], $selector['parsed'], true)['matches'];
            }
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
        $afterCandidates = $this->session->authorStyleRuleCandidates($matchNode, $temporarySelectorCache);
        foreach ( $descendants as $node ) {
            $afterCandidates = array_merge($afterCandidates, $this->session->authorStyleRuleCandidates($node, $temporarySelectorCache));
        }
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

        foreach ( $descendants as $index => $node ) {
            foreach ( $candidates as $key => $selector ) {
                $matchesAfter = $selector['parsed']['supported']
                    && $temporarySelectorCache->matches($node, $selector['selector'], $selector['parsed'], true)['matches'];
                if ( ($descendantMatchesBefore[$index][$key] ?? false) !== $matchesAfter ) {
                    $survives = false;
                    break 2;
                }
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
        return in_array(CssValueInspector::declared($declarations, 'display'), array( 'flex', 'inline-flex', 'grid', 'inline-grid' ), true);
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
        foreach ($this->styleResolver->inlineDeclarations($element) as $property => $value) {
            if (! $this->isRenderNeutralGeometryDeclaration($property, $value)) return false;
        }
        return true;
    }

    private function hasOnlyFullWidthTransparentInlineGeometry(DOMElement $element): bool
    {
        $declarations = $this->styleResolver->inlineDeclarations($element);
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
        $zeroWidthBorder = (new StyleAttributeMapper())->allBorderWidthsZero($declarations);
        foreach ($declarations as $property => $value) {
            if ($zeroWidthBorder && preg_match('/^border(?:-(?:top|right|bottom|left))?(?:-(?:width|style|color))?$/', $property)) continue;
            if (! preg_match('/^(?:align-content|align-items|align-self|background|border|bottom|column|contain|display|filter|flex|float|gap|grid|height|inset|isolation|left|margin|max-|min-|opacity|outline|overflow|padding|perspective|position|right|row-gap|top|transform|width|z-index)/', $property)) continue;
            if (! $this->isRenderNeutralGeometryDeclaration($property, $value)) return false;
        }
        return true;
    }

    private function isRenderNeutralGeometryDeclaration(string $property, string $value): bool
    {
        if ('whitespace' === $property) return true;
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
        $display = CssValueInspector::declared($declarations, 'display');
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
        foreach ( $this->session->authorStyleRuleCandidates($element) as $selector ) {
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
        foreach ( SourceDom::tokens($className) as $class ) {
            if ( $token === $class ) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $childBlock */
    private function hasBlockingStructureSignals(DOMElement $element, array $childBlock): bool
    {
        $signals = $this->sourceElementDescription->structureSignals($element);
        if ( 'core/image' === ($childBlock['blockName'] ?? null) ) {
            unset($signals['card_like'], $signals['grid_like'], $signals['section_container_like']);
        }

        return array() !== $signals;
    }

    /**
     * The layout-geometry proof this wrapper/child pair already satisfies, if
     * the normalizer recorded one. `ElementConversionPrelude` also reads it
     * before author-layout lowering runs.
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
     * This runs before author-layout lowering, whose custom block deliberately
     * owns CSS flex/grid topology. The contract has already compared source and
     * wrapper-free layouts; this method only carries the wrapper identity and
     * generated geometry carrier onto an existing native child block.
     *
     * @param array<int,array<string,mixed>> $fallbacks
     * @return array<string,mixed>|null
     */
    public function proofBackedWrapperCoalescing(DOMElement $element, array &$fallbacks): ?array
    {
        $proof = $this->layoutGeometryProofFor($element);
        if (null === $proof || $this->runtimeIslands->isRuntimeDomTarget($element) || '' !== trim(SourceDom::attr($element, 'id')) || '' !== trim(SourceDom::attr($element, 'role')) || array() !== $this->sourceElementDescription->interactiveAttributes($element) || $this->sourceElementClassifier->hasMotionStructureToken($element)) return null;
        $children = $this->tree->children($element, $fallbacks, true);
        if (1 !== count($children) || !in_array($children[0]['blockName'] ?? null, array('core/group', 'core/image'), true) || $this->session->transformationProvenanceState()->isBindingAnchor($children[0]['_source_provenance_id'] ?? null)) return null;
        $sourceChild = ($this->soleElementChild)($element);
        if (!$sourceChild instanceof DOMElement || SourceDom::elementSelector($sourceChild) !== ($proof['target_selector'] ?? null)) return null;
        $childAttrs = is_array($children[0]['attrs'] ?? null) ? $children[0]['attrs'] : array();
        $childAttrs['className'] = SourceDom::mergeClassNames((string) ($childAttrs['className'] ?? ''), $this->layoutGeometryProofCarrier($proof));
        $childAttrs = array_filter($childAttrs, static fn (mixed $value): bool => !is_string($value) || '' !== trim($value));
        $this->session->layoutGeometryState()->recordProof($proof);
        return $this->createBlock->createBlock((string) $children[0]['blockName'], $childAttrs, $children[0]['innerBlocks'] ?? array(), $sourceChild);
    }

    /**
     * Registers (idempotently) the corrective-CSS rule a layout-geometry
     * proof carries and returns the class name that applies it.
     *
     * @param array<string,mixed> $proof
     */
    private function layoutGeometryProofCarrier(array $proof): string
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
            || array() !== SourceDom::safeDataAttributes($element)
            || array() !== $this->sourceElementDescription->structureSignals($element)
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

    private function generatedBlocks(): GeneratedBlockRegistry
    {
        return $this->session->generatedBlockRegistry()
            ?? throw new LogicException('Generated block registry has not been prepared for this transform.');
    }
}

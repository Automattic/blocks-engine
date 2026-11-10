<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\Contract\GeneratedBlockEditingSurface;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/**
 * Lowers reusable-component candidates: to native blocks when the whole
 * component is provably native, otherwise to the generated component block
 * the fallback emitter registers for it.
 */
final class GeneratedComponentLowering implements ElementConverter
{
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly RuntimeIslandAnalyzer $runtimeIslands,
        private readonly AuthorLayoutLowering $authorLayout,
        private readonly SourceBlockCreator $createBlock
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        $component = $this->isCandidate($element) ? $this->nativeOrGeneratedBlock($element, $fallbacks) : null;

        return null === $component ? ConversionOutcome::unhandled() : ConversionOutcome::handled($component);
    }

    public function isCandidate(DOMElement $element): bool
    {
        return $this->session->reusableComponentState()->isGeneratedCandidate((string) $element->getNodePath());
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function nativeOrGeneratedBlock(DOMElement $element, array &$fallbacks): ?array
    {
        $nativeFallbacks = array();
        $hasRuntimeTarget = false;
        foreach (array_merge(array($element), SourceDom::descendantElements($element)) as $target) {
            $hasRuntimeTarget = $hasRuntimeTarget || $this->runtimeIslands->isRuntimeDomTarget($target);
        }
        // A defining script or an exact runtime DOM contract cannot be proven
        // editable just by inspecting the static children of its host.
        $native = $hasRuntimeTarget || str_contains($element->tagName, '-')
            ? null
            : $this->authorLayout->authorLayoutBlockFromElement($element, $nativeFallbacks);
        $reason = 'generated_component_non_native_items';
        if (null !== $native && array() === $nativeFallbacks && $this->isEntirelyNativeComponent($native)) {
            return $native;
        }
        $generated = $this->session->fallbackEmitter()->maybeGenerateCustomBlock($element, $this->session->requireGeneratedBlockRegistry(), true, true);
        if (null === $generated) {
            return null;
        }
        // Explain the preserved editing surface without adding conversion loss
        // to the fallbacks list that drives quality fallback_count.
        $this->session->transformationEvidenceState()->recordConversionDiagnostic(array_merge(array(
            'type' => 'generated_component',
            'severity' => 'info',
            'conversion_classification' => 'editable_approximation',
            'reason' => $reason,
            'code' => $reason,
            'diagnostic_code' => $reason,
            'message' => 'Repeated content retained as generated raw HTML: ' . $reason . '.',
            'source_format' => 'html',
            'tag' => strtolower($element->tagName),
            'selector' => SourceDom::elementSelector($element),
        ), $this->session->transformationProvenanceState()->fallback()));
        return $this->generatedBlock($generated, $element);
    }

    /** @param array{blockName: string, attrs: array<string, mixed>} $generated @return array<string, mixed> */
    public function generatedBlock(array $generated, DOMElement $element): array
    {
        $block = $this->createBlock->createBlock($generated['blockName'], $generated['attrs'], array(), $element);
        foreach (array_merge(array($element), SourceDom::descendantElements($element)) as $target) {
            if (! $this->runtimeIslands->isRuntimeDomTarget($target)) {
                continue;
            }
            $block['_editability_runtime_owned'] = true;
            $this->runtimeIslands->recordNativeRuntimeDomPreservation($target, $generated['blockName']);
        }
        return $block;
    }

    /** @param array<string, mixed> $block */
    private function isEntirelyNativeComponent(array $block): bool
    {
        $name = (string) ($block['blockName'] ?? '');
        $rawHtmlNames = GeneratedBlockEditingSurface::rawHtmlBlockNames($this->session->requireGeneratedBlockRegistry()->definitions());
        $nativeCore = str_starts_with($name, 'core/') && ! in_array($name, array('core/html', 'core/freeform'), true);
        $structuredGenerated = str_starts_with($name, $this->session->requireGeneratedBlockRegistry()->namespace() . '/') && ! isset($rawHtmlNames[$name]);
        if (! $nativeCore && ! $structuredGenerated) {
            return false;
        }
        foreach ($block['innerBlocks'] ?? array() as $child) {
            if (! $this->isEntirelyNativeComponent($child)) {
                return false;
            }
        }
        return true;
    }
}

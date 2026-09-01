<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\AssetMaterializationState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\TransformationEvidenceState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\TransformationProvenanceState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\LayoutGeometryState;
use Closure;
use DOMElement;

/**
 * Explicit collaborator surface for {@see SvgMaterializer}.
 *
 * Per-transform state (layout geometry, materialized assets, transformation
 * evidence and provenance) is resolved through closures because the materializer
 * is constructed once with the transformer but must see the state belonging to
 * the transform currently running.
 */
final class SvgMaterializationContext
{
    /**
     * @param Closure(string, array<string, mixed>, array<int, array<string, mixed>>, ?DOMElement, ?DOMElement): array<string, mixed> $createBlock
     * @param Closure(string): bool                                                                $isInlineContentElement
     * @param Closure(DOMElement): bool                                                            $isVisualLayerElement
     * @param Closure(): LayoutGeometryState                                                       $layoutGeometry
     * @param Closure(): AssetMaterializationState                                                 $materializedAssets
     * @param Closure(DOMElement): ?string                                                         $reusableComponentFingerprintFor
     * @param Closure(DOMElement): string                                                          $safeFallbackHtml
     * @param Closure(DOMElement): string                                                          $sanitizeInlineSvgMarkup
     * @param Closure(): TransformationEvidenceState                                               $transformationEvidence
     * @param Closure(): TransformationProvenanceState                                             $transformationProvenance
     */
    public function __construct(
        private readonly Closure $createBlock,
        private readonly Closure $isInlineContentElement,
        private readonly Closure $isVisualLayerElement,
        private readonly Closure $layoutGeometry,
        private readonly Closure $materializedAssets,
        private readonly Closure $reusableComponentFingerprintFor,
        private readonly Closure $safeFallbackHtml,
        private readonly Closure $sanitizeInlineSvgMarkup,
        private readonly Closure $transformationEvidence,
        private readonly Closure $transformationProvenance
    ) {
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<int, array<string, mixed>> $innerBlocks
     * @return array<string, mixed>
     */
    public function createBlock(
        string $name,
        array $attrs = array(),
        array $innerBlocks = array(),
        ?DOMElement $sourceElement = null,
        ?DOMElement $logicalSourceElement = null
    ): array {
        return ($this->createBlock)($name, $attrs, $innerBlocks, $sourceElement, $logicalSourceElement);
    }

    public function isInlineContentElement(string $tagName): bool
    {
        return ($this->isInlineContentElement)($tagName);
    }

    public function isVisualLayerElement(DOMElement $element): bool
    {
        return ($this->isVisualLayerElement)($element);
    }

    public function layoutGeometry(): LayoutGeometryState
    {
        return ($this->layoutGeometry)();
    }

    public function materializedAssets(): AssetMaterializationState
    {
        return ($this->materializedAssets)();
    }

    public function reusableComponentFingerprintFor(DOMElement $element): ?string
    {
        return ($this->reusableComponentFingerprintFor)($element);
    }

    public function safeFallbackHtml(DOMElement $element): string
    {
        return ($this->safeFallbackHtml)($element);
    }

    public function sanitizeInlineSvgMarkup(DOMElement $element): string
    {
        return ($this->sanitizeInlineSvgMarkup)($element);
    }

    public function transformationEvidence(): TransformationEvidenceState
    {
        return ($this->transformationEvidence)();
    }

    public function transformationProvenance(): TransformationProvenanceState
    {
        return ($this->transformationProvenance)();
    }
}

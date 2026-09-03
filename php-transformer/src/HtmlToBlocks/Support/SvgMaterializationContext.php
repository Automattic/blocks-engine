<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\AssetMaterializationState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\TransformationEvidenceState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\TransformationProvenanceState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\LayoutGeometryState;
use Closure;
use DOMElement;

/**
 * Explicit collaborator surface for {@see SvgMaterializer}.
 *
 * Per-transform state is read from the compilation's typed session. Closures
 * remain only for transformer-owned operations.
 */
final class SvgMaterializationContext
{
    /**
     * @param Closure(DOMElement): ?string                                                         $reusableComponentFingerprintFor
     * @param Closure(DOMElement): string                                                          $safeFallbackHtml
     * @param Closure(DOMElement): string                                                          $sanitizeInlineSvgMarkup
     * @param Closure(DOMElement, string): array<string, mixed>                                     $svgArtworkBlock
     */
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly SourceBlockCreator $createBlock,
        private readonly Closure $reusableComponentFingerprintFor,
        private readonly Closure $safeFallbackHtml,
        private readonly Closure $sanitizeInlineSvgMarkup,
        private readonly Closure $svgArtworkBlock
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
        return $this->createBlock->createBlock($name, $attrs, $innerBlocks, $sourceElement, $logicalSourceElement);
    }

    public function isInlineContentElement(string $tagName): bool
    {
        return $this->sourceElementClassifier->isInlineContentElement($tagName);
    }

    public function isVisualLayerElement(DOMElement $element): bool
    {
        return $this->sourceElementClassifier->isVisualLayerElement($element);
    }

    public function layoutGeometry(): LayoutGeometryState
    {
        return $this->session->layoutGeometryState();
    }

    public function materializedAssets(): AssetMaterializationState
    {
        return $this->session->assetMaterializationState();
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

    /** @return array<string, mixed> */
    public function svgArtworkBlock(DOMElement $element, string $svg): array
    {
        return ($this->svgArtworkBlock)($element, $svg);
    }

    public function transformationEvidence(): TransformationEvidenceState
    {
        return $this->session->transformationEvidenceState();
    }

    public function transformationProvenance(): TransformationProvenanceState
    {
        return $this->session->transformationProvenanceState();
    }
}

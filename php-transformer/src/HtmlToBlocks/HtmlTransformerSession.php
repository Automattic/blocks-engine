<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackEmitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorSelectorProjectionState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorStyleAnalysis;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\GeneratedSupportStylesheetState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\LayoutGeometryState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\PresentationResolutionCache;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceStyleResolutionState;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Closure;
use DOMElement;

/**
 * Mutable data accumulated while converting one HTML document.
 *
 * A fresh session is installed for every transform so a reused transformer
 * retains only its immutable collaborators and shared analysis cache.
 */
final class HtmlTransformerSession
{
    private readonly TransformationEvidenceState $transformationEvidenceState;
    public array $responsiveImageFallbacks = array();
    public array $responsiveImageFallbackSelectors = array();
    private readonly TransformationProvenanceState $transformationProvenanceState;
    public array $scriptMetadata = array();
    private readonly RuntimeDomState $runtimeDomState;
    private readonly ReusableComponentState $reusableComponentState;
    public array $nativeDisclosureRootIds = array();
    private ?GeneratedBlockRegistry $generatedBlockRegistry = null;
    public bool $emptyRuntimeTargetGenerated = false;
    public array $runtimeScriptMetadata = array();
    private ?AssetMaterializationState $assetMaterializationState = null;
    private RuntimeSelectorState $runtimeSelectorState;
    private ?AuthorStyleAnalysis $authorStyleAnalysis = null;
    private readonly AuthorSelectorProjectionState $authorSelectorProjectionState;
    private readonly GeneratedSupportStylesheetState $generatedSupportStylesheetState;
    public bool $preserveShellLandmarks = false;
    public bool $fallbackReductionMode = false;
    public readonly PresentationResolutionCache $presentationResolutionCache;
    private readonly SourceStyleResolutionState $sourceStyleResolutionState;
    private ?LayoutGeometryState $layoutGeometryState = null;
    public readonly FallbackEmitter $fallbackEmitter;

    /** @param Closure(DOMElement): array<string, mixed> $sourceContextResolver */
    public function __construct(Runtime $runtime, Closure $sourceContextResolver)
    {
        $this->fallbackEmitter = new FallbackEmitter($runtime, $sourceContextResolver);
        $this->presentationResolutionCache = new PresentationResolutionCache();
        $this->sourceStyleResolutionState = new SourceStyleResolutionState();
        $this->runtimeDomState = new RuntimeDomState();
        $this->reusableComponentState = new ReusableComponentState();
        $this->authorSelectorProjectionState = new AuthorSelectorProjectionState();
        $this->generatedSupportStylesheetState = new GeneratedSupportStylesheetState();
        $this->transformationProvenanceState = new TransformationProvenanceState();
        $this->transformationEvidenceState = new TransformationEvidenceState();
        $this->runtimeSelectorState = new RuntimeSelectorState(array(), array(), array());
    }

    public function installAuthorStyleAnalysis(AuthorStyleAnalysis $analysis): void
    {
        $this->authorStyleAnalysis = $analysis;
        $this->authorSelectorProjectionState->installAuthorStyles($analysis);
    }

    public function authorStyleAnalysis(): ?AuthorStyleAnalysis
    {
        return $this->authorStyleAnalysis;
    }

    public function installLayoutGeometryState(LayoutGeometryState $state): void
    {
        $this->layoutGeometryState = $state;
    }

    public function layoutGeometryState(): ?LayoutGeometryState
    {
        return $this->layoutGeometryState;
    }

    public function installGeneratedBlockRegistry(GeneratedBlockRegistry $registry): void
    {
        $this->generatedBlockRegistry = $registry;
    }

    public function generatedBlockRegistry(): ?GeneratedBlockRegistry
    {
        return $this->generatedBlockRegistry;
    }

    public function installAssetMaterializationState(AssetMaterializationState $state): void
    {
        $this->assetMaterializationState = $state;
    }

    public function assetMaterializationState(): ?AssetMaterializationState
    {
        return $this->assetMaterializationState;
    }

    public function runtimeDomState(): RuntimeDomState
    {
        return $this->runtimeDomState;
    }

    public function installRuntimeSelectorState(RuntimeSelectorState $state): void
    {
        $this->runtimeSelectorState = $state;
    }

    public function runtimeSelectorState(): RuntimeSelectorState
    {
        return $this->runtimeSelectorState;
    }

    public function sourceStyleResolutionState(): SourceStyleResolutionState
    {
        return $this->sourceStyleResolutionState;
    }

    public function reusableComponentState(): ReusableComponentState
    {
        return $this->reusableComponentState;
    }

    public function authorSelectorProjectionState(): AuthorSelectorProjectionState
    {
        return $this->authorSelectorProjectionState;
    }

    public function generatedSupportStylesheetState(): GeneratedSupportStylesheetState
    {
        return $this->generatedSupportStylesheetState;
    }

    public function transformationProvenanceState(): TransformationProvenanceState
    {
        return $this->transformationProvenanceState;
    }

    public function transformationEvidenceState(): TransformationEvidenceState
    {
        return $this->transformationEvidenceState;
    }
}

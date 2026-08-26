<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackEmitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorSelectorProjectionState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorStyleAnalysis;
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
    public array $formControlEchoTexts = array();
    public array $responsiveImageFallbacks = array();
    public array $responsiveImageFallbackSelectors = array();
    public array $fallbackProvenance = array();
    public array $presentationProvenance = array();
    public array $frozenHiddenStateFindings = array();
    public array $droppedLinkWrapperFindings = array();
    public array $sourceProvenance = array();
    public array $sourceBaseHiddenStates = array();
    public array $formControlSlotPaths = array();
    public array $structureProvenance = array();
    public array $scriptMetadata = array();
    private readonly RuntimeDomState $runtimeDomState;
    private readonly ReusableComponentState $reusableComponentState;
    public array $nativeDisclosureRootIds = array();
    private ?GeneratedBlockRegistry $generatedBlockRegistry = null;
    public bool $emptyRuntimeTargetGenerated = false;
    public array $runtimeScriptMetadata = array();
    private ?AssetMaterializationState $assetMaterializationState = null;
    public array $nativeSearchTriggerCssRules = array();
    public array $nativeButtonStyleRules = array();
    public array $syntheticHeaderAnchorStyleRules = array();
    public array $headerRichTextStyleRules = array();
    public array $gutenbergIncompatibilities = array();
    public array $listNavigationPaddingFallbacks = array();
    public array $navigationLinkColorFallbacks = array();
    public array $navigationSubmenuBackgroundFallbacks = array();
    public array $navigationSpacingFallbacks = array();
    public array $buttonWrapperSpacingFallbacks = array();
    private RuntimeSelectorState $runtimeSelectorState;
    public array $directFlexButtonStyleRules = array();
    public array $fullWidthButtonStyleRules = array();
    public array $responsiveGeometryAmbiguities = array();
    public array $authorLayoutTopologies = array();
    private ?AuthorStyleAnalysis $authorStyleAnalysis = null;
    private readonly AuthorSelectorProjectionState $authorSelectorProjectionState;
    public int $nextSourceProvenanceId = 1;
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
}

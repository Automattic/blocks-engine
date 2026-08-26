<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackEmitter;
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
    public array $reusableComponentFingerprints = array();
    public array $generatedComponentCandidates = array();
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
    public array $cssCustomProperties = array();
    public array $staticClassPromotions = array();
    public array $staticStyleRules = array();
    public array $conditionalStyleRules = array();
    public array $navigationStateStyleRules = array();
    public array $listNavigationPaddingFallbacks = array();
    public array $navigationLinkColorFallbacks = array();
    public array $navigationSubmenuBackgroundFallbacks = array();
    public array $navigationSpacingFallbacks = array();
    public array $buttonWrapperSpacingFallbacks = array();
    public array $imageShapeStyleRules = array();
    public array $staticPseudoElementStyleRules = array();
    public array $runtimeDomSelectors = array();
    public array $runtimeBehavioralSelectors = array();
    public array $runtimeCanvasSelectors = array();
    public array $supersededRuntimeSelectors = array();
    public array $sourceTagMarkers = array();
    public array $sourceControlMarkers = array();
    public array $directFlexButtonStyleRules = array();
    public array $fullWidthButtonStyleRules = array();
    public array $sourceButtonPresentationMarkers = array();
    public array $sourceControlPaths = array();
    public array $sourceSemanticMarkers = array();
    public array $sourceAttributeMarkers = array();
    public array $sourceAttributeNegationMarkers = array();
    public array $sourceAttributeStateMarkers = array();
    public array $sourceRootChildMarkers = array();
    public array $responsiveGeometryAmbiguities = array();
    public array $sourceTableMarkers = array();
    public array $sourceTableRepresentability = array();
    public array $sourceTableDescendantPaths = array();
    public array $sourceRichTextSemanticMarkers = array();
    public array $authorLayoutTopologies = array();
    public array $parsedCssSelectors = array();
    public string $formLayoutCss = '';
    private ?AuthorStyleAnalysis $authorStyleAnalysis = null;
    public int $nextSourceProvenanceId = 1;
    public bool $preserveShellLandmarks = false;
    public bool $fallbackReductionMode = false;
    public readonly PresentationResolutionCache $presentationResolutionCache;
    public readonly SourceStyleResolutionState $sourceStyleResolutionState;
    private ?LayoutGeometryState $layoutGeometryState = null;
    public readonly FallbackEmitter $fallbackEmitter;

    /** @param Closure(DOMElement): array<string, mixed> $sourceContextResolver */
    public function __construct(Runtime $runtime, Closure $sourceContextResolver)
    {
        $this->fallbackEmitter = new FallbackEmitter($runtime, $sourceContextResolver);
        $this->presentationResolutionCache = new PresentationResolutionCache();
        $this->sourceStyleResolutionState = new SourceStyleResolutionState();
        $this->runtimeDomState = new RuntimeDomState();
    }

    public function installAuthorStyleAnalysis(AuthorStyleAnalysis $analysis): void
    {
        $this->authorStyleAnalysis = $analysis;
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
}

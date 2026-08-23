<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackEmitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssSelectorMatchCache;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\GeometryCarrierClassAllocator;
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
    public array $runtimeIslands = array();
    public array $runtimeDomPreservations = array();
    public array $runtimeDomFallbacks = array();
    public array $nativeDisclosureRootIds = array();
    public array $generatedBlocks = array();
    public bool $descriptionListBlockGenerated = false;
    public bool $formSelectBlockGenerated = false;
    public bool $formInputBlockGenerated = false;
    public bool $responsiveMediaBlockGenerated = false;
    public bool $authoredMarqueeBlockGenerated = false;
    public bool $emptyRuntimeTargetGenerated = false;
    public bool $capturedDialogBlockGenerated = false;
    public string $generatedBlockNamespace = 'custom';
    public string $generatedAssetRoot = '';
    public array $runtimeScriptMetadata = array();
    public array $assetMetadata = array();
    public array $generatedAssets = array();
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
    public array $sourceRootChildMarkers = array();
    public array $sourceBodyProjectionClasses = array();
    public array $responsiveGeometryAmbiguities = array();
    public array $sourceTableMarkers = array();
    public array $sourceTableRepresentability = array();
    public array $sourceTableDescendantPaths = array();
    public array $sourceRichTextSemanticMarkers = array();
    public array $authorLayoutTopologies = array();
    public bool $authorLayoutBlockGenerated = false;
    public string $combinedAuthorCss = '';
    public ?DOMElement $authorStyleSourceBody = null;
    public array $authorStyleSourceElements = array();
    public array $authorStyleSourceElementsByTag = array();
    public array $authorStyleSourceElementsById = array();
    public array $authorStyleSourceElementsByClass = array();
    public array $authorStyleSourceTags = array();
    public array $authorStyleSourceIds = array();
    public array $authorStyleSourceClasses = array();
    public array $authorSourceSelectorMatches = array();
    public array $parsedCssSelectors = array();
    public ?CssSelectorMatchCache $authorSelectorMatchCache = null;
    public array $authorSelectors = array();
    public array $authorStyleRules = array();
    public array $authorStyleRuleCandidateIndexes = array();
    public string $authorMarkerSeed = '';
    public int $authorMarkerCounter = 0;
    public string $authorMarkerCollisionText = '';
    public array $authorStylesheetAssets = array();
    public string $formLayoutCss = '';
    public string $authorSpecificityShim = '';
    public string $authorClassSpecificityShim = '';
    public string $authorIdSpecificityShim = '';
    public int $nextSourceProvenanceId = 1;
    public bool $preserveShellLandmarks = false;
    public bool $fallbackReductionMode = false;
    public array $presentationAttributesCache = array();
    public array $presentationDeclarationsCache = array();
    public array $mergedPresentationStyleCache = array();
    public array $mediaTextPresentationStyleCache = array();
    public array $generatedGeometryRules = array();
    public ?GeometryCarrierClassAllocator $geometryCarrierClassAllocator = null;
    public ?CssSelectorMatchCache $sourceSelectorMatchCache = null;
    public array $styleRuleCandidateIndexes = array();
    public array $authorDeclaredPropertyValuesCache = array();
    public readonly FallbackEmitter $fallbackEmitter;

    /** @param Closure(DOMElement): array<string, mixed> $sourceContextResolver */
    public function __construct(Runtime $runtime, Closure $sourceContextResolver)
    {
        $this->fallbackEmitter = new FallbackEmitter($runtime, $sourceContextResolver);
    }
}

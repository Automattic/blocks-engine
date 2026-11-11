<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\TransformOptions;
use Automattic\BlocksEngine\PhpTransformer\Contract\TransformationOptions;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatchCache;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\GeneratedBlockRegistry;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackEmitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorSelectorProjectionState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorStyleAnalysis;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\GeneratedSupportStylesheetState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\LayoutGeometryState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\PresentationResolutionCache;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceStyleResolutionState;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\SourceElementDescription;
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
    private readonly TransformationProvenanceState $transformationProvenanceState;
    private readonly RuntimeBehaviorState $runtimeBehaviorState;
    private readonly NavigationProjectionState $navigationProjectionState;
    private readonly SourceTargetProjectionState $sourceTargetProjectionState;
    private readonly RuntimeDomState $runtimeDomState;
    private readonly ReusableComponentState $reusableComponentState;
    private ?GeneratedBlockRegistry $generatedBlockRegistry = null;
    private ?AssetMaterializationState $assetMaterializationState = null;
    private RuntimeSelectorState $runtimeSelectorState;
    private ?AuthorStyleAnalysis $authorStyleAnalysis = null;
    private readonly AuthorSelectorProjectionState $authorSelectorProjectionState;
    private readonly GeneratedSupportStylesheetState $generatedSupportStylesheetState;
    private bool $preserveShellLandmarks = false;
    private bool $fallbackReductionMode = false;
    private string $sourcePath = '';
    private string $sourceRootTheme = '';
    /** @var list<string> */
    private array $declaredDocumentHosts = array();
    /** @var \SplObjectStorage<DOMElement, string>|null Captured `data-blocks-engine-capture-glyph-color` values keyed by glyph element. */
    private ?\SplObjectStorage $capturedGlyphColors = null;
    private readonly PresentationResolutionCache $presentationResolutionCache;
    private readonly SourceStyleResolutionState $sourceStyleResolutionState;
    private ?LayoutGeometryState $layoutGeometryState = null;
    private readonly FallbackEmitter $fallbackEmitter;

    public function __construct(Runtime $runtime, SourceElementDescription $sourceElementDescription)
    {
        $this->fallbackEmitter = new FallbackEmitter($runtime, $sourceElementDescription);
        $this->presentationResolutionCache = new PresentationResolutionCache();
        $this->sourceStyleResolutionState = new SourceStyleResolutionState();
        $this->runtimeDomState = new RuntimeDomState();
        $this->reusableComponentState = new ReusableComponentState();
        $this->authorSelectorProjectionState = new AuthorSelectorProjectionState();
        $this->generatedSupportStylesheetState = new GeneratedSupportStylesheetState();
        $this->transformationProvenanceState = new TransformationProvenanceState();
        $this->transformationEvidenceState = new TransformationEvidenceState();
        $this->runtimeBehaviorState = new RuntimeBehaviorState();
        $this->navigationProjectionState = new NavigationProjectionState();
        $this->sourceTargetProjectionState = new SourceTargetProjectionState();
        $this->runtimeSelectorState = new RuntimeSelectorState(array(), array(), array());
    }

    public function installAuthorStyleAnalysis(AuthorStyleAnalysis $analysis): void
    {
        $this->authorStyleAnalysis = $analysis;
        $this->authorSelectorProjectionState->installAuthorStyles($analysis);
    }

    public function authorStyleAnalysis(): AuthorStyleAnalysis
    {
        return $this->authorStyleAnalysis
            ?? throw new \LogicException('Author styles have not been prepared for this transform.');
    }

    public function authorStyleRuleCandidates(DOMElement $element, ?CssSelectorMatchCache $selectorCache = null): array
    {
        $index = $this->authorStyleAnalysis()->styleRuleCandidateIndex();
        $selectorCache ??= $this->sourceStyleResolutionState()->selectorMatchCache;
        return $selectorCache->styleRuleCandidates($element, 'author-rules', $index);
    }

    public function installLayoutGeometryState(LayoutGeometryState $state): void
    {
        $this->layoutGeometryState = $state;
    }

    public function layoutGeometryState(): LayoutGeometryState
    {
        return $this->layoutGeometryState
            ?? throw new \LogicException('Layout geometry state has not been prepared for this transform.');
    }

    public function installGeneratedBlockRegistry(GeneratedBlockRegistry $registry): void
    {
        $this->generatedBlockRegistry = $registry;
    }

    public function requireGeneratedBlockRegistry(): GeneratedBlockRegistry
    {
        return $this->generatedBlockRegistry
            ?? throw new \LogicException('Generated block registry has not been prepared for this transform.');
    }

    public function generatedBlockRegistry(): ?GeneratedBlockRegistry
    {
        return $this->generatedBlockRegistry;
    }

    public function installAssetMaterializationState(AssetMaterializationState $state): void
    {
        $this->assetMaterializationState = $state;
    }

    public function hasAssetMaterializationState(): bool
    {
        return null !== $this->assetMaterializationState;
    }

    public function assetMaterializationState(): AssetMaterializationState
    {
        return $this->assetMaterializationState
            ?? throw new \LogicException('Asset materialization state has not been prepared for this transform.');
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

    public function runtimeBehaviorState(): RuntimeBehaviorState
    {
        return $this->runtimeBehaviorState;
    }

    /** Records a native disclosure root and supersedes the local-disclosure runtime selectors inside it. */
    public function rememberNativeDisclosure(DOMElement $root): void
    {
        $this->runtimeBehaviorState->rememberNativeDisclosureRoot($root->getNodePath() ?? '');
        foreach ( array_merge(array( $root ), iterator_to_array($root->getElementsByTagName('*'), false)) as $node ) {
            if ( $node instanceof DOMElement && 'true' === strtolower($node->getAttribute(CaptureEvidence::LOCAL_DISCLOSURE)) ) {
                $this->runtimeSelectorState()->supersede('[' . CaptureEvidence::LOCAL_DISCLOSURE . ']');
                if ( '' !== $node->getAttribute('id') ) {
                    $this->runtimeSelectorState()->supersede('#' . $node->getAttribute('id'));
                }
            }
        }
    }

    /** Records the tab controls of the first tablist inside a native tabs root. */
    public function rememberNativeTabControls(DOMElement $tabsRoot): void
    {
        foreach ( $tabsRoot->childNodes as $child ) {
            if ( ! $child instanceof DOMElement || 'tablist' !== SourceDom::role($child) ) {
                continue;
            }
            foreach ( $child->getElementsByTagName('*') as $candidate ) {
                if ( $candidate instanceof DOMElement && 'tab' === SourceDom::role($candidate) ) {
                    $this->runtimeBehaviorState->rememberNativeTabControl($candidate->getNodePath() ?? '');
                }
            }
            break;
        }
    }

    public function navigationProjectionState(): NavigationProjectionState
    {
        return $this->navigationProjectionState;
    }

    public function sourceTargetProjectionState(): SourceTargetProjectionState
    {
        return $this->sourceTargetProjectionState;
    }

    /** Artifact path of the document being compiled, for example `website/about/index.html`. */
    public function installSourcePath(string $sourcePath): void
    {
        $this->sourcePath = $sourcePath;
    }

    public function sourcePath(): string
    {
        return $this->sourcePath;
    }

    /**
     * Facts the source document declares outside its body.
     *
     * @param string $rootTheme Theme class (`dark` or `light`) on the source `<html>`, or empty.
     * @param list<string> $declaredHosts Hosts the source document declares as its own.
     */
    public function installSourceDocument(string $rootTheme, array $declaredHosts): void
    {
        $this->sourceRootTheme = $rootTheme;
        $this->declaredDocumentHosts = $declaredHosts;
    }

    public function sourceRootTheme(): string
    {
        return $this->sourceRootTheme;
    }

    /** @return list<string> */
    public function declaredDocumentHosts(): array
    {
        return $this->declaredDocumentHosts;
    }

    /** @param \SplObjectStorage<DOMElement, string> $colors */
    public function installCapturedGlyphColors(\SplObjectStorage $colors): void
    {
        $this->capturedGlyphColors = $colors;
    }

    /** Captured rendered colour of a glyph element, or empty when there is none. */
    public function capturedGlyphColor(DOMElement $glyph): string
    {
        return null !== $this->capturedGlyphColors && isset($this->capturedGlyphColors[$glyph]) ? $this->capturedGlyphColors[$glyph] : '';
    }

    public function configurePolicy(bool $preserveShellLandmarks, bool $fallbackReductionMode): void
    {
        $this->preserveShellLandmarks = $preserveShellLandmarks;
        $this->fallbackReductionMode = $fallbackReductionMode;
    }

    public function preservesShellLandmarks(): bool
    {
        return $this->preserveShellLandmarks;
    }

    public function usesFallbackReductionMode(): bool
    {
        return $this->fallbackReductionMode;
    }

    public function presentationResolutionCache(): PresentationResolutionCache
    {
        return $this->presentationResolutionCache;
    }

    public function fallbackEmitter(): FallbackEmitter
    {
        return $this->fallbackEmitter;
    }

    /**
     * Install the per-transform state read from transform options: provenance,
     * generated block registry, asset materialization, policy, runtime
     * behavior and selector evidence, source path, and layout geometry proof.
     *
     * @param array<string, mixed> $options
     */
    public function installTransformOptions(array $options): void
    {
        $this->transformationProvenanceState()->installFallback(TransformationOptions::provenance($options));
        $this->installGeneratedBlockRegistry(new GeneratedBlockRegistry(TransformOptions::generatedBlockNamespaceFromOptions($options)));
        $this->installAssetMaterializationState(new AssetMaterializationState(
            trim((string) ($options['generated_asset_root'] ?? ''), '/'),
            TransformOptions::assetMetadataFromOptions($options)
        ));
        $this->configurePolicy(! empty($options['extract_global_shell']), ! empty($options['fallback_reduction_mode']));
        $this->runtimeBehaviorState()->installRuntimeScriptMetadata(TransformOptions::runtimeScriptMetadataFromOptions($options));
        $this->runtimeBehaviorState()->installRuntimeProjectionScriptAssets(
            is_array($options['runtime_projection_script_assets'] ?? null) ? $options['runtime_projection_script_assets'] : array()
        );
        $this->runtimeBehaviorState()->installThemePreferenceOwnership(
            is_array($options['theme_preference_ownership'] ?? null) ? $options['theme_preference_ownership'] : array()
        );
        $this->installSourcePath((string) ($options['source'] ?? 'html'));
        $this->installRuntimeSelectorState(new RuntimeSelectorState(
            TransformOptions::runtimeSelectorsFromOptions($options, 'runtime_dom_selectors'),
            TransformOptions::runtimeSelectorsFromOptions($options, 'runtime_behavioral_selectors'),
            TransformOptions::runtimeSelectorsFromOptions($options, 'runtime_canvas_selectors')
        ));
        $this->installLayoutGeometryState(new LayoutGeometryState(
            is_array($options['layout_geometry_proof']['reductions'] ?? null) ? $options['layout_geometry_proof']['reductions'] : array()
        ));
    }
}

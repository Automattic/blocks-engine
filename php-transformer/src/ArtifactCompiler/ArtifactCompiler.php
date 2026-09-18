<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Support\RuntimeSelectorVocabulary;
use Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\CssUrlRewriter;
use Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\ReferenceAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionReportProjection;
use Automattic\BlocksEngine\PhpTransformer\Contract\CoreHtmlFallbackEvidence;
use Automattic\BlocksEngine\PhpTransformer\Contract\BlockCompilationOutput;
use Automattic\BlocksEngine\PhpTransformer\Contract\EditabilityPolicy;
use Automattic\BlocksEngine\PhpTransformer\Contract\EditabilityReport;
use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use Automattic\BlocksEngine\PhpTransformer\FormatBridge\FormatBridge;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformerAnalysisCache;
use Automattic\BlocksEngine\PhpTransformer\Support\ShellLandmarkPolicy;
use Automattic\BlocksEngine\PhpTransformer\Css\AdminBarAccommodation;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssStylesheetChunker;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormPresentationGraphBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;
use Automattic\BlocksEngine\PhpTransformer\Support\DeterministicRowDeduplicator;
use Automattic\BlocksEngine\PhpTransformer\Support\StyleTagScanner;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use DOMDocument;
use DOMElement;

final class ArtifactCompiler
{
    use StagedTransport;

    private const MAX_PLAN_DIGEST_DEPTH = 512;

    public const INPUT_SCHEMA = 'blocks-engine/php-transformer/site-artifact/v1';
    // Plans are a published v1 transport contract. Receipts are additive: v2
    // carries terminal reductions while v1 receipts remain composable.
    public const SHARED_PLAN_SCHEMA = 'blocks-engine/php-transformer/staged-shared-plan/v1';
    public const PAGE_PLAN_SCHEMA = 'blocks-engine/php-transformer/staged-page-plan/v1';
    public const PAGE_RECEIPT_SCHEMA = 'blocks-engine/php-transformer/compiled-page-receipt/v1';
    public const COMPILED_RECEIPT_SCHEMA = 'blocks-engine/php-transformer/compiled-page-receipt/v2';
    public const COMPACT_RECEIPT_SCHEMA = 'blocks-engine/php-transformer/compiled-page-receipt/v3';

    /**
     * Tag-only script selectors whose native DOM shape can be behavior-bearing.
     *
     * @var array<int, string>
     */

	/** @var array<string, string> */
	private array $themeStaticCssCache = array();

	private WordPressCompatCss $wordpressCompat;

    private readonly RuntimeScriptEvidenceAnalyzer $runtimeScriptEvidenceAnalyzer;

    private ?HtmlTransformerAnalysisCache $htmlTransformerAnalysisCache = null;

    /** Observational only: excludes receipt cache hits. */
    private int $htmlDocumentTransformCount = 0;

    /** Observational only: counts source stylesheet discovery passes. */
    private int $stylesheetAssetDiscoveryCount = 0;

    public function __construct(
        private readonly bool $cacheHtmlAnalysis = true,
        private readonly int $stylesheetSelectorBudget = CssStylesheetChunker::MAX_SELECTOR_RECORDS
    )
    {
        $this->wordpressCompat = new WordPressCompatCss();
        $this->runtimeScriptEvidenceAnalyzer = new RuntimeScriptEvidenceAnalyzer();
    }

    /** @return array<string, int> */
    public function htmlAnalysisCacheMetrics(): array
    {
        $cache = $this->htmlTransformerAnalysisCache;
        if ( ! $cache instanceof HtmlTransformerAnalysisCache ) {
            return array();
        }

        return array(
            'style_builds' => $cache->styleBuilds,
            'style_hits' => $cache->styleHits,
            'style_evictions' => $cache->styleEvictions,
            'style_bytes' => $cache->styleBytes,
            'author_builds' => $cache->authorSelectorBuilds,
            'author_hits' => $cache->authorSelectorHits,
            'author_evictions' => $cache->authorSelectorEvictions,
            'author_bytes' => $cache->authorSelectorBytes,
            'stylesheet_asset_discoveries' => $this->stylesheetAssetDiscoveryCount,
        );
    }

    private string $generatedAssetRoot = '';

    /** @var array<string, array<string, mixed>> */
    private array $filesByPath = array();

    /** @var array<int, array<string, mixed>> */
    private array $imageFiles = array();

    /** @var array<int, string> */
    private array $scriptContents = array();

    /** @var array<string,mixed> Optional normalized proof for the current artifact. */
    private array $layoutGeometryProof = array();

    /**
     * Resolve the runtime selector context used when a caller converts one
     * source document or landmark separately from full artifact compilation.
     *
     * @param array<int|string, mixed> $files
     * @return array<string, mixed>
     */
    public function runtimeContextForSource(string $html, string $sourcePath, array $files): array
    {
        $normalized = ( new ArtifactNormalizer() )->normalize(array(
            'entrypoint' => $sourcePath,
            'files'      => $files,
        ));
        $this->indexFiles($normalized['files']);
        $runtimeDomSelectors = $this->runtimeDomSelectors($html, $sourcePath, $normalized['files']);

        return array(
            'runtime_script_metadata'  => $this->runtimeScriptMetadataForSource($html, $sourcePath, $normalized['files']),
            'runtime_dom_selectors'    => $runtimeDomSelectors,
            'runtime_behavioral_selectors' => $runtimeDomSelectors,
            'runtime_canvas_selectors' => $this->runtimeCanvasSelectors($html, $sourcePath, $normalized['files']),
        );
    }

    /**
     * @param array<string, mixed> $artifact
     */
    public function compile(array $artifact): TransformerResult
    {
        $this->htmlDocumentTransformCount = 0;
        return $this->compileArtifact((new ResponsiveDocumentVariants())->compose($artifact));
    }

    /**
     * Assemble a normalized artifact into its terminal result. Kept separate
     * from the public inline entry point so staged composition cannot recurse
     * through whole-artifact compilation.
     *
     * @param array<string, mixed> $artifact
     */
    private function compileArtifact(array $artifact): TransformerResult
    {
		$this->themeStaticCssCache = array();
		$this->wordpressCompat = new WordPressCompatCss();
        $this->htmlTransformerAnalysisCache = $this->cacheHtmlAnalysis ? new HtmlTransformerAnalysisCache() : null;
        $normalized = (new ArtifactNormalizer())->normalize($artifact);
        $this->layoutGeometryProof = is_array($normalized['layout_geometry_proof'] ?? null) ? $normalized['layout_geometry_proof'] : array();
        $capturedDialogs = (new CapturedDialogProjector())->project($normalized['files']);
        $scrollStates = (new ScrollStateProjector())->project($capturedDialogs['files']);
        $normalized['files'] = $scrollStates['files'];
        return $this->finalizeArtifact($artifact, array(
            'normalized' => $normalized,
            'inline_compilation' => true,
            // Both capture-time projectors run against the same source
            // documents before block conversion, so their diagnostics and
            // projection counts share one reporting bucket.
            'captured_dialogs' => array(
                'diagnostics' => array_merge($capturedDialogs['diagnostics'], $scrollStates['diagnostics']),
                'projected_count' => $capturedDialogs['projected_count'] + $scrollStates['projected_count'],
            ),
        ));
    }

    /**
     * Finalize collected facts into the canonical transformer envelope. Receipt
     * composition enters here only after all page payload access and content
     * work is done. WordPress site-plan production is derived afterward.
     *
     * @param array<string,mixed> $artifact
     * @param array<string,mixed> $reduction
     */
    private function finalizeArtifact(array $artifact, array $reduction): TransformerResult
    {
        $startedAt = hrtime(true);
        $normalized = $reduction['normalized'];
        $capturedDialogs = is_array($reduction['captured_dialogs'] ?? null) ? $reduction['captured_dialogs'] : array('diagnostics' => array(), 'projected_count' => 0);
        $entry = $this->entryFile($normalized['files'], $normalized['entrypoints']);
        $documents = is_array($reduction['source_documents'] ?? null) ? $reduction['source_documents'] : $this->compileSourceDocuments($normalized);
        $diagnostics = array_merge($this->operatorFacingNormalizationDiagnostics($normalized['diagnostics']), $capturedDialogs['diagnostics'], $documents['diagnostics'], $this->svgAssetDiagnostics($normalized['files']));

        if ( null === $entry && array() === $documents['documents'] ) {
            $diagnostics[] = $this->diagnostic('missing_entry_html', 'error', 'No HTML entry file was available to compile.');
        }

        $entryPath = is_array($entry) ? (string) $entry['path'] : '';
        $this->generatedAssetRoot = '.' === dirname($entryPath) ? '' : trim(dirname($entryPath), '/');
        $html = is_array($entry) ? (string) $entry['content'] : '';
        $components = is_array($reduction['components'] ?? null) ? $reduction['components'] : $this->detectComponents($normalized['files'], $entryPath, $documents['components']);
        $blockTypes = is_array($reduction['block_types'] ?? null) ? $reduction['block_types'] : $this->detectBlockTypes($normalized['files'], $diagnostics);
        $companionPluginPayloadBuilder = new CompanionPluginPayload();
        if (!empty($reduction['inline_compilation'])) $normalized['files'] = $this->withStylesheetOccurrenceAssets($html, $entryPath, $normalized['files']);
        $this->indexFiles($normalized['files']);
        $entryBlocks = is_array($reduction['entry_blocks'] ?? null) ? $reduction['entry_blocks'] : $this->compileEntryBlocks($html, $entryPath, $normalized['files'], $companionPluginPayloadBuilder->blockNamespace($artifact));
        $compiledHtmlDocuments = is_array($reduction['compiled_documents'] ?? null) ? $reduction['compiled_documents'] : $this->compileHtmlSourceDocuments($normalized['files'], $entryPath, $companionPluginPayloadBuilder->blockNamespace($artifact));
        $inlineShellCompilation = is_array($reduction['inline_shell_compilation'] ?? null)
            ? $reduction['inline_shell_compilation']
            : $this->compileSharedInlineShells($normalized['files'], $entryPath, $companionPluginPayloadBuilder->blockNamespace($artifact));
        $authorStylesheetProjections = array_merge(
            $entryBlocks['author_stylesheet_projections'],
            $inlineShellCompilation['author_stylesheet_projections'] ?? array()
        );
        $runtimeScriptProjections = array_merge(
            $entryBlocks['runtime_script_projections'],
            $inlineShellCompilation['runtime_script_projections'] ?? array()
        );
        $allDiagnostics = $this->entryTransformDiagnostics($entryBlocks['diagnostics'], $entryPath);
        $allFallbacks = $entryBlocks['fallbacks'];
        $allGeneratedBlocks = $entryBlocks['generated_blocks'];
        $allGutenbergGaps = $entryBlocks['gutenberg_gaps'];
        $coreHtmlFallbackEvidence = array($entryBlocks['core_html_fallback_evidence']);
        foreach ( $compiledHtmlDocuments as $sourcePath => $compiledHtmlDocument ) {
            $authorStylesheetProjections = array_merge($authorStylesheetProjections, $compiledHtmlDocument['author_stylesheet_projections'] ?? array());
            $runtimeScriptProjections = array_merge($runtimeScriptProjections, $compiledHtmlDocument['runtime_script_projections'] ?? array());
            $allDiagnostics = array_merge($allDiagnostics, $this->entryTransformDiagnostics($compiledHtmlDocument['diagnostics'] ?? array(), (string) $sourcePath));
            $allFallbacks = array_merge($allFallbacks, $compiledHtmlDocument['fallbacks'] ?? array());
            $allGeneratedBlocks = array_merge($allGeneratedBlocks, $compiledHtmlDocument['generated_blocks'] ?? array());
            $allGutenbergGaps = array_merge($allGutenbergGaps, $compiledHtmlDocument['gutenberg_gaps'] ?? array());
            $coreHtmlFallbackEvidence[] = $compiledHtmlDocument['core_html_fallback_evidence'] ?? array();
        }
        $allGutenbergGaps = $this->dedupeRows($allGutenbergGaps);
        $runtimeDeclarationDiagnostics = array();
        $runtimeEntityRecords = array();
        $normalized['runtime_declarations'] = $this->runtimeDeclarationsFromFallbacks($normalized['runtime_declarations'], $allFallbacks, $entryPath, $normalized['files'], $runtimeDeclarationDiagnostics, $runtimeEntityRecords);
        $normalized['files'] = $this->applyAuthorStylesheetProjections($normalized['files'], $authorStylesheetProjections, $entryBlocks['author_stylesheet_projections']);
        $normalized['files'] = $this->chunkProjectedStylesheets($normalized['files']);
        foreach ($normalized['files'] as $file) {
            if ($entryPath === ($file['path'] ?? null) && is_string($file['content'] ?? null)) {
                $html = $file['content'];
                break;
            }
        }
        $this->indexFiles($normalized['files']);
        $normalized['files'] = $this->applyRuntimeScriptProjections($normalized['files'], $runtimeScriptProjections);
        $runtimeIslandPackage = $this->applyRuntimeScriptPackageProjections(
            ( new RuntimeIslandPackageBuilder() )->fromRuntimeIslands($entryBlocks['runtime_islands'], $normalized['files'], $entryPath),
            $runtimeScriptProjections
        );
        $wordpressCompatAsset = $this->wordpressCompat->asset($normalized['files'], $this->themeStaticCss($normalized['files'], false), $this->allScriptContents($normalized['files']));
        $referenceReports = $this->referenceReports($normalized['files'], $entryPath);
        $manifestAssets = $this->assetManifest($normalized['files'], $entryPath, $referenceReports['asset_references'], $html);
        $entryOwnership = is_array($entry) ? $this->fileOwnership($entry) : array('scope' => 'page', 'id' => $entryPath);
        $generatedAssets = $this->generatedAssetsForDocuments($entryBlocks['assets'], $entryOwnership, $compiledHtmlDocuments, $normalized['files']);
        $generatedAssetIdentities = array();
        foreach ($generatedAssets as $asset) $generatedAssetIdentities[hash('sha256', (string) ($asset['path'] ?? '') . "\0" . (string) ($asset['content'] ?? ''))] = true;
        foreach ($inlineShellCompilation['assets'] as $asset) {
            $identity = hash('sha256', (string) ($asset['path'] ?? '') . "\0" . (string) ($asset['content'] ?? ''));
            if (isset($generatedAssetIdentities[$identity])) continue;
            $generatedAssetIdentities[$identity] = true; $generatedAssets[] = $asset;
        }
        $projectedAdminBarAsset = $this->projectedAdminBarAccommodationAsset($normalized['files']);
        if (null !== $projectedAdminBarAsset) {
            $generatedAssets[] = $projectedAdminBarAsset;
        }
        $beforeAuthorAssets = array_values(array_filter($generatedAssets, static fn (array $asset): bool => 'before-author' === ($asset['stylesheet_placement'] ?? '')));
        $afterAuthorAssets = array_values(array_filter($generatedAssets, static fn (array $asset): bool => 'after-author' === ($asset['stylesheet_placement'] ?? '')));
        $otherGeneratedAssets = array_values(array_filter($generatedAssets, static fn (array $asset): bool => ! in_array($asset, $beforeAuthorAssets, true) && ! in_array($asset, $afterAuthorAssets, true)));
        // Runtime loads the manifest in array order. Placement metadata keeps
        // engine support on its intended side of the authored stylesheets.
        $assets = array_merge($beforeAuthorAssets, $manifestAssets, $otherGeneratedAssets, $afterAuthorAssets);
        if ( null !== $wordpressCompatAsset ) {
            $assets[] = $wordpressCompatAsset;
        }
        $assets = $this->deduplicateVisualAssets($assets);
        $assets = $this->coalesceStylesheetAssets($assets);
        $diagnostics = array_merge($diagnostics, $allDiagnostics, $runtimeDeclarationDiagnostics);
        $serializedBlocks = $entryBlocks['serialized_blocks'];
        if ( '' === $serializedBlocks && ! empty($documents['documents'][0]['block_markup']) ) {
            $serializedBlocks = (string) $documents['documents'][0]['block_markup'];
        }
        $fallbackEvidence = CoreHtmlFallbackEvidence::merge($coreHtmlFallbackEvidence);
        $sourceReports = array(
            'core_html_fallback_evidence' => $fallbackEvidence,
            'reusable_components' => $this->reusableComponentEvidence($entryPath, $entryBlocks['reusable_components'], $compiledHtmlDocuments, $generatedAssets),
            'layout_geometry_proof' => array_merge($entryBlocks['layout_geometry_proof'] ?? array(), ...array_values(array_map(static fn(array $document): array => $document['layout_geometry_proof'] ?? array(), $compiledHtmlDocuments))),
            'artifact' => array(
                'schema'          => self::INPUT_SCHEMA,
                'original_schema' => is_string($artifact['schema'] ?? null) ? $artifact['schema'] : '',
                'entry_path'      => $entryPath,
                'entrypoints'     => $normalized['entrypoints'],
                'file_count'      => count($normalized['files']),
                'accepted_count'  => count($normalized['files']),
                'rejected_count'  => $normalized['rejected_count'],
                'bytes'           => $normalized['bytes'],
                'files_by_kind'   => $this->countBy($normalized['files'], 'kind'),
                'files_by_role'   => $this->countBy($normalized['files'], 'role'),
                'files_by_mime'   => $this->countBy($normalized['files'], 'mime_type'),
                'files_by_source' => $this->countBy($normalized['files'], 'source'),
                'files_by_intent' => $this->countBy($normalized['files'], 'intent'),
                'truncation_impact' => $normalized['truncation_impact'],
                'limits'          => array(
                    'max_files'       => $normalized['limits']['max_files'],
                    'max_file_bytes'  => $normalized['limits']['max_file_bytes'],
                    'max_total_bytes' => $normalized['limits']['max_total_bytes'],
                ),
                'source_hash'     => $normalized['source_hash'],
                'html'            => array(
                    'bytes'         => strlen($html),
                    'element_count' => preg_match_all('/<\s*[a-z][a-z0-9:-]*(?:\s|>|\/)/i', $html),
                ),
                'internal_links'    => $referenceReports['internal_links'],
                'asset_references'  => $referenceReports['asset_references'],
                'image_references'  => $referenceReports['image_references'],
                'runtime_declarations' => $normalized['runtime_declarations'],
            ),
        );
        if (0 < $capturedDialogs['projected_count']) {
            $sourceReports['captured_interactions'] = array(
                'schema' => 'blocks-engine/captured-interactions/v1',
                'projected_dialog_count' => $capturedDialogs['projected_count'],
            );
        }
        $compiledSite = $this->compiledSiteReport($normalized, $entryPath, $documents['documents'], $assets, $blockTypes, $serializedBlocks, $entryBlocks['shell_artifacts'], $compiledHtmlDocuments, $inlineShellCompilation['artifacts']);
        $compiledSite['runtime_entity_records'] = $runtimeEntityRecords;
        $sourceReports['compiled_site'] = $compiledSite;
        $identityFailures = WordPressSitePlan::compiledSiteIdentityFailures($compiledSite);
        foreach ( WordPressSitePlan::documentIdentityDiagnostics($identityFailures) as $identityDiagnostic ) {
            $diagnostics[] = array_merge($identityDiagnostic, array('source' => self::class));
        }
        $fileMetadata = array_column($normalized['files'], null, 'path');
        $entryFile = $fileMetadata[$entryPath] ?? array();
        $editabilityDocuments = array($entryPath => array('blocks' => $entryBlocks['blocks'], 'serialized_blocks' => $entryBlocks['serialized_blocks'], 'generated_carrier_css' => $this->cssAssetContent($entryBlocks['assets']), 'runtime_block_paths' => $entryBlocks['runtime_block_paths'], 'visual_block_paths' => $entryBlocks['visual_block_paths'], 'editability_report' => $entryBlocks['editability_report'], 'template_surface' => $entryFile['metadata']['template_surface'] ?? null, 'provenance' => $entryFile['provenance'] ?? null));
        foreach ($compiledHtmlDocuments as $sourcePath => $compiledHtmlDocument) {
            $sourceFile = $fileMetadata[$sourcePath] ?? array();
            $editabilityDocuments[(string) $sourcePath] = array(
                'blocks' => is_array($compiledHtmlDocument['blocks'] ?? null) ? $compiledHtmlDocument['blocks'] : array(),
                'serialized_blocks' => is_string($compiledHtmlDocument['serialized_blocks'] ?? null) ? $compiledHtmlDocument['serialized_blocks'] : '',
                'generated_carrier_css' => $this->cssAssetContent(is_array($compiledHtmlDocument['assets'] ?? null) ? $compiledHtmlDocument['assets'] : array()),
                'runtime_block_paths' => $compiledHtmlDocument['runtime_block_paths'],
                'visual_block_paths' => $compiledHtmlDocument['visual_block_paths'],
                'editability_report' => $compiledHtmlDocument['editability_report'],
                'template_surface' => $sourceFile['metadata']['template_surface'] ?? null,
                'provenance' => $sourceFile['provenance'] ?? null,
            );
        }
        $editabilityReport = (new EditabilityReport())->fromDocuments($editabilityDocuments);
        $editabilityPolicy = (new EditabilityPolicy())->evaluate($editabilityReport);
        $sourceReports['editability_report'] = $editabilityReport;
        $sourceReports['editability_policy'] = $editabilityPolicy;
        foreach ($editabilityPolicy['failures'] as $failure) {
            $diagnostics[] = $this->diagnostic('editability_policy_failed', 'error', (string) $failure['message'], array(
                'policy_schema' => EditabilityPolicy::SCHEMA,
                'metric' => $failure['metric'],
                'actual' => $failure['actual'],
                'maximum' => $failure['maximum'],
                'source_path' => $failure['source_path'] ?? '',
            ));
        }
        $responsiveCounterpartReports = array();
        foreach (array_merge(array($entryPath => $entryBlocks), $compiledHtmlDocuments) as $reportSourcePath => $compiledDocument) {
            if (is_array($compiledDocument['responsive_counterpart_contracts'] ?? null) && array() !== $compiledDocument['responsive_counterpart_contracts']) {
                $responsiveCounterpartReports[(string) $reportSourcePath] = $compiledDocument['responsive_counterpart_contracts'];
            }
        }
        if (array() !== $responsiveCounterpartReports) {
            $sourceReports['responsive_counterpart_contracts'] = $this->mergeResponsiveCounterpartContracts($responsiveCounterpartReports);
            $declaredCount = (int) ($sourceReports['responsive_counterpart_contracts']['metrics']['declared_count'] ?? 0);
            if (0 < $declaredCount) {
                $diagnostics[] = $this->diagnostic('responsive_counterparts_declared', 'info', sprintf('Declared %d responsive counterpart pair(s) from stable source provenance.', $declaredCount), array(
                    'schema' => \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\ResponsiveCorrespondence::SCHEMA,
                    'declared_count' => $declaredCount,
                ));
            }
        }
        if ( array() !== $allGutenbergGaps ) {
            $sourceReports['gutenberg_gaps'] = $allGutenbergGaps;
        }
        $editorScripts = array();
        $editorModule = $sourceReports['responsive_counterpart_contracts']['editor_module'] ?? null;
        if ( is_array($editorModule)
            && is_string($editorModule['handle'] ?? null)
            && is_string($editorModule['content'] ?? null)
            && is_array($editorModule['script_dependencies'] ?? null)
        ) {
            $editorScripts[] = array(
                'handle'       => $editorModule['handle'],
                'content'      => $editorModule['content'],
                'dependencies' => $editorModule['script_dependencies'],
            );
        }
        $themeOwnedRequiredScripts = RuntimeIslandPackageBuilder::themeOwnedRequiredScriptOccurrences($runtimeIslandPackage, $compiledSite['pages'] ?? array());
        $companionPluginPayload = $companionPluginPayloadBuilder->fromBlockTypes($blockTypes, $normalized['files'], $artifact, $allGeneratedBlocks, $runtimeIslandPackage, $editorScripts, $themeOwnedRequiredScripts);
        if ( array() !== $companionPluginPayload ) {
            $sourceReports['companion_plugin_payload'] = $companionPluginPayload;
        }
        if ( array() !== $entryBlocks['superseded_selectors'] ) {
            $sourceReports['superseded_selectors'] = $entryBlocks['superseded_selectors'];
        }
        $sourceReports['runtime_dependency_parity'] = ( new RuntimeDependencyParityReport($this->runtimeScriptEvidenceAnalyzer) )->fromArtifact($normalized['files'], $html, $serializedBlocks, $entryPath, $entryBlocks['runtime_islands'], $referenceReports['asset_references'], $entryBlocks['interaction_candidates'], $entryBlocks['superseded_selectors'], $allGeneratedBlocks);
        foreach ($sourceReports['runtime_dependency_parity']['findings'] ?? array() as $finding) {
            if ('runtime_dependency_target_missing' !== ($finding['code'] ?? '') || 'telemetry' === ($finding['script_kind'] ?? '')) {
                continue;
            }
            $diagnostics[] = $this->diagnostic('runtime_dependency_contract_failed', 'error', (string) ($finding['message'] ?? 'A required runtime DOM target is absent from generated markup.'), array_filter(array(
                'selector' => $finding['selector'] ?? null,
                'script_path' => $finding['script_path'] ?? null,
                'source_path' => $finding['source_path'] ?? null,
            ), static fn (mixed $value): bool => null !== $value && '' !== $value));
        }
        if ( array() !== $entryBlocks['runtime_islands'] ) {
            $sourceReports['runtime_islands'] = $entryBlocks['runtime_islands'];
            if ( array() !== $runtimeIslandPackage ) {
                $sourceReports['runtime_island_package'] = $runtimeIslandPackage;
            }
        }
        $provenance = array(
            array(
                'source_format' => 'artifact',
                'input_keys'    => $this->sourceOperationInputKeys($artifact),
                'source_hash'   => $normalized['source_hash'],
            ),
        );
        $sourceUrl = is_array($artifact['provenance'] ?? null) && is_string($artifact['provenance']['source_url'] ?? null)
            ? trim($artifact['provenance']['source_url'])
            : '';
        $sourceUrlParts = '' !== $sourceUrl ? parse_url($sourceUrl) : false;
        if ( is_array($sourceUrlParts) && in_array(strtolower((string) ($sourceUrlParts['scheme'] ?? '')), array( 'http', 'https' ), true) && '' !== (string) ($sourceUrlParts['host'] ?? '') && !isset($sourceUrlParts['user'], $sourceUrlParts['pass']) ) {
            $provenance[0]['source_url'] = $sourceUrl;
        }
        $metrics = array(
            'input_bytes'           => $normalized['bytes'],
            'block_count'           => $this->countBlocks($entryBlocks['blocks']),
            'fallback_count'        => \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackDiagnostic::countableFallbackCount($allFallbacks),
            'diagnostic_count'      => count($diagnostics),
            'transform_duration_ms' => (hrtime(true) - $startedAt) / 1000000,
            'output_bytes'          => strlen($serializedBlocks),
        );
        $sourceReports['conversion_report'] = ConversionReportProjection::fromResultParts('artifact', $entryBlocks['blocks'], $allFallbacks, $sourceReports, $assets, $provenance, $metrics);

        return ( new WordPressSitePlanComposer() )->compose(
            new TransformerResult(
                status: $this->statusFromDiagnostics($diagnostics),
                components: $components,
                blockTypes: $blockTypes,
                sourceReports: $sourceReports,
                blocks: $entryBlocks['blocks'],
                serializedBlocks: $serializedBlocks,
                documents: $documents['documents'],
                assets: $assets,
                diagnostics: $diagnostics,
                fallbacks: $allFallbacks,
                provenance: $provenance,
                metrics: $metrics
            ),
            array(
                // These counters describe process work and intentionally remain
                // out of canonical reports and WordPress site-plan equality.
                'html_document_transform_count' => $this->htmlDocumentTransformCount,
                'normalization_count' => !empty($reduction['inline_compilation']) ? 1 : 0,
                'analysis_count' => !empty($reduction['inline_compilation']) ? 1 : 0,
                'terminal_reduction_count' => 1,
            ),
            $startedAt
        );
    }

    /**
     * Detailed normalization warnings remain available on normalized artifacts
     * and staged plans. Terminal results expose their bounded aggregate only.
     *
     * @param array<int,array<string,mixed>> $diagnostics
     * @return array<int,array<string,mixed>>
     */
    private function operatorFacingNormalizationDiagnostics(array $diagnostics): array
    {
        $rejectionCodes = array_fill_keys(array(
            'file_limit_exceeded',
            'unsafe_artifact_path',
            'invalid_payload_reference',
            'invalid_base64_content',
            'missing_file_payload',
            'artifact_file_too_large',
            'artifact_total_too_large',
        ), true);
        return array_values(array_filter(
            $diagnostics,
            static fn(array $diagnostic): bool => !isset($rejectionCodes[$diagnostic['code'] ?? ''])
        ));
    }

    /** @param array<int,array<string,mixed>> $assets */
    private function cssAssetContent(array $assets): string
    {
        $content = array();
        foreach ($assets as $asset) if ('css' === ($asset['kind'] ?? '') && 'engine-support' === ($asset['source'] ?? '') && is_string($asset['content'] ?? null)) $content[] = $asset['content'];
        return implode("\n", $content);
    }

    /**
     * Merge per-document responsive counterpart contracts into one bounded
     * artifact report. Counterpart entries gain their owning source path so a
     * consumer can attribute every declared pair, and the editor module is
     * carried once.
     *
     * @param array<string, array<string, mixed>> $reports Keyed by source path.
     * @return array<string, mixed>
     */
    private function mergeResponsiveCounterpartContracts(array $reports): array
    {
        $merged = array(
            'schema' => \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\ResponsiveCorrespondence::SCHEMA,
            'pairing_rule' => 'stable_source_id_and_variant_structure_only',
            'counterparts' => array(),
            'metrics' => array('declared_count' => 0, 'declined_count' => 0, 'document_count' => count($reports)),
        );
        $editorModule = array();
        ksort($reports, SORT_STRING);
        foreach ($reports as $sourcePath => $report) {
            foreach (is_array($report['counterparts'] ?? null) ? $report['counterparts'] : array() as $counterpart) {
                if (!is_array($counterpart)) {
                    continue;
                }
                $counterpart['source_path'] = (string) $sourcePath;
                $merged['counterparts'][] = $counterpart;
            }
            foreach (is_array($report['declined'] ?? null) ? $report['declined'] : array() as $declinedEntry) {
                if (is_array($declinedEntry)) {
                    $declinedEntry['source_path'] = (string) $sourcePath;
                    $merged['declined'][] = $declinedEntry;
                }
            }
            foreach (array('declared_count', 'declined_count') as $metric) {
                $merged['metrics'][$metric] += (int) ($report['metrics'][$metric] ?? 0);
            }
            if (array() === $editorModule && is_array($report['editor_module'] ?? null)) {
                $editorModule = $report['editor_module'];
            }
        }
        if (array() !== $merged['counterparts']) {
            usort($merged['counterparts'], static function (array $left, array $right): int {
                return [$left['source_path'], $left['token']] <=> [$right['source_path'], $right['token']];
            });
        }
        if (array() !== $editorModule) {
            $merged['editor_module'] = $editorModule;
        }
        return $merged;
    }

    /** @param array<int,array<string,mixed>> $files @param array<int,array<string,mixed>> $runtimeDeclarations */
    private function normalizedSourceHash(array $files, array $runtimeDeclarations): string
    {
        usort($files, static fn(array $left, array $right): int => strcmp((string) $left['path'], (string) $right['path']));
        $context = hash_init('sha256');
        foreach ($files as $file) {
            $content = isset($file['content_base64']) ? (string) $file['content_base64'] : (isset($file['payload_reference']) ? (string) $file['payload_reference']['sha256'] : (string) ($file['content'] ?? ''));
            hash_update($context, $file['path'] . "\0" . $file['kind'] . "\0" . ($file['mime_type'] ?? '') . "\0");
            hash_update($context, $content);
            hash_update($context, "\0");
        }
        hash_update($context, "\n" . RuntimeDeclarations::canonicalJson($runtimeDeclarations));
        return hash_final($context);
    }

    /** @param array<string,mixed> $file @return array{scope:string,id:string} */
    private function fileOwnership(array $file): array
    {
        $ownership = $file['metadata']['compilation'] ?? null;
        if (null === $ownership) {
            if (in_array(($file['kind'] ?? null), array('html', 'markdown', 'mdx'), true)) {
                return array('scope' => 'page', 'id' => (string) $file['path']);
            }
            // Inline styles/scripts expanded out of an unannotated page must
            // follow that page: parking page-varying content in the immutable
            // shared plan would invalidate every page plan on a page edit.
            $inlineSource = ArtifactNormalizer::inlineExpansionSourcePath($file);
            if ('' !== $inlineSource) {
                return array('scope' => 'page', 'id' => $inlineSource);
            }
            return array('scope' => 'shared', 'id' => '');
        }
        if (!is_array($ownership) || !is_string($ownership['scope'] ?? null) || !in_array($ownership['scope'], array('shared', 'page'), true)) {
            throw new \InvalidArgumentException('File compilation ownership requires a shared or page scope.');
        }
        if ('shared' === $ownership['scope']) {
            if (isset($ownership['id'])) {
                throw new \InvalidArgumentException('Shared file compilation ownership cannot declare a page id.');
            }
            return array('scope' => 'shared', 'id' => '');
        }
        if (!is_string($ownership['id'] ?? null) || '' === trim($ownership['id']) || strlen($ownership['id']) > 255) {
            throw new \InvalidArgumentException('Page file compilation ownership requires a bounded nonblank page id.');
        }
        return array('scope' => 'page', 'id' => $ownership['id']);
    }

    /**
     * @param array<int,array<string,mixed>> $entryAssets
     * @param array{scope:string,id:string} $entryOwnership
     * @param array<string,array<string,mixed>> $compiledHtmlDocuments
     * @param array<int,array<string,mixed>> $files
     * @return array<int,array<string,mixed>>
     */
    private function generatedAssetsForDocuments(array $entryAssets, array $entryOwnership, array $compiledHtmlDocuments, array $files): array
    {
        $assets = array();
        $assetIndexes = array();
        $append = static function (array $documentAssets, array $ownership) use (&$assets, &$assetIndexes): void {
            foreach ( $documentAssets as $asset ) {
                if ( ! is_array($asset) ) {
                    continue;
                }
                if ( 'css' === ($asset['kind'] ?? null) ) {
                    $asset['compilation'] ??= $ownership;
                }
                $payload = is_string($asset['visual_payload'] ?? null) ? $asset['visual_payload'] : (is_string($asset['content_base64'] ?? null) ? $asset['content_base64'] : (string) ($asset['content'] ?? ''));
                $identity = hash('sha256', (string) ($asset['path'] ?? '') . "\0" . $payload);
                if ( ! isset($assetIndexes[$identity]) ) {
                    $assetIndexes[$identity] = count($assets);
                    $assets[] = $asset;
                    continue;
                }
                $index = $assetIndexes[$identity];
                if (is_string($asset['visual_payload'] ?? null)) {
                    $assets[$index]['content'] = $asset['visual_payload'];
                    $assets[$index]['bytes'] = strlen($asset['visual_payload']);
                    $assets[$index]['hash'] = hash('sha256', $asset['visual_payload']);
                    $assets[$index]['source_hash'] = $assets[$index]['hash'];
                    $assets[$index]['visual_payload'] = $asset['visual_payload'];
                }
                $occurrences = is_array($assets[$index]['component_occurrences'] ?? null) ? $assets[$index]['component_occurrences'] : array();
                foreach (is_array($asset['component_occurrences'] ?? null) ? $asset['component_occurrences'] : array() as $occurrence) {
                    if (count($occurrences) < 8 && !in_array($occurrence, $occurrences, true)) $occurrences[] = $occurrence;
                }
                if (array() !== $occurrences) $assets[$index]['component_occurrences'] = $occurrences;
                $counts = is_array($assets[$index]['component_occurrence_counts'] ?? null) ? $assets[$index]['component_occurrence_counts'] : array();
                foreach (is_array($asset['component_occurrence_counts'] ?? null) ? $asset['component_occurrence_counts'] : array() as $fingerprint => $count) if (is_string($fingerprint) && is_int($count)) $counts[$fingerprint] = (int) ($counts[$fingerprint] ?? 0) + $count;
                if (array() !== $counts) $assets[$index]['component_occurrence_counts'] = $counts;
                $assets[$index]['component_occurrences_omitted'] = max(0, array_sum($counts) - count($occurrences));
                if ( 'css' !== ($asset['kind'] ?? null) ) {
                    continue;
                }
                $existingOwnership = $assets[$index]['compilation'] ?? null;
                $assetOwnership = $asset['compilation'] ?? null;
                if ( $existingOwnership !== $assetOwnership ) {
                    $assets[$index]['compilation'] = array('scope' => 'shared');
                }
            }
        };

        $append($entryAssets, $entryOwnership);
        $filesByPath = array_column($files, null, 'path');
        foreach ( $compiledHtmlDocuments as $sourcePath => $compiledHtmlDocument ) {
            $file = $filesByPath[$sourcePath] ?? array('path' => $sourcePath, 'kind' => 'html');
            $append(
                is_array($compiledHtmlDocument['assets'] ?? null) ? $compiledHtmlDocument['assets'] : array(),
                $this->fileOwnership($file)
            );
        }

        return $assets;
    }

    /** @param array<int, array<string, mixed>> $assets @return array<int, array<string, mixed>> */
    private function deduplicateVisualAssets(array $assets): array
    {
        $deduplicated = array();
        $indexes = array();
        foreach ($assets as $asset) {
            $payload = is_string($asset['visual_payload'] ?? null) ? $asset['visual_payload'] : null;
            $canonicalPayload = $payload ?? (string) ($asset['content'] ?? '');
            if ('inline-svg' === ($asset['source'] ?? null)) {
                // The content-addressed filename is already the visual payload
                // identity. Some later projections retain only the public asset
                // fields, so use that stable path at this final boundary too.
                $payload = '';
            }
            if (null === $payload) {
                $deduplicated[] = $asset;
                continue;
            }
            $identity = hash('sha256', (string) ($asset['path'] ?? '') . "\0" . $payload);
            if (!isset($indexes[$identity])) {
                $indexes[$identity] = count($deduplicated);
                $deduplicated[] = $asset;
                continue;
            }
            $index = $indexes[$identity];
            $deduplicated[$index]['content'] = $canonicalPayload;
            $deduplicated[$index]['bytes'] = strlen($canonicalPayload);
            $deduplicated[$index]['hash'] = hash('sha256', $canonicalPayload);
            $deduplicated[$index]['source_hash'] = $deduplicated[$index]['hash'];
            $rows = is_array($deduplicated[$index]['component_occurrences'] ?? null) ? $deduplicated[$index]['component_occurrences'] : array();
            $incomingRows = is_array($asset['component_occurrences'] ?? null) ? $asset['component_occurrences'] : array();
            $alreadyIncluded = array() !== $incomingRows;
            foreach ($incomingRows as $row) {
                if (!in_array($row, $rows, true)) $alreadyIncluded = false;
                if (count($rows) < 8 && !in_array($row, $rows, true)) $rows[] = $row;
            }
            $deduplicated[$index]['component_occurrences'] = $rows;
            if (!$alreadyIncluded) foreach (is_array($asset['component_occurrence_counts'] ?? null) ? $asset['component_occurrence_counts'] : array() as $fingerprint => $count) if (is_string($fingerprint) && is_int($count)) $deduplicated[$index]['component_occurrence_counts'][$fingerprint] = (int) ($deduplicated[$index]['component_occurrence_counts'][$fingerprint] ?? 0) + $count;
            $deduplicated[$index]['component_occurrences_omitted'] = max(0, array_sum(is_array($deduplicated[$index]['component_occurrence_counts'] ?? null) ? $deduplicated[$index]['component_occurrence_counts'] : array()) - count($rows));
        }
        return $deduplicated;
    }

    /**
     * Coalesce only adjacent stylesheet assets with the same runtime contract.
     * Keeping the run contiguous preserves the existing cascade order while
     * bounding bootstrap records for fragmented inline author styles.
     *
     * @param array<int,array<string,mixed>> $assets
     * @return array<int,array<string,mixed>>
     */
    private function coalesceStylesheetAssets(array $assets): array
    {
        $coalesced = array();
        $run = array();
        $runKey = '';
        $flush = static function () use (&$coalesced, &$run, &$runKey): void {
            if ( array() === $run ) {
                return;
            }
            if ( 1 === count($run) ) {
                $coalesced[] = $run[0];
                $run = array();
                $runKey = '';
                return;
            }
            $content = implode("\n", array_map(static fn (array $asset): string => rtrim((string) $asset['content']) . "\n", $run));
            $hash = hash('sha256', $content);
            $pathHash = hash('sha256', $runKey . "\0" . $content);
            $bundle = $run[0];
            $bundle['source'] = 'stylesheet-bundle';
            $bundle['path'] = 'assets/css/stylesheet-bundle-' . substr($pathHash, 0, 16) . '.css';
            $bundle['target_path'] = $bundle['path'];
            $bundle['content'] = $content;
            $bundle['bytes'] = strlen($content);
            $bundle['hash'] = $hash;
            $bundle['source_hash'] = $hash;
            $bundle['source_paths'] = array_values(array_map(static fn (array $asset): string => (string) ($asset['path'] ?? ''), $run));
            $bundle['source_hashes'] = array_values(array_map(static fn (array $asset): string => (string) ($asset['hash'] ?? ''), $run));
            $coalesced[] = $bundle;
            $run = array();
            $runKey = '';
        };
        foreach ( $assets as $asset ) {
            if ( ! $this->isCoalescibleStylesheetAsset($asset) ) {
                $flush();
                $coalesced[] = $asset;
                continue;
            }
            $key = $this->stylesheetBundleKey($asset);
            if ( array() !== $run && $key !== $runKey ) {
                $flush();
            }
            $run[] = $asset;
            $runKey = $key;
        }
        $flush();
        return $coalesced;
    }

    /** @param array<string,mixed> $asset */
    private function isCoalescibleStylesheetAsset(array $asset): bool
    {
        return 'css' === ($asset['kind'] ?? null)
            && 'stylesheet' === ($asset['role'] ?? null)
            && 'inline-style' === ($asset['source'] ?? null)
            && is_string($asset['content'] ?? null)
            && '' !== (string) $asset['content'];
    }

    /** @param array<string,mixed> $asset */
    private function stylesheetBundleKey(array $asset): string
    {
        return json_encode(array(
            'compilation' => $asset['compilation'] ?? array('scope' => 'shared'),
            'source' => $asset['source'] ?? '',
            'target' => $asset['stylesheet_target'] ?? 'both',
            'placement' => $asset['stylesheet_placement'] ?? '',
            'media' => $asset['media'] ?? '',
        ), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $entryEvidence @param array<string, array<string, mixed>> $documents @param array<int, array<string, mixed>> $assets */
    private function reusableComponentEvidence(string $entryPath, array $entryEvidence, array $documents, array $assets): array
    {
        $candidates = array();
        $append = static function (string $sourcePath, array $evidence) use (&$candidates): void {
            foreach (is_array($evidence['candidates'] ?? null) ? $evidence['candidates'] : array() as $candidate) {
                if (is_array($candidate) && is_string($candidate['fingerprint'] ?? null) && is_string($candidate['path'] ?? null) && is_string($candidate['tag'] ?? null)) $candidates[$candidate['fingerprint']][] = array('source_path' => $sourcePath, 'path' => $candidate['path'], 'tag' => $candidate['tag']);
            }
        };
        $append($entryPath, $entryEvidence);
        foreach ($documents as $sourcePath => $document) $append((string) $sourcePath, is_array($document['reusable_components'] ?? null) ? $document['reusable_components'] : array());
        $mapped = array();
        foreach ($assets as $asset) foreach (is_array($asset['component_occurrence_counts'] ?? null) ? $asset['component_occurrence_counts'] : array() as $fingerprint => $count) if (is_string($fingerprint) && is_int($count)) $mapped[$fingerprint] = (int) ($mapped[$fingerprint] ?? 0) + $count;
        $components = array();
        foreach ($candidates as $fingerprint => $occurrences) {
            if (count($occurrences) < 2) continue;
            $tag = $occurrences[0]['tag'];
            $mappedCount = (int) ($mapped[$fingerprint] ?? 0);
            $retained = min(count($occurrences), 8);
            $omitted = count($occurrences) - $retained;
            $components[] = array('fingerprint' => $fingerprint, 'tag' => $tag, 'occurrence_count' => count($occurrences), 'mapping' => 'svg' === $tag && $mappedCount === count($occurrences) ? 'shared_core_image_asset' : ('svg' === $tag ? 'capability_gap:svg_instances_not_all_core_image_assets' : 'capability_gap:no_safe_reusable_block_mapping'), 'mapped_asset_occurrence_count' => $mappedCount, 'occurrence_limit' => 8, 'retained_occurrence_count' => $retained, 'omitted_occurrence_count' => $omitted, 'truncated' => 0 < $omitted, 'truncation_reason' => 0 < $omitted ? 'max_occurrences' : '', 'incomplete' => 0 < $omitted, 'occurrences' => array_slice($occurrences, 0, 8));
        }
        usort($components, static fn(array $a, array $b): int => $b['occurrence_count'] <=> $a['occurrence_count'] ?: strcmp($a['fingerprint'], $b['fingerprint']));
        $documentScans = array();
        $scan = static function (string $sourcePath, array $evidence) use (&$documentScans): void { $documentScans[] = array('source_path' => $sourcePath, 'scanned_node_count' => (int) ($evidence['scanned_node_count'] ?? 0), 'candidate_count' => (int) ($evidence['candidate_count'] ?? 0), 'omitted_candidate_count' => (int) ($evidence['omitted_candidate_count'] ?? 0), 'truncated' => array_values(is_array($evidence['truncated'] ?? null) ? $evidence['truncated'] : array())); };
        $scan($entryPath, $entryEvidence);
        foreach ($documents as $sourcePath => $document) $scan((string) $sourcePath, is_array($document['reusable_components'] ?? null) ? $document['reusable_components'] : array());
        $truncated = array_values(array_unique(array_merge(...array_map(static fn(array $scan): array => $scan['truncated'], $documentScans))));
        $componentOmitted = max(0, count($components) - 32);
        $documentOmitted = max(0, count($documentScans) - 64);
        if (0 < $componentOmitted) $truncated[] = 'max_components';
        if (0 < $documentOmitted) $truncated[] = 'max_documents';
        if (array_filter($components, static fn(array $component): bool => !empty($component['incomplete']))) $truncated[] = 'max_occurrences';
        $truncated = array_values(array_unique($truncated));
        return array('schema' => 'blocks-engine/reusable-component-recognition/v1', 'limits' => array('max_components' => 32, 'max_documents' => 64), 'retained_component_count' => min(count($components), 32), 'omitted_component_count' => $componentOmitted, 'components' => array_slice($components, 0, 32), 'scanned_node_count' => array_sum(array_column($documentScans, 'scanned_node_count')), 'candidate_count' => array_sum(array_column($documentScans, 'candidate_count')), 'omitted_candidate_count' => array_sum(array_column($documentScans, 'omitted_candidate_count')), 'retained_document_count' => min(count($documentScans), 64), 'omitted_document_count' => $documentOmitted, 'truncated' => $truncated, 'incomplete' => array() !== $truncated, 'documents' => array_slice($documentScans, 0, 64));
    }

    /**
     * @param array<int,array<string,mixed>> $files
     * @return array<int,array<string,mixed>>
     */
    private static function sortedByPath(array $files): array
    {
        usort($files, static fn(array $left, array $right): int => strcmp((string) ($left['path'] ?? ''), (string) ($right['path'] ?? '')));
        return $files;
    }

    /** @param array<string,mixed> $artifact @return array<int,string> */
    private function sourceOperationInputKeys(array $artifact): array
    {
        $sourceOperation = $artifact['source_operation'] ?? null;
        $inputKeys = is_array($sourceOperation) ? ($sourceOperation['input_keys'] ?? null) : null;
        if (is_array($sourceOperation) && 'blocks-engine/php-transformer/source-operation/v1' === ($sourceOperation['schema'] ?? null) && is_array($inputKeys) && array_is_list($inputKeys)) {
            foreach ($inputKeys as $key) {
                if (!is_string($key) || '' === $key) {
                    return array_values(array_filter(array_keys($artifact), 'is_string'));
                }
            }
            return $inputKeys;
        }
        return array_values(array_filter(array_keys($artifact), 'is_string'));
    }

    /**
     * Promote provider-materializable findings into the canonical runtime contract.
     *
     * Explicit caller declarations remain authoritative. Detected entities fill
     * only missing product/form collections and their matching dependencies.
     *
     * @param array<int,array<string,mixed>> $declarations
     * @param array<int,array<string,mixed>> $fallbacks
     * @return array<int,array<string,mixed>>
     */
    private function runtimeDeclarationsFromFallbacks(array $declarations, array $fallbacks, string $entryPath, array $files, array &$diagnostics = array(), array &$runtimeEntityRecords = array()): array
    {
        if ( '' === $entryPath ) return $declarations;
        foreach ( $declarations as $declaration ) foreach ( $declaration['payload']['entities'] ?? array() as $entity ) if ( is_array($entity) && array_key_exists('superseded_scripts', $entity) ) throw new \InvalidArgumentException('Caller runtime declarations cannot provide compiler-reserved script supersession proofs.');

        $keys = array();
        foreach ( $declarations as $declaration ) {
            if ( ! is_array($declaration) ) continue;
            $name = $declaration['type'] ?? $declaration['capability'] ?? null;
            if ( is_string($declaration['kind'] ?? null) && is_string($name) ) $keys[$declaration['kind'] . ':' . $name] = true;
        }

        $slug = static function (string $value): string {
            $value = strtolower(trim($value));
            $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
            return trim($value, '-');
        };
        $price = static function (mixed $value): string {
            if ( ! is_scalar($value) ) return '';
            $clean = preg_replace('/[^0-9.,]/', '', trim((string) $value)) ?? '';
            if ( '' === $clean ) return '';
            $commaCount = substr_count($clean, ',');
            $dotCount = substr_count($clean, '.');
            $decimal = '';
            if ( 0 < $commaCount && 0 < $dotCount ) $decimal = strrpos($clean, ',') > strrpos($clean, '.') ? ',' : '.';
            elseif ( 1 === $commaCount ) { $tail = strlen(substr($clean, (int) strrpos($clean, ',') + 1)); if ( 1 <= $tail && 2 >= $tail ) $decimal = ','; }
            elseif ( 1 === $dotCount ) { $tail = strlen(substr($clean, (int) strrpos($clean, '.') + 1)); if ( 1 <= $tail && 2 >= $tail ) $decimal = '.'; }
            if ( '' === $decimal ) return ltrim(preg_replace('/[^0-9]/', '', $clean) ?? '', '0') ?: '0';
            $parts = explode($decimal, $clean); $fraction = preg_replace('/[^0-9]/', '', (string) array_pop($parts)) ?? ''; $integer = ltrim(preg_replace('/[^0-9]/', '', implode('', $parts)) ?? '', '0') ?: '0';
            return strlen($fraction) > 2 ? number_format((float) ($integer . '.' . $fraction), 2, '.', '') : $integer . '.' . str_pad($fraction, 2, '0');
        };

        $products = array();
        $forms = array();
        foreach ( $fallbacks as $fallback ) {
            if ( ! is_array($fallback) ) continue;
            $code = (string) ($fallback['diagnostic_code'] ?? $fallback['kind'] ?? '');
            $sourcePath = is_string($fallback['source'] ?? null) ? $fallback['source'] : $entryPath;
            if ( 'html_product_grid_fallback' === $code ) {
                $container = is_string($fallback['container_selector'] ?? null) ? $fallback['container_selector'] : (is_string($fallback['selector'] ?? null) ? $fallback['selector'] : '');
                foreach ( is_array($fallback['products'] ?? null) ? $fallback['products'] : array() as $product ) {
                    if ( ! is_array($product) ) continue;
                    $name = is_scalar($product['name'] ?? null) ? trim((string) $product['name']) : '';
                    $productSlug = $slug(is_scalar($product['slug'] ?? null) ? (string) $product['slug'] : $name);
                    $regularPrice = $price($product['price'] ?? null);
                    if ( '' === $name || '' === $productSlug || '' === $regularPrice ) continue;
                    $row = array('name' => $name, 'slug' => $productSlug, 'regular_price' => $regularPrice);
                    $salePrice = $price($product['sale_price'] ?? null); if ( '' !== $salePrice ) $row['sale_price'] = $salePrice;
                    if ( is_scalar($product['description'] ?? null) && '' !== trim((string) $product['description']) ) $row['description'] = (string) $product['description'];
                    $image = is_string($product['image'] ?? null) ? $product['image'] : (is_array($product['image'] ?? null) && is_string($product['image']['src'] ?? null) ? $product['image']['src'] : '');
                    if ( '' !== trim($image) ) $row['image'] = $image;
                    $sourceSelector = is_string($product['source_selector'] ?? null) ? trim($product['source_selector']) : '';
                    $selectors = array_values(array_unique(array_filter(array($sourceSelector, $container), static fn(mixed $selector): bool => is_string($selector) && '' !== trim($selector))));
                    if ( array() !== $selectors ) $row['source_selectors'] = $selectors;
                    // This is the compiler's exact product-card identity. Consumers
                    // must not infer a leaf selector from diagnostic presentation data.
                    if ( '' !== $sourceSelector ) {
                        $row['source_path'] = $sourcePath;
                        $row['selector'] = $sourceSelector;
                    }
                    if ( is_array($product['binding'] ?? null) && 'generic/block-binding/v1' === ($product['binding']['schema'] ?? null) && is_string($product['binding']['search_block_markup'] ?? null) && '' !== trim($product['binding']['search_block_markup']) ) {
                        $row['bindings'] = array(array_merge($product['binding'], array('source_path' => $sourcePath)));
                    }
                    if ( isset($products[$productSlug]) ) {
                        if ( ! isset($row['bindings'][0]) ) {
                            continue;
                        }
                        $binding = $row['bindings'][0];
                        $claim = $binding['source_path'] . "\n" . hash('sha256', $binding['search_block_markup']) . "\n" . $binding['occurrence'];
                        $existingBindings = is_array($products[$productSlug]['bindings'] ?? null) ? $products[$productSlug]['bindings'] : array();
                        $existingClaims = array_map(static fn(array $existing): string => $existing['source_path'] . "\n" . hash('sha256', $existing['search_block_markup']) . "\n" . $existing['occurrence'], $existingBindings);
                        if (!in_array($claim, $existingClaims, true)) $products[$productSlug]['bindings'][] = $binding;
                        continue;
                    }
                    $products[$productSlug] = $row;
                }
            } elseif ( 'html_form_fallback' === $code && is_array($fallback['controls'] ?? null) ) {
                $selector = is_string($fallback['selector'] ?? null) ? $fallback['selector'] : '';
                // Only a finding carrying data-entry metadata describes a form a
                // provider could materialize, so only that finding is worth
                // reporting when a contract stops it from being declared.
                $declarable = array() !== array_filter(
                    array_filter($fallback['controls'], 'is_array'),
                    static fn (array $control): bool => in_array(strtolower((string) ($control['tag'] ?? '')), array('input', 'select', 'textarea'), true)
                        && ! in_array(strtolower((string) ($control['type'] ?? '')), array('button', 'image', 'reset', 'submit'), true)
                );
                if ( true === ($fallback['control_topology']['truncated'] ?? false) ) { if ( $declarable ) $diagnostics[] = $this->declinedFormDeclarationDiagnostic($fallback, $sourcePath, $selector, 'control_topology_truncated', 'its bounded control topology was truncated, so the control graph is incomplete'); continue; }
                $form = array('selector' => $selector, 'source_path' => $sourcePath, 'form' => is_array($fallback['form'] ?? null) ? $fallback['form'] : array(), 'controls' => array_values(array_filter($fallback['controls'], 'is_array')));
                foreach (array('fallback_identity', 'reconciliation_identity') as $identityKey) if (is_string($fallback[$identityKey] ?? null) && preg_match('/^[a-f0-9]{64}$/', $fallback[$identityKey])) $form[$identityKey] = $fallback[$identityKey];
                if ( is_array($fallback['control_topology'] ?? null) ) $form['control_topology'] = $fallback['control_topology'];
                if ( is_array($fallback['sibling_relations'] ?? null) && true !== ($fallback['sibling_relations']['truncated'] ?? false) ) $form['sibling_relations'] = $fallback['sibling_relations'];
                if ( is_array($fallback['layout_graph'] ?? null) && true !== ($fallback['layout_graph']['truncated'] ?? false) ) { FormLayoutGraphBuilder::assertValid($fallback['layout_graph']); $form['layout_graph'] = $fallback['layout_graph']; }
                if ( is_array($fallback['presentation_graph'] ?? null) && true !== ($fallback['presentation_graph']['truncated'] ?? false) ) { FormPresentationGraphBuilder::assertValid($fallback['presentation_graph']); $form['presentation_graph'] = $fallback['presentation_graph']; }
                if ( is_array($fallback['binding'] ?? null) && 'generic/block-binding/v1' === ($fallback['binding']['schema'] ?? null) && is_string($fallback['binding']['search_block_markup'] ?? null) && '' !== trim($fallback['binding']['search_block_markup']) ) {
                    $form['bindings'] = array(array_merge($fallback['binding'], array('source_path' => $sourcePath)));
                }
                if ( ! isset($form['bindings']) ) { if ( $declarable ) $diagnostics[] = $this->declinedFormDeclarationDiagnostic($fallback, $sourcePath, $selector, 'page_owned_binding_anchor_missing', 'it has no page-owned block binding anchor, so a provider cannot locate the converted form in the page'); continue; }
                $supersededScripts = $this->supersededFormScripts($fallback, $files, $sourcePath);
                if ( array() !== $supersededScripts ) $form['superseded_scripts'] = $supersededScripts;
                $forms[$sourcePath . "\n" . $selector] = $form;
            }
        }
        ksort($products, SORT_STRING); ksort($forms, SORT_STRING);
        $collections = array(
            'shop' => array('type' => 'products', 'aliases' => array('product', 'products'), 'entities' => array_values($products), 'schema' => 'generic/products/v1'),
            'form' => array('type' => 'forms', 'aliases' => array('form', 'forms'), 'entities' => array_values($forms), 'schema' => 'generic/forms/v1'),
        );
        foreach ( $collections as $capability => $collection ) {
            $entityKey = 'entity_collection:' . $collection['type'];
            foreach ( $collection['aliases'] as $alias ) if ( isset($keys['entity_collection:' . $alias]) ) { $entityKey = 'entity_collection:' . $alias; break; }
            if ( array() !== $collection['entities'] && ! isset($keys[$entityKey]) ) {
                if ( 'forms' === $collection['type'] ) {
                    $budget = $this->budgetGeneratedForms($declarations, $collection['entities'], $entryPath, $entityKey, isset($keys['dependency:' . $capability]));
                    $collection['entities'] = $budget['entities'];
                    if (isset($budget['records'])) $runtimeEntityRecords = array_merge($runtimeEntityRecords, $budget['records']);
                    if (isset($budget['payload'])) $collection['payload'] = $budget['payload'];
                    if ( null !== $budget['diagnostic'] ) $diagnostics[] = $budget['diagnostic'];
                }
                if ( array() === $collection['entities'] ) continue;
                $declarations[] = array('kind' => 'entity_collection', 'type' => $collection['type'], 'source_path' => $entryPath, 'payload' => $collection['payload'] ?? array('schema' => $collection['schema'], 'entities' => $collection['entities']));
                $keys[$entityKey] = true;
            }
            $dependencyKey = 'dependency:' . $capability;
            if ( isset($keys[$entityKey]) && ! isset($keys[$dependencyKey]) ) {
                $declarations[] = array('kind' => 'dependency', 'capability' => $capability, 'source_path' => $entryPath, 'required_for' => array($entityKey));
                $keys[$dependencyKey] = true;
            }
        }
        return RuntimeDeclarations::normalizeList($declarations);
    }

    /**
     * Name the contract that stopped prepared form metadata from reaching the
     * runtime declarations. A declined form is otherwise invisible: the
     * `html_form_fallback` finding still reports extracted controls while the
     * `generic/forms/v1` entity that a provider materializes never appears.
     *
     * @param array<string,mixed> $fallback
     * @return array<string,mixed>
     */
    private function declinedFormDeclarationDiagnostic(array $fallback, string $sourcePath, string $selector, string $failedCheck, string $because): array
    {
        $controls = array_values(array_filter(is_array($fallback['controls'] ?? null) ? $fallback['controls'] : array(), 'is_array'));
        return array(
            'code' => 'runtime_form_declaration_declined',
            'severity' => 'warning',
            'message' => substr('Extracted form metadata was declined before the runtime form entity declaration because ' . $because . '.', 0, 256),
            'source_path' => substr($sourcePath, 0, 256),
            'selector' => substr($selector, 0, 256),
            'failed_check' => $failedCheck,
            'control_count' => count($controls),
            'entity_schema' => 'generic/forms/v1',
            'reason_code' => 'runtime_form_declaration_declined',
            'pattern_family' => 'interactive_form',
            'repair_bucket' => 'materialize_form_provider',
            'suggested_repair_class' => 'materialize_form_provider',
            'source' => self::class,
        );
    }

    /**
     * Keep generated form declarations within the same canonical limit enforced
     * for caller declarations, without ever trimming a JSON entity in place.
     *
     * @param array<int,array<string,mixed>> $declarations
     * @param array<int,array<string,mixed>> $forms
     * @return array{entities:array<int,array<string,mixed>>,payload?:array<string,mixed>,records?:array<int,array<string,mixed>>,diagnostic:array<string,mixed>|null}
     */
    private function budgetGeneratedForms(array $declarations, array $forms, string $entryPath, string $entityKey, bool $hasDependency): array
    {
        $fits = static function (array $entities) use ($declarations, $entryPath, $entityKey, $hasDependency): bool {
            $candidate = array_merge($declarations, array(array('kind' => 'entity_collection', 'type' => 'forms', 'source_path' => $entryPath, 'payload' => array('schema' => 'generic/forms/v1', 'entities' => $entities))));
            if ( ! $hasDependency ) $candidate[] = array('kind' => 'dependency', 'capability' => 'form', 'source_path' => $entryPath, 'required_for' => array($entityKey));
            try {
                RuntimeDeclarations::normalizeList($candidate);
                return true;
            } catch (\InvalidArgumentException $error) {
                if (str_contains($error->getMessage(), 'payload exceeds the byte limit') || str_contains($error->getMessage(), 'aggregate canonical byte limit')) return false;
                throw $error;
            }
        };
        if ( $fits($forms) ) return array('entities' => $forms, 'diagnostic' => null);

        $manifest = RuntimeEntityManifest::fromEntities('generic/forms/v1', $forms);
        $candidate = array_merge($declarations, array(array('kind' => 'entity_collection', 'type' => 'forms', 'source_path' => $entryPath, 'payload' => $manifest['payload'])));
        if (!$hasDependency) $candidate[] = array('kind' => 'dependency', 'capability' => 'form', 'source_path' => $entryPath, 'required_for' => array($entityKey));
        RuntimeDeclarations::normalizeList($candidate);
        return array('entities' => $forms, 'payload' => $manifest['payload'], 'records' => $manifest['records'], 'diagnostic' => null);
    }

    /** @param array<string,mixed> $fallback @param array<int,array<string,mixed>> $files @return array<int,array<string,string>> */
    private function supersededFormScripts(array $fallback, array $files, string $sourcePath): array
    {
        $ownedIds = array();
        foreach ( array_merge(array($fallback['form'] ?? array()), is_array($fallback['controls'] ?? null) ? $fallback['controls'] : array()) as $row ) {
            if ( is_array($row) && is_string($row['id'] ?? null) && '' !== trim($row['id']) ) $ownedIds[$row['id']] = true;
        }
        if ( is_string($fallback['html'] ?? null) && preg_match_all('/\bid\s*=\s*["\']([^"\']+)["\']/i', $fallback['html'], $matches) ) foreach ( $matches[1] as $id ) $ownedIds[(string) $id] = true;
        if ( array() === $ownedIds ) return array();
        $formId = is_array($fallback['form'] ?? null) && is_string($fallback['form']['id'] ?? null) ? trim($fallback['form']['id']) : '';
        if ( '' === $formId ) return array();
        $targetSelector = '#' . $formId;

        $superseded = array();
        foreach ( $files as $file ) {
            if ( !is_array($file) || 'inline-script' !== ($file['source'] ?? null) || $sourcePath !== ($file['source_path'] ?? null) || $targetSelector !== ($file['superseded_by'] ?? null) || !is_string($file['selector'] ?? null) || !is_string($file['content'] ?? null) ) continue;
            $script = trim($file['content']);
            if ( '' === $script || preg_match('/\b(?:window|globalThis|fetch|XMLHttpRequest|WebSocket|EventSource|navigator|localStorage|sessionStorage|indexedDB|eval|import|createElement|appendChild|insertBefore)\b|document\s*\.\s*(?:cookie|location)/i', $script) ) continue;
            if ( !preg_match_all('/document\s*\.\s*getElementById\s*\(\s*["\']([^"\']+)["\']\s*\)/i', $script, $lookups) || array() !== array_diff(array_unique($lookups[1]), array_keys($ownedIds)) ) continue;
            $ownedVariables = array();
            if ( preg_match_all('/\b(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*document\s*\.\s*getElementById\s*\(\s*["\']([^"\']+)["\']\s*\)/i', $script, $assignments, PREG_SET_ORDER) ) foreach ( $assignments as $assignment ) if ( isset($ownedIds[$assignment[2]]) ) $ownedVariables[$assignment[1]] = true;
            $withoutOwnedLookups = preg_replace('/document\s*\.\s*getElementById\s*\(\s*["\'][^"\']+["\']\s*\)/i', '', $script) ?? $script;
            if ( preg_match('/\[\s*["\'][A-Za-z_$][A-Za-z0-9_$]*["\']\s*\]/', $withoutOwnedLookups) ) continue;
            if ( preg_match('/\bdocument\s*\./i', $withoutOwnedLookups) ) continue;
            if ( preg_match_all('/(?<![.\w$])([A-Za-z_$][A-Za-z0-9_$]*)\s*\(/', $withoutOwnedLookups, $calls) && array_diff(array_unique($calls[1]), array('if', 'for', 'while', 'switch', 'catch', 'function')) ) continue;
            if ( preg_match_all('/\.\s*([A-Za-z_$][A-Za-z0-9_$]*)\s*\(/', $withoutOwnedLookups, $memberCalls) && array_diff(array_unique($memberCalls[1]), array('addEventListener', 'preventDefault', 'querySelector', 'querySelectorAll', 'trim', 'forEach')) ) continue;
            if ( preg_match('/\.\s*(?:parentElement|parentNode|ownerDocument|children|firstElementChild|lastElementChild|nextElementSibling|previousElementSibling)\b/i', $withoutOwnedLookups) ) continue;
            if ( preg_match_all('/([A-Za-z_$][A-Za-z0-9_$]*(?:\s*\.\s*[A-Za-z_$][A-Za-z0-9_$]*)*)\s*\.\s*querySelector(?:All)?\s*\(/', $withoutOwnedLookups, $queries) && array_diff(array_map(static fn(string $receiver): string => preg_replace('/\s+/', '', $receiver) ?? $receiver, array_unique($queries[1])), array_keys($ownedVariables)) ) continue;
            if ( preg_match_all('/\.\s*([A-Za-z_$][A-Za-z0-9_$]*(?:\s*\.\s*[A-Za-z_$][A-Za-z0-9_$]*)?)\s*=/', $withoutOwnedLookups, $writes) && array_diff(array_map(static fn(string $property): string => preg_replace('/\s+/', '', $property) ?? $property, array_unique($writes[1])), array('textContent', 'style.background', 'style.borderColor', 'style.display', 'value')) ) continue;
            $superseded[] = array('schema' => 'blocks-engine/provider-script-supersession/v1', 'source_path' => $sourcePath, 'selector' => $file['selector'], 'asset_source_path' => $file['path'], 'body_hash' => hash('sha256', $script), 'target_selector' => $targetSelector, 'reason' => 'provider_binding_replaces_form_behavior');
        }
        usort($superseded, static fn(array $left, array $right): int => strcmp($left['body_hash'], $right['body_hash']));
        return $superseded;
    }

    /**
     * Compile a standalone source fragment through the canonical format bridge.
     *
     * @param array<string,mixed> $options Transformer context/provenance options.
     */
    public function compileFragment(string $content, string $source = 'fragment', string $format = 'html', array $options = array()): TransformerResult
    {
        $bridge = new FormatBridge();
        return $bridge->convertResult($content, $format, 'blocks', array_merge(array(
            'source'       => $source,
            'source_scope' => 'artifact-fragment',
        ), $options));
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array{blocks: array<int, array<string, mixed>>, serialized_blocks: string, diagnostics: array<int, array<string, mixed>>, fallbacks: array<int, array<string, mixed>>, assets: array<int, array<string, mixed>>, runtime_islands: array<int, array<string, mixed>>, generated_blocks: array<int, array<string, mixed>>, gutenberg_gaps: array<int, array<string, mixed>>, interaction_candidates: array<int, array<string, mixed>>, superseded_selectors: array<int, string>, author_stylesheet_projections: array<int, array<string, mixed>>, runtime_script_projections: array<int, array<string, mixed>>, shell_artifacts: array<int, array<string, mixed>>, core_html_fallback_evidence: array<string, mixed>}
     */
    private function compileEntryBlocks(string $html, string $entryPath, array $files, string $generatedBlockNamespace = ''): array
    {
        $result = $this->compileHtmlDocumentBlocks($html, $entryPath, $files, 'artifact-entry', $generatedBlockNamespace, true);

        return array(
            'blocks'            => $result['blocks'],
            'serialized_blocks' => $result['serialized_blocks'],
            'diagnostics'       => $result['diagnostics'],
            'fallbacks'         => $result['fallbacks'],
            'assets'            => $result['assets'],
            'runtime_islands'   => $result['runtime_islands'],
            'generated_blocks'  => $result['generated_blocks'],
            'gutenberg_gaps'    => $result['gutenberg_gaps'],
            'interaction_candidates' => $result['interaction_candidates'],
            'superseded_selectors' => $result['superseded_selectors'],
            'author_stylesheet_projections' => $result['author_stylesheet_projections'],
            'runtime_script_projections' => $result['runtime_script_projections'],
            'shell_artifacts' => $result['shell_artifacts'],
            'core_html_fallback_evidence' => $result['core_html_fallback_evidence'],
            'runtime_block_paths' => $result['runtime_block_paths'],
            'visual_block_paths' => $result['visual_block_paths'],
            'editability_report' => $result['editability_report'],
            'responsive_counterpart_contracts' => $result['responsive_counterpart_contracts'],
            'reusable_components' => $result['reusable_components'],
            'layout_geometry_proof' => $result['layout_geometry_proof'],
        );
    }

    private function compileHtmlDocumentBlocks(string $html, string $sourcePath, array $files, string $sourceScope, string $generatedBlockNamespace = '', bool $extractGlobalShell = false): array
    {
        ++$this->htmlDocumentTransformCount;
        $preserveBlockMarkup = $this->containsBlockMarkup($html);
        if ( $preserveBlockMarkup || '' === trim($html) ) {
            return array(
                'blocks'            => array(),
                'serialized_blocks' => $preserveBlockMarkup ? $html : '',
                'diagnostics'       => array(),
                'fallbacks'         => array(),
                'assets'            => array(),
                'runtime_islands'   => array(),
                'generated_blocks'  => array(),
                'gutenberg_gaps'    => array(),
                'interaction_candidates' => array(),
                'superseded_selectors' => array(),
                'author_stylesheet_projections' => array(),
                'runtime_script_projections' => array(),
                'shell_artifacts' => array(),
                'core_html_fallback_evidence' => CoreHtmlFallbackEvidence::fromBlocks(array(), array(), array()),
                'reusable_components' => array(),
                'runtime_block_paths' => array(),
                'visual_block_paths' => array(),
                'editability_report' => null,
                'responsive_counterpart_contracts' => array(),
                'layout_geometry_proof' => array(),
            );
        }

        $stylesheetAssets = $this->stylesheetAssetsForSource($html, $sourcePath, $files);
        $stylesheetPayloads = $this->linkedStylesheetPayloads($stylesheetAssets, $sourcePath, $files);
        $analysisCache = $this->cacheHtmlAnalysis
            ? $this->htmlTransformerAnalysisCache ??= new HtmlTransformerAnalysisCache()
            : new HtmlTransformerAnalysisCache();
        $runtimeDomSelectors = $this->runtimeDomSelectors($html, $sourcePath, $files);
        $runtimeProjectionSelectors = $this->runtimeProjectionSelectors($html, $sourcePath, $files);
        $result = (new HtmlTransformer(analysisCache: $analysisCache))->transform($this->safeHtmlDocumentHtml($html, $sourcePath, $files), array(
            'source'                    => $sourcePath,
            'source_scope'              => $sourceScope,
            'declarative_state_html'    => $html,
            'static_css'                => trim(implode("\n", array_column($stylesheetPayloads, 'content'))),
            'stylesheet_payloads'       => $stylesheetPayloads,
            'author_stylesheet_assets'  => $stylesheetAssets,
            'shared_stylesheet_paths'   => $this->sharedStylesheetPathsFromFiles($files),
            'skip_author_stylesheet_materialization' => true,
            'asset_metadata'            => $this->assetMetadataForSource($sourcePath, $files),
            'runtime_script_metadata'   => $this->runtimeScriptMetadataForSource($html, $sourcePath, $files),
            'runtime_dom_selectors'     => $runtimeDomSelectors,
            'runtime_behavioral_selectors' => $runtimeDomSelectors,
            'runtime_projection_selectors' => $runtimeProjectionSelectors,
            'runtime_projection_script_assets' => $this->runtimeProjectionScriptAssetsForSource($html, $sourcePath, $files),
            'runtime_canvas_selectors'  => $this->runtimeCanvasSelectors($html, $sourcePath, $files),
            'generated_block_namespace' => $generatedBlockNamespace,
            'generated_asset_root'       => $this->generatedAssetRoot,
            'extract_global_shell'       => $extractGlobalShell,
            'layout_geometry_proof'      => $this->layoutGeometryProofForSource($files, $sourcePath),
        ));
        $blockCompilationOutput = $result->blockCompilationOutput;
        if (!$blockCompilationOutput instanceof BlockCompilationOutput) {
            throw new \LogicException('HTML compilation must provide BlockCompilationOutput.');
        }

        return array(
            'blocks'            => $result->blocks,
            'serialized_blocks' => $result->serializedBlocks,
            'diagnostics'       => $result->diagnostics,
            'fallbacks'         => $result->fallbacks,
            'core_html_fallback_evidence' => $blockCompilationOutput->coreHtmlFallbackEvidence,
            'runtime_block_paths' => $blockCompilationOutput->runtimeBlockPaths,
            'visual_block_paths' => $blockCompilationOutput->visualBlockPaths,
            'editability_report' => $blockCompilationOutput->editabilityReport,
            'responsive_counterpart_contracts' => $blockCompilationOutput->responsiveCounterpartContracts,
            'layout_geometry_proof' => $blockCompilationOutput->layoutGeometryProof,
            'reusable_components' => $blockCompilationOutput->reusableComponents,
            'assets'            => $result->assets,
            'runtime_islands'   => $this->runtimeIslandsWithMaterializedInlineScripts(
                $blockCompilationOutput->runtimeIslands,
                $sourcePath,
                $files
            ),
            'generated_blocks'  => $blockCompilationOutput->generatedBlocks,
            'gutenberg_gaps'    => $blockCompilationOutput->gutenbergGaps,
            'interaction_candidates' => $blockCompilationOutput->interactionCandidates,
            'superseded_selectors' => array_values(array_filter(
                $blockCompilationOutput->supersededSelectors,
                static fn (mixed $selector): bool => is_string($selector) && '' !== $selector
            )),
            'author_stylesheet_projections' => $blockCompilationOutput->authorStylesheetProjections,
            'runtime_script_projections' => $blockCompilationOutput->runtimeScriptProjections,
            'shell_artifacts' => $blockCompilationOutput->shellArtifacts,
        );
    }

    /** @param array<int,array<string,mixed>> $files @return array<string,mixed> */
    private function layoutGeometryProofForSource(array $files, string $sourcePath): array
    {
        foreach ($files as $file) {
            if ($sourcePath !== ($file['path'] ?? null) || 'html' !== ($file['kind'] ?? null) || !is_string($file['content'] ?? null)) continue;
            $proof = is_array($file['layout_geometry_proof'] ?? null) ? $file['layout_geometry_proof'] : $this->layoutGeometryProof;
            $reductions = array_values(array_filter($proof['reductions'] ?? array(), static fn(mixed $reduction): bool => is_array($reduction) && $sourcePath === ($reduction['source_path'] ?? null) && hash('sha256', $file['content']) === ($reduction['source_hash'] ?? null)));
            return array() === $reductions ? array() : array('schema' => LayoutGeometryProof::SCHEMA, 'reductions' => $reductions);
        }
        return array();
    }

    /**
     * @param array<int, array<string, mixed>> $runtimeIslands
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function runtimeIslandsWithMaterializedInlineScripts(array $runtimeIslands, string $sourcePath, array $files): array
    {
        $inlineScripts = array_values(array_filter($files, fn (mixed $file): bool => is_array($file) && 'inline-script' === ($file['source'] ?? '') && $sourcePath === ($file['source_path'] ?? '') && $this->isMaterializedScriptAsset($file)));
        foreach ( $runtimeIslands as &$runtimeIsland ) {
            if ( ! is_array($runtimeIsland) || 'script' === ($runtimeIsland['kind'] ?? '') ) {
                continue;
            }
            $requiredScripts = is_array($runtimeIsland['required_scripts'] ?? null) ? $runtimeIsland['required_scripts'] : array();
            foreach ( $inlineScripts as $file ) {
                $content = is_scalar($file['content'] ?? null) ? trim((string) $file['content']) : '';
                if ( '' === $content || ! $this->inlineScriptReferencesRuntimeIsland($content, $runtimeIsland) ) {
                    continue;
                }
                $requiredScripts[] = array_filter(array(
                    'script_source_kind' => 'inline',
                    'script_role'        => 'first_party',
                    'selector'           => is_scalar($file['selector'] ?? null) ? (string) $file['selector'] : '',
                    'script_body'        => $content,
                    'body_bytes'         => strlen($content),
                    'body_truncated'     => false,
                    'attributes'         => $this->inlineScriptAttributes($file),
                ), static fn (mixed $value): bool => null !== $value && '' !== $value && array() !== $value);
            }
            $runtimeIsland['required_scripts'] = SourceDom::dedupeArrayRows($requiredScripts);
        }
        unset($runtimeIsland);

        foreach ( $files as $file ) {
            if ( ! is_array($file) || 'inline-script' !== ($file['source'] ?? '') || $sourcePath !== ($file['source_path'] ?? '') || ! $this->isMaterializedScriptAsset($file) ) {
                continue;
            }

            $selector = is_scalar($file['selector'] ?? null) ? (string) $file['selector'] : '';
            $content = is_scalar($file['content'] ?? null) ? trim((string) $file['content']) : '';
            if ( '' === $selector || '' === $content || $this->hasRuntimeIsland($runtimeIslands, 'script', $selector) ) {
                continue;
            }

            $attributes = $this->inlineScriptAttributes($file);

            $attributeHtml = '';
            foreach ( $attributes as $name => $value ) {
                if ( $name === $value ) {
                    $attributeHtml .= ' ' . $name;
                    continue;
                }
                $attributeHtml .= ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            }

            $runtimeIslands[] = array_filter(array(
                'kind'                => 'script',
                'selector'            => $selector,
                'tag'                 => 'script',
                'diagnostic_code'     => 'preserved_runtime_island',
                'preservation_reason' => 'script_requires_runtime',
                'runtime_requirement' => 'client_script_execution',
                'disposition'         => 'preserve',
                'preservation_status' => 'accepted_runtime_preservation',
                'js_handling'         => 'preserve_verbatim',
                'source_snippet'      => '<script' . $attributeHtml . '></script>',
                'source_bytes'        => strlen($content),
                'source_truncated'    => false,
                'attributes'          => $attributes,
                'script_role'         => 'first_party',
                'script_source_kind'  => 'inline',
                'script_body'         => $content,
                'body_bytes'          => strlen($content),
                'body_truncated'      => false,
                'required_assets'     => array(),
                'required_scripts'    => array(),
            ), static fn (mixed $value): bool => null !== $value && '' !== $value && array() !== $value);
        }

        return $runtimeIslands;
    }

    /**
     * @param array<int, array<string, mixed>> $runtimeIslands
     */
    private function hasRuntimeIsland(array $runtimeIslands, string $kind, string $selector): bool
    {
        foreach ( $runtimeIslands as $island ) {
            if ( is_array($island) && $kind === ($island['kind'] ?? '') && $selector === ($island['selector'] ?? '') ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $runtimeIsland
     */
    private function inlineScriptReferencesRuntimeIsland(string $content, array $runtimeIsland): bool
    {
        $attributes = is_array($runtimeIsland['attributes'] ?? null) ? $runtimeIsland['attributes'] : array();
        $id = is_scalar($attributes['id'] ?? null) ? trim((string) $attributes['id']) : '';
        if ( '' !== $id && str_contains($content, $id) ) {
            return true;
        }

        $classes = preg_split('/\s+/', is_scalar($attributes['class'] ?? null) ? trim((string) $attributes['class']) : '') ?: array();
        foreach ( $classes as $class ) {
            if ( '' !== $class && str_contains($content, $class) ) {
                return true;
            }
        }

        $selector = is_scalar($runtimeIsland['selector'] ?? null) ? trim((string) $runtimeIsland['selector']) : '';
        return '' !== $selector && str_contains($content, $selector);
    }

    /**
     * @param array<string, mixed> $file
     * @return array<string, string>
     */
    private function inlineScriptAttributes(array $file): array
    {
        $attributes = array();
        if ( isset($file['type']) && is_scalar($file['type']) && '' !== trim((string) $file['type']) ) {
            $attributes['type'] = (string) $file['type'];
        }
        foreach ( array('defer', 'async') as $field ) {
            if ( ! empty($file[$field]) ) {
                $attributes[$field] = $field;
            }
        }

        return $attributes;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     */
    private function safeHtmlDocumentHtml(string $html, string $entryPath, array $files): string
    {
        $html = $this->withoutMaterializedScriptTags($html, $entryPath, $files);
        $html = $this->withoutMaterializedStyleTags($html, $entryPath, $files);
        $html = $this->withoutGlobalTemplatePartShell($html, $files);
        $html = preg_replace_callback('/<img\s+[^>]*src\s*=\s*(["\'])([^"\']+)\1[^>]*>/i', function (array $matches) use ($entryPath, $files): string {
            $asset = $this->findAssetByHtmlReference((string) $matches[2], $entryPath, $files);
            if ( is_array($asset) && 'image/svg+xml' === ($asset['mime_type'] ?? '') && ! $this->isSafeImageAsset($asset) ) {
                return '';
            }

            return (string) $matches[0];
        }, $html) ?? $html;

        return $html;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     */
    private function withoutGlobalTemplatePartShell(string $html, array $files): string
    {
        $areas = $this->templatePartAreas($files);
        if ( ! in_array('footer', $areas, true) ) {
            return $html;
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ( ! $loaded ) {
            return $html;
        }

        $body = $document->getElementsByTagName('body')->item(0);
        if ( ! $body instanceof DOMElement ) {
            return $html;
        }

        $removed = false;
        $candidates = array();
        foreach ( $body->getElementsByTagName('*') as $element ) {
            if ( $element instanceof DOMElement && $this->isGlobalFooterShellElement($element) ) {
                $candidates[] = $element;
            }
        }

        foreach ( $candidates as $element ) {
            if ( null !== $element->parentNode ) {
                $element->parentNode->removeChild($element);
                $removed = true;
            }
        }

        if ( ! $removed ) {
            return $html;
        }

        $result = '';
        foreach ( $body->childNodes as $child ) {
            $result .= $document->saveHTML($child) ?: '';
        }

        return $result;
    }

    private function isGlobalFooterShellElement(DOMElement $element): bool
    {
        if ( 'footer' !== ShellLandmarkPolicy::landmarkKind($element->tagName, (string) $element->getAttribute('role')) ) {
            return false;
        }

        for ( $ancestor = $element->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode ) {
            $ancestorTag = strtolower($ancestor->tagName);
            if ( in_array($ancestorTag, array( 'main', 'article', 'blockquote', 'figure' ), true) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, string>
     */
    private function templatePartAreas(array $files): array
    {
        $areas = array();
        foreach ( $files as $file ) {
            if ( ! is_array($file) || ! $this->isTemplatePartFile($file) ) {
                continue;
            }

            $areas[] = $this->templatePartArea((string) ($file['path'] ?? ''), (string) ($file['role'] ?? ''));
        }

        return array_values(array_unique($areas));
    }

    /**
     * @param array<int, array<string, mixed>> $files
     */
    private function withoutMaterializedScriptTags(string $html, string $entryPath, array $files): string
    {
        $scriptIndex = 0;
        $hasDeclaredScriptFiles = false;
        foreach ( $files as $file ) {
            if ( is_array($file) && 'inline-script' !== ($file['source'] ?? '') && $this->isMaterializedScriptAsset($file) ) {
                $hasDeclaredScriptFiles = true;
                break;
            }
        }

        return preg_replace_callback('/<script\b([^>]*)>(.*?)<\/script>/is', function (array $matches) use ($entryPath, $files, $hasDeclaredScriptFiles, &$scriptIndex): string {
            ++$scriptIndex;
            $src = $this->htmlAttribute((string) $matches[1], 'src');
            if ( '' !== $src ) {
                $asset = $this->findAssetByHtmlReference($src, $entryPath, $files);
                if ( ! is_array($asset) || ! $this->isMaterializedScriptAsset($asset) ) {
                    return $hasDeclaredScriptFiles ? (string) $matches[0] : '';
                }

                return '';
            }

            $asset = $this->findInlineScriptAsset($entryPath, $scriptIndex, $files);
            if ( ! is_array($asset) ) {
                return $hasDeclaredScriptFiles ? (string) $matches[0] : '';
            }

            return '';
        }, $html) ?? $html;
    }

    /**
     * Inline CSS is materialized as a stylesheet asset during normalization.
     * Remove only styles backed by that asset before block conversion so CSS is
     * not also classified as unsupported body content.
     *
     * @param array<int, array<string, mixed>> $files
     */
    private function withoutMaterializedStyleTags(string $html, string $entryPath, array $files): string
    {
        $styleIndex = 0;
        return preg_replace_callback('/<style\b([^>]*)>(.*?)<\/style>/is', function (array $matches) use ($entryPath, $files, &$styleIndex): string {
            $attributes = (string) $matches[1];
            if ( ! StyleTagScanner::isCssType($this->htmlAttribute($attributes, 'type')) || '' === trim((string) $matches[2]) ) {
                return (string) $matches[0];
            }

            ++$styleIndex;
            foreach ( $files as $file ) {
                if ( 'inline-style' === ($file['source'] ?? '') && $entryPath === ($file['source_path'] ?? '') && $styleIndex === (int) ($file['stylesheet_index'] ?? 0) && 'css' === ($file['kind'] ?? '') ) {
                    return '';
                }
            }

            return (string) $matches[0];
        }, $html) ?? $html;
    }

    /**
     * @param array<string, mixed> $asset
     */
    private function isMaterializedScriptAsset(array $asset): bool
    {
        return in_array($asset['kind'] ?? '', array('js', 'mjs'), true)
            || 'script' === ($asset['role'] ?? '')
            || in_array($asset['mime_type'] ?? '', array('application/javascript', 'text/javascript', 'application/ecmascript', 'text/ecmascript'), true);
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<string, mixed>|null
     */
    private function findInlineScriptAsset(string $entryPath, int $scriptIndex, array $files): ?array
    {
        $selector = 'script:nth-of-type(' . $scriptIndex . ')';
        foreach ( $files as $file ) {
            if ( 'inline-script' !== ($file['source'] ?? '') || $entryPath !== ($file['source_path'] ?? '') || $selector !== ($file['selector'] ?? '') || ! $this->isMaterializedScriptAsset($file) ) {
                continue;
            }

            return $file;
        }

        return null;
    }

    /**
     * Keep source stylesheet boundaries intact for payload-addressed analysis.
     *
     * @param list<array{path: string, content: string, source_hash: string}> $stylesheets
     * @param array<int, array<string, mixed>> $files
     * @return list<array{content: string, source_hash: string, media: string}>
     */
    private function linkedStylesheetPayloads(array $stylesheets, string $sourcePath, array $files): array
    {
        $payloads = array();
        foreach ( $stylesheets as $stylesheet ) {
            $content = (string) ($stylesheet['content'] ?? '');
            if ( '' !== trim($content) ) {
                $payloads[] = array(
                    'content' => $this->artifactRelativeStylesheetContent($content, (string) ($stylesheet['source_path'] ?? $sourcePath), $files),
                    'source_hash' => (string) ($stylesheet['source_hash'] ?? hash('sha256', $content)),
                    'media' => (string) ($stylesheet['media'] ?? ''),
                );
            }
        }

        return $payloads;
    }

    /**
     * Linked CSS files are shared artifact assets. Their class-bound rules must
     * keep addressing authored classes across every consuming document.
     *
     * @param array<int, array<string, mixed>> $files
     * @return list<string>
     */
    private function sharedStylesheetPathsFromFiles(array $files): array
    {
        $paths = array();
        foreach ( $files as $file ) {
            if ( 'css' !== ($file['kind'] ?? '') || ! is_string($file['path'] ?? null) || 'shared' !== $this->fileOwnership($file)['scope'] ) {
                continue;
            }
            $paths[$file['path']] = true;
            if ( is_string($file['stylesheet_source_path'] ?? null) && '' !== $file['stylesheet_source_path'] ) {
                $paths[$file['stylesheet_source_path']] = true;
            }
        }

        return array_keys($paths);
    }

    /**
     * Preserve authored stylesheet boundaries and document order for selector
     * projection. Inline CSS is normalized as its own asset by ArtifactNormalizer.
     *
     * @param array<int, array<string, mixed>> $files
     * @return list<array{path: string, content: string, source_hash: string}>
     */
    private function stylesheetAssetsForSource(string $html, string $sourcePath, array $files): array
    {
        ++$this->stylesheetAssetDiscoveryCount;
        $byPath = array();
        $inline = array();
        $occurrencePaths = array();
        foreach ( $files as $file ) {
            if ( 'css' !== ($file['kind'] ?? '') || ! is_string($file['path'] ?? null) || ! is_string($file['content'] ?? null) ) {
                continue;
            }
            $byPath[$file['path']] = $file;
            if ( 'inline-style' === ($file['source'] ?? '') && $sourcePath === ($file['source_path'] ?? '') ) {
                $inline[(int) ($file['stylesheet_index'] ?? 0)] = $file;
            }
            if ( is_string($file['stylesheet_source_path'] ?? null) && isset($file['stylesheet_occurrence']) ) {
                $occurrencePaths[$file['stylesheet_source_path']][(int) $file['stylesheet_occurrence']] = $file['path'];
            }
        }
        $assets = array();
        $seenPaths = array();
        $inlineIndex = 0;
        $linkOccurrences = array();
        $tags = array_map(
            static fn (array $style): array => array('kind' => 'style', 'offset' => $style['offset'], 'attributes' => $style['attributes'], 'content' => $style['content']),
            StyleTagScanner::scan($html)
        );
        foreach ( StyleTagScanner::scanLinks($html) as $link ) {
            $tags[] = array('kind' => 'link', 'offset' => $link['offset'], 'tag' => $link['tag']);
        }
        usort($tags, static fn (array $left, array $right): int => $left['offset'] <=> $right['offset']);
        foreach ( $tags as $tagRecord ) {
            if ( 'style' === $tagRecord['kind'] ) {
                $attributes = $tagRecord['attributes'];
                if ( ! StyleTagScanner::isCssType($this->htmlAttribute($attributes, 'type')) ) {
                    continue;
                }
                if ( '' === trim($tagRecord['content']) ) {
                    continue;
                }
                ++$inlineIndex;
                $file = $inline[$inlineIndex] ?? null;
                if ( is_array($file) && ! isset($seenPaths[$file['path']]) ) {
                    $assets[] = array( 'path' => $file['path'], 'source_path' => $file['source_path'] ?? $file['path'], 'content' => $file['content'], 'source_hash' => (string) ($file['provenance']['hash'] ?? hash('sha256', $file['content']) ), 'media' => (string) ($file['media'] ?? ''), 'type' => (string) ($file['type'] ?? '') );
                    $seenPaths[$file['path']] = true;
                } elseif ( '' !== ($content = trim(html_entity_decode($tagRecord['content'], ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ) {
                    // Generated inline-style files can be omitted at the artifact
                    // file limit. Their source HTML was accepted independently,
                    // so retain the authored stylesheet for source analysis.
                    $assets[] = array( 'path' => 'inline-style-' . $inlineIndex . '.css', 'source_path' => 'inline-style', 'content' => $content, 'source_hash' => hash('sha256', $content), 'media' => $this->htmlAttribute($attributes, 'media'), 'type' => $this->htmlAttribute($attributes, 'type') );
                }
                continue;
            }
            $tag = $tagRecord['tag'];
            if ( ! preg_match('/^<link\b/i', $tag) || ! StyleTagScanner::isStylesheetRel($this->htmlAttribute((string) $tag, 'rel')) || ! StyleTagScanner::isCssType($this->htmlAttribute((string) $tag, 'type')) ) {
                continue;
            }
            $sourcePathForLink = $this->stylesheetPathFromHref($this->htmlAttribute((string) $tag, 'href'), $sourcePath, $files);
            $linkOccurrences[$sourcePathForLink] = ($linkOccurrences[$sourcePathForLink] ?? 0) + 1;
            $path = $occurrencePaths[$sourcePathForLink][$linkOccurrences[$sourcePathForLink]] ?? '';
            $file = $byPath[$path] ?? null;
            if ( is_array($file) && ! isset($seenPaths[$path]) ) {
                $assets[] = array( 'path' => $path, 'source_path' => $file['stylesheet_source_path'] ?? $sourcePathForLink, 'content' => $file['content'], 'source_hash' => (string) ($file['provenance']['hash'] ?? hash('sha256', $file['content']) ), 'media' => $this->htmlAttribute((string) $tag, 'media'), 'type' => $this->htmlAttribute((string) $tag, 'type') );
                $seenPaths[$path] = true;
            }
        }
        return $assets;
    }

    /** @param array<int, array<string, mixed>> $files */
    private function artifactRelativeStylesheetContent(string $content, string $stylesheetPath, array $files): string
    {
        $paths = array() !== $this->filesByPath ? $this->filesByPath : array_fill_keys(array_column($files, 'path'), true);
        return CssUrlRewriter::rewrite($content, static function (string $reference) use ($stylesheetPath, $paths): string {
            if ('' === $reference || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|#|\?)~i', $reference)) return $reference;
            preg_match('/^([^?#]*)(.*)$/s', $reference, $parts);
            $path = str_starts_with($parts[1] ?? '', '/')
                ? ArtifactPath::safeRelativePath(ltrim((string) ($parts[1] ?? ''), '/'))
                : ArtifactPath::resolveRelativePath((string) ($parts[1] ?? ''), $stylesheetPath);
            return '' !== $path && isset($paths[$path]) ? $path . ($parts[2] ?? '') : $reference;
        });
    }

    /** @param array<int, array<string, mixed>> $files @return array<int, array<string, mixed>> */
    private function withStylesheetOccurrenceAssets(string $html, string $sourcePath, array $files): array
    {
        $byPath = array();
        $reserved = array();
        foreach ( $files as $index => $file ) {
            $path = (string) ($file['path'] ?? '');
            $byPath[$path] = $index;
            $reserved[$path] = true;
        }
        $occurrences = array();
        $variants = array();
        foreach ( StyleTagScanner::scanLinks($html) as $link ) {
            $tag = $link['tag'];
            if ( ! StyleTagScanner::isStylesheetRel($this->htmlAttribute((string) $tag, 'rel')) || ! StyleTagScanner::isCssType($this->htmlAttribute((string) $tag, 'type')) ) {
                continue;
            }
            $originalPath = $this->stylesheetPathFromHref($this->htmlAttribute((string) $tag, 'href'), $sourcePath, $files);
            if ( '' === $originalPath || ! isset($byPath[$originalPath]) || 'css' !== ($files[$byPath[$originalPath]]['kind'] ?? '') ) {
                continue;
            }
            $occurrences[$originalPath] = ($occurrences[$originalPath] ?? 0) + 1;
            $occurrence = $occurrences[$originalPath];
            $media = $this->htmlAttribute((string) $tag, 'media');
            $type = $this->htmlAttribute((string) $tag, 'type');
            if ( 1 === $occurrence ) {
                $files[$byPath[$originalPath]]['media'] = $media;
                $files[$byPath[$originalPath]]['type'] = $type;
                $files[$byPath[$originalPath]]['stylesheet_source_path'] = $originalPath;
                $files[$byPath[$originalPath]]['stylesheet_occurrence'] = 1;
                $variants[$originalPath][$media . "\0" . $type] = true;
                continue;
            }
            // Repeating one stylesheet under the same conditions applies it
            // once, exactly as a browser resolves it. Only a differing media or
            // type makes a later reference its own participant in the cascade.
            if ( isset($variants[$originalPath][$media . "\0" . $type]) ) {
                continue;
            }
            $variants[$originalPath][$media . "\0" . $type] = true;
            $alias = $this->allocateStylesheetOccurrencePath($this->stylesheetOccurrencePath($originalPath, $occurrence), $reserved);
            $aliasFile = $files[$byPath[$originalPath]];
            $aliasFile['path'] = $alias;
            $aliasFile['source'] = 'stylesheet-occurrence';
            $aliasFile['source_path'] = $originalPath;
            $aliasFile['stylesheet_source_path'] = $originalPath;
            $aliasFile['stylesheet_occurrence'] = $occurrence;
            $aliasFile['media'] = $media;
            $aliasFile['type'] = $type;
            $aliasFile['provenance']['source_path'] = $originalPath;
            $files[] = $aliasFile;
            $byPath[$alias] = count($files) - 1;
        }
        return $files;
    }

    /** @param array<int, array<string, mixed>> $files */
    private function stylesheetPathFromHref(string $href, string $sourcePath, array $files = array()): string
    {
        $href = (string) preg_replace('/[?#].*$/', '', $href);
        if ( ! str_starts_with($href, '/') ) {
            return ArtifactPath::resolveRelativePath($href, $sourcePath);
        }

        return $this->artifactRootRelativePath($href, $sourcePath, array_fill_keys(array_column($files, 'path'), true));
    }

    /** @param array<string, true> $paths */
    private function artifactRootRelativePath(string $reference, string $sourcePath, array $paths): string
    {
        $relative = ArtifactPath::safeRelativePath(ltrim($reference, '/'));
        if ( '' === $relative || isset($paths[$relative]) ) {
            return $relative;
        }

        $sourceSegments = explode('/', dirname($sourcePath));
        $matches = array();
        foreach ( array_keys($paths) as $path ) {
            if ( ! str_ends_with($path, '/' . $relative) ) {
                continue;
            }
            $candidateSegments = explode('/', dirname($path));
            $common = 0;
            while ( isset($sourceSegments[$common], $candidateSegments[$common]) && $sourceSegments[$common] === $candidateSegments[$common] ) {
                ++$common;
            }
            $matches[] = array( 'path' => $path, 'common' => $common );
        }
        usort($matches, static fn (array $left, array $right): int => $right['common'] <=> $left['common'] ?: strcmp($left['path'], $right['path']));

        return isset($matches[0]) && $matches[0]['common'] > 0 ? (string) $matches[0]['path'] : $relative;
    }

    private function stylesheetOccurrencePath(string $path, int $occurrence): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $base = '' === $extension ? $path : substr($path, 0, -strlen($extension) - 1);
        return $base . '.occurrence-' . $occurrence . ('' === $extension ? '' : '.' . $extension);
    }

    /** @param array<string, true> $reserved */
    private function allocateStylesheetOccurrencePath(string $candidate, array &$reserved): string
    {
        $path = $candidate;
        $index = 1;
        while ( isset($reserved[$path]) ) {
            $extension = pathinfo($candidate, PATHINFO_EXTENSION);
            $base = '' === $extension ? $candidate : substr($candidate, 0, -strlen($extension) - 1);
            $path = $base . '-generated-' . $index++ . ('' === $extension ? '' : '.' . $extension);
        }
        $reserved[$path] = true;
        return $path;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @param array<int, array<string, mixed>> $projections
     * @param array<int, array<string, mixed>> $primaryProjections
     * @return array<int, array<string, mixed>>
     */
    private function applyAuthorStylesheetProjections(array $files, array $projections, array $primaryProjections = array()): array
    {
        $attributeStateMarkers = array();
        foreach ($projections as $projection) {
            $markers = $projection['attribute_state_markers'] ?? null;
            if (!is_array($markers)) continue;
            foreach ($markers as $selector => $marker) {
                if (is_string($selector) && is_string($marker) && '' !== $marker) $attributeStateMarkers[$selector][$marker] = true;
            }
        }
        $reconcileAttributeStateMarkers = static function (array $projection) use ($attributeStateMarkers): array {
            $markers = $projection['attribute_state_markers'] ?? null;
            if (!is_string($projection['content'] ?? null) || !is_array($markers)) return $projection;
            foreach ($markers as $selector => $marker) {
                $allMarkers = array_keys($attributeStateMarkers[$selector] ?? array());
                if (!is_string($marker) || '' === $marker || count($allMarkers) < 2) continue;
                $replacement = implode('', array_map(static fn(string $candidate): string => ':not(.' . $candidate . ')', $allMarkers));
                $projection['content'] = str_replace(':not(.' . $marker . ')', $replacement, $projection['content']);
            }
            return $projection;
        };
        $projections = array_map($reconcileAttributeStateMarkers, $projections);
        $primaryProjections = array_map($reconcileAttributeStateMarkers, $primaryProjections);
        $byPath = array();
        $primaryByPath = array();
        foreach ( $primaryProjections as $projection ) {
            if ( is_string($projection['path'] ?? null) && is_string($projection['content'] ?? null) ) {
                $primaryByPath[$projection['path']][$projection['content']] = true;
            }
        }
        foreach ( $projections as $projection ) {
            if ( is_string($projection['path'] ?? null) && is_string($projection['content'] ?? null) ) {
                $path = $projection['path'];
                $byPath[$path] ??= array();
                $byPath[$path][$projection['content']] = true;
            }
        }
        foreach ( $files as &$file ) {
            $pathProjections = $byPath[$file['path'] ?? ''] ?? null;
            if ( ! is_array($pathProjections) || 'css' !== ($file['kind'] ?? '') ) {
                continue;
            }
            foreach ( array_keys($primaryByPath[$file['path'] ?? ''] ?? array()) as $primaryContent ) {
                unset($pathProjections[$primaryContent]);
            }
            $authoritativeContent = array_keys($primaryByPath[$file['path'] ?? ''] ?? array());
            if ( array() === $authoritativeContent ) {
                if ( array() !== $pathProjections ) {
                    $authoritativeProjection = array_key_last($pathProjections);
                    $authoritativeContent[] = (string) $authoritativeProjection;
                    unset($pathProjections[$authoritativeProjection]);
                } else {
                    $authoritativeContent[] = (string) ($file['content'] ?? '');
                }
            }
            $preambles = array();
            $stylesheets = array();
            foreach ( $authoritativeContent as $stylesheet ) {
                $split = ( new CssStylesheetTransformer() )->splitLeadingAtRulePreamble($stylesheet);
                if ( '' !== trim($split['preamble']) ) {
                    $preambles[] = $split['preamble'];
                }
                if ( '' !== trim($split['stylesheet']) ) {
                    $stylesheets[] = $split['stylesheet'];
                }
            }
            $content = implode("\n", array_merge($preambles, array_keys($pathProjections), $stylesheets));
            $file['content'] = $content;
            // Projection rewrites the CSS text, so any base64 twin from the
            // source payload is stale. Drop it and let the rewritten text be the
            // sole representation rather than shipping an inconsistent encoding.
            unset($file['content_base64']);
            $file['bytes'] = strlen($content);
            $file['encoding'] = 'text';
            $file['binary'] = false;
            $file['provenance']['projected_from_hash'] = $file['provenance']['hash'] ?? '';
            $file['provenance']['hash'] = hash('sha256', $content);
        }
        unset($file);
        return $files;
    }

    /**
     * Keep transformed selector records below browser engine limits while
     * retaining the source stylesheet's cascade position and link contract.
     *
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function chunkProjectedStylesheets(array $files): array
    {
        $reserved = array_fill_keys(array_column($files, 'path'), true);
        $chunkPaths = array();
        $chunker = new CssStylesheetChunker();
        $output = array();
        foreach ($files as $file) {
            if ('css' !== ($file['kind'] ?? null) || !is_string($file['content'] ?? null)) {
                $output[] = $file;
                continue;
            }
            $chunks = $chunker->chunk($file['content'], $this->stylesheetSelectorBudget);
            if (count($chunks) < 2) {
                $output[] = $file;
                continue;
            }
            $paths = array();
            for ($index = 0; $index < count($chunks); ++$index) {
                $paths[] = $this->stylesheetChunkPath((string) $file['path'], $index + 1, $reserved);
            }
            $chunkPaths[(string) $file['path']] = $paths;
            $loader = $file;
            $loader['content'] = implode('', array_map(static fn (string $path): string => '@import url("' . basename($path) . '");', $paths));
            unset($loader['content_base64']);
            $loader['bytes'] = strlen($loader['content']);
            $loader['encoding'] = 'text';
            $loader['binary'] = false;
            $loader['provenance']['hash'] = hash('sha256', $loader['content']);
            $output[] = $loader;
            $continuationPreamble = $chunker->continuationPreamble($file['content']);
            foreach ($chunks as $index => $content) {
                $chunk = $file;
                $chunk['path'] = $paths[$index];
                $chunk['content'] = 0 === $index ? $content : $continuationPreamble . $content;
                unset($chunk['content_base64']);
                $chunk['bytes'] = strlen($chunk['content']);
                $chunk['encoding'] = 'text';
                $chunk['binary'] = false;
                $chunk['provenance']['hash'] = hash('sha256', $chunk['content']);
                $output[] = $chunk;
            }
        }
        if (array() === $chunkPaths) {
            return $output;
        }
        return $output;
    }

    /** @param array<string, true> $reserved */
    private function stylesheetChunkPath(string $path, int $index, array &$reserved): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $base = '' === $extension ? $path : substr($path, 0, -strlen($extension) - 1);
        $candidate = $base . '.chunk-' . $index . ('' === $extension ? '' : '.' . $extension);
        $suffix = 2;
        while (isset($reserved[$candidate])) {
            $candidate = $base . '.chunk-' . $index . '-' . $suffix++ . ('' === $extension ? '' : '.' . $extension);
        }
        $reserved[$candidate] = true;
        return $candidate;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @param array<int, array<string, mixed>> $projections
     * @return array<int, array<string, mixed>>
     */
    private function applyRuntimeScriptProjections(array $files, array $projections): array
    {
        $byPath = $this->runtimeScriptProjectionMap($projections, 'path');

        foreach ( $files as &$file ) {
            $pathProjection = $byPath[$file['path'] ?? ''] ?? null;
            if ( ! is_array($pathProjection) || ! in_array($file['kind'] ?? '', array('js', 'mjs'), true) || ! is_string($file['content'] ?? null) ) {
                continue;
            }
            $content = $this->projectRuntimeScriptContent($file['content'], $pathProjection);
            if ( $content === $file['content'] ) {
                continue;
            }
            $file['content'] = $content;
            unset($file['content_base64']);
            $file['bytes'] = strlen($content);
            $file['encoding'] = 'text';
            $file['binary'] = false;
            $file['provenance']['projected_from_hash'] = $file['provenance']['hash'] ?? '';
            $file['provenance']['hash'] = hash('sha256', $content);
        }
        unset($file);

        return $files;
    }

    /** @param array<string, mixed> $package @param array<int, array<string, mixed>> $projections @return array<string, mixed> */
    private function applyRuntimeScriptPackageProjections(array $package, array $projections): array
    {
        if ( ! is_array($package['islands'] ?? null) ) {
            return $package;
        }
        foreach ( $package['islands'] as &$island ) {
            if ( ! is_array($island['scripts'] ?? null) ) {
                continue;
            }
            foreach ( $island['scripts'] as &$script ) {
                if ( ! is_string($script['content'] ?? null) ) {
                    continue;
                }
                $projection = $this->runtimeScriptProjectionForContent($script['content'], $projections);
                if ( is_array($projection) ) {
                    $script['content'] = $this->projectRuntimeScriptContent($script['content'], $projection);
                }
            }
            unset($script);
        }
        unset($island);

        return $package;
    }

    /** @param array<int, array<string, mixed>> $projections @return array<string, array<string, true>> */
    private function runtimeScriptProjectionForContent(string $content, array $projections): array
    {
        $matched = array();
        foreach ( $projections as $projection ) {
            if ( ! is_array($projection) ) {
                continue;
            }
            foreach ( $projection['selectors'] ?? array() as $selector => $markers ) {
                if ( ! is_string($selector) || ! is_array($markers) || ! preg_match('~(?:querySelector(?:All)?|closest|matches)\s*\(\s*(["\'])' . preg_quote($selector, '~') . '\1~', $content) ) {
                    continue;
                }
                foreach ( $markers as $marker ) {
                    if ( is_string($marker) && '' !== $marker ) {
                        $matched[$selector][$marker] = true;
                    }
                }
            }
            foreach ( $projection['superseded_ids'] ?? array() as $id ) {
                if ( is_string($id) && '' !== $id && preg_match('~getElementById\s*\(\s*(["\'])' . preg_quote($id, '~') . '\1\s*\)~', $content) ) {
                    $matched['superseded_ids'][$id] = true;
                }
            }
        }

        return $matched;
    }

    /** @param array<int, array<string, mixed>> $projections @return array<string, array<string, array<string, true>>> */
    private function runtimeScriptProjectionMap(array $projections, string $identityKey): array
    {
        $map = array();
        foreach ( $projections as $projection ) {
            if ( ! is_array($projection) ) {
                continue;
            }
            $identity = $projection[$identityKey] ?? null;
            if ( ! is_string($identity) || '' === $identity || ! is_array($projection['selectors'] ?? null) || ! is_array($projection['superseded_ids'] ?? null) ) {
                continue;
            }
            foreach ( $projection['selectors'] as $selector => $markers ) {
                if ( ! is_string($selector) || ! is_array($markers) ) {
                    continue;
                }
                foreach ( $markers as $marker ) {
                    if ( is_string($marker) && '' !== $marker ) {
                        $map[$identity][$selector][$marker] = true;
                    }
                }
            }
            foreach ( $projection['superseded_ids'] as $id ) {
                if ( is_string($id) && '' !== $id ) {
                    $map[$identity]['superseded_ids'][$id] = true;
                }
            }
        }

        return $map;
    }

    /** @param array<string, array<string, true>> $projection */
    private function projectRuntimeScriptContent(string $content, array $projection): string
    {
        foreach ( $projection as $selector => $markers ) {
            if ( 'superseded_ids' === $selector ) {
                continue;
            }
            $projectedSelector = implode(',', array_map(static fn (string $marker): string => '.' . $marker, array_keys($markers)));
            $selectorPattern = preg_quote($selector, '~');
            $content = preg_replace_callback(
                '~((?:querySelector(?:All)?|closest|matches)\s*\(\s*)(["\'])' . $selectorPattern . '\2~',
                static fn (array $match): string => $match[1] . $match[2] . $projectedSelector . $match[2],
                $content
            ) ?? $content;
        }
        foreach ( array_keys($projection['superseded_ids'] ?? array()) as $id ) {
            $content = preg_replace_callback(
                '~document\s*\.\s*getElementById\s*\(\s*(["\'])' . preg_quote($id, '~') . '\1\s*\)~',
                static fn (array $match): string => '(' . $match[0] . " || document.createElement('div'))",
                $content
            ) ?? $content;
        }

        return $content;
    }

    /**
     * Compile non-entry HTML documents once so their stylesheet projections are
     * available before theme assets are materialized.
     *
     * @param array<int, array<string, mixed>> $files
     * @return array<string, array<string, mixed>>
     */
    private function compileHtmlSourceDocuments(array $files, string $entryPath, string $generatedBlockNamespace = ''): array
    {
        $documents = array();
        foreach ( $files as $file ) {
            if ( 'html' !== ($file['kind'] ?? '') || $this->isTemplatePartFile($file) ) {
                continue;
            }
            $path = (string) ($file['path'] ?? '');
            if ( '' === $path || $entryPath === $path ) {
                continue;
            }
            $documents[$path] = $this->compileHtmlDocumentBlocks((string) ($file['content'] ?? ''), $path, $files, 'artifact-document', $generatedBlockNamespace, true);
        }
        return $documents;
    }

    /**
     * Collect the `<link>` tags declared across the artifact's HTML sources so
     * downstream font materialization can detect linked web-font stylesheets
     * (e.g. Google Fonts) without re-parsing every document. Deduplicated to
     * stay bounded for multi-page sites that repeat a shared `<head>`.
     *
     * @param array<int, array<string, mixed>> $files
     */
    private function themeFontLinkHtml(array $files): string
    {
        $tags = array();
        foreach ( $files as $file ) {
            if ( 'html' !== ($file['kind'] ?? '') || ! is_string($file['content'] ?? null) ) {
                continue;
            }
            foreach ( StyleTagScanner::scanLinks((string) $file['content']) as $link ) {
                $tags[trim($link['tag'])] = true;
            }
        }

        return implode("\n", array_keys($tags));
    }

    /**
     * Aggregate the artifact's authored CSS (linked stylesheet files plus inline
     * `<style>` blocks) so downstream font materialization can read generic
     * `font-family` declarations. Deduplicated and order-preserving.
     *
     * @param array<int, array<string, mixed>> $files
     */
    private function themeStaticCss(array $files, bool $includeNavigationCompat = true): string
    {
		$cacheKey = $includeNavigationCompat ? 'with-compat' : 'without-compat';
		if ( array_key_exists($cacheKey, $this->themeStaticCssCache) ) {
			return $this->themeStaticCssCache[$cacheKey];
		}
		if ( $includeNavigationCompat ) {
			$css = $this->themeStaticCss($files, false);
			return $this->themeStaticCssCache[$cacheKey] = $css . $this->wordpressCompat->css($css, $files, $this->allScriptContents($files));
		}
        $blocks = array();
        foreach ( $files as $file ) {
            $content = is_string($file['content'] ?? null) ? (string) $file['content'] : '';
            if ( '' === trim($content) ) {
                continue;
            }

            if ( 'css' === ($file['kind'] ?? '') ) {
                $blocks[trim($content)] = true;
                continue;
            }

            if ( 'html' === ($file['kind'] ?? '') && preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $content, $matches) ) {
                foreach ( $matches[1] as $style ) {
                    $style = trim((string) $style);
                    if ( '' !== $style ) {
                        $blocks[$style] = true;
                    }
                }
            }
        }

        $css = implode("\n", array_keys($blocks));

		return $this->themeStaticCssCache[$cacheKey] = $css;
    }

    /** @return array<int,array{path:string,content:string,source_hash:string}> */
    private function themeFontCssSources(array $files): array
    {
        $sources = array();
        foreach ( $files as $file ) {
            if ( 'css' !== ($file['kind'] ?? '') || ! is_string($file['content'] ?? null) || strlen($file['content']) === strspn($file['content'], " \t\n\r\0\x0B") ) continue;
            $sources[] = array('path' => (string) ($file['path'] ?? 'css:input'), 'content' => $file['content'], 'source_hash' => (string) ($file['provenance']['hash'] ?? hash('sha256', $file['content'])));
        }
        return self::sortedByPath($sources);
    }

    /**
     * Linked stylesheets are projected after HtmlTransformer has emitted its
     * per-document support assets, so scan their final runtime selectors here.
     *
     * @param array<int, array<string, mixed>> $files
     * @return array<string, mixed>|null
     */
    private function projectedAdminBarAccommodationAsset(array $files): ?array
    {
        $css = array();
        $accommodation = new AdminBarAccommodation();
        foreach ($files as $file) {
            if ('css' !== ($file['kind'] ?? '') || !is_string($file['content'] ?? null)) {
                continue;
            }
            $supportCss = $accommodation->supportCss($file['content']);
            if ('' !== $supportCss) {
                $css[] = $supportCss;
            }
        }
        $content = trim(implode("\n", $css));
        if ('' === $content) {
            return null;
        }

        $content .= "\n";
        $hash = hash('sha256', $content);
        $path = 'assets/css/engine-support-after-author-' . substr($hash, 0, 16) . '.css';
        return array(
            'source' => 'engine-support',
            'path' => $path,
            'target_path' => $path,
            'kind' => 'css',
            'role' => 'stylesheet',
            'stylesheet_placement' => 'after-author',
            'stylesheet_target' => 'both',
            'mime_type' => 'text/css',
            'media_type' => 'text/css',
            'bytes' => strlen($content),
            'encoding' => 'utf-8',
            'binary' => false,
            'content' => $content,
            'hash' => $hash,
            'source_hash' => $hash,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, string>
     */
    private function runtimeDomSelectors(string $html, string $sourcePath, array $files): array
    {
        $hasDeclaredScriptFiles = false;
        foreach ( $files as $file ) {
            if ( is_array($file) && 'inline-script' !== ($file['source'] ?? '') && $this->isMaterializedScriptAsset($file) ) {
                $hasDeclaredScriptFiles = true;
                break;
            }
        }
        if ( ! $hasDeclaredScriptFiles ) {
            return array();
        }

        $selectors = array();
        $controlSelectors = $this->formControlSelectors($html);
        $statusFeedbackSelectors = $this->formStatusFeedbackSelectors($html);
        foreach ( $this->documentScriptContents($html, $sourcePath, $files) as $script ) {
            foreach ( $this->runtimeScriptEvidenceAnalyzer->analyze($script)['dependencies'] as $dependency ) {
                $selector = (string) $dependency['selector'];
                if ( true === $dependency['presentation_only'] ) {
                    continue;
                }
                if ( isset($controlSelectors[$selector]) && true !== $dependency['control_runtime'] ) {
                    continue;
                }
                $selectors[$selector] = true;
            }
        }

        foreach ( $this->allScriptContents($files) as $script ) {
            foreach ( $this->runtimeScriptEvidenceAnalyzer->analyze($script)['dependencies'] as $dependency ) {
                $selector = (string) $dependency['selector'];
                if ( true === $dependency['presentation_only'] ) {
                    continue;
                }
                if ( isset($statusFeedbackSelectors[$selector]) ) {
                    $selectors[$selector] = true;
                }
            }
        }

        return array_keys($selectors);
    }

    /**
     * Presentation-only scripts still need stable DOM identities when editable
     * block serialization cannot retain their source data attributes.
     *
     * @param array<int, array<string, mixed>> $files
     * @return array<int, string>
     */
    private function runtimeProjectionSelectors(string $html, string $sourcePath, array $files): array
    {
        $selectors = array();
        foreach ( $this->documentScriptContents($html, $sourcePath, $files) as $script ) {
            foreach ( $this->runtimeScriptEvidenceAnalyzer->analyze($script)['dependencies'] as $dependency ) {
                $selector = (string) $dependency['selector'];
                if ( str_contains($selector, '[data-') && true === $dependency['presentation_only'] ) {
                    $selectors[$selector] = true;
                }
            }
        }

        return array_keys($selectors);
    }

    /**
     * @return array<string, bool>
     */
    private function formStatusFeedbackSelectors(string $html): array
    {
        $selectors = array();
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ( ! $loaded ) {
            return array();
        }

        foreach ( $document->getElementsByTagName('*') as $element ) {
            if ( ! $element instanceof DOMElement || ! $this->isFormStatusFeedbackElement($element) ) {
                continue;
            }

            $id = trim($element->hasAttribute('id') ? $element->getAttribute('id') : '');
            if ( '' !== $id ) {
                $selectors['#' . $id] = true;
            }
            foreach ( preg_split('/\s+/', trim($element->hasAttribute('class') ? $element->getAttribute('class') : '')) ?: array() as $class ) {
                if ( '' !== $class && ! $this->isBehaviorHookClassName($class) ) {
                    $selectors['.' . $class] = true;
                }
            }
        }

        return $selectors;
    }

    private function isFormStatusFeedbackElement(DOMElement $element): bool
    {
        if ( in_array(strtolower($element->tagName), array('button', 'input', 'select', 'textarea', 'form', 'script', 'style'), true) ) {
            return false;
        }

        $tokens = strtolower(trim(implode(' ', array(
            $element->hasAttribute('id') ? $element->getAttribute('id') : '',
            $element->hasAttribute('class') ? $element->getAttribute('class') : '',
            $element->hasAttribute('role') ? $element->getAttribute('role') : '',
            $element->hasAttribute('aria-live') ? 'aria-live' : '',
        ))));

        return (bool) preg_match('/(?:^|[^a-z0-9])(?:form|contact|newsletter|signup|subscribe|submission|submit|message|status|feedback|alert|notice|response|success|error|warning|confirmation|thanks?)(?:[^a-z0-9]|$)/', $tokens)
            && (bool) preg_match('/(?:^|[^a-z0-9])(?:success|error|message|status|feedback|alert|notice|response|warning|confirmation|thanks?|aria-live)(?:[^a-z0-9]|$)/', $tokens);
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, string>
     */
    private function runtimeCanvasSelectors(string $html, string $sourcePath, array $files): array
    {
        $canvasSelectors = $this->canvasSelectors($html);
        if ( array() === $canvasSelectors ) {
            return array();
        }

        $selectors = array();
        $scripts = $this->documentScriptContents($html, $sourcePath, $files);
        foreach ( $scripts as $script ) {
            foreach ( $this->runtimeScriptEvidenceAnalyzer->analyze($script)['canvas_selectors'] as $selector ) {
                if ( isset($canvasSelectors[$selector]) ) {
                    $selectors[$selector] = true;
                }
            }
        }

        $combinedScripts = implode("\n", $scripts);
        if ( 1 === preg_match('/\.\s*getContext\s*\(/', $combinedScripts) ) {
            foreach ( $this->scriptCanvasArgumentSelectors($combinedScripts) as $selector ) {
                if ( isset($canvasSelectors[$selector]) ) {
                    $selectors[$selector] = true;
                }
            }
        }

        return array_keys($selectors);
    }

    /**
     * @return array<int, string>
     */
    private function scriptCanvasArgumentSelectors(string $script): array
    {
        $selectors = array();
        if ( preg_match_all('/\b[A-Za-z_$][A-Za-z0-9_$]*(?:\s*\.\s*[A-Za-z_$][A-Za-z0-9_$]*)*\s*\([^;)]*document\s*\.\s*getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\1\s*\)/', $script, $matches) ) {
            foreach ( $matches[2] as $id ) {
                $selectors['#' . (string) $id] = true;
            }
        }

        if ( preg_match_all('/(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*document\s*\.\s*(?:getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\2\s*\)|querySelector\s*\(\s*(["\'])(' . $this->scriptSelectorPattern() . ')\4\s*\))/', $script, $assignments, PREG_SET_ORDER) ) {
            foreach ( $assignments as $assignment ) {
                $variable = (string) $assignment[1];
                if ( ! preg_match('/(?:\bnew\s+)?\b[A-Za-z_$][A-Za-z0-9_$]*(?:\s*\.\s*[A-Za-z_$][A-Za-z0-9_$]*)*\s*\([^;)]*\b' . preg_quote($variable, '/') . '\b/', $script) ) {
                    continue;
                }
                $selectors['' !== (string) ($assignment[3] ?? '') ? '#' . (string) $assignment[3] : (string) $assignment[5]] = true;
            }
        }

        return array_keys($selectors);
    }

    /**
     * @return array<string, bool>
     */
    private function canvasSelectors(string $html): array
    {
        $selectors = array();
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ( ! $loaded ) {
            return array();
        }

        foreach ( $document->getElementsByTagName('canvas') as $canvas ) {
            if ( ! $canvas instanceof DOMElement ) {
                continue;
            }
            $id = trim($canvas->hasAttribute('id') ? $canvas->getAttribute('id') : '');
            if ( '' !== $id ) {
                $selectors['#' . $id] = true;
            }
            foreach ( preg_split('/\s+/', trim($canvas->hasAttribute('class') ? $canvas->getAttribute('class') : '')) ?: array() as $class ) {
                if ( '' !== $class ) {
                    $selectors['.' . $class] = true;
                    $selectors['canvas.' . $class] = true;
                }
            }
            $selectors['canvas'] = true;
        }

        return $selectors;
    }

    /**
     * @return array<string, bool>
     */
    private function formControlSelectors(string $html): array
    {
        $selectors = array();
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ( ! $loaded ) {
            return array();
        }

        foreach ( $document->getElementsByTagName('*') as $element ) {
            if ( ! $element instanceof DOMElement || ! in_array(strtolower($element->tagName), array('button', 'input', 'select', 'textarea'), true) ) {
                continue;
            }

            $id = trim($element->hasAttribute('id') ? $element->getAttribute('id') : '');
            if ( '' !== $id ) {
                $selectors['#' . $id] = true;
            }
            foreach ( preg_split('/\s+/', trim($element->hasAttribute('class') ? $element->getAttribute('class') : '')) ?: array() as $class ) {
                if ( '' !== $class ) {
                    $selectors['.' . $class] = true;
                }
            }
        }

        return $selectors;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, string>
     */
    private function allScriptContents(array $files): array
    {
        if ( array() !== $this->filesByPath ) {
            return $this->scriptContents;
        }

        $scripts = array();
        foreach ( $files as $file ) {
            if ( $this->isMaterializedScriptAsset($file) && is_string($file['content'] ?? null) ) {
                $scripts[] = (string) $file['content'];
            }
        }

        return $scripts;
    }

    private function isBehaviorHookClassName(string $className): bool
    {
        return 1 === preg_match('/^js(?:$|[-_:]|[A-Z])/', $className);
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, string>
     */
    private function documentScriptContents(string $html, string $sourcePath, array $files): array
    {
        $scripts = array();
        if ( ! preg_match_all('/<script\b([^>]*)>(.*?)<\/script>/is', $html, $matches, PREG_SET_ORDER) ) {
            return array();
        }

        foreach ( $matches as $match ) {
            $src = $this->htmlAttribute((string) $match[1], 'src');
            if ( '' === $src ) {
                $scripts[] = (string) $match[2];
                continue;
            }

            $asset = $this->findAssetByHtmlReference($src, $sourcePath, $files);
            if ( is_array($asset) && $this->isMaterializedScriptAsset($asset) && is_string($asset['content'] ?? null) ) {
                $scripts[] = (string) $asset['content'];
            }
        }

        return $scripts;
    }

    private function scriptSelectorPattern(): string

    {

        return RuntimeSelectorVocabulary::scriptSelectorPattern();

    }

    private function htmlAttribute(string $tag, string $name): string
    {
        return $this->htmlAttributes($tag)[strtolower($name)] ?? '';
    }

    private function hasHtmlAttribute(string $tag, string $name): bool
    {
        return array_key_exists(strtolower($name), $this->htmlAttributes($tag));
    }

    /** @return array<string,string> */
    private function htmlAttributes(string $tag): array
    {
        $length = strlen($tag);
        $offset = strpos($tag, '<');
        if (false === $offset) {
            $offset = 0;
        } else {
            ++$offset;
            while ($offset < $length && ctype_space($tag[$offset])) ++$offset;
            if ($offset < $length && '/' === $tag[$offset]) ++$offset;
            while ($offset < $length && !ctype_space($tag[$offset]) && !in_array($tag[$offset], array('>', '/'), true)) ++$offset;
        }
        $attributes = array();
        while ($offset < $length) {
            while ($offset < $length && ctype_space($tag[$offset])) ++$offset;
            if ($offset >= $length || '>' === $tag[$offset] || '/' === $tag[$offset]) break;
            $start = $offset;
            while ($offset < $length && !ctype_space($tag[$offset]) && !in_array($tag[$offset], array('=', '>', '/', '"', "'", '<'), true)) ++$offset;
            if ($start === $offset) break;
            $name = strtolower(substr($tag, $start, $offset - $start));
            while ($offset < $length && ctype_space($tag[$offset])) ++$offset;
            $value = '';
            if ($offset < $length && '=' === $tag[$offset]) {
                ++$offset;
                while ($offset < $length && ctype_space($tag[$offset])) ++$offset;
                if ($offset >= $length) break;
                if (in_array($tag[$offset], array('"', "'"), true)) {
                    $quote = $tag[$offset++]; $start = $offset;
                    while ($offset < $length && $tag[$offset] !== $quote) ++$offset;
                    if ($offset >= $length) break;
                    $value = substr($tag, $start, $offset - $start); ++$offset;
                } else {
                    $start = $offset;
                    while ($offset < $length && !ctype_space($tag[$offset]) && '>' !== $tag[$offset]) {
                        if (in_array($tag[$offset], array('"', "'", '<'), true)) break 2;
                        ++$offset;
                    }
                    $value = substr($tag, $start, $offset - $start);
                }
            }
            if (!isset($attributes[$name])) $attributes[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $attributes;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array{internal_links: array<int, array<string, mixed>>, asset_references: array<int, array<string, mixed>>, image_references: array<int, array<string, mixed>>}
     */
    private function referenceReports(array $files, string $entryPath): array
    {
        return ( new ReferenceAnalyzer() )->referenceReports(
            $files,
            fn (array $file): bool => $this->isLinkableDocument($file),
            fn (array $asset): bool => $this->isSafeImageAsset($asset),
            '.' === dirname($entryPath) ? '' : dirname($entryPath)
        );
    }

    /**
     * @param array<string, mixed> $file
     */
    private function isLinkableDocument(array $file): bool
    {
        return in_array($file['kind'] ?? '', array('html', 'blocks'), true) && ! $this->isTemplatePartFile($file);
    }

    /**
     * Compile route-shared nested shell landmarks in isolation so generated
     * support classes and CSS are canonical rather than page-seeded.
     *
     * @param array<int,array<string,mixed>> $files
     * @return array{artifacts:array<int,array<string,mixed>>,assets:array<int,array<string,mixed>>,author_stylesheet_projections:array<int,array<string,mixed>>,runtime_script_projections:array<int,array<string,mixed>>}
     */
    private function compileSharedInlineShells(array $files, string $entryPath, string $generatedBlockNamespace): array
    {
        $documents = array();
        foreach ($files as $file) {
            if ('html' !== ($file['kind'] ?? null) || $this->isTemplatePartFile($file) || !is_string($file['content'] ?? null) || '' === trim($file['content'])) continue;
            $dom = new \DOMDocument();
            $loaded = self::loadUtf8Html($dom, (string) $file['content']);
            if (!$loaded) continue;
            $rows = array('header' => array(), 'footer' => array());
            $body = $dom->getElementsByTagName('body')->item(0);
            if (!$body instanceof \DOMElement) continue;
            $walk = static function (\DOMElement $parent, bool $insideContent = false) use (&$walk, &$rows, $dom): void {
                foreach ($parent->childNodes as $child) {
                    if (!$child instanceof \DOMElement) continue;
                    $tag = strtolower($child->tagName);
                    $content = $insideContent || in_array($tag, array('main', 'article', 'section', 'aside'), true);
                    $area = ShellLandmarkPolicy::landmarkKind($tag, trim($child->getAttribute('role')));
                    if (!$content && isset($rows[$area])) {
                        $markup = $dom->saveHTML($child);
                        if (is_string($markup) && '' !== $markup) $rows[$area][] = array('markup' => $markup, 'identity' => self::normalizeSourceShellIdentity($markup));
                    }
                    $walk($child, $content);
                }
            };
            $walk($body);
            $documents[(string) $file['path']] = array('content' => (string) $file['content'], 'rows' => $rows);
        }
        if (count($documents) < 2) return array('artifacts' => array(), 'assets' => array(), 'author_stylesheet_projections' => array(), 'runtime_script_projections' => array());

        $artifacts = array();
        $assets = array();
        $authorStylesheetProjections = array();
        $runtimeScriptProjections = array();
        foreach (array('header', 'footer') as $area) {
            $variantCount = null; $canonicalMarkups = array();
            $sourcePath = isset($documents[$entryPath]) ? $entryPath : (string) array_key_first($documents);
            foreach ($documents as $document) {
                $count = count($document['rows'][$area]);
                if (0 === $count || (null !== $variantCount && $variantCount !== $count)) { $variantCount = null; break; }
                $variantCount = $count;
            }
            if (null === $variantCount) continue;
            for ($variant = 0; $variant < $variantCount; ++$variant) {
                $identities = self::normalizeSourceShellRootClasses(array_map(static fn(array $document): string => $document['rows'][$area][$variant]['identity'], $documents));
                if (1 !== count(array_unique($identities))) continue 2;
                $canonicalMarkups[$variant] = $identities[$sourcePath];
            }
            $head = preg_match('/<head\b[^>]*>(.*?)<\/head>/is', $documents[$sourcePath]['content'], $headMatch) ? $headMatch[1] : '';
            foreach ($documents[$sourcePath]['rows'][$area] as $variant => $row) {
                $synthetic = '<!doctype html><html><head>' . $head . '</head><body>' . $canonicalMarkups[$variant] . '</body></html>';
                $compiled = $this->compileHtmlDocumentBlocks($synthetic, $sourcePath, $files, 'artifact-shared-shell', $generatedBlockNamespace, true);
                $shell = current(array_filter($compiled['shell_artifacts'], static fn(array $candidate): bool => $area === ($candidate['area'] ?? null)));
                if (!is_array($shell)) { $artifacts = array(); $assets = array(); break 2; }
                $slug = 1 === $variantCount ? $area : $area . '-' . ($variant + 1);
                $artifacts[] = array_merge($shell, array(
                    'slug' => $slug,
                    'source_path' => $sourcePath . '#' . $slug,
                    'source_paths' => array_keys($documents),
                    'source_hash' => hash('sha256', $canonicalMarkups[$variant]),
                    'variant' => $variant + 1,
                    'placement' => array('kind' => 'inline_shared_shell', 'source_path' => 'wordpress-site-plan/shared/' . $slug, 'source_paths' => array_keys($documents), 'variant' => $variant + 1),
                ));
                foreach ($compiled['assets'] as $asset) {
                    if (!is_array($asset)) continue;
                    $asset['compilation'] = array('scope' => 'shared');
                    $assets[] = $asset;
                }
                $authorStylesheetProjections = array_merge($authorStylesheetProjections, $compiled['author_stylesheet_projections']);
                $runtimeScriptProjections = array_merge($runtimeScriptProjections, $compiled['runtime_script_projections']);
            }
        }
        return array(
            'artifacts' => $artifacts,
            'assets' => $assets,
            'author_stylesheet_projections' => $authorStylesheetProjections,
            'runtime_script_projections' => $runtimeScriptProjections,
        );
    }

    private static function normalizeSourceShellIdentity(string $markup): string
    {
        $dom = new \DOMDocument();
        $loaded = self::loadUtf8Html($dom, '<body>' . $markup . '</body>');
        if (!$loaded) return $markup;
        $xpath = new \DOMXPath($dom);
        $currentNodes = array();
        foreach ($xpath->query('//*[@aria-current] | //*[contains(concat(" ", normalize-space(@data-state), " "), " selected ")]') ?: array() as $node) if ($node instanceof \DOMElement) $currentNodes[] = $node;
        foreach ($currentNodes as $node) {
            for ($cursor = $node; $cursor instanceof \DOMElement; $cursor = $cursor->parentNode) {
                if ($cursor->hasAttribute('data-state')) $cursor->setAttribute('data-state', preg_replace('/\bselected\b/', 'false', $cursor->getAttribute('data-state')) ?? $cursor->getAttribute('data-state'));
                $classes = preg_split('/\s+/', trim($cursor->getAttribute('class'))) ?: array();
                if (array() !== $classes && $cursor->parentNode instanceof \DOMElement) {
                    $siblingClasses = array();
                    foreach ($cursor->parentNode->childNodes as $sibling) {
                        if (!$sibling instanceof \DOMElement || $sibling === $cursor || $sibling->tagName !== $cursor->tagName) continue;
                        foreach (preg_split('/\s+/', trim($sibling->getAttribute('class'))) ?: array() as $class) $siblingClasses[$class] = true;
                    }
                    if (array() !== $siblingClasses) $cursor->setAttribute('class', implode(' ', array_values(array_filter($classes, static fn(string $class): bool => isset($siblingClasses[$class])))));
                }
                if ('nav' === strtolower($cursor->tagName) || 'navigation' === strtolower($cursor->getAttribute('role'))) break;
            }
        }
        foreach ($xpath->query('//*[@aria-current]') ?: array() as $node) if ($node instanceof \DOMElement) $node->removeAttribute('aria-current');
        foreach ($xpath->query('//*[@style]') ?: array() as $node) {
            if (!$node instanceof \DOMElement) continue;
            $style = strtolower(preg_replace('/\s+/', '', $node->getAttribute('style')) ?? '');
            $style = rtrim($style, ';');
            if ('' === $style) $node->removeAttribute('style');
            else $node->setAttribute('style', $style);
        }
        $body = $dom->getElementsByTagName('body')->item(0);
        $root = $body instanceof \DOMElement ? $body->firstElementChild : null;
        return $root instanceof \DOMElement ? ($dom->saveHTML($root) ?: $markup) : $markup;
    }

    private static function loadUtf8Html(\DOMDocument $dom, string $html): bool
    {
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors(); libxml_use_internal_errors($previous);
        return $loaded;
    }

    /** @param array<int,string> $markups @return array<int,string> */
    private static function normalizeSourceShellRootClasses(array $markups): array
    {
        $classSets = array();
        foreach ($markups as $markup) {
            preg_match('/^<[^>]+\sclass="([^"]*)"/i', $markup, $match);
            $classSets[] = array_values(array_filter(preg_split('/\s+/', trim($match[1] ?? '')) ?: array()));
        }
        if (count($markups) < 3 && 1 !== count(array_unique(array_map(static fn(array $classes): string => implode("\0", $classes), $classSets)))) return $markups;
        $counts = array(); $order = array();
        foreach ($classSets as $classes) foreach (array_unique($classes) as $class) {
            if (!isset($counts[$class])) $order[] = $class;
            $counts[$class] = ($counts[$class] ?? 0) + 1;
        }
        $threshold = intdiv(count($markups), 2) + 1;
        $consensus = array_values(array_filter($order, static fn(string $class): bool => ($counts[$class] ?? 0) >= $threshold));
        return array_map(static fn(string $markup): string => preg_replace('/^(<[^>]+\sclass=")[^"]*(")/i', '$1' . implode(' ', $consensus) . '$2', $markup, 1) ?? $markup, $markups);
    }

    /**
     * @param array{files: array<int, array<string, mixed>>, bytes: int, source_hash: string} $artifact
     * @param array<int, array<string, mixed>> $documents
     * @param array<int, array<string, mixed>> $assets
     * @param array<int, array<string, mixed>> $blockTypes
     * @return array<string, mixed>
     */
    private function compiledSiteReport(array $artifact, string $entryPath, array $documents, array &$assets, array $blockTypes, string $serializedBlocks, array $entryShellArtifacts = array(), array $compiledHtmlDocuments = array(), array $inlineShellArtifacts = array()): array
    {
        $pages = array();
        $assetPayloadsByPath = array();
        foreach ( $assets as $asset ) {
            $path = (string) ($asset['path'] ?? '');
            $payload = is_string($asset['visual_payload'] ?? null) ? $asset['visual_payload'] : (is_string($asset['content_base64'] ?? null) ? $asset['content_base64'] : (string) ($asset['content'] ?? ''));
            $assetPayloadsByPath[$path][hash('sha256', $payload)] = true;
        }
        $entryTitle = '';
        foreach ( $artifact['files'] as $file ) {
            if ( $entryPath === ($file['path'] ?? '') ) {
                $entryTitle = $this->titleFromHtml((string) ($file['content'] ?? ''), $entryPath, $entryPath);
                break;
            }
        }
        foreach ( $artifact['files'] as $file ) {
            if ( 'html' !== ($file['kind'] ?? '') || $this->isTemplatePartFile($file) ) {
                continue;
            }

            $path = (string) ($file['path'] ?? '');
            $title = $this->titleFromHtml((string) ($file['content'] ?? ''), $path, $entryPath, $entryTitle);
            $slug = $this->slugFromPath($path);
            $content = (string) ($file['content'] ?? '');
            $compiledBlocks = $path === $entryPath
                ? array('serialized_blocks' => $serializedBlocks, 'assets' => array(), 'shell_artifacts' => $entryShellArtifacts)
                : ($compiledHtmlDocuments[$path] ?? $this->compileHtmlDocumentBlocks($content, $path, $artifact['files'], 'artifact-document', '', true));
            foreach ( $compiledBlocks['assets'] ?? array() as $generatedAsset ) {
                if ( is_array($generatedAsset) ) {
                    if ( 'css' === ($generatedAsset['kind'] ?? null) ) {
                        $generatedAsset['compilation'] = array('scope' => 'page', 'id' => $path);
                    }
                    $generatedAssetPath = (string) ($generatedAsset['path'] ?? '');
                    $payload = is_string($generatedAsset['visual_payload'] ?? null) ? $generatedAsset['visual_payload'] : (is_string($generatedAsset['content_base64'] ?? null) ? $generatedAsset['content_base64'] : (string) ($generatedAsset['content'] ?? ''));
                    $payloadHash = hash('sha256', $payload);
                    if ( isset($assetPayloadsByPath[$generatedAssetPath][$payloadHash]) ) {
                        continue;
                    }
                    $assets[] = $generatedAsset;
                    $assetPayloadsByPath[$generatedAssetPath][$payloadHash] = true;
                }
            }
            $blockMarkup = (string) ($compiledBlocks['serialized_blocks'] ?? '');
            if ( '' === $blockMarkup && '' !== trim($content) ) {
                $blockMarkup = $this->htmlDocumentBlockMarkup($content);
            }
            $bodyFormat = '' !== trim($blockMarkup) ? 'blocks' : 'html';
            $pages[] = array_filter(
                array(
                    'source_path'    => $path,
                    'kind'           => 'html',
                    'role'           => $file['role'] ?? 'document',
                    'entrypoint'     => $path === $entryPath,
                    'slug'           => $slug,
                    'title'          => $title,
                    'metadata'       => array_merge($this->documentMetadata($path, 'html', (string) ($file['role'] ?? 'document'), $slug, $title, $bodyFormat), is_string($file['metadata']['route_path'] ?? null) ? array('route_path' => $file['metadata']['route_path']) : array(), is_string($file['metadata']['post_type'] ?? null) ? array('post_type' => $file['metadata']['post_type'], 'post_type_declaration' => 'metadata:post_type') : array(), is_array($file['metadata']['template_surface'] ?? null) ? array('template_surface' => $file['metadata']['template_surface']) : array()),
                    'document_metadata' => $this->fullDocumentMetadata($content, $path, $artifact['files'], $path === $entryPath ? $assets : ($compiledBlocks['assets'] ?? array())),
                    'html'           => $file['content'] ?? '',
                    'body_format'    => $bodyFormat,
                    'block_markup'   => $blockMarkup,
                    'shell_artifacts' => is_array($compiledBlocks['shell_artifacts'] ?? null) ? $compiledBlocks['shell_artifacts'] : array(),
                    'runtime_islands' => is_array($compiledBlocks['runtime_islands'] ?? null) ? $compiledBlocks['runtime_islands'] : array(),
                    'bytes'          => $file['bytes'] ?? 0,
                    'mime_type'      => $file['mime_type'] ?? 'text/html',
                    'asset_references' => $this->assetReferencePaths($assets),
                    'provenance'     => $file['provenance'] ?? array(),
                ),
                static fn (mixed $value): bool => array() !== $value
            );
        }

        foreach ( $documents as $document ) {
            $pages[] = array_filter(
                array(
                    'source_path'  => $document['source_path'] ?? '',
                    'kind'         => $document['kind'] ?? 'document',
                    'role'         => 'document',
                    'entrypoint'   => false,
                    'slug'         => $document['slug'] ?? '',
                    'title'        => $document['title'] ?? '',
                    'metadata'     => $this->documentMetadata(
                        (string) ($document['source_path'] ?? ''),
                        (string) ($document['kind'] ?? 'document'),
                        'document',
                        (string) ($document['slug'] ?? ''),
                        (string) ($document['title'] ?? ''),
                        (string) ($document['body_format'] ?? ''),
                        $document
                    ),
                    'body_format'  => $document['body_format'] ?? '',
                    'block_markup' => $document['block_markup'] ?? '',
                    'provenance'   => $document['provenance'] ?? array(),
                ),
                static fn (mixed $value): bool => array() !== $value
            );
        }

        $templateParts = $this->compiledSiteTemplateParts($artifact['files']);
        // Preserve the v1 report's established entry-shell shape while the v2
        // plan uses complete shell candidates for cross-page comparison.
        $partSlugs = array_fill_keys(array_column($templateParts, 'slug'), true);
        foreach ( $entryShellArtifacts as $shellArtifact ) {
            if ( ! is_array($shellArtifact) ) {
                continue;
            }
            $slug = (string) ($shellArtifact['slug'] ?? '');
            if ( isset($partSlugs[$slug]) ) {
                $shellArtifact['slug'] = 'entry-' . $slug;
            }
            $partSlugs[(string) $shellArtifact['slug']] = true;
            if ( is_string($shellArtifact['template_part_block_markup'] ?? null) ) {
                $shellArtifact['block_markup'] = $shellArtifact['template_part_block_markup'];
                unset($shellArtifact['template_part_block_markup'], $shellArtifact['inner_block_markup']);
            } elseif ( is_string($shellArtifact['inner_block_markup'] ?? null) ) {
                $shellArtifact['block_markup'] = $shellArtifact['inner_block_markup'];
                unset($shellArtifact['inner_block_markup']);
            }
            $templateParts[] = $shellArtifact;
        }

        return array(
            'schema'      => 'blocks-engine/php-transformer/compiled-site/v1',
            'source_hash' => $artifact['source_hash'],
            'entry_path'  => $entryPath,
            'pages'       => $pages,
            'assets'      => $this->compiledSiteAssets($assets),
            'template_parts' => $templateParts,
            'inline_shell_artifacts' => $inlineShellArtifacts,
            'visual_repair' => $this->compiledSiteVisualRepair($assets, $artifact['files']),
            'runtime_declarations' => $artifact['runtime_declarations'],
            'theme'       => array_filter(
                array(
                    'stylesheets' => $this->assetPathsByIntentOrRole($assets, 'style', 'stylesheet'),
                    'scripts'     => $this->assetPathsByIntentOrRole($assets, 'behavior', 'script'),
                    'fonts'       => $this->assetPathsByRole($assets, 'font'),
                    'images'      => $this->assetPathsByRole($assets, 'image'),
                    'font_link_html' => $this->themeFontLinkHtml($artifact['files']),
                    'static_css'  => $this->themeStaticCss($artifact['files']),
                    'font_css_sources' => $this->themeFontCssSources($artifact['files']),
                    'template_parts' => array_values(array_map(
                        static fn (array $part): string => (string) ($part['source_path'] ?? ''),
                        $templateParts
                    )),
                    'block_types' => array_values(array_map(
                        static fn (array $blockType): string => (string) ($blockType['name'] ?? ''),
                        $blockTypes
                    )),
                ),
                static fn (mixed $value): bool => '' !== $value && array() !== $value
            ),
            'totals'      => array(
                'pages'       => count($pages),
                'assets'      => count($assets),
                'input_bytes' => $artifact['bytes'],
            ),
        );
    }

    private function htmlDocumentBlockMarkup(string $html): string
    {
        if ( '' === trim($html) ) {
            return '';
        }
        if ( $this->containsBlockMarkup($html) ) {
            return $html;
        }

        $result = ( new HtmlTransformer() )->transform($html, array(
            'source'       => 'html-document',
            'source_scope' => 'artifact-document',
        ))->toArray();

        return isset($result['serialized_blocks']) && is_scalar($result['serialized_blocks']) ? trim((string) $result['serialized_blocks']) : '';
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<string, array<string, mixed>>
     */
    private function assetMetadataForSource(string $sourcePath, array $files): array
    {
        $metadata = array();
        $candidates = array() !== $this->filesByPath ? $this->imageFiles : $files;
        foreach ( $candidates as $file ) {
            if ( $this->isMaterializedHtmlDocument($file) ) {
                continue;
            }

            $path = (string) ($file['path'] ?? '');
            $mimeType = (string) ($file['mime_type'] ?? '');
            if ( ! str_starts_with($mimeType, 'image/') ) {
                continue;
            }
            if ( '' === $path ) {
                continue;
            }

            $asset = array(
                'url'       => $path,
                'path'      => $path,
                'mime_type' => $mimeType,
            );

            foreach ( $this->assetLookupKeysForSource($path, $sourcePath) as $key ) {
                $metadata[$key] = $asset;
            }
        }

        return $metadata;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function runtimeScriptMetadataForSource(string $html, string $sourcePath, array $files): array
    {
        if ( ! preg_match_all('/<script\b[^>]*>/i', $html, $matches) ) {
            return array();
        }

        $metadata = array();
        foreach ( $matches[0] as $index => $tag ) {
            $src = $this->htmlAttribute((string) $tag, 'src');
            if ( '' === $src ) {
                continue;
            }

            $asset = $this->findAssetByHtmlReference($src, $sourcePath, $files);
            if ( ! is_array($asset) || ! $this->isMaterializedScriptAsset($asset) ) {
                continue;
            }

            $metadata[] = array_filter(array(
                'path'               => (string) ($asset['path'] ?? ''),
                'selector'           => 'script:nth-of-type(' . ($index + 1) . ')',
                'attributes'         => array_filter(array(
                    'src'   => $src,
                    'type'  => $this->htmlAttribute((string) $tag, 'type'),
                    'async' => $this->htmlAttribute((string) $tag, 'async'),
                    'defer' => $this->htmlAttribute((string) $tag, 'defer'),
                ), static fn (string $value): bool => '' !== $value),
                'script_role'        => 'runtime',
                'script_source_kind' => 'external',
            ), static fn (mixed $value): bool => '' !== $value && array() !== $value);
        }

        return $this->dedupeRows($metadata);
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array{path: string, content: string}>
     */
    private function runtimeProjectionScriptAssetsForSource(string $html, string $sourcePath, array $files): array
    {
        if ( ! preg_match_all('/<script\b([^>]*)>(.*?)<\/script>/is', $html, $matches, PREG_SET_ORDER) ) {
            return array();
        }

        $assets = array();
        $scriptIndex = 0;
        foreach ( $matches as $match ) {
            ++$scriptIndex;
            $src = $this->htmlAttribute((string) $match[1], 'src');
            $asset = '' === $src
                ? $this->findInlineScriptAsset($sourcePath, $scriptIndex, $files)
                : $this->findAssetByHtmlReference($src, $sourcePath, $files);
            if ( ! is_array($asset) || ! $this->isMaterializedScriptAsset($asset) || ! is_string($asset['path'] ?? null) || ! is_string($asset['content'] ?? null) ) {
                continue;
            }
            $assets[] = array('path' => $asset['path'], 'content' => $asset['content']);
        }

        return $this->dedupeRows($assets);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function dedupeRows(array $rows): array
    {
        return DeterministicRowDeduplicator::dedupe($rows);
    }

    /**
     * @return array<int, string>
     */
    private function assetLookupKeysForSource(string $assetPath, string $sourcePath): array
    {
        $keys = array($assetPath, '/' . $assetPath);
        $relativePath = $this->relativePathFromSource($assetPath, $sourcePath);
        if ( '' !== $relativePath ) {
            $keys[] = $relativePath;
            if ( ! str_starts_with($relativePath, '../') ) {
                $keys[] = './' . $relativePath;
            }
        }

        return array_values(array_unique(array_filter($keys, static fn (string $key): bool => '' !== $key)));
    }

    private function relativePathFromSource(string $assetPath, string $sourcePath): string
    {
        $sourceDir = '' === $sourcePath || ! str_contains($sourcePath, '/') ? '' : dirname($sourcePath);
        if ( '' === $sourceDir ) {
            return $assetPath;
        }

        $sourceParts = explode('/', $sourceDir);
        $assetParts = explode('/', $assetPath);
        while ( array() !== $sourceParts && array() !== $assetParts && $sourceParts[0] === $assetParts[0] ) {
            array_shift($sourceParts);
            array_shift($assetParts);
        }

        return implode('/', array_merge(array_fill(0, count($sourceParts), '..'), $assetParts));
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function documentMetadata(string $sourcePath, string $kind, string $role, string $slug, string $title, string $bodyFormat, array $document = array()): array
    {
        return array_filter(
            array(
                'source_path' => $sourcePath,
                'kind'        => $kind,
                'role'        => $role,
                'post_type'   => $document['post_type'] ?? ('document' === $role ? 'page' : ''),
                'slug'        => $slug,
                'title'       => $title,
                'excerpt'     => $document['excerpt'] ?? '',
                'date'        => $document['date'] ?? '',
                'template'    => $document['template'] ?? '',
                'taxonomies'  => $document['taxonomies'] ?? array(),
                'frontmatter' => $document['frontmatter'] ?? array(),
                'body_format' => $bodyFormat,
            ),
            static fn (mixed $value): bool => '' !== $value && array() !== $value
        );
    }

    /** @param array<int, array<string, mixed>> $files @param array<int, array<string, mixed>> $generatedAssets @return array<string, mixed> */
    private function fullDocumentMetadata(string $html, string $sourcePath, array $files, array $generatedAssets = array()): array
    {
        $headEnd = preg_match('/<head\b[^>]*>.*?<\/head\s*>/is', $html, $head) ? (int) strpos($html, $head[0]) + strlen($head[0]) : 0;
        $reference = static fn(string $value): array => array('url' => $value);
        $attributes = function (string $tag, array $names): array {
            $values = array();
            foreach ($names as $name) {
                if (!$this->hasHtmlAttribute($tag, $name)) continue;
                $value = $this->htmlAttribute($tag, $name);
                // HTML's empty and invalid-value CORS states both select anonymous.
                $values[str_replace('-', '_', $name)] = 'crossorigin' === $name && '' === $value ? 'anonymous' : $value;
            }
            return $values;
        };
        $placement = static fn(int $offset): string => $offset < $headEnd ? 'head' : 'body';
        $inlineScripts = array();
        foreach ($generatedAssets as $asset) if ('inline-script' === ($asset['source'] ?? null) && is_string($asset['selector'] ?? null) && is_string($asset['path'] ?? null)) $inlineScripts[$asset['selector']] = $asset['path'];
        $meta = array(); $links = array(); $scripts = array();
        if (preg_match_all('/<meta\b[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE)) foreach ($matches[0] as $match) {
            $tag = (string) $match[0];
            $row = $attributes($tag, array('charset', 'name', 'property', 'http-equiv', 'content'));
            if (array() !== $row) { $row = array_merge(array('order' => count($meta), 'placement' => $placement((int) $match[1])), $row); $meta[] = $row; }
        }
        foreach (StyleTagScanner::scanLinks($html) as $link) {
            $tag = $link['tag']; $href = $this->htmlAttribute($tag, 'href');
            if ('' === $href) continue;
            $links[] = array_merge(array('order' => count($links), 'placement' => $placement($link['offset'])), $attributes($tag, array('rel', 'type', 'media', 'integrity', 'crossorigin', 'referrerpolicy', 'as', 'fetchpriority', 'sizes')), $reference($href));
        }
        if (preg_match_all('/<script\b[^>]*>(?:.*?)<\/script\s*>/is', $html, $matches, PREG_OFFSET_CAPTURE)) foreach ($matches[0] as $match) {
            $tag = (string) $match[0]; $open = strstr($tag, '>', true) . '>'; $src = $this->htmlAttribute($open, 'src');
            $async = $this->hasHtmlAttribute($open, 'async'); $defer = $this->hasHtmlAttribute($open, 'defer'); $module = 'module' === strtolower($this->htmlAttribute($open, 'type'));
            $selector = 'script:nth-of-type(' . (count($scripts) + 1) . ')';
            $supersededBy = $this->htmlAttribute($open, 'data-blocks-engine-superseded-by');
            $inlineBodyHash = hash('sha256', trim((string) preg_replace('/^.*?>|<\/script\s*>$/is', '', $tag)));
            $inline = isset($inlineScripts[$selector]) ? $reference($inlineScripts[$selector]) : array('source_kind' => 'inline', 'body_hash' => $inlineBodyHash);
            if ( '' !== $supersededBy ) $inline = array_merge($inline, array('selector' => $selector, 'superseded_by' => $supersededBy, 'body_hash' => $inlineBodyHash));
            $scripts[] = array_merge(array('order' => count($scripts), 'placement' => $placement((int) $match[1]), 'async' => $async, 'defer' => $defer, 'module' => $module, 'nomodule' => $this->hasHtmlAttribute($open, 'nomodule'), 'effective_loading' => $async ? 'async' : (($defer || $module) ? 'defer' : 'blocking')), $attributes($open, array('type', 'integrity', 'crossorigin', 'referrerpolicy', 'fetchpriority')), '' !== $src ? $reference($src) : $inline);
        }
        $title = preg_match('/<title\b[^>]*>(.*?)<\/title\s*>/is', $html, $match) ? trim(html_entity_decode(strip_tags((string) $match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : $this->titleFromHtml($html, $sourcePath);
        return array('source_context' => array('source_path' => $sourcePath, 'kind' => 'html'), 'title' => $title, 'title_declaration' => array('order' => 0, 'placement' => 'head'), 'meta' => $meta, 'links' => $links, 'scripts' => $scripts);
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function compiledSiteTemplateParts(array $files): array
    {
        $parts = array();
        foreach ( $files as $file ) {
            $path = (string) ($file['path'] ?? '');
            if ( ! $this->isTemplatePartFile($file) ) {
                continue;
            }

            $slug = $this->slugFromPath($path);
            $area = $this->templatePartArea($path, (string) ($file['role'] ?? ''));
            $tagName = ShellLandmarkPolicy::templatePartTagName($path, (string) ($file['role'] ?? ''));
            $parts[] = array_filter(
                array(
                    'source_path'  => $path,
                    'slug'         => $slug,
                    'title'        => $this->titleFromPath($path),
                    'area'         => $area,
                    'tag_name'     => $tagName,
                    'body_format'  => (string) ($file['kind'] ?? ''),
                    'block_markup' => $this->htmlDocumentBlockMarkup((string) ($file['content'] ?? '')),
                    'document_metadata' => $this->fullDocumentMetadata((string) ($file['content'] ?? ''), $path, $files),
                    'runtime_islands' => array(),
                    'bytes'        => $file['bytes'] ?? 0,
                    'provenance'   => $file['provenance'] ?? array(),
                    'placement'    => 'aside' === $tagName
                        ? array('kind' => 'shared_shell', 'source_path' => $path, 'template_slugs' => array('index', 'page', 'front-page'))
                        : array('kind' => 'unbound'),
                ),
                static fn (mixed $value): bool => '' !== $value && array() !== $value
            );
        }

        return $parts;
    }

    /**
     * @param array<string, mixed> $file
     */
    private function isTemplatePartFile(array $file): bool
    {
        $path = (string) ($file['path'] ?? '');
        $role = (string) ($file['role'] ?? '');
        return 'html' === ($file['kind'] ?? '') && ('template-part' === $role || preg_match('#(^|/)(parts|template-parts)/[^/]+\.html?$#i', $path));
    }

    private function templatePartArea(string $path, string $role): string
    {
        return ShellLandmarkPolicy::templatePartArea($path, $role);
    }

    /**
     * @param array<int, array<string, mixed>> $assets
     * @return array<string, mixed>
     */
    private function compiledSiteVisualRepair(array $assets, array $files): array
    {
        $stylesheets = array_values(array_filter($assets, fn (array $asset): bool => $this->isVisualRepairStylesheet($asset)));
        $css = '';
        foreach ( $stylesheets as $asset ) {
            if ( isset($asset['content']) && is_string($asset['content']) ) {
                $css .= ('' === $css ? '' : "\n") . $asset['content'];
            }
        }
        $staticCss = $this->themeStaticCss($files, false);
        $navigationCompatCss = $this->wordpressCompat->css($staticCss, $files, $this->allScriptContents($files));
        if ( '' !== $navigationCompatCss ) {
            $css .= ('' === $css ? '' : "\n") . $navigationCompatCss;
        }

        return array_filter(
            array(
                'stylesheets' => array_values(array_map(
                    static fn (array $asset): array => array_filter(
                        array(
                            'path'      => $asset['path'] ?? '',
                            'role'      => $asset['role'] ?? '',
                            'intent'    => $asset['intent'] ?? '',
                            'mime_type' => $asset['mime_type'] ?? '',
                            'bytes'     => $asset['bytes'] ?? 0,
                        ),
                        static fn (mixed $value): bool => '' !== $value
                    ),
                    $stylesheets
                )),
                'css'         => $css,
                'compat_css'  => $navigationCompatCss,
            ),
            static fn (mixed $value): bool => '' !== $value && array() !== $value
        );
    }

    /**
     * @param array<string, mixed> $asset
     */
    private function isVisualRepairStylesheet(array $asset): bool
    {
        $path = (string) ($asset['path'] ?? '');
        $role = (string) ($asset['role'] ?? '');
        $intent = (string) ($asset['intent'] ?? '');
        return 'css' === ($asset['kind'] ?? '') && ('visual-repair' === $role || 'visual-repair' === $intent || preg_match('/(?:^|[-_\/])visual[-_]repair(?:[-_\/]|\.)/i', $path));
    }

    private function titleFromHtml(string $html, string $path, string $entryPath = '', string $entryTitle = ''): string
    {
        $normalize = static function (string $titleHtml): string {
            $titleHtml = preg_replace('/<\s*(?:br|\/\s*(?:div|h[1-6]|p))\b[^>]*>/i', ' ', $titleHtml) ?? $titleHtml;
            $titleHtml = str_replace("\u{00A0}", ' ', html_entity_decode(strip_tags($titleHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            return trim(preg_replace('/\s+/', ' ', $titleHtml) ?? '');
        };

        $contentHeading = '';
        if ( preg_match_all('/<h1\b[^>]*>(.*?)<\/h1>/is', $html, $matches) ) {
            foreach ( $matches[1] as $headingHtml ) {
                if ( $this->headingIsHyperlinkChrome($headingHtml) ) {
                    continue;
                }
                $title = $normalize($headingHtml);
                if ( '' !== $title ) {
                    $contentHeading = $title;
                    break;
                }
            }
        }
        if ( '' !== $contentHeading ) {
            return $contentHeading;
        }

        if ( preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $match) ) {
            $title = $normalize($match[1]);
            if ( '' !== $title && ( $path === $entryPath || '' === $entryPath || $title !== $entryTitle ) ) {
                return $title;
            }
        }

        return $this->titleFromPath($path);
    }

    private function headingIsHyperlinkChrome(string $headingHtml): bool
    {
        $remaining = trim($headingHtml);
        while ( preg_match('/^<(?:span|mark)\b[^>]*>([\s\S]*)<\/(?:span|mark)>$/is', $remaining, $match) ) {
            $remaining = trim($match[1]);
        }

        return (bool) preg_match('/^<a\b[^>]*>[\s\S]*<\/a>$/is', $remaining);
    }

    /**
     * @param array<int, array<string, mixed>> $assets
     * @return array<int, array<string, mixed>>
     */
    private function compiledSiteAssets(array $assets): array
    {
        return array_values(array_map(
            static fn (array $asset): array => array_filter(
                array(
                    'source'           => $asset['source'] ?? '',
                    'path'             => $asset['path'] ?? '',
                    'target_path'      => $asset['target_path'] ?? $asset['path'] ?? '',
                    'kind'             => $asset['kind'] ?? '',
                    'role'             => $asset['role'] ?? '',
                    'stylesheet_placement' => $asset['stylesheet_placement'] ?? '',
                    'stylesheet_target' => 'css' === ($asset['kind'] ?? '') ? ($asset['stylesheet_target'] ?? 'both') : '',
                    'intent'           => $asset['intent'] ?? '',
                    'media_type'       => $asset['media_type'] ?? $asset['mime_type'] ?? '',
                    'media'            => $asset['media'] ?? '',
                    'mime_type'        => $asset['mime_type'] ?? '',
                    'bytes'            => $asset['bytes'] ?? 0,
                    'binary'           => $asset['binary'] ?? false,
                    'content_encoding' => $asset['content_encoding'] ?? $asset['encoding'] ?? '',
                    'content'          => $asset['content'] ?? null,
                    'content_base64'   => $asset['content_base64'] ?? null,
                    'payload_reference' => $asset['payload_reference'] ?? null,
                    'raw_sha256'       => $asset['raw_sha256'] ?? null,
                    'transport_sha256' => $asset['transport_sha256'] ?? null,
                    'hash'             => $asset['hash'] ?? $asset['provenance']['hash'] ?? '',
                    'source_hash'      => $asset['source_hash'] ?? '',
                    'source_role'      => $asset['source_role'] ?? '',
                    'keep_source'      => $asset['keep_source'] ?? null,
                    'pipeline_sanitized' => $asset['pipeline_sanitized'] ?? null,
                    'placement'        => $asset['placement'] ?? '',
                    'type'             => $asset['type'] ?? '',
                    'defer'            => $asset['defer'] ?? false,
                    'async'            => $asset['async'] ?? false,
                    'source_path'      => $asset['source_path'] ?? '',
                    'selector'         => $asset['selector'] ?? '',
                    'references'       => $asset['references'] ?? array(),
                    'compilation'      => 'css' === ($asset['kind'] ?? null) ? ($asset['compilation'] ?? null) : null,
                ),
                static fn (mixed $value, string $key): bool => ('content' === $key && is_string($value)) || (null !== $value && '' !== $value),
                ARRAY_FILTER_USE_BOTH
            ),
            $assets
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $assets
     * @return array<int, string>
     */
    private function assetReferencePaths(array $assets): array
    {
        return array_values(array_map(static fn (array $asset): string => (string) ($asset['path'] ?? ''), $assets));
    }

    /**
     * @param array<int, array<string, mixed>> $assets
     * @return array<int, string>
     */
    private function assetPathsByIntentOrRole(array $assets, string $intent, string $role): array
    {
        return array_values(array_map(
            static fn (array $asset): string => (string) ($asset['path'] ?? ''),
            array_filter($assets, static fn (array $asset): bool => $intent === ($asset['intent'] ?? '') || $role === ($asset['role'] ?? ''))
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $assets
     * @return array<int, string>
     */
    private function assetPathsByRole(array $assets, string $role): array
    {
        return array_values(array_map(
            static fn (array $asset): string => (string) ($asset['path'] ?? ''),
            array_filter($assets, static fn (array $asset): bool => $role === ($asset['role'] ?? ''))
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, array<string, mixed>>
     */
    private function entryTransformDiagnostics(array $diagnostics, string $sourcePath = ''): array
    {
        $diagnostics = array_values(array_filter(
            $diagnostics,
            static fn (array $diagnostic): bool => 'html_to_blocks_core_slice' !== ($diagnostic['code'] ?? '')
        ));
        if ( '' !== $sourcePath ) foreach ( $diagnostics as &$diagnostic ) if ( !isset($diagnostic['source_path']) ) $diagnostic['source_path'] = $sourcePath;
        unset($diagnostic);
        return $diagnostics;
    }

    /**
     * @param array{files: array<int, array<string, mixed>>} $artifact
     * @return array{documents: array<int, array<string, mixed>>, components: array<int, array<string, mixed>>, diagnostics: array<int, array<string, mixed>>}
     */
    private function compileSourceDocuments(array $artifact): array
    {
        $documents = array();
        $components = array();
        $diagnostics = array();

        foreach ( $artifact['files'] as $file ) {
            if ( ! in_array($file['kind'], array('markdown', 'mdx'), true) || ! empty($file['binary']) ) {
                continue;
            }

            $parsed = $this->parseFrontmatter((string) $file['content']);
            $body = $parsed['body'];
            $frontmatter = $parsed['frontmatter'];
            $documentDiagnostics = array();

            if ( 'mdx' === $file['kind'] ) {
                $mdx = $this->extractMdxSemantics($body, $file, $artifact);
                $body = $mdx['markdown_body'];
                $components = array_merge($components, $mdx['components']);
                $documentDiagnostics = array_merge($documentDiagnostics, $mdx['diagnostics']);
            }

            $conversion = $this->convertMarkdownToBlocks($body);
            $documentDiagnostics = array_merge($documentDiagnostics, $conversion['diagnostics']);
            $diagnostics = array_merge($diagnostics, $documentDiagnostics);

            $documents[] = array(
                'source_path'  => $file['path'],
                'kind'         => $file['kind'],
                'post_type'    => $this->frontmatterString($frontmatter, array('post_type', 'type'), 'page'),
                'slug'         => $this->frontmatterString($frontmatter, array('slug'), $this->slugFromPath((string) $file['path'])),
                'title'        => $this->frontmatterString($frontmatter, array('title'), $this->titleFromPath((string) $file['path'])),
                'excerpt'      => $this->frontmatterString($frontmatter, array('excerpt', 'description'), ''),
                'date'         => $this->frontmatterString($frontmatter, array('date', 'published', 'published_at'), ''),
                'template'     => $this->frontmatterString($frontmatter, array('template', 'layout'), ''),
                'taxonomies'   => $this->frontmatterTaxonomies($frontmatter),
                'frontmatter'  => $frontmatter,
                'body'         => $body,
                'body_format'  => 'mdx' === $file['kind'] ? 'mdx' : 'markdown',
                'block_markup' => $conversion['serialized_blocks'],
                'diagnostics'  => $documentDiagnostics,
                'provenance'   => $file['provenance'],
            );
        }

        return array(
            'documents'   => $documents,
            'components'  => $components,
            'diagnostics' => $this->dedupeDiagnostics($diagnostics),
        );
    }

    /**
     * @return array{serialized_blocks: string, diagnostics: array<int, array<string, mixed>>}
     */
    private function convertMarkdownToBlocks(string $markdown): array
    {
        $result = ( new FormatBridge() )->convertResult(
            $markdown,
            'markdown',
            'blocks',
            array(
                'source'  => 'artifact_compiler',
                'context' => array(
                    'source_format' => 'markdown',
                    'target_format' => 'blocks',
                ),
            )
        )->toArray();

        if ( 'failed' !== (string) ( $result['status'] ?? '' ) ) {
            return array(
                'serialized_blocks' => (string) ( $result['serialized_blocks'] ?? '' ),
                'diagnostics'       => array_values(array_filter(
                    is_array($result['diagnostics'] ?? null) ? $result['diagnostics'] : array(),
                    static fn (array $diagnostic): bool => 'format_bridge_conversion_completed' !== (string) ($diagnostic['code'] ?? '')
                )),
            );
        }

        $diagnostics = is_array($result['diagnostics'] ?? null) ? $result['diagnostics'] : array();
        $diagnostics[] = $this->diagnostic('markdown_adapter_unavailable', 'warning', 'A Markdown adapter is unavailable; preserved source Markdown as a core/html fallback.');

        return array(
            'serialized_blocks' => '<!-- wp:html -->' . "\n" . $markdown . "\n" . '<!-- /wp:html -->',
            'diagnostics'       => $diagnostics,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @param array<int, string> $entrypoints
     * @return array<string, mixed>|null
     */
    private function entryFile(array $files, array $entrypoints): ?array
    {
        foreach ( $entrypoints as $entrypoint ) {
            foreach ( $files as $file ) {
                if ( $entrypoint === $file['path'] && $this->isEntryFile($file) ) {
                    return $file;
                }
            }
        }
        foreach ( array('index.html', 'index.htm', 'static-site/index.html', 'public/index.html') as $preferred ) {
            foreach ( $files as $file ) {
                if ( $preferred === strtolower((string) $file['path']) && $this->isEntryFile($file) ) {
                    return $file;
                }
            }
        }
        foreach ( $files as $file ) {
            if ( $this->isEntryFile($file) ) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $file
     */
    private function isEntryFile(array $file): bool
    {
        if ( ! empty($file['binary']) ) {
            return false;
        }

        return 'html' === ($file['kind'] ?? '') || 'blocks' === ($file['kind'] ?? '') || $this->containsBlockMarkup((string) ($file['content'] ?? ''));
    }

    private function containsBlockMarkup(string $content): bool
    {
        return str_contains($content, '<!-- wp:');
    }

    /**
     * @param array<int, array<string, mixed>> $files
     */
    private function fileHashPayload(array $files): string
    {
        $payload = '';
        foreach ( $files as $file ) {
            $content = isset($file['content_base64']) ? (string) $file['content_base64'] : (string) $file['content'];
            $payload .= $file['path'] . "\0" . $file['kind'] . "\0" . ($file['mime_type'] ?? '') . "\0" . $content . "\0";
        }

        return $payload;
    }

    private function sanitizeKey(string $key): string
    {
        return preg_replace('/[^a-z0-9_-]+/', '-', strtolower(trim($key))) ?? '';
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function assetManifest(array $files, string $entryPath, array $assetReferences = array(), string $entryHtml = ''): array
    {
        $assets = array();
        $unsupportedStylesheets = $this->unsupportedStylesheetPaths($entryHtml, $entryPath);
        foreach ( $files as $file ) {
            if ( $entryPath === $file['path'] || $this->isMaterializedHtmlDocument($file) || isset($unsupportedStylesheets[$file['path'] ?? '']) ) {
                continue;
            }
            $asset = array(
                'source'           => $file['source'] ?? 'artifact',
                'path'             => $file['path'],
                'target_path'      => $file['path'],
                'kind'             => $file['kind'],
                'bytes'            => $file['bytes'],
                'media_type'       => $file['mime_type'],
                'mime_type'        => $file['mime_type'],
                'role'             => $file['role'],
                'encoding'         => $file['encoding'],
                'content_encoding' => $file['encoding'],
                'binary'           => $file['binary'],
                'hash'             => $file['provenance']['hash'] ?? '',
                'source_hash'      => $file['provenance']['projected_from_hash'] ?? ($file['provenance']['hash'] ?? ''),
                'provenance'       => $file['provenance'],
            );
            if ( ! empty($file['content_base64']) ) {
                $asset['content_base64'] = $file['content_base64'];
            }
            if ( is_string($file['raw_sha256'] ?? null) ) {
                $asset['raw_sha256'] = $file['raw_sha256'];
            }
            if ( is_array($file['payload_reference'] ?? null) ) {
                $asset['payload_reference'] = $file['payload_reference'];
                $asset['raw_sha256'] = $file['raw_sha256'] ?? $file['payload_reference']['sha256'];
            }
            if ( is_string($file['transport_sha256'] ?? null) ) {
                $asset['transport_sha256'] = $file['transport_sha256'];
            }
            if ( empty($file['binary']) && ! $this->isUnsafeSvgAsset($file) ) {
                $asset['content'] = $file['content'];
            }
            if ( ! empty($file['intent']) ) {
                $asset['intent'] = $file['intent'];
            }
            foreach ( array('placement', 'type', 'source_path', 'selector') as $field ) {
                if ( isset($file[$field]) && is_scalar($file[$field]) && '' !== trim((string) $file[$field]) ) {
                    $asset[$field] = (string) $file[$field];
                }
            }
            if ( isset($file['media']) && is_scalar($file['media']) && '' !== trim((string) $file['media']) ) {
                $asset['media'] = (string) $file['media'];
            }
            if ( 'css' === ($file['kind'] ?? null) ) {
                if (is_array($file['metadata']['compilation'] ?? null) || '' !== ArtifactNormalizer::inlineExpansionSourcePath($file)) {
                    $asset['compilation'] = $this->fileOwnership($file);
                }
            }
            foreach ( array('defer', 'async') as $field ) {
                if ( isset($file[$field]) ) {
                    $asset[$field] = (bool) $file[$field];
                }
            }
            $references = $this->referencesForAsset((string) $file['path'], $assetReferences);
            if ( array() !== $references ) {
                $asset['references'] = $references;
            }
            $assets[] = $asset;
        }
        if ( '' === $entryHtml ) {
            return $assets;
        }
        $orderedPaths = array_column($this->stylesheetAssetsForSource($entryHtml, $entryPath, $files), 'path');
        $ordered = array();
        $consumed = array();
        foreach ( $orderedPaths as $path ) {
            if ( isset($consumed[$path]) ) {
                continue;
            }
            foreach ( $assets as $asset ) {
                if ( $path === ($asset['path'] ?? '') ) {
                    $ordered[] = $asset;
                    $consumed[$path] = true;
                    break;
                }
            }
        }
        foreach ( $assets as $asset ) {
            if ( isset($consumed[$asset['path'] ?? '']) ) {
                continue;
            }
            $ordered[] = $asset;
        }
        return $ordered;
    }

    /** @return array<string, true> */
    private function unsupportedStylesheetPaths(string $html, string $sourcePath): array
    {
        $unsupported = array();
        $supported = array();
        foreach ( StyleTagScanner::scanLinks($html) as $link ) {
            $tag = $link['tag'];
            if ( ! StyleTagScanner::isStylesheetRel($this->htmlAttribute((string) $tag, 'rel')) ) {
                continue;
            }
            $path = $this->stylesheetPathFromHref($this->htmlAttribute((string) $tag, 'href'), $sourcePath);
            if ( '' === $path ) {
                continue;
            }
            if ( StyleTagScanner::isCssType($this->htmlAttribute((string) $tag, 'type')) ) {
                $supported[$path] = true;
            } else {
                $unsupported[$path] = true;
            }
        }
        foreach ( $supported as $path => $_true ) {
            unset($unsupported[$path]);
        }
        return $unsupported;
    }

    /**
     * @param array<string, mixed> $file
     */
    private function isMaterializedHtmlDocument(array $file): bool
    {
        return 'html' === ($file['kind'] ?? '') && ($this->isLinkableDocument($file) || $this->isTemplatePartFile($file));
    }

    /**
     * @param array<int, array<string, mixed>> $assetReferences
     * @return array<int, array<string, mixed>>
     */
    private function referencesForAsset(string $path, array $assetReferences): array
    {
        $references = array();
        foreach ( $assetReferences as $reference ) {
            if ( $path !== ($reference['asset_path'] ?? '') ) {
                continue;
            }

            $references[] = array_filter(
                array(
                    'source_path' => $reference['source_path'] ?? '',
                    'selector'    => $reference['selector'] ?? '',
                    'element'     => $reference['element'] ?? '',
                    'attribute'   => $reference['attribute'] ?? '',
                    'value'       => $reference['value'] ?? '',
                    'url'         => $reference['url'] ?? '',
                    'context'     => $reference['context'] ?? '',
                ),
                static fn (mixed $value): bool => '' !== $value
            );
        }

        return $references;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function svgAssetDiagnostics(array $files): array
    {
        $diagnostics = array();
        foreach ( $files as $file ) {
            if ( 'image/svg+xml' !== ($file['mime_type'] ?? '') || empty($file['content']) || $this->isSafeSvgContent((string) $file['content']) ) {
                continue;
            }

            $diagnostics[] = $this->diagnostic('unsafe_svg_asset', 'warning', 'An SVG image asset contains scriptable markup and its inline content was not exposed.', array('path' => $file['path']));
        }

        return $diagnostics;
    }

    /**
     * @param array<string, mixed> $asset
     */
    private function isSafeImageAsset(array $asset): bool
    {
        if (isset($asset['payload_reference'])) return true;
        if ( 'image/svg+xml' !== ($asset['mime_type'] ?? '') ) {
            return true;
        }

        return ! empty($asset['content']) && $this->isSafeSvgContent((string) $asset['content']);
    }

    /**
     * @param array<string, mixed> $file
     */
    private function isUnsafeSvgAsset(array $file): bool
    {
        return 'image/svg+xml' === ($file['mime_type'] ?? '') && ! $this->isSafeSvgContent((string) ($file['content'] ?? ''));
    }

    private function isSafeSvgContent(string $content): bool
    {
        if ( '' === trim($content) ) {
            return false;
        }

        if ( ! preg_match('/<svg(?:\s|>)/i', $content) ) {
            return false;
        }

        return ! preg_match('/<\s*script\b|\son[a-z]+\s*=|javascript\s*:/i', $content);
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<string, mixed>|null
     */
    private function findAssetByHtmlReference(string $reference, string $entryPath, array $files): ?array
    {
        if ( '' === trim($reference) || preg_match('#^[a-z][a-z0-9+.-]*:#i', $reference) ) {
            return null;
        }

        $paths = array_filter(array(
            $this->resolveHtmlReferencePath($reference, $entryPath),
            str_starts_with($reference, '/') ? $this->resolveHtmlReferencePath(ltrim($reference, '/'), $entryPath) : '',
        ), static fn (string $path): bool => '' !== $path);
        $paths = array_values(array_unique($paths));
        if ( array() === $paths ) {
            return null;
        }

        foreach ( $paths as $path ) {
            if ( isset($this->filesByPath[$path]) ) {
                return $this->filesByPath[$path];
            }
        }

        foreach ( $files as $file ) {
            if ( in_array($file['path'] ?? '', $paths, true) ) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Build immutable lookup state for one normalized artifact compilation.
     *
     * @param array<int, array<string, mixed>> $files
     */
    private function indexFiles(array $files): void
    {
        $this->filesByPath = array();
        $this->imageFiles = array();
        $this->scriptContents = array();
        $this->runtimeScriptEvidenceAnalyzer->resetCache();

        foreach ( $files as $file ) {
            if ( ! is_array($file) ) {
                continue;
            }
            $path = (string) ($file['path'] ?? '');
            if ( '' !== $path ) {
                $this->filesByPath[$path] = $file;
            }
            if ( str_starts_with((string) ($file['mime_type'] ?? ''), 'image/') && ! $this->isMaterializedHtmlDocument($file) ) {
                $this->imageFiles[] = $file;
            }
            if ( $this->isMaterializedScriptAsset($file) && is_string($file['content'] ?? null) ) {
                $this->scriptContents[] = (string) $file['content'];
            }
        }
    }
    private function resolveHtmlReferencePath(string $reference, string $entryPath): string
    {
        return ArtifactPath::resolveRelativePath($reference, $entryPath);
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    private function countBlocks(array $blocks): int
    {
        $count = 0;
        foreach ( $blocks as $block ) {
            ++$count;
            if ( ! empty($block['innerBlocks']) && is_array($block['innerBlocks']) ) {
                $count += $this->countBlocks($block['innerBlocks']);
            }
        }

        return $count;
    }

    /**
     * @param array<int, array<string, mixed>> $sourceDocumentComponents
     * @return array<int, array<string, mixed>>
     */
    private function detectComponents(array $files, string $entryPath, array $sourceDocumentComponents = array()): array
    {
        return $this->finalizeComponentFacts($this->collectComponentFacts($files, $sourceDocumentComponents), $entryPath);
    }

    /**
     * Collect the uncapped sufficient statistics used by component detection.
     *
     * @param array<int,array<string,mixed>> $files
     * @param array<int,array<string,mixed>> $sourceDocumentComponents
     * @return array{components:array<int,array<string,mixed>>,classes:array<string,int>}
     */
    private function collectComponentFacts(array $files, array $sourceDocumentComponents = array()): array
    {
        $components = array();
        $classes = array();
        foreach ( $sourceDocumentComponents as $component ) {
            $key = 'mdx:' . (string) ($component['source'] ?? '') . ':' . (string) ($component['name'] ?? '');
            $components[$key] = $component;
        }

        foreach ( $files as $file ) {
            if ( in_array($file['kind'], array('jsx', 'tsx'), true) && empty($file['binary']) ) {
                foreach ( $this->detectJsxFileComponents($file) as $component ) {
                    $components['jsx-file:' . (string) $component['source'] . ':' . (string) $component['name']] = $component;
                }
            }

            if ( 'html' !== $file['kind'] || ! empty($file['binary']) ) {
                continue;
            }

            $content = (string) $file['content'];
            if ( preg_match_all('/data-component\s*=\s*(["\'])([^"\']+)\1/i', $content, $matches) ) {
                foreach ( $matches[2] as $name ) {
                    $key = $this->sanitizeKey($name);
                    if ( '' === $key ) {
                        continue;
                    }
                    $components['explicit:' . $key] = array(
                        'name'        => $key,
                        'source'      => $file['path'],
                        'signal'      => 'data-component',
                        'occurrences' => ($components['explicit:' . $key]['occurrences'] ?? 0) + 1,
                        'provenance'  => array('source_path' => $file['path']),
                    );
                }
            }

            if ( preg_match_all('/class\s*=\s*(["\'])([^"\']+)\1/i', $content, $matches) ) {
                foreach ( $matches[2] as $classList ) {
                    $classTokens = preg_split('/\s+/', trim($classList));
                    foreach ( false === $classTokens ? array() : $classTokens as $class ) {
                        $class = $this->sanitizeKey($class);
                        if ( '' === $class || strlen($class) < 3 ) {
                            continue;
                        }
                        $classes[$class] = ($classes[$class] ?? 0) + 1;
                    }
                }
            }
        }

        return array('components' => array_values($components), 'classes' => $classes);
    }

    /**
     * @param array<int,array{components:array<int,array<string,mixed>>,classes:array<string,int>}> $facts
     * @return array{components:array<int,array<string,mixed>>,classes:array<string,int>}
     */
    private function mergeComponentFacts(array $facts): array
    {
        $components = array();
        $classes = array();
        foreach ($facts as $fact) {
            foreach ($fact['components'] as $component) {
                $identity = (string) ($component['signal'] ?? '') . ':' . (string) ($component['source'] ?? '') . ':' . (string) ($component['name'] ?? '');
                if ('data-component' === ($component['signal'] ?? null)) $identity = 'data-component:' . (string) ($component['name'] ?? '');
                if (isset($components[$identity])) $component['occurrences'] = (int) ($components[$identity]['occurrences'] ?? 1) + (int) ($component['occurrences'] ?? 1);
                $components[$identity] = $component;
            }
            foreach ($fact['classes'] as $class => $count) $classes[$class] = (int) ($classes[$class] ?? 0) + (int) $count;
        }
        return array('components' => array_values($components), 'classes' => $classes);
    }

    /** @param array{components:array<int,array<string,mixed>>,classes:array<string,int>} $facts @return array<int,array<string,mixed>> */
    private function finalizeComponentFacts(array $facts, string $entryPath): array
    {
        $components = $facts['components'];

        foreach ( $facts['classes'] as $class => $count ) {
            if ( $count < 2 && ! preg_match('/(?:card|grid|hero|nav|header|footer|feature|testimonial|pricing|product|gallery|section)/', $class) ) {
                continue;
            }

            $components[] = array(
                'name'        => $class,
                'source'      => $entryPath,
                'signal'      => 'class-token',
                'occurrences' => $count,
                'provenance'  => array('source_path' => $entryPath),
            );
        }

        usort(
            $components,
            static function (array $left, array $right): int {
                $occurrenceComparison = ($right['occurrences'] ?? 1) <=> ($left['occurrences'] ?? 1);
                return 0 !== $occurrenceComparison ? $occurrenceComparison : strcmp((string) $left['name'], (string) $right['name']);
            }
        );

        return array_slice($components, 0, 25);
    }

    /**
     * @param array<string, mixed> $file
     * @return array<int, array<string, mixed>>
     */
    private function detectJsxFileComponents(array $file): array
    {
        $components = array();
        $content = (string) ($file['content'] ?? '');

        if ( preg_match_all('/(?:export\s+default\s+)?function\s+([A-Z][A-Za-z0-9_]*)\s*\(/', $content, $matches) ) {
            foreach ( $matches[1] as $name ) {
                $components[$name] = true;
            }
        }

        if ( preg_match_all('/(?:export\s+)?(?:const|let|var)\s+([A-Z][A-Za-z0-9_]*)\s*=\s*(?:\([^)]*\)|[A-Za-z0-9_]+)\s*=>/', $content, $matches) ) {
            foreach ( $matches[1] as $name ) {
                $components[$name] = true;
            }
        }

        return array_map(
            fn (string $name): array => array(
                'name'        => $name,
                'source'      => (string) ($file['path'] ?? ''),
                'signal'      => 'jsx-component-file',
                'occurrences' => 1,
                'provenance'  => array('source_path' => (string) ($file['path'] ?? '')),
            ),
            array_keys($components)
        );
    }

    /**
     * @return array{frontmatter: array<string, mixed>, body: string}
     */
    private function parseFrontmatter(string $content): array
    {
        if ( ! preg_match('/\A---\s*\R(.*?)\R---\s*\R?/s', $content, $matches) ) {
            return array(
                'frontmatter' => array(),
                'body'        => $content,
            );
        }

        $frontmatter = array();
        $lines = preg_split('/\R/', trim($matches[1]));
        foreach ( false === $lines ? array() : $lines as $line ) {
            if ( ! preg_match('/^([A-Za-z0-9_-]+)\s*:\s*(.*)$/', $line, $pair) ) {
                continue;
            }

            $value = trim($pair[2], " \t\n\r\0\x0B\"'");
            if ( preg_match('/^\[(.*)\]$/', $value, $list) ) {
                $value = array_values(array_filter(array_map(static fn (string $item): string => trim($item, " \t\n\r\0\x0B\"'"), explode(',', $list[1])), static fn (string $item): bool => '' !== $item));
            }

            $frontmatter[$this->sanitizeKey($pair[1])] = $value;
        }

        return array(
            'frontmatter' => $frontmatter,
            'body'        => substr($content, strlen($matches[0])),
        );
    }

    /**
     * @param array<string, mixed> $file
     * @param array{files: array<int, array<string, mixed>>} $artifact
     * @return array{markdown_body: string, components: array<int, array<string, mixed>>, diagnostics: array<int, array<string, mixed>>}
     */
    private function extractMdxSemantics(string $body, array $file, array $artifact): array
    {
        $imports = $this->extractMdxImports($body);
        $components = array();
        $diagnostics = array();
        $sourcePath = (string) $file['path'];

        if ( preg_match_all('/<([A-Z][A-Za-z0-9._-]*)(?:\s[^>]*)?\s*(?:>|\/>)/', $body, $matches) ) {
            foreach ( $matches[1] as $name ) {
                $import = $imports[$name] ?? null;
                $resolved = is_array($import) ? $this->resolveComponentImport((string) $import['path'], $sourcePath, $artifact) : '';
                $component = array(
                    'name'        => $name,
                    'source'      => $sourcePath,
                    'signal'      => 'mdx-jsx',
                    'occurrences' => ($components[$name]['occurrences'] ?? 0) + 1,
                    'provenance'  => array('source_path' => $sourcePath),
                );

                if ( is_array($import) ) {
                    $component['import_path'] = $import['path'];
                }
                if ( '' !== $resolved ) {
                    $component['resolved_path'] = $resolved;
                }

                $components[$name] = $component;

                if ( ! is_array($import) ) {
                    $diagnostics[] = $this->diagnostic('mdx_component_unresolved', 'warning', 'MDX component reference has no matching import.', array('path' => $sourcePath, 'component' => $name));
                } elseif ( '' === $resolved && str_starts_with((string) $import['path'], '.') ) {
                    $diagnostics[] = $this->diagnostic('mdx_import_unresolved', 'warning', 'MDX component import could not be linked to a generated source file.', array('path' => $sourcePath, 'component' => $name, 'import_path' => $import['path']));
                }
            }
        }

        $markdownBody = preg_replace('/^\s*import\s+[^;\r\n]+;?\s*$/m', '', $body) ?? $body;
        $markdownBody = preg_replace('/^\s*export\s+[^\r\n]+\s*$/m', '', $markdownBody) ?? $markdownBody;
        $markdownBody = preg_replace('/<([A-Z][A-Za-z0-9._-]*)(?:\s[^>]*)?\s*\/>/', '', $markdownBody) ?? $markdownBody;
        $markdownBody = preg_replace('/<\/?[A-Z][A-Za-z0-9._-]*(?:\s[^>]*)?>/', '', $markdownBody) ?? $markdownBody;

        return array(
            'markdown_body' => trim($markdownBody),
            'components'    => array_values($components),
            'diagnostics'   => $this->dedupeDiagnostics($diagnostics),
        );
    }

    /**
     * @return array<string, array{path: string}>
     */
    private function extractMdxImports(string $body): array
    {
        $imports = array();
        if ( ! preg_match_all('/^\s*import\s+(.+?)\s+from\s+["\']([^"\']+)["\'];?\s*$/m', $body, $matches, PREG_SET_ORDER) ) {
            return $imports;
        }

        foreach ( $matches as $match ) {
            $clause = trim($match[1]);
            $path = $match[2];
            if ( preg_match('/^([A-Z][A-Za-z0-9_]*)/', $clause, $default) ) {
                $imports[$default[1]] = array('path' => $path);
            }
            if ( preg_match('/\{([^}]+)\}/', $clause, $named) ) {
                foreach ( explode(',', $named[1]) as $name ) {
                    $parts = preg_split('/\s+as\s+/i', trim($name));
                    $alias = trim((string) end($parts));
                    if ( preg_match('/^[A-Z][A-Za-z0-9_]*$/', $alias) ) {
                        $imports[$alias] = array('path' => $path);
                    }
                }
            }
        }

        return $imports;
    }

    /**
     * @param array{files: array<int, array<string, mixed>>} $artifact
     */
    private function resolveComponentImport(string $importPath, string $sourcePath, array $artifact): string
    {
        if ( ! str_starts_with($importPath, '.') ) {
            return '';
        }

        $path = ArtifactPath::resolveRelativePath($importPath, $sourcePath, true);
        if ( '' === $path ) {
            return '';
        }

        $candidates = array($path);
        foreach ( array('js', 'jsx', 'ts', 'tsx', 'mdx') as $extension ) {
            $candidates[] = $path . '.' . $extension;
            $candidates[] = $path . '/index.' . $extension;
        }

        foreach ( $artifact['files'] as $file ) {
            if ( in_array($file['path'], $candidates, true) ) {
                return (string) $file['path'];
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $frontmatter
     * @param array<int, string> $keys
     */
    private function frontmatterString(array $frontmatter, array $keys, string $fallback): string
    {
        foreach ( $keys as $key ) {
            if ( isset($frontmatter[$key]) && is_scalar($frontmatter[$key]) && '' !== trim((string) $frontmatter[$key]) ) {
                return (string) $frontmatter[$key];
            }
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed> $frontmatter
     * @return array<string, mixed>
     */
    private function frontmatterTaxonomies(array $frontmatter): array
    {
        $taxonomies = array();
        foreach ( array('category', 'categories', 'tag', 'tags') as $key ) {
            if ( isset($frontmatter[$key]) ) {
                $taxonomies[$key] = $frontmatter[$key];
            }
        }

        return $taxonomies;
    }

    private function slugFromPath(string $path): string
    {
        $base = preg_replace('/\.[A-Za-z0-9]+$/', '', basename($path));
        $base = '' === $base || null === $base ? 'document' : $base;
        return $this->sanitizeKey(str_replace(array('_', '.'), '-', $base));
    }

    private function titleFromPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $base = basename($normalized);
        if ( preg_match('/^index\.html?$/i', $base) || 0 === strcasecmp($base, 'home.html') ) {
            $parent = basename(dirname($normalized));
            if ( '' !== $parent && '.' !== $parent ) {
                return ucwords(str_replace(array( '-', '_' ), ' ', $parent));
            }
        }

        return ucwords(str_replace(array( '-', '_' ), ' ', $this->slugFromPath($path)));
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function detectBlockTypes(array $files, array &$diagnostics): array
    {
        $blockTypes = array();
        $blockRoots = array();

        foreach ( $files as $file ) {
            if ( 'block.json' !== basename((string) $file['path']) ) {
                continue;
            }
            $directory = dirname((string) $file['path']);
            $directory = '.' === $directory ? '' : $directory;
            $blockRoots[$directory] = $file;
        }

        foreach ( $blockRoots as $directory => $blockJsonFile ) {
            $blockJson = json_decode((string) $blockJsonFile['content'], true);
            if ( ! is_array($blockJson) ) {
                $blockJson = array();
                $diagnostics[] = $this->diagnostic('invalid_block_json', 'warning', 'A generated block.json file could not be decoded.', array('path' => $blockJsonFile['path']));
            }

            $name = isset($blockJson['name']) && is_string($blockJson['name']) ? trim($blockJson['name']) : '';
            if ( '' === $name ) {
                $name = 'generated/' . ('' === $directory ? 'block' : $this->sanitizeKey(basename($directory)));
                $diagnostics[] = $this->diagnostic('block_json_missing_name', 'warning', 'A generated block.json file did not declare a name; a stable generated name was assigned.', array('path' => $blockJsonFile['path'], 'name' => $name));
            }

            $blockFiles = $this->filesUnderDirectory($files, $directory);
            $blockTypes[] = array(
                'schema'          => 'chubes4/wordpress-block-type-artifact/v1',
                'name'            => $name,
                'slug'            => $this->sanitizeKey(basename($name)),
                'directory'       => $directory,
                'block_json_path' => $blockJsonFile['path'],
                'block_json'      => $blockJson,
                'metadata'        => $this->blockMetadataContract($blockJson),
                'assets'          => $this->blockAssetContract($blockJson, $blockFiles),
                'dependencies'    => $this->blockDependencyContract($blockJson, $blockFiles),
                'provenance'      => array(
                    'source'      => $blockJsonFile['source'] ?? 'artifact',
                    'source_hash' => hash('sha256', $this->fileHashPayload($blockFiles)),
                    'files'       => array_values(array_map(static fn (array $file): string => (string) $file['path'], $blockFiles)),
                ),
                'files'           => array_values(
                    array_map(
                        static fn (array $file): array => array(
                            'path'  => $file['path'],
                            'kind'  => $file['kind'],
                            'bytes' => $file['bytes'],
                        ),
                        $blockFiles
                    )
                ),
            );
        }

        usort(
            $blockTypes,
            static fn (array $left, array $right): int => strcmp((string) $left['name'], (string) $right['name'])
        );

        return $blockTypes;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function filesUnderDirectory(array $files, string $directory): array
    {
        $matched = array();
        $prefix = '' === $directory ? '' : $directory . '/';
        foreach ( $files as $file ) {
            if ( '' === $prefix || str_starts_with((string) $file['path'], $prefix) ) {
                $matched[] = $file;
            }
        }

        return $matched;
    }

    /**
     * @param array<string, mixed> $blockJson
     * @return array<string, mixed>
     */
    private function blockMetadataContract(array $blockJson): array
    {
        $metadata = array();
        foreach ( array('apiVersion', 'title', 'category', 'description', 'keywords', 'attributes', 'supports', 'usesContext', 'providesContext', 'textdomain', 'example', 'variations', 'parent', 'ancestor', 'allowedBlocks') as $key ) {
            if ( array_key_exists($key, $blockJson) ) {
                $metadata[$key] = $blockJson[$key];
            }
        }

        return $metadata;
    }

    /**
     * @param array<string, mixed> $blockJson
     * @param array<int, array<string, mixed>> $files
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function blockAssetContract(array $blockJson, array $files): array
    {
        $assets = array(
            'render'        => array(),
            'editor_script' => array(),
            'script'        => array(),
            'view_script'   => array(),
            'editor_style'  => array(),
            'style'         => array(),
            'view_style'    => array(),
        );

        foreach ( array(
            'render'       => 'render',
            'editorScript' => 'editor_script',
            'script'       => 'script',
            'viewScript'   => 'view_script',
            'editorStyle'  => 'editor_style',
            'style'        => 'style',
            'viewStyle'    => 'view_style',
        ) as $sourceField => $targetField ) {
            foreach ( $this->normalizeAssetReferences($blockJson[$sourceField] ?? null, $files, $sourceField) as $reference ) {
                $assets[$targetField][] = $reference;
            }
        }

        return $assets;
    }

    /**
     * @param mixed $value
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function normalizeAssetReferences(mixed $value, array $files, string $sourceField): array
    {
        $references = array();
        $values = is_array($value) ? array_values($value) : array($value);
        foreach ( $values as $item ) {
            if ( ! is_string($item) || '' === trim($item) ) {
                continue;
            }

            $item = trim($item);
            $isFileRef = str_starts_with($item, 'file:');
            $file = $isFileRef ? $this->findBlockFileByRelativePath($files, substr($item, 5)) : null;

            $reference = array(
                'reference'    => $item,
                'source_field' => $sourceField,
                'type'         => $isFileRef ? 'file' : 'handle',
            );
            if ( is_array($file) ) {
                $reference['path'] = $file['path'];
                $reference['kind'] = $file['kind'];
                $reference['bytes'] = $file['bytes'];
            }

            $references[] = $reference;
        }

        return $references;
    }

    /**
     * @param array<string, mixed> $blockJson
     * @param array<int, array<string, mixed>> $files
     * @return array<string, mixed>
     */
    private function blockDependencyContract(array $blockJson, array $files): array
    {
        $declared = array();
        foreach ( array('editorScript', 'script', 'viewScript', 'editorStyle', 'style', 'viewStyle') as $field ) {
            if ( array_key_exists($field, $blockJson) ) {
                $declared[$field] = $blockJson[$field];
            }
        }

        $assetFiles = array();
        foreach ( $files as $file ) {
            if ( ! str_ends_with((string) $file['path'], '.asset.php') ) {
                continue;
            }

            $assetFile = array(
                'path'  => $file['path'],
                'kind'  => $file['kind'],
                'bytes' => $file['bytes'],
            );
            $parsed = $this->parseAssetPhpManifest((string) ($file['content'] ?? ''));
            if ( array() !== $parsed ) {
                $assetFile['manifest'] = $parsed;
            }
            $assetFiles[] = $assetFile;
        }

        return array(
            'declared'    => $declared,
            'asset_files' => $assetFiles,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function parseAssetPhpManifest(string $content): array
    {
        $manifest = array();
        if ( preg_match('/["\']version["\']\s*=>\s*["\']([^"\']+)["\']/', $content, $version) ) {
            $manifest['version'] = $version[1];
        }
        if ( preg_match('/["\']dependencies["\']\s*=>\s*array\s*\((.*?)\)/s', $content, $dependencies) && preg_match_all('/["\']([^"\']+)["\']/', $dependencies[1], $matches) ) {
            $manifest['dependencies'] = array_values($matches[1]);
        }

        return $manifest;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<string, mixed>|null
     */
    private function findBlockFileByRelativePath(array $files, string $relativePath): ?array
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), './');
        foreach ( $files as $file ) {
            if ( basename((string) $file['path']) === $relativePath || str_ends_with((string) $file['path'], '/' . $relativePath) ) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<string, int>
     */
    private function countBy(array $files, string $field): array
    {
        $counts = array();
        foreach ( $files as $file ) {
            $value = (string) ($file[$field] ?? '');
            if ( '' === $value ) {
                continue;
            }
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, array<string, mixed>>
     */
    private function dedupeDiagnostics(array $diagnostics): array
    {
        $seen = array();
        $deduped = array();
        foreach ( $diagnostics as $diagnostic ) {
            $key = json_encode($diagnostic, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: serialize($diagnostic);
            if ( isset($seen[$key]) ) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $diagnostic;
        }

        return $deduped;
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     */
    private function statusFromDiagnostics(array $diagnostics): string
    {
        $warningDiagnostics = array();
        foreach ( $diagnostics as $diagnostic ) {
            if ( 'error' === ($diagnostic['severity'] ?? '') ) {
                return 'failed';
            }

            if ( in_array(($diagnostic['code'] ?? ''), array('preserved_runtime_island', 'runtime_dom_contract_preserved', 'runtime_dom_contract_fallback'), true) ) {
                continue;
            }

            $warningDiagnostics[] = $diagnostic;
        }
        return array() === $warningDiagnostics ? 'success' : 'success_with_warnings';
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function diagnostic(string $code, string $severity, string $message, array $context = array()): array
    {
        return array_filter(
            array(
                'code'     => $code,
                'severity' => $severity,
                'message'  => $message,
                'source'   => self::class,
                'context'  => $context,
            ),
            static fn (mixed $value): bool => array() !== $value
        );
    }
}

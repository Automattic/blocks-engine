<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use Automattic\BlocksEngine\PhpTransformer\Css\UnreachableSelectorPruner;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\CaptureReplayScripts;
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
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\MotionSequenceBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\LiveClockBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\Support\ShellLandmarkPolicy;
use Automattic\BlocksEngine\PhpTransformer\Css\AdminBarAccommodation;
use Automattic\BlocksEngine\PhpTransformer\Css\TemplatePartStickyContainment;
use Automattic\BlocksEngine\PhpTransformer\Css\AuthorCascadeLayerOrder;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorListCompactor;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\Css\MediaCondition;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssStylesheetChunker;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\CapturedGlyphColor;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\MonochromeGlyphColor;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;
use Automattic\BlocksEngine\PhpTransformer\Support\DeterministicRowDeduplicator;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlTagScanner;
use Automattic\BlocksEngine\PhpTransformer\Support\StyleTagScanner;
use Automattic\BlocksEngine\PhpTransformer\Support\StylesheetActivation;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentIdentityException;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ValidationException;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentIdentity;
use DOMDocument;
use DOMElement;
use DOMXPath;

final class ArtifactCompiler
{
    use \Automattic\BlocksEngine\PhpTransformer\Support\CountsBlocks;

    use StagedTransport;

    public const MAX_PLAN_DIGEST_DEPTH = 512;

    /**
     * The shared sheet is downloaded once and cached across routes; page
     * copies cost a separate download per page. Allow the greater of this
     * factor times the largest copy, one tenth of total copy bytes, and the
     * floor below. Measure scoped variants and split-rule overhead before
     * compaction; exceeding the bound falls back to page copies.
     */
    public const ROUTE_SCOPE_BYTES_FACTOR = 3;

    /** Cached shared overhead may replace up to 10% of total per-page copy download bytes. */
    public const ROUTE_SCOPE_COPY_TOTAL_DIVISOR = 10;

    /** Route-scoping overhead always allowed, e.g. for routes that only drop rules (their copy is empty). */
    public const ROUTE_SCOPE_BYTES_FLOOR = 8192;

    public const INPUT_SCHEMA = 'blocks-engine/php-transformer/site-artifact/v1';
    // Plans are a published v1 transport contract. Receipts are additive: v2
    // carries terminal reductions while v1 receipts remain composable.
    public const SHARED_PLAN_SCHEMA = 'blocks-engine/php-transformer/staged-shared-plan/v1';
    public const PAGE_PLAN_SCHEMA = 'blocks-engine/php-transformer/staged-page-plan/v1';
    public const COMPACT_RECEIPT_SCHEMA = 'blocks-engine/php-transformer/compiled-page-receipt/v3';
    /** Diagnostic recorded for each non-entrypoint page dropped by a page-scoped compile limit. */
    public const PAGE_OMITTED_DIAGNOSTIC_CODE = 'page_compile_omitted';
    public const PAGE_OMITTED_REASON_CODE = 'page_compile_representability_limit';

	/** @var array<string, string> */
	private array $themeStaticCssCache = array();

    /** @var array<string, list<array<string, mixed>>> */
    private array $stylesheetOccurrenceCache = array();

    /** @var array<string,string> Collision-free root classes keyed by site route identity. */
    private array $routeClasses = array();
    private array $routeSets = array();

    /** @var array<string, true> Source resources imported into an authored layer. */
    private array $layeredNavigationImports = array();

	private WordPressCompatCss $wordpressCompat;

    private readonly RuntimeScriptEvidenceAnalyzer $runtimeScriptEvidenceAnalyzer;

    /** The artifact's files indexed for the current compile. */
    private readonly ArtifactFileIndex $fileIndex;

    /** Collects the selectors runtime scripts depend on. */
    private readonly RuntimeScriptSelectors $runtimeSelectors;

    /** Promotes conversion findings into runtime declarations. */
    private readonly RuntimeDeclarationPromotion $runtimeDeclarationPromotion;

    /** Reads page titles and navigation labels from source documents. */
    private readonly DocumentTitles $titles;

    private \Automattic\BlocksEngine\PhpTransformer\Css\CssRuleTreeCache $cssRuleTrees;

    public function cssTokenizationCounts(): array
    {
        return $this->cssRuleTrees->tokenizations();
    }

    private ?HtmlTransformerAnalysisCache $htmlTransformerAnalysisCache = null;

    /** Observational only: excludes receipt cache hits. */
    private int $htmlDocumentTransformCount = 0;

    /** Observational only: counts source stylesheet discovery passes. */
    private int $stylesheetAssetDiscoveryCount = 0;

    /**
     * @param null|\Closure(string,string):void $beforeDocumentTransform Called with the source path and source scope
     *        immediately before a page document is converted. A throw is treated exactly like a throw from the
     *        converter itself, so a consumer can prove its handling of per-page compile failures.
     */
    public function __construct(
        private readonly bool $cacheHtmlAnalysis = true,
        private readonly int $stylesheetSelectorBudget = CssStylesheetChunker::MAX_SELECTOR_RECORDS,
        private readonly ?\Closure $beforeDocumentTransform = null
    )
    {
        $this->cssRuleTrees = new \Automattic\BlocksEngine\PhpTransformer\Css\CssRuleTreeCache();
        $this->wordpressCompat = new WordPressCompatCss();
        $this->runtimeScriptEvidenceAnalyzer = new RuntimeScriptEvidenceAnalyzer();
        $this->fileIndex = new ArtifactFileIndex();
        $this->runtimeSelectors = new RuntimeScriptSelectors($this->runtimeScriptEvidenceAnalyzer, $this->fileIndex);
        $this->runtimeDeclarationPromotion = new RuntimeDeclarationPromotion();
        $this->titles = new DocumentTitles();
    }

    /** @param array<int, array<string, mixed>> $files */
    private function hasUnreproducedSourceMotion(array $files): bool
    {
        $receipt = CaptureReceiptReport::read($files);
        return null !== $receipt
            && CaptureEvidence::SOURCE_INTERACTIVITY_SCHEMA === ($receipt['sourceInteractivity']['schema'] ?? null)
            && is_int($receipt['sourceInteractivity']['unreproduced_route_count'] ?? null)
            && $receipt['sourceInteractivity']['unreproduced_route_count'] > 0;
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
            'style_evicted_bytes' => $cache->styleEvictedBytes,
            'author_builds' => $cache->authorSelectorBuilds,
            'author_hits' => $cache->authorSelectorHits,
            'author_evictions' => $cache->authorSelectorEvictions,
            'author_bytes' => $cache->authorSelectorBytes,
            'author_evicted_bytes' => $cache->authorSelectorEvictedBytes,
            'author_class_token_builds' => $cache->authorSelectorClassTokenBuilds,
            'author_class_token_hits' => $cache->authorSelectorClassTokenHits,
            'author_attribute_reads' => $cache->authorSelectorAttributeReads,
            'author_match_result_builds' => $cache->authorSelectorMatchResultBuilds,
            'author_match_result_hits' => $cache->authorSelectorMatchResultHits,
            'author_style_rule_builds' => $cache->authorStyleRuleBuilds,
            // Selector matching dominates per-page work, so its executions,
            // evictions and peak occupancy are reported rather than left to a
            // reader who would otherwise infer cost from the build counts above.
            'source_selector_match_executions' => $cache->sourceSelectorMatchExecutions,
            'source_selector_match_hits' => $cache->sourceSelectorMatchHits,
            'source_selector_match_misses' => $cache->sourceSelectorMatchMisses,
            'source_selector_match_evictions' => $cache->sourceSelectorMatchEvictions,
            'source_selector_match_peak_entries' => $cache->sourceSelectorMatchPeakEntries,
            'source_selector_class_token_builds' => $cache->sourceSelectorClassTokenBuilds,
            'source_selector_class_token_hits' => $cache->sourceSelectorClassTokenHits,
            'source_selector_attribute_reads' => $cache->sourceSelectorAttributeReads,
            // `rules_skipped` counts rules the candidate index excluded, so a
            // large value is the index working, not work being wasted.
            'source_style_candidate_rule_checks' => $cache->sourceStyleCandidateRuleChecks,
            'source_style_candidate_rules_skipped' => $cache->sourceStyleCandidateRulesSkipped,
            'source_style_candidate_rule_hits' => $cache->sourceStyleCandidateRuleHits,
            'source_style_candidate_rule_misses' => $cache->sourceStyleCandidateRuleMisses,
            'source_style_candidate_rule_evictions' => $cache->sourceStyleCandidateRuleEvictions,
            'source_style_candidate_rule_peak_entries' => $cache->sourceStyleCandidateRulePeakEntries,
            'source_style_candidate_rule_peak_retained' => $cache->sourceStyleCandidateRulePeakRetained,
            'source_structural_declaration_builds' => $cache->sourceStructuralDeclarationBuilds,
            'source_structural_declaration_hits' => $cache->sourceStructuralDeclarationHits,
            'stylesheet_asset_discoveries' => $this->stylesheetAssetDiscoveryCount,
        );
    }

    private string $generatedAssetRoot = '';

    /** Whether the artifact offers a purchase affordance; decided once per compile. */
    private ?bool $sourceHasPurchaseAffordance = null;

    /**
     * Raw-byte SHA-256 results retained across page indexing for this instance.
     *
     * @var array<string, array{color:string, undecodable:bool}>
     */
    private array $glyphColorByHash = array();

    /** Counts actual image inspections, not memo lookups. */
    private int $glyphColorInspectionCount = 0;

    private ?PayloadReader $glyphPayloadReader = null;

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
        return $this->cssRuleTrees->run(fn () => $this->runtimeContextForSourceWithCssRuleTrees($html, $sourcePath, $files));
    }

    private function runtimeContextForSourceWithCssRuleTrees(string $html, string $sourcePath, array $files): array
    {
        $normalized = ( new ArtifactNormalizer() )->normalize(array(
            'entrypoint' => $sourcePath,
            'files'      => $files,
        ));
        $this->indexFiles($normalized['files']);
        $runtimeDomSelectors = $this->runtimeSelectors->runtimeDomSelectors($html, $sourcePath, $normalized['files']);

        return array(
            'runtime_script_metadata'  => $this->runtimeScriptMetadataForSource($html, $sourcePath, $normalized['files']),
            'runtime_dom_selectors'    => $runtimeDomSelectors,
            'runtime_behavioral_selectors' => $runtimeDomSelectors,
            'runtime_canvas_selectors' => $this->runtimeSelectors->runtimeCanvasSelectors($html, $sourcePath, $normalized['files']),
        );
    }

    /**
     * @param array<string, mixed> $artifact
     */
    public function compile(array $artifact): TransformerResult
    {
        return $this->cssRuleTrees->run(fn () => $this->compileWithCssRuleTrees($artifact));
    }

    private function compileWithCssRuleTrees(array $artifact): TransformerResult
    {
        $this->htmlDocumentTransformCount = 0;
        return $this->compileArtifact($this->composeInputArtifact($artifact));
    }

    /**
     * Assemble a normalized artifact into its terminal result. Kept separate
     * from the public inline entry point so staged composition cannot recurse
     * through whole-artifact compilation.
     *
     * @param array<string, mixed> $artifact
     */
    private function sourceHasPurchaseAffordance(array $files): bool
    {
        return $this->sourceHasPurchaseAffordance ??= \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CommerceStructureRecognizer::sourceHasPurchaseAffordance($files);
    }

    private function compileArtifact(array $artifact): TransformerResult
    {
		$this->themeStaticCssCache = array();
        $this->sourceHasPurchaseAffordance = null;
        $this->stylesheetOccurrenceCache = array();
        $this->layeredNavigationImports = array();
		$this->wordpressCompat = new WordPressCompatCss();
        $this->htmlTransformerAnalysisCache = $this->cacheHtmlAnalysis ? new HtmlTransformerAnalysisCache() : null;
        $normalized = (new ArtifactNormalizer())->normalize($artifact);
        $normalized['source_css_bytes'] = $artifact['_captured_source_css_bytes'] ?? self::sourceCssBytes($normalized['files']);
        $this->layoutGeometryProof = ArrayField::array($normalized, 'layout_geometry_proof');
        $capturedDialogs = (new CapturedDialogProjector())->project($normalized['files']);
        $collections = (new CapturedCollectionProjector())->project($capturedDialogs['files']);
        $selectableSets = (new CapturedSelectableSetProjector())->project($collections['files'], $collections['consumed_selectable_bindings'] ?? array());
        $choiceGroups = (new CapturedChoiceGroupProjector())->project($selectableSets['files']);
        $scrollStates = (new ScrollStateProjector())->project($choiceGroups['files']);
        $normalized['files'] = (new RouteStateProjector())->project($scrollStates['files'])['files'];
        return $this->finalizeArtifact($artifact, array(
            'normalized' => $normalized,
            'inline_compilation' => true,
            // Capture-time projectors run against the same source documents
            // before block conversion, so their diagnostics share one
            // reporting bucket. Selectable-set counts stay separate so
            // projected_dialog_count is not inflated.
            'captured_dialogs' => array(
                'diagnostics' => CapturedInteractionReport::mergeDiagnostics($capturedDialogs['diagnostics'], $collections['diagnostics'], $selectableSets['diagnostics'], $choiceGroups['diagnostics'], $scrollStates['diagnostics']),
                'projected_count' => $capturedDialogs['projected_count'] + $scrollStates['projected_count'],
                'projected_selectable_set_count' => $selectableSets['projected_count'],
                'projected_choice_group_count' => $choiceGroups['projected_count'],
                'native_runtime_replacements' => array_merge(
                    $capturedDialogs['native_runtime_replacements'] ?? array(),
                    $selectableSets['native_runtime_replacements'] ?? array(),
                    $collections['superseded_runtime_scripts'] ?? array()
                ),
            ),
        ));
    }

    /** @param array<string,mixed> $artifact @return array<string,mixed> */
    private function composeInputArtifact(array $artifact): array
    {
        unset($artifact['_captured_source_css_bytes']);
        if (!isset($artifact['document_variants'])) return $artifact;
        // Responsive composition adds scoped and control CSS. Capture the
        // original documents before that first compilation step, too.
        $normalized = (new ArtifactNormalizer())->normalize($artifact);
        $bytes = self::sourceCssBytes($normalized['files']);
        $artifact = (new ResponsiveDocumentVariants())->compose($artifact);
        $artifact['_captured_source_css_bytes'] = $bytes;
        return $artifact;
    }

    /**
     * Count captured author CSS before any compiler projections. Normalization
     * also extracts inline styles into synthetic CSS files; read their original
     * HTML instead so whitespace is preserved and body-style projections do not
     * enter the source total. One hash set deduplicates all captured CSS content.
     *
     * @param array<int,array<string,mixed>> $files
     */
    private static function sourceCssBytes(array $files): int
    {
        $bytes = array();
        foreach ($files as $file) {
            $content = (string) ($file['content'] ?? '');
            if ('css' === ($file['kind'] ?? null) && 'inline-style' !== ($file['source'] ?? null)) {
                $bytes[hash('sha256', $content)] = strlen($content);
            } elseif ('html' === ($file['kind'] ?? null)) {
                foreach (StyleTagScanner::scan($content) as $style) {
                    $css = $style['content'];
                    $bytes[hash('sha256', $css)] = strlen($css);
                }
            }
        }
        return array_sum($bytes);
    }

    /**
     * Finalize collected facts into the canonical transformer envelope. Receipt
     * composition enters here only after all page payload access and content
     * work is done. WordPress site-plan production is derived afterward.
     *
     * @param array<string,mixed> $artifact
     * @param array<string,mixed> $reduction
     */
    private function finalizeArtifact(array $artifact, array $reduction, ?\Automattic\BlocksEngine\PhpTransformer\Support\StepTimer $timer = null): TransformerResult
    {
        $timer ??= new \Automattic\BlocksEngine\PhpTransformer\Support\StepTimer('compose.artifact_assembly');
        $timer->start('compose.artifact_assembly');
        $startedAt = hrtime(true);
        $normalized = $reduction['normalized'];
        $routeIdentities = array();
        foreach ($normalized['files'] as $file) {
            if ('html' !== ($file['kind'] ?? '')) continue;
            $source = (string) $file['path'];
            $routeIdentities[] = \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan::identity('page', $source, $source);
        }
        $this->routeClasses = \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentRootContext::routeClasses($routeIdentities);
        $this->routeSets = array();
        $capturedDialogs = is_array($reduction['captured_dialogs'] ?? null) ? $reduction['captured_dialogs'] : array('diagnostics' => array(), 'projected_count' => 0);
        $entry = $this->entryFile($normalized['files'], $normalized['entrypoints']);
        $documents = is_array($reduction['source_documents'] ?? null) ? $reduction['source_documents'] : SourceDocuments::compile($normalized);
        $diagnostics = array_merge($this->operatorFacingNormalizationDiagnostics($normalized['diagnostics']), $capturedDialogs['diagnostics'], $documents['diagnostics'], $this->svgAssetDiagnostics($normalized['files']));

        if ( null === $entry && array() === $documents['documents'] ) {
            $diagnostics[] = ArtifactDiagnostics::make('missing_entry_html', 'error', 'No HTML entry file was available to compile.');
        }

        $entryPath = is_array($entry) ? (string) $entry['path'] : '';
        $this->generatedAssetRoot = '.' === dirname($entryPath) ? '' : trim(dirname($entryPath), '/');
        $html = is_array($entry) ? (string) $entry['content'] : '';
        $components = is_array($reduction['components'] ?? null) ? $reduction['components'] : ComponentDetection::detect($normalized['files'], $entryPath, $documents['components']);
        $blockTypes = is_array($reduction['block_types'] ?? null) ? $reduction['block_types'] : BlockTypes::detect($normalized['files'], $diagnostics);
        $companionPluginPayloadBuilder = new CompanionPluginPayload();
        $normalized['files'] = LinkedStylesheets::withDocumentMedia($normalized['files']);
        $normalized['files'] = LinkedStylesheets::separateUnlinked($normalized['files'], $normalized['runtime_declarations']);
        $this->indexFiles($normalized['files']);
        $entryBlocks = is_array($reduction['entry_blocks'] ?? null) ? $reduction['entry_blocks'] : $this->compileEntryBlocks($html, $entryPath, $normalized['files'], $companionPluginPayloadBuilder->blockNamespace($artifact), $normalized['runtime_declarations']);
        // Staged receipts carry the pages their workers omitted; whole compilation
        // discovers them here. Both reach the same page-omitted accounting below.
        $omittedPages = ArrayField::array($reduction, 'omitted_pages');
        $compiledHtmlDocuments = is_array($reduction['compiled_documents'] ?? null) ? $reduction['compiled_documents'] : $this->compileHtmlSourceDocuments($normalized['files'], $entryPath, $companionPluginPayloadBuilder->blockNamespace($artifact), $normalized['runtime_declarations'], $omittedPages);
        $inlineShellCompilation = is_array($reduction['inline_shell_compilation'] ?? null)
            ? $reduction['inline_shell_compilation']
            : $this->compileSharedInlineShells($normalized['files'], $entryPath, $companionPluginPayloadBuilder->blockNamespace($artifact));
        if (array() !== $omittedPages) {
            $normalized['files'] = FileOwnership::withoutOmittedPages($normalized['files'], array_keys($omittedPages));
            $inlineShellCompilation = $this->withoutOmittedInlineShellSources($inlineShellCompilation, array_keys($omittedPages));
            $this->indexFiles($normalized['files']);
        }
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
        foreach ($capturedDialogs['diagnostics'] as $projectionDiagnostic) {
            $code = (string) ($projectionDiagnostic['code'] ?? '');
            if (!str_contains($code, 'limit_exceeded') && 'captured_interaction_ref_unresolved' !== $code) continue;
            $allFallbacks[] = \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackDiagnostic::build(array(
                'diagnostic_code' => $code,
                'severity' => 'error',
                'summary' => $projectionDiagnostic['message'],
                'source' => $projectionDiagnostic['source'] ?? self::class,
                'context' => $projectionDiagnostic['context'] ?? array(),
                'conversion_classification' => 'unsupported',
                'loss_class' => 'content_loss',
                'diagnostic_class' => 'content_loss',
            ));
        }
        foreach (array_merge(CaptureReceiptReport::diagnostics($normalized['files']), CaptureReceiptReport::normalizationDiagnostics($normalized['diagnostics'])) as $receiptDiagnostic) {
            if (!in_array($receiptDiagnostic, $diagnostics, true)) $diagnostics[] = $receiptDiagnostic;
            $lostControls = array();
            foreach (CapturedInteractionReport::read($normalized['files'])['pages'] ?? array() as $page) {
                foreach ($page['components'] ?? array() as $component) {
                    if (is_array($component)) $lostControls[] = array('source_url' => $page['sourceUrl'] ?? null, 'component' => $component['id'] ?? null);
                }
            }
            // A rejected receipt is a loss even when the interaction sidecar is
            // absent or unreadable; never turn unknown control loss into success.
            if (array() === $lostControls) $lostControls[] = array();
            foreach ($lostControls as $control) $allFallbacks[] = \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackDiagnostic::build(array(
                'diagnostic_code' => $receiptDiagnostic['code'],
                'severity' => 'error',
                'summary' => $receiptDiagnostic['message'],
                'source' => $receiptDiagnostic['context']['path'],
                'context' => array_merge($receiptDiagnostic['context'], $control),
                'conversion_classification' => 'unsupported',
                'loss_class' => 'content_loss',
                'diagnostic_class' => 'content_loss',
            ));
        }
        $allGeneratedBlocks = array_merge($entryBlocks['generated_blocks'], $inlineShellCompilation['generated_blocks'] ?? array());
        $allGutenbergGaps = $entryBlocks['gutenberg_gaps'];
        $coreHtmlFallbackEvidence = array($entryBlocks['core_html_fallback_evidence']);
        // A projection belongs to the page that produced it. Template surfaces
        // render across many posts, so their projections stay site-wide.
        $filesByPath = array_column($normalized['files'], null, 'path');
        $ownedBy = function (array $projections, string $sourcePath) use ($filesByPath): array {
            $file = $filesByPath[$sourcePath] ?? array( 'path' => $sourcePath, 'kind' => 'html' );
            $ownership = FileOwnership::of($file);
            $owner = 'page' === $ownership['scope'] && ! isset($file['metadata']['template_surface']) ? $ownership['id'] : '';
            return array_map(static fn (array $projection): array => $projection + array( 'owner' => $owner ), $projections);
        };
        $authorStylesheetProjections = array_merge(
            $ownedBy($entryBlocks['author_stylesheet_projections'], $entryPath),
            $inlineShellCompilation['author_stylesheet_projections'] ?? array()
        );
        foreach ( $compiledHtmlDocuments as $sourcePath => $compiledHtmlDocument ) {
            $authorStylesheetProjections = array_merge($authorStylesheetProjections, $ownedBy($compiledHtmlDocument['author_stylesheet_projections'] ?? array(), (string) $sourcePath));
            $runtimeScriptProjections = array_merge($runtimeScriptProjections, $compiledHtmlDocument['runtime_script_projections'] ?? array());
            $allDiagnostics = array_merge($allDiagnostics, $this->entryTransformDiagnostics($compiledHtmlDocument['diagnostics'] ?? array(), (string) $sourcePath));
            $allFallbacks = array_merge($allFallbacks, $compiledHtmlDocument['fallbacks'] ?? array());
            $allGeneratedBlocks = array_merge($allGeneratedBlocks, $compiledHtmlDocument['generated_blocks'] ?? array());
            $allGutenbergGaps = array_merge($allGutenbergGaps, $compiledHtmlDocument['gutenberg_gaps'] ?? array());
            $coreHtmlFallbackEvidence[] = $compiledHtmlDocument['core_html_fallback_evidence'] ?? array();
        }
        $allGutenbergGaps = DeterministicRowDeduplicator::dedupe($allGutenbergGaps);
        $runtimeDeclarationDiagnostics = array();
        $runtimeEntityRecords = array();
        $normalized['runtime_declarations'] = $this->runtimeDeclarationPromotion->cartLinkRuntimeDeclarations($normalized['runtime_declarations'], $allDiagnostics, $entryPath);
        $normalized['runtime_declarations'] = $this->runtimeDeclarationPromotion->runtimeDeclarationsFromFallbacks($normalized['runtime_declarations'], $allFallbacks, $entryPath, $normalized['files'], fn (): bool => $this->sourceHasPurchaseAffordance($normalized['files']), $runtimeDeclarationDiagnostics, $runtimeEntityRecords);
        // Internal fallback markers are consumed above and never part of the
        // published contract. Ambiguous description candidates were used to
        // build the unrepresented-text diagnostic; remove them from findings
        // too, keeping their controls identical to the declared form entity.
        $allFallbacks = $this->withoutInternalFindingMarkers($allFallbacks);
        $allDiagnostics = $this->withoutInternalFindingMarkers($allDiagnostics);
        $pageMarkup = array();
        $pageMarkup[(string) ($ownedBy(array(array()), $entryPath)[0]['owner'] ?? '')] = $entryBlocks['serialized_blocks'];
        foreach ($compiledHtmlDocuments as $sourcePath => $document) {
            $pageMarkup[(string) ($ownedBy(array(array()), (string) $sourcePath)[0]['owner'] ?? '')] = $document['serialized_blocks'];
        }
        // These are the template-part candidates consumed by compiledSiteReport
        // and WordPressSitePlan's shell extraction. serialized_blocks alone omits
        // extracted chrome. Use a conservative union of all candidate parts.
        $sharedMarkup = '';
        $parts = array_merge($this->compiledSiteTemplateParts($normalized['files']), $entryBlocks['shell_artifacts'], $inlineShellCompilation['artifacts']);
        foreach ($compiledHtmlDocuments as $document) $parts = array_merge($parts, $document['shell_artifacts'] ?? array());
        // Pages sharing chrome each carry an identical copy of the same part;
        // one copy is the same selector evidence, and every route appends this.
        foreach (array_unique(array_map(static fn (array $part): string => (string) ($part['block_markup'] ?? ''), $parts)) as $partMarkup) $sharedMarkup .= "\n" . $partMarkup;
        foreach ($pageMarkup as &$markup) $markup .= "\n" . $sharedMarkup;
        unset($markup);
        $authoredFiles = $normalized['files'];
        $normalized['files'] = $this->applyAuthorStylesheetProjections($normalized['files'], $authorStylesheetProjections, $entryBlocks['author_stylesheet_projections'], (string) ($ownedBy(array(array()), $entryPath)[0]['owner'] ?? ''), $pageMarkup, $allDiagnostics, $sharedMarkup, $timer);
        $normalized['files'] = $this->withoutUnreachableSelectors($normalized['files']);
        foreach ($normalized['files'] as $document) {
            if ('html' !== ($document['kind'] ?? '') || !is_string($document['content'] ?? null) || !empty($document['metadata']['compilation']['included_component'])) continue;
            $documentFiles = $normalized['files'];
            foreach ($this->stylesheetAssetsForSource($document['content'], $document['path'], $documentFiles) as $stylesheet) {
                // The canonical import walk also records which resource rules
                // must share their importing layer rather than a replay layer.
                $this->stylesheetOccurrenceContent($stylesheet, $documentFiles);
            }
        }
        foreach ($normalized['files'] as &$file) {
            if ('css' !== ($file['kind'] ?? '') || !is_string($file['content'] ?? null)) continue;
            $content = $this->wordpressCompat->projectNavigationStylesheet($file['content'], $normalized['files'], isset($this->layeredNavigationImports[$file['path']]));
            if ($content !== $file['content']) $file = $this->projectedStylesheetFile($file, $file['path'], $content);
        }
        unset($file);
        $wordpressCompatAssets = $this->wordpressCompatAssetsForDocuments($normalized['files'], $authoredFiles, $diagnostics);
        $normalized['files'] = $this->chunkProjectedStylesheets($normalized['files']);
        foreach ($normalized['files'] as $file) {
            if ($entryPath === ($file['path'] ?? null) && is_string($file['content'] ?? null)) {
                $html = $file['content'];
                break;
            }
        }
        $this->indexFiles($normalized['files']);
        $normalized['files'] = RuntimeScriptRewrites::applyToFiles($normalized['files'], $runtimeScriptProjections);
        $runtimeIslandPackage = RuntimeScriptRewrites::applyToPackage(
            ( new RuntimeIslandPackageBuilder() )->fromRuntimeIslands($entryBlocks['runtime_islands'], $normalized['files'], $entryPath),
            $runtimeScriptProjections
        );
        $referenceReports = $this->referenceReports($normalized['files'], $entryPath);
        $manifestAssets = $this->assetManifest($normalized['files'], $entryPath, $referenceReports['asset_references'], $html);
        $entryOwnership = is_array($entry) ? FileOwnership::of($entry) : array('scope' => 'page', 'id' => $entryPath);
        $generatedAssets = $this->generatedAssetsForDocuments($entryBlocks['assets'], $entryOwnership, $compiledHtmlDocuments, $normalized['files']);
        $generatedAssetIdentities = array();
        foreach ($generatedAssets as $asset) $generatedAssetIdentities[hash('sha256', (string) ($asset['path'] ?? '') . "\0" . (string) ($asset['content'] ?? ''))] = true;
        foreach ($inlineShellCompilation['assets'] as $asset) {
            $identity = hash('sha256', (string) ($asset['path'] ?? '') . "\0" . (string) ($asset['content'] ?? ''));
            if (isset($generatedAssetIdentities[$identity])) continue;
            $generatedAssetIdentities[$identity] = true; $generatedAssets[] = $asset;
        }
        $rootInlineAsset = $this->documentInlineStyleAsset($normalized['files']);
        if (null !== $rootInlineAsset) $generatedAssets[] = $rootInlineAsset;
        $presentationalHintAsset = $this->documentPresentationalHintAsset($normalized['files']);
        if (null !== $presentationalHintAsset) $generatedAssets[] = $presentationalHintAsset;
        $projectedAdminBarAsset = $this->projectedViewportChromeAsset($normalized['files']);
        if (null !== $projectedAdminBarAsset) {
            $generatedAssets[] = $projectedAdminBarAsset;
        }
        $beforeAuthorAssets = array_values(array_filter($generatedAssets, static fn (array $asset): bool => 'before-author' === ($asset['stylesheet_placement'] ?? '')));
        $afterAuthorAssets = array_values(array_filter($generatedAssets, static fn (array $asset): bool => 'after-author' === ($asset['stylesheet_placement'] ?? '')));
        $otherGeneratedAssets = array_values(array_filter($generatedAssets, static fn (array $asset): bool => ! in_array($asset, $beforeAuthorAssets, true) && ! in_array($asset, $afterAuthorAssets, true)));
        // Runtime loads the manifest in array order. Placement metadata keeps
        // engine support on its intended side of the authored stylesheets.
        $assets = array_merge($beforeAuthorAssets, $manifestAssets, $otherGeneratedAssets, $afterAuthorAssets);
        $assets = array_merge($assets, $wordpressCompatAssets);
        $assets = $this->pruneAbsentSupportSubjects($assets, $authoredFiles, implode("\n", $pageMarkup) . "\n" . $sharedMarkup);
        $assets = $this->deduplicateVisualAssets($assets);
        $assets = $this->coalesceStylesheetAssets($assets);
        $diagnostics = ArtifactDiagnostics::dedupe(array_merge($diagnostics, $allDiagnostics, $runtimeDeclarationDiagnostics, $this->omittedPageDiagnostics($omittedPages)));
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
                'original_schema' => ArrayField::string($artifact, 'schema'),
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
                    'max_media_files' => $normalized['limits']['max_media_files'],
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
        $interactionReport = array('schema' => 'blocks-engine/captured-interactions/v1');
        if (0 < $capturedDialogs['projected_count']) {
            $interactionReport['projected_dialog_count'] = $capturedDialogs['projected_count'];
        }
        if (0 < ($capturedDialogs['projected_selectable_set_count'] ?? 0)) {
            $interactionReport['projected_selectable_set_count'] = $capturedDialogs['projected_selectable_set_count'];
        }
        if (0 < ($capturedDialogs['projected_choice_group_count'] ?? 0)) {
            $interactionReport['projected_choice_group_count'] = $capturedDialogs['projected_choice_group_count'];
        }
        if (isset($interactionReport['projected_dialog_count']) || isset($interactionReport['projected_selectable_set_count']) || isset($interactionReport['projected_choice_group_count'])) {
            $sourceReports['captured_interactions'] = $interactionReport;
        }
        if (array() !== ($capturedDialogs['native_runtime_replacements'] ?? array())) {
            $sourceReports['native_runtime_replacements'] = $capturedDialogs['native_runtime_replacements'];
        }
        $compiledSite = $this->compiledSiteReport($normalized, $entryPath, $documents['documents'], $assets, $blockTypes, $serializedBlocks, $entryBlocks['shell_artifacts'], $compiledHtmlDocuments, $inlineShellCompilation['artifacts'], ArrayField::array($entryBlocks, 'document_root_markers'), ArrayField::array($entryBlocks, 'design_usage'));
        $compiledSite['source_css_bytes'] = $normalized['source_css_bytes'];
        $compiledSite['runtime_entity_records'] = $runtimeEntityRecords;
        $sourceReports['compiled_site'] = $compiledSite;
        $identityFailures = DocumentIdentity::compiledSiteFailures($compiledSite);
        foreach ( DocumentIdentity::diagnostics($identityFailures) as $identityDiagnostic ) {
            $diagnostics[] = array_merge($identityDiagnostic, array('source' => self::class));
        }
        $fileMetadata = array_column($normalized['files'], null, 'path');
        $entryFile = $fileMetadata[$entryPath] ?? array();
        $editabilityDocuments = array($entryPath => array('blocks' => $entryBlocks['blocks'], 'serialized_blocks' => $entryBlocks['serialized_blocks'], 'generated_carrier_css' => $this->cssAssetContent($entryBlocks['assets']), 'runtime_block_paths' => $entryBlocks['runtime_block_paths'], 'visual_block_paths' => $entryBlocks['visual_block_paths'], 'editability_report' => $entryBlocks['editability_report'], 'template_surface' => $entryFile['metadata']['template_surface'] ?? null, 'provenance' => $entryFile['provenance'] ?? null));
        foreach ($compiledHtmlDocuments as $sourcePath => $compiledHtmlDocument) {
            $sourceFile = $fileMetadata[$sourcePath] ?? array();
            $editabilityDocuments[(string) $sourcePath] = array(
                'blocks' => ArrayField::array($compiledHtmlDocument, 'blocks'),
                'serialized_blocks' => ArrayField::string($compiledHtmlDocument, 'serialized_blocks'),
                'generated_carrier_css' => $this->cssAssetContent(ArrayField::array($compiledHtmlDocument, 'assets')),
                'runtime_block_paths' => $compiledHtmlDocument['runtime_block_paths'],
                'visual_block_paths' => $compiledHtmlDocument['visual_block_paths'],
                'editability_report' => $compiledHtmlDocument['editability_report'],
                'template_surface' => $sourceFile['metadata']['template_surface'] ?? null,
                'provenance' => $sourceFile['provenance'] ?? null,
            );
        }
        $editabilityReport = (new EditabilityReport())->fromDocuments($editabilityDocuments);
        $editabilityPolicy = (new EditabilityPolicy())->evaluate($editabilityReport, $entryPath);
        $sourceReports['editability_report'] = $editabilityReport;
        $sourceReports['editability_policy'] = $editabilityPolicy;
        foreach (array('failures' => array('editability_policy_failed', 'error'), 'findings' => array('editability_policy_degraded', 'warning')) as $key => list($code, $severity)) {
            foreach ($editabilityPolicy[$key] as $failure) {
                $diagnostics[] = ArtifactDiagnostics::make($code, $severity, (string) $failure['message'], array(
                    'policy_schema' => EditabilityPolicy::SCHEMA,
                    'metric' => $failure['metric'],
                    'actual' => $failure['actual'],
                    'maximum' => $failure['maximum'],
                    'source_path' => $failure['source_path'] ?? '',
                ));
            }
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
                $diagnostics[] = ArtifactDiagnostics::make('responsive_counterparts_declared', 'info', sprintf('Declared %d responsive counterpart pair(s) from stable source provenance.', $declaredCount), array(
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
        if ($this->hasUnreproducedSourceMotion($normalized['files'])) {
            $namespace = $companionPluginPayloadBuilder->blockNamespace($artifact);
            if ('' !== $namespace) {
                $allGeneratedBlocks[] = (new MotionSequenceBlockGenerator())->definition($namespace . '/' . MotionSequenceBlockGenerator::LOCAL_NAME);
                $allGeneratedBlocks[] = (new LiveClockBlockGenerator())->definition($namespace . '/' . LiveClockBlockGenerator::LOCAL_NAME);
            }
        }
        $themeOwnedRequiredScripts = RuntimeIslandPackageBuilder::themeOwnedRequiredScriptOccurrences($runtimeIslandPackage, $compiledSite['pages'] ?? array());
        $companionPluginPayload = $companionPluginPayloadBuilder->fromBlockTypes($blockTypes, $normalized['files'], $artifact, $allGeneratedBlocks, $runtimeIslandPackage, $editorScripts, $themeOwnedRequiredScripts);
        if ( array() !== $companionPluginPayload ) {
            $sourceReports['companion_plugin_payload'] = $companionPluginPayload;
        }
        if ( array() !== $entryBlocks['superseded_selectors'] ) {
            $sourceReports['superseded_selectors'] = $entryBlocks['superseded_selectors'];
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

        return $timer->result(( new WordPressSitePlanComposer() )->compose(
            new TransformerResult(
                status: ArtifactDiagnostics::status($diagnostics),
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
            $startedAt,
            fn(): array => ( new RuntimeDependencyParityReport($this->runtimeScriptEvidenceAnalyzer) )->fromArtifact($normalized['files'], $html, $serializedBlocks, $entryPath, $entryBlocks['runtime_islands'], $referenceReports['asset_references'], $entryBlocks['interaction_candidates'], $entryBlocks['superseded_selectors'], $allGeneratedBlocks),
            $timer
        ));
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
            foreach (ArrayField::array($report, 'counterparts') as $counterpart) {
                if (!is_array($counterpart)) {
                    continue;
                }
                $counterpart['source_path'] = (string) $sourcePath;
                $merged['counterparts'][] = $counterpart;
            }
            foreach (ArrayField::array($report, 'declined') as $declinedEntry) {
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
                foreach (ArrayField::array($asset, 'component_occurrences') as $occurrence) {
                    if (count($occurrences) < 8 && !in_array($occurrence, $occurrences, true)) $occurrences[] = $occurrence;
                }
                if (array() !== $occurrences) $assets[$index]['component_occurrences'] = $occurrences;
                $counts = is_array($assets[$index]['component_occurrence_counts'] ?? null) ? $assets[$index]['component_occurrence_counts'] : array();
                foreach (ArrayField::array($asset, 'component_occurrence_counts') as $fingerprint => $count) if (is_string($fingerprint) && is_int($count)) $counts[$fingerprint] = (int) ($counts[$fingerprint] ?? 0) + $count;
                if (array() !== $counts) $assets[$index]['component_occurrence_counts'] = $counts;
                $assets[$index]['component_occurrences_omitted'] = max(0, array_sum($counts) - count($occurrences));
                if ( 'css' !== ($asset['kind'] ?? null) ) {
                    continue;
                }
                $existingOwnership = $assets[$index]['compilation'] ?? null;
                $assetOwnership = $asset['compilation'] ?? null;
                if ( $existingOwnership !== $assetOwnership ) {
                    // Sharing bytes does not make document-owned rules site-wide.
                    // Retain every consumer; only an actually shared compilation
                    // may erase that bounded set of document owners.
                    $sources = static fn(array $owner): array => 'page' === ($owner['scope'] ?? null) ? array($owner['id']) : ($owner['source_paths'] ?? array());
                    $existingSources = $sources($existingOwnership ?? array());
                    $assetSources = $sources($assetOwnership ?? array());
                    $assets[$index]['compilation'] = array('scope' => 'shared');
                    if (array() !== $existingSources && array() !== $assetSources) {
                        $assets[$index]['compilation']['source_paths'] = array_values(array_unique(array_merge($existingSources, $assetSources)));
                    }
                }
            }
        };

        $append($entryAssets, $entryOwnership);
        $filesByPath = array_column($files, null, 'path');
        foreach ( $compiledHtmlDocuments as $sourcePath => $compiledHtmlDocument ) {
            $file = $filesByPath[$sourcePath] ?? array('path' => $sourcePath, 'kind' => 'html');
            $append(
                ArrayField::array($compiledHtmlDocument, 'assets'),
                FileOwnership::of($file)
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
            $incomingRows = ArrayField::array($asset, 'component_occurrences');
            $alreadyIncluded = array() !== $incomingRows;
            foreach ($incomingRows as $row) {
                if (!in_array($row, $rows, true)) $alreadyIncluded = false;
                if (count($rows) < 8 && !in_array($row, $rows, true)) $rows[] = $row;
            }
            $deduplicated[$index]['component_occurrences'] = $rows;
            if (!$alreadyIncluded) foreach (ArrayField::array($asset, 'component_occurrence_counts') as $fingerprint => $count) if (is_string($fingerprint) && is_int($count)) $deduplicated[$index]['component_occurrence_counts'][$fingerprint] = (int) ($deduplicated[$index]['component_occurrence_counts'][$fingerprint] ?? 0) + $count;
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
            && !isset($asset['inline_references'])
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
            // A linked stylesheet between two <style> elements splits the run.
            'link_position' => $asset['stylesheet_link_position'] ?? null,
        ), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $entryEvidence @param array<string, array<string, mixed>> $documents @param array<int, array<string, mixed>> $assets */
    private function reusableComponentEvidence(string $entryPath, array $entryEvidence, array $documents, array $assets): array
    {
        $candidates = array();
        $append = static function (string $sourcePath, array $evidence) use (&$candidates): void {
            foreach (ArrayField::array($evidence, 'candidates') as $candidate) {
                if (is_array($candidate) && is_string($candidate['fingerprint'] ?? null) && is_string($candidate['path'] ?? null) && is_string($candidate['tag'] ?? null)) $candidates[$candidate['fingerprint']][] = array('source_path' => $sourcePath, 'path' => $candidate['path'], 'tag' => $candidate['tag']);
            }
        };
        $append($entryPath, $entryEvidence);
        foreach ($documents as $sourcePath => $document) $append((string) $sourcePath, ArrayField::array($document, 'reusable_components'));
        $mapped = array();
        foreach ($assets as $asset) foreach (ArrayField::array($asset, 'component_occurrence_counts') as $fingerprint => $count) if (is_string($fingerprint) && is_int($count)) $mapped[$fingerprint] = (int) ($mapped[$fingerprint] ?? 0) + $count;
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
        $scan = static function (string $sourcePath, array $evidence) use (&$documentScans): void { $documentScans[] = array('source_path' => $sourcePath, 'scanned_node_count' => (int) ($evidence['scanned_node_count'] ?? 0), 'candidate_count' => (int) ($evidence['candidate_count'] ?? 0), 'omitted_candidate_count' => (int) ($evidence['omitted_candidate_count'] ?? 0), 'truncated' => array_values(ArrayField::array($evidence, 'truncated'))); };
        $scan($entryPath, $entryEvidence);
        foreach ($documents as $sourcePath => $document) $scan((string) $sourcePath, ArrayField::array($document, 'reusable_components'));
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
     * Compile a standalone source fragment through the canonical format bridge.
     *
     * @param array<string,mixed> $options Transformer context/provenance options.
     */
    public function compileFragment(string $content, string $source = 'fragment', string $format = 'html', array $options = array()): TransformerResult
    {
        return $this->cssRuleTrees->run(fn () => $this->compileFragmentWithCssRuleTrees($content, $source, $format, $options));
    }

    private function compileFragmentWithCssRuleTrees(string $content, string $source = 'fragment', string $format = 'html', array $options = array()): TransformerResult
    {
        $bridge = new FormatBridge();
        return $bridge->convertResult($content, $format, 'blocks', array_merge(array(
            'source'       => $source,
            'source_scope' => 'artifact-fragment',
        ), $options));
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array{blocks: array<int, array<string, mixed>>, serialized_blocks: string, diagnostics: array<int, array<string, mixed>>, fallbacks: array<int, array<string, mixed>>, assets: array<int, array<string, mixed>>, runtime_islands: array<int, array<string, mixed>>, generated_blocks: array<int, array<string, mixed>>, gutenberg_gaps: array<int, array<string, mixed>>, interaction_candidates: array<int, array<string, mixed>>, superseded_selectors: array<int, string>, author_stylesheet_projections: array<int, array<string, mixed>>, runtime_script_projections: array<int, array<string, mixed>>, shell_artifacts: array<int, array<string, mixed>>, core_html_fallback_evidence: array<string, mixed>, document_root_markers: array<int, string>}
     */
    private function compileEntryBlocks(string $html, string $entryPath, array $files, string $generatedBlockNamespace = '', array $runtimeDeclarations = array()): array
    {
        $result = $this->compileHtmlDocumentBlocks($html, $entryPath, $files, 'artifact-entry', $generatedBlockNamespace, true, $runtimeDeclarations);

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
            'document_root_markers' => $result['document_root_markers'],
            'design_usage' => $result['design_usage'],
        );
    }

    private function compileHtmlDocumentBlocks(string $html, string $sourcePath, array $files, string $sourceScope, string $generatedBlockNamespace = '', bool $extractGlobalShell = false, array $runtimeDeclarations = array(), int $globalShellContextDepth = 0): array
    {
        return $this->cssRuleTrees->runPage(fn () => $this->compileHtmlDocumentBlocksWithCssRuleTrees($html, $sourcePath, $files, $sourceScope, $generatedBlockNamespace, $extractGlobalShell, $runtimeDeclarations, $globalShellContextDepth));
    }

    private function compileHtmlDocumentBlocksWithCssRuleTrees(string $html, string $sourcePath, array $files, string $sourceScope, string $generatedBlockNamespace, bool $extractGlobalShell, array $runtimeDeclarations, int $globalShellContextDepth): array
    {
        ++$this->htmlDocumentTransformCount;
        $preserveBlockMarkup = $this->isBlockMarkup($html);
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
                'document_root_markers' => array(),
                'design_usage' => array(),
                'reusable_components' => array(),
                'runtime_block_paths' => array(),
                'visual_block_paths' => array(),
                'editability_report' => null,
                'responsive_counterpart_contracts' => array(),
                'layout_geometry_proof' => array(),
            );
        }

        $themePreferenceOwnership = $this->themePreferenceOwnershipForSource($runtimeDeclarations, $sourcePath);
        $stylesheetAssets = $this->stylesheetAssetsForSource($html, $sourcePath, $files, true);
        $stylesheetPayloads = $this->linkedStylesheetPayloads($stylesheetAssets, $sourcePath, $files);
        $layerOrderCss = implode("\n", array_map(fn(array $stylesheet): string => $this->stylesheetOccurrenceContent($stylesheet, $files), $stylesheetAssets));
        $analysisCache = $this->cacheHtmlAnalysis
            ? $this->htmlTransformerAnalysisCache ??= new HtmlTransformerAnalysisCache()
            : new HtmlTransformerAnalysisCache();
        $runtimeDomSelectors = $this->runtimeSelectors->runtimeDomSelectors($html, $sourcePath, $files);
        $runtimeProjectionSelectors = $this->runtimeSelectors->runtimeProjectionSelectors($html, $sourcePath, $files);
        $runtimeProjectionScriptAssets = $this->runtimeProjectionScriptAssetsForSource($html, $sourcePath, $files);
        foreach ($themePreferenceOwnership as $ownership) {
            $ownerPath = $ownership['runtime_script_path'] ?? null;
            if (!is_string($ownerPath) || array_filter($runtimeProjectionScriptAssets, static fn(array $asset): bool => $ownerPath === ($asset['path'] ?? null))) continue;
            $ownerAsset = null;
            foreach ($files as $file) if ($ownerPath === ($file['path'] ?? null) && is_string($file['content'] ?? null)) { $ownerAsset = $file; break; }
            if (is_array($ownerAsset) && hash('sha256', $ownerAsset['content']) === ($ownership['runtime_script_sha256'] ?? null)) {
                // The capture declaration binds this browser-observed asset even
                // when a static snapshot has intentionally omitted the source
                // app's script tag before compilation. The asset is analysis
                // evidence only; it is not re-enqueued into the generated page.
                $runtimeProjectionScriptAssets[] = array('path' => $ownerPath, 'content' => $ownerAsset['content']);
            } elseif (is_string($ownership['runtime_script_content'] ?? null)
                && hash('sha256', $ownership['runtime_script_content']) === ($ownership['runtime_script_sha256'] ?? null)) {
                // SSI's inert-script policy can remove executable source files
                // before compilation. The canonical browser evidence retains
                // the exact bounded bytes needed for analysis without reviving
                // the source script in the imported page.
                $runtimeProjectionScriptAssets[] = array('path' => $ownerPath, 'content' => $ownership['runtime_script_content']);
            }
        }
        if (null !== $this->beforeDocumentTransform) {
            ($this->beforeDocumentTransform)($sourcePath, $sourceScope);
        }
        $result = (new HtmlTransformer(analysisCache: $analysisCache))->transform($this->safeHtmlDocumentHtml($html, $sourcePath, $files), array(
            'source'                    => $sourcePath,
            'source_scope'              => $sourceScope,
            'native_document_context'   => true,
            'source_has_purchase_affordance' => $this->sourceHasPurchaseAffordance($files),
            'declarative_state_html'    => $html,
            'static_css'                => trim(implode("\n", array_column($stylesheetPayloads, 'content'))),
            'stylesheet_payloads'       => $stylesheetPayloads,
            // Rule analysis reads each occurrence with its imports expanded in
            // place, conditions attached; projections keep the authored bytes.
            'author_stylesheet_assets'  => $this->authorStylesheetAssetsWithImports($stylesheetAssets, $files),
            'author_layer_order'        => (new AuthorCascadeLayerOrder())->statement($layerOrderCss),
            'shared_stylesheet_paths'   => FileOwnership::sharedStylesheetPaths($files),
            'skip_author_stylesheet_materialization' => true,
            'asset_metadata'            => $this->assetMetadataForSource($sourcePath, $files, $html),
            'runtime_script_metadata'   => $this->runtimeScriptMetadataForSource($html, $sourcePath, $files),
            'runtime_dom_selectors'     => $runtimeDomSelectors,
            'runtime_behavioral_selectors' => $runtimeDomSelectors,
            'runtime_projection_selectors' => $runtimeProjectionSelectors,
            'runtime_projection_script_assets' => $runtimeProjectionScriptAssets,
            'theme_preference_ownership' => $themePreferenceOwnership,
            'runtime_canvas_selectors'  => $this->runtimeSelectors->runtimeCanvasSelectors($html, $sourcePath, $files),
            'generated_block_namespace' => $generatedBlockNamespace,
            'generated_asset_root'       => $this->generatedAssetRoot,
            'extract_global_shell'       => $extractGlobalShell,
            'global_shell_context_depth' => $globalShellContextDepth,
            // A shared shell's projection joins every page's stylesheet, where
            // a rule it left unprojected would bypass the page's projection.
            'project_only_matched_author_rules' => 'artifact-shared-shell' === $sourceScope,
            'layout_geometry_proof'      => $this->layoutGeometryProofForSource($files, $sourcePath),
        ));
        $blockCompilationOutput = $result->blockCompilationOutput;
        if (!$blockCompilationOutput instanceof BlockCompilationOutput) {
            throw new \LogicException('HTML compilation must provide BlockCompilationOutput.');
        }

        return array(
            'step_timings'      => $result->metrics['step_timings'] ?? array(),
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
            'runtime_islands'   => InlineScriptIslands::withInlineScripts(
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
            'document_root_markers' => $blockCompilationOutput->documentRootMarkers,
            'design_usage' => $blockCompilationOutput->designUsage,
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
     * @param array<int, array<string, mixed>> $files
     */
    private function safeHtmlDocumentHtml(string $html, string $entryPath, array $files): string
    {
        $html = $this->withoutMaterializedScriptTags($html, $entryPath, $files);
        $html = $this->withoutMaterializedStyleTags($html, $entryPath, $files);
        $html = $this->withInlinedSvgSpriteSymbols($html, $entryPath, $files);
        $html = preg_replace_callback('/<img\s+[^>]*src\s*=\s*(["\'])([^"\']+)\1[^>]*>/i', function (array $matches) use ($entryPath, $files): string {
            $asset = $this->fileIndex->findAssetByHtmlReference((string) $matches[2], $entryPath, $files);
            if ( is_array($asset) && 'image/svg+xml' === ($asset['mime_type'] ?? '') && ! $this->isSafeImageAsset($asset) ) {
                return '';
            }

            return (string) $matches[0];
        }, $html) ?? $html;

        return $html;
    }

    /**
     * An SVG `<use>` that names a symbol in a sprite file shipped with the
     * artifact (`href="/icons.svg#mail"`) renders that symbol in the source.
     * Copy each referenced symbol, and the definitions it references in turn,
     * into a hidden sprite in the document and point the `<use>` at the local
     * copy, so the icon survives as same-document artwork.
     *
     * @param array<int, array<string, mixed>> $files
     */
    private function withInlinedSvgSpriteSymbols(string $html, string $entryPath, array $files): string
    {
        if ( ! preg_match('/<use\b[^>]*\s(?:xlink:)?href\s*=\s*["\'][^"\'#]+#/i', $html) ) {
            return $html;
        }

        $sprites = array();
        $definitions = array();
        $html = preg_replace_callback('/(<use\b[^>]*?\s(?:xlink:)?href\s*=\s*)(["\'])([^"\'#]+)#([^"\']+)\2/i', function (array $match) use ($entryPath, $files, &$sprites, &$definitions): string {
            $path = html_entity_decode($match[3], ENT_QUOTES | ENT_HTML5);
            if ( ! array_key_exists($path, $sprites) ) {
                $asset = $this->fileIndex->findAssetByHtmlReference($path, $entryPath, $files);
                $content = is_array($asset) && 'image/svg+xml' === ($asset['mime_type'] ?? '') ? (string) ($asset['content'] ?? '') : '';
                $sprite = new DOMDocument();
                $sprites[$path] = '' !== $content && SourceDom::isSafeSvgContent($content) && $sprite->loadXML($content, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING) ? new DOMXPath($sprite) : null;
            }
            $xpath = $sprites[$path];
            $id = html_entity_decode($match[4], ENT_QUOTES | ENT_HTML5);
            if ( ! $xpath instanceof DOMXPath || ! $this->collectSvgSpriteDefinition($xpath, $id, $definitions) ) {
                return $match[0];
            }

            return $match[1] . $match[2] . '#' . htmlspecialchars($id, ENT_QUOTES) . $match[2];
        }, $html) ?? $html;
        if ( array() === $definitions ) {
            return $html;
        }

        // One <defs> per definition, so an icon copies only what it uses.
        $sprite = '<svg aria-hidden="true" style="display:none"><defs>' . implode('</defs><defs>', $definitions) . '</defs></svg>';
        $count = 0;
        $html = preg_replace_callback('/<body\b[^>]*>/i', static fn (array $body): string => $body[0] . $sprite, $html, 1, $count) ?? $html;

        return $count > 0 ? $html : $sprite . $html;
    }

    /** @param array<string, string> $definitions */
    private function collectSvgSpriteDefinition(DOMXPath $xpath, string $id, array &$definitions): bool
    {
        if ( isset($definitions[$id]) ) {
            return true;
        }
        $nodes = '' === $id || str_contains($id, '"') ? false : $xpath->query('//*[@id="' . $id . '"]');
        $node = false !== $nodes ? $nodes->item(0) : null;
        if ( ! $node instanceof DOMElement ) {
            return false;
        }
        $definitions[$id] = (string) $node->ownerDocument?->saveXML($node);
        preg_match_all('/\burl\(\s*["\']?#([^\s"\')]+)|\bhref\s*=\s*["\']#([^"\']+)/i', $definitions[$id], $references, PREG_SET_ORDER);
        foreach ( $references as $reference ) {
            $this->collectSvgSpriteDefinition($xpath, '' !== $reference[1] ? $reference[1] : $reference[2], $definitions);
        }

        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     */
    private function withoutMaterializedScriptTags(string $html, string $entryPath, array $files): string
    {
        $scriptIndex = 0;
        $hasDeclaredScriptFiles = false;
        foreach ( $files as $file ) {
            if ( is_array($file) && 'inline-script' !== ($file['source'] ?? '') && ArtifactFileIndex::isMaterializedScriptAsset($file) ) {
                $hasDeclaredScriptFiles = true;
                break;
            }
        }

        return preg_replace_callback('/<script\b([^>]*)>(.*?)<\/script>/is', function (array $matches) use ($entryPath, $files, $hasDeclaredScriptFiles, &$scriptIndex): string {
            ++$scriptIndex;
            $src = HtmlTagScanner::attribute((string) $matches[1], 'src');
            if ( '' !== $src ) {
                $asset = $this->fileIndex->findAssetByHtmlReference($src, $entryPath, $files);
                if ( ! is_array($asset) || ! ArtifactFileIndex::isMaterializedScriptAsset($asset) ) {
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
        $files = ArtifactNormalizer::inlineOccurrences($files);
        $styleIndex = 0;
        return preg_replace_callback('/<style\b([^>]*)>(.*?)<\/style>/is', function (array $matches) use ($entryPath, $files, &$styleIndex): string {
            $attributes = (string) $matches[1];
            if ( ! StyleTagScanner::isCssType(HtmlTagScanner::attribute($attributes, 'type')) || '' === trim((string) $matches[2]) ) {
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

    /** Drop impossible engine replay/capture rules before bundling and plan enqueue projection. */
    private function pruneAbsentSupportSubjects(array $assets, array $files, string $markup): array
    {
        $captureHashes = array();
        foreach ($files as $file) {
            if ('html' !== ($file['kind'] ?? '') || !is_string($file['content'] ?? null)) continue;
            // Document root attributes survive on the served WordPress shell,
            // outside serialized post and template-part markup.
            foreach (array('html', 'body') as $tag) {
                if (preg_match('/<' . $tag . '\\b[^>]*>/i', $file['content'], $root)) $markup .= preg_replace('/^<' . $tag . '\\b/i', '<div', $root[0]) . '</div>';
            }
            foreach (StyleTagScanner::scan($file['content']) as $style) {
                if (StyleTagScanner::isCaptureOnly($style['attributes'])) $captureHashes[hash('sha256', trim($style['content']))] = true;
            }
        }
        $subjects = new SupportCssSubjects($markup);
        $result = array();
        foreach ($assets as $asset) {
            if ('css' === ($asset['kind'] ?? '') && is_string($asset['content'] ?? null)
                && (in_array($asset['source'] ?? '', array('wordpress-compat'), true)
                    || isset($captureHashes[$asset['source_hash'] ?? '']) || isset($captureHashes[hash('sha256', trim($asset['content']))]))) {
                $content = $subjects->stylesheet($asset['content']);
                if ('' === trim($content)) continue;
                if ($content !== $asset['content']) {
                    $hash = hash('sha256', $content);
                    $asset['content'] = $content;
                    $asset['bytes'] = strlen($content);
                    $asset['hash'] = $hash;
                    $asset['path'] = $asset['target_path'] = 'assets/css/' . ($asset['source'] ?? 'support') . '-' . substr(hash('sha256', json_encode($asset['compilation'] ?? array()) . "\0" . $content), 0, 16) . '.css';
                }
            }
            $result[] = $asset;
        }
        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<string, mixed>|null
     */
    private function findInlineScriptAsset(string $entryPath, int $scriptIndex, array $files): ?array
    {
        $files = ArtifactNormalizer::inlineOccurrences($files);
        $selector = 'script:nth-of-type(' . $scriptIndex . ')';
        foreach ( $files as $file ) {
            if ( 'inline-script' !== ($file['source'] ?? '') || $entryPath !== ($file['source_path'] ?? '') || $selector !== ($file['selector'] ?? '') || ! ArtifactFileIndex::isMaterializedScriptAsset($file) ) {
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
            if (!StylesheetActivation::active($stylesheet)) continue;
            // Analysis must see imported rules at their authored occurrence,
            // with import media/supports/layer qualifiers still attached.
            $content = $this->stylesheetOccurrenceContent(array('content' => (string) ($stylesheet['content'] ?? ''), 'path' => (string) ($stylesheet['path'] ?? $sourcePath)) + $stylesheet, $files);
            if ( '' !== trim($content) ) {
                $payloads[] = array(
                    'content' => $this->artifactRelativeStylesheetContent($content, (string) ($stylesheet['source_path'] ?? $sourcePath), $files),
                    'source_hash' => hash('sha256', $content),
                    'media' => (string) ($stylesheet['media'] ?? ''),
                );
            }
        }

        return $payloads;
    }

    /**
     * Preserve authored stylesheet boundaries and document order for selector
     * projection. Inline CSS is normalized as its own asset by ArtifactNormalizer.
     *
     * @param array<int, array<string, mixed>> $files
     * @return list<array{path: string, content: string, source_hash: string}>
     */
    private function stylesheetAssetsForSource(string $html, string $sourcePath, array $files, bool $excludeDeviceRuntime = false): array
    {
        $files = ArtifactNormalizer::inlineOccurrences($files);
        $byPath = array();
        $inline = array();
        foreach ( $files as $file ) {
            if ( 'css' !== ($file['kind'] ?? '') || ! is_string($file['path'] ?? null) || ! is_string($file['content'] ?? null) || isset($file['metadata']['page_stylesheet_of']) ) {
                continue;
            }
            $byPath[$file['path']] = $file;
            if ( 'inline-style' === ($file['source'] ?? '') && $sourcePath === ($file['source_path'] ?? '') ) {
                $inline[(int) ($file['stylesheet_index'] ?? 0)] = $file;
            }
        }
        $excludeDeviceRuntime = $excludeDeviceRuntime && str_contains($html, CaptureEvidence::DEVICE_VISIBILITY);
        $cacheKey = hash('sha256', $sourcePath . "\0" . (int) $excludeDeviceRuntime . "\0" . $html);
        if (isset($this->stylesheetOccurrenceCache[$cacheKey])) {
            // Cache occurrence identity/order, not projected declaration bytes.
            // The replay reads the final payload after source-selector projection.
            return array_map(static function (array $asset) use ($byPath): array {
                $file = $byPath[$asset['path']] ?? null;
                if (is_array($file)) {
                    $asset['content'] = $file['content'];
                    $asset['source_hash'] = (string) ($file['provenance']['hash'] ?? hash('sha256', $file['content']));
                }
                return $asset;
            }, $this->stylesheetOccurrenceCache[$cacheKey]);
        }
        ++$this->stylesheetAssetDiscoveryCount;
        $assets = array();
        $seenPaths = array();
        $inlineIndex = 0;
        $activation = StylesheetActivation::links($html);
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
                if ( ! StyleTagScanner::isCssType(HtmlTagScanner::attribute($attributes, 'type')) ) {
                    continue;
                }
                if ( '' === trim($tagRecord['content']) ) {
                    continue;
                }
                ++$inlineIndex;
                // Device carriers own output visibility. Capture's selection
                // runtime must not project a frozen hide gate onto their shells.
                if (array_key_exists(CaptureEvidence::DEVICE_VISIBILITY, HtmlTagScanner::attributes($attributes))
                    && $excludeDeviceRuntime) continue;
                $file = $inline[$inlineIndex] ?? null;
                if ( is_array($file) ) {
                    $assets[] = array( 'path' => $file['path'], 'source_path' => $file['source_path'] ?? $file['path'], 'content' => $file['content'], 'source_hash' => (string) ($file['provenance']['hash'] ?? hash('sha256', $file['content']) ), 'media' => StyleTagScanner::authorMedia($attributes), 'type' => (string) ($file['type'] ?? '') );
                    $seenPaths[$file['path']] = true;
                } elseif ( '' !== ($content = trim(html_entity_decode($tagRecord['content'], ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ) {
                    // Generated inline-style files can be omitted at the artifact
                    // file limit. Their source HTML was accepted independently,
                    // so retain the authored stylesheet for source analysis.
                    $assets[] = array( 'path' => 'inline-style-' . $inlineIndex . '.css', 'source_path' => 'inline-style', 'content' => $content, 'source_hash' => hash('sha256', $content), 'media' => StyleTagScanner::authorMedia($attributes), 'type' => HtmlTagScanner::attribute($attributes, 'type') );
                }
                $assets[array_key_last($assets)]['capture_only'] = StyleTagScanner::isCaptureOnly($attributes);
                continue;
            }
            $tag = $tagRecord['tag'];
            if ( ! preg_match('/^<link\b/i', $tag) || ! StyleTagScanner::isStylesheetRel(HtmlTagScanner::attribute((string) $tag, 'rel')) || ! StyleTagScanner::isCssType(HtmlTagScanner::attribute((string) $tag, 'type')) ) {
                continue;
            }
            $sourcePathForLink = ArtifactPath::linkedFilePath(HtmlTagScanner::attribute((string) $tag, 'href'), $sourcePath, $files);
            $path = $sourcePathForLink;
            $file = $byPath[$path] ?? null;
            if ( is_array($file) ) {
                $assets[] = array( 'path' => $path, 'source_path' => $sourcePathForLink, 'content' => $file['content'], 'source_hash' => (string) ($file['provenance']['hash'] ?? hash('sha256', $file['content']) ), 'media' => StyleTagScanner::authorMedia((string) $tag), 'type' => HtmlTagScanner::attribute((string) $tag, 'type') );
                $assets[array_key_last($assets)]['stylesheet_activation'] = $activation[$tagRecord['offset']];
            }
        }
        return $this->stylesheetOccurrenceCache[$cacheKey] = $assets;
    }

    /**
     * Replay the pre-projection authored occurrence stream, excluding native
     * item subjects already carried by projection. Generated editor alternatives are
     * transport, not additional source navigation rules. File transport order
     * is not CSS order.
     *
     * @param array<int, array<string, mixed>> $files
     * @param array<int, array<string, mixed>> $authoredFiles
     * @return array<int, array<string, mixed>>
     */
    private function wordpressCompatAssetsForDocuments(array $files, array $authoredFiles, array &$diagnostics = array()): array
    {
        // Replay authored rules, not generated element/editor alternatives.
        // A source list-item subject replaced by a native item projection already
        // carries its geometry in the author sheet and must not be replayed.
        // Apply the same selection to route-owned deltas.
        $authoredByPath = array_column($authoredFiles, null, 'path');
        $transformer = new CssStylesheetTransformer();
        foreach ($files as &$file) {
            if ('css' !== ($file['kind'] ?? '') || !is_string($file['content'] ?? null)) continue;
            $source = $authoredByPath[$file['path']]['content'] ?? $authoredByPath[$file['metadata']['page_stylesheet_of'] ?? '']['content'] ?? $file['content'];
            $native = array_fill_keys($file['metadata']['native_selectors'] ?? array(), true);
            $file['content'] = $transformer->transformStyleRules($source, static function (string $prelude, string $body) use ($native): string {
                $remaining = array_filter(CssStylesheetTransformer::splitSelectorList($prelude) ?? array(), static function (string $selector) use ($native): bool {
                    $selector = preg_replace('#/\*.*?\*/#s', '', trim($selector)) ?? $selector;
                    return !isset($native[trim($selector)]);
                });
                return array() === $remaining ? '' : implode(',', $remaining) . '{' . $body . '}';
            });
        }
        unset($file);
        $assets = array();
        $scripts = $this->fileIndex->allScriptContents($files);
        foreach ($files as $document) {
            if ('html' !== ($document['kind'] ?? '') || !is_string($document['content'] ?? null)) continue;
            // An included component is replayed inside each document that includes it.
            if (!empty($document['metadata']['compilation']['included_component'])) continue;
            $sourcePath = (string) $document['path'];
            $ownership = FileOwnership::of($document);
            $documentFiles = $files;
            $parts = array();
            $stylesheets = $this->stylesheetAssetsForSource($document['content'], $sourcePath, $documentFiles);
            foreach ($stylesheets as $stylesheet) {
                $unit = array($this->stylesheetOccurrenceContent($stylesheet, $documentFiles));
                $deltas = array_filter($files, fn(array $file): bool =>
                    ($file['metadata']['page_stylesheet_of'] ?? null) === $stylesheet['path'] && FileOwnership::of($file) === $ownership
                );
                usort($deltas, static fn(array $left, array $right): int => strcmp($left['path'], $right['path']));
                foreach ($deltas as $delta) {
                    $unit[] = $this->compatibilityStylesheetContent($delta['content'], $delta['path'], $documentFiles);
                }
                $content = implode("\n", $unit);
                $media = trim((string) ($stylesheet['media'] ?? ''));
                $parts[] = '' === $media ? $content : '@media ' . $media . '{' . $content . '}';
            }
            $compatDiagnostics = array();
            $asset = $this->wordpressCompat->asset($documentFiles, $transformer->concatenateWithoutRedundantRules($parts), $scripts, $compatDiagnostics);
            foreach ($compatDiagnostics as $diagnostic) {
                $diagnostic['source_path'] = $sourcePath;
                $diagnostics[] = $diagnostic;
            }
            if (null === $asset) continue;
            // Content-identical replay can still have different route ownership.
            // Keep the existing page-asset contract instead of promoting a
            // partial set of consumers to an unconditionally shared cascade.
            $asset['compilation'] = $ownership;
            $asset['path'] = $asset['target_path'] = 'assets/css/wordpress-compat-'
                . substr(hash('sha256', json_encode($ownership) . "\0" . $asset['content']), 0, 16) . '.css';
            $assets[] = $asset;
        }
        return $assets;
    }

    /**
     * An inline <style> sheet is stored at a content-addressed path, but its
     * @import references resolve against the owning document, like the
     * browser's base URL. Linked sheets carry their own path as source_path.
     *
     * @param array<string, mixed> $stylesheet
     * @param array<int, array<string, mixed>> $files
     */
    private function stylesheetOccurrenceContent(array $stylesheet, array $files): string
    {
        return $this->compatibilityStylesheetContent((string) $stylesheet['content'], (string) ($stylesheet['source_path'] ?? $stylesheet['path']), $files);
    }

    /**
     * Imported files are projection targets too, but their rules are analyzed
     * only in the importing occurrence's expanded cascade stream.
     *
     * @param array<int, array<string, mixed>> $stylesheets
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function authorStylesheetAssetsWithImports(array $stylesheets, array $files): array
    {
        $assets = array();
        $importsByPath = array();
        foreach ($stylesheets as $stylesheet) {
            $imports = array();
            $cascade = $this->compatibilityStylesheetContent($stylesheet['content'], $stylesheet['source_path'] ?? $stylesheet['path'], $files, array(), false, $imports, (string) ($stylesheet['media'] ?? ''));
            $assets[] = $stylesheet + array('cascade_content' => $cascade);
            foreach ($imports as $import) {
                foreach (array('capture_only', 'stylesheet_activation') as $key) {
                    if (isset($stylesheet[$key])) $import[$key] = $stylesheet[$key];
                }
                // One file is one projection target. Repeated imports (such as
                // one per viewport band) widen its media instead of repeating it.
                $path = $import['path'];
                if (!isset($importsByPath[$path])) {
                    $importsByPath[$path] = $import;
                    continue;
                }
                if ($importsByPath[$path]['content'] !== $import['content']) {
                    // Nested intersections may differ between occurrences of
                    // one target. Preserve their union as separately gated CSS.
                    $importsByPath[$path]['content'] = MediaCondition::wrap($importsByPath[$path]['content'], array($importsByPath[$path]['media']))
                        . MediaCondition::wrap($import['content'], array($import['media']));
                    $importsByPath[$path]['media'] = '';
                    continue;
                }
                $media = array($importsByPath[$path]['media'], $import['media']);
                $importsByPath[$path]['media'] = in_array('', $media, true) ? '' : implode(', ', array_unique($media));
            }
        }
        return array_merge($assets, array_values($importsByPath));
    }

    private function compatibilityStylesheetContent(string $css, string $path, array $files, array $ancestors = array(), bool $importedLayer = false, ?array &$imports = null, string $inheritedMedia = '', array $nestedMedia = array()): string
    {
        if (isset($ancestors[$path])) return '';
        $ancestors[$path] = true;
        if ($importedLayer) $this->layeredNavigationImports[$path] = true;
        $split = (new CssStylesheetTransformer())->splitLeadingAtRulePreamble($css);
        $preamble = array();
        $byPath = array_column($files, null, 'path');
        foreach ($split['statements'] as $statement) {
            $clean = rtrim(trim(preg_replace('#^(?:\s|/\*.*?\*/)*#s', '', $statement) ?? $statement), ';');
            if (!preg_match('/^@import\s+(?:url\(\s*(?:"([^"]+)"|\'([^\']+)\'|([^\s)]+))\s*\)|"([^"]+)"|\'([^\']+)\')(.*)$/is', $clean, $match)) {
                $preamble[] = $statement;
                continue;
            }
            $reference = $match[1] ?: ($match[2] ?: ($match[3] ?: ($match[4] ?: $match[5])));
            // Root-relative imports name the captured site root, like root-relative <link> hrefs.
            $importPath = isset($byPath[$reference]) ? $reference : ArtifactPath::linkedFilePath($reference, $path, $files);
            $file = $byPath[$importPath] ?? null;
            if (!is_array($file) || 'css' !== ($file['kind'] ?? '') || !is_string($file['content'] ?? null)) continue;
            $conditions = CssValueSplitter::splitTopLevelWhitespace(trim($match[6]));
            $layer = '';
            $supports = '';
            if (isset($conditions[0]) && preg_match('/^layer(?:\((.*)\))?$/is', $conditions[0], $condition)) {
                $layer = '@layer' . (isset($condition[1]) ? ' ' . $condition[1] : '');
                array_shift($conditions);
            }
            if (isset($conditions[0]) && preg_match('/^supports\((.*)\)$/is', $conditions[0], $condition)) {
                $supports = '@supports ' . (preg_match('/^[\w-]+\s*:/', $condition[1]) ? '(' . $condition[1] . ')' : $condition[1]);
                array_shift($conditions);
            }
            $media = implode(' ', $conditions);
            $projectionMedia = MediaCondition::combine($inheritedMedia, $media);
            $projectionNestedMedia = $nestedMedia;
            if (null === $projectionMedia) {
                $projectionNestedMedia[] = $inheritedMedia;
                $projectionMedia = $media;
            }
            if (null !== $imports && !isset($ancestors[$importPath])) {
                $imports[] = array('path' => $importPath, 'source_path' => $importPath, 'content' => MediaCondition::wrap($file['content'], $projectionNestedMedia), 'source_hash' => (string) ($file['provenance']['hash'] ?? hash('sha256', $file['content'])), 'media' => $projectionMedia, 'cascade_content' => '');
            }
            $content = $this->compatibilityStylesheetContent($file['content'], $importPath, $files, $ancestors, $importedLayer || '' !== $layer, $imports, $projectionMedia, $projectionNestedMedia);
            if (array() !== $conditions) $content = '@media ' . $media . '{' . $content . '}';
            if ('' !== $supports) $content = $supports . '{' . $content . '}';
            if ('' !== $layer) $content = $layer . '{' . $content . '}';
            $preamble[] = $content;
        }
        return implode("\n", $preamble) . $split['stylesheet'];
    }

    /** @param array<int, array<string, mixed>> $files */
    private function artifactRelativeStylesheetContent(string $content, string $stylesheetPath, array $files): string
    {
        $paths = $this->fileIndex->knownPaths($files);
        return CssUrlRewriter::rewrite($content, static function (string $reference) use ($stylesheetPath, $paths): string {
            if ('' === $reference || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|#|\?)~i', $reference)) return $reference;
            preg_match('/^([^?#]*)(.*)$/s', $reference, $parts);
            $path = str_starts_with($parts[1] ?? '', '/')
                ? ArtifactPath::resolveRelativePath(ltrim((string) ($parts[1] ?? ''), '/'))
                : ArtifactPath::resolveRelativePath((string) ($parts[1] ?? ''), $stylesheetPath);
            return '' !== $path && isset($paths[$path]) ? $path . ($parts[2] ?? '') : $reference;
        });
    }

    /** Number of distinct source paths projected by the latest compose. */
    private int $authorStylesheetProjectionCount = 0;

    /**
     * @param array<int, array<string, mixed>> $files
     * @param array<int, array<string, mixed>> $projections
     * @param array<int, array<string, mixed>> $primaryProjections
     * @param string $primaryOwner The page that produced the primary projections, '' when they are site-wide.
     * @return array<int, array<string, mixed>>
     */
    private function applyAuthorStylesheetProjections(array $files, array $projections, array $primaryProjections = array(), string $primaryOwner = '', array $pageMarkup = array(), array &$projectionDiagnostics = array(), string $sharedMarkup = '', ?\Automattic\BlocksEngine\PhpTransformer\Support\StepTimer $timer = null): array
    {
        $timer?->start('compose.author_stylesheet_projections');
        $this->authorStylesheetProjectionCount = 0;
        // Asset identity is its path, not the number of partition occurrences.
        $files = array_values(array_column($files, null, 'path'));
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
        $nativeByContent = array();
        foreach ($projections as $projection) {
            foreach ($projection['native_selectors'] ?? array() as $selector) {
                $nativeByContent[$projection['path']][$projection['content']][$selector] = true;
            }
        }
        $primaryByPath = array();
        foreach ( $primaryProjections as $projection ) {
            if ( is_string($projection['path'] ?? null) && is_string($projection['content'] ?? null) ) {
                $primaryByPath[$projection['path']][$projection['content']] = true;
            }
        }
        // Shared-part projections apply site-wide unless their inline landmark
        // carries member-only ancestor context; page projections have an owner.
        $sharedByPath = array();
        $memberShellsByPath = array();
        $pageByPath = array();
        $ownersByPath = array();
        foreach ( $projections as $projection ) {
            if (isset($projection['member_owners']) && is_string($projection['path'] ?? null) && is_string($projection['content'] ?? null)) {
                foreach ($projection['member_owners'] as $owner) $memberShellsByPath[$projection['path']][$owner][] = $projection['content'];
                continue;
            }
            if ( is_string($projection['path'] ?? null) && is_string($projection['content'] ?? null) && '' !== (string) ($projection['owner'] ?? '') ) {
                $ownersByPath[$projection['path']][$projection['content']][(string) $projection['owner']] = true;
            }
            if ( ! is_string($projection['path'] ?? null) || ! is_string($projection['content'] ?? null) || isset($primaryByPath[$projection['path']][$projection['content']]) ) {
                continue;
            }
            $owner = (string) ($projection['owner'] ?? '');
            if ( '' === $owner ) {
                $sharedByPath[$projection['path']][$projection['content']] = true;
            } else {
                $pageByPath[$projection['path']][$owner][] = $projection['content'];
            }
        }
        $transformer = new CssStylesheetTransformer();
        $routeIdentities = array();
        $routeDocuments = array();
        $timer?->start('compose.author_stylesheet_projections.route_documents');
        foreach ($files as $document) {
            if ('html' !== ($document['kind'] ?? '')) continue;
            $source = (string) $document['path'];
            $owner = FileOwnership::of($document)['id'];
            $routeIdentities[$owner] = \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan::identity('page', $source, $source);
            if (!isset($pageMarkup[$owner])) continue;
            // Parse the lowered markup once per route, never once per arm or
            // stylesheet. Retain source root/body context just as projection
            // does; block comments are inert to the HTML parser.
            $dom = HtmlSerialization::parseDocument('<html><body>' . $pageMarkup[$owner] . '</body></html>');
            $attributes = \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentRootContext::metadataFromHtml((string) ($document['content'] ?? ''));
            foreach (array('html' => 'root_attributes', 'body' => 'body_attributes') as $tag => $key) {
                $element = $dom->getElementsByTagName($tag)->item(0);
                foreach ($attributes[$key] ?? array() as $name => $value) $element?->setAttribute($name, $value);
            }
            $routeDocuments[$owner] = $dom;
        }
        $routeMatchIndex = new \Automattic\BlocksEngine\PhpTransformer\Css\RouteSelectorMatchIndex($routeDocuments);
        $reserved = array_fill_keys(array_column($files, 'path'), true);
        $output = array();
        foreach ( $files as $file ) {
            $path = (string) ($file['path'] ?? '');
            if ( 'css' !== ($file['kind'] ?? '') || ( ! isset($primaryByPath[$path]) && ! isset($sharedByPath[$path]) && ! isset($pageByPath[$path]) ) ) {
                $output[] = $file;
                continue;
            }
            ++$this->authorStylesheetProjectionCount;
            $pages = $pageByPath[$path] ?? array();
            $authoritativeContent = array_keys($primaryByPath[$path] ?? array());
            // The site-wide copy is the projection most pages produced, not
            // necessarily the entry page's: with per-element markers named
            // from source context, pages sharing a layout project a shared
            // stylesheet to the same text, and each page that differs gets
            // the slice it needs. A site-wide entry projection (a template
            // surface) stays authoritative.
            if ( 1 === count($authoritativeContent) && '' !== $primaryOwner && isset($ownersByPath[$path][$authoritativeContent[0]][$primaryOwner]) ) {
                $primary = $authoritativeContent[0];
                $best = $primary;
                foreach ( $ownersByPath[$path] as $content => $owners ) {
                    if ( count($owners) > count($ownersByPath[$path][$best]) ) {
                        $best = (string) $content;
                    }
                }
                if ( $best !== $primary ) {
                    $authoritativeContent = array( $best );
                    $rebased = array();
                    foreach ( array_keys($ownersByPath[$path][$primary]) as $owner ) {
                        $rebased[$owner][] = $primary;
                    }
                    foreach ( $pages as $owner => $contents ) {
                        foreach ( $contents as $content ) {
                            if ( $content !== $best ) {
                                $rebased[$owner][] = $content;
                            }
                        }
                    }
                    $pages = $rebased;
                }
            }
            if ( array() === $authoritativeContent ) {
                // Without an entry projection the last page's projection is
                // authoritative, as the site-wide copy every page loads.
                if ( array() !== $pages ) {
                    $lastOwner = array_key_last($pages);
                    $authoritativeContent[] = (string) array_pop($pages[$lastOwner]);
                    if ( array() === $pages[$lastOwner] ) {
                        unset($pages[$lastOwner]);
                    }
                } else {
                    $authoritativeContent[] = (string) ($file['content'] ?? '');
                }
            }
            $preambles = array();
            $stylesheets = array();
            foreach ( $authoritativeContent as $stylesheet ) {
                $split = $transformer->splitLeadingAtRulePreamble($stylesheet);
                $imports = '';
                foreach ($split['statements'] as $statement) {
                    if (preg_match('/^\s*(?:\/\*.*?\*\/\s*)*@import\b/is', $statement)) $imports .= $statement;
                    else $preambles[] = $statement;
                }
                if ( '' !== trim($imports . $split['stylesheet']) ) {
                    $stylesheets[] = $imports . $split['stylesheet'];
                }
            }
            $sharedStylesheets = array_merge(array_map('strval', array_keys($sharedByPath[$path] ?? array())), $stylesheets);
            // Shared-shell projections are part of every route's stylesheet.
            // Align the full route projection, never a trailing cascade delta.
            $native = array();
            foreach (array_merge(array_keys($sharedByPath[$path] ?? array()), $authoritativeContent) as $content) {
                $native += $nativeByContent[$path][$content] ?? array();
            }
            $originalPages = $pages;
            $pageProjections = array();
            foreach ($ownersByPath[$path] ?? array() as $content => $owners) {
                foreach ($owners as $owner => $_) $pageProjections[$owner][] = (string) $content;
            }
            foreach ($pageProjections as $owner => &$contents) {
                $contents = array($transformer->concatenateWithoutRedundantRules(array_merge(array_map('strval', array_keys($sharedByPath[$path] ?? array())), $memberShellsByPath[$path][$owner] ?? array(), $contents)));
            }
            unset($contents);
            $sharedStylesheets = array($transformer->concatenateWithoutRedundantRules($sharedStylesheets));
            $fallbackSharedStylesheets = $sharedStylesheets;
            foreach ($pages as $owner => $_) $pages[$owner] = $pageProjections[$owner] ?? $pages[$owner];
            $timer?->start('compose.author_stylesheet_projections.route_variants');
            $routed = $transformer->scopeRouteVariants($sharedStylesheets, $pageProjections, $routeIdentities, preg_match('/@namespace\b/i', implode('', $preambles)) ? '@namespace' : null, $pageMarkup, $routeMatchIndex, $this->routeClasses, $sharedMarkup, function (array $classes): string {
                return \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentRootContext::routeSetClass($classes, $this->routeSets);
            });
            // Recompact only at serialization, merging compatible marker arms.
            $recompact = static function (string $css) use ($timer, $pageProjections): string {
                $compact = static fn(): string => count($pageProjections) > 1 ? CssSelectorListCompactor::stylesheet($css, true) : $css;
                return null === $timer ? $compact() : $timer->measure('compose.compaction', $compact);
            };
            $timer?->start('compose.author_stylesheet_projections.route_budget');
            // A cached shared download replaces separate page-copy downloads.
            // Both costs are measured before compaction.
            $largestCopy = $totalCopyBytes = 0;
            if (0 < $routed['route_bytes']) {
                foreach ($routed['scoped'] as $owner) {
                    $copyBytes = strlen($transformer->rulesAbsentFrom($pageProjections[$owner] ?? array(), $fallbackSharedStylesheets, true, $routeMatchIndex, (string) $owner));
                    $largestCopy = max($largestCopy, $copyBytes);
                    $totalCopyBytes += $copyBytes;
                }
            }
            $routeBudget = max(self::ROUTE_SCOPE_BYTES_FACTOR * $largestCopy, intdiv($totalCopyBytes, self::ROUTE_SCOPE_COPY_TOTAL_DIVISOR), self::ROUTE_SCOPE_BYTES_FLOOR);
            if (array() !== $routed['scoped'] && $routed['route_bytes'] > $routeBudget) {
                $projectionDiagnostics[] = ArtifactDiagnostics::make('author_stylesheet_route_scope_fallback', 'warning', sprintf('%s: route-scoped variants add %d cached-sheet bytes, over budget %d = max(%dx largest copy %d, total copies %d / %d, floor %d); emitting page copies', $path, $routed['route_bytes'], $routeBudget, self::ROUTE_SCOPE_BYTES_FACTOR, $largestCopy, $totalCopyBytes, self::ROUTE_SCOPE_COPY_TOTAL_DIVISOR, self::ROUTE_SCOPE_BYTES_FLOOR));
                foreach ($routed['scoped'] as $owner) $pages[$owner] ??= $pageProjections[$owner] ?? array();
                $routed['stylesheet'] = implode("\n", $fallbackSharedStylesheets);
            } else {
                foreach ($routed['scoped'] as $owner) unset($pages[$owner]);
            }
            foreach ($routed['reasons'] as $owner => $reason) {
                $projectionDiagnostics[] = ArtifactDiagnostics::make('author_stylesheet_route_scope_fallback', 'warning', $path . ' (' . $owner . '): ' . $reason);
            }
            $timer?->start('compose.serialization');
            $projectedFile = $this->projectedStylesheetFile($file, $path, implode("\n", array_merge($preambles, array( $recompact($transformer->concatenateWithoutRedundantRules(array($routed['stylesheet']))) ))));
            $projectedFile['metadata']['native_selectors'] = array_keys($native);
            $output[] = $projectedFile;
            foreach ( $pages as $owner => $pageStylesheets ) {
                $delta = $recompact($transformer->rulesAbsentFrom($pageStylesheets, $fallbackSharedStylesheets, true, $routeMatchIndex, (string) $owner));
                if ( '' === trim($delta) ) {
                    continue;
                }
                $pageFile = $this->projectedStylesheetFile($file, $this->pageStylesheetPath($path, (string) $owner, $reserved), implode('', $preambles) . $delta);
                // A delta borrows the parent's bytes contract, not its other
                // routes' occurrences. Keep the shared file's inventory intact.
                foreach (array('inline_references', 'references', 'stylesheet_instances') as $key) {
                    if (isset($pageFile[$key])) {
                        $pageFile[$key] = array_values(array_filter($pageFile[$key], static fn(array $reference): bool => ($reference['source_path'] ?? null) === (string) $owner));
                    }
                }
                $pageFile['metadata']['compilation'] = array( 'scope' => 'page', 'id' => (string) $owner );
                $pageFile['metadata']['page_stylesheet_of'] = $path;
                $pageNative = $native;
                foreach ($originalPages[$owner] ?? array() as $content) {
                    $pageNative += $nativeByContent[$path][$content] ?? array();
                }
                $pageFile['metadata']['native_selectors'] = array_keys($pageNative);
                $output[] = $pageFile;
            }
        }
        $timer?->start('compose.artifact_assembly');
        return $output;
    }

    /**
     * Carry a projected stylesheet under a path. Projection rewrites the CSS
     * text, so any base64 twin from the source payload is stale and dropped.
     *
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    private function projectedStylesheetFile(array $file, string $path, string $content): array
    {
        $file['provenance']['projected_from_bytes'] ??= (int) ($file['bytes'] ?? strlen((string) ($file['content'] ?? '')));
        $file['path'] = $path;
        $file = ArtifactNormalizer::withTextPayload($file, $content);
        $file['provenance']['projected_from_hash'] = $file['provenance']['hash'] ?? '';
        $file['provenance']['hash'] = hash('sha256', $content);
        return $file;
    }

    /** @param array<string, true> $reserved */
    private function pageStylesheetPath(string $path, string $owner, array &$reserved): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $base = '' === $extension ? $path : substr($path, 0, -strlen($extension) - 1);
        $candidate = $base . '.page-' . substr(hash('sha256', $owner), 0, 12) . ('' === $extension ? '' : '.' . $extension);
        for ( $suffix = 2; isset($reserved[$candidate]); ++$suffix ) {
            $candidate = $base . '.page-' . substr(hash('sha256', $owner), 0, 12) . '-' . $suffix . ('' === $extension ? '' : '.' . $extension);
        }
        $reserved[$candidate] = true;
        return $candidate;
    }

    /**
     * Drop stylesheet arms that require a class or id no other site file names
     * (see {@see UnreachableSelectorPruner}). Each stylesheet is pruned against
     * the whole site, so route copies of one sheet stay identical.
     *
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function withoutUnreachableSelectors(array $files): array
    {
        $texts = array();
        $documents = 0;
        foreach ($files as $file) {
            if ('css' === ($file['kind'] ?? '')) continue;
            if ('html' === ($file['kind'] ?? '')) ++$documents;
            // Metadata carries captured interaction states.
            if (is_array($file['metadata'] ?? null) && array() !== $file['metadata']) $texts[] = (string) json_encode($file['metadata']);
            $text = $file['content'] ?? (in_array($file['kind'] ?? '', array('html', 'js', 'json', 'svg', 'text'), true) && is_string($file['content_base64'] ?? null) ? base64_decode($file['content_base64'], true) : null);
            if (is_string($text) && '' !== $text) $texts[] = $text;
        }
        if (0 === $documents) return $files;
        $pruner = new UnreachableSelectorPruner($texts);
        foreach ($files as &$file) {
            // Only the projected sheets the pages load; other CSS is inventory.
            if ('css' !== ($file['kind'] ?? '') || !isset($file['provenance']['projected_from_hash']) || !is_string($file['content'] ?? null)) continue;
            $content = $pruner->prune($file['content']);
            if ($content !== $file['content']) $file = $this->projectedStylesheetFile($file, $file['path'], $content);
        }
        unset($file);
        return $files;
    }

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
            $loader = ArtifactNormalizer::withTextPayload($file, implode('', array_map(static fn (string $path): string => '@import url("' . basename($path) . '");', $paths)));
            $loader['provenance']['hash'] = hash('sha256', $loader['content']);
            $output[] = $loader;
            $continuationPreamble = $chunker->continuationPreamble($file['content']);
            foreach ($chunks as $index => $content) {
                $chunk = $file;
                $chunk['path'] = $paths[$index];
                $chunk['content'] = 0 === $index ? $content : $continuationPreamble . $content;
                // Continuations inherit activation for inference, but are loaded
                // by the parent CSS import, not by the parent's document link.
                unset($chunk['content_base64'], $chunk['references']);
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
     * Compile non-entry HTML documents once so their stylesheet projections are
     * available before theme assets are materialized.
     *
     * @param array<int, array<string, mixed>> $files
     * @return array<string, array<string, mixed>>
     */
    private function compileHtmlSourceDocuments(array $files, string $entryPath, string $generatedBlockNamespace = '', array $runtimeDeclarations = array(), array &$omittedPages = array()): array
    {
        $documents = array();
        foreach ( $files as $file ) {
            if ( 'html' !== ($file['kind'] ?? '') || HtmlFragmentIncludes::isTemplatePartFile($file) ) {
                continue;
            }
            $path = (string) ($file['path'] ?? '');
            if ( '' === $path || $entryPath === $path ) {
                continue;
            }
            // Stylesheet occurrence records are per-document: a stylesheet this
            // page links but the entry page does not has no record in the
            // entry-annotated file set and would be dropped from its cascade.
            // Staged compilation annotates each page from its own source; the
            // whole-artifact driver does the same so both see one conversion.
            $documentFiles = $files;
            $this->indexFiles($documentFiles);
            try {
                $documents[$path] = $this->compileHtmlDocumentBlocks((string) ($file['content'] ?? ''), $path, $documentFiles, 'artifact-document', $generatedBlockNamespace, true, $runtimeDeclarations);
            } catch ( \InvalidArgumentException $error ) {
                $omittedPages[$path] = self::omittedPageOrRethrow($path, $error);
            }
        }
        $this->indexFiles($files);
        return $documents;
    }

    /** @param array<int,array<string,mixed>> $runtimeDeclarations @return array<int,array<string,mixed>> */
    private function themePreferenceOwnershipForSource(array $runtimeDeclarations, string $sourcePath): array
    {
        foreach ($runtimeDeclarations as $declaration) {
            if (ThemePreferenceOwnership::DECLARATION_KIND !== ($declaration['kind'] ?? null)
                || ThemePreferenceOwnership::DECLARATION_TYPE !== ($declaration['type'] ?? null)
                || $sourcePath !== ($declaration['source_path'] ?? null)) continue;
            $ownership = $declaration['payload']['ownership'] ?? null;
            if (!is_array($ownership) || $sourcePath !== ($ownership['source_path'] ?? null)) return array();
            return array($ownership);
        }
        return array();
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
    private function themeStaticCss(array $files): string
    {
		$cacheKey = 'authored';
		if ( array_key_exists($cacheKey, $this->themeStaticCssCache) ) {
			return $this->themeStaticCssCache[$cacheKey];
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
     * Legacy `<body bgcolor>` hints, scoped to their route. They rank beneath
     * every author rule, so the zero-specificity route scope adds only the
     * `body` type selector and the asset loads before authored stylesheets.
     */
    private function documentPresentationalHintAsset(array $files): ?array
    {
        $rules = array();
        foreach ($files as $file) {
            if ('html' !== ($file['kind'] ?? '')) continue;
            $declarations = \Automattic\BlocksEngine\PhpTransformer\Support\BodyPresentationalHints::declarations((string) ($file['content'] ?? ''));
            if ('' === $declarations) continue;
            $source = (string) $file['path'];
            $identity = \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan::identity('page', $source, $source);
            $class = $this->routeClasses[$identity] ?? \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentRootContext::routeClass($identity);
            $rules[] = ':where(html.' . $class . ') body{' . $declarations . '}';
        }
        if (array() === $rules) return null;
        $content = implode("\n", $rules) . "\n";
        $hash = hash('sha256', $content);
        $path = 'assets/css/document-presentational-hints-' . substr($hash, 0, 16) . '.css';
        return array('source' => 'engine-support', 'path' => $path, 'target_path' => $path, 'kind' => 'css', 'role' => 'stylesheet', 'stylesheet_placement' => 'before-author', 'stylesheet_target' => 'frontend', 'mime_type' => 'text/css', 'media_type' => 'text/css', 'bytes' => strlen($content), 'encoding' => 'utf-8', 'binary' => false, 'content' => $content, 'hash' => $hash, 'source_hash' => $hash);
    }

    /** Front-end-only document mechanics; never part of the editor carrier. */
    private function documentInlineStyleAsset(array $files): ?array
    {
        $documents = array();
        foreach ($files as $file) {
            if ('html' !== ($file['kind'] ?? '')) continue;
            foreach (array('html', 'body') as $tag) {
                $style = \Automattic\BlocksEngine\PhpTransformer\Support\InlineBodyStyle::declarations((string) ($file['content'] ?? ''), $tag);
                if ('' !== $style) $documents[(string) $file['path']][$tag] = $style;
            }
        }
        if (array() === $documents) return null;
        // One ID more than the heaviest author selector outranks every rule,
        // as inline style does. The matching class arm of :is() supplies the
        // ID weight without an authored ID, like InlineGeometry's carriers.
        $ids = 0;
        $transformer = new CssStylesheetTransformer();
        foreach ($files as $file) {
            if ('css' !== ($file['kind'] ?? '')) continue;
            $transformer->visitStyleRules((string) ($file['content'] ?? ''), static function (string $prelude) use (&$ids): void {
                foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
                    $weight = \Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher::specificityCounts(\Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher::parse($selector));
                    $ids = max($ids, $weight['ids']);
                }
            });
        }
        $rules = array();
        foreach ($documents as $source => $styles) {
            $identity = \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan::identity('page', $source, $source);
            $class = $this->routeClasses[$identity] ?? \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentRootContext::routeClass($identity);
            $root = 'html.' . $class . ':is(.' . $class . ',' . str_repeat('#' . $class, $ids + 1) . ')';
            foreach ($styles as $tag => $style) {
                $rules[] = $root . ('body' === $tag ? ' body' : '') . '{' . $style . '}';
            }
        }
        if (array() === $rules) return null;
        $content = implode("\n", $rules) . "\n";
        $hash = hash('sha256', $content);
        $path = 'assets/css/document-inline-' . substr($hash, 0, 16) . '.css';
        return array('source' => 'engine-support', 'path' => $path, 'target_path' => $path, 'kind' => 'css', 'role' => 'stylesheet', 'stylesheet_placement' => 'after-author', 'stylesheet_target' => 'frontend', 'mime_type' => 'text/css', 'media_type' => 'text/css', 'bytes' => strlen($content), 'encoding' => 'utf-8', 'binary' => false, 'content' => $content, 'hash' => $hash, 'source_hash' => $hash);
    }

    /**
     * Linked stylesheets are projected after HtmlTransformer has emitted its
     * per-document support assets, so scan their final runtime selectors here.
     *
     * @param array<int, array<string, mixed>> $files
     * @return array<string, mixed>|null
     */
    private function projectedViewportChromeAsset(array $files): ?array
    {
        $css = array();
        $accommodation = new AdminBarAccommodation();
        $stickyContainment = new TemplatePartStickyContainment();
        foreach ($files as $file) {
            if ('css' !== ($file['kind'] ?? '') || !is_string($file['content'] ?? null)) {
                continue;
            }
            foreach (array($accommodation->supportCss($file['content']), $stickyContainment->supportCss($file['content'])) as $supportCss) {
                if ('' !== $supportCss) {
                    $css[] = $supportCss;
                }
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

    private function hasHtmlAttribute(string $tag, string $name): bool
    {
        return array_key_exists(strtolower($name), HtmlTagScanner::attributes($tag));
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array{internal_links: array<int, array<string, mixed>>, asset_references: array<int, array<string, mixed>>, image_references: array<int, array<string, mixed>>}
     */
    private function referenceReports(array $files, string $entryPath): array
    {
        return ( new ReferenceAnalyzer() )->referenceReports(
            $files,
            fn (array $file): bool => ArtifactFileIndex::isLinkableDocument($file),
            fn (array $asset): bool => $this->isSafeImageAsset($asset),
            '.' === dirname($entryPath) ? '' : dirname($entryPath)
        );
    }

    /**
     * Keep the members that load every landmark stylesheet most members load.
     * A landmark stylesheet is a style source that names one of the landmark's
     * ids; route-only stylesheets still drop out of the shell head.
     *
     * @param array<string,array{content:string,rows:array<string,mixed>}> $members
     * @param array<int,array<string,mixed>> $files
     * @return array<string,array{content:string,rows:array<string,mixed>}>
     */
    private function membersSharingLandmarkStyles(array $members, string $area, int|string $variant, array $files, string $entryPath): array
    {
        $counts = array();
        $loaded = array();
        foreach ($members as $path => $document) {
            preg_match_all('/\bid="([^"]+)"/', (string) $document['rows'][$area][$variant]['markup'], $idMatches);
            $head = preg_match('/<head\b[^>]*>(.*?)<\/head>/is', $document['content'], $headMatch) ? $headMatch[1] : '';
            preg_match_all('/<(?:link\b[^>]*|style\b[^>]*>.*?<\/style)\s*>/is', $head, $styleMatches);
            $loaded[$path] = array();
            foreach (array_unique($styleMatches[0]) as $style) {
                $href = preg_match('/^<link\b[^>]*\bhref="([^"]+)"/i', $style, $hrefMatch) ? (string) strtok(html_entity_decode($hrefMatch[1]), '?#') : null;
                // Root-relative hrefs resolve from the site root, the entry's directory.
                $css = null === $href ? $style : (string) ($this->fileIndex->findAssetByHtmlReference($href, str_starts_with($href, '/') ? $entryPath : (string) $path, $files)['content'] ?? '');
                foreach (array_unique($idMatches[1]) as $id) {
                    if (str_contains($css, $id)) { $loaded[$path][$style] = true; $counts[$style] = ($counts[$style] ?? 0) + 1; break; }
                }
            }
        }
        $shared = array_keys(array_filter($counts, static fn(int $count): bool => 2 * $count > count($members)));
        return array_filter($members, static fn(string $path): bool => array() === array_diff_key(array_flip($shared), $loaded[$path]), ARRAY_FILTER_USE_KEY);
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
            if ('html' !== ($file['kind'] ?? null) || HtmlFragmentIncludes::isTemplatePartFile($file) || !is_string($file['content'] ?? null) || '' === trim($file['content'])) continue;
            $dom = HtmlSerialization::parseDocument((string) $file['content']);
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
                        if (is_string($markup) && '' !== $markup) $rows[$area][] = array('markup' => $markup, 'identity' => SourceShells::identity($markup), 'context' => SourceShells::ancestorContext($child));
                    }
                    $walk($child, $content);
                }
            };
            $walk($body);
            foreach ($rows as &$areaRows) foreach ($areaRows as &$row) {
                $row['source_identity'] = 'markup:' . hash('sha256', \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ChromeStackingIdentity::normalize($row['identity']));
                // Include provenance describes transport, not a different shell.
                // An expanded include and identical inline markup must share the
                // same compilation group and its canonical support assets.
            }
            unset($areaRows, $row);
            $documents[(string) $file['path']] = array('content' => (string) $file['content'], 'rows' => $rows);
        }
        if (count($documents) < 2) return array('artifacts' => array(), 'assets' => array(), 'author_stylesheet_projections' => array(), 'runtime_script_projections' => array());

        $artifacts = array();
        $assets = array();
        $generatedBlocks = array();
        $authorStylesheetProjections = array();
        $runtimeScriptProjections = array();
        foreach (array('header', 'footer') as $area) {
            $groups = array();
            foreach ($documents as $path => $document) {
                foreach ($document['rows'][$area] as $variant => $row) {
                    $groups[$variant][$row['source_identity']][$path] = $document;
                }
            }
            foreach ($groups as $variant => $variantGroups) foreach ($variantGroups as $sourceIdentity => $members) {
                // A member whose landmark styles come from another stylesheet
                // renders it under other rules. It keeps its own copy, so the
                // stylesheets most members load still shape the shared shell.
                $members = $this->membersSharingLandmarkStyles($members, $area, $variant, $files, $entryPath);
                if (count($members) < 2) continue;
                $sourcePath = isset($members[$entryPath]) ? $entryPath : (string) array_key_first($members);
                // Identity excludes capture-only entrance state, but the emitted
                // part must retain it. Prefer a member that observed entrances,
                // even when the entry route was too short to trigger them.
                $canonicalSource = $members[$sourcePath]['rows'][$area][$variant]['markup'];
                foreach ($members as $member) {
                    $candidate = $member['rows'][$area][$variant]['markup'];
                    if (SourceShells::evidenceCount($candidate) > SourceShells::evidenceCount($canonicalSource)) $canonicalSource = $candidate;
                }
                $canonicalMarkup = SourceShells::identity($canonicalSource, false);
                $head = preg_match('/<head\b[^>]*>(.*?)<\/head>/is', $members[$sourcePath]['content'], $headMatch) ? $headMatch[1] : '';
                // A route-only stylesheet must not seed the shared part, even
                // when that route is the entrypoint. Only style sources common
                // to this source group shape the shell; carried CSS stays local.
                $head = preg_replace_callback('/<(?:link\b[^>]*|style\b[^>]*>.*?<\/style)\s*>/is', static function (array $match) use ($members): string {
                    foreach ($members as $document) {
                        if (!str_contains($document['content'], $match[0])) return '';
                    }
                    return $match[0];
                }, $head) ?? $head;
                // The landmark keeps the ancestor context its members share, so
                // author rules scoped by an ancestor (`#app .button`, inherited
                // custom properties) resolve as they do on every member page.
                // Member-specific ancestor attributes are dropped like route-only
                // stylesheets, keeping the shell canonical rather than page-seeded.
                $context = SourceShells::sharedAncestorContext(array_map(static fn(array $member): array => $member['rows'][$area][$variant]['context'], $members));
                $open = ''; $close = '';
                foreach (array_slice($context, 2) as $ancestor) { $open .= '<' . $ancestor['tag'] . SourceShells::attributes($ancestor['attributes']) . '>'; $close = '</' . $ancestor['tag'] . '>' . $close; }
                $synthetic = '<!doctype html><html' . SourceShells::attributes($context[0]['attributes'] ?? array()) . '><head>' . $head . '</head><body' . SourceShells::attributes($context[1]['attributes'] ?? array()) . '>' . $open . $canonicalMarkup . $close . '</body></html>';
                $compiled = $this->compileHtmlDocumentBlocks($synthetic, $sourcePath, $files, 'artifact-shared-shell', $generatedBlockNamespace, true, array(), max(0, count($context) - 2));
                $generatedBlocks = array_merge($generatedBlocks, $compiled['generated_blocks'] ?? array());
                $shell = current(array_filter($compiled['shell_artifacts'], static fn(array $candidate): bool => $area === ($candidate['area'] ?? null)));
                if (!is_array($shell)) continue;
                $slug = 1 === count($groups) ? $area : $area . '-' . ($variant + 1);
                if (count($variantGroups) > 1) $slug .= '-' . substr(hash('sha256', $sourceIdentity), 0, 12);
                $artifacts[] = array_merge($shell, array(
                    'slug' => $slug,
                    'source_path' => $sourcePath . '#' . $slug,
                    'source_paths' => array_keys($members),
                    'source_hash' => hash('sha256', $canonicalMarkup),
                    'variant' => $variant + 1,
                    'placement' => array('kind' => 'inline_shared_shell', 'source_path' => 'wordpress-site-plan/shared/' . $slug, 'source_paths' => array_keys($members), 'variant' => $variant + 1, 'equal_source_identity' => true),
                ));
                foreach ($compiled['assets'] as $asset) {
                    if (!is_array($asset)) continue;
                    $asset['compilation'] = array('scope' => 'shared', 'source_paths' => array_keys($members));
                    $assets[] = $asset;
                }
                // Keep site-wide reconciliation (including richtext reset
                // exclusions) unless the synthetic ancestors carry state that
                // is not common to every route. Other landmark identities still
                // count: sharing markup is not evidence of sharing route state.
                $siteContexts = array();
                foreach ($documents as $document) {
                    $siteContexts[] = $document['rows'][$area][$variant]['context'] ?? array();
                }
                $siteContext = SourceShells::sharedAncestorContext($siteContexts);
                $contextMatches = null;
                if ($context !== $siteContext) {
                    $siteOpen = ''; $siteClose = '';
                    foreach (array_slice($siteContext, 2) as $ancestor) { $siteOpen .= '<' . $ancestor['tag'] . SourceShells::attributes($ancestor['attributes']) . '>'; $siteClose = '</' . $ancestor['tag'] . '>' . $siteClose; }
                    $siteSynthetic = '<!doctype html><html' . SourceShells::attributes($siteContext[0]['attributes'] ?? array()) . '><head>' . $head . '</head><body' . SourceShells::attributes($siteContext[1]['attributes'] ?? array()) . '>' . $siteOpen . $canonicalMarkup . $siteClose . '</body></html>';
                    $contextMatches = new \Automattic\BlocksEngine\PhpTransformer\Css\RouteSelectorMatchIndex(array(
                        'member' => HtmlSerialization::parseDocument($synthetic),
                        'site' => HtmlSerialization::parseDocument($siteSynthetic),
                    ));
                }
                foreach ($compiled['author_stylesheet_projections'] as $projection) {
                    // Only scope stylesheets whose projection actually depends
                    // on the additional member context, not unrelated resets.
                    if (null !== $contextMatches && $this->sharedShellStylesheetDependsOnContext($projection, $synthetic, $sourcePath, $files, $contextMatches)) $projection['member_owners'] = array_keys($members);
                    $authorStylesheetProjections[] = $projection;
                }
                $runtimeScriptProjections = array_merge($runtimeScriptProjections, $compiled['runtime_script_projections']);
            }
        }
        return array(
            'artifacts' => $artifacts,
            'assets' => $assets,
            'generated_blocks' => $generatedBlocks,
            'author_stylesheet_projections' => $authorStylesheetProjections,
            'runtime_script_projections' => $runtimeScriptProjections,
        );
    }

    /**
     * Compare rule reachability in the two source contexts, not a second block
     * compilation. Visit the original stylesheet (including imports): projected
     * selectors may already have replaced the ancestor with a generated marker.
     * The existing selector index handles functional selectors and interaction
     * states; no declarations or cascade are resolved here.
     */
    private function sharedShellStylesheetDependsOnContext(array $projection, string $html, string $sourcePath, array $files, \Automattic\BlocksEngine\PhpTransformer\Css\RouteSelectorMatchIndex $matches): bool
    {
        $depends = false;
        $transformer = new CssStylesheetTransformer();
        $assets = $this->authorStylesheetAssetsWithImports($this->stylesheetAssetsForSource($html, $sourcePath, $files, true), $files);
        foreach ($assets as $asset) {
            if (($asset['path'] ?? null) !== $projection['path']) continue;
            $transformer->visitStyleRules($asset['content'], static function (string $prelude) use ($matches, &$depends): void {
                foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
                    $member = $matches->matchedElements($selector, 'member');
                    $site = $matches->matchedElements($selector, 'site');
                    // An arm the index cannot evaluate cannot disprove context dependence.
                    if (null === $member || null === $site || count($member) !== count($site)) $depends = true;
                }
            });
        }
        return $depends;
    }

    /**
     * @param array{files: array<int, array<string, mixed>>, bytes: int, source_hash: string} $artifact
     * @param array<int, array<string, mixed>> $documents
     * @param array<int, array<string, mixed>> $assets
     * @param array<int, array<string, mixed>> $blockTypes
     * @return array<string, mixed>
     */
    private function compiledSiteReport(array $artifact, string $entryPath, array $documents, array &$assets, array $blockTypes, string $serializedBlocks, array $entryShellArtifacts = array(), array $compiledHtmlDocuments = array(), array $inlineShellArtifacts = array(), array $entryDocumentRootMarkers = array(), array $entryDesignUsage = array()): array
    {
        $pages = array();
        $assetPayloadsByPath = array();
        foreach ( $assets as $asset ) {
            $path = (string) ($asset['path'] ?? '');
            $payload = is_string($asset['visual_payload'] ?? null) ? $asset['visual_payload'] : (is_string($asset['content_base64'] ?? null) ? $asset['content_base64'] : (string) ($asset['content'] ?? ''));
            $assetPayloadsByPath[$path][hash('sha256', $payload)] = true;
        }
        $titleCounts = array();
        foreach ( $artifact['files'] as $file ) {
            if ( 'html' !== ($file['kind'] ?? '') || HtmlFragmentIncludes::isTemplatePartFile($file) ) {
                continue;
            }
            $declaration = HtmlTagScanner::scan((string) ($file['content'] ?? ''), 'title')[0] ?? null;
            if ( null !== $declaration ) {
                $key = trim(html_entity_decode(strip_tags($declaration['content']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $titleCounts[$key] = ($titleCounts[$key] ?? 0) + 1;
            }
        }
        $sharedTitle = $this->titles->sharedDocumentTitleSegment($artifact['files']);
        $siteNameSegments = $sharedTitle['segments'];
        $siteNameEdge = $sharedTitle['edge'];
        $entryNavigationLabel = $this->titles->entryNavigationLabel($artifact['files'], $siteNameSegments);
        $entryTitle = '';
        $entryDocumentTitle = '';
        foreach ( $artifact['files'] as $file ) {
            if ( $entryPath === ($file['path'] ?? '') ) {
                $entryHtml = (string) ($file['content'] ?? '');
                $entryTitle = $this->titles->titleFromHtml($entryHtml, $entryPath, $entryPath, '', $siteNameSegments, $siteNameEdge, $entryNavigationLabel, '', $titleCounts, $artifact['files'], $file['metadata']['declared_content']['title'] ?? null);
                $entryTitles = HtmlTagScanner::scan($entryHtml, 'title');
                if ( isset($entryTitles[0]) ) {
                    $entryDocumentTitle = $entryTitles[0]['content'];
                }
                break;
            }
        }
        foreach ( $artifact['files'] as $file ) {
            if ( 'html' !== ($file['kind'] ?? '') || HtmlFragmentIncludes::isTemplatePartFile($file) ) {
                continue;
            }

            $path = (string) ($file['path'] ?? '');
            $title = $this->titles->titleFromHtml((string) ($file['content'] ?? ''), $path, $entryPath, $entryTitle, $siteNameSegments, $siteNameEdge, $entryNavigationLabel, $entryDocumentTitle, $titleCounts, $artifact['files'], $file['metadata']['declared_content']['title'] ?? null);
            $slug = ArtifactPath::slug($path, $entryPath);
            $content = (string) ($file['content'] ?? '');
            $compiledBlocks = $path === $entryPath
                ? array('serialized_blocks' => $serializedBlocks, 'assets' => array(), 'shell_artifacts' => $entryShellArtifacts, 'document_root_markers' => $entryDocumentRootMarkers, 'design_usage' => $entryDesignUsage)
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
                    'metadata'       => array_merge($this->documentMetadata($path, 'html', (string) ($file['role'] ?? 'document'), $slug, $title, $bodyFormat), is_string($file['metadata']['route_path'] ?? null) ? array('route_path' => $file['metadata']['route_path']) : array(), is_string($file['metadata']['post_type'] ?? null) ? array('post_type' => $file['metadata']['post_type'], 'post_type_declaration' => 'metadata:post_type') : array(), is_array($file['metadata']['template_surface'] ?? null) ? array('template_surface' => $file['metadata']['template_surface']) : array(), is_array($file['metadata']['structured_data'] ?? null) ? array('structured_data' => $file['metadata']['structured_data']) : array(), is_array($file['metadata']['declared_content'] ?? null) ? array('declared_content' => $file['metadata']['declared_content']) : array()),
                    'document_metadata' => $this->fullDocumentMetadata($content, $path, $artifact['files'], $path === $entryPath ? $assets : ($compiledBlocks['assets'] ?? array()), ArrayField::array($compiledBlocks, 'document_root_markers')),
                    'html'           => $file['content'] ?? '',
                    'body_format'    => $bodyFormat,
                    'block_markup'   => $blockMarkup,
                    'shell_artifacts' => ArrayField::array($compiledBlocks, 'shell_artifacts'),
                    'runtime_islands' => ArrayField::array($compiledBlocks, 'runtime_islands'),
                    'design_usage'   => ArrayField::array($compiledBlocks, 'design_usage'),
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
        if ( $this->isBlockMarkup($html) ) {
            return $html;
        }

        $result = ( new HtmlTransformer() )->transform($html, array(
            'source'       => 'html-document',
            'source_scope' => 'artifact-document',
        ))->toArray();

        return isset($result['serialized_blocks']) && is_scalar($result['serialized_blocks']) ? trim((string) $result['serialized_blocks']) : '';
    }

    /**
     * @param array<string, mixed> $file
     * @return array{color:string, undecodable:bool}
     */
    private function glyphColorForFile(array $file): array
    {
        // Normalized binary files already carry the raw-byte digest, including
        // payload references. Look it up before reading or decoding any bytes.
        $hash = (string) ($file['raw_sha256'] ?? $file['payload_reference']['sha256'] ?? '');
        if ( '' !== $hash && isset($this->glyphColorByHash[$hash]) ) {
            return $this->glyphColorByHash[$hash];
        }

        $bytes = $this->embeddedFileBytes($file);
        if ( '' === $bytes ) {
            return array('color' => '', 'undecodable' => false);
        }
        if ( '' === $hash ) {
            $hash = hash('sha256', $bytes);
        }
        if ( ! isset($this->glyphColorByHash[$hash]) ) {
            ++$this->glyphColorInspectionCount;
            $this->glyphColorByHash[$hash] = MonochromeGlyphColor::inspect($bytes);
        }

        return $this->glyphColorByHash[$hash];
    }

    /** @param array<string, mixed> $file */
    private function embeddedFileBytes(array $file): string
    {
        if ( is_string($file['content_base64'] ?? null) && '' !== $file['content_base64'] ) {
            $decoded = base64_decode($file['content_base64'], true);
            if ( is_string($decoded) && '' !== $decoded ) {
                return $decoded;
            }
        }

        $content = ArrayField::string($file, 'content');
        if ( '' !== $content ) {
            return $content;
        }

        $reference = $file['payload_reference'] ?? null;
        if ( ! is_array($reference) || null === $this->glyphPayloadReader ) {
            return '';
        }
        $declaredBytes = (int) ($reference['bytes'] ?? 0);
        if ( $declaredBytes < 1 || $declaredBytes > 65536 ) {
            return '';
        }

        try {
            $payload = $this->glyphPayloadReader->read($reference);
        } catch ( \Throwable ) {
            return '';
        }
        if ( strlen($payload) !== $declaredBytes ) {
            return '';
        }
        $sha = (string) ($reference['sha256'] ?? '');
        if ( '' !== $sha && ! hash_equals($sha, hash('sha256', $payload)) ) {
            return '';
        }

        return $payload;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<string, array<string, mixed>>
     */
    private function assetMetadataForSource(string $sourcePath, array $files, string $html): array
    {
        $metadata = array();
        $capturedGlyphPaths = $this->capturedGlyphAssetPaths($html, $sourcePath, $files);
        $candidates = $this->fileIndex->isIndexed() ? $this->fileIndex->imageFiles() : $files;
        foreach ( $candidates as $file ) {
            if ( ArtifactFileIndex::isMaterializedHtmlDocument($file) ) {
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
            // Capture already recorded the rendered colour on every reference.
            $glyph = ($capturedGlyphPaths[$path] ?? false) ? array('color' => '', 'undecodable' => false) : $this->glyphColorForFile($file);
            if ( '' !== $glyph['color'] ) {
                $asset['glyph_color'] = $glyph['color'];
            }
            if ( $glyph['undecodable'] ) {
                $asset['glyph_color_undecodable'] = true;
            }

            foreach ( $this->assetLookupKeysForSource($path, $sourcePath) as $key ) {
                $metadata[$key] = $asset;
            }
        }

        return $metadata;
    }

    /**
     * Image paths whose every `<img>` reference on the page carries a valid
     * captured `data-blocks-engine-capture-glyph-color`. Their glyph colour comes from that
     * evidence, so their bytes are not decoded.
     *
     * @param array<int, array<string, mixed>> $files
     * @return array<string, bool>
     */
    private function capturedGlyphAssetPaths(string $html, string $sourcePath, array $files): array
    {
        if ( ! str_contains($html, CaptureEvidence::GLYPH_COLOR) ) {
            return array();
        }

        $captured = array();
        foreach ( HtmlTagScanner::scan($html, 'img') as $image ) {
            $attributes = HtmlTagScanner::attributes($image['tag']);
            $asset = $this->fileIndex->findAssetByHtmlReference($attributes['src'] ?? '', $sourcePath, $files);
            $path = is_array($asset) ? (string) ($asset['path'] ?? '') : '';
            if ( '' === $path ) {
                continue;
            }
            $captured[$path] = ($captured[$path] ?? true)
                && '' !== CapturedGlyphColor::normalize($attributes[CaptureEvidence::GLYPH_COLOR] ?? '');
        }

        return $captured;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function runtimeScriptMetadataForSource(string $html, string $sourcePath, array $files): array
    {
        $metadata = array();
        foreach ( HtmlTagScanner::scan($html, 'script') as $index => $script ) {
            if (CaptureReplayScripts::isReplayScript($script['tag'])) continue;
            $tag = $script['tag'];
            $src = HtmlTagScanner::attribute((string) $tag, 'src');
            if ( '' === $src ) {
                continue;
            }

            $asset = $this->fileIndex->findAssetByHtmlReference($src, $sourcePath, $files);
            if ( ! is_array($asset) || ! ArtifactFileIndex::isMaterializedScriptAsset($asset) ) {
                continue;
            }

            $metadata[] = array_filter(array(
                'path'               => (string) ($asset['path'] ?? ''),
                'selector'           => 'script:nth-of-type(' . ($index + 1) . ')',
                'attributes'         => array_filter(array(
                    'src'   => $src,
                    'type'  => HtmlTagScanner::attribute((string) $tag, 'type'),
                    'async' => HtmlTagScanner::attribute((string) $tag, 'async'),
                    'defer' => HtmlTagScanner::attribute((string) $tag, 'defer'),
                ), static fn (string $value): bool => '' !== $value),
                'script_role'        => 'runtime',
                'script_source_kind' => 'external',
            ), static fn (mixed $value): bool => '' !== $value && array() !== $value);
        }

        return DeterministicRowDeduplicator::dedupe($metadata);
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array{path: string, content: string}>
     */
    private function runtimeProjectionScriptAssetsForSource(string $html, string $sourcePath, array $files): array
    {
        $assets = array();
        $scriptIndex = 0;
        foreach ( HtmlTagScanner::scan($html, 'script') as $script ) {
            ++$scriptIndex;
            if (CaptureReplayScripts::isReplayScript($script['tag'])) continue;
            $src = HtmlTagScanner::attribute($script['tag'], 'src');
            $asset = '' === $src
                ? $this->findInlineScriptAsset($sourcePath, $scriptIndex, $files)
                : $this->fileIndex->findAssetByHtmlReference($src, $sourcePath, $files);
            if ( ! is_array($asset) || ! ArtifactFileIndex::isMaterializedScriptAsset($asset) || ! is_string($asset['path'] ?? null) || ! is_string($asset['content'] ?? null) ) {
                continue;
            }
            $assets[] = array('path' => $asset['path'], 'content' => $asset['content']);
        }

        return DeterministicRowDeduplicator::dedupe($assets);
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
        $root = trim($this->generatedAssetRoot, '/');
        if ( '' !== $root && str_starts_with($assetPath, $root . '/') ) {
            $webRelative = substr($assetPath, strlen($root) + 1);
            if ( '' !== $webRelative ) {
                $keys[] = '/' . $webRelative;
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

    /**
     * @param array<int, array<string, mixed>> $files
     * @param array<int, string> $projectedRootMarkers Engine marker classes the
     * compilation installed on the analysis document root; the root registry
     * must materialize them or projected `:root:not(.marker)` predicates would
     * invert their source truth on every rendered route.
     */
    private function fullDocumentMetadata(string $html, string $sourcePath, array $files, array $generatedAssets = array(), array $projectedRootMarkers = array()): array
    {
        $reference = static fn(string $value): array => array('url' => $value);
        $attributes = function (string $tag, array $names): array {
            $values = array();
            foreach ($names as $name) {
                if (!$this->hasHtmlAttribute($tag, $name)) continue;
                $value = HtmlTagScanner::attribute($tag, $name);
                // HTML's empty and invalid-value CORS states both select anonymous.
                $values[in_array($name, array(CaptureEvidence::DEVICE_STYLE, CaptureEvidence::SOURCE_MEDIA), true) ? $name : str_replace('-', '_', $name)] = 'crossorigin' === $name && '' === $value ? 'anonymous' : $value;
            }
            return $values;
        };
        $inlineScripts = array();
        // The head projection and loading metadata must resolve the same
        // source-owned occurrence, including scripts already materialized by
        // artifact normalization (not only scripts generated during block
        // conversion).
        foreach (ArtifactNormalizer::inlineOccurrences(array_merge($files, $generatedAssets)) as $asset) if ('inline-script' === ($asset['source'] ?? null) && $sourcePath === ($asset['source_path'] ?? null) && is_string($asset['selector'] ?? null) && is_string($asset['path'] ?? null)) $inlineScripts[$asset['selector']] = $asset['path'];
        $meta = array(); $links = array(); $scripts = array();
        foreach (HtmlTagScanner::scan($html, 'meta') as $declaration) {
            $tag = $declaration['tag'];
            $row = $attributes($tag, array('charset', 'name', 'property', 'http-equiv', 'content'));
            if (array() !== $row) { $row = array_merge(array('order' => count($meta), 'placement' => $declaration['placement']), $row); $meta[] = $row; }
        }
        foreach (HtmlTagScanner::scan($html, 'link') as $link) {
            $tag = $link['tag']; $href = HtmlTagScanner::attribute($tag, 'href');
            if ('' === $href) continue;
            $links[] = array_merge(array('order' => count($links), 'placement' => $link['placement']), $attributes($tag, array('rel', 'type', 'media', 'integrity', 'crossorigin', 'referrerpolicy', 'as', 'fetchpriority', 'sizes', CaptureEvidence::DEVICE_STYLE, CaptureEvidence::SOURCE_MEDIA)), $reference($href));
        }
        $scriptIndex = 0;
        foreach (HtmlTagScanner::scan($html, 'script') as $script) {
            $open = $script['tag']; $src = HtmlTagScanner::attribute($open, 'src');
            $selector = 'script:nth-of-type(' . (++$scriptIndex) . ')';
            // A static-site interpreter of the inert motion markers is replaced by
            // the view scripts of the blocks those markers lower to.
            if ($this->hasHtmlAttribute($open, 'data-blocks-engine-marker-runtime')) continue;
            // Capture replay interpreters are discarded at normalization (their
            // evidence lowers into blocks or the engine runtime), so they have no asset to load.
            if (CaptureReplayScripts::isReplayScript($open)) continue;
            $async = $this->hasHtmlAttribute($open, 'async'); $defer = $this->hasHtmlAttribute($open, 'defer'); $module = 'module' === strtolower(HtmlTagScanner::attribute($open, 'type'));
            $supersededBy = HtmlTagScanner::attribute($open, 'data-blocks-engine-superseded-by');
            $inlineBodyHash = hash('sha256', trim($script['content']));
            $inline = isset($inlineScripts[$selector]) ? $reference($inlineScripts[$selector]) : array('source_kind' => 'inline', 'body_hash' => $inlineBodyHash);
            if ( '' !== $supersededBy ) $inline = array_merge($inline, array('selector' => $selector, 'superseded_by' => $supersededBy, 'body_hash' => $inlineBodyHash));
            $scripts[] = array_merge(array('order' => count($scripts), 'placement' => $script['placement'], 'async' => $async, 'defer' => $defer, 'module' => $module, 'nomodule' => $this->hasHtmlAttribute($open, 'nomodule'), 'effective_loading' => $async ? 'async' : (($defer || $module) ? 'defer' : 'blocking')), $attributes($open, array('type', 'integrity', 'crossorigin', 'referrerpolicy', 'fetchpriority')), '' !== $src ? $reference($src) : $inline);
        }
        $titles = HtmlTagScanner::scan($html, 'title');
        $title = isset($titles[0]) ? trim(html_entity_decode(strip_tags($titles[0]['content']), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : $this->titles->titleFromHtml($html, $sourcePath);
        $routeIdentity = \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan::identity('page', $sourcePath, $sourcePath);
        $metadata = array('source_context' => array('source_path' => $sourcePath, 'kind' => 'html'), ...\Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentRootContext::metadataFromHtml($html, $routeIdentity, $this->routeClasses[$routeIdentity] ?? null), 'title' => $title, 'title_declaration' => array('order' => 0, 'placement' => 'head'), 'meta' => $meta, 'links' => $links, 'scripts' => $scripts);
        $metadata = \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentRootContext::withRouteSets($metadata, $this->routeSets);
        if ( array() !== $projectedRootMarkers ) {
            $rootClasses = array_values(array_filter(SourceDom::tokens((string) ($metadata['root_attributes']['class'] ?? '')), static fn(string $class): bool => '' !== $class));
            $metadata['root_attributes']['class'] = implode(' ', array_unique(array_merge($rootClasses, $projectedRootMarkers)));
        }
        $comments = \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\WordPressComments::entities($html, $sourcePath);
        if (array() !== $comments) $metadata['comment_entities'] = $comments;
        return $metadata;
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
            if ( ! HtmlFragmentIncludes::isTemplatePartFile($file) ) {
                continue;
            }

            // Resolved component chrome is bound by ShellExtraction from each
            // page's actual block tree, not emitted again as an unbound part.
            if (!empty($file['metadata']['compilation']['included_component'])) continue;

            $slug = ArtifactPath::slug($path);
            $area = ShellLandmarkPolicy::templatePartArea($path, (string) ($file['role'] ?? ''));
            $tagName = ShellLandmarkPolicy::templatePartTagName($path, (string) ($file['role'] ?? ''));
            $parts[] = array_filter(
                array(
                    'source_path'  => $path,
                    'slug'         => $slug,
                    'title'        => DocumentTitles::titleFromPath($path),
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
        $navigationCompatCss = implode("\n", array_column(array_filter($assets, static fn(array $asset): bool => 'wordpress-compat' === ($asset['source'] ?? '')), 'content'));
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
                    'stylesheet_activation' => $asset['stylesheet_activation'] ?? null,
                    'stylesheet_source_path' => $asset['stylesheet_source_path'] ?? null,
                    'stylesheet_target' => 'css' === ($asset['kind'] ?? '') ? ($asset['stylesheet_target'] ?? 'both') : '',
                    'intent'           => $asset['intent'] ?? '',
                    'media_type'       => $asset['media_type'] ?? $asset['mime_type'] ?? '',
                    'media'            => $asset['media'] ?? '',
                    'source_media'     => $asset['source_media'] ?? null,
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
                    'source_bytes'     => 'css' === ($asset['kind'] ?? null) ? ($asset['source_bytes'] ?? null) : null,
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
                    'stylesheet_link_position' => 'css' === ($asset['kind'] ?? null) && is_int($asset['stylesheet_link_position'] ?? null) ? $asset['stylesheet_link_position'] : null,
                    'inline_references' => $asset['inline_references'] ?? null,
                    'stylesheet_parent' => $asset['stylesheet_parent'] ?? null,
                    'stylesheet_inline_order' => $asset['stylesheet_inline_order'] ?? null,
                ),
                static fn (mixed $value, string $key): bool => (in_array($key, array('content', 'source_media'), true) && is_string($value)) || (null !== $value && '' !== $value),
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
     * @param array<int, array<string, mixed>> $findings
     * @return array<int, array<string, mixed>>
     */
    private function withoutInternalFindingMarkers(array $findings): array
    {
        return array_map(static function (mixed $finding): mixed {
            if ( ! is_array($finding) ) return $finding;
            unset($finding['_collection_binding_declined']);
            if ( is_array($finding['controls'] ?? null) ) {
                foreach ( $finding['controls'] as &$control ) {
                    if ( is_array($control) ) unset($control['_unresolved_description_candidates']);
                }
                unset($control);
            }
            return $finding;
        }, $findings);
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

        return 'html' === ($file['kind'] ?? '') || 'blocks' === ($file['kind'] ?? '') || $this->isBlockMarkup((string) ($file['content'] ?? ''));
    }

    /**
     * Serialized block markup starts with a block comment. An HTML document
     * can carry block comments in its text (block-editor content pasted into
     * a page builder widget); it is still HTML and must be converted.
     */
    private function isBlockMarkup(string $content): bool
    {
        return str_starts_with(ltrim($content), '<!-- wp:');
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function assetManifest(array $files, string $entryPath, array $assetReferences = array(), string $entryHtml = ''): array
    {
        $assets = array();
        $unsupportedStylesheets = $this->unsupportedStylesheetPaths($entryHtml, $entryPath);
        $documentLinkMedia = LinkedStylesheets::documentLinkMedia($files);
        $portableTargets = $this->portableTargetPaths($files);
        foreach ( $files as $file ) {
            if ( $entryPath === $file['path'] || ArtifactNormalizer::CAPTURE_REPORT_ROLE === $file['role'] || ArtifactFileIndex::isMaterializedHtmlDocument($file) || isset($unsupportedStylesheets[$file['path'] ?? '']) ) {
                continue;
            }
            $asset = array(
                'source'           => $file['source'] ?? 'artifact',
                'path'             => $file['path'],
                'target_path'      => $portableTargets[$file['path']] ?? $file['path'],
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
                'source_bytes'     => (int) ($file['provenance']['projected_from_bytes'] ?? $file['bytes']),
                'provenance'       => $file['provenance'],
            );
            if (isset($file['inline_references'])) $asset['inline_references'] = $file['inline_references'];
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
            } elseif ( isset($documentLinkMedia[$file['path']]) ) {
                // Only the entry document annotates the site-wide file set. A
                // stylesheet linked solely from other pages keeps the media
                // every linking page gives it, or it would apply at all widths.
                $asset['media'] = $documentLinkMedia[$file['path']];
            }
            if (isset($file['source_media']) && is_string($file['source_media'])) $asset['source_media'] = $file['source_media'];
            if ( 'css' === ($file['kind'] ?? null) ) {
                if (isset($file['stylesheet_activation'])) {
                    $asset['stylesheet_activation'] = $file['stylesheet_activation'];
                    $asset['stylesheet_source_path'] = $file['stylesheet_source_path'];
                }
                if (is_array($file['metadata']['compilation'] ?? null) || isset($file['inline_references']) || in_array($file['source'] ?? '', array('inline-style', 'inline-script'), true)) {
                    $asset['compilation'] = FileOwnership::of($file);
                }
                if ( is_int($file['stylesheet_link_position'] ?? null) ) {
                    $asset['stylesheet_link_position'] = $file['stylesheet_link_position'];
                }
                if (is_string($file['metadata']['page_stylesheet_of'] ?? null)) $asset['stylesheet_parent'] = $file['metadata']['page_stylesheet_of'];
                if (isset($file['stylesheet_index'])) $asset['stylesheet_inline_order'] = (int) $file['stylesheet_index'];
            }
            foreach ( array('defer', 'async') as $field ) {
                if ( isset($file[$field]) ) {
                    $asset[$field] = (bool) $file[$field];
                }
            }
            $references = $this->referencesForAsset((string) $file['path'], $assetReferences);
            if (isset($file['stylesheet_activation'], $file['references'])) $references = $file['references'];
            if ( array() !== $references ) {
                $asset['references'] = $references;
            }
            $assets[] = $asset;
        }
        if ( '' === $entryHtml ) {
            return $assets;
        }
        $orderedPaths = array();
        $pageStylesheets = array();
        $pageStylesheetParents = array();
        foreach ( $files as $file ) {
            if ( is_string($file['metadata']['page_stylesheet_of'] ?? null) ) {
                $parent = $file['metadata']['page_stylesheet_of'];
                $pageStylesheets[$parent][] = (string) $file['path'];
                $pageStylesheetParents[(string) $file['path']] = $parent;
            }
        }
        // The page's attribute-specific projection follows the shared copy at
        // the same source stylesheet boundary, before the next linked sheet.
        foreach ( array_column($this->stylesheetAssetsForSource($entryHtml, $entryPath, $files), 'path') as $path ) {
            $path = $pageStylesheetParents[$path] ?? $path;
            array_push($orderedPaths, $path, ...($pageStylesheets[$path] ?? array()));
        }
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
            if ( ! StyleTagScanner::isStylesheetRel(HtmlTagScanner::attribute((string) $tag, 'rel')) ) {
                continue;
            }
            $path = ArtifactPath::linkedFilePath(HtmlTagScanner::attribute((string) $tag, 'href'), $sourcePath);
            if ( '' === $path ) {
                continue;
            }
            if ( StyleTagScanner::isCssType(HtmlTagScanner::attribute((string) $tag, 'type')) ) {
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
     * Theme asset targets must be portable to any WordPress host:
     *
     * - No path segment contains `..`. Hosts such as WordPress.com's `_static/??`
     *   stylesheet concatenator reject such paths, so runs of dots collapse to
     *   one (`_..x.css` becomes `_.x.css`) and the extension is kept.
     * - Targets follow the site plan's case-insensitive collision policy: two
     *   files whose targets differ only by letter case would land in one file
     *   on a case-insensitive filesystem, so the plan rejects them.
     *
     * Files are grouped by their lowercased collapsed path. Within a group, an
     * already portable path keeps its name (byte-order-first when several do),
     * and every other file gets a numbered target (`photo-2.png`) that no other
     * file uses in any case. The outcome does not depend on file order.
     * Source paths stay as captured: references resolve by exact source path,
     * and each asset's token hashes its own target.
     *
     * @param array<int, array<string, mixed>> $files
     * @return array<string, string> Source path to target path, for renamed files only.
     */
    private function portableTargetPaths(array $files): array
    {
        $groups = array();
        $reserved = array();
        foreach ( $files as $file ) {
            $path = (string) ($file['path'] ?? '');
            if ( '' === $path ) {
                continue;
            }
            $reserved[strtolower($path)] = true;
            $collapsed = self::collapseDotRuns($path);
            $groups[strtolower($collapsed)][$path] = $collapsed;
        }
        $targets = array();
        ksort($groups, SORT_STRING);
        foreach ( $groups as $spellings ) {
            $paths = array_map('strval', array_keys($spellings));
            // Portable spellings first, then byte order.
            usort($paths, static fn(string $left, string $right): int => (($left !== $spellings[$left]) <=> ($right !== $spellings[$right])) ?: strcmp($left, $right));
            foreach ( $paths as $index => $path ) {
                if ( 0 === $index && $path === $spellings[$path] ) {
                    continue;
                }
                $target = ArtifactNormalizer::dedupePath($spellings[$path], $reserved, true);
                $reserved[strtolower($target)] = true;
                $targets[$path] = $target;
            }
        }
        return $targets;
    }

    /**
     * Collapse every run of two or more dots to a single dot, so no path
     * segment contains `..`.
     */
    private static function collapseDotRuns(string $path): string
    {
        return preg_replace('/\.{2,}/', '.', $path) ?? $path;
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
            if ( 'image/svg+xml' !== ($file['mime_type'] ?? '') || empty($file['content']) || SourceDom::isSafeSvgContent((string) $file['content']) ) {
                continue;
            }

            $diagnostics[] = ArtifactDiagnostics::make('unsafe_svg_asset', 'warning', 'An SVG image asset contains scriptable markup and its inline content was not exposed.', array('path' => $file['path']));
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

        return ! empty($asset['content']) && SourceDom::isSafeSvgContent((string) $asset['content']);
    }

    /**
     * @param array<string, mixed> $file
     */
    private function isUnsafeSvgAsset(array $file): bool
    {
        return 'image/svg+xml' === ($file['mime_type'] ?? '') && ! SourceDom::isSafeSvgContent((string) ($file['content'] ?? ''));
    }

    /**
     * Build immutable lookup state for one normalized artifact compilation.
     *
     * @param array<int, array<string, mixed>> $files
     */
    private function indexFiles(array $files): void
    {
        $this->fileIndex->index($files);
        $this->runtimeScriptEvidenceAnalyzer->resetCache();
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
     * A non-entrypoint page that fails to convert is a representability limit of
     * that page, not of the site: it is dropped and named, and the rest compiles.
     * Identity and plan-validation failures are integrity violations and keep
     * failing the compile, as does everything that is not an
     * InvalidArgumentException (LogicException, runtime and engine errors).
     *
     * @return array{source_path:string,reason_code:string,message:string}
     */
    private static function omittedPageOrRethrow(string $sourcePath, \InvalidArgumentException $error): array
    {
        if ($error instanceof DocumentIdentityException || $error instanceof ValidationException) {
            throw $error;
        }
        return array(
            'source_path' => $sourcePath,
            'reason_code' => self::PAGE_OMITTED_REASON_CODE,
            'message' => mb_strcut($error->getMessage(), 0, 256, 'UTF-8'),
        );
    }

    /**
     * Shared shells were classified across every page before one was dropped; a
     * dropped page is no longer a source of them.
     *
     * @param array<string,mixed> $compilation
     * @param array<int,string> $omittedPaths
     * @return array<string,mixed>
     */
    private function withoutOmittedInlineShellSources(array $compilation, array $omittedPaths): array
    {
        $keep = static fn(mixed $paths): mixed => is_array($paths) ? array_values(array_diff($paths, $omittedPaths)) : $paths;
        foreach ($compilation['artifacts'] ?? array() as $index => $artifact) {
            if (is_array($artifact['source_paths'] ?? null)) $compilation['artifacts'][$index]['source_paths'] = $keep($artifact['source_paths']);
            if (is_array($artifact['placement']['source_paths'] ?? null)) $compilation['artifacts'][$index]['placement']['source_paths'] = $keep($artifact['placement']['source_paths']);
        }
        return $compilation;
    }

    /**
     * One warning per dropped page, in source-path order. This is the only place
     * the plan records the omitted set; a consumer reads the diagnostics whose
     * code is PAGE_OMITTED_DIAGNOSTIC_CODE.
     *
     * @param array<string,array{source_path:string,reason_code:string,message:string}> $omittedPages
     * @return array<int,array<string,mixed>>
     */
    private function omittedPageDiagnostics(array $omittedPages): array
    {
        ksort($omittedPages, SORT_STRING);
        $diagnostics = array();
        foreach ($omittedPages as $page) {
            $diagnostics[] = array(
                'code' => self::PAGE_OMITTED_DIAGNOSTIC_CODE,
                'severity' => 'warning',
                'message' => $page['message'],
                'source' => self::class,
                'unit' => 'page',
                'source_path' => $page['source_path'],
                'reason_code' => $page['reason_code'],
            );
        }
        return $diagnostics;
    }

}

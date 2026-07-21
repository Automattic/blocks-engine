<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionReportProjection;
use Automattic\BlocksEngine\PhpTransformer\Contract\TransformationOptions;
use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\ContentRoundTripReporter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\DiagnosticsCollector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackEmitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\SemanticParityReporter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\AccordionPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ButtonsPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CodeWindowPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ColumnsPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\DetailsPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\GalleryPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\LogoPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\MathPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationUnderlineColorResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ParameterTablePattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternRecognizerRegistry;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PlaceholderMediaPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\QuotePattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\SpacerPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolutionTrait;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\BackgroundImageExtractor;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\ButtonLinkDispatchTrait;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\DomHelpersTrait;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\FormDispatchTrait;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\NavigationToggleSuppressionTrait;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SvgMaterializationTrait;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMDocument;
use DOMElement;
use DOMNode;

final class HtmlTransformer
{
    use ButtonLinkDispatchTrait;
    use DomHelpersTrait;
    use FormDispatchTrait;
    use NavigationToggleSuppressionTrait;
    use StyleResolutionTrait;
    use SvgMaterializationTrait;

    private const MAX_INTERACTION_CANDIDATES = 100;

    /**
     * Tag-only script selectors that must keep their native DOM shape when a
     * first-party runtime binds directly to them.
     *
     * @var array<int, string>
     */
    private const RUNTIME_TAG_SELECTORS = array( 'button', 'input', 'select', 'textarea', 'ul', 'ol', 'li', 'span', 'menu', 'menuitem' );

    /**
     * Generic class/id tokens that usually mark a JS-owned application surface
     * rather than editorial content. Used only with runtime selector evidence.
     *
     * @var array<int, string>
     */
    private const RUNTIME_APP_ROOT_TOKENS = array(
        'app', 'application', 'board', 'canvas', 'dashboard', 'desktop', 'editor',
        'explorer', 'instrument', 'lab', 'playground', 'rack', 'scene', 'shell',
        'simulator', 'stage', 'studio', 'terminal', 'viewport', 'workspace', 'world',
    );

    /**
     * Blocks that manage their own link destination and must never receive a
     * propagated card-link wrapper href (core/button owns its `url`,
     * core/navigation-link owns its `url`, core/html is opaque markup, …).
     *
     * @var array<int, string>
     */
    private const LINK_SELF_MANAGING_BLOCKS = array(
        'core/button',
        'core/buttons',
        'core/file',
        'core/html',
        'core/navigation',
        'core/navigation-link',
        'core/navigation-submenu',
    );

    /**
     * RichText content blocks whose stored `content` can carry an inline `<a>`
     * when a whole-element link wrapper is propagated onto them (#260).
     *
     * @var array<int, string>
     */
    private const LINK_BEARING_TEXT_BLOCKS = array(
        'core/heading',
        'core/paragraph',
        'core/list-item',
    );

    /**
     * @var array<int, string>
     */
    private const SUPPORTED_BLOCKS = array(
        'core/audio',
        'core/button',
        'core/buttons',
        'core/code',
        'core/column',
        'core/columns',
        'core/details',
        'core/embed',
        'core/file',
        'core/gallery',
        'core/group',
        'core/heading',
        'core/icon',
        'core/image',
        'core/list',
        'core/list-item',
        'core/math',
        'core/navigation',
        'core/navigation-link',
        'core/paragraph',
        'core/preformatted',
        'core/pullquote',
        'core/quote',
        'core/separator',
        'core/shortcode',
        'core/spacer',
        'core/navigation-submenu',
        'core/table',
        'core/video',
        'core/search',
    );

    private readonly BlockFactory $blockFactory;

    private readonly BackgroundImageExtractor $backgroundImageExtractor;

    private readonly ButtonsPattern $buttonsPattern;

    private readonly CodeWindowPattern $codeWindowPattern;

    private readonly ColumnsPattern $columnsPattern;

    private readonly DetailsPattern $detailsPattern;

    private readonly GalleryPattern $galleryPattern;

    private readonly LogoPattern $logoPattern;

    private readonly MathPattern $mathPattern;

    private readonly ParameterTablePattern $parameterTablePattern;

    private readonly TableClassificationPolicy $tableClassificationPolicy;

    private readonly PlaceholderMediaPattern $placeholderMediaPattern;

    private readonly QuotePattern $quotePattern;

    private readonly SpacerPattern $spacerPattern;

    private readonly PatternRecognizerRegistry $patternRecognizers;

    private readonly NavigationUnderlineColorResolver $navigationUnderlineColorResolver;

    private readonly DiagnosticsCollector $diagnosticsCollector;

    private readonly SemanticParityReporter $semanticParityReporter;

    private readonly ContentRoundTripReporter $contentRoundTripReporter;

    /**
     * Text the transformer SYNTHESIZES from form controls (label + value/
     * placeholder/required state) rather than extracting from visible source.
     * Declared to the content round-trip reporter so it is not mistaken for
     * invented copy. Reset per transform().
     *
     * @var array<int, string>
     */
    private array $formControlEchoTexts = array();

    private readonly FallbackEmitter $fallbackEmitter;

    /**
     * @var array<string, string>
     */
    private array $fallbackProvenance = array();

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $presentationProvenance = array();

    /**
     * Responsive/JS-revealed hidden base states normalized away during style
     * resolution (#259), surfaced for diagnostics.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $frozenHiddenStateFindings = array();

    /**
     * Whole-element link wrappers (an <a> wrapping block-level content) whose
     * link could not be propagated onto any native link-bearing inner block, so
     * the resulting content is no longer navigable (#260). Surfaced for
     * diagnostics so the navigation loss is detectable and a downstream repair
     * loop can act on it, rather than emitted as an unsupported attribute.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $droppedLinkWrapperFindings = array();

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $sourceProvenance = array();

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $structureProvenance = array();

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $scriptMetadata = array();

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $runtimeIslands = array();

    /**
     * Source elements whose subtree was folded into a native zero-JS disclosure
     * block (`core/details`) or the native `core/accordion` block. The toggle
     * controls inside these subtrees have their show/hide behavior carried
     * natively, so they must not be reported as interactive-control behavior
     * loss (analogous to core/navigation fold-in).
     *
     * Keyed by the source element's stable node path (libxml-derived XPath),
     * since PHP DOM hands out a fresh wrapper object per traversal and
     * `spl_object_id()` is therefore not stable across passes.
     *
     * @var array<string, true>
     */
    private array $nativeDisclosureRootIds = array();

    /**
     * Generated dynamic custom-block definitions produced at `core/html`
     * fallback decisions (issue #497). Surfaced under
     * `source_reports.generated_blocks` and packaged into the companion-plugin
     * payload by the ArtifactCompiler.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $generatedBlocks = array();

    /**
     * Block namespace for generated custom-block references. The ArtifactCompiler
     * sets this to the per-site companion-plugin namespace (`ssi-<site_slug>`) so
     * emitted references match the blocks SSI registers; standalone transforms
     * fall back to a generic namespace.
     */
    private string $generatedBlockNamespace = 'custom';

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $runtimeScriptMetadata = array();

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $assetMetadata = array();

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $generatedAssets = array();

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $gutenbergIncompatibilities = array();

    /**
     * @var array<string, string>
     */
    private array $cssCustomProperties = array();

    /**
     * @var array<string, array<int, string>>
     */
    private array $staticClassPromotions = array();

    /**
     * @var array<int, array{selector: string, declarations: array<string, string>}>
     */
    private array $staticStyleRules = array();

    /**
     * @var array<int, array{selector: string, declarations: array<string, string>}>
     */
    private array $conditionalStyleRules = array();

    /**
     * @var array<int, array{selector: string, pseudo: string, declarations: array<string, string>}>
     */
    private array $staticPseudoElementStyleRules = array();

    /**
     * @var array<string, bool>
     */
    private array $runtimeDomSelectors = array();

    /**
     * @var array<string, bool>
     */
    private array $runtimeCanvasSelectors = array();

    /**
     * Source DOM selectors (id/class) the transformer intentionally removed
     * because the element was superseded by a native block's own behavior — e.g.
     * a redundant JS hamburger menu-toggle (and the menu/overlay it controlled)
     * dropped because the navigation became a core/navigation with its own
     * responsive overlay. Surfaced under `source_reports.superseded_selectors`
     * so the runtime-dependency parity report can reclassify a "missing DOM
     * target" finding for these selectors as an acceptable, superseded loss
     * rather than a materialization bug (a preserved site script may still
     * reference the removed selector, which is expected, not broken).
     *
     * @var array<string, bool>
     */
    private array $supersededRuntimeSelectors = array();

    /** @var array<string, string> Source tag names whose serialized blocks need provenance classes. */
    private array $sourceTagMarkers = array();

    /** @var array<string, string> Source control DOM paths mapped to core/button wrapper classes. */
    private array $sourceControlMarkers = array();

    /** @var array<string, true> Source controls that need selector projection. */
    private array $sourceControlPaths = array();

    /** @var array<string, string> CSS-addressed inline leaves keyed by stable source DOM path. */
    private array $sourceSemanticMarkers = array();

    /** @var array<string, string> Source body children that need wrapper-safe selector projection. */
    private array $sourceRootChildMarkers = array();

    /** @var array<string, string> CSS-addressed RichText spans keyed by stable source DOM path. */
    private array $sourceRichTextSemanticMarkers = array();

    private string $combinedAuthorCss = '';

    private ?DOMElement $authorStyleSourceBody = null;

    private string $authorMarkerSeed = '';

    private int $authorMarkerCounter = 0;

    private string $authorMarkerCollisionText = '';

    /** @var list<array{path: string, content: string, source_hash: string}> */
    private array $authorStylesheetAssets = array();

    /** A collision-checked custom element used solely to retain type specificity. */
    private string $authorSpecificityShim = '';

    private string $authorClassSpecificityShim = '';

    private string $authorIdSpecificityShim = '';

    private int $nextSourceProvenanceId = 1;

    private bool $preserveShellLandmarks = false;

    public function __construct(private readonly Runtime $runtime = new Runtime())
    {
        $this->blockFactory      = new BlockFactory();
        $this->backgroundImageExtractor = new BackgroundImageExtractor();
        $this->buttonsPattern    = new ButtonsPattern();
        $this->codeWindowPattern = new CodeWindowPattern();
        $this->columnsPattern    = new ColumnsPattern();
        $this->detailsPattern    = new DetailsPattern();
        $this->galleryPattern    = new GalleryPattern();
        $this->logoPattern       = new LogoPattern();
        $this->mathPattern       = new MathPattern();
        $this->parameterTablePattern = new ParameterTablePattern();
        $this->tableClassificationPolicy = new TableClassificationPolicy();
        $this->placeholderMediaPattern = new PlaceholderMediaPattern();
        $this->quotePattern      = new QuotePattern();
        $this->spacerPattern     = new SpacerPattern();
        $this->patternRecognizers = new PatternRecognizerRegistry(array(
            new AccordionPattern(),
            new NavigationPattern(),
        ));
        $this->navigationUnderlineColorResolver = new NavigationUnderlineColorResolver();
        $this->diagnosticsCollector = new DiagnosticsCollector();
        $this->semanticParityReporter = new SemanticParityReporter($this->runtime);
        $this->contentRoundTripReporter = new ContentRoundTripReporter();
        $this->fallbackEmitter = new FallbackEmitter(
            $this->runtime,
            fn (DOMElement $element): array => $this->sourceContext($element)
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    public function transform(string $html, array $options = array()): TransformerResult
    {
        $context                  = TransformationOptions::context($options);
        $startedAt                = hrtime(true);
        $this->fallbackProvenance = TransformationOptions::provenance($options);
        $this->presentationProvenance = array();
        $this->frozenHiddenStateFindings = array();
        $this->droppedLinkWrapperFindings = array();
        $this->sourceProvenance = array();
        $this->structureProvenance = array();
        $this->scriptMetadata = array();
        $this->runtimeIslands = array();
        $this->nativeDisclosureRootIds = array();
        $this->generatedBlocks = array();
        $this->formControlEchoTexts = array();
        $this->generatedBlockNamespace = $this->generatedBlockNamespaceFromOptions($options);
        $this->preserveShellLandmarks = !empty($options['extract_global_shell']);
        $this->fallbackEmitter->resetGeneratedBlocks();
        $this->runtimeScriptMetadata = $this->runtimeScriptMetadataFromOptions($options);
        $this->assetMetadata = $this->assetMetadataFromOptions($options);
        $this->generatedAssets = array();
        $this->gutenbergIncompatibilities = array();
        $this->sourceTagMarkers = array();
        $this->sourceControlMarkers = array();
        $this->sourceControlPaths = array();
        $this->sourceSemanticMarkers = array();
        $this->sourceRootChildMarkers = array();
        $this->sourceRichTextSemanticMarkers = array();
        $this->combinedAuthorCss = '';
        $this->authorStyleSourceBody = null;
        $this->authorMarkerSeed = '';
        $this->authorMarkerCounter = 0;
        $this->authorMarkerCollisionText = '';
        $this->authorStylesheetAssets = array();
        $this->authorSpecificityShim = '';
        $this->authorClassSpecificityShim = '';
        $this->authorIdSpecificityShim = '';
        $this->staticClassPromotions = $this->detectStaticClassPromotions($html);
        $this->staticStyleRules = $this->staticStyleRules($html, (string) ($options['static_css'] ?? ''));
        $this->conditionalStyleRules = $this->conditionalStyleRules($html, (string) ($options['static_css'] ?? ''));
        $this->staticPseudoElementStyleRules = $this->staticPseudoElementStyleRules($html, (string) ($options['static_css'] ?? ''));
        $this->cssCustomProperties = $this->cssCustomProperties($html, (string) ($options['static_css'] ?? ''));
        $this->resetPresentationResolutionCache();
        $this->runtimeDomSelectors = $this->runtimeSelectorsFromOptions($options, 'runtime_dom_selectors');
        $this->runtimeCanvasSelectors = $this->runtimeCanvasSelectorsFromOptions($options);
        $this->supersededRuntimeSelectors = array();
        $this->fallbackEmitter->configure($this->fallbackProvenance, $this->runtimeScriptMetadata, $this->runtimeCanvasSelectors);
        $this->nextSourceProvenanceId = 1;
        $provenance               = array(
            array_merge(array(
                'source_format' => 'html',
                'input_bytes'   => strlen($html),
                'transformer'   => self::class,
            ), $this->fallbackProvenance),
        );

        $normalizedHtml = $this->normalizeHtml5VoidElements($this->documentBodyHtml($this->normalizeExplicitPlaintextElements($html)));
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded   = $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $normalizedHtml . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ( ! $loaded ) {
            $diagnostics = array(
                array(
                    'code'    => 'html_parse_failed',
                    'message' => 'Unable to parse HTML input.',
                    'source'  => self::class,
                ),
            );
            $fallbacks = array(
                FallbackDiagnostic::build(array(
                    'type'            => 'html',
                    'reason'          => 'parse_failed',
                    'diagnostic_code' => 'html_parse_failed',
                    'source_format'   => 'html',
                    'html'            => $html,
                ), $this->fallbackProvenance),
            );

            $metrics = $this->metrics($html, array(), '', $fallbacks, $diagnostics, $startedAt);
            $sourceReports = array(
                'conversion_report' => ConversionReportProjection::fromResultParts('html', array(), $fallbacks, array(), array(), $provenance, $metrics),
            );

            return new TransformerResult(
                diagnostics: $diagnostics,
                sourceReports: $sourceReports,
                fallbacks: $fallbacks,
                provenance: $provenance,
                context: $context,
                metrics: $metrics
            );
        }

        $body = $document->getElementsByTagName('body')->item(0);
        if ( ! $body instanceof DOMElement ) {
            $metrics = $this->metrics($html, array(), '', array(), array(), $startedAt);
            $sourceReports = array(
                'conversion_report' => ConversionReportProjection::fromResultParts('html', array(), array(), array(), array(), $provenance, $metrics),
            );

            return new TransformerResult(
                sourceReports: $sourceReports,
                provenance: $provenance,
                context: $context,
                metrics: $metrics
            );
        }

        $this->prepareAuthorSelectorSemantics($html, (string) ($options['static_css'] ?? ''), $body, $options);

        $fallbacks   = array();
        $interactionCandidates = $this->interactionCandidates($body);
        $this->collectSupersededNavToggleSelectors($body);
        $shellArtifacts = !array_key_exists('extract_global_shell', $options) || !empty($options['extract_global_shell']) ? $this->globalShellArtifacts($body, (string) ($options['source'] ?? 'html')) : array();
        $blocks      = $this->deduplicateNavigationBlocks($this->convertChildren($body, $fallbacks, true));
        $this->recordRuntimeIslandsForPreservedHtmlBlocks($blocks);
        $this->appendInteractiveControlBehaviorLossFallbacks($body, $fallbacks);
        $this->appendProductGridFallbacks($body, $fallbacks);
        $this->appendCommerceControlsFallbacks($body, $fallbacks);
        $sourceProvenance = $this->sourceProvenanceForBlocks($blocks);
        $serializedBlocks = $this->runtime->serializeBlocks($blocks);
        $authorStylesheetProjections = $this->authorStylesheetProjections();
        $this->materializeAuthorStylesheet(
            $html,
            (string) ($options['static_css'] ?? ''),
            true !== ($options['skip_author_stylesheet_materialization'] ?? false),
            $serializedBlocks
        );
        $blockValidityReport = $this->runtime->validateBlockSerialization($blocks);
        $semanticParityReport = $this->semanticParityReporter->report($body, $blocks, $sourceProvenance, $html, (string) ($options['static_css'] ?? ''));
        $contentRoundTripReport = $this->contentRoundTripReporter->report($serializedBlocks, $html, $this->formControlEchoTexts);
        $diagnostics = $this->diagnosticsCollector->collect(
            self::class,
            $this->scriptMetadata,
            $fallbacks,
            $this->runtimeIslands,
            $blockValidityReport,
            $semanticParityReport,
            $contentRoundTripReport
        );

        $metrics = $this->metrics($html, $blocks, $serializedBlocks, $fallbacks, $diagnostics, $startedAt);
        $nativeTargetBlocks = $this->runtime->availableCoreBlockNames();
        $sourceReports = array(
            'native_target_blocks' => $nativeTargetBlocks,
            'available_core_blocks' => $nativeTargetBlocks,
            'runtime_islands' => $this->runtimeIslands,
            'generated_blocks' => $this->generatedBlocks,
            'interaction_candidates' => $interactionCandidates,
            'superseded_selectors' => array_keys($this->supersededRuntimeSelectors),
            'shell_artifacts' => $shellArtifacts,
            'wp_block_validity' => $blockValidityReport,
            'semantic_parity' => $semanticParityReport,
            'content_round_trip' => $contentRoundTripReport,
            'html' => array(
                'presentation_signals' => $this->presentationProvenance,
                'frozen_hidden_state'  => $this->frozenHiddenStateFindings,
                'dropped_link_wrappers' => $this->droppedLinkWrapperFindings,
                'gutenberg_incompatibilities' => $this->gutenbergIncompatibilities,
                'source_provenance'    => $sourceProvenance,
                'structure_signals'    => $this->structureProvenance,
                'script_metadata'      => $this->scriptMetadata,
                'runtime_islands'      => $this->runtimeIslands,
            ),
        );
        if ( array() !== $authorStylesheetProjections ) {
            $sourceReports['author_stylesheet_projections'] = $authorStylesheetProjections;
        }
        $sourceReports['conversion_report'] = ConversionReportProjection::fromResultParts('html', $blocks, $fallbacks, $sourceReports, array(), $provenance, $metrics);

        return new TransformerResult(
            status: $this->statusForFallbacks($fallbacks, $context),
            blocks: $blocks,
            serializedBlocks: $serializedBlocks,
            assets: array_values($this->generatedAssets),
            diagnostics: $diagnostics,
            fallbacks: $fallbacks,
            provenance: $provenance,
            sourceReports: $sourceReports,
            coverage: array(
                array(
                    'supported_blocks'      => self::SUPPORTED_BLOCKS,
                    'native_target_blocks'  => $nativeTargetBlocks,
                    'available_core_blocks' => $nativeTargetBlocks,
                    'block_count'           => count($blocks),
                    'fallback_count'        => count($fallbacks),
                    'source_provenance_count' => count($sourceProvenance),
                ),
            ),
            context: $context,
            metrics: $metrics
        );
    }

    /**
     * Convert reusable document shell interiors through the same transformer
     * state as the full page so projected selector identities remain canonical.
     *
     * @return array<int, array<string, mixed>>
     */
    private function globalShellArtifacts(DOMElement $body, string $source, bool $removeFromContent = false): array
    {
        $artifacts = array();
        $removals = array();
        foreach ( $body->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            $area = ShellLandmarkPolicy::landmarkKind(strtolower($child->tagName), $this->attr($child, 'role'));
            if ( ! in_array($area, array( 'header', 'footer' ), true) ) {
                continue;
            }

            $shellFallbacks = array();
            $blocks = $this->deduplicateNavigationBlocks($this->convertChildren($child, $shellFallbacks, true));
            $innerMarkup = $this->runtime->serializeBlocks($blocks);
            $wrapperAttrs = $this->hoistedStylingAttributes($child);
            $wrapperAttrs['tagName'] = $area;
            $inlineStyle = trim($this->attr($child, 'style'));
            if ( '' !== $inlineStyle ) {
                // Group support maps only its canonical subset; retain the source
                // declaration so the landmark wrapper still owns its visual hook.
                $wrapperAttrs['inlineGeometryStyle'] = $inlineStyle;
            }
            $anchor = trim($this->attr($child, 'id'));
            if ( '' !== $anchor ) {
                $wrapperAttrs['anchor'] = $anchor;
            }
            // Use one core/group landmark wrapper rather than nesting the source
            // landmark around an independently converted landmark block.
            $blocks = array($this->createBlock('core/group', $wrapperAttrs, $blocks, $child));
            $markup = $this->runtime->serializeBlocks($blocks);
            if ( '' === trim($markup) ) {
                continue;
            }
            $artifacts[] = array(
                'source_path' => $source . '#' . $area,
                'slug' => $area,
                'title' => ucfirst($area),
                'area' => $area,
                'body_format' => 'blocks',
                'block_markup' => $markup,
                'inner_block_markup' => $innerMarkup,
                'source_selector' => strtolower($child->tagName),
                'source_classes' => $this->shellSourceClasses($child),
                'source_hash' => hash('sha256', $this->outerHtml($child)),
                'placement' => array('kind' => 'entry_shell', 'source_path' => $source, 'template_slugs' => array('front-page')),
            );
            // A successfully projected global shell is owned by the template part,
            // not duplicated in the entry page's post-content markup.
            if ($removeFromContent) $removals[] = $child;
        }

        foreach ($removals as $child) $body->removeChild($child);

        return $artifacts;
    }

    /** @return array<int, string> */
    private function shellSourceClasses(DOMElement $element): array
    {
        $classes = preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array();
        $classes = array_values(array_unique(array_filter($classes, static fn (string $class): bool => '' !== $class)));
        sort($classes, SORT_STRING);
        return $classes;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $fallbacks
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, int|float>
     */
    private function metrics(string $input, array $blocks, string $output, array $fallbacks, array $diagnostics, int $startedAt): array
    {
        return array(
            'input_bytes'           => strlen($input),
            'block_count'           => $this->countBlocks($blocks),
            'fallback_count'        => count($fallbacks),
            'diagnostic_count'      => count($diagnostics),
            'transform_duration_ms' => (hrtime(true) - $startedAt) / 1000000,
            'output_bytes'          => strlen($output),
        );
    }

    private function materializeAuthorStylesheet(string $html, string $staticCss, bool $includeAuthorStyles = true, string $serializedBlocks = ''): void
    {
        $cssParts = array();
        $geometryCss = $this->generatedGeometryCss($serializedBlocks);
        if ( '' !== $geometryCss ) {
            // Important carrier rules precede author CSS: they retain inline
            // precedence over normal selectors while authored !important rules
            // remain able to override them.
            $cssParts[] = $geometryCss;
        }
        $markerReset = $this->richTextMarkerResetCss();
        if ( '' !== $markerReset ) {
            $cssParts[] = $markerReset;
        }
        if ( str_contains($serializedBlocks, 'blocks-engine-list-navigation') ) {
            $cssParts[] = '.wp-block-navigation.blocks-engine-list-navigation .wp-block-navigation-item.wp-block-navigation-link{display:list-item;font:inherit}'
                . "\n" . '.wp-block-navigation.blocks-engine-list-navigation .wp-block-navigation-item__content{display:inline}';
        }
        if ( $includeAuthorStyles && '' !== $this->combinedAuthorCss ) {
            $cssParts[] = $this->rewriteAuthorStylesheet($this->combinedAuthorCss);
        }

        $css = trim(implode("\n\n", $cssParts));
        if ( '' === $css ) {
            return;
        }

        $hash = hash('sha256', $css);
        $path = 'assets/css/source-author-' . substr($hash, 0, 16) . '.css';

        $this->generatedAssets[$path] = array(
            'source'      => 'author-css',
            'source_path' => '',
            'path'        => $path,
            'target_path' => $path,
            'kind'        => 'css',
            'role'        => 'stylesheet',
            'mime_type'   => 'text/css',
            'media_type'  => 'text/css',
            'content'     => $css . "\n",
            'bytes'       => strlen($css) + 1,
            'encoding'    => 'utf-8',
            'binary'      => false,
            'hash'        => $hash,
            'source_hash' => $hash,
        );
    }

    private function richTextMarkerResetCss(): string
    {
        if ( array() === $this->sourceRichTextSemanticMarkers ) {
            return '';
        }

        return ':where(mark)[style*="--blocks-engine-richtext-marker:"]{background-color:transparent;color:inherit}';
    }

    /** @param array<string, mixed> $options */
    private function prepareAuthorSelectorSemantics(string $html, string $staticCss, DOMElement $sourceBody, array $options): void
    {
        $this->authorStylesheetAssets = $this->authorStylesheetAssetsFromOptions($options);
        $this->combinedAuthorCss = array() === $this->authorStylesheetAssets
            ? $this->combinedAuthorStylesheet($html, $staticCss)
            : implode("\n\n", array_column($this->authorStylesheetAssets, 'content'));
        // Ignore already-generated-looking markers when seeding so collision
        // avoidance remains deterministic even when source CSS contains one.
        $seedInput = preg_replace('/blocks-engine-(?:source-p|control|specificity(?:-(?:class|id))?)-[a-f0-9]+-\d+/', '', $html . "\0" . $this->combinedAuthorCss) ?? '';
        $this->authorMarkerSeed = substr(hash('sha256', $seedInput), 0, 12);
        $this->authorMarkerCollisionText = $html . "\0" . $this->combinedAuthorCss;
        $this->authorSpecificityShim = $this->allocateAuthorMarker('specificity');
        $this->authorClassSpecificityShim = $this->allocateAuthorMarker('specificity-class');
        $this->authorIdSpecificityShim = $this->allocateAuthorMarker('specificity-id');

        if ( '' === $this->combinedAuthorCss ) {
            return;
        }

        $this->authorStyleSourceBody = $sourceBody;

        $sourceTagSelectorNames = array();
        ( new CssStylesheetTransformer() )->transform($this->combinedAuthorCss, static function (string $prelude) use (&$sourceTagSelectorNames): string {
            foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
                $parsed = CssSelectorMatcher::parse($selector);
                foreach ( $parsed['type_spans'] ?? array() as $typeSpan ) {
                    $tagName = strtolower($typeSpan['name']);
                    if ( in_array($tagName, array( 'nav', 'p' ), true) ) {
                        $sourceTagSelectorNames[ $tagName ] = true;
                    }
                }
            }
            return $prelude;
        });
        foreach ( array_keys($sourceTagSelectorNames) as $tagName ) {
            $this->sourceTagMarkers[ $tagName ] = $this->allocateAuthorMarker('source-' . $tagName);
        }
        $this->discoverAuthorControlPaths();
        $this->discoverAuthorInlineSemanticPaths();
        $this->discoverAuthorRootChildPaths();
    }

    private function discoverAuthorControlPaths(): void
    {
        ( new CssStylesheetTransformer() )->transform($this->combinedAuthorCss, function (string $prelude): string {
            foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
                $parsed = CssSelectorMatcher::parse($selector);
                if ( ! $parsed['supported'] ) {
                    continue;
                }
                $matches = $this->matchingAuthorSourceElements($parsed);
                $controls = array_filter($matches, static fn (DOMElement $element): bool => in_array(strtolower($element->tagName), array( 'a', 'button' ), true));
                if ( array() === $controls ) {
                    continue;
                }
                foreach ( $controls as $control ) {
                    $path = $control->getNodePath() ?? '';
                    if ( '' !== $path ) {
                        $this->sourceControlPaths[$path] = true;
                    }
                }
            }
            return $prelude;
        });
    }

    private function discoverAuthorInlineSemanticPaths(): void
    {
        ( new CssStylesheetTransformer() )->transform($this->combinedAuthorCss, function (string $prelude): string {
            foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
                $parsed = CssSelectorMatcher::parse($selector);
                if ( ! $parsed['supported'] ) {
                    continue;
                }
                foreach ( $this->matchingAuthorSourceElements($parsed) as $element ) {
                    if ( 'span' !== strtolower($element->tagName) ) {
                        continue;
                    }
                    $path = $this->sourceElementIdentity($element);
                    if ( '' === $path ) {
                        continue;
                    }
                    if ( $this->requiresIndependentSemanticWrapper($element) ) {
                        if ( '' !== $path ) {
                            $this->sourceSemanticMarkers[$path] ??= $this->allocateAuthorMarker('semantic');
                        }
                    } elseif ( $this->richTextSelectorNeedsHook($parsed) ) {
                        $marker = $this->sourceRichTextSemanticMarkers[$path] ??= $this->allocateAuthorMarker('richtext');
                        // Carry the generated identity through intermediate
                        // wrapper conversions before RichText normalizes spans.
                        $element->setAttribute('data-blocks-engine-richtext-marker', $marker);
                    }
                }
            }
            return $prelude;
        });
    }

    private function discoverAuthorRootChildPaths(): void
    {
        ( new CssStylesheetTransformer() )->transform($this->combinedAuthorCss, function (string $prelude): string {
            foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
                $parsed = CssSelectorMatcher::parse($selector);
                if ( ! $parsed['supported'] || ! $this->isRootChildSelector($parsed) ) {
                    continue;
                }
                foreach ( $this->matchingAuthorSourceElements($parsed) as $element ) {
                    if ( in_array(strtolower($element->tagName), array( 'link', 'meta', 'script', 'style', 'template', 'title' ), true) ) {
                        continue;
                    }
                    $path = $this->sourceElementIdentity($element);
                    if ( '' !== $path ) {
                        $this->sourceRootChildMarkers[$path] ??= $this->allocateAuthorMarker('root-child');
                    }
                }
            }
            return $prelude;
        });
    }

    /** @param array<string, mixed> $parsed */
    private function isRootChildSelector(array $parsed): bool
    {
        $compounds = $parsed['compounds'] ?? array();
        $combinators = $parsed['combinators'] ?? array();
        $last = count($compounds) - 1;

        return $last >= 1
            && 'body' === strtolower((string) ($compounds[$last - 1]['type'] ?? ''))
            && '>' === ($combinators[$last - 1] ?? '');
    }

    private function combinedAuthorStylesheet(string $html, string $staticCss): string
    {
        $cssParts = array();
        if ( preg_match_all('@<style\b[^>]*>(.*?)</style>@is', $html, $matches) ) {
            foreach ( $matches[1] as $styleBlock ) {
                $styleBlock = trim(html_entity_decode((string) $styleBlock, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ( '' !== $styleBlock ) {
                    $cssParts[] = $styleBlock;
                }
            }
        }
        $staticCss = trim($staticCss);
        if ( '' !== $staticCss ) {
            $cssParts[] = $staticCss;
        }
        return trim(implode("\n\n", $cssParts));
    }

    /** @param array<string, mixed> $options @return list<array{path: string, content: string, source_hash: string}> */
    private function authorStylesheetAssetsFromOptions(array $options): array
    {
        if ( ! is_array($options['author_stylesheet_assets'] ?? null) ) {
            return array();
        }
        $assets = array();
        foreach ( $options['author_stylesheet_assets'] as $asset ) {
            if ( ! is_array($asset) || ! is_string($asset['path'] ?? null) || '' === $asset['path'] || ! is_string($asset['content'] ?? null) ) {
                continue;
            }
            $assets[] = array( 'path' => $asset['path'], 'content' => $asset['content'], 'source_hash' => is_string($asset['source_hash'] ?? null) ? $asset['source_hash'] : hash('sha256', $asset['content']) );
        }
        return $assets;
    }

    /** @return list<array{path: string, content: string, bytes: int, hash: string, source_hash: string}> */
    private function authorStylesheetProjections(): array
    {
        $projections = array();
        $markerReset = $this->richTextMarkerResetCss();
        foreach ( $this->authorStylesheetAssets as $asset ) {
            $content = $this->rewriteAuthorStylesheet($asset['content']);
            if ( '' !== $markerReset ) {
                $content = $markerReset . "\n" . $content;
                $markerReset = '';
            }
            $hash = hash('sha256', $content);
            $projections[] = array(
                'path'        => $asset['path'],
                'content'     => $content,
                'bytes'       => strlen($content),
                'hash'        => $hash,
                'source_hash' => $asset['source_hash'],
            );
        }
        return $projections;
    }

    private function allocateAuthorMarker(string $kind): string
    {
        do {
            $marker = 'blocks-engine-' . $kind . '-' . $this->authorMarkerSeed . '-' . $this->authorMarkerCounter++;
        } while ( str_contains($this->authorMarkerCollisionText, $marker) );
        return $marker;
    }

    private function rewriteAuthorStylesheet(string $stylesheet): string
    {
        return ( new CssStylesheetTransformer() )->transformStyleRules($stylesheet, function (string $prelude, string $body): string {
            $declarations = $this->cssDeclarations($body);
            $margins = array_filter($declarations, static fn (string $name): bool => 'margin' === $name || str_starts_with($name, 'margin-'), ARRAY_FILTER_USE_KEY);
            if ( array() === $margins ) {
                return $this->rewriteAuthorSelectorPrelude($prelude) . '{' . $body . '}';
            }

            $inner = array_diff_key($declarations, $margins);
            $rules = '' === $this->cssDeclarationString($inner)
                ? ''
                : $this->rewriteAuthorSelectorPrelude($prelude) . '{' . $this->cssDeclarationString($inner) . '}';
            return $rules . $this->rewriteAuthorSelectorPrelude($prelude, true) . '{' . $this->cssDeclarationString($margins) . '}';
        });
    }

    private function rewriteAuthorSelectorPrelude(string $prelude, bool $controlWrapper = false): string
    {
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors || ! $this->authorStyleSourceBody instanceof DOMElement ) {
            return $prelude;
        }

        $rewritten = array();
        foreach ( $selectors as $selector ) {
            $parsed = CssSelectorMatcher::parse($selector);
            if ( ! $parsed['supported'] ) {
                $rewritten[] = $selector;
                continue;
            }
            $matches = $this->matchingAuthorSourceElements($parsed);
            if ( array() === $matches ) {
                $rewritten[] = $selector;
                continue;
            }
            $hasImageMatch = array() !== array_filter($matches, static fn (DOMElement $element): bool => 'img' === strtolower($element->tagName));
            if ( $this->isRootChildSelector($parsed) ) {
                $shellTags = array_values(array_unique(array_filter(array_map(
                    function (DOMElement $element): string {
                        if ( $element->parentNode !== $this->authorStyleSourceBody ) {
                            return '';
                        }
                        $tag = strtolower($element->tagName);
                        $area = ShellLandmarkPolicy::landmarkKind($tag, $this->attr($element, 'role'));
                        return in_array($area, array( 'header', 'footer' ), true) ? $tag : '';
                    },
                    $matches
                ))));
                $markers = array_values(array_unique(array_filter(array_map(
                    function (DOMElement $element) use ($shellTags): string {
                        return in_array(strtolower($element->tagName), $shellTags, true)
                            ? ''
                            : ($this->sourceRootChildMarkers[$this->sourceElementIdentity($element)] ?? '');
                    },
                    $matches
                ))));
                if ( array() === $markers && array() === $shellTags ) {
                    $rewritten[] = $selector;
                    continue;
                }
                foreach ( $markers as $marker ) {
                    $projected = $this->projectSemanticLeafSelector($selector, $parsed, $marker);
                    $rewritten[] = $projected;
                    if ( $hasImageMatch ) {
                        $rewritten[] = $projected . ' > :where(img)';
                    }
                }
                foreach ( $shellTags as $tag ) {
                    $rewritten[] = ':where(' . $tag . '.wp-block-template-part)' . $this->selectorSpecificityShims($parsed);
                }
                continue;
            }

            $controls = array();
            $semanticLeaves = array();
            $richTextLeaves = array();
            $hasNonProjected = false;
            foreach ( $matches as $element ) {
                $path = $element->getNodePath() ?? '';
                if ( isset($this->sourceControlMarkers[$path]) ) {
                    $controls[] = $this->sourceControlMarkers[$path];
                } elseif ( isset($this->sourceSemanticMarkers[$this->sourceElementIdentity($element)]) ) {
                    $semanticLeaves[] = $this->sourceSemanticMarkers[$this->sourceElementIdentity($element)];
                } elseif ( isset($this->sourceRichTextSemanticMarkers[$this->sourceElementIdentity($element)]) ) {
                    $richTextLeaves[] = $this->sourceRichTextSemanticMarkers[$this->sourceElementIdentity($element)];
                } else {
                    $hasNonProjected = true;
                }
            }
            $controls = array_values(array_unique($controls));
            $semanticLeaves = array_values(array_unique($semanticLeaves));
            $richTextLeaves = array_values(array_unique($richTextLeaves));
            if ( array() === $controls && array() === $semanticLeaves && array() === $richTextLeaves ) {
                $rewritten[] = $this->rewriteSourceTagTypes($selector, $parsed);
                if ( $hasImageMatch ) {
                    $rewritten[] = $this->projectImageSelector($selector, $parsed);
                }
                continue;
            }

            $projectedMarkers = array_merge($controls, $semanticLeaves, $richTextLeaves);
            if ( $hasNonProjected ) {
                $rewritten[] = $this->rewriteSourceTagTypes($selector, $parsed, ':not(:where(.' . implode(',.', $projectedMarkers) . '))');
            }
            foreach ( $controls as $marker ) {
                $rewritten[] = $this->projectControlSelector($selector, $parsed, $marker, $controlWrapper);
            }
            foreach ( $semanticLeaves as $marker ) {
                $rewritten[] = $this->projectSemanticLeafSelector($selector, $parsed, $marker);
            }
            foreach ( $richTextLeaves as $marker ) {
                $rewritten[] = $this->projectRichTextSemanticSelector($selector, $parsed, $marker);
            }
            if ( $hasImageMatch ) {
                $rewritten[] = $this->projectImageSelector($selector, $parsed);
            }
        }
        return implode(',', $rewritten);
    }

    /** @param array<string, mixed> $parsed @return list<DOMElement> */
    private function matchingAuthorSourceElements(array $parsed): array
    {
        $matches = array();
        foreach ($this->authorStyleSourceBody?->getElementsByTagName('*') ?? array() as $element) {
            if ( $element instanceof DOMElement && CssSelectorMatcher::matches($element, $parsed, true)['matches'] ) {
                $matches[] = $element;
            }
        }
        return $matches;
    }

    /** @param array<string, mixed> $parsed */
    private function rewriteSourceTagTypes(string $selector, array $parsed, string $rightmostInsertion = ''): string
    {
        $replacements = array();
        foreach ( $parsed['type_spans'] as $typeSpan ) {
            if ( isset($this->sourceTagMarkers[strtolower($typeSpan['name'])]) ) {
                $replacements[$typeSpan['start']] = array( 'end' => $typeSpan['end'], 'value' => ':where(.' . $this->sourceTagMarkers[strtolower($typeSpan['name'])] . ')' . $this->typeSpecificityShim() );
            }
        }
        if ( '' !== $rightmostInsertion ) {
            $replacements[(int) $parsed['rightmost_rewrite_end']] = array( 'end' => (int) $parsed['rightmost_rewrite_end'], 'value' => $rightmostInsertion );
        }
        return $this->replaceSelectorSpans($selector, $replacements);
    }

    /** @param array<string, mixed> $parsed */
    private function projectControlSelector(string $selector, array $parsed, string $marker, bool $wrapper = false): string
    {
        $suffix = null === $parsed['pseudo_state_suffix_span'] ? '' : substr($selector, $parsed['pseudo_state_suffix_span']['start']);
        // Source matching is complete before mutation and the marker is unique to
        // this control. Project through it rather than assuming source attributes
        // or ancestors survive canonical core/button serialization.
        return ':where(.' . $marker . ')' . ($wrapper ? ':where(.wp-block-buttons)' : $this->selectorSpecificityShims($parsed) . '> :where(.wp-block-button__link)') . $suffix;
    }

    /** @param array<string, mixed> $parsed */
    private function projectSemanticLeafSelector(string $selector, array $parsed, string $marker): string
    {
        $suffix = null === $parsed['pseudo_state_suffix_span'] ? '' : substr($selector, $parsed['pseudo_state_suffix_span']['start']);
        return ':where(.' . $marker . ')' . $this->selectorSpecificityShims($parsed) . $suffix;
    }

    /** @param array<string, mixed> $parsed */
    private function projectRichTextSemanticSelector(string $selector, array $parsed, string $marker): string
    {
        $suffix = null === $parsed['pseudo_state_suffix_span'] ? '' : substr($selector, $parsed['pseudo_state_suffix_span']['start']);
        return 'mark[style*="--blocks-engine-richtext-marker:' . $marker . '"]' . $this->selectorSpecificityShims($parsed) . $suffix;
    }

    /** @param array<string, mixed> $parsed */
    private function projectImageSelector(string $selector, array $parsed): string
    {
        $replacements = array(
            (int) $parsed['rightmost_rewrite_end'] => array(
                'end'   => (int) $parsed['rightmost_rewrite_end'],
                'value' => ' > :where(img)',
            ),
        );
        $rightmostType = $parsed['compounds'][count($parsed['compounds']) - 1]['type'] ?? null;
        if ( is_string($rightmostType) && 'img' === strtolower($rightmostType) ) {
            $typeSpan = end($parsed['type_spans']);
            if ( is_array($typeSpan) ) {
                $replacements[(int) $typeSpan['start']] = array(
                    'end'   => (int) $typeSpan['end'],
                    'value' => ':where(figure)' . $this->typeSpecificityShim(),
                );
            }
        }

        return $this->replaceSelectorSpans($selector, $replacements);
    }

    /** @param array<string, mixed> $parsed */
    private function rightmostTypeIsControl(array $parsed): bool
    {
        $type = $parsed['compounds'][count($parsed['compounds']) - 1]['type'] ?? null;
        return is_string($type) && in_array(strtolower($type), array( 'a', 'button' ), true);
    }

    private function typeSpecificityShim(): string
    {
        return '' === $this->authorSpecificityShim ? '' : ':not(' . $this->authorSpecificityShim . ')';
    }

    /** @param array<string, mixed> $parsed */
    private function selectorSpecificityShims(array $parsed): string
    {
        // A wrapper-driven button can collapse selector ancestors onto its one
        // canonical wrapper. Collision-checked impossible sentinels preserve the
        // source selector's specificity without coupling to Gutenberg classes.
        $shims = '';
        foreach ( $parsed['compounds'] as $compound ) {
            if ( null !== $compound['type'] ) {
                $shims .= $this->typeSpecificityShim();
            }
            foreach ( $compound['classes'] as $_class ) {
                $shims .= ':not(.' . $this->authorClassSpecificityShim . ')';
            }
            foreach ( $compound['attributes'] as $_attribute ) {
                $shims .= ':not(.' . $this->authorClassSpecificityShim . ')';
            }
            foreach ( $compound['ids'] as $_id ) {
                $shims .= ':not(#' . $this->authorIdSpecificityShim . ')';
            }
        }
        return $shims;
    }

    /** @param array<int, array{end: int, value: string}> $replacements */
    private function replaceSelectorSpans(string $selector, array $replacements): string
    {
        ksort($replacements, SORT_NUMERIC);
        $output = '';
        $offset = 0;
        foreach ( $replacements as $start => $replacement ) {
            $output .= substr($selector, $offset, $start - $offset) . $replacement['value'];
            $offset = $replacement['end'];
        }
        return $output . substr($selector, $offset);
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
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateNavigationBlocks(array $blocks): array
    {
        $seen = array();
        return $this->deduplicateNavigationBlocksRecursive($blocks, $seen);
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<string, bool> $seen
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateNavigationBlocksRecursive(array $blocks, array &$seen): array
    {
        $deduplicated = array();
        foreach ( $blocks as $block ) {
            if ( ! is_array($block) ) {
                continue;
            }

            if ( ! empty($block['innerBlocks']) && is_array($block['innerBlocks']) ) {
                $block['innerBlocks'] = $this->deduplicateNavigationBlocksRecursive($block['innerBlocks'], $seen);
                $block = $this->reconcileInnerContentChildPlaceholders($block);
            }

            if ( 'core/navigation' === ($block['blockName'] ?? '') ) {
                $signature = $this->navigationBlockSignature($block);
                if ( '' !== $signature && isset($seen[$signature]) && $this->isMobileDuplicateNavigationBlock($block) ) {
                    continue;
                }
                if ( '' !== $signature ) {
                    $seen[$signature] = true;
                }
            }

            $deduplicated[] = $block;
        }

        return $deduplicated;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function isMobileDuplicateNavigationBlock(array $block): bool
    {
        $provenanceId = $block['_source_provenance_id'] ?? null;
        $source = is_int($provenanceId) ? ( $this->sourceProvenance[$provenanceId] ?? array() ) : array();
        $attributes = is_array($source['source_attributes'] ?? null) ? $source['source_attributes'] : array();
        $context = is_array($source['context'] ?? null) ? $source['context'] : array();
        $classNames = is_array($context['class_names'] ?? null) ? implode(' ', $context['class_names']) : '';

        $haystack = strtolower(trim(implode(' ', array(
            (string) ($attributes['class'] ?? ''),
            (string) ($attributes['id'] ?? ''),
            $classNames,
        ))));

        return (bool) preg_match('/(?:^|[^a-z0-9])(?:mobile|drawer|offcanvas|overlay|hamburger|menu-panel|nav-panel)(?:[^a-z0-9]|$)/', $haystack);
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function reconcileInnerContentChildPlaceholders(array $block): array
    {
        $innerBlocks = is_array($block['innerBlocks'] ?? null) ? array_values($block['innerBlocks']) : array();
        $innerContent = is_array($block['innerContent'] ?? null) ? array_values($block['innerContent']) : null;
        if ( null === $innerContent ) {
            return $block;
        }

        $placeholderCount = 0;
        $firstPlaceholderIndex = null;
        $lastPlaceholderIndex = null;
        foreach ( $innerContent as $index => $part ) {
            if ( null !== $part ) {
                continue;
            }

            ++$placeholderCount;
            $firstPlaceholderIndex ??= $index;
            $lastPlaceholderIndex = $index;
        }

        if ( count($innerBlocks) === $placeholderCount ) {
            return $block;
        }

        if ( null === $firstPlaceholderIndex || null === $lastPlaceholderIndex ) {
            return $block;
        }

        $opening = array_slice($innerContent, 0, $firstPlaceholderIndex);
        $closing = array_slice($innerContent, $lastPlaceholderIndex + 1);
        $block['innerBlocks'] = $innerBlocks;
        $block['innerContent'] = array_merge($opening, array_fill(0, count($innerBlocks), null), $closing);
        $block['innerHTML'] = implode('', array_map(static fn ($part): string => null === $part ? '' : (string) $part, array_merge($opening, $closing)));

        return $block;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function navigationBlockSignature(array $block): string
    {
        $links = array();
        $this->collectNavigationBlockLinks($block, $links);
        return implode('|', $links);
    }

    /**
     * @param array<string, mixed> $block
     * @param array<int, string> $links
     */
    private function collectNavigationBlockLinks(array $block, array &$links): void
    {
        if ( in_array($block['blockName'] ?? '', array( 'core/navigation-link', 'core/navigation-submenu' ), true) ) {
            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
            $links[] = $this->normalizedNavigationLabel((string) ($attrs['label'] ?? '')) . '>' . trim((string) ($attrs['url'] ?? ''));
        }

        foreach ( is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array() as $innerBlock ) {
            if ( is_array($innerBlock) ) {
                $this->collectNavigationBlockLinks($innerBlock, $links);
            }
        }
    }

    private function normalizeHtml5VoidElements(string $html): string
    {
        return preg_replace('/<source\b([^>]*?)(?<!\/)\s*>/i', '<source$1></source>', $html) ?? $html;
    }

    private function normalizeExplicitPlaintextElements(string $html): string
    {
        return preg_replace_callback(
            '/<plaintext\b([^>]*)>(.*?)<\/plaintext\s*>/is',
            static fn (array $matches): string => '<pre' . $matches[1] . '>' . str_replace('<', '&lt;', $matches[2]) . '</pre>',
            $html
        ) ?? $html;
    }

    private function documentBodyHtml(string $html): string
    {
        if ( ! preg_match('/<(?:!doctype|html|head|body)\b/i', $html) ) {
            return $html;
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded   = $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ( ! $loaded ) {
            return $html;
        }

        $body = $document->getElementsByTagName('body')->item(0);
        if ( ! $body instanceof DOMElement ) {
            return $html;
        }

        return $this->innerHtml($body);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @param array{strict: bool, allow_fallbacks: bool} $context
     */
    private function statusForFallbacks(array $fallbacks, array $context): string
    {
        if ( array() === $fallbacks || $context['allow_fallbacks'] ) {
            return 'success';
        }

        return $context['strict'] ? 'failed' : 'success_with_warnings';
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<int, array<string, mixed>>
     */
    private function convertChildren(DOMNode $parent, array &$fallbacks, bool $captureUnsupported = false): array
    {
        $blocks = array();

        foreach ( $parent->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                $text = trim($child->textContent ?? '');
                if ( '' !== $text ) {
                    $blocks = array_merge($blocks, $this->convertText($text));
                }
                continue;
            }

            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $block = $this->convertElement($child, $fallbacks, $captureUnsupported);
            if ( null !== $block ) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    private function patternContext(bool $includeRuntimeDomTarget = true): PatternContext
    {
        return new PatternContext(
            fn (DOMElement $sourceElement, array $excludedGeometryProperties = array()): array => $this->presentationAttributes($sourceElement, $excludedGeometryProperties),
            fn (DOMElement $sourceElement): string => $this->innerHtml($sourceElement),
            fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => $this->createBlock($name, $attrs, $innerBlocks, $sourceElement),
            $includeRuntimeDomTarget ? fn (DOMElement $sourceElement): bool => $this->isRuntimeDomTarget($sourceElement) : null,
            fn (DOMElement $sourceElement): array => $this->convertPatternChildren($sourceElement),
            fn (DOMElement $sourceElement, array $excludedTags): array => $this->convertPatternChildrenWithoutTags($sourceElement, $excludedTags),
            fn (DOMElement $item, DOMElement $anchor): string => $this->navigationUnderlineColor($item, $anchor)
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function convertPatternChildren(DOMElement $element): array
    {
        $fallbacks = array();
        return $this->convertChildren($element, $fallbacks, true);
    }

    /**
     * @param array<int, string> $excludedTags
     * @return array<int, array<string, mixed>>
     */
    private function convertPatternChildrenWithoutTags(DOMElement $element, array $excludedTags): array
    {
        $fallbacks = array();
        return $this->convertChildrenWithoutTags($element, $fallbacks, $excludedTags);
    }

    /**
     * A side-effect-free pattern context for probing whether an element would
     * convert to a given block, without recording provenance or runtime islands.
     */
    private function probePatternContext(): PatternContext
    {
        return new PatternContext(
            fn (DOMElement $sourceElement, array $excludedGeometryProperties = array()): array => $this->presentationAttributes($sourceElement, $excludedGeometryProperties),
            fn (DOMElement $sourceElement): string => $this->innerHtml($sourceElement),
            static fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => array(
                'blockName'   => $name,
                'attrs'       => $attrs,
                'innerBlocks' => $innerBlocks,
            ),
            null,
            null,
            null,
            fn (DOMElement $item, DOMElement $anchor): string => $this->navigationUnderlineColor($item, $anchor)
        );
    }

    private function navigationUnderlineColor(DOMElement $item, DOMElement $anchor): string
    {
        return $this->navigationUnderlineColorResolver->resolve(
            $item,
            $anchor,
            fn (DOMElement $element): array => $this->presentationDeclarations($element),
            $this->staticPseudoElementStyleRules,
            fn (DOMElement $element, string $selector): bool => $this->matchesCssSelector($element, $selector)
        );
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function convertElement(DOMElement $element, array &$fallbacks, bool $captureUnsupported = false): ?array
    {
        $tagName = strtolower($element->tagName);

        if ( $this->isRedundantMenuToggleControl($element) ) {
            return null;
        }

        // Handle a safe SVG at a phrasing-to-block boundary before generic
        // preservation rules see the SVG as an unsupported document fragment.
        if ( 'svg' === $tagName && $this->svgNeedsPhrasingHost($element) ) {
            $imageMarkup = $this->inlineSvgRichTextImageMarkup($element);
            if ( null !== $imageMarkup ) {
                return $this->createBlock('core/paragraph', array( 'content' => $imageMarkup ), array(), $element);
            }
        }

        if ( $this->shouldPreserveDataAttributeRuntimeTarget($element) ) {
            return $this->createBlock('core/html', array( 'content' => $this->outerHtml($element) ), array(), $element);
        }

        $mathBlock = $this->mathPattern->match(
            $element,
            fn (DOMElement $sourceElement, string $name): string => $this->attr($sourceElement, $name),
            fn (DOMElement $sourceElement): array => $this->presentationAttributes($sourceElement),
            fn (DOMElement $sourceElement): string => $this->innerHtml($sourceElement),
            fn (DOMElement $sourceElement): string => $this->safeFallbackHtml($sourceElement),
            fn (string $text): string => $this->runtime->escapeHtml($text),
            fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => $this->createBlock($name, $attrs, $innerBlocks, $sourceElement)
        );
        if ( null !== $mathBlock ) {
            return $mathBlock;
        }

        if ( preg_match('/^h([1-6])$/', $tagName, $matches) ) {
            $content = $this->richTextContentWithMaterializedInlineStyles($element);
            if ( $this->richTextRequiresHtmlFallbackWithoutNativeSvgImageObjects($content) ) {
                return $this->createBlock('core/html', array( 'content' => $this->restoreSvgCasing($this->outerHtml($element)) ), array(), $element);
            }
            if ( '' === trim($this->runtime->stripAllTags($content)) ) {
                return null;
            }

            return $this->createBlock('core/heading', array_merge($this->presentationAttributes($element), array(
                'content' => $content,
                'level'   => (int) $matches[1],
            )), array(), $element);
        }

        if ( 'p' === $tagName ) {
            $content = $this->richTextContentWithMaterializedInlineStyles($element);
            $inlineSvgContent = $this->richTextContentWithMaterializedSvgImages($element, $content);
            if ( null !== $inlineSvgContent ) {
                $content = $inlineSvgContent;
            }
            if ( $this->richTextRequiresHtmlFallbackWithoutNativeSvgImageObjects($content) ) {
                return $this->createBlock('core/html', array( 'content' => $this->restoreSvgCasing($this->outerHtml($element)) ), array(), $element);
            }
            if ( $this->hasEmptyVisualInlineChild($element) ) {
                $children = $this->convertChildren($element, $fallbacks, true);
                if ( array() !== $children ) {
                    return $this->createBlock('core/group', $this->presentationAttributes($element), $children, $element);
                }
            }
            if ( '' === trim($this->runtime->stripAllTags($content)) && ! $this->richTextContainsNativeSvgImageObject($content) ) {
                if ( $this->isRuntimeDomTarget($element) ) {
                    return $this->createBlock('core/group', $this->presentationAttributes($element), array(), $element);
                }
                $textBlocks = $this->convertText(trim($element->textContent ?? ''));
                return $textBlocks[0] ?? null;
            }

            return $this->createBlock('core/paragraph', array_merge($this->presentationAttributes($element), array( 'content' => $content )), array(), $element);
        }

        if ( 'address' === $tagName ) {
            $content = $this->richTextContentWithMaterializedInlineStyles($element);
            if ( '' === trim($this->runtime->stripAllTags($content)) ) {
                return null;
            }

            return $this->createBlock('core/paragraph', array_merge($this->presentationAttributes($element), array( 'content' => $content )), array(), $element);
        }

        if ( $this->preserveShellLandmarks && (in_array($tagName, array('header', 'footer'), true) || in_array(strtolower($this->attr($element, 'role')), array('banner', 'contentinfo'), true)) && ('body' === strtolower($element->parentNode?->nodeName ?? '') || $this->hasAncestorTag($element, array('article'))) ) {
            $children = $this->convertChildren($element, $fallbacks, true);
            if ( array() !== $children ) {
                return $this->createBlock('core/group', $this->presentationAttributes($element), $children, $element);
            }
        }

        $mediaDispatch = $this->convertMediaDispatchElement($element, $tagName, $fallbacks);
        if ( $mediaDispatch['handled'] ) {
            return $mediaDispatch['block'];
        }

        if ( $this->isInlineContentElement($tagName) ) {
            if ( $this->isRuntimeDomTarget($element) ) {
                return $this->createBlock('core/html', array( 'content' => $this->outerHtml($element) ), array(), $element);
            }

            $inlineSvgTextGroup = $this->inlineSvgTextGroupBlockFromElement($element);
            if ( null !== $inlineSvgTextGroup ) {
                return $inlineSvgTextGroup;
            }

            if ( $this->hasAuthorSemanticMarker($element) ) {
                $content = $this->innerHtml($element);
                if ( '' !== trim($this->runtime->stripAllTags($content)) ) {
                    return $this->createBlock('core/group', $this->presentationAttributes($element), array(
                        $this->createBlock('core/paragraph', array( 'content' => $content )),
                    ), $element);
                }
            }

            $richTextMarker = $this->richTextMarkerForElement($element);
            if ( '' !== $richTextMarker ) {
                $content = $this->innerHtml($element);
                if ( '' !== trim($this->runtime->stripAllTags($content)) ) {
                    return $this->createBlock('core/paragraph', array(
                        'content' => '<mark style="--blocks-engine-richtext-marker:' . $richTextMarker . '">' . $content . '</mark>',
                    ), array(), $element);
                }
            }

            $dynamicText = $this->dynamicTextContent($element);
            if ( null !== $dynamicText ) {
                return $this->createBlock('core/paragraph', array_merge($this->presentationAttributes($element), array( 'content' => $this->runtime->escapeHtml($dynamicText) )), array(), $element);
            }

            $content = $this->outerHtml($element);
            if ( '' === trim($this->runtime->stripAllTags($content)) ) {
                $children = $this->convertChildren($element, $fallbacks, true);
                if ( 1 === count($children) ) {
                    if ( array() !== $this->presentationAttributes($element) ) {
                        return $this->createBlock('core/group', $this->presentationAttributes($element), $children, $element);
                    }
                    return $children[0];
                }
                if ( array() !== $children ) {
                    return $this->createBlock('core/group', $this->presentationAttributes($element), $children, $element);
                }

                if ( $this->shouldPreserveEmptyVisualElement($element) ) {
                    return $this->createBlock('core/group', $this->presentationAttributes($element), array(), $element);
                }

                return null;
            }

            return $this->createBlock('core/paragraph', array( 'content' => $content ));
        }

        if ( 'ul' === $tagName || 'ol' === $tagName ) {
            $navigation = $this->patternRecognizers->firstMatch($element, $this->patternContext());
            if ( null !== $navigation ) {
                return $this->rememberAccordionDisclosureRoot($navigation, $element);
            }

            if ( $this->isStructuredCardList($element) ) {
                $decomposed = $this->decomposeStructuredCardList($element, $fallbacks);
                if ( null !== $decomposed ) {
                    return $decomposed;
                }
            }

            $items = $this->listItems($element, $fallbacks);

            if ( array() === $items ) {
                return null;
            }

            return $this->createBlock('core/list', array_merge($this->presentationAttributes($element), 'ol' === $tagName ? array( 'ordered' => true ) : array()), $items, $element);
        }

        if ( 'dl' === $tagName ) {
            $items = $this->definitionListItems($element);
            if ( array() !== $items ) {
                return $this->createBlock('core/list', $this->presentationAttributes($element), $items, $element);
            }

            $children = $this->convertChildren($element, $fallbacks, true);
            if ( array() === $children ) {
                return null;
            }

            return $this->createBlock('core/group', $this->presentationAttributes($element), $children, $element);
        }

        if ( 'dt' === $tagName ) {
            $content = $this->richTextContentWithMaterializedInlineStyles($element);
            if ( '' === trim($this->runtime->stripAllTags($content)) ) {
                return null;
            }

            return $this->createBlock('core/paragraph', array_merge($this->presentationAttributes($element), array( 'content' => $content )), array(), $element);
        }

        if ( 'dd' === $tagName ) {
            if ( $this->hasBlockContentChildren($element) ) {
                $children = $this->convertChildren($element, $fallbacks, true);
                if ( array() !== $children ) {
                    return $this->createBlock('core/group', $this->presentationAttributes($element), $children, $element);
                }
            }

            $content = $this->richTextContentWithMaterializedInlineStyles($element);
            if ( '' === trim($this->runtime->stripAllTags($content)) ) {
                return null;
            }

            return $this->createBlock('core/paragraph', array_merge($this->presentationAttributes($element), array( 'content' => $content )), array(), $element);
        }

        if ( 'blockquote' === $tagName ) {
            return $this->quotePattern->matchBlockquote(
                $element,
                $fallbacks,
                fn (DOMElement $sourceElement): string => $this->citationFromElement($sourceElement),
                fn (DOMElement $sourceElement, array $excludedTags): string => $this->innerHtmlWithoutTags($sourceElement, $excludedTags),
                fn (string $html): string => $this->runtime->stripAllTags($html),
                fn (DOMElement $sourceElement, array $excludedGeometryProperties = array()): array => $this->presentationAttributes($sourceElement, $excludedGeometryProperties),
                fn (DOMElement $sourceElement, array &$sourceFallbacks, array $excludedTags): array => $this->convertChildrenWithoutTags($sourceElement, $sourceFallbacks, $excludedTags),
                fn (string $inlineTagName): bool => $this->isInlineContentElement($inlineTagName),
                fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null, ?DOMElement $logicalSourceElement = null): array => $this->createBlock($name, $attrs, $innerBlocks, $sourceElement, $logicalSourceElement)
            );
        }

        if ( 'address' === $tagName ) {
            $content = $this->innerHtml($element);
            if ( '' === trim($this->runtime->stripAllTags($content)) ) {
                return null;
            }

            return $this->createBlock('core/paragraph', array_merge($this->presentationAttributes($element), array( 'content' => $content )), array(), $element);
        }

        if ( 'figure' === $tagName ) {
            $gallery = $this->mediaGalleryBlockFromElement($element);
            if ( null !== $gallery ) {
                return $gallery;
            }

            $codeWindow = $this->codeWindowPattern->match(
                $element,
                fn (DOMElement $sourceElement): array => $this->presentationAttributes($sourceElement),
                fn (DOMElement $sourceElement): string => $this->innerHtml($sourceElement),
                fn (DOMElement $sourcePre, DOMElement $sourceCode): array => $this->codePresentationAttributes($sourcePre, $sourceCode),
                fn (DOMElement $sourceCode): string => $this->codeContent($sourceCode),
                fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => $this->createBlock($name, $attrs, $innerBlocks, $sourceElement)
            );
            if ( null !== $codeWindow ) {
                return $codeWindow;
            }

            $linkedMedia = $this->figureLinkedMediaAnchor($element);
            if ( $linkedMedia instanceof DOMElement ) {
                $linkedPicture = $this->firstChildElement($linkedMedia, 'picture');
                if ( $linkedPicture instanceof DOMElement ) {
                    return $this->convertPictureElement($linkedPicture, $element, $linkedMedia);
                }

                $linkedImage = $this->firstChildElement($linkedMedia, 'img');
                if ( $linkedImage instanceof DOMElement ) {
                    return $this->convertImageElement($linkedImage, $element, null, $linkedMedia);
                }
            }

            $image = $this->figureMediaElement($element, 'img');
            if ( $image instanceof DOMElement ) {
                return $this->convertImageElement($image, $element);
            }

            $picture = $this->figureMediaElement($element, 'picture');
            if ( $picture instanceof DOMElement ) {
                return $this->convertPictureElement($picture, $element);
            }

            $blockquote = $this->firstChildElement($element, 'blockquote');
            if ( $blockquote instanceof DOMElement ) {
                return $this->quotePattern->matchFigureBlockquote(
                    $element,
                    $blockquote,
                    $fallbacks,
                    fn (DOMElement $sourceElement): string => $this->citationFromElement($sourceElement),
                    fn (DOMElement $sourceElement): string => $this->innerHtml($sourceElement),
                    fn (DOMElement $sourceElement, array $excludedTags): string => $this->innerHtmlWithoutTags($sourceElement, $excludedTags),
                    fn (string $html): string => $this->runtime->stripAllTags($html),
                    fn (DOMElement $sourceElement): array => $this->presentationAttributes($sourceElement),
                    fn (DOMElement $sourceElement, array &$sourceFallbacks, array $excludedTags): array => $this->convertChildrenWithoutTags($sourceElement, $sourceFallbacks, $excludedTags),
                    fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => $this->createBlock($name, $attrs, $innerBlocks, $sourceElement)
                );
            }

            return $this->convertFigureGeneric($element, $fallbacks);
        }

        if ( 'figcaption' === $tagName ) {
            $content = $this->innerHtml($element);
            if ( '' === trim($this->runtime->stripAllTags($content)) ) {
                return null;
            }

            return $this->createBlock('core/paragraph', array_merge($this->presentationAttributes($element), array( 'content' => $content )), array(), $element);
        }

        if ( 'noscript' === $tagName ) {
            $children = $this->convertChildren($element, $fallbacks, true);
            if ( array() === $children ) {
                $content = $this->innerHtml($element);
                if ( '' === trim($this->runtime->stripAllTags($content)) ) {
                    return null;
                }

                return $this->createBlock('core/paragraph', array_merge($this->presentationAttributes($element), array( 'content' => $content )), array(), $element);
            }

            if ( 1 === count($children) && array() === $this->presentationAttributes($element) ) {
                return $children[0];
            }

            return $this->createBlock('core/group', $this->presentationAttributes($element), $children, $element);
        }

        if ( 'marquee' === $tagName || 'blink' === $tagName ) {
            if ( $this->hasBlockContentChildren($element) ) {
                $children = $this->convertChildren($element, $fallbacks, true);
                if ( array() === $children ) {
                    return null;
                }

                if ( 1 === count($children) && array() === $this->presentationAttributes($element) ) {
                    return $children[0];
                }

                return $this->createBlock('core/group', $this->presentationAttributes($element), $children, $element);
            }

            $content = $this->innerHtml($element);
            if ( '' === trim($this->runtime->stripAllTags($content)) ) {
                return null;
            }

            return $this->createBlock('core/paragraph', array_merge($this->presentationAttributes($element), array( 'content' => $content )), array(), $element);
        }

        if ( 'label' === $tagName ) {
            return $this->readableFormControlBlockFromElement($element);
        }

        if ( 'pre' === $tagName ) {
            $code = $this->firstChildElement($element, 'code');
            if ( $code instanceof DOMElement ) {
                return $this->createBlock('core/code', array_merge($this->codePresentationAttributes($element, $code), array( 'content' => $this->codeContent($code) )), array(), $element);
            }

            return $this->createBlock('core/preformatted', array_merge($this->presentationAttributes($element), array( 'content' => $this->innerHtmlPreservingWhitespace($element) )), array(), $element);
        }

        if ( 'plaintext' === $tagName ) {
            $content = $this->runtime->escapeHtml($element->textContent ?? '');
            if ( '' === trim($content) ) {
                return null;
            }

            return $this->createBlock('core/preformatted', array_merge($this->presentationAttributes($element), array( 'content' => $content )), array(), $element);
        }

        if ( 'table' === $tagName ) {
            $classification = $this->tableClassificationPolicy->classify($element);
            if ( ! $classification['representable'] ) {
                return $this->createBlock('core/html', array( 'content' => $this->outerHtml($element) ), array(), $element);
            }

            return $this->createBlock('core/table', array_merge($this->presentationAttributes($element), $this->tableAttributes($element)), array(), $element);
        }

        $parameterTable = $this->parameterTablePattern->match(
            $element,
            fn (DOMElement $sourceElement): array => $this->presentationAttributes($sourceElement),
            fn (DOMElement $sourceElement): string => $this->innerHtml($sourceElement),
            fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => $this->createBlock($name, $attrs, $innerBlocks, $sourceElement)
        );
        if ( null !== $parameterTable ) {
            return $parameterTable;
        }

        if ( 'hr' === $tagName ) {
            return $this->createBlock('core/separator', $this->presentationAttributes($element), array(), $element);
        }

        if ( 'br' === $tagName ) {
            return null;
        }

        if ( 'details' === $tagName ) {
            return $this->detailsPattern->match(
                $element,
                $fallbacks,
                fn (DOMElement $sourceElement, array &$sourceFallbacks, array $excludedTags): array => $this->convertChildrenWithoutTags($sourceElement, $sourceFallbacks, $excludedTags),
                fn (DOMElement $sourceElement): array => $this->presentationAttributes($sourceElement),
                fn (DOMElement $sourceElement): string => $this->innerHtml($sourceElement),
                fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => $this->createBlock($name, $attrs, $innerBlocks, $sourceElement)
            );
        }

        if ( 'a' === $tagName ) {
            return $this->convertAnchorDispatchElement($element, $fallbacks);
        }

        if ( 'button' === $tagName ) {
            return $this->convertButtonDispatchElement($element);
        }

        if ( 'svg' === $tagName ) {
            if ( $this->isRuntimeDomTarget($element) ) {
                $html = $this->sanitizeInlineSvgMarkup($element);
                if ( $this->isSafeSvgContent($html) ) {
                    return $this->createBlock('core/html', array( 'content' => $this->restoreSvgCasing($this->ensureInlineSvgBoxStyle($html, $element)) ), array(), $element);
                }
            }

            // Imported inline SVGs are never routed through core/icon: that block
            // is dynamic and keyed on a registered icon slug, not arbitrary SVG.
            // Passive self-contained SVGs can be represented by core/image using
            // a data:image/svg+xml source; the rest stay faithful core/html.
            if ( $this->isSafeDecorativeSvgElement($element) ) {
                // Faithfully preserve any inline SVG that carries real drawable
                // artwork — icons, diagrams, illustrations — even when it is
                // marked aria-hidden / role=presentation. aria-hidden hides the
                // graphic from the accessibility tree; it does NOT mean the
                // artwork is visually disposable. WordPress cannot reconstruct
                // arbitrary vector artwork from CSS, so routing such an SVG into
                // the visual-layer group (empty) or dropping it (return null)
                // silently erased every shape — service icons collapsed to empty
                // blocks and pipe/boiler diagrams to whitespace + comments.
                //
                // The exception is genuine decorative chrome the materialized
                // source CSS recreates: a positioned visual layer (an absolutely
                // positioned full-bleed background) or a stretched-to-fit band
                // (preserveAspectRatio="none", which distorts geometry and so is
                // never used for meaningful icons/diagrams). Those still collapse
                // to a styleable group / are dropped below.
                $isDecorativeChrome = $this->isVisualLayerElement($element)
                    || 'none' === strtolower(trim($this->attr($element, 'preserveaspectratio')));
                if ( ! $isDecorativeChrome && $this->svgHasDrawableContent($element) ) {
                    if ( $this->svgNeedsPhrasingHost($element) ) {
                        $imageMarkup = $this->inlineSvgRichTextImageMarkup($element);
                        if ( null !== $imageMarkup ) {
                            return $this->createBlock('core/paragraph', array( 'content' => $imageMarkup ), array(), $element);
                        }
                    }
                    $svgBlock = $this->inlineSvgBlockFromElement($element);
                    if ( null !== $svgBlock ) {
                        return $svgBlock;
                    }
                }
                if ( $this->isVisualLayerElement($element) ) {
                    return $this->createBlock('core/group', $this->presentationAttributes($element), array(), $element);
                }
                return null;
            }

            if ( $this->svgNeedsPhrasingHost($element) ) {
                $imageMarkup = $this->inlineSvgRichTextImageMarkup($element);
                if ( null !== $imageMarkup ) {
                    return $this->createBlock('core/paragraph', array( 'content' => $imageMarkup ), array(), $element);
                }
            }

            $svgBlock = $this->inlineSvgBlockFromElement($element);
            if ( null !== $svgBlock ) {
                return $svgBlock;
            }

            $this->captureInlineSvgFallback($element, $fallbacks);
            return null;
        }

        if ( 'canvas' === $tagName ) {
            if ( ! $this->isRuntimeCanvasTarget($element) ) {
                return null;
            }

            $this->recordRuntimeIsland($element, 'canvas', 'canvas_requires_runtime', 'canvas_element_and_client_script_execution', array(
                'script_dependency_hint' => 'Scripts may target this canvas and call canvas APIs such as getContext(); preserving the native element keeps the runtime addressable.',
                'required_scripts'        => $this->requiredScriptsForElement($element),
            ));
            return $this->createBlock('core/html', array( 'content' => $this->outerHtml($element) ), array(), $element);
        }

        if ( 'script' === $tagName ) {
            if ( $this->captureStaticScriptMetadata($element) ) {
                return null;
            }

            $this->captureScriptFallback($element, $fallbacks);
            return null;
        }

        if ( 'template' === $tagName ) {
            $this->captureTemplateFallback($element, $fallbacks);
            return null;
        }

        if ( 'form' === $tagName ) {
            return $this->convertFormDispatchElement($element, $fallbacks);
        }

        if ( 'nav' === $tagName ) {
            $navigation = $this->patternRecognizers->firstMatch($element, $this->patternContext(false));
            if ( null !== $navigation ) {
                return $this->rememberAccordionDisclosureRoot($navigation, $element);
            }
        }

        if ( ShellLandmarkPolicy::isFlowContainerTag($tagName) ) {
            if ( $this->shouldPreserveRuntimeAppShell($element) ) {
                $targets = $this->runtimeTargetsInSubtree($element, 8);
                $this->recordRuntimeIsland($element, 'app_shell', 'runtime_app_shell', 'client_script_execution', array(
                    'events'          => $this->eventMetadata($element),
                    'target_count'    => count($targets),
                    'targets'         => $targets,
                    'app_shell_signals' => $this->runtimeAppShellSignals($element),
                    'required_scripts' => $this->requiredScriptsForElement($element),
                ));

                return $this->htmlPreservationBlock($element);
            }

            $this->captureDivBasedPseudoFormFallback($element, $fallbacks);

            $logo = $this->logoPattern->match(
                $element,
                fn (DOMElement $sourceElement): array => $this->presentationAttributes($sourceElement),
                fn (DOMElement $sourceElement): string => $this->innerHtml($sourceElement),
                fn (DOMElement $sourceElement): string => $this->restoreSvgCasing($this->outerHtml($sourceElement)),
                fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => $this->createBlock($name, $attrs, $innerBlocks, $sourceElement)
            );
            if ( null !== $logo ) {
                return $logo;
            }

            $spacer = $this->spacerPattern->match(
                $element,
                fn (DOMElement $sourceElement): int => $this->childElementCount($sourceElement),
                fn (DOMElement $sourceElement, string $name): string => $this->attr($sourceElement, $name),
                fn (DOMElement $sourceElement, string $className): bool => $this->hasClass($sourceElement, $className),
                fn (DOMElement $sourceElement, array $excludedGeometryProperties = array()): array => $this->presentationAttributes($sourceElement, $excludedGeometryProperties),
                fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => $this->createBlock($name, $attrs, $innerBlocks, $sourceElement)
            );
            if ( null !== $spacer ) {
                return $spacer;
            }

            $navigationSection = $this->navigationSectionBlockFromElement($element);
            if ( null !== $navigationSection ) {
                return $navigationSection;
            }

            if ( ! $this->shouldDeferNavigationPatternToChildren($element) ) {
                $navigation = $this->patternRecognizers->firstMatch($element, $this->patternContext());
                if ( null !== $navigation ) {
                    return $this->rememberAccordionDisclosureRoot($navigation, $element);
                }
            }

            if ( in_array($tagName, array( 'div', 'section', 'article' ), true) ) {
                $disclosure = $this->detailsPattern->matchDisclosure(
                    $element,
                    fn (DOMElement $sourceElement): array => $this->convertPatternChildren($sourceElement),
                    fn (DOMElement $sourceElement): array => $this->presentationAttributes($sourceElement),
                    fn (DOMElement $sourceElement): string => $this->innerHtml($sourceElement),
                    fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => $this->createBlock($name, $attrs, $innerBlocks, $sourceElement)
                );
                if ( null !== $disclosure ) {
                    $this->nativeDisclosureRootIds[ $element->getNodePath() ?? '' ] = true;

                    return $disclosure;
                }
            }

            $columns = $this->columnsPattern->match(
                $element,
                $fallbacks,
                fn (DOMElement $sourceElement, array &$sourceFallbacks, bool $captureUnsupported): array => $this->convertChildren($sourceElement, $sourceFallbacks, $captureUnsupported),
                fn (DOMElement $sourceElement, array &$sourceFallbacks, bool $captureUnsupported): ?array => $this->convertElement($sourceElement, $sourceFallbacks, $captureUnsupported),
                fn (DOMElement $sourceElement): array => $this->presentationAttributes($sourceElement),
                fn (DOMElement $sourceElement): string => $this->mergedPresentationStyle($sourceElement),
                fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => $this->createBlock($name, $attrs, $innerBlocks, $sourceElement)
            );
            if ( null !== $columns ) {
                return $columns;
            }

            $gallery = $this->mediaGalleryBlockFromElement($element);
            if ( null !== $gallery ) {
                return $gallery;
            }

            $codeWindow = $this->codeWindowPattern->match(
                $element,
                fn (DOMElement $sourceElement): array => $this->presentationAttributes($sourceElement),
                fn (DOMElement $sourceElement): string => $this->innerHtml($sourceElement),
                fn (DOMElement $sourcePre, DOMElement $sourceCode): array => $this->codePresentationAttributes($sourcePre, $sourceCode),
                fn (DOMElement $sourceCode): string => $this->codeContent($sourceCode),
                fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => $this->createBlock($name, $attrs, $innerBlocks, $sourceElement)
            );
            if ( null !== $codeWindow ) {
                return $codeWindow;
            }

            $namePriceRow = $this->namePriceRowBlockFromElement($element, $fallbacks);
            if ( null !== $namePriceRow ) {
                return $namePriceRow;
            }

            $inlineTokenGroup = $this->inlineTokenGroupBlockFromElement($element, $fallbacks);
            if ( null !== $inlineTokenGroup ) {
                return $inlineTokenGroup;
            }

            $visualTextWrapper = $this->visualTextWrapperBlockFromElement($element);
            if ( null !== $visualTextWrapper ) {
                return $visualTextWrapper;
            }

            $inlineContent = $this->paragraphBlockFromInlineContentWrapper($element);
            if ( null !== $inlineContent ) {
                return $inlineContent;
            }

            $standaloneSearch = $this->searchBlockFromStandaloneControl($element);
            if ( null !== $standaloneSearch ) {
                return $standaloneSearch;
            }

            $buttons = $this->buttonsPattern->matchContainer(
                $element,
                fn (DOMElement $sourceElement): array => $this->presentationAttributes($sourceElement),
                fn (DOMElement $sourceElement): string => $this->resolveCssVariablesInValue($this->mergedPresentationStyle($sourceElement)),
                fn (DOMElement $sourceElement): string => $this->innerHtml($sourceElement),
                fn (DOMElement $sourceElement, string $name): string => $this->attr($sourceElement, $name),
                fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null, ?DOMElement $logicalSourceElement = null): array => $this->createBlock($name, $attrs, $innerBlocks, $sourceElement, $logicalSourceElement)
            );
            if ( null !== $buttons ) {
                return $buttons;
            }

            $textFlow = $this->textFlowBlockFromElement($element);
            if ( null !== $textFlow ) {
                return $textFlow;
            }

            $children = $this->convertChildren($element, $fallbacks, true);
            $backgroundImage = $this->backgroundImageBlockFromElement($element);
            if ( null !== $backgroundImage && ! $this->hasDirectMediaChild($element) ) {
                array_unshift($children, $backgroundImage);
            }
            if ( 1 === count($children) ) {
                if ( $this->shouldPreserveWrapper($element) ) {
                    return $this->createBlock('core/group', $this->presentationAttributes($element), $children, $element);
                }
                return $children[0];
            }
            if ( array() !== $children ) {
                return $this->createBlock('core/group', $this->presentationAttributes($element), $children, $element);
            }
            if ( $this->shouldPreserveEmptyVisualElement($element) ) {
                return $this->createBlock('core/group', $this->presentationAttributes($element), array(), $element);
            }
            return null;
        }

        $readableControlBlock = $this->readableFormControlBlockFromElement($element);
        if ( null !== $readableControlBlock ) {
            return $readableControlBlock;
        }

        if ( $this->preserveStandaloneFormControlAsRuntimeIsland($element) ) {
            return null;
        }

        if ( $captureUnsupported ) {
            // Producer link (issue #497): this is a core/html fallback decision —
            // the element mapped to nothing native/Automattic. If the structural
            // classifier identifies it as a high-confidence custom_block, generate
            // a dynamic block and emit a self-closing reference instead of raw
            // core/html. Otherwise keep the existing fallback diagnostic.
            $generated = $this->fallbackEmitter->maybeGenerateCustomBlock($element, $this->generatedBlocks, $this->generatedBlockNamespace);
            if ( null !== $generated ) {
                return $this->createBlock($generated['blockName'], $generated['attrs'], array(), $element);
            }

            $fallback = array(
                'type'            => 'unsupported_element',
                'reason'          => 'unsupported_element',
                'diagnostic_code' => 'html_unsupported_element',
                'source_format'   => 'html',
                'tag'             => $tagName,
                'selector'        => $this->elementSelector($element),
                'attributes'      => $this->htmlAttributes($element),
                'context'         => $this->sourceContext($element),
                'classification'  => $this->fallbackEmitter->classifyFallbackSubtree($element),
                'events'          => $this->eventMetadata($element),
                'text_length'     => strlen(trim($element->textContent ?? '')),
                'child_count'     => $this->childElementCount($element),
                'html'            => $this->safeFallbackHtml($element),
            );

            $control = $this->formControlMetadata($element);
            if ( array() !== $control ) {
                $fallback['control'] = $control;
            }

            $fallbacks[] = FallbackDiagnostic::build($fallback, $this->fallbackProvenance);
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array{handled: bool, block: array<string, mixed>|null}
     */
    private function convertMediaDispatchElement(DOMElement $element, string $tagName, array &$fallbacks): array
    {
        $placeholderMedia = $this->placeholderMediaPattern->match(
            $element,
            fn (DOMElement $sourceElement): array => $this->presentationAttributes($sourceElement),
            fn (string $value): string => $this->runtime->escapeHtml($value),
            fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => $this->createBlock($name, $attrs, $innerBlocks, $sourceElement)
        );
        if ( null !== $placeholderMedia ) {
            return array( 'handled' => true, 'block' => $placeholderMedia );
        }

        if ( 'img' === $tagName ) {
            return array( 'handled' => true, 'block' => $this->convertImageElement($element) );
        }

        if ( 'picture' === $tagName ) {
            return array( 'handled' => true, 'block' => $this->convertPictureElement($element) );
        }

        if ( 'iframe' === $tagName ) {
            return array( 'handled' => true, 'block' => $this->convertIframeElement($element, $fallbacks) );
        }

        if ( in_array($tagName, array( 'audio', 'video' ), true) ) {
            return array( 'handled' => true, 'block' => $this->convertMediaElement($element) );
        }

        if ( 'a' === $tagName ) {
            $linkedImage = $this->imageBlockFromAnchor($element);
            if ( null !== $linkedImage ) {
                return array( 'handled' => true, 'block' => $linkedImage );
            }
        }

        return array( 'handled' => false, 'block' => null );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mediaGalleryBlockFromElement(DOMElement $element): ?array
    {
        return $this->galleryPattern->match(
            $element,
            fn (DOMElement $image, ?DOMElement $figure = null, ?DOMElement $picture = null, ?DOMElement $link = null): ?array => $this->convertImageElement($image, $figure, $picture, $link),
            fn (DOMElement $picture, ?DOMElement $figure = null, ?DOMElement $link = null): ?array => $this->convertPictureElement($picture, $figure, $link),
            fn (DOMElement $figure): ?DOMElement => $this->figureLinkedMediaAnchor($figure),
            fn (DOMElement $sourceElement): array => $this->presentationAttributes($sourceElement),
            fn (DOMElement $sourceElement): string => $this->innerHtml($sourceElement),
            fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => $this->createBlock($name, $attrs, $innerBlocks, $sourceElement)
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function convertText(string $text): array
    {
        $blocks = array();
        if ( $this->runtime->isShortcodeOnly($text) ) {
            $blocks[] = $this->createBlock('core/shortcode', array( 'text' => $this->runtime->preserveShortcodeText($text) ));
            return $blocks;
        }

        $blocks[] = $this->createBlock('core/paragraph', array( 'content' => $this->runtime->escapeHtml($text) ));
        return $blocks;
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<int, array<string, mixed>> $innerBlocks
     * @return array<string, mixed>
     */
    private function createBlock(string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null, ?DOMElement $logicalSourceElement = null): array
    {
        $attrs = $this->hoistContentWrappingSpans($name, $attrs);
        if ( $sourceElement instanceof DOMElement && in_array($name, array( 'core/paragraph', 'core/heading' ), true) ) {
            $textAlign = strtolower(trim((string) ($this->presentationDeclarations($sourceElement)['text-align'] ?? '')));
            if ( in_array($textAlign, array( 'left', 'center', 'right' ), true) ) {
                $attrs['align'] = $textAlign;
            }
        }

        if ( $sourceElement instanceof DOMElement && in_array($name, array( 'core/paragraph', 'core/heading' ), true) && $this->richTextRequiresHtmlFallbackWithoutNativeSvgImageObjects((string) ($attrs['content'] ?? '')) ) {
            $attrs['content'] = $this->stripDecorativeSvgFromRichText((string) ($attrs['content'] ?? ''));
            if ( $this->richTextRequiresHtmlFallbackWithoutNativeSvgImageObjects((string) ($attrs['content'] ?? '')) ) {
                return $this->blockFactory->create('core/html', array( 'content' => $this->restoreSvgCasing($this->outerHtml($sourceElement)) ));
            }
        }

        if ( $sourceElement instanceof DOMElement ) {
            $sourceTagName = strtolower($sourceElement->tagName);
            if ( isset($this->sourceTagMarkers[$sourceTagName]) ) {
                $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), $this->sourceTagMarkers[$sourceTagName]);
            }
            $semanticMarkers = $this->authorSemanticMarkersForElement($sourceElement);
            if ( array() !== $semanticMarkers ) {
                $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), ...$semanticMarkers);
            }
            $logicalControl = $logicalSourceElement ?? $sourceElement;
            if ( in_array($name, array( 'core/button', 'core/buttons' ), true) && in_array(strtolower($logicalControl->tagName), array( 'a', 'button' ), true) && ( isset($this->sourceControlPaths[$logicalControl->getNodePath() ?? '']) || ( '' !== $this->combinedAuthorCss && 'a' === strtolower($logicalControl->tagName) && ( '' !== trim($this->attr($logicalControl, 'class')) || '' !== trim($this->attr($logicalControl, 'id')) ) ) ) ) {
                $path = $logicalControl->getNodePath() ?? '';
                if ( '' !== $path && ! isset($this->sourceControlMarkers[$path]) ) {
                    $this->sourceControlMarkers[$path] = $this->allocateAuthorMarker('control');
                }
                if ( isset($this->sourceControlMarkers[$path]) ) {
                    $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), $this->sourceControlMarkers[$path]);
                }
            }
            $provenanceId = $this->nextSourceProvenanceId++;
            $this->recordPresentationProvenance($name, $attrs, $sourceElement);
            $this->recordStructureProvenance($name, $attrs, $sourceElement);
            if ( $this->isRuntimeDomTarget($sourceElement) && ! $this->isFormControlElement($sourceElement) && ! in_array($sourceTagName, array( 'canvas', 'form', 'script' ), true) ) {
                $this->recordRuntimeIsland($sourceElement, 'dom', 'runtime_dom_target', 'client_script_execution', array(
                    'events'          => $this->eventMetadata($sourceElement),
                    'required_scripts' => $this->requiredScriptsForElement($sourceElement),
                ));
            }
            $this->sourceProvenance[$provenanceId] = $this->sourceProvenanceEntry($name, $sourceElement);
        }

        if ( 'core/group' === $name && $sourceElement instanceof DOMElement && ! isset($attrs['tagName']) ) {
            $semanticTag = $this->semanticGroupTagName($sourceElement);
            if ( null !== $semanticTag ) {
                $attrs['tagName'] = $semanticTag;
            }
        }

        $block = $this->blockFactory->create($name, $attrs, $innerBlocks);
        if ( isset($provenanceId) ) {
            $block['_source_provenance_id'] = $provenanceId;
        }

        return $block;
    }

    private function hasAuthorSemanticMarker(DOMElement $element): bool
    {
        return array() !== $this->authorSemanticMarkersForElement($element);
    }

    /** @return list<string> */
    private function authorSemanticMarkersForElement(DOMElement $element): array
    {
        $markers = array();
        $path = $this->sourceElementIdentity($element);
        if ( 'span' === strtolower($element->tagName) && isset($this->sourceSemanticMarkers[$path]) ) {
            $markers[] = $this->sourceSemanticMarkers[$path];
        }
        if ( isset($this->sourceRootChildMarkers[$path]) ) {
            $markers[] = $this->sourceRootChildMarkers[$path];
        }
        return $markers;
    }

    private function requiresIndependentSemanticWrapper(DOMElement $element): bool
    {
        if ( 'span' !== strtolower($element->tagName) || $this->isRichTextInlineContext($element) ) {
            return false;
        }

        $parent = $element->parentNode instanceof DOMElement ? $element->parentNode : null;
        if ( ! $parent instanceof DOMElement || ! $this->isStructuralLayoutElement($parent) ) {
            return false;
        }

        $declarations = array_merge($this->presentationDeclarations($element), $this->authorSemanticDeclarations($element));
        $display = strtolower(trim((string) ($declarations['display'] ?? 'inline')));
        if ( ! in_array($display, array( '', 'inline', 'inherit', 'initial', 'unset' ), true) ) {
            return true;
        }

        foreach ( array( 'font-size', 'line-height', 'letter-spacing', 'text-transform', 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'border', 'border-width', 'border-color', 'border-radius', 'margin', 'width', 'height', 'min-width', 'min-height', 'max-width', 'max-height' ) as $property ) {
            if ( '' !== trim((string) ($declarations[$property] ?? '')) ) {
                return true;
            }
        }

        return false;
    }

    private function isStructuralLayoutElement(DOMElement $element): bool
    {
        $declarations = array_merge($this->presentationDeclarations($element), $this->authorSemanticDeclarations($element));
        return in_array(strtolower(trim((string) ($declarations['display'] ?? ''))), array( 'flex', 'inline-flex', 'grid', 'inline-grid' ), true);
    }

    /** @param array<string, mixed> $parsed */
    private function richTextSelectorNeedsHook(array $parsed): bool
    {
        foreach ( $parsed['compounds'] as $compound ) {
            if ( array() !== $compound['classes'] || array() !== $compound['ids'] || array() !== $compound['attributes'] ) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> */
    private function authorSemanticDeclarations(DOMElement $element): array
    {
        $declarations = array();
        foreach ( $this->staticStyleRules as $rule ) {
            $parsed = CssSelectorMatcher::parse($rule['selector']);
            if ( $parsed['supported'] && CssSelectorMatcher::matches($element, $parsed, true)['matches'] ) {
                $declarations = array_merge($declarations, $rule['declarations']);
            }
        }

        return $declarations;
    }

    private function isRichTextInlineContext(DOMElement $element): bool
    {
        for ( $parent = $element->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
            if ( in_array(strtolower($parent->tagName), array( 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li' ), true) ) {
                return true;
            }
        }

        return false;
    }

    private function sourceElementIdentity(DOMElement $element): string
    {
        return $element->getNodePath() ?? '';
    }

    private function richTextMarkerForElement(DOMElement $element): string
    {
        $marker = trim($this->attr($element, 'data-blocks-engine-richtext-marker'));
        if ( '' !== $marker ) {
            return $marker;
        }

        return $this->sourceRichTextSemanticMarkers[$this->sourceElementIdentity($element)] ?? '';
    }

    /**
     * Lift class/style styling hooks out of a paragraph/heading/list-item's
     * RichText `content` so the stored block round-trips through RichText
     * unchanged.
     *
     * core/paragraph, core/heading, and core/list-item store `content` as
     * RichText, which only preserves a fixed set of inline formats (a, strong,
     * em, br, …). A `<span class="…">` / `<span style="…">` is not a format, so
     * RichText drops its attributes on parse: the saved markup no longer matches
     * the re-serialized block ("unexpected or invalid content"), and the class —
     * a styling hook the materialized CSS targets — would be silently lost.
     *
     * The fix keys off STRUCTURE (a content-bearing span carrying only
     * class/style), never on any specific class name:
     *   - A SINGLE styling-hook span wrapping the ENTIRE content is UNWRAPPED and
     *     its class/style are HOISTED onto the block (merged into `className` and
     *     the canonical `style` object). The hook survives where RichText does
     *     preserve it and the inner text/inline-format becomes valid content.
     *     Nested wrappers are peeled across iterations.
     *   - Remaining sibling/partial styling-hook spans are UNWRAPPED to their
     *     inner content. Their per-span class styling cannot ride valid RichText
     *     here, so this is best-effort; the emitted block is always valid.
     * Genuine inline formats (strong/em/a/br/…) are kept, but arbitrary
     * class/style hooks on links are moved to the block wrapper when the link is
     * the sole content wrapper, or dropped when they are partial-content hooks.
     * RichText's link format round-trips href/target/rel, not source CSS hooks.
     *
     * A list item whose content carries block-level children (an image/heading/
     * paragraph "card", e.g. a commerce product grid) is left untouched here:
     * that is not flowing RichText, so it stays the job of the structured-card
     * decomposition path and the commerce path rather than per-span unwrapping.
     *
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function hoistContentWrappingSpans(string $name, array $attrs): array
    {
        if ( ! in_array($name, array( 'core/paragraph', 'core/heading', 'core/list-item' ), true) ) {
            return $attrs;
        }

        $content = (string) ($attrs['content'] ?? '');
        if ( '' === $content || ! preg_match('/<(?:span|a|em|i|strong|b|mark|small|sub|sup)\b/i', $content) ) {
            return $attrs;
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded   = $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $content . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $loaded ? $document->getElementsByTagName('body')->item(0) : null;
        if ( ! $body instanceof DOMElement ) {
            return $attrs;
        }

        if ( 'core/list-item' === $name && $this->hasBlockContentChildren($body) ) {
            return $attrs;
        }

        $hoistedClasses      = '';
        $hoistedDeclarations = array();

        // Peel a single styling-hook span wrapping the whole content, hoisting it
        // onto the block. Nested wrappers are peeled across iterations.
        while ( ( $wrapper = $this->soleStylingHookSpan($body) ) instanceof DOMElement ) {
            $hoistedClasses = trim($hoistedClasses . ' ' . $this->attr($wrapper, 'class'));
            $wrapperStyle   = trim($this->attr($wrapper, 'style'));
            if ( '' !== $wrapperStyle ) {
                $hoistedDeclarations = array_merge($hoistedDeclarations, $this->cssDeclarations($wrapperStyle));
            }
            $this->unwrapElement($wrapper);
        }

        $soleAnchor = $this->soleRichTextAnchor($body);
        if ( $soleAnchor instanceof DOMElement ) {
            $hoistedClasses = trim($hoistedClasses . ' ' . $this->attr($soleAnchor, 'class'));
            $anchorStyle    = trim($this->attr($soleAnchor, 'style'));
            if ( '' !== $anchorStyle ) {
                $hoistedDeclarations = array_merge($hoistedDeclarations, $this->cssDeclarations($anchorStyle));
            }
        }

        // Unwrap any remaining styling hooks (sibling / partial content) unless
        // their visual style can be carried by RichText's mark format.
        foreach ( $this->richTextStylingHookElements($body) as $inline ) {
            if ( $this->replaceRichTextStylingHookWithMark($inline) ) {
                continue;
            }
            if ( 'span' === strtolower($inline->tagName) ) {
                $this->unwrapElement($inline);
            }
        }

        foreach ( $this->richTextAnchors($body) as $anchor ) {
            $anchor->removeAttribute('class');
            $anchor->removeAttribute('style');
        }

        $newContent = $this->innerHtml($body);
        if ( $newContent === $content && '' === $hoistedClasses && array() === $hoistedDeclarations ) {
            return $attrs;
        }

        $attrs['content'] = $newContent;

        if ( '' !== $hoistedClasses ) {
            $promoted = $this->promotedClassName($hoistedClasses);
            if ( '' !== trim($promoted) ) {
                $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), $promoted);
            }
        }

        if ( array() !== $hoistedDeclarations ) {
            $mapped = $this->styleAttributeMapper()->map($hoistedDeclarations)['style'];
            if ( array() !== $mapped ) {
                $existing       = is_array($attrs['style'] ?? null) ? $attrs['style'] : array();
                $attrs['style'] = array_replace_recursive($mapped, $existing);
            }
        }

        return $attrs;
    }

    /**
     * The single styling-hook span that is the container's only significant
     * child, or null when the content is plain text, inline formats, or sibling
     * spans (which must not be hoisted as one block-level styling hook).
     */
    private function soleStylingHookSpan(DOMElement $container): ?DOMElement
    {
        $only = null;
        foreach ( $container->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }
            if ( null !== $only ) {
                return null;
            }
            $only = $child;
        }

        return $only instanceof DOMElement && $this->isStylingHookSpan($only) ? $only : null;
    }

    private function soleRichTextAnchor(DOMElement $container): ?DOMElement
    {
        $only = null;
        foreach ( $container->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }
            if ( null !== $only ) {
                return null;
            }
            $only = $child;
        }

        return $only instanceof DOMElement && 'a' === strtolower($only->tagName) ? $only : null;
    }

    /**
     * A `<span>` whose only attributes are class and/or style (at least one
     * non-empty). These are presentational styling hooks RichText cannot store,
     * not semantic spans (a span carrying id, data-, or role is left intact).
     */
    private function isStylingHookSpan(DOMElement $element): bool
    {
        if ( 'span' !== strtolower($element->tagName) ) {
            return false;
        }

        $hasStyling = false;
        foreach ( $element->attributes ?? array() as $attribute ) {
            $attributeName = strtolower($attribute->nodeName);
            if ( ! in_array($attributeName, array( 'class', 'style', 'data-blocks-engine-richtext-marker' ), true) ) {
                return false;
            }
            if ( '' !== trim($attribute->nodeValue ?? '') ) {
                $hasStyling = true;
            }
        }

        if ( $hasStyling ) {
            return true;
        }

        foreach ( $this->staticStyleRules as $rule ) {
            if ( $this->matchesCssSelector($element, $rule['selector']) ) {
                return true;
            }
        }

        return false;
    }

    private function isRichTextInlineStylingHookElement(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( 'span' === $tagName ) {
            return $this->isStylingHookSpan($element);
        }

        if ( ! in_array($tagName, array( 'em', 'i', 'strong', 'b', 'mark', 'small', 'sub', 'sup' ), true) ) {
            return false;
        }

        $hasStyling = false;
        foreach ( $element->attributes ?? array() as $attribute ) {
            $attributeName = strtolower($attribute->nodeName);
            if ( 'class' !== $attributeName && 'style' !== $attributeName ) {
                return false;
            }
            if ( '' !== trim($attribute->nodeValue ?? '') ) {
                $hasStyling = true;
            }
        }

        return $hasStyling;
    }

    /**
     * @return array<int, DOMElement>
     */
    private function stylingHookSpans(DOMElement $container): array
    {
        $spans = array();
        foreach ( $container->getElementsByTagName('span') as $span ) {
            if ( $span instanceof DOMElement && $this->isStylingHookSpan($span) ) {
                $spans[] = $span;
            }
        }

        return $spans;
    }

    /**
     * @return array<int, DOMElement>
     */
    private function richTextStylingHookElements(DOMElement $container): array
    {
        $elements = array();
        foreach ( $container->getElementsByTagName('*') as $element ) {
            if ( $element instanceof DOMElement && $this->isRichTextInlineStylingHookElement($element) ) {
                $elements[] = $element;
            }
        }

        return $elements;
    }

    /**
     * @return array<int, DOMElement>
     */
    private function richTextAnchors(DOMElement $container): array
    {
        $anchors = array();
        foreach ( $container->getElementsByTagName('a') as $anchor ) {
            if ( $anchor instanceof DOMElement && ( $anchor->hasAttribute('class') || $anchor->hasAttribute('style') ) ) {
                $anchors[] = $anchor;
            }
        }

        return $anchors;
    }

    private function richTextRequiresHtmlFallback(string $content): bool
    {
        return (bool) preg_match('/<(?:svg|canvas|img|picture|video|audio|iframe|object|embed|input|button|select|textarea|form)\b/i', $content);
    }

    private function richTextContentWithMaterializedInlineStyles(DOMElement $element): string
    {
        $content = $this->innerHtml($element);
        if ( '' === $content || ! preg_match('/<(?:span|em|i|strong|b|mark|small|sub|sup)\b/i', $content) ) {
            return $content;
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded   = $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $content . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $loaded ? $document->getElementsByTagName('body')->item(0) : null;
        if ( ! $body instanceof DOMElement ) {
            return $content;
        }

        $sourceInlines = array();
        foreach ( $element->getElementsByTagName('*') as $sourceInline ) {
            if ( $sourceInline instanceof DOMElement && in_array(strtolower($sourceInline->tagName), array( 'span', 'em', 'i', 'strong', 'b', 'mark', 'small', 'sub', 'sup' ), true) ) {
                $sourceInlines[] = $sourceInline;
            }
        }

        $targetInlines = array();
        foreach ( $body->getElementsByTagName('*') as $targetInline ) {
            if ( $targetInline instanceof DOMElement && in_array(strtolower($targetInline->tagName), array( 'span', 'em', 'i', 'strong', 'b', 'mark', 'small', 'sub', 'sup' ), true) ) {
                $targetInlines[] = $targetInline;
            }
        }

        foreach ( $targetInlines as $index => $targetInline ) {
            $sourceInline = $sourceInlines[$index] ?? null;
            if ( ! $sourceInline instanceof DOMElement ) {
                continue;
            }

            $inline = $this->richTextInlineVisualDeclarations($sourceInline);
            $marker = $this->richTextMarkerForElement($sourceInline);
            if ( '' !== $marker ) {
                $inline['--blocks-engine-richtext-marker'] = $marker;
            }
            if ( array() === $inline ) {
                continue;
            }

            $existing = $this->cssDeclarations($this->attr($targetInline, 'style'));
            $targetInline->setAttribute('style', $this->cssDeclarationString(array_merge($inline, $existing)));
        }

        return $this->innerHtml($body);
    }

    /**
     * @return array<string, string>
     */
    private function richTextInlineVisualDeclarations(DOMElement $element): array
    {
        $allowed = array_flip(array(
            'background-color',
            'color',
            'display',
            'font-family',
            'font-size',
            'font-style',
            'font-weight',
            'letter-spacing',
            'line-height',
            'text-decoration',
            'text-transform',
        ));

        $declarations = array();
        foreach ( $this->staticStyleRules as $rule ) {
            if ( $this->matchesCssSelector($element, $rule['selector']) ) {
                $declarations = array_merge($declarations, $rule['declarations']);
            }
        }
        $declarations = array_merge($declarations, $this->cssDeclarations($this->attr($element, 'style')));

        return array_intersect_key($declarations, $allowed);
    }

    private function replaceRichTextStylingHookWithMark(DOMElement $element): bool
    {
        if ( $element->getElementsByTagName('mark')->length > 0 ) {
            return false;
        }

        $declarations = $this->richTextInlineVisualDeclarations($element);
        $existingDeclarations = $this->cssDeclarations($this->attr($element, 'style'));
        $marker = trim((string) ($existingDeclarations['--blocks-engine-richtext-marker'] ?? ''));
        if ( '' === $marker && array() === $declarations ) {
            return false;
        }

        if ( '' !== $marker ) {
            $declarations['--blocks-engine-richtext-marker'] = $marker;
        }

        if ( '' === $marker && ! isset($declarations['background-color']) ) {
            $declarations['background-color'] = 'transparent';
        }
        if ( '' === $marker && ! isset($declarations['color']) ) {
            $declarations['color'] = 'inherit';
        }

        $document = $element->ownerDocument;
        if ( ! $document instanceof DOMDocument ) {
            return false;
        }

        $mark = $document->createElement('mark');
        $mark->setAttribute('style', $this->cssDeclarationString($declarations));
        while ( null !== $element->firstChild ) {
            $mark->appendChild($element->firstChild);
        }

        $parent = $element->parentNode;
        if ( ! $parent instanceof DOMNode ) {
            return false;
        }

        if ( in_array(strtolower($element->tagName), array( 'span', 'mark' ), true) ) {
            $parent->replaceChild($mark, $element);
            return true;
        }

        $element->removeAttribute('class');
        $element->removeAttribute('style');
        $element->appendChild($mark);
        return true;
    }

    /**
     * Replace an element with its children in place, dropping only the wrapper.
     */
    private function unwrapElement(DOMElement $element): void
    {
        $parent = $element->parentNode;
        if ( ! $parent instanceof DOMNode ) {
            return;
        }

        while ( null !== $element->firstChild ) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    private function semanticGroupTagName(DOMElement $element): ?string
    {
        $tag = strtolower($element->tagName);
        if ( ShellLandmarkPolicy::isSemanticGroupTag($tag) ) {
            return $tag;
        }

        $landmark = ShellLandmarkPolicy::landmarkKind($tag, $this->attr($element, 'role'));
        return in_array($landmark, array('header', 'footer'), true) ? $landmark : null;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    private function sourceProvenanceForBlocks(array &$blocks): array
    {
        $resolved = array();
        $this->resolveSourceProvenancePaths($blocks, 'blocks', $resolved);
        return $resolved;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $resolved
     */
    private function resolveSourceProvenancePaths(array &$blocks, string $path, array &$resolved): void
    {
        foreach ( $blocks as $index => &$block ) {
            $blockPath = $path . '.' . $index;
            $provenanceId = $block['_source_provenance_id'] ?? null;
            if ( is_int($provenanceId) && isset($this->sourceProvenance[$provenanceId]) ) {
                $resolved[] = array_merge(array( 'block_path' => $blockPath ), $this->sourceProvenance[$provenanceId]);
            }
            unset($block['_source_provenance_id']);

            if ( ! empty($block['innerBlocks']) && is_array($block['innerBlocks']) ) {
                $this->resolveSourceProvenancePaths($block['innerBlocks'], $blockPath . '.innerBlocks', $resolved);
            }
        }
        unset($block);
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceProvenanceEntry(string $blockName, DOMElement $element): array
    {
        return array_merge(array(
            'block_name'        => $blockName,
            'tag'               => strtolower($element->tagName),
            'selector'          => $this->elementSelector($element),
            'source_attributes' => $this->safeSourceAttributes($element),
            'source_fragment'   => $this->safeSourceFragment($element),
            'context'           => $this->sourceContext($element),
        ), $this->sourceConversionMetadata($blockName, $element));
    }

    /**
     * @return array{conversion_classification: string, preservation_strategy: string}
     */
    private function sourceConversionMetadata(string $blockName, DOMElement $element): array
    {
        $tagName = strtolower($element->tagName);

        if ( 'core/html' === $blockName ) {
            return array(
                'conversion_classification' => 'runtime_island_preserved',
                'preservation_strategy'     => 'bounded_raw_html_runtime_island',
            );
        }

        if ( $this->isRuntimeDomTarget($element) ) {
            return array(
                'conversion_classification' => 'runtime_island_preserved',
                'preservation_strategy'     => 'core_block_shell_with_runtime_target',
            );
        }

        if ( in_array($tagName, array('form', 'input', 'select', 'textarea'), true) && 'core/search' !== $blockName ) {
            return array(
                'conversion_classification' => 'editable_approximation',
                'preservation_strategy'     => 'readable_static_block_approximation',
            );
        }

        return array(
            'conversion_classification' => 'native_block_conversion',
            'preservation_strategy'     => 'core_block',
        );
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function detectStaticClassPromotions(string $html): array
    {
        if ( ! str_contains($html, 'classList.add') || ! str_contains($html, 'querySelectorAll') ) {
            return array();
        }

        if ( ! str_contains($html, 'IntersectionObserver') && ! str_contains($html, 'isIntersecting') ) {
            return array();
        }

        preg_match_all('/querySelectorAll\s*\(\s*([\'"`])\.([A-Za-z0-9_-]+)\1\s*\)/', $html, $selectorMatches);
        preg_match_all('/classList\.add\s*\(([^)]*)\)/', $html, $addMatches);

        $triggerClasses = array_values(array_unique($selectorMatches[2] ?? array()));
        $terminalClasses = array();
        foreach ( $addMatches[1] ?? array() as $args ) {
            preg_match_all('/[\'"`]([A-Za-z0-9_-]+)[\'"`]/', (string) $args, $classMatches);
            foreach ( $classMatches[1] ?? array() as $className ) {
                $terminalClasses[] = $className;
            }
        }

        $terminalClasses = array_values(array_unique($terminalClasses));
        if ( array() === $triggerClasses || array() === $terminalClasses ) {
            return array();
        }

        $promotions = array();
        foreach ( array_slice($triggerClasses, 0, 20) as $triggerClass ) {
            $promotions[$triggerClass] = array_values(array_diff(array_slice($terminalClasses, 0, 20), array( $triggerClass )));
        }

        return array_filter($promotions, static fn (array $classes): bool => array() !== $classes);
    }

    private function promotedClassName(string $className): string
    {
        if ( '' === trim($className) || array() === $this->staticClassPromotions ) {
            return $this->presentationClassName($className);
        }

        $classes = preg_split('/\s+/', trim($className)) ?: array();
        foreach ( $classes as $class ) {
            foreach ( $this->staticClassPromotions[$class] ?? array() as $terminalClass ) {
                if ( ! in_array($terminalClass, $classes, true) ) {
                    $classes[] = $terminalClass;
                }
            }
        }

        return $this->presentationClassName(implode(' ', $classes));
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function recordPresentationProvenance(string $blockName, array $attrs, DOMElement $element): void
    {
        $signals = array_intersect_key($attrs, array_flip(array( 'className', 'style', 'layout' )));
        $signals = array_filter($signals, static fn ($value): bool => is_array($value) ? array() !== $value : '' !== trim((string) $value));
        if ( array() === $signals ) {
            return;
        }

        $this->presentationProvenance[] = array(
            'block_name'        => $blockName,
            'tag'               => strtolower($element->tagName),
            'selector'          => $this->elementSelector($element),
            'signals'           => $signals,
            'source_attributes' => array_intersect_key($this->htmlAttributes($element), array_flip(array( 'class', 'style', 'data-layout', 'data-wp-layout' ))),
        );
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function recordStructureProvenance(string $blockName, array $attrs, DOMElement $element): void
    {
        $signals = $this->structureSignals($element, $attrs);
        if ( array() === $signals ) {
            return;
        }

        $this->structureProvenance[] = array(
            'block_name'        => $blockName,
            'tag'               => strtolower($element->tagName),
            'selector'          => $this->elementSelector($element),
            'signals'           => $signals,
            'source_attributes' => array_intersect_key($this->htmlAttributes($element), array_flip(array( 'class', 'id', 'role', 'style', 'data-layout', 'data-wp-layout' ))),
        );
    }

    private function shouldPreserveWrapper(DOMElement $element): bool
    {
        return ShellLandmarkPolicy::isWrapperPreservingTag($element->tagName) && ( $this->isRuntimeDomTarget($element) || array() !== $this->presentationAttributes($element) || array() !== $this->structureSignals($element, array()) );
    }

    private function shouldDeferNavigationPatternToChildren(DOMElement $element): bool
    {
        if ( 'nav' === strtolower($element->tagName) || ! $this->shouldPreserveWrapper($element) ) {
            return false;
        }

        $hasNavigationDescendant = false;
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            if ( in_array(strtolower($child->tagName), array( 'a', 'ul', 'ol' ), true) ) {
                return false;
            }

            $hasNavigationDescendant = $hasNavigationDescendant || 'nav' === strtolower($child->tagName) || 0 < $child->getElementsByTagName('a')->length;
        }

        return $hasNavigationDescendant;
    }

    private function shouldPreserveEmptyVisualElement(DOMElement $element): bool
    {
        if ( '' !== trim($element->textContent ?? '') ) {
            return false;
        }

        if ( $this->shouldPreserveWrapper($element) ) {
            return true;
        }

        if ( in_array(strtolower($this->attr($element, 'role')), array( 'presentation', 'none' ), true) || 'true' === strtolower($this->attr($element, 'aria-hidden')) ) {
            return true;
        }

        if ( ! $this->isEmptyVisualInlineCandidate($element) ) {
            return false;
        }

        $declarations = $this->presentationDeclarations($element);
        foreach ( array( 'background', 'background-color', 'border', 'border-color', 'border-width', 'border-radius', 'box-shadow', 'width', 'height', 'min-width', 'min-height' ) as $property ) {
            if ( isset($declarations[$property]) && '' !== trim($declarations[$property]) ) {
                return true;
            }
        }

        return false;
    }

    private function isEmptyVisualInlineCandidate(DOMElement $element): bool
    {
        if ( '' !== trim($element->textContent ?? '') || 0 !== $this->childElementCount($element) || ! $this->isInlineContentElement(strtolower($element->tagName)) ) {
            return false;
        }

        $tokens = strtolower(trim($this->attr($element, 'class') . ' ' . $this->attr($element, 'id') . ' ' . $this->attr($element, 'role')));
        if ( '' === $tokens && $element->parentNode instanceof DOMElement ) {
            $tokens = strtolower(trim($this->attr($element->parentNode, 'class') . ' ' . $this->attr($element->parentNode, 'id')));
        }
        return (bool) preg_match('/(?:^|[^a-z0-9])(?:badges?|chips?|pills?|status|indicators?|markers?|dots?|orbs?|icons?)(?:[^a-z0-9]|$)/', $tokens);
    }

    private function hasEmptyVisualInlineChild(DOMElement $element): bool
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && $this->isInlineContentElement(strtolower($child->tagName)) && $this->shouldPreserveEmptyVisualElement($child) ) {
                return true;
            }
        }

        return false;
    }

    private function isInlineContentElement(string $tagName): bool
    {
        return in_array($tagName, array( 'abbr', 'b', 'cite', 'code', 'em', 'font', 'i', 'kbd', 'mark', 'rp', 'rt', 'ruby', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'var' ), true);
    }

    private function hasBlockContentChildren(DOMElement $element): bool
    {
        foreach ( $element->childNodes as $child ) {
            $tagName = $child instanceof DOMElement ? strtolower($child->tagName) : '';
            if ( $child instanceof DOMElement && 'br' !== $tagName && ! $this->isInlineContentElement($tagName) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function linkedSvgLogoBlockFromAnchor(DOMElement $anchor, array &$fallbacks): ?array
    {
        if ( ! $this->isLinkedSvgLogoAnchor($anchor) ) {
            return null;
        }

        return $this->convertLinkWrapperGroup($anchor, $fallbacks);
    }

    private function isLinkedSvgLogoAnchor(DOMElement $anchor): bool
    {
        return $this->hasLogoBrandSignal($anchor) && 0 < $anchor->getElementsByTagName('svg')->length;
    }

    private function hasLogoBrandSignal(DOMElement $element): bool
    {
        foreach ( array( 'class', 'id' ) as $attribute ) {
            foreach ( preg_split('/[^a-z0-9]+/', strtolower($this->attr($element, $attribute))) ?: array() as $token ) {
                if ( in_array($token, array( 'logo', 'brand', 'branding' ), true) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function textFlowBlockFromElement(DOMElement $element): ?array
    {
        if ( 'div' !== strtolower($element->tagName) || '' !== trim($this->attr($element, 'id')) || '' !== trim($this->attr($element, 'role')) ) {
            return null;
        }

        $hasLineBreak = false;
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $tagName = strtolower($child->tagName);
            if ( 'br' === $tagName ) {
                $hasLineBreak = true;
            }
            if ( 'br' !== $tagName && ! $this->isInlineContentElement($tagName) && 'a' !== $tagName ) {
                return null;
            }
        }

        if ( ! $hasLineBreak ) {
            return null;
        }

        $content = $this->richTextContentWithoutDecorativeSvg($element);
        if ( '' === trim($this->runtime->stripAllTags($content)) ) {
            return null;
        }

        return $this->createBlock('core/paragraph', array_merge($this->presentationAttributes($element), array( 'content' => $content )), array(), $element);
    }

    private function richTextContentWithoutDecorativeSvg(DOMElement $element): string
    {
        return $this->stripDecorativeSvgFromRichText($this->innerHtml($element));
    }

    /**
     * Convert an inline text token with a passive SVG into native sibling blocks.
     * RichText cannot retain SVG markup, but a materialized core/image can retain
     * the artwork while the wrapper class remains available to the stylesheet.
     *
     * @return array<string, mixed>|null
     */
    private function inlineSvgTextGroupBlockFromElement(DOMElement $element): ?array
    {
        if ( 'span' !== strtolower($element->tagName) || '' === trim($this->attr($element, 'class')) || 0 === $element->getElementsByTagName('svg')->length ) {
            return null;
        }

        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                continue;
            }

            if ( ! $child instanceof DOMElement ) {
                return null;
            }

            $tagName = strtolower($child->tagName);
            if ( 'svg' === $tagName ) {
                continue;
            }

            if ( 'a' !== $tagName && 'br' !== $tagName && ! $this->isInlineContentElement($tagName) ) {
                return null;
            }

            foreach ( $child->getElementsByTagName('*') as $descendant ) {
                if ( ! $descendant instanceof DOMElement ) {
                    continue;
                }

                $descendantTagName = strtolower($descendant->tagName);
                if ( 'a' !== $descendantTagName && 'br' !== $descendantTagName && ! $this->isInlineContentElement($descendantTagName) ) {
                    return null;
                }
            }
        }

        $blocks = array();
        $textRun = '';
        $hasTextRun = false;
        $flushTextRun = function () use ( &$blocks, &$textRun, &$hasTextRun ): bool {
            $content = trim($textRun);
            $textRun = '';
            if ( '' === trim($this->runtime->stripAllTags($content)) ) {
                return true;
            }
            if ( $this->richTextRequiresHtmlFallbackWithoutNativeSvgImageObjects($content) ) {
                return false;
            }

            $blocks[] = $this->createBlock('core/paragraph', array( 'content' => $content ));
            $hasTextRun = true;
            return true;
        };

        $generatedAssets = $this->generatedAssets;
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                $textRun .= htmlspecialchars($child->textContent ?? '', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
                continue;
            }

            if ( ! $child instanceof DOMElement ) {
                $this->generatedAssets = $generatedAssets;
                return null;
            }

            if ( 'svg' !== strtolower($child->tagName) ) {
                $textRun .= $this->outerHtml($child);
                continue;
            }

            $image = $this->inlineSvgRichTextImageMarkup($child);
            if ( null === $image ) {
                $this->generatedAssets = $generatedAssets;
                return null;
            }

            $textRun .= $image;
        }

        if ( ! $flushTextRun() || ! $hasTextRun ) {
            $this->generatedAssets = $generatedAssets;
            return null;
        }

        return $this->createBlock('core/group', $this->presentationAttributes($element), $blocks, $element);
    }

    private function richTextContentWithMaterializedSvgImages(DOMElement $element, string $content): ?string
    {
        if ( 0 === $element->getElementsByTagName('svg')->length ) {
            return $content;
        }

        $generatedAssets = $this->generatedAssets;
        foreach ( $element->getElementsByTagName('svg') as $svg ) {
            if ( ! $svg instanceof DOMElement ) {
                continue;
            }
            $image = $this->inlineSvgRichTextImageMarkup($svg, false);
            if ( null === $image ) {
                $this->generatedAssets = $generatedAssets;
                return null;
            }
            // RichText preparation may normalize SVG casing (viewBox -> viewbox),
            // so the DOM serialization is not a stable replacement key.
            $replaced = preg_replace('@<svg\b[^>]*>.*?</svg>@is', $image, $content, 1);
            if ( ! is_string($replaced) || $replaced === $content ) {
                $this->generatedAssets = $generatedAssets;
                return null;
            }
            $content = $replaced;
        }

        return $content;
    }

    private function richTextRequiresHtmlFallbackWithoutNativeSvgImageObjects(string $content): bool
    {
        // RichText stores core/image objects as <img> nodes. The generic fallback
        // detector intentionally rejects arbitrary images, so remove only our
        // materialized SVG image objects before applying that conservative gate.
        $content = preg_replace_callback(
            '@<img\b[^>]*\s*/?>@i',
            fn (array $matches): string => $this->isGeneratedInlineSvgSource($this->imageSourceFromMarkup($matches[0])) ? '' : $matches[0],
            $content
        ) ?? $content;
        return $this->richTextRequiresHtmlFallback($content);
    }

    private function richTextContainsNativeSvgImageObject(string $content): bool
    {
        if ( ! preg_match_all('@<img\b[^>]*\s*/?>@i', $content, $matches) ) {
            return false;
        }

        foreach ( $matches[0] as $markup ) {
            if ( $this->isGeneratedInlineSvgSource($this->imageSourceFromMarkup($markup)) ) {
                return true;
            }
        }

        return false;
    }

    private function imageSourceFromMarkup(string $markup): string
    {
        return preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/i', $markup, $matches)
            ? html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : '';
    }

    private function isGeneratedInlineSvgSource(string $source): bool
    {
        return isset($this->generatedAssets[$source]) && 'inline-svg' === ($this->generatedAssets[$source]['source'] ?? '');
    }

    private function stripDecorativeSvgFromRichText(string $content): string
    {
        $content = preg_replace('/<(?:span|i|b)\b(?=[^>]*\baria-hidden\s*=\s*(["\'])true\1)[^>]*>\s*<svg\b[\s\S]*?<\/svg>\s*<\/(?:span|i|b)>\s*/i', '', $content) ?? $content;

        return preg_replace('/<svg\b(?=[^>]*\baria-hidden\s*=\s*(["\'])true\1)[\s\S]*?<\/svg>\s*/i', '', $content) ?? $content;
    }

    private function inlineTokenGroupBlockFromElement(DOMElement $element, array &$fallbacks): ?array
    {
        if ( ! ShellLandmarkPolicy::isInlineTokenContainerTag($element->tagName) ) {
            return null;
        }

        if ( ! $this->hasInlineTokenGroupSignal($element) ) {
            return null;
        }

        $children = array();
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                if ( '' !== trim($child->textContent ?? '') ) {
                    return null;
                }
                continue;
            }

            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            if ( ! $this->isInlineTokenItemElement($child) ) {
                return null;
            }

            $tagName = strtolower($child->tagName);
            if ( in_array($tagName, array( 'a', 'button' ), true) ) {
                $block = $this->convertElement($child, $fallbacks, true);
                if ( null === $block ) {
                    return null;
                }
                $children[] = $block;
                continue;
            }

            $content = $this->innerHtml($child);
            if ( '' === trim($this->runtime->stripAllTags($content)) ) {
                return null;
            }
            $children[] = $this->createBlock('core/paragraph', array_merge($this->presentationAttributes($child), array( 'content' => $content )), array(), $child);
        }

        if ( count($children) < 2 ) {
            return null;
        }

        return $this->createBlock('core/group', $this->presentationAttributes($element), $children, $element);
    }

    private function hasInlineTokenGroupSignal(DOMElement $element): bool
    {
        if ( $this->hasInlineTokenSignal($element) ) {
            return true;
        }

        $tokenChildren = 0;
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && $this->isInlineTokenItemElement($child) ) {
                ++$tokenChildren;
            }
        }

        return 1 < $tokenChildren;
    }

    private function isInlineTokenItemElement(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( ! in_array($tagName, array( 'a', 'button' ), true) && ! $this->isInlineContentElement($tagName) ) {
            return false;
        }

        return $this->hasInlineTokenSignal($element);
    }

    private function hasInlineTokenSignal(DOMElement $element): bool
    {
        $tokens = strtolower(trim(implode(' ', array(
            $this->attr($element, 'class'),
            $this->attr($element, 'id'),
            $this->attr($element, 'role'),
            $this->attr($element, 'data-filter'),
            $this->attr($element, 'data-tag'),
        ))));

        return 1 === preg_match('/(?:^|[^a-z0-9])(?:chips?|pills?|badges?|tags?|filters?|facets?)(?:[^a-z0-9]|$)/', $tokens);
    }

    private function visualTextWrapperBlockFromElement(DOMElement $element): ?array
    {
        if ( ! in_array(strtolower($element->tagName), array( 'div', 'span' ), true) || $this->hasBlockContentChildren($element) ) {
            return null;
        }

        if ( $this->hasAuthorSemanticMarkedChild($element) || $this->hasRichTextMarkedDescendant($element) ) {
            return null;
        }

        $content = $this->richTextContentWithMaterializedInlineStyles($element);
        if ( '' === trim($this->runtime->stripAllTags($content)) || $this->richTextRequiresHtmlFallback($content) ) {
            return null;
        }

        if ( ! $this->hasVisualTextWrapperSignal($element) ) {
            return null;
        }

        return $this->createBlock(
            'core/group',
            $this->presentationAttributes($element),
            array( $this->createBlock('core/paragraph', array( 'content' => $content )) ),
            $element
        );
    }

    private function hasVisualTextWrapperSignal(DOMElement $element): bool
    {
        $className = strtolower($this->attr($element, 'class'));
        if ( preg_match('/(?:^|[\s_-])(?:badge|tag|label|eyebrow|kicker|meta|pill|chip|stat|num|price|amount|result|caption|title|name)(?:$|[\s_-])/', $className) ) {
            return true;
        }

        if ( 0 < $this->childElementCount($element) ) {
            return false;
        }

        $declarations = $this->presentationDeclarations($element);
        foreach ( array( 'display', 'gap', 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'border', 'border-color', 'border-radius', 'width', 'height', 'min-width', 'max-width', 'min-height' ) as $property ) {
            if ( isset($declarations[$property]) && '' !== trim((string) $declarations[$property]) ) {
                return true;
            }
        }

        return false;
    }

    private function paragraphBlockFromInlineContentWrapper(DOMElement $element): ?array
    {
        if ( ! ShellLandmarkPolicy::isInlineContentWrapperTag($element->tagName) ) {
            return null;
        }

        if ( ! $this->hasOnlyPhrasingChildren($element) ) {
            return null;
        }

        // A CSS-addressed inline leaf needs an independent native wrapper. Do
        // not absorb it into this parent RichText paragraph, where its selector
        // path and flex/grid item geometry would be lost.
        if ( $this->hasAuthorSemanticMarkedChild($element) || $this->hasRichTextMarkedDescendant($element) ) {
            return null;
        }

        $structuredInlineItems = $this->structuredInlineItemBlocks($element);
        if ( null !== $structuredInlineItems ) {
            return $this->createBlock('core/group', $this->presentationAttributes($element), $structuredInlineItems, $element);
        }

        $content = $this->richTextContentWithMaterializedInlineStyles($element);
        if ( '' === trim($this->runtime->stripAllTags($content)) ) {
            return null;
        }

        return $this->createBlock('core/paragraph', array_merge($this->presentationAttributes($element), array( 'content' => $content )), array(), $element);
    }

    private function hasAuthorSemanticMarkedChild(DOMElement $element): bool
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && $this->hasAuthorSemanticMarker($child) ) {
                return true;
            }
        }

        return false;
    }

    private function hasRichTextMarkedDescendant(DOMElement $element): bool
    {
        foreach ( $element->getElementsByTagName('span') as $span ) {
            if ( $span instanceof DOMElement && '' !== $this->richTextMarkerForElement($span) ) {
                return true;
            }
        }

        return false;
    }

    private function hasOnlyPhrasingChildren(DOMElement $element): bool
    {
        $nonAnchorText = false;

        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                if ( '' !== trim($child->textContent ?? '') ) {
                    $nonAnchorText = true;
                }
                continue;
            }

            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $tagName = strtolower($child->tagName);
            if ( 'a' === $tagName ) {
                continue;
            }

            if ( 'br' === $tagName || $this->isInlineContentElement($tagName) ) {
                $nonAnchorText = true;
                continue;
            }

            return false;
        }

        return $nonAnchorText;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function structuredInlineItemBlocks(DOMElement $element): ?array
    {
        $blocks = array();

        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                if ( '' !== trim($child->textContent ?? '') ) {
                    return null;
                }
                continue;
            }

            if ( XML_COMMENT_NODE === $child->nodeType ) {
                continue;
            }

            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            if ( ! $this->isClassedPhrasingItem($child) ) {
                return null;
            }

            $inlineSvgTextGroup = $this->inlineSvgTextGroupBlockFromElement($child);
            if ( null !== $inlineSvgTextGroup ) {
                $blocks[] = $inlineSvgTextGroup;
                continue;
            }

            $content = $this->innerHtml($child);
            if ( '' === trim($this->runtime->stripAllTags($content)) ) {
                return null;
            }

            $blocks[] = $this->createBlock('core/paragraph', array_merge($this->presentationAttributes($child), array( 'content' => $content )), array(), $child);
        }

        return 1 < count($blocks) ? $blocks : null;
    }

    private function isClassedPhrasingItem(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( 'br' === $tagName || ( 'a' !== $tagName && ! $this->isInlineContentElement($tagName) ) ) {
            return false;
        }

        return '' !== trim($this->attr($element, 'class')) || '' !== trim($this->attr($element, 'style'));
    }

    private function dynamicTextContent(DOMElement $element): ?string
    {
        $target = trim($this->attr($element, 'data-target'));
        if ( '' === $target ) {
            $target = trim($this->attr($element, 'data-count'));
        }
        if ( '' === $target || ! is_numeric($target) ) {
            return null;
        }

        $isFloat = 'true' === strtolower(trim($this->attr($element, 'data-float'))) || str_contains($target, '.');
        $value = $isFloat
            ? number_format((float) $target, 1, '.', ',')
            : number_format((float) $target, 0, '.', ',');

        return $this->attr($element, 'data-prefix') . $value . $this->attr($element, 'data-suffix');
    }

    /**
     * @return array<string, string>
     */
    private function safeSourceAttributes(DOMElement $element): array
    {
        $safe = array();
        $allowed = array_flip(array( 'alt', 'class', 'data-layout', 'data-wp-layout', 'height', 'href', 'id', 'media', 'open', 'sizes', 'src', 'srcset', 'style', 'title', 'type', 'width' ));
        foreach ( $this->htmlAttributes($element) as $name => $value ) {
            if ( isset($allowed[$name]) && ! preg_match('/^\s*javascript\s*:/i', $value) ) {
                $safe[$name] = $value;
            }
        }

        return $safe;
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceContext(DOMElement $element): array
    {
        return array_filter(array(
            'selector'                => $this->elementSelector($element),
            'parent_tag'              => $element->parentNode instanceof DOMElement && 'body' !== strtolower($element->parentNode->tagName) ? strtolower($element->parentNode->tagName) : '',
            'ancestor_tags'           => $this->ancestorTags($element),
            'nearest_heading'         => $this->nearestPreviousHeadingText($element),
            'role'                    => $this->attr($element, 'role'),
            'id'                      => $this->attr($element, 'id'),
            'class_names'             => $this->classNames($element),
            'data_attributes'         => $this->safeDataAttributes($element),
            'structure_signals'       => $this->structureSignals($element, array()),
            'interactive_attributes'  => $this->interactiveAttributes($element),
        ), static fn (mixed $value): bool => '' !== $value && array() !== $value);
    }

    private function nearestPreviousHeadingText(DOMElement $element): string
    {
        for ( $node = $element->previousSibling; $node instanceof DOMNode; $node = $node->previousSibling ) {
            if ( $node instanceof DOMElement && preg_match('/^h[1-6]$/i', $node->tagName) ) {
                return trim(preg_replace('/\s+/', ' ', $node->textContent ?? '') ?? '');
            }
        }

        return '';
    }

    /**
     * @return array<string, string>
     */
    private function safeDataAttributes(DOMElement $element): array
    {
        $data = array();
        foreach ( $this->htmlAttributes($element) as $name => $value ) {
            if ( preg_match('/^data-[a-z0-9_-]+$/i', $name) && strlen($value) <= 300 && ! preg_match('/javascript\s*:/i', $value) ) {
                $data[$name] = $value;
            }
        }

        return $data;
    }

    /**
     * @return array<string, bool|string>
     */
    private function interactiveAttributes(DOMElement $element): array
    {
        return array_filter(array(
            'tabindex'      => $this->attr($element, 'tabindex'),
            'aria-expanded' => $this->attr($element, 'aria-expanded'),
            'aria-controls' => $this->attr($element, 'aria-controls'),
            'has_events'    => array() !== $this->eventMetadata($element),
        ), static fn (mixed $value): bool => false !== $value && '' !== $value);
    }

    /**
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function structureSignals(DOMElement $element, array $attrs): array
    {
        $className = strtolower(trim($this->attr($element, 'class') . ' ' . (string) ($attrs['className'] ?? '')));
        $style = strtolower(trim($this->attr($element, 'style') . ';' . (is_string($attrs['style'] ?? null) ? $attrs['style'] : '')));
        $signals = array();

        if ( preg_match('/(?:^|[\s_-])(?:card|feature|service|provider|resource|post|project|stat|badge|tile|panel|item)(?:$|[\s_-])/', $className) || 'article' === strtolower($element->tagName) ) {
            $signals['card_like'] = true;
        }
        if ( preg_match('/(?:^|[\s_-])(?:cards|features|services|providers|testimonials|resources|posts|projects|stats|badges|grid|grid-[0-9]+|tiles|columns|collection|gallery)(?:$|[\s_-])/', $className) || preg_match('/(?:^|;)\s*(?:display\s*:\s*grid|grid-template-columns\s*:)/', $style) ) {
            $signals['grid_like'] = true;
        }
        if ( preg_match('/(?:^|[\s_-])(?:hero|masthead|intro|banner|container|wrap|wrapper|inner|shell)(?:$|[\s_-])/', $className) ) {
            $signals['section_container_like'] = true;
        }
        if ( $this->isVisualLayerElement($element) ) {
            $signals['visual_layer'] = true;
        }
        if ( $this->hasCommerceToken($element, array( 'badge', 'featured', 'popular', 'recommended' )) ) {
            $signals['featured_badge_like'] = true;
        }
        if ( $this->hasCommerceToken($element, array( 'price', 'pricing', 'amount', 'cost' )) || $this->looksLikePriceText($element->textContent ?? '') ) {
            $signals['price_like'] = true;
        }
        if ( $this->hasCommerceToken($element, array( 'product', 'menu', 'dish', 'plan', 'tier', 'name', 'title' )) ) {
            $signals['commerce_content_like'] = true;
        }
        if ( $this->looksLikeNamePriceRow($element) ) {
            $signals['name_price_row'] = true;
        }

        $itemCount = $this->cardLikeChildCount($element);
        if ( 1 < $itemCount ) {
            $signals['repeated_card_children'] = $itemCount;
        }

        return $signals;
    }

    private function cardLikeChildCount(DOMElement $element): int
    {
        $itemCount = 0;
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && $this->isCardLikeElement($child) ) {
                ++$itemCount;
            }
        }

        return $itemCount;
    }

    private function isCardLikeElement(DOMElement $element): bool
    {
        $className = strtolower($this->attr($element, 'class'));
        return 'article' === strtolower($element->tagName) || (bool) preg_match('/(?:^|[\s_-])(?:card|feature|service|provider|resource|post|project|stat|badge|tile|panel|item)(?:$|[\s_-])/', $className);
    }

    private function isVisualLayerElement(DOMElement $element): bool
    {
        $context = strtolower(trim(implode(' ', array(
            $this->attr($element, 'class'),
            $this->attr($element, 'id'),
            $this->attr($element, 'aria-label'),
        ))));
        $style = strtolower($this->attr($element, 'style'));

        if ( preg_match('/(?:^|[\s_-])(?:hero|decor|decorative|layer|overlay|grain|noise|texture|glow|atmosphere|ambient|aura|orb|blob|backdrop|background|bg)(?:$|[\s_-])/', $context) ) {
            return true;
        }

        return (bool) ( preg_match('/(?:^|;)\s*position\s*:\s*(?:fixed|absolute)\b/', $style)
            && preg_match('/(?:^|;)\s*(?:inset|top|right|bottom|left|z-index|pointer-events|mix-blend-mode|opacity|filter|background|background-image)\s*:/', $style) );
    }

    /**
     * @param array<int, string> $tokens
     */
    private function hasCommerceToken(DOMElement $element, array $tokens): bool
    {
        foreach ( array( 'class', 'id', 'itemprop' ) as $attribute ) {
            $value = strtolower($this->attr($element, $attribute));
            foreach ( preg_split('/[^a-z0-9]+/', $value) ?: array() as $token ) {
                if ( in_array($token, $tokens, true) ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function looksLikePriceText(string $text): bool
    {
        return (bool) preg_match('/(?:\p{Sc}\s?\d|\d+(?:[.,]\d{2})?\s?(?:usd|eur|gbp|cad|aud)\b)/iu', trim($text));
    }

    private function looksLikeNamePriceRow(DOMElement $element): bool
    {
        return null !== $this->namePriceChildren($element);
    }

    private function safeSourceFragment(DOMElement $element): string
    {
        $html = $this->safeFallbackHtml($element);
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/\s+(href|src)\s*=\s*("\s*javascript:[^"]*"|\'\s*javascript:[^\']*\'|javascript:[^\s>]+)/i', '', $html) ?? '';

        if ( strlen($html) > 500 ) {
            return substr($html, 0, 500) . '...';
        }

        return $html;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function interactionCandidates(DOMElement $root): array
    {
        $candidates = array();
        $seen = array();
        foreach ( $root->getElementsByTagName('*') as $element ) {
            if ( ! $element instanceof DOMElement ) {
                continue;
            }

            foreach ( $this->interactionCandidatesForElement($element) as $candidate ) {
                $key = json_encode($candidate, JSON_UNESCAPED_SLASHES);
                if ( ! is_string($key) || isset($seen[$key]) ) {
                    continue;
                }
                $seen[$key] = true;
                $candidates[] = $candidate;
                if ( count($candidates) >= self::MAX_INTERACTION_CANDIDATES ) {
                    return $candidates;
                }
            }
        }

        return $candidates;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function interactionCandidatesForElement(DOMElement $element): array
    {
        $tagName = strtolower($element->tagName);
        $role = strtolower($this->attr($element, 'role'));
        $classes = strtolower($this->attr($element, 'class'));
        $id = strtolower($this->attr($element, 'id'));
        $data = $this->safeDataAttributes($element);
        $dataText = strtolower(implode(' ', array_merge(array_keys($data), array_values($data))));
        $nameText = trim($classes . ' ' . $id . ' ' . $dataText);
        $events = $this->eventMetadata($element);
        $actionDataAttributes = array_keys(array_filter($data, static fn (string $value, string $name): bool => preg_match('/^data-(?:action|on|event)$/i', $name) && '' !== trim($value), ARRAY_FILTER_USE_BOTH));
        $hasAriaControl = '' !== trim($this->attr($element, 'aria-controls')) || '' !== trim($this->attr($element, 'aria-expanded'));
        $candidates = array();

        if ( 'details' === $tagName ) {
            $candidates[] = $this->interactionCandidate($element, 'details', 'summary', $this->targetForElement($element), array('details_element'), 'high', 'native_toggle');
        }

        if ( 'form' === $tagName ) {
            $metadata = $this->formMetadata($element);
            $candidates[] = $this->interactionCandidate($element, 'form', 'submit', (string) ($metadata['action'] ?? ''), array_filter(array('form_element', (string) ($metadata['method'] ?? ''))), 'high', 'form_submission');
        }

        if ( in_array($tagName, array('button', 'a'), true) && ( array() !== $events || array() !== $actionDataAttributes || $hasAriaControl ) ) {
            $candidates[] = $this->interactionCandidate($element, 'control', $this->controlTrigger($element, $events), $this->controlledTarget($element), $this->controlEvidence($element, $events, $actionDataAttributes), $hasAriaControl ? 'high' : 'medium', 'client_runtime');
        }

        if ( 'dialog' === $tagName || in_array($role, array('dialog', 'alertdialog'), true) || preg_match('/(?:^|[\s_-])(?:modal|dialog|popup|lightbox)(?:$|[\s_-])/', $nameText) ) {
            $candidates[] = $this->interactionCandidate($element, 'modal', $this->modalTriggerHint($element), $this->targetForElement($element), array_filter(array('modal_like', 'dialog' === $tagName ? 'dialog_element' : '', '' !== $role ? 'role:' . $role : '')), 'medium', 'modal_runtime');
        }

        if ( in_array($role, array('tablist', 'tab', 'tabpanel'), true) ) {
            $candidates[] = $this->interactionCandidate($element, 'tabs', 'tab' === $role ? 'tab_select' : $role, $this->controlledTarget($element), array_filter(array('role:' . $role, '' !== $this->attr($element, 'aria-controls') ? 'aria-controls' : '')), 'high', 'tab_state');
        }

        if ( ( in_array($tagName, array('button', 'a'), true) || '' !== $role ) && ( preg_match('/(?:^|[\s_-])accordion(?:$|[\s_-])/', $nameText) || ( $hasAriaControl && 'tab' !== $role && '' !== trim($this->attr($element, 'aria-expanded')) ) ) ) {
            $candidates[] = $this->interactionCandidate($element, 'accordion', $this->controlTrigger($element, $events), $this->controlledTarget($element), array_filter(array('accordion_like', '' !== $this->attr($element, 'aria-expanded') ? 'aria-expanded' : '', '' !== $this->attr($element, 'aria-controls') ? 'aria-controls' : '')), 'medium', 'accordion_state');
        }

        if ( preg_match('/(?:^|[\s_-])(?:carousel|slider|slideshow|swiper)(?:$|[\s_-])/', $nameText) ) {
            $candidates[] = $this->interactionCandidate($element, 'carousel', $this->carouselTriggerHint($element), $this->targetForElement($element), array('carousel_like'), 'medium', 'carousel_runtime');
        }

        return $candidates;
    }

    /**
     * Emit a generic behavior-loss diagnostic for interactive controls that
     * convert to static, non-interactive blocks without their behavior being
     * preserved or rebuilt.
     *
     * Detection is structural/semantic — handler attributes (on*), declarative
     * JS hooks (data-action/toggle/target/...), ARIA control state
     * (aria-controls/aria-expanded/aria-haspopup), or a button role on a
     * non-button, non-link element — never a fixture-specific class string.
     *
     * Controls whose behavior survives conversion are intentionally excluded so
     * ordinary content stays silent: forms (covered by html_form_fallback),
     * script DOM targets (covered by the runtime dependency parity report),
     * elements preserved as runtime islands, hamburger toggles folded into
     * core/navigation, controls consumed by a menu that becomes core/navigation,
     * and plain links/buttons with no interaction signals.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     */
    private function appendInteractiveControlBehaviorLossFallbacks(DOMElement $body, array &$fallbacks): void
    {
        $emitted = 0;
        $seen = array();
        foreach ( $body->getElementsByTagName('*') as $element ) {
            if ( ! $element instanceof DOMElement ) {
                continue;
            }

            if ( $emitted >= self::MAX_INTERACTION_CANDIDATES ) {
                return;
            }

            $signals = $this->interactionSignalEvidence($element);
            if ( array() === $signals || ! $this->isInteractiveControlBehaviorLoss($element) ) {
                continue;
            }

            $key = strtolower($element->tagName) . '|' . $this->elementSelector($element);
            if ( isset($seen[$key]) ) {
                continue;
            }
            $seen[$key] = true;

            $boundedHtml = $this->boundedFallbackHtml($this->safeFallbackHtml($element));
            $fallbacks[] = FallbackDiagnostic::build(array_filter(array(
                'type'                => 'html',
                'reason'              => 'interactive_control_behavior_lost',
                'diagnostic_code'     => 'interactive_control_behavior_lost',
                'message'             => 'An interactive control was converted to a static block, so its source behavior is no longer wired to any runtime.',
                'source_format'       => 'html',
                'tag'                 => strtolower($element->tagName),
                'selector'            => $this->elementSelector($element),
                'attributes'          => $this->htmlAttributes($element),
                'context'             => $this->sourceContext($element),
                'events'              => $this->eventMetadata($element),
                'interaction_signals' => $signals,
                'controlled_target'   => $this->controlledTarget($element),
                'html'                => $boundedHtml['html'],
                'html_bytes'          => $boundedHtml['bytes'],
                'html_truncated'      => $boundedHtml['truncated'],
            ), static fn (mixed $value): bool => null !== $value && '' !== $value && array() !== $value), $this->fallbackProvenance);
            ++$emitted;
        }
    }

    /**
     * Surface a generic product-grid finding so a downstream consumer can
     * materialize the recognized products (e.g. as commerce products) without the
     * transformer carrying any provider or plugin knowledge.
     *
     * This is purely ADDITIVE: the layout block output (grid -> group/columns) is
     * unchanged; this only appends an `html_product_grid_fallback` diagnostic that
     * a consumer may act on or ignore.
     *
     * Detection composes the existing commerce-recognition primitives
     * (grid_like / repeated card children / price + name tokens) with schema.org
     * microdata (`itemtype` Product / `itemprop` name|price|Offer). A product card
     * is a repeated sibling (>= 2 under a grid_like or list container) that either
     * declares schema.org Product/Offer structure OR carries the full structural
     * triad: a name (heading or name token), a currency-formatted price, and an
     * add-to-cart/buy control. Detection reads only structural/semantic signals
     * and schema.org vocabulary — never fixture names or specific class strings.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     */
    private function appendProductGridFallbacks(DOMElement $body, array &$fallbacks): void
    {
        $emitted = 0;
        $coveredPaths = array();
        foreach ( $body->getElementsByTagName('*') as $element ) {
            if ( ! $element instanceof DOMElement ) {
                continue;
            }

            if ( $emitted >= self::MAX_INTERACTION_CANDIDATES ) {
                return;
            }

            if ( ! $this->isProductGridContainer($element) ) {
                continue;
            }

            // Prefer the innermost qualifying container: skip a grid whose products
            // were already attributed to a nested grid emitted earlier in the walk.
            $path = $element->getNodePath() ?? '';
            foreach ( $coveredPaths as $coveredPath ) {
                if ( '' !== $path && '' !== $coveredPath && str_starts_with($coveredPath, $path . '/') ) {
                    continue 2;
                }
            }

            $products = $this->productCardsForContainer($element);
            if ( count($products) < 2 ) {
                continue;
            }

            $coveredPaths[] = $path;

            $fallbacks[] = FallbackDiagnostic::build(array_filter(array(
                'type'              => 'html',
                'reason'            => 'commerce_product_grid_detected',
                'diagnostic_code'   => 'html_product_grid_fallback',
                'kind'              => 'html_product_grid_fallback',
                'message'           => 'A product grid was detected; per-card commerce structure was extracted so a shop provider can materialize the products.',
                'source_format'     => 'html',
                'tag'               => strtolower($element->tagName),
                'selector'          => $this->elementSelector($element),
                'container_selector' => $this->elementSelector($element),
                'context'           => $this->sourceContext($element),
                'products'          => $products,
                'product_count'     => count($products),
            ), static fn (mixed $value): bool => null !== $value && '' !== $value && array() !== $value), $this->fallbackProvenance);
            ++$emitted;
        }
    }

    /**
     * Surface commerce-specific runtime controls separately from the surrounding
     * product-grid structure. The transformer can emit editable layout/product
     * metadata, but quantity and add-to-cart controls require a commerce runtime.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     */
    private function appendCommerceControlsFallbacks(DOMElement $body, array &$fallbacks): void
    {
        $emitted = 0;
        foreach ( $body->getElementsByTagName('*') as $element ) {
            if ( ! $element instanceof DOMElement ) {
                continue;
            }

            if ( $emitted >= self::MAX_INTERACTION_CANDIDATES ) {
                return;
            }

            if ( ! $this->isProductGridContainer($element) ) {
                continue;
            }

            $controlGroups = $this->commerceControlGroupsForContainer($element);
            if ( array() === $controlGroups ) {
                continue;
            }

            $fallbacks[] = FallbackDiagnostic::build(array_filter(array(
                'type'              => 'html',
                'reason'            => 'commerce_controls_require_runtime',
                'diagnostic_code'   => 'html_commerce_controls_fallback',
                'kind'              => 'html_commerce_controls_fallback',
                'message'           => 'Commerce quantity and add-to-cart controls were detected; product data can be seeded by a shop provider, but these controls need cart runtime binding rather than a static core block approximation.',
                'source_format'     => 'html',
                'tag'               => strtolower($element->tagName),
                'selector'          => $this->elementSelector($element),
                'container_selector' => $this->elementSelector($element),
                'context'           => $this->sourceContext($element),
                'controls'          => $controlGroups,
                'control_count'     => count($controlGroups),
            ), static fn (mixed $value): bool => null !== $value && '' !== $value && array() !== $value), $this->fallbackProvenance);
            ++$emitted;
        }
    }

    /**
     * Whether an element is a plausible product-grid container: a list (ul/ol) or
     * an element the structure classifier already flags as grid_like.
     */
    private function isProductGridContainer(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( in_array($tagName, array( 'ul', 'ol' ), true) ) {
            return true;
        }

        $signals = $this->structureSignals($element, array());
        return true === ($signals['grid_like'] ?? false);
    }

    /**
     * Extract the qualifying product cards directly under a grid container. A card
     * is a direct child element that declares schema.org Product/Offer structure or
     * carries the full structural commerce triad (name + price + cart control).
     *
     * @return array<int, array<string, mixed>>
     */
    private function productCardsForContainer(DOMElement $container): array
    {
        $products = array();
        foreach ( $container->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $product = $this->productCardData($child);
            if ( null !== $product ) {
                $products[] = $product;
            }
        }

        return $products;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function commerceControlGroupsForContainer(DOMElement $container): array
    {
        $groups = array();
        foreach ( $container->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $product = $this->productCardData($child);
            if ( null === $product || empty($product['has_cart_control']) ) {
                continue;
            }

            $hasQuantity = $this->hasQuantityControl($child);
            $groups[] = array_filter(array(
                'product_name'         => $product['name'] ?? '',
                'source_selector'      => $this->elementSelector($child),
                'has_quantity_control' => $hasQuantity,
                'has_cart_control'     => true,
                'runtime_requirement'  => 'commerce_cart_runtime',
            ), static fn (mixed $value): bool => null !== $value && '' !== $value);
        }

        return $groups;
    }

    /**
     * Build the per-card product payload when a card qualifies, else null.
     *
     * A card qualifies when it declares schema.org Product/Offer structure
     * (microdata `itemtype` Product or both `itemprop` name and price), OR carries
     * the structural triad: a name, a currency-formatted price, and an
     * add-to-cart/buy control.
     *
     * @return array<string, mixed>|null
     */
    private function productCardData(DOMElement $card): ?array
    {
        $name = $this->productNameText($card);
        $prices = $this->productPriceTexts($card);
        $hasCart = $this->hasCartControl($card);
        $isSchemaProduct = $this->isSchemaProductCard($card);

        if ( '' === $name || array() === $prices ) {
            return null;
        }

        // schema.org Product/Offer is an authoritative commerce signal, so it
        // qualifies a card on its own. Otherwise require the full structural triad
        // (name + price + cart control) to avoid flagging generic content grids.
        if ( ! $isSchemaProduct && ! $hasCart ) {
            return null;
        }

        return array_filter(array(
            'name'             => $name,
            'price'            => $prices['price'],
            'sale_price'       => $prices['sale_price'] ?? null,
            'description'      => $this->productDescriptionText($card, $name),
            'image'            => $this->productImage($card),
            'has_cart_control' => $hasCart,
            'source_selector'  => $this->elementSelector($card),
        ), static fn (mixed $value, string $key): bool => in_array($key, array( 'sale_price', 'description', 'image' ), true) || ( null !== $value && '' !== $value ), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Whether the card declares schema.org Product/Offer structure via microdata.
     */
    private function isSchemaProductCard(DOMElement $card): bool
    {
        $itemtype = strtolower($this->attr($card, 'itemtype'));
        if ( str_contains($itemtype, 'schema.org/product') ) {
            return true;
        }

        $hasName = null !== $this->firstDescendantWithItemprop($card, array( 'name' ));
        $hasPrice = null !== $this->firstDescendantWithItemprop($card, array( 'price' ));
        if ( $hasName && $hasPrice ) {
            return true;
        }

        foreach ( $card->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement && str_contains(strtolower($this->attr($descendant, 'itemtype')), 'schema.org/offer') ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The product name text: schema.org `itemprop="name"`, else the first heading,
     * else the first element carrying a name token.
     */
    private function productNameText(DOMElement $card): string
    {
        $schemaName = $this->firstDescendantWithItemprop($card, array( 'name' ));
        if ( null !== $schemaName ) {
            $text = $this->collapsedText($schemaName);
            if ( '' !== $text ) {
                return $text;
            }
        }

        foreach ( $card->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement && preg_match('/^h[1-6]$/', strtolower($descendant->tagName)) ) {
                $text = $this->collapsedText($descendant);
                if ( '' !== $text ) {
                    return $text;
                }
            }
        }

        foreach ( $card->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement && $this->hasCommerceToken($descendant, array( 'name', 'title', 'product' )) ) {
                $text = $this->collapsedText($descendant);
                if ( '' !== $text ) {
                    return $text;
                }
            }
        }

        return '';
    }

    /**
     * Currency-formatted price text for the card, returning the regular price and
     * an optional sale price. schema.org `itemprop="price"` is preferred; otherwise
     * elements whose text is currency-formatted or carry a price token are used.
     * A price element marked with a sale/discount token is treated as the sale
     * price and the other as the regular price.
     *
     * @return array{price: string, sale_price?: string}
     */
    private function productPriceTexts(DOMElement $card): array
    {
        $regular = '';
        $sale = '';
        $fallback = '';

        $schemaPrice = $this->firstDescendantWithItemprop($card, array( 'price' ));
        if ( null !== $schemaPrice ) {
            $content = trim($this->attr($schemaPrice, 'content'));
            $regular = $this->currencyFormattedText('' !== $content ? $content : ($schemaPrice->textContent ?? ''), $schemaPrice);
        }

        foreach ( $card->getElementsByTagName('*') as $descendant ) {
            if ( ! $descendant instanceof DOMElement ) {
                continue;
            }

            $text = $this->collapsedText($descendant);
            if ( '' === $text || ! $this->isPriceElement($descendant) ) {
                continue;
            }
            // Only consider leaf-ish price elements so a wrapper's concatenated
            // text does not shadow the individual regular/sale amounts.
            if ( $this->childElementCount($descendant) > 0 ) {
                continue;
            }

            $formatted = $this->currencyFormattedText($text, $descendant);
            if ( '' === $formatted ) {
                continue;
            }

            if ( $this->hasCommerceToken($descendant, array( 'sale', 'discount', 'special', 'reduced', 'now' )) ) {
                $sale = '' === $sale ? $formatted : $sale;
                continue;
            }

            if ( '' === $regular ) {
                $regular = $formatted;
            } elseif ( '' === $fallback ) {
                $fallback = $formatted;
            }
        }

        if ( '' === $regular ) {
            $regular = '' !== $fallback ? $fallback : $sale;
            $sale = '' !== $fallback ? $sale : '';
        }

        if ( '' === $regular ) {
            return array();
        }

        $result = array( 'price' => $regular );
        if ( '' !== $sale && $sale !== $regular ) {
            $result['sale_price'] = $sale;
        }

        return $result;
    }

    /**
     * Reduce raw text to its currency-formatted price token (e.g. "$24"), keeping
     * the trimmed source when no currency token is present but the element is a
     * declared price (schema.org / price token).
     */
    private function currencyFormattedText(string $text, DOMElement $element): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ( '' === $text ) {
            return '';
        }

        if ( preg_match('/\p{Sc}\s?\d[\d.,]*|\d[\d.,]*\s?(?:usd|eur|gbp|cad|aud)\b/iu', $text, $matches) ) {
            return trim($matches[0]);
        }

        // A schema.org price content attribute is bare numeric (e.g. "24.00");
        // keep it as-is when the element is a declared price.
        if ( $this->hasCommerceToken($element, array( 'price', 'amount', 'cost' )) && preg_match('/\d/', $text) ) {
            return $text;
        }

        return '';
    }

    /**
     * Whether the card contains an add-to-cart / buy / purchase control. Detection
     * is semantic: a button/link/input whose text, class, id, name, aria-label, or
     * data-* carries cart/buy/add/purchase/checkout/order semantics.
     */
    private function hasCartControl(DOMElement $card): bool
    {
        $tokens = array( 'cart', 'buy', 'purchase', 'checkout', 'order', 'addtocart', 'add-to-cart' );
        foreach ( $card->getElementsByTagName('*') as $descendant ) {
            if ( ! $descendant instanceof DOMElement ) {
                continue;
            }

            $tagName = strtolower($descendant->tagName);
            $role = strtolower($this->attr($descendant, 'role'));
            $isControl = in_array($tagName, array( 'button', 'a', 'input' ), true) || 'button' === $role;
            if ( ! $isControl ) {
                continue;
            }

            $haystack = strtolower(implode(' ', array(
                $this->attr($descendant, 'class'),
                $this->attr($descendant, 'id'),
                $this->attr($descendant, 'name'),
                $this->attr($descendant, 'aria-label'),
                $this->attr($descendant, 'value'),
                implode(' ', $this->safeDataAttributes($descendant)),
                $this->collapsedText($descendant),
            )));

            foreach ( $tokens as $token ) {
                if ( str_contains($haystack, $token) ) {
                    return true;
                }
            }

            // "add" alone is ambiguous, so require it to co-occur with a commerce
            // context word ("cart"/"bag"/"basket") to count as a cart control.
            if ( preg_match('/\badd\b/', $haystack) && preg_match('/\b(?:cart|bag|basket)\b/', $haystack) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the card contains quantity UI: number input, spinbutton, +/- controls,
     * or explicit quantity labels/classes/ARIA. This is diagnostic only.
     */
    private function hasQuantityControl(DOMElement $card): bool
    {
        foreach ( $card->getElementsByTagName('*') as $descendant ) {
            if ( ! $descendant instanceof DOMElement ) {
                continue;
            }

            $tagName = strtolower($descendant->tagName);
            $role = strtolower($this->attr($descendant, 'role'));
            if ( 'input' === $tagName && 'number' === strtolower($this->attr($descendant, 'type')) ) {
                return true;
            }
            if ( 'spinbutton' === $role ) {
                return true;
            }

            $haystack = strtolower(implode(' ', array(
                $this->attr($descendant, 'class'),
                $this->attr($descendant, 'id'),
                $this->attr($descendant, 'name'),
                $this->attr($descendant, 'aria-label'),
                implode(' ', $this->safeDataAttributes($descendant)),
                $this->collapsedText($descendant),
            )));

            if ( preg_match('/\b(?:qty|quantity|decrease|increase)\b/', $haystack) ) {
                return true;
            }
            if ( in_array($tagName, array( 'button', 'a' ), true) && preg_match('/^[+\x{2212}-]$/u', trim($this->collapsedText($descendant))) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * An optional short product description: the first paragraph in the card whose
     * text is neither the name nor a price. Returns null when none is present.
     */
    private function productDescriptionText(DOMElement $card, string $name): ?string
    {
        foreach ( $card->getElementsByTagName('p') as $paragraph ) {
            if ( ! $paragraph instanceof DOMElement ) {
                continue;
            }

            $text = $this->collapsedText($paragraph);
            if ( '' === $text || $text === $name || $this->looksLikePriceText($text) ) {
                continue;
            }

            return mb_strlen($text) > 280 ? mb_substr($text, 0, 277) . '...' : $text;
        }

        return null;
    }

    /**
     * The card's primary image as a generic { src, alt } pair, or null.
     *
     * @return array<string, string>|null
     */
    private function productImage(DOMElement $card): ?array
    {
        foreach ( $card->getElementsByTagName('img') as $image ) {
            if ( ! $image instanceof DOMElement ) {
                continue;
            }

            $src = trim($this->attr($image, 'src'));
            if ( '' === $src && '' !== trim($this->attr($image, 'data-src')) ) {
                $src = trim($this->attr($image, 'data-src'));
            }
            if ( '' === $src || preg_match('/^\s*javascript\s*:/i', $src) ) {
                continue;
            }

            return array_filter(array(
                'src' => $src,
                'alt' => trim($this->attr($image, 'alt')),
            ), static fn (mixed $value): bool => '' !== $value);
        }

        return null;
    }

    /**
     * Find the nearest descendant (or the element itself) declaring one of the
     * given schema.org `itemprop` values.
     *
     * @param array<int, string> $itemprops
     */
    private function firstDescendantWithItemprop(DOMElement $element, array $itemprops): ?DOMElement
    {
        if ( in_array(strtolower($this->attr($element, 'itemprop')), $itemprops, true) ) {
            return $element;
        }

        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement && in_array(strtolower($this->attr($descendant, 'itemprop')), $itemprops, true) ) {
                return $descendant;
            }
        }

        return null;
    }

    private function collapsedText(DOMElement $element): string
    {
        return trim(preg_replace('/\s+/', ' ', $element->textContent ?? '') ?? '');
    }

    /**
     * Whether an element carries structural interaction signals AND converts to
     * a static block with its behavior dropped (not preserved or rebuilt).
     */
    private function isInteractiveControlBehaviorLoss(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);

        // Elements with a dedicated preservation or diagnostic path. SVG (and
        // its subtree) is sanitized/diagnosed by the inline-SVG fallback paths,
        // which already account for any scriptable content.
        if ( in_array($tagName, array( 'form', 'input', 'select', 'textarea', 'details', 'summary', 'script', 'svg' ), true) ) {
            return false;
        }

        if ( $this->hasAncestorTag($element, array( 'svg' )) ) {
            return false;
        }

        if ( array() === $this->interactionSignalEvidence($element) ) {
            return false;
        }

        // Behavior is preserved or rebuilt elsewhere — not lost.
        if ( $this->isRedundantMenuToggleControl($element) ) {
            return false;
        }

        if ( $this->isFoldedIntoCoreNavigation($element) ) {
            return false;
        }

        if ( $this->isFoldedIntoNativeDisclosure($element) ) {
            return false;
        }

        if ( $this->isRuntimeDomTarget($element) ) {
            return false;
        }

        if ( $this->isPreservedRuntimeIslandElement($element) ) {
            return false;
        }

        if ( $this->hasAncestorTag($element, array( 'form' )) ) {
            return false;
        }

        return true;
    }

    /**
     * Structural/semantic interaction signals on an element, as generic evidence
     * tokens. Never matches class-name strings.
     *
     * @return array<int, string>
     */
    private function interactionSignalEvidence(DOMElement $element): array
    {
        $tagName = strtolower($element->tagName);
        $evidence = array();

        foreach ( $this->eventMetadata($element) as $event ) {
            $attribute = (string) ($event['attribute'] ?? '');
            if ( '' !== $attribute ) {
                $evidence[] = $attribute;
            }
        }

        foreach ( array( 'aria-controls', 'aria-expanded', 'aria-haspopup' ) as $ariaAttribute ) {
            if ( '' !== trim($this->attr($element, $ariaAttribute)) ) {
                $evidence[] = $ariaAttribute;
            }
        }

        // Only data-* attributes that unambiguously BIND behavior count as a
        // signal — never data-* that merely carries a value (e.g. data-target as
        // a counter goal). data-action/on/event also surface via eventMetadata.
        foreach ( array_keys($this->safeDataAttributes($element)) as $dataName ) {
            if ( preg_match('/^data-(?:action|on|event|toggle)$/i', (string) $dataName) ) {
                $evidence[] = strtolower((string) $dataName);
            }
        }

        if ( 'button' === strtolower($this->attr($element, 'role')) && 'button' !== $tagName ) {
            $href = 'a' === $tagName ? $this->safeLinkUrl($this->attr($element, 'href')) : '';
            if ( '' === $href ) {
                $evidence[] = 'role=button';
            }
        }

        return array_values(array_unique($evidence));
    }

    /**
     * Whether the element (or an ancestor) is a navigation menu that converts to
     * core/navigation, which rebuilds its toggle/submenu behavior natively.
     */
    private function isFoldedIntoCoreNavigation(DOMElement $element): bool
    {
        for ( $node = $element; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( $this->isNavigationMenuCandidate($node) && $this->convertsToCoreNavigation($node) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record a source element whose subtree converted to the native
     * `core/accordion` block, so its toggle controls are not later flagged as
     * interactive-control behavior loss. Returns the block unchanged for use as
     * a passthrough at recognizer call sites.
     *
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function rememberAccordionDisclosureRoot(array $block, DOMElement $element): array
    {
        if ( 'core/accordion' === ( $block['blockName'] ?? '' ) ) {
            $this->nativeDisclosureRootIds[ $element->getNodePath() ?? '' ] = true;
        }

        return $block;
    }

    /**
     * Whether the element is a disclosure toggle whose containing widget was
     * folded into a native zero-JS `core/details` block or the native
     * `core/accordion` block. The show/hide behavior is then carried natively
     * (no preserved JavaScript), so flagging behavior loss would be a false
     * positive — the same way `isFoldedIntoCoreNavigation()` excludes controls
     * rebuilt by `core/navigation`.
     *
     * The toggle is recognized structurally (its `aria-expanded`/`aria-controls`
     * disclosure state), never by class string, and only inside a subtree that
     * actually converted to a native disclosure block.
     */
    private function isFoldedIntoNativeDisclosure(DOMElement $element): bool
    {
        if ( array() === $this->nativeDisclosureRootIds ) {
            return false;
        }

        $hasDisclosureState = '' !== trim($this->attr($element, 'aria-expanded'))
            || '' !== trim($this->attr($element, 'aria-controls'));
        if ( ! $hasDisclosureState ) {
            return false;
        }

        for ( $node = $element; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( isset($this->nativeDisclosureRootIds[ $node->getNodePath() ?? '' ]) ) {
                return true;
            }
        }

        return false;
    }

    private function isPreservedRuntimeIslandElement(DOMElement $element): bool
    {
        $selector = $this->runtimeIslandSelector($element);
        foreach ( $this->runtimeIslands as $island ) {
            if ( is_array($island) && ($island['selector'] ?? null) === $selector ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $evidence
     * @return array<string, mixed>
     */
    private function interactionCandidate(DOMElement $element, string $kind, string $trigger, string $target, array $evidence, string $confidence, string $runtimeRequirement): array
    {
        return array_filter(
            array(
                'selector'                => $this->elementSelector($element),
                'kind'                    => $kind,
                'trigger'                 => $trigger,
                'target'                  => $target,
                'evidence'                => array_values(array_unique(array_filter($evidence, static fn (string $value): bool => '' !== $value))),
                'confidence'              => $confidence,
                'runtime_requirement'     => $runtimeRequirement,
                'materialization_hint'    => $this->materializationHintForInteractionKind($kind),
            ),
            static fn (mixed $value): bool => '' !== $value && array() !== $value
        );
    }

    private function targetForElement(DOMElement $element): string
    {
        $id = trim($this->attr($element, 'id'));
        return '' !== $id ? '#' . $id : $this->elementSelector($element);
    }

    private function controlledTarget(DOMElement $element): string
    {
        $target = trim($this->attr($element, 'aria-controls'));
        return '' !== $target ? '#' . ltrim($target, '#') : $this->targetForElement($element);
    }

    /**
     * @param array<int, array<string, string>> $events
     */
    private function controlTrigger(DOMElement $element, array $events): string
    {
        if ( array() !== $events ) {
            return (string) ($events[0]['type'] ?? 'event');
        }

        $type = strtolower($this->attr($element, 'type'));
        return 'submit' === $type ? 'submit' : 'click';
    }

    /**
     * @param array<int, array<string, string>> $events
     * @param array<int, string> $actionDataAttributes
     * @return array<int, string>
     */
    private function controlEvidence(DOMElement $element, array $events, array $actionDataAttributes): array
    {
        $evidence = array();
        foreach ( $events as $event ) {
            $attribute = (string) ($event['attribute'] ?? '');
            if ( '' !== $attribute ) {
                $evidence[] = $attribute;
            }
        }
        foreach ( $actionDataAttributes as $attribute ) {
            $evidence[] = $attribute;
        }
        if ( '' !== trim($this->attr($element, 'aria-controls')) ) {
            $evidence[] = 'aria-controls';
        }
        if ( '' !== trim($this->attr($element, 'aria-expanded')) ) {
            $evidence[] = 'aria-expanded';
        }

        return $evidence;
    }

    private function modalTriggerHint(DOMElement $element): string
    {
        return '' !== trim($this->attr($element, 'open')) ? 'open' : 'show';
    }

    private function carouselTriggerHint(DOMElement $element): string
    {
        return preg_match('/(?:^|[\s_-])(?:next|prev|previous)(?:$|[\s_-])/', strtolower($this->attr($element, 'class'))) ? 'advance' : 'slide';
    }

    private function materializationHintForInteractionKind(string $kind): string
    {
        return match ( $kind ) {
            'details' => 'preserve_native_details',
            'form' => 'preserve_or_replace_form_runtime',
            'tabs' => 'materialize_tab_panels_or_runtime',
            'accordion' => 'materialize_expanded_state_or_runtime',
            'carousel' => 'preserve_static_slides_or_runtime',
            'modal' => 'preserve_dialog_markup_or_runtime',
            default => 'preserve_static_markup_with_runtime_note',
        };
    }

    private function figureMediaElement(DOMElement $figure, string $tagName): ?DOMElement
    {
        $direct = $this->firstChildElement($figure, $tagName);
        if ( $direct instanceof DOMElement ) {
            return $direct;
        }

        $wrapper = null;
        foreach ( $figure->childNodes as $child ) {
            if ( $child instanceof DOMElement && 'figcaption' === strtolower($child->tagName) ) {
                continue;
            }

            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }

            if ( ! $child instanceof DOMElement || null !== $wrapper ) {
                return null;
            }

            $wrapper = $child;
        }

        if ( ! $wrapper instanceof DOMElement || ! in_array(strtolower($wrapper->tagName), array( 'div', 'span' ), true) || '' !== trim($wrapper->textContent ?? '') ) {
            return null;
        }

        return $this->onlyChildElement($wrapper, $tagName);
    }

    private function figureLinkedMediaAnchor(DOMElement $figure): ?DOMElement
    {
        $anchor = null;
        foreach ( $figure->childNodes as $child ) {
            if ( $child instanceof DOMElement && 'figcaption' === strtolower($child->tagName) ) {
                continue;
            }

            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }

            if ( ! $child instanceof DOMElement || 'a' !== strtolower($child->tagName) || null !== $anchor ) {
                return null;
            }

            $anchor = $child;
        }

        return $anchor instanceof DOMElement && $this->isImageOnlyAnchor($anchor) ? $anchor : null;
    }

    private function citationFromElement(DOMElement $element): string
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && in_array(strtolower($child->tagName), array( 'cite', 'footer', 'figcaption' ), true) ) {
                return $this->innerHtml($child);
            }
        }

        return '';
    }

    /**
     * Convert a figure that wraps non-media content (table, code, multiple
     * elements, or text) into the closest faithful native block(s).
     *
     * The figcaption is consumed as a trailing caption paragraph so it is never
     * emitted as a separate orphan fallback. A figure with a single child and no
     * caption unwraps to that child; otherwise the children plus caption are
     * preserved inside a core/group that carries the figure's presentation.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function convertFigureGeneric(DOMElement $figure, array &$fallbacks): ?array
    {
        $children = $this->convertChildrenWithoutTags($figure, $fallbacks, array( 'figcaption' ));

        $caption = $this->firstChildElement($figure, 'figcaption');
        if ( $caption instanceof DOMElement ) {
            $captionHtml = $this->innerHtml($caption);
            if ( '' !== trim($this->runtime->stripAllTags($captionHtml)) ) {
                $children[] = $this->createBlock('core/paragraph', array( 'content' => $captionHtml ), array(), $caption);
            }
        }

        if ( array() === $children ) {
            return null;
        }

        if ( 1 === count($children) && array() === $this->presentationAttributes($figure) ) {
            return $children[0];
        }

        return $this->createBlock('core/group', $this->presentationAttributes($figure), $children, $figure);
    }

    /**
     * @param array<int, string> $excludedTags
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<int, array<string, mixed>>
     */
    private function convertChildrenWithoutTags(DOMElement $element, array &$fallbacks, array $excludedTags): array
    {
        $blocks = array();
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && in_array(strtolower($child->tagName), $excludedTags, true) ) {
                continue;
            }

            if ( XML_TEXT_NODE === $child->nodeType ) {
                $text = trim($child->textContent ?? '');
                if ( '' !== $text ) {
                    $blocks = array_merge($blocks, $this->convertText($text));
                }
                continue;
            }

            if ( $child instanceof DOMElement ) {
                $block = $this->convertElement($child, $fallbacks, true);
                if ( null !== $block ) {
                    $blocks[] = $block;
                }
            }
        }

        return $blocks;
    }

    /**
     * @return array<string, mixed>
     */
    private function tableAttributes(DOMElement $table): array
    {
        $attrs = array();
        foreach ( array( 'thead' => 'head', 'tbody' => 'body', 'tfoot' => 'foot' ) as $sectionTag => $attrName ) {
            $rows = array();
            foreach ( $table->getElementsByTagName($sectionTag) as $section ) {
                if ( ! $this->belongsToTable($section, $table) ) {
                    continue;
                }
                foreach ( $section->getElementsByTagName('tr') as $row ) {
                    if ( ! $this->belongsToTable($row, $table) ) {
                        continue;
                    }
                    $rows[] = array( 'cells' => $this->tableCells($row) );
                }
            }
            if ( array() !== $rows ) {
                $attrs[$attrName] = $rows;
            }
        }

        if ( empty($attrs['body']) ) {
            $rows = array();
            foreach ( $table->getElementsByTagName('tr') as $row ) {
                if ( ! $this->belongsToTable($row, $table) ) {
                    continue;
                }
                if ( in_array($this->closestTagName($row), array( 'thead', 'tfoot' ), true) ) {
                    continue;
                }
                $rows[] = array( 'cells' => $this->tableCells($row) );
            }
            if ( array() !== $rows ) {
                $attrs['body'] = $rows;
            }
        }

        $caption = $this->firstChildElement($table, 'caption');
        if ( $caption instanceof DOMElement ) {
            $attrs['caption'] = $this->innerHtml($caption);
        }

        return $attrs;
    }

    private function belongsToTable(DOMElement $element, DOMElement $table): bool
    {
        for ( $node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( 'table' !== strtolower($node->tagName) ) {
                continue;
            }

            return $node->isSameNode($table);
        }

        return false;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function tableCells(DOMElement $row): array
    {
        $cells = array();
        foreach ( $row->childNodes as $cell ) {
            if ( ! $cell instanceof DOMElement || ! in_array(strtolower($cell->tagName), array( 'td', 'th' ), true) ) {
                continue;
            }
            $cells[] = array(
                'content' => $this->innerHtml($cell),
                'tag'     => strtolower($cell->tagName),
            );
        }
        return $cells;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function definitionListItems(DOMElement $list): array
    {
        $items = array();
        $term = '';

        foreach ( $list->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $tagName = strtolower($child->tagName);
            if ( 'dt' === $tagName ) {
                $term = $this->innerHtml($child);
                continue;
            }

            if ( 'dd' === $tagName ) {
                $description = $this->innerHtml($child);
                if ( '' === trim($this->runtime->stripAllTags($term . $description)) ) {
                    continue;
                }

                $prefix = '' !== trim($term) ? '<strong>' . $term . '</strong>' : '';
                $items[] = $this->createBlock('core/list-item', array_merge($this->presentationAttributes($child), array(
                    'content' => trim($prefix . ( '' !== $prefix && '' !== trim($description) ? ' ' : '' ) . $description),
                )), array(), $child);
            }
        }

        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<int, array<string, mixed>>
     */
    private function listItems(DOMElement $list, array &$fallbacks): array
    {
        $items = array();
        foreach ( $list->childNodes as $child ) {
            if ( ! $child instanceof DOMElement || 'li' !== strtolower($child->tagName) ) {
                continue;
            }

            $nested = array();
            foreach ( $child->childNodes as $itemChild ) {
                if ( $itemChild instanceof DOMElement && in_array(strtolower($itemChild->tagName), array( 'ul', 'ol' ), true) ) {
                    $nestedBlock = $this->convertElement($itemChild, $fallbacks, true);
                    if ( null !== $nestedBlock ) {
                        $nested[] = $nestedBlock;
                    }
                }
            }

            $content = $this->innerHtmlWithoutTags($child, array( 'ul', 'ol' ));
            if ( '' === trim($this->runtime->stripAllTags($content)) && array() === $nested ) {
                continue;
            }

            $items[] = $this->createBlock('core/list-item', array_merge($this->presentationAttributes($child), array( 'content' => $content )), $nested, $child);
        }

        return $items;
    }

    /**
     * Whether a `<ul>`/`<ol>` is a stack of "structured inline cards" rather than
     * a normal list.
     *
     * A structured card list is one whose every content-bearing `<li>` is built
     * from MULTIPLE class/style-carrying inline fragments — the universal
     * blog/news/essay-index row of a title link plus dek/meta spans
     * (`<a class>` + `<span class>` + `<span class>`). core/list-item stores its
     * content as RichText, which only preserves a fixed set of inline formats, so
     * the class on an inner `<a>`/`<span>` is dropped on parse (saved markup
     * diverges from the regenerated block) and the per-fragment styling hooks the
     * materialized CSS targets are lost. A single list item also cannot carry the
     * distinct class of each fragment, so the row is really a mini-card.
     *
     * Keys off STRUCTURE (multiple styling-hook inline fragments), never on any
     * specific class name. A plain-text list, a simple link list, a flowing
     * sentence with one inline link, or a list item that carries block-level
     * children (an image/heading/paragraph product card owned by the commerce
     * path) is NOT a structured card and stays a normal core/list.
     */
    private function isStructuredCardList(DOMElement $list): bool
    {
        $cardItems = 0;
        foreach ( $list->childNodes as $child ) {
            if ( ! $child instanceof DOMElement || 'li' !== strtolower($child->tagName) ) {
                continue;
            }
            if ( '' === trim($this->runtime->stripAllTags($this->innerHtmlWithoutTags($child, array( 'ul', 'ol' )))) ) {
                continue;
            }
            if ( ! $this->isStructuredCardItem($child) ) {
                return false;
            }
            ++$cardItems;
        }

        return $cardItems > 0;
    }

    /**
     * A `<li>` that is a structured inline card: all of its content is inline
     * (text + inline formats/links — no block-level children), and it carries at
     * least two class/style styling-hook inline fragments (e.g. a classed title
     * link plus dek/meta spans). The "two hooks" threshold distinguishes a
     * stacked card from flowing text that merely contains a single inline link.
     */
    private function isStructuredCardItem(DOMElement $item): bool
    {
        $stylingHookFragments = 0;
        foreach ( $item->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                continue;
            }
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $tag = strtolower($child->tagName);
            if ( in_array($tag, array( 'ul', 'ol' ), true) ) {
                continue;
            }

            // A block-level child means this is not an inline card (e.g. a
            // product card with <img>/<h3>/<p>); leave it to the normal path.
            if ( 'br' !== $tag && 'a' !== $tag && ! $this->isInlineContentElement($tag) ) {
                return false;
            }

            if ( $this->isStylingHookInline($child) ) {
                ++$stylingHookFragments;
            }
        }

        return $stylingHookFragments >= 2;
    }

    /**
     * An inline element carrying a class/style styling hook RichText cannot
     * store: a styling-hook `<span>` (class/style only), or any link/inline
     * format element (`<a>`, `<strong>`, …) with a non-empty class or style.
     */
    private function isStylingHookInline(DOMElement $element): bool
    {
        $tag = strtolower($element->tagName);
        if ( 'span' === $tag ) {
            return $this->isStylingHookSpan($element);
        }
        if ( 'a' !== $tag && ! $this->isInlineContentElement($tag) ) {
            return false;
        }

        return '' !== trim($this->attr($element, 'class')) || '' !== trim($this->attr($element, 'style'));
    }

    /**
     * Decompose a structured card `<ul>`/`<ol>` into a `core/group` of per-item
     * `core/group`s. Each fragment of an item becomes its own block carrying its
     * hoisted styling hook, so the result is fully valid (group/paragraph
     * round-trip and store a custom className) while the per-fragment styling
     * hooks and the working link survive — which a single core/list-item cannot
     * represent. The outer group inherits the list's presentation, each inner
     * group inherits its `<li>`'s presentation.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function decomposeStructuredCardList(DOMElement $list, array &$fallbacks): ?array
    {
        $itemGroups = array();
        foreach ( $list->childNodes as $child ) {
            if ( ! $child instanceof DOMElement || 'li' !== strtolower($child->tagName) ) {
                continue;
            }

            $itemGroup = $this->structuredCardItemGroup($child, $fallbacks);
            if ( null !== $itemGroup ) {
                $itemGroups[] = $itemGroup;
            }
        }

        if ( array() === $itemGroups ) {
            return null;
        }

        return $this->createBlock('core/group', $this->presentationAttributes($list), $itemGroups, $list);
    }

    /**
     * Build the per-item `core/group` for one structured card `<li>`: a paragraph
     * per inline fragment (the title link, the dek, the meta), each carrying the
     * fragment's hoisted styling hook, plus any nested list converted in place.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function structuredCardItemGroup(DOMElement $item, array &$fallbacks): ?array
    {
        $fragmentBlocks = array();
        foreach ( $item->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                $text = trim($child->textContent ?? '');
                if ( '' !== $text ) {
                    $fragmentBlocks[] = $this->createBlock('core/paragraph', array( 'content' => $this->runtime->escapeHtml($text) ));
                }
                continue;
            }
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $tag = strtolower($child->tagName);
            if ( in_array($tag, array( 'ul', 'ol' ), true) ) {
                $nested = $this->convertElement($child, $fallbacks, true);
                if ( null !== $nested ) {
                    $fragmentBlocks[] = $nested;
                }
                continue;
            }

            $block = $this->cardFragmentBlock($child);
            if ( null !== $block ) {
                $fragmentBlocks[] = $block;
            }
        }

        if ( array() === $fragmentBlocks ) {
            return null;
        }

        return $this->createBlock('core/group', $this->presentationAttributes($item), $fragmentBlocks, $item);
    }

    /**
     * Turn one inline card fragment into a `core/paragraph` that round-trips
     * through RichText while keeping the fragment's styling hook on the block.
     *
     *   - A link fragment stays a valid RichText anchor (`<a href>` with its
     *     RichText-dropped class/style stripped) and its class/style are hoisted
     *     onto the paragraph, so the styling hook survives and the link works.
     *   - A styling-hook `<span>` is unwrapped to its inner content and its
     *     class/style are hoisted onto the paragraph.
     *   - Any other inline fragment is kept verbatim inside the paragraph;
     *     createBlock's span hoisting normalizes any nested styling-hook spans.
     *
     * @return array<string, mixed>|null
     */
    private function cardFragmentBlock(DOMElement $element): ?array
    {
        $tag = strtolower($element->tagName);

        if ( 'a' === $tag ) {
            $content = $this->anchorWithoutStylingAttributes($element);
            if ( '' === trim($this->runtime->stripAllTags($content)) ) {
                return null;
            }

            return $this->createBlock('core/paragraph', array_merge(
                $this->hoistedStylingAttributes($element),
                array( 'content' => $content )
            ));
        }

        if ( 'span' === $tag && $this->isStylingHookSpan($element) ) {
            $content = $this->innerHtml($element);
            if ( '' === trim($this->runtime->stripAllTags($content)) ) {
                return null;
            }

            return $this->createBlock('core/paragraph', array_merge(
                $this->hoistedStylingAttributes($element),
                array( 'content' => $content )
            ));
        }

        $content = $this->outerHtml($element);
        if ( '' === trim($this->runtime->stripAllTags($content)) ) {
            return null;
        }

        return $this->createBlock('core/paragraph', array( 'content' => $content ));
    }

    /**
     * Map an element's source class/style into the block `className` + canonical
     * `style` object attributes, so the styling hook rides where the block save()
     * reproduces it.
     *
     * @return array<string, mixed>
     */
    private function hoistedStylingAttributes(DOMElement $element): array
    {
        $attrs = array();

        $className = $this->promotedClassName($this->attr($element, 'class'));
        if ( '' !== trim($className) ) {
            $attrs['className'] = $className;
        }

        $style = trim($this->attr($element, 'style'));
        if ( '' !== $style ) {
            $mapped = $this->styleAttributeMapper()->map($this->cssDeclarations($style))['style'];
            if ( array() !== $mapped ) {
                $attrs['style'] = $mapped;
            }
        }

        return $attrs;
    }

    /**
     * Serialize an `<a>` with its RichText-dropped presentational attributes
     * (class/style) removed and an unsafe href dropped, leaving a clean anchor
     * RichText preserves. When no safe href remains, the link text is returned
     * without the anchor so no broken/empty link is emitted.
     */
    private function anchorWithoutStylingAttributes(DOMElement $anchor): string
    {
        $attributes = array();
        foreach ( $this->htmlAttributes($anchor) as $name => $value ) {
            if ( in_array(strtolower($name), array( 'class', 'style' ), true) ) {
                continue;
            }
            $attributes[$name] = $value;
        }

        $href = $this->safeNavigationUrl($this->attr($anchor, 'href'));
        $inner = $this->innerHtml($anchor);
        if ( '' === $href ) {
            return $inner;
        }

        $attributes['href'] = $href;
        return '<a' . $this->htmlAttributeString($attributes) . '>' . $inner . '</a>';
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     */
    private function captureInlineSvgFallback(DOMElement $element, array &$fallbacks): void
    {
        $this->fallbackEmitter->captureInlineSvgFallback($element, $fallbacks);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     */
    private function captureCanvasFallback(DOMElement $element, array &$fallbacks): void
    {
        $this->fallbackEmitter->captureCanvasFallback($element, $fallbacks, $this->runtimeIslands);
    }

    private function isRuntimeCanvasTarget(DOMElement $element): bool
    {
        return $this->fallbackEmitter->isRuntimeCanvasTarget($element);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, bool>
     */
    private function runtimeCanvasSelectorsFromOptions(array $options): array
    {
        return $this->runtimeSelectorsFromOptions($options, 'runtime_canvas_selectors');
    }

    private function isRuntimeDomTarget(DOMElement $element): bool
    {
        $id = trim($this->attr($element, 'id'));
        if ( '' !== $id && isset($this->runtimeDomSelectors['#' . $id]) && ! $this->isPresentationalAnimationSelector('#' . $id) ) {
            return true;
        }

        foreach ( preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array() as $class ) {
            if ( '' !== $class && isset($this->runtimeDomSelectors['.' . $class]) && ! $this->isPresentationalAnimationSelector('.' . $class) ) {
                return true;
            }
        }

        foreach ( array_keys($this->runtimeDomSelectors) as $selector ) {
            if ( $this->isPresentationalAnimationSelector((string) $selector) ) {
                continue;
            }

            if ( $this->elementMatchesRuntimeSelector($element, (string) $selector) ) {
                return true;
            }
        }

        return false;
    }

    private function shouldPreserveRuntimeAppShell(DOMElement $element): bool
    {
        if ( array() === $this->runtimeDomSelectors && array() === $this->runtimeCanvasSelectors ) {
            return false;
        }

        $tagName = strtolower($element->tagName);
        if ( ShellLandmarkPolicy::isGlobalShellLandmarkTag($tagName) ) {
            return false;
        }

        $targets = $this->runtimeTargetsInSubtree($element, 4);
        if ( count($targets) < 2 ) {
            return false;
        }

        $signals = $this->runtimeAppShellSignals($element);
        if ( in_array($tagName, array( 'body', 'main' ), true) && ! in_array('app_root_token', $signals, true) ) {
            return false;
        }

        return in_array('app_root_token', $signals, true) || in_array('workspace_surface', $signals, true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function runtimeTargetsInSubtree(DOMElement $element, int $limit): array
    {
        $targets = array();
        foreach ( $this->descendantElements($element) as $descendant ) {
            if ( $this->isRuntimeDomTarget($descendant) || $this->isRuntimeCanvasTarget($descendant) ) {
                $targets[] = array_filter(array(
                    'selector'   => $this->runtimeIslandSelector($descendant),
                    'tag'        => strtolower($descendant->tagName),
                    'attributes' => $this->boundedRuntimeTargetAttributes($descendant),
                ), static fn (mixed $value): bool => '' !== $value && array() !== $value);
            }

            if ( count($targets) >= $limit ) {
                break;
            }
        }

        return $targets;
    }

    private function shouldRecordRuntimeHtmlSubtreeIsland(DOMElement $element): bool
    {
        if ( ! in_array(strtolower($element->tagName), array( 'article', 'aside', 'div', 'main', 'section' ), true) ) {
            return false;
        }

        if ( $this->isRuntimeDomTarget($element) ) {
            return false;
        }

        if ( 0 < count($this->runtimeTargetsInSubtree($element, 1)) ) {
            return true;
        }

        foreach ( $this->descendantElements($element) as $descendant ) {
            $tagName = strtolower($descendant->tagName);
            if ( 'form' === $tagName && $this->formHasDataEntryControls($descendant) ) {
                return true;
            }
            if ( in_array($tagName, array( 'canvas', 'template' ), true) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    private function recordRuntimeIslandsForPreservedHtmlBlocks(array $blocks): void
    {
        foreach ( $blocks as $block ) {
            if ( ! is_array($block) ) {
                continue;
            }

            if ( 'core/html' === ($block['blockName'] ?? '') ) {
                $content = is_array($block['attrs'] ?? null) && is_scalar($block['attrs']['content'] ?? null) ? (string) $block['attrs']['content'] : '';
                $element = $this->preservedHtmlRootElement($content);
                if ( $element instanceof DOMElement && $this->shouldRecordRuntimeHtmlSubtreeIsland($element) ) {
                    $targets = $this->runtimeTargetsInSubtree($element, 8);
                    $this->recordRuntimeIsland($element, 'app_shell', 'runtime_html_subtree', 'client_script_execution', array(
                        'events'            => $this->eventMetadata($element),
                        'target_count'      => count($targets),
                        'targets'           => $targets,
                        'app_shell_signals' => $this->runtimeAppShellSignals($element),
                        'required_scripts'  => $this->requiredScriptsForElement($element),
                    ));
                }
            }

            if ( isset($block['innerBlocks']) && is_array($block['innerBlocks']) ) {
                $this->recordRuntimeIslandsForPreservedHtmlBlocks($block['innerBlocks']);
            }
        }
    }

    private function preservedHtmlRootElement(string $html): ?DOMElement
    {
        if ( '' === trim($html) ) {
            return null;
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $this->normalizeHtml5VoidElements($html) . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ( ! $loaded ) {
            return null;
        }

        $body = $document->getElementsByTagName('body')->item(0);
        if ( ! $body instanceof DOMElement ) {
            return null;
        }

        foreach ( $body->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                return $child;
            }
        }

        return null;
    }

    /**
     * @return array<int, DOMElement>
     */
    private function descendantElements(DOMElement $element): array
    {
        $descendants = array();
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            $descendants[] = $child;
            foreach ( $this->descendantElements($child) as $grandchild ) {
                $descendants[] = $grandchild;
            }
        }

        return $descendants;
    }

    /**
     * @return array<int, string>
     */
    private function runtimeAppShellSignals(DOMElement $element): array
    {
        $signals = array();
        if ( $this->hasRuntimeAppRootToken($element) ) {
            $signals[] = 'app_root_token';
        }
        if ( $this->hasWorkspaceSurface($element) ) {
            $signals[] = 'workspace_surface';
        }

        return array_values(array_unique($signals));
    }

    private function hasRuntimeAppRootToken(DOMElement $element): bool
    {
        $tokens = preg_split('/[^A-Za-z0-9]+/', strtolower(trim($this->attr($element, 'id') . ' ' . $this->attr($element, 'class')))) ?: array();
        foreach ( $tokens as $token ) {
            if ( in_array($token, self::RUNTIME_APP_ROOT_TOKENS, true) ) {
                return true;
            }
        }

        return false;
    }

    private function hasWorkspaceSurface(DOMElement $element): bool
    {
        foreach ( $this->descendantElements($element) as $descendant ) {
            $tagName = strtolower($descendant->tagName);
            if ( in_array($tagName, array( 'canvas', 'iframe', 'template' ), true) ) {
                return true;
            }
            if ( 'textarea' === $tagName && $this->textareaIsRuntimeWorkspaceSurface($descendant, $element) ) {
                return true;
            }
            if ( '' !== trim($this->attr($descendant, 'contenteditable')) ) {
                return true;
            }
        }

        return false;
    }

    private function textareaIsRuntimeWorkspaceSurface(DOMElement $textarea, DOMElement $root): bool
    {
        if ( ! $this->isRuntimeDomTarget($textarea) || $this->hasFormAncestor($textarea) ) {
            return false;
        }

        // A plain wrapper that pairs data entry with a submit action is a
        // pseudo-form, not an editor surface. Only a non-control target inside
        // that same candidate upgrades it to a runtime workspace.
        for ( $ancestor = $textarea->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode ) {
            if ( $this->isDivBasedPseudoForm($ancestor) ) {
                return $ancestor === $root && $this->hasNonFormControlRuntimeTarget($ancestor);
            }
            if ( $ancestor === $root ) {
                break;
            }
        }

        return true;
    }

    private function hasNonFormControlRuntimeTarget(DOMElement $element): bool
    {
        foreach ( $this->descendantElements($element) as $descendant ) {
            if ( $this->isRuntimeDomTarget($descendant) && ! $this->isFormControlElement($descendant) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function boundedRuntimeTargetAttributes(DOMElement $element): array
    {
        $attributes = array();
        foreach ( array( 'id', 'class', 'role', 'aria-label', 'type', 'name' ) as $name ) {
            $value = trim($this->attr($element, $name));
            if ( '' !== $value ) {
                $attributes[$name] = substr($value, 0, 160);
            }
        }

        foreach ( $element->attributes ?? array() as $attribute ) {
            if ( str_starts_with(strtolower($attribute->name), 'data-') ) {
                $attributes[$attribute->name] = substr((string) $attribute->value, 0, 160);
            }
        }

        return $attributes;
    }

    private function shouldPreserveDataAttributeRuntimeTarget(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( in_array($tagName, array( 'canvas', 'form', 'script' ), true) || $this->isFormControlElement($element) ) {
            return false;
        }

        foreach ( array_keys($this->runtimeDomSelectors) as $selector ) {
            if ( str_contains((string) $selector, '[') && $this->elementMatchesRuntimeSelector($element, (string) $selector) ) {
                if ( $this->isPresentationalAnimationSelector((string) $selector) ) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

    private function isPresentationalAnimationSelector(string $selector): bool
    {
        $name = '';
        if ( preg_match('/\[(data-[A-Za-z][A-Za-z0-9_-]*)/', $selector, $match) ) {
            $name = substr(strtolower((string) $match[1]), 5);
        } elseif ( preg_match('/^(?:[a-z][a-z0-9-]*\.|\.)([A-Za-z][A-Za-z0-9_-]*)$/', $selector, $match) ) {
            $name = strtolower((string) $match[1]);
        } elseif ( preg_match('/^#([A-Za-z][A-Za-z0-9_-]*)$/', $selector, $match) ) {
            $name = strtolower((string) $match[1]);
        }

        if ( '' === $name ) {
            return false;
        }

        foreach ( preg_split('/[^a-z0-9]+/', $name) ?: array() as $token ) {
            if ( in_array($token, array( 'animate', 'animation', 'appear', 'count', 'counter', 'delay', 'fade', 'motion', 'parallax', 'reveal', 'scroll', 'stagger', 'transition' ), true) ) {
                return true;
            }
        }

        return false;
    }

    private function elementMatchesRuntimeSelector(DOMElement $element, string $selector): bool
    {
        $tag = strtolower($element->tagName);
        if ( $selector === $tag && in_array($tag, array_merge(array('canvas', 'svg'), self::RUNTIME_TAG_SELECTORS), true) ) {
            return true;
        }
        if ( preg_match('/^([a-z][a-z0-9-]*)\.([A-Za-z][A-Za-z0-9_-]*)$/', $selector, $match) ) {
            return $tag === strtolower((string) $match[1]) && in_array((string) $match[2], preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array(), true);
        }
        if ( preg_match('/^(?:([a-z][a-z0-9-]*))?\[(data-[A-Za-z][A-Za-z0-9_-]*)(?:=["\'][^"\']{1,80}["\'])?\]$/', $selector, $match) ) {
            return ( '' === (string) ($match[1] ?? '') || $tag === strtolower((string) $match[1]) ) && $element->hasAttribute(strtolower((string) $match[2]));
        }

        return false;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function recordRuntimeIsland(DOMElement $element, string $kind, string $reason, string $runtimeRequirement, array $metadata = array()): void
    {
        $this->fallbackEmitter->recordRuntimeIsland($element, $kind, $reason, $runtimeRequirement, $metadata, $this->runtimeIslands);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function requiredScriptsForElement(DOMElement $element): array
    {
        return $this->fallbackEmitter->requiredScriptsForElement($element);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>>
     */
    private function runtimeScriptMetadataFromOptions(array $options): array
    {
        $metadata = array();
        foreach ( $options['runtime_script_metadata'] ?? array() as $script ) {
            if ( ! is_array($script) ) {
                continue;
            }

            $metadata[] = array_filter(array(
                'path'               => is_string($script['path'] ?? null) ? $script['path'] : '',
                'selector'           => is_string($script['selector'] ?? null) ? $script['selector'] : '',
                'attributes'         => is_array($script['attributes'] ?? null) ? $script['attributes'] : array(),
                'script_role'        => 'runtime',
                'script_source_kind' => is_string($script['script_source_kind'] ?? null) ? $script['script_source_kind'] : 'external',
            ), static fn (mixed $value): bool => '' !== $value && array() !== $value);
        }

        return $this->dedupeArrayRows($metadata);
    }

    /**
     * Resolve the generated custom-block namespace from transform options,
     * defaulting to a generic namespace for standalone transforms.
     *
     * @param array<string, mixed> $options
     */
    private function generatedBlockNamespaceFromOptions(array $options): string
    {
        $namespace = is_scalar($options['generated_block_namespace'] ?? null) ? trim((string) $options['generated_block_namespace']) : '';

        return '' !== $namespace ? $namespace : 'custom';
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, bool>
     */
    private function runtimeSelectorsFromOptions(array $options, string $key): array
    {
        $selectors = array();
        foreach ( $options[$key] ?? array() as $selector ) {
            if ( is_string($selector) && $this->isBoundedRuntimeSelector($selector) ) {
                $selectors[$selector] = true;
            }
        }

        return $selectors;
    }

    private function isBoundedRuntimeSelector(string $selector): bool
    {
        $name = '[A-Za-z][A-Za-z0-9_-]*';
        $runtimeTags = implode('|', self::RUNTIME_TAG_SELECTORS);
        return 1 === preg_match('/^(?:[#.]' . $name . '|' . $name . '\.' . $name . '|\[data-' . $name . '(?:=["\'][^"\']{1,80}["\'])?\]|' . $name . '\[data-' . $name . '(?:=["\'][^"\']{1,80}["\'])?\]|canvas|svg|' . $runtimeTags . ')$/', $selector);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     */
    private function captureScriptFallback(DOMElement $element, array &$fallbacks): void
    {
        $this->fallbackEmitter->captureScriptFallback($element, $fallbacks, $this->runtimeIslands);
    }

    private function captureStaticScriptMetadata(DOMElement $element): bool
    {
        return $this->fallbackEmitter->captureStaticScriptMetadata($element, $this->scriptMetadata);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     */
    private function captureTemplateFallback(DOMElement $element, array &$fallbacks): void
    {
        $this->fallbackEmitter->captureTemplateFallback($element, $fallbacks, $this->runtimeIslands);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function formControls(DOMElement $form): array
    {
        $controls = array();
        $order = 0;
        foreach ( $this->formControlElements($form) as $control ) {
            $metadata = $this->formControlMetadata($control);
            if ( array() !== $metadata ) {
                $metadata['order'] = $order;
                $controls[] = $metadata;
                ++$order;
            }
        }

        return $controls;
    }

    /**
     * @return array<string, mixed>
     */
    private function formMetadata(DOMElement $form): array
    {
        $metadata = array_filter(
            array(
                'id'         => $this->attr($form, 'id'),
                'name'       => $this->attr($form, 'name'),
                'class'      => $this->attr($form, 'class'),
                'aria_label' => $this->attr($form, 'aria-label'),
                'action'     => $this->attr($form, 'action'),
                'method'     => strtolower($this->attr($form, 'method')),
                'enctype'    => $this->attr($form, 'enctype'),
                'target'     => $this->attr($form, 'target'),
                'autocomplete' => $this->attr($form, 'autocomplete'),
            ),
            static fn (string $value): bool => '' !== $value
        );

        foreach ( array( 'novalidate' ) as $attribute ) {
            if ( $form->hasAttribute($attribute) ) {
                $metadata[$attribute] = true;
            }
        }

        return $metadata;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function searchBlockFromForm(DOMElement $form): ?array
    {
        $method = strtolower(trim($this->attr($form, 'method')));
        if ( '' !== $method && 'get' !== $method ) {
            return null;
        }

        if ( 0 < $form->getElementsByTagName('script')->length || array() !== $this->eventMetadata($form) ) {
            return null;
        }

        $textInput = null;
        $submitControl = null;
        foreach ( $this->formControlElements($form) as $control ) {
            if ( array() !== $this->eventMetadata($control) ) {
                return null;
            }

            $tagName = strtolower($control->tagName);
            $type = $this->formControlType($control);
            if ( 'input' === $tagName && in_array($type, array( 'text', 'search' ), true) ) {
                if ( null !== $textInput ) {
                    return null;
                }
                $textInput = $control;
                continue;
            }

            if ( ( 'button' === $tagName || 'input' === $tagName ) && 'submit' === $type ) {
                if ( null !== $submitControl ) {
                    return null;
                }
                $submitControl = $control;
                continue;
            }

            return null;
        }

        if ( ! $textInput instanceof DOMElement || ! $this->hasSearchFormSignal($form, $textInput) ) {
            return null;
        }

        return $this->htmlPreservationBlock($form);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function searchBlockFromStandaloneControl(DOMElement $element): ?array
    {
        if ( 0 < $element->getElementsByTagName('form')->length || 0 < $element->getElementsByTagName('script')->length || array() !== $this->eventMetadata($element) || $this->isRuntimeDomTarget($element) ) {
            return null;
        }

        $inputs = array();
        foreach ( $element->getElementsByTagName('input') as $input ) {
            if ( $input instanceof DOMElement && $input->parentNode === $element && 'search' === $this->formControlType($input) ) {
                $inputs[] = $input;
            }
        }
        if ( 1 !== count($inputs) || array() !== $this->eventMetadata($inputs[0]) || $this->isRuntimeDomTarget($inputs[0]) ) {
            return null;
        }
        $controls = $this->formControlElements($element);
        if ( 1 !== count($controls) ) {
            return null;
        }

        $searchInput = $inputs[0];
        if ( ! $this->hasStandaloneSearchSignal($element, $searchInput) ) {
            return null;
        }

        $label = $this->formControlLabel($searchInput);
        if ( '' === $label ) {
            $label = $this->attr($searchInput, 'aria-label');
        }
        if ( '' === $label ) {
            $label = $this->attr($searchInput, 'placeholder');
        }

        if ( '' !== $this->attr($searchInput, 'id') || 's' !== $this->attr($searchInput, 'name') ) {
            return $this->htmlPreservationBlock($element);
        }
        if ( 1 !== $this->childElementCount($element) ) {
            return null;
        }

        $placeholder = $this->attr($searchInput, 'placeholder');
        return $this->createBlock('core/search', array_merge($this->presentationAttributes($element), array(
            'label'          => '' !== $label ? $label : 'Search',
            'showLabel'      => false,
            'placeholder'    => $placeholder,
            'buttonPosition' => 'no-button',
        )), array(), $element);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readableFormBlockFromForm(DOMElement $form, bool $allowFormEvents = false): ?array
    {
        if ( 0 < $form->getElementsByTagName('script')->length || ( ! $allowFormEvents && array() !== $this->eventMetadata($form) ) ) {
            return null;
        }

        $contentBlocks = array();
        $buttonBlocks = array();
        foreach ( $this->formControlElements($form) as $control ) {
            if ( array() !== $this->eventMetadata($control) || ! $this->isReadableFormControl($control) ) {
                return null;
            }

            if ( 'submit' === $this->formControlType($control) ) {
                $buttonBlocks[] = $this->createBlock('core/button', array_merge($this->presentationAttributes($control), array(
                    'text' => $this->runtime->escapeHtml($this->readableSubmitText($control)),
                )), array(), $control);
                continue;
            }

            if ( $this->isRuntimeDomTarget($control) ) {
                $this->recordRuntimeIsland($control, 'control', 'runtime_dom_target', 'client_script_execution', array(
                    'control'          => $this->formControlMetadata($control),
                    'events'           => $this->eventMetadata($control),
                    'required_scripts' => $this->requiredScriptsForElement($control),
                ));
            }

            $readableControlBlock = $this->readableFormControlBlockFromElement($control);
            if ( null !== $readableControlBlock ) {
                $contentBlocks[] = $readableControlBlock;
            }
        }

        if ( array() !== $buttonBlocks ) {
            $contentBlocks[] = $this->createBlock('core/buttons', array(), $buttonBlocks, $form);
        }

        if ( array() === $contentBlocks ) {
            return null;
        }

        return $this->createBlock('core/group', $this->presentationAttributes($form), $contentBlocks, $form);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readableFormControlBlockFromElement(DOMElement $element): ?array
    {
        $tagName = strtolower($element->tagName);
        if ( 'label' === $tagName ) {
            $controls = $this->formControlElements($element);
            if ( array() !== $controls ) {
                $blocks = array();
                foreach ( $controls as $control ) {
                    if ( ! $this->isReadableFormControl($control) || array() !== $this->eventMetadata($control) ) {
                        return null;
                    }

                    if ( $this->isRuntimeDomTarget($control) ) {
                        $this->recordRuntimeControlIsland($control);
                        return $this->htmlPreservationBlock($element);
                    }

                    $summary = $this->readableFormControlText($control);
                    if ( '' !== $summary ) {
                        $blocks[] = $this->createBlock('core/paragraph', array( 'content' => $summary ), array(), $control);
                    }
                }

                if ( 1 === count($blocks) ) {
                    return $blocks[0];
                }

                return array() !== $blocks ? $this->createBlock('core/group', $this->presentationAttributes($element), $blocks, $element) : null;
            }

            $label = $this->normalizedControlLabelText($element);
            if ( '' === $label ) {
                $label = trim(preg_replace('/\s+/', ' ', $element->textContent ?? '') ?? '');
            }

            return '' !== $label ? $this->createBlock('core/paragraph', array( 'content' => $this->runtime->escapeHtml($label) ), array(), $element) : null;
        }

        if ( ! $this->isFormControlElement($element) || ! $this->isReadableFormControl($element) || array() !== $this->eventMetadata($element) ) {
            return null;
        }

        if ( 'input' === $tagName && 'search' === $this->formControlType($element) ) {
            $label = $this->formControlLabel($element);
            if ( '' === $label ) {
                $label = $this->attr($element, 'aria-label');
            }
            if ( '' === $label ) {
                $label = 'Search';
            }

            return $this->htmlPreservationBlock($element);
        }

        if ( $this->isRuntimeDomTarget($element) ) {
            $this->recordRuntimeControlIsland($element);
            return $this->htmlPreservationBlock($element);
        }

        if ( 'select' === $tagName ) {
            $selectBlock = $this->readableSelectBlockFromElement($element);
            if ( null !== $selectBlock ) {
                return $selectBlock;
            }
        }

        $summary = $this->readableFormControlText($element);
        if ( '' === $summary ) {
            return null;
        }

        return $this->createBlock('core/paragraph', array_merge($this->presentationAttributes($element), array( 'content' => $summary )), array(), $element);
    }

    private function htmlPreservationBlock(DOMElement $element): array
    {
        return $this->createBlock('core/html', array( 'content' => $this->outerHtml($element) ), array(), $element);
    }

    private function recordRuntimeControlIsland(DOMElement $element): void
    {
        $this->recordRuntimeIsland($element, 'control', 'runtime_dom_target', 'client_script_execution', array(
            'control'          => $this->formControlMetadata($element),
            'events'           => $this->eventMetadata($element),
            'required_scripts' => $this->requiredScriptsForElement($element),
        ));
    }

    /**
     * Preserve a standalone form control that has no faithful native block or
     * readable static approximation as a bounded runtime island instead of an
     * unsupported-element loss.
     *
     * Reached only after the readable-control and search paths decline, so the
     * control is one whose behavior depends on a client runtime: file/hidden/
     * color/date-style inputs core blocks cannot represent, or any control
     * carrying inline event handlers. The source markup is carried in the
     * island snippet so the behavior can be re-attached, and no misleading
     * static text is emitted for controls (often hidden) that have no visual
     * representation. This yields a `preserved_runtime_island` outcome rather
     * than an `unsupported_element_loss`.
     */
    private function preserveStandaloneFormControlAsRuntimeIsland(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( ! in_array($tagName, array( 'input', 'select', 'textarea' ), true) ) {
            return false;
        }

        $this->recordRuntimeIsland($element, 'control', 'form_control_requires_runtime', 'client_form_control_runtime', array(
            'control'          => $this->formControlMetadata($element),
            'events'           => $this->eventMetadata($element),
            'required_scripts' => $this->requiredScriptsForElement($element),
        ));

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readableSelectBlockFromElement(DOMElement $select): ?array
    {
        $label = $this->readableFormControlLabel($select);
        $this->registerFormControlEcho($label);
        $optionBlocks = array();

        foreach ( $this->selectOptions($select) as $option ) {
            $optionLabel = trim((string) ($option['label'] ?? ''));
            if ( '' === $optionLabel ) {
                continue;
            }

            if ( true === ($option['selected'] ?? false) ) {
                $optionLabel .= ' (selected)';
            }

            $this->registerFormControlEcho($optionLabel);
            $optionBlocks[] = $this->createBlock('core/list-item', array( 'content' => $this->runtime->escapeHtml($optionLabel) ));
        }

        if ( array() === $optionBlocks ) {
            return null;
        }

        return $this->createBlock('core/group', $this->presentationAttributes($select), array(
            $this->createBlock('core/paragraph', array( 'content' => $this->runtime->escapeHtml($label) ), array(), $select),
            $this->createBlock('core/list', array(), $optionBlocks, $select),
        ), $select);
    }

    /**
     * @return array<string, string>
     */
    private function searchInputRuntimeAttributes(DOMElement $input): array
    {
        if ( ! $this->isRuntimeDomTarget($input) ) {
            return array();
        }

        return array_filter(array(
            'inputAnchor'    => $this->safeAnchor($this->attr($input, 'id')),
            'inputClassName' => $this->promotedClassName($this->attr($input, 'class')),
        ), static fn (string $value): bool => '' !== trim($value));
    }

    private function formRequiresRuntimePreservation(DOMElement $form): bool
    {
        return 0 < $form->getElementsByTagName('script')->length
            || array() !== $this->eventMetadata($form)
            || $this->formHasRuntimeSubmissionMetadata($form)
            || $this->formHasCommerceSubmissionSignal($form)
            || $this->formHasRuntimeDomTargets($form);
    }

    private function formHasRuntimeSubmissionMetadata(DOMElement $form): bool
    {
        $action = trim($this->attr($form, 'action'));
        if ( '' !== $action && '#' !== $action ) {
            return true;
        }

        if ( '' === $action && '' !== trim($this->attr($form, 'method')) ) {
            return true;
        }

        foreach ( array( 'enctype', 'target' ) as $attribute ) {
            if ( '' !== trim($this->attr($form, $attribute)) ) {
                return true;
            }
        }

        return false;
    }

    private function formHasCommerceSubmissionSignal(DOMElement $form): bool
    {
        foreach ( $this->formControlElements($form) as $control ) {
            if ( ! $this->isSubmitLikeControl($control) ) {
                continue;
            }

            $haystack = strtolower(implode(' ', array(
                $control->textContent ?? '',
                $this->attr($control, 'value'),
                $this->attr($control, 'class'),
                $this->attr($control, 'id'),
                $this->attr($control, 'name'),
                $this->attr($control, 'aria-label'),
                $this->attr($control, 'title'),
            )));

            if ( preg_match('/(?:^|[^a-z0-9])(?:add to cart|cart|checkout|payment|purchase|buy|order|register|registration|ticket)(?:[^a-z0-9]|$)/', $haystack) ) {
                return true;
            }
        }

        return false;
    }

    private function formHasRuntimeDomTargets(DOMElement $form): bool
    {
        if ( $this->isRuntimeDomTarget($form) || $this->hasRuntimeClassSignal($form) ) {
            return true;
        }

        foreach ( $this->formControlElements($form) as $control ) {
            if ( $this->isRuntimeDomTarget($control) || $this->hasRuntimeClassSignal($control) ) {
                return true;
            }
        }

        return false;
    }

    private function hasRuntimeClassSignal(DOMElement $element): bool
    {
        foreach ( preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array() as $class ) {
            if ( preg_match('/^js-[A-Za-z0-9_-]+$/', $class) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the shared html_form_fallback finding (issue #315) for an element that
     * behaves as a form. Both the real <form> path and the div-based pseudo-form
     * path emit through here so the downstream materializer receives an identical
     * shape (controls, form metadata, classification, bounded HTML) regardless of
     * whether the source markup used a <form> element.
     *
     * @param array<string, mixed>|null $readableFormBlock
     * @return array<string, mixed>
     */
    private function formFallbackFinding(DOMElement $element, ?array $readableFormBlock): array
    {
        $controls = $this->formControls($element);
        $boundedHtml = $this->boundedFallbackHtml($this->safeFallbackHtml($element));

        return FallbackDiagnostic::build(array(
            'type'            => 'html',
            'reason'          => 'form_requires_runtime',
            'diagnostic_code' => 'html_form_fallback',
            'message'         => 'Form intent and controls were extracted as provider-materializable metadata; the source form markup is preserved until a form provider materializes it.',
            'source_format'   => 'html',
            'tag'             => strtolower($element->tagName),
            'selector'        => $this->elementSelector($element),
            'attributes'      => $this->htmlAttributes($element),
            'form'            => $this->formMetadata($element),
            'success_panel'   => $this->formSuccessPanelMetadata($element),
            'context'         => $this->sourceContext($element),
            'classification'  => $this->fallbackEmitter->classifyFallbackSubtree($element),
            'events'          => $this->eventMetadata($element),
            'readable_blocks' => null !== $readableFormBlock ? array( $readableFormBlock ) : array(),
            'controls'        => $controls,
            'control_count'   => count($controls),
            'text_length'     => strlen(trim($element->textContent ?? '')),
            'child_count'     => $this->childElementCount($element),
            'html'            => $boundedHtml['html'],
            'html_bytes'      => $boundedHtml['bytes'],
            'html_truncated'  => $boundedHtml['truncated'],
        ), $this->fallbackProvenance);
    }

    /**
     * @return array<string, mixed>
     */
    private function formSuccessPanelMetadata(DOMElement $form): array
    {
        for ( $sibling = $form->nextSibling; $sibling instanceof DOMNode; $sibling = $sibling->nextSibling ) {
            if ( XML_TEXT_NODE === $sibling->nodeType && '' === trim($sibling->textContent ?? '') ) {
                continue;
            }

            if ( ! $sibling instanceof DOMElement ) {
                return array();
            }

            if ( ! $this->hasSuccessPanelSignal($sibling) ) {
                return array();
            }

            $boundedHtml = $this->boundedFallbackHtml($this->safeFallbackHtml($sibling));
            return array_filter(array(
                'selector'       => $this->elementSelector($sibling),
                'id'             => $this->attr($sibling, 'id'),
                'class'          => $this->attr($sibling, 'class'),
                'role'           => $this->attr($sibling, 'role'),
                'aria_live'      => $this->attr($sibling, 'aria-live'),
                'text'           => $this->normalizedSuccessPanelText($sibling),
                'html'           => $boundedHtml['html'],
                'html_bytes'     => $boundedHtml['bytes'],
                'html_truncated' => $boundedHtml['truncated'],
            ), static fn (mixed $value): bool => is_bool($value) || is_int($value) || '' !== trim((string) $value));
        }

        return array();
    }

    private function normalizedSuccessPanelText(DOMElement $element): string
    {
        $html = preg_replace('/<\/?[a-z][a-z0-9]*\b[^>]*>/i', ' ', $this->innerHtml($element)) ?? $element->textContent ?? '';
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    private function hasSuccessPanelSignal(DOMElement $element): bool
    {
        $role = strtolower($this->attr($element, 'role'));
        if ( in_array($role, array( 'status', 'alert' ), true) ) {
            return true;
        }

        $tokens = strtolower(trim($this->attr($element, 'id') . ' ' . $this->attr($element, 'class') . ' ' . $this->attr($element, 'aria-live')));
        return (bool) preg_match('/(?:^|[^a-z0-9])(?:success|sent|submitted|thank|thanks|confirmation|confirmed)(?:[^a-z0-9]|$)/', $tokens);
    }

    /**
     * Whether a non-<form> container behaves as a form: it is the tightest
     * container that pairs at least one data-entry control with a submit-like
     * control, and no real <form> owns the subtree.
     *
     * Structural only — the signal is "data-entry control + submit-like control in
     * one bounded container", never a fixture id/class/name. Conservative: a lone
     * search box or a stray input with no submit control never qualifies, and a
     * subtree owned by a real <form> (as ancestor or descendant) is left to the
     * <form> path so the finding is emitted exactly once.
     */
    private function isDivBasedPseudoForm(DOMElement $element): bool
    {
        if ( 'form' === strtolower($element->tagName) ) {
            return false;
        }

        // A real <form> ancestor or descendant owns the controls; let the <form>
        // path emit the finding so it is never double-counted.
        if ( $this->hasFormAncestor($element) ) {
            return false;
        }
        if ( 0 < $element->getElementsByTagName('form')->length ) {
            return false;
        }

        if ( ! $this->containerPairsDataEntryWithSubmit($element) ) {
            return false;
        }

        // Bound the container to the tightest one: if a descendant container also
        // pairs the controls, defer to it so a wrapper does not swallow a nested
        // pseudo-form (and sibling pseudo-forms each emit their own finding).
        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement
                && ! $this->isFormControlElement($descendant)
                && $this->containerPairsDataEntryWithSubmit($descendant) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a container holds at least one data-entry control AND at least one
     * submit-like control. Reuses the issue #315 control-detection helpers
     * (formControlElements / isDataEntryControl) so detection stays in one place.
     */
    private function containerPairsDataEntryWithSubmit(DOMElement $element): bool
    {
        $hasDataEntry = false;
        $hasSubmit = false;

        foreach ( $this->formControlElements($element) as $control ) {
            if ( $this->isPseudoFormDataEntryControl($control) ) {
                $hasDataEntry = true;
            } elseif ( $this->isSubmitLikeControl($control) ) {
                $hasSubmit = true;
            }

            if ( $hasDataEntry && $hasSubmit ) {
                return true;
            }
        }

        return false;
    }

    /**
     * A data-entry control that anchors a pseudo-form. Reuses #315's
     * isDataEntryControl and additionally excludes search inputs, which already
     * have dedicated standalone-search handling and should not be promoted into a
     * form fallback.
     */
    private function isPseudoFormDataEntryControl(DOMElement $control): bool
    {
        return $this->isDataEntryControl($control) && 'search' !== $this->formControlType($control);
    }

    /**
     * Whether a control submits a form: an explicit submit/image control, or a
     * button/input whose text/value/type/class/id/name/aria carries submit,
     * subscribe, sign-up, or send semantics. A plain <button> defaults to type
     * "submit" and qualifies directly; a type="reset" control never does.
     */
    private function isSubmitLikeControl(DOMElement $control): bool
    {
        $tagName = strtolower($control->tagName);
        if ( 'button' !== $tagName && 'input' !== $tagName ) {
            return false;
        }

        $type = $this->formControlType($control);
        if ( in_array($type, array( 'submit', 'image' ), true) ) {
            return true;
        }
        if ( 'reset' === $type ) {
            return false;
        }

        // Only generic clickable controls (button-typed) fall through to the
        // semantic check; data-entry input types are never submit controls.
        if ( 'input' === $tagName && 'button' !== $type ) {
            return false;
        }

        return $this->hasSubmitSemantics($control);
    }

    /**
     * Whether a control's text/attributes carry submit-like intent. Structural
     * vocabulary only — no fixture-specific identifiers.
     */
    private function hasSubmitSemantics(DOMElement $control): bool
    {
        $haystack = strtolower(implode(' ', array(
            $control->textContent ?? '',
            $this->attr($control, 'value'),
            $this->attr($control, 'class'),
            $this->attr($control, 'id'),
            $this->attr($control, 'name'),
            $this->attr($control, 'aria-label'),
        )));

        foreach ( array( 'submit', 'subscribe', 'sign up', 'sign-up', 'signup', 'send' ) as $needle ) {
            if ( str_contains($haystack, $needle) ) {
                return true;
            }
        }

        return false;
    }

    private function hasFormAncestor(DOMElement $element): bool
    {
        for ( $parent = $element->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
            if ( 'form' === strtolower($parent->tagName) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a form collects user input through at least one data-entry control.
     *
     * A <form> that gathers data (text/email/select/textarea and similar) needs a
     * real form runtime to submit, validate, and notify — even when it declares no
     * action/method/script/event handler (common in static exports and design
     * mockups where submission is wired downstream). Such a form must be preserved
     * as a runtime island carrying its control structure rather than flattened to
     * readable prose, so a consumer can materialize it into a working form. Keying
     * off the control structure keeps this generic: no provider, plugin, or site
     * knowledge leaks into the transformer.
     */
    private function formHasDataEntryControls(DOMElement $form): bool
    {
        foreach ( $this->formControlElements($form) as $control ) {
            if ( $this->isDataEntryControl($control) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a control collects user input (as opposed to a submit/reset/button,
     * hidden state, file upload, or image button).
     *
     * The excluded set mirrors the controls a form provider cannot map to a data
     * field, so a form whose only controls are non-data-entry stays a readable
     * fallback instead of becoming an empty preserved island.
     */
    private function isDataEntryControl(DOMElement $control): bool
    {
        return FormControlClassifier::isDataEntryControl($control);
    }

    private function isReadableFormControl(DOMElement $control): bool
    {
        $tagName = strtolower($control->tagName);
        if ( in_array($tagName, array( 'select', 'textarea' ), true) ) {
            return true;
        }

        return 'button' === $tagName || ( 'input' === $tagName && in_array($this->formControlType($control), array( 'checkbox', 'email', 'number', 'radio', 'range', 'search', 'submit', 'tel', 'text', 'url' ), true) );
    }

    private function readableFormControlText(DOMElement $control): string
    {
        $label = $this->readableFormControlLabel($control);

        $type = $this->formControlType($control);
        if ( '' === $label ) {
            $label = 'select' === $type ? 'Select option' : ucfirst($type);
        }

        $details = array();
        if ( 'select' === strtolower($control->tagName) ) {
            $options = array();
            $selected = array();
            foreach ( $this->selectOptions($control) as $option ) {
                $optionLabel = (string) ($option['label'] ?? '');
                if ( '' === $optionLabel ) {
                    continue;
                }
                $options[] = $optionLabel;
                if ( true === ($option['selected'] ?? false) ) {
                    $selected[] = $optionLabel;
                }
            }
            if ( array() !== $options ) {
                $details[] = implode(', ', $options);
            }
            if ( array() !== $selected ) {
                $details[] = 'selected: ' . implode(', ', $selected);
            }
        } elseif ( 'range' === $type ) {
            $value = trim($this->attr($control, 'value'));
            if ( '' !== $value ) {
                $details[] = $value;
            }

            $bounds = array();
            foreach ( array( 'min', 'max', 'step' ) as $attribute ) {
                $value = trim($this->attr($control, $attribute));
                if ( '' !== $value ) {
                    $bounds[] = $attribute . ' ' . $value;
                }
            }
            if ( array() !== $bounds ) {
                $details[] = implode(', ', $bounds);
            }
        } else {
            foreach ( array( 'value', 'placeholder' ) as $attribute ) {
                $value = trim($this->attr($control, $attribute));
                if ( '' !== $value ) {
                    $details[] = $value;
                    break;
                }
            }
        }

        $text = $label;
        if ( array() !== $details ) {
            $text .= ': ' . implode(' (', $details) . ( count($details) > 1 ? ')' : '' );
        }
        if ( $control->hasAttribute('required') ) {
            $text .= ' (required)';
        }

        $this->registerFormControlEcho($text);

        return $this->runtime->escapeHtml($text);
    }

    /**
     * Record text the transformer synthesizes from a form control (label plus
     * value/placeholder/options/required state) so the content round-trip
     * reporter does not flag it as invented copy — it is intentionally absent
     * from the source's visible content. Harmless if a recorded string never
     * reaches the output: the reporter only ever uses it to suppress an exact
     * match.
     */
    private function registerFormControlEcho(string $text): void
    {
        $text = trim($text);
        if ( '' !== $text ) {
            $this->formControlEchoTexts[] = $text;
        }
    }

    private function readableFormControlLabel(DOMElement $control): string
    {
        $label = $this->formControlLabel($control);
        if ( '' === $label ) {
            $label = $this->attr($control, 'aria-label');
        }
        if ( '' === $label ) {
            $label = $this->attr($control, 'placeholder');
        }
        if ( '' === $label ) {
            $label = $this->attr($control, 'name');
        }

        $type = $this->formControlType($control);
        if ( '' === $label ) {
            return 'select' === $type ? 'Select option' : ucfirst($type);
        }

        return $label;
    }

    private function readableSubmitText(DOMElement $control): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $control->textContent ?? '') ?? '');
        if ( '' !== $text ) {
            return $text;
        }

        $value = trim($this->attr($control, 'value'));
        return '' !== $value ? $value : 'Submit';
    }

    /**
     * @return array<int, DOMElement>
     */
    private function formControlElements(DOMElement $form): array
    {
        $controls = array();
        foreach ( $form->getElementsByTagName('*') as $control ) {
            if ( $control instanceof DOMElement && $this->isFormControlElement($control) ) {
                $controls[] = $control;
            }
        }

        return $controls;
    }

    private function hasSearchFormSignal(DOMElement $form, DOMElement $input): bool
    {
        if ( 'search' === $this->formControlType($input) || 'search' === strtolower(trim($this->attr($form, 'role'))) ) {
            return true;
        }

        $queryName = strtolower(trim($this->attr($input, 'name')));
        if ( in_array($queryName, array( 's', 'q', 'query', 'search' ), true) ) {
            return true;
        }

        $haystack = strtolower(implode(' ', array(
            $this->attr($form, 'action'),
            $this->attr($form, 'aria-label'),
            $this->attr($form, 'id'),
            $this->attr($form, 'class'),
        )));

        return str_contains($haystack, 'search');
    }

    private function hasStandaloneSearchSignal(DOMElement $element, DOMElement $input): bool
    {
        if ( 'search' === $this->formControlType($input) || 'search' === strtolower(trim($this->attr($element, 'role'))) ) {
            return true;
        }

        $haystack = strtolower(implode(' ', array(
            $this->attr($element, 'aria-label'),
            $this->attr($element, 'id'),
            $this->attr($element, 'class'),
            $this->attr($input, 'aria-label'),
            $this->attr($input, 'id'),
            $this->attr($input, 'class'),
            $this->attr($input, 'name'),
            $this->attr($input, 'placeholder'),
        )));

        return str_contains($haystack, 'search');
    }

    private function submitButtonText(DOMElement $control): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $control->textContent ?? '') ?? '');
        if ( '' !== $text ) {
            return $text;
        }

        $value = trim($this->attr($control, 'value'));
        return '' !== $value ? $value : 'Search';
    }

    /**
     * @return array<string, mixed>
     */
    private function formControlMetadata(DOMElement $control): array
    {
        if ( ! $this->isFormControlElement($control) ) {
            return array();
        }

        $tagName = strtolower($control->tagName);
        $type = $this->formControlType($control);
        $metadata = array_filter(array(
            'tag'         => $tagName,
            'selector'    => $this->elementSelector($control),
            'id'          => $this->attr($control, 'id'),
            'name'        => $this->attr($control, 'name'),
            'type'        => $type,
            'label'       => $this->formControlLabel($control),
            'placeholder' => $this->attr($control, 'placeholder'),
            'autocomplete' => $this->attr($control, 'autocomplete'),
            'pattern'     => $this->attr($control, 'pattern'),
            'min'         => $this->attr($control, 'min'),
            'max'         => $this->attr($control, 'max'),
            'step'        => $this->attr($control, 'step'),
            'maxlength'   => $this->attr($control, 'maxlength'),
            'rows'        => $this->attr($control, 'rows'),
        ), static fn (string $value): bool => '' !== $value);

        if ( in_array($type, array( 'button', 'reset', 'submit' ), true) ) {
            $text = $this->formButtonText($control);
            if ( '' !== $text ) {
                $metadata['text'] = $text;
            }
        }

        if ( $control->hasAttribute('required') ) {
            $metadata['required'] = true;
        }
        if ( $control->hasAttribute('disabled') ) {
            $metadata['disabled'] = true;
        }
        if ( $control->hasAttribute('readonly') ) {
            $metadata['readonly'] = true;
        }
        if ( $control->hasAttribute('checked') ) {
            $metadata['checked'] = true;
        }
        if ( $control->hasAttribute('multiple') ) {
            $metadata['multiple'] = true;
        }

        $value = $this->attr($control, 'value');
        if ( '' !== $value && 'select' !== $tagName ) {
            $metadata['value'] = $value;
        }

        if ( 'select' === $tagName ) {
            $options = $this->selectOptions($control);
            if ( array() !== $options ) {
                $metadata['options'] = $options;
            }
        }

        return $metadata;
    }

    private function isFormControlElement(DOMElement $element): bool
    {
        return FormControlClassifier::isControlElement($element);
    }

    private function formControlType(DOMElement $control): string
    {
        return FormControlClassifier::controlType($control);
    }

    private function formControlLabel(DOMElement $control): string
    {
        $ariaLabel = trim($this->attr($control, 'aria-label'));
        if ( '' !== $ariaLabel ) {
            return $ariaLabel;
        }

        $id = $this->attr($control, 'id');
        if ( '' !== $id && $control->ownerDocument instanceof DOMDocument ) {
            foreach ( $control->ownerDocument->getElementsByTagName('label') as $label ) {
                if ( $label instanceof DOMElement && $id === $this->attr($label, 'for') ) {
                    return $this->normalizedControlLabelText($label);
                }
            }
        }

        for ( $parent = $control->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
            if ( 'label' === strtolower($parent->tagName) ) {
                return $this->normalizedControlLabelText($parent);
            }
        }

        return '';
    }

    private function normalizedControlLabelText(DOMElement $label): string
    {
        return trim(preg_replace('/\s+/', ' ', $this->labelTextWithoutControls($label)) ?? '');
    }

    private function labelTextWithoutControls(DOMNode $node): string
    {
        if ( XML_TEXT_NODE === $node->nodeType ) {
            return $node->textContent ?? '';
        }

        if ( $node instanceof DOMElement && 'true' === strtolower($this->attr($node, 'aria-hidden')) ) {
            return '';
        }

        if ( $node instanceof DOMElement && $this->isFormControlElement($node) ) {
            return '';
        }

        $text = '';
        foreach ( $node->childNodes as $child ) {
            $text .= $this->labelTextWithoutControls($child);
        }

        return $text;
    }

    private function formButtonText(DOMElement $control): string
    {
        foreach ( array( 'aria-label', 'title' ) as $attribute ) {
            $label = trim($this->attr($control, $attribute));
            if ( '' !== $label ) {
                return $label;
            }
        }

        $text = trim(preg_replace('/\s+/', ' ', $control->textContent ?? '') ?? '');
        if ( '' !== $text ) {
            return $text;
        }

        return trim($this->attr($control, 'value'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function selectOptions(DOMElement $select): array
    {
        $options = array();
        foreach ( $select->getElementsByTagName('option') as $option ) {
            if ( ! $option instanceof DOMElement ) {
                continue;
            }

            $value = $this->attr($option, 'value');
            $optionMetadata = array(
                'label' => trim(preg_replace('/\s+/', ' ', $option->textContent ?? '') ?? ''),
                'value' => '' !== $value ? $value : trim($option->textContent ?? ''),
            );
            if ( $option->hasAttribute('selected') ) {
                $optionMetadata['selected'] = true;
            }
            if ( $option->hasAttribute('disabled') ) {
                $optionMetadata['disabled'] = true;
            }
            if ( '' === trim($this->attr($option, 'value')) && ( $option->hasAttribute('disabled') || $option->hasAttribute('selected') ) ) {
                $optionMetadata['placeholder'] = true;
            }

            $options[] = $optionMetadata;
        }

        return $options;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function backgroundImageBlockFromElement(DOMElement $element): ?array
    {
        $url = $this->backgroundImageExtractor->urlFromStyle($this->mergedPresentationStyle($element));
        if ( '' === $url ) {
            return null;
        }

        return $this->createBlock('core/image', array_filter(array(
            'url'       => $this->resolvedAssetImageUrl($url),
            'alt'       => $this->backgroundImageExtractor->altFromAttributes($this->htmlAttributes($element)),
            'className' => 'blocks-engine-background-image',
        ), static fn (string $value): bool => '' !== $value), array(), $element);
    }

    private function hasDirectMediaChild(DOMElement $element): bool
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && in_array(strtolower($child->tagName), array( 'img', 'picture', 'svg', 'video', 'audio' ), true) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function namePriceRowBlockFromElement(DOMElement $element, array &$fallbacks): ?array
    {
        $children = $this->namePriceChildren($element);
        if ( null === $children ) {
            return null;
        }

        $rowFallbacks = array();
        $columns = array();
        foreach ( $children as $child ) {
            $converted = array_filter(array( $this->convertElement($child, $rowFallbacks, true) ));
            if ( array() === $converted ) {
                return null;
            }

            $columns[] = $this->createBlock('core/column', $this->presentationAttributes($child), $converted, $child);
        }
        array_push($fallbacks, ...$rowFallbacks);

        return $this->createBlock('core/columns', $this->presentationAttributes($element), $columns, $element);
    }

    /**
     * @return array<int, DOMElement>|null
     */
    private function namePriceChildren(DOMElement $element): ?array
    {
        if ( ! in_array(strtolower($element->tagName), array( 'div', 'header', 'section' ), true) ) {
            return null;
        }

        $children = array();
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }
            if ( ! $child instanceof DOMElement ) {
                return null;
            }
            if ( ! $this->isInlineCommerceRowChild($child) ) {
                return null;
            }
            $children[] = $child;
        }

        if ( 2 !== count($children) ) {
            return null;
        }

        $first = $children[0];
        $second = $children[1];
        $firstIsPrice = $this->isPriceElement($first);
        $secondIsPrice = $this->isPriceElement($second);
        if ( $firstIsPrice !== $secondIsPrice ) {
            $other = $firstIsPrice ? $second : $first;
            if ( $this->isNameElement($other) || $this->hasCommerceToken($element, array( 'menu', 'product', 'pricing', 'price', 'plan', 'tier', 'dish', 'item', 'row' )) ) {
                return $children;
            }
        }

        if ( $this->looksLikeHoursRow($element, $first, $second) ) {
            return $children;
        }

        if ( $this->looksLikeLabelValueRow($element, $first, $second) ) {
            return $children;
        }

        return null;
    }

    private function isInlineCommerceRowChild(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( in_array($tagName, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'a', 'span', 'strong', 'em', 'small', 'time' ), true) ) {
            return ! $this->hasBlockContentChildren($element);
        }

        return false;
    }

    private function isPriceElement(DOMElement $element): bool
    {
        return $this->hasCommerceToken($element, array( 'price', 'amount', 'cost' )) || $this->looksLikePriceText($element->textContent ?? '');
    }

    private function isNameElement(DOMElement $element): bool
    {
        return $this->hasCommerceToken($element, array( 'name', 'title', 'product', 'dish', 'item', 'plan', 'tier' )) || preg_match('/^h[1-6]$/', strtolower($element->tagName));
    }

    private function looksLikeHoursRow(DOMElement $row, DOMElement $first, DOMElement $second): bool
    {
        if ( ! $this->hasCommerceToken($row, array( 'hours', 'hour', 'schedule', 'time', 'row' )) ) {
            return false;
        }

        return ( $this->isDayElement($first) && $this->isTimeValueElement($second) )
            || ( $this->isDayElement($second) && $this->isTimeValueElement($first) );
    }

    private function looksLikeLabelValueRow(DOMElement $row, DOMElement $first, DOMElement $second): bool
    {
        if ( ! $this->hasCommerceToken($row, array( 'row', 'item', 'pair', 'line', 'entry', 'schedule', 'session', 'meta', 'detail' )) ) {
            return false;
        }

        $firstIsLabel = $this->isLabelValueLabelElement($first);
        $secondIsLabel = $this->isLabelValueLabelElement($second);
        $firstIsValue = $this->isLabelValueValueElement($first);
        $secondIsValue = $this->isLabelValueValueElement($second);

        return ( $firstIsLabel && $secondIsValue ) || ( $secondIsLabel && $firstIsValue );
    }

    private function isLabelValueLabelElement(DOMElement $element): bool
    {
        return $this->hasCommerceToken($element, array( 'label', 'term', 'key', 'day', 'date', 'time', 'hour', 'hours', 'duration' ))
            || 'time' === strtolower($element->tagName)
            || $this->looksLikeDateOrTimeText($element->textContent ?? '');
    }

    private function isLabelValueValueElement(DOMElement $element): bool
    {
        return $this->hasCommerceToken($element, array( 'value', 'detail', 'title', 'name', 'content', 'description', 'desc', 'meta', 'session', 'event', 'location', 'venue' ))
            || preg_match('/^h[1-6]$/', strtolower($element->tagName));
    }

    private function looksLikeDateOrTimeText(string $text): bool
    {
        return (bool) preg_match('/\b(?:\d{1,2}(?::\d{2})?\s*(?:am|pm)?|\d{1,2}\s*(?:min|mins|minutes|hr|hrs|hours)|mon(?:day)?|tue(?:sday)?|wed(?:nesday)?|thu(?:rsday)?|fri(?:day)?|sat(?:urday)?|sun(?:day)?|day\s+\d+)\b/i', trim($text));
    }

    private function isDayElement(DOMElement $element): bool
    {
        return $this->hasCommerceToken($element, array( 'day', 'date', 'label' )) || (bool) preg_match('/\b(?:mon(?:day)?|tue(?:sday)?|wed(?:nesday)?|thu(?:rsday)?|fri(?:day)?|sat(?:urday)?|sun(?:day)?|weekdays?|weekends?)\b/i', $element->textContent ?? '');
    }

    private function isTimeValueElement(DOMElement $element): bool
    {
        return $this->hasCommerceToken($element, array( 'time', 'hours', 'value', 'closed' )) || (bool) preg_match('/\b(?:closed|open|\d{1,2}(?::\d{2})?\s*(?:am|pm)?\s*(?:[\x{2013}\x{2014}-]|to)\s*\d{1,2}(?::\d{2})?\s*(?:am|pm)?)\b/iu', $element->textContent ?? '');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function navigationSectionBlockFromElement(DOMElement $element): ?array
    {
        $heading = null;
        $anchors = array();
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }

            if ( $child instanceof DOMElement && $this->isNavigationSectionHeading($child) ) {
                if ( $heading instanceof DOMElement ) {
                    return null;
                }
                $heading = $child;
                continue;
            }

            if ( $child instanceof DOMElement && 'a' === strtolower($child->tagName) && '' !== trim($child->textContent ?? '') ) {
                $anchors[] = $child;
                continue;
            }

            return null;
        }

        if ( ! $heading instanceof DOMElement || array() === $anchors ) {
            return null;
        }

        if ( ! $this->hasNavigationContainerSignal($element) && ! $this->hasSoftNavigationSectionHeadingSignal($heading) ) {
            return null;
        }

        $sectionFallbacks = array();
        $blocks = array( $this->convertElement($heading, $sectionFallbacks, true) );
        $links = array();
        foreach ( $anchors as $anchor ) {
            $links[] = $this->createBlock('core/navigation-link', array_filter(array(
                'label' => $this->innerHtml($anchor),
                'url'   => $this->safeNavigationUrl($this->attr($anchor, 'href')),
                'kind'  => 'custom',
            ), static fn ($value): bool => '' !== $value), array(), $anchor);
        }
        // Declare responsive-overlay intent explicitly (see NavigationPattern):
        // `overlayMenu` => `mobile` matches the core default so WP renders the
        // responsive overlay and enqueues the navigation view module instead of
        // depending on the render-time default being applied.
        $blocks[] = $this->createBlock('core/navigation', array( 'overlayMenu' => 'mobile' ), $links, $element);

        return $this->createBlock('core/group', $this->presentationAttributes($element), array_values(array_filter($blocks)), $element);
    }

    private function isNavigationSectionHeading(DOMElement $element): bool
    {
        if ( preg_match('/^h[1-6]$/i', $element->tagName) ) {
            return true;
        }

        if ( ! in_array(strtolower($element->tagName), array( 'div', 'p', 'span' ), true) || '' === trim($element->textContent ?? '') ) {
            return false;
        }

        $name = strtolower(trim($this->attr($element, 'class') . ' ' . $this->attr($element, 'id') . ' ' . $this->attr($element, 'role') . ' ' . $this->attr($element, 'aria-label')));
        return (bool) preg_match('/(?:^|[\s_-])(?:heading|label|title)(?:$|[\s_-])/', $name);
    }

    private function hasSoftNavigationSectionHeadingSignal(DOMElement $element): bool
    {
        return ! preg_match('/^h[1-6]$/i', $element->tagName) && $this->isNavigationSectionHeading($element);
    }

    private function hasNavigationContainerSignal(DOMElement $element): bool
    {
        if ( 'navigation' === strtolower($this->attr($element, 'role')) ) {
            return true;
        }

        $name = strtolower(trim($this->attr($element, 'class') . ' ' . $this->attr($element, 'id')));
        return (bool) preg_match('/(?:^|[\s_-])(?:nav|navbar|navigation|menu|links)(?:$|[\s_-])/', $name);
    }

    private function hasDirectChildElement(DOMElement $element, string $tagName): bool
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && $tagName === strtolower($child->tagName) ) {
                return true;
            }
        }

        return false;
    }

    private function convertMediaElement(DOMElement $element): ?array
    {
        $tagName = strtolower($element->tagName);
        $src = $this->safeMediaUrl($this->attr($element, 'src'));
        if ( '' === $src ) {
            $source = $this->firstChildElement($element, 'source');
            $src = $source instanceof DOMElement ? $this->safeMediaUrl($this->attr($source, 'src')) : '';
        }
        if ( '' === $src ) {
            return null;
        }

        $attrs = array_filter(array_merge($this->presentationAttributes($element), array(
            'src'      => $src,
            'poster'   => 'video' === $tagName ? $this->attr($element, 'poster') : '',
            'preload'  => $this->attr($element, 'preload'),
            'width'    => $this->attr($element, 'width'),
            'height'   => $this->attr($element, 'height'),
            'controls' => $element->hasAttribute('controls'),
        )), static fn (mixed $value): bool => is_bool($value) ? $value : '' !== $value);

        return $this->createBlock('core/' . $tagName, $attrs, array(), $element);
    }

    private function safeMediaUrl(string $url): string
    {
        $url = trim($url);
        if ( '' === $url || preg_match('/[\x00-\x1f\x7f]|javascript\s*:/i', $url) ) {
            return '';
        }

        return $url;
    }

    private function fileBlockFromAnchor(DOMElement $anchor): ?array
    {
        $href = $this->safeFileUrl($this->attr($anchor, 'href'));
        if ( '' === $href ) {
            return null;
        }

        $attrs = array_filter(array_merge($this->presentationAttributes($anchor), array(
            'href'               => $href,
            'url'                => $href,
            'text'               => $this->innerHtml($anchor),
            'showDownloadButton' => $anchor->hasAttribute('download'),
        )), static fn (mixed $value): bool => is_bool($value) ? $value : '' !== $value);

        return $this->createBlock('core/file', $attrs, array(), $anchor);
    }

    private function safeFileUrl(string $url): string
    {
        $url = trim($url);
        if ( '' === $url || preg_match('/[\x00-\x1f\x7f]|javascript\s*:/i', $url) ) {
            return '';
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($extension, array( 'doc', 'docx', 'odp', 'ods', 'odt', 'pdf', 'ppt', 'pptx', 'rtf', 'txt', 'xls', 'xlsx', 'zip' ), true) ? $url : '';
    }

    private function convertPictureElement(DOMElement $picture, ?DOMElement $figure = null, ?DOMElement $link = null): ?array
    {
        $image = $this->firstChildElement($picture, 'img');
        if ( ! $image instanceof DOMElement ) {
            return null;
        }

        return $this->convertImageElement($image, $figure ?? $picture, $picture, $link);
    }

    private function imageBlockFromAnchor(DOMElement $anchor): ?array
    {
        $href = $this->safeLinkUrl($this->attr($anchor, 'href'));
        if ( '' === $href || ! $this->isImageOnlyAnchor($anchor) ) {
            return null;
        }

        $picture = $this->firstChildElement($anchor, 'picture');
        if ( $picture instanceof DOMElement ) {
            $image = $this->firstChildElement($picture, 'img');
            return $image instanceof DOMElement ? $this->convertImageElement($image, null, $picture, $anchor) : null;
        }

        $image = $this->firstChildElement($anchor, 'img');
        return $image instanceof DOMElement ? $this->convertImageElement($image, null, null, $anchor) : null;
    }

    private function isImageOnlyAnchor(DOMElement $anchor): bool
    {
        $imageChildren = 0;
        foreach ( $anchor->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                if ( ! in_array(strtolower($child->tagName), array( 'img', 'picture' ), true) ) {
                    return false;
                }
                ++$imageChildren;
                continue;
            }

            if ( '' !== trim($child->textContent ?? '') ) {
                return false;
            }
        }

        return 1 === $imageChildren;
    }

    private function convertImageElement(DOMElement $image, ?DOMElement $figure = null, ?DOMElement $picture = null, ?DOMElement $link = null): ?array
    {
        $originalUrl = $this->safeImageUrl($this->attr($image, 'src'));
        $url = $this->resolvedAssetImageUrl($originalUrl);
        if ( '' === $url ) {
            return null;
        }

        $attrs = $this->imagePresentationAttributes($image, $figure);
        if ( null !== $picture && ! $figure instanceof DOMElement ) {
            $attrs = array_merge($this->presentationAttributes($picture), $attrs);
        }
        $width = $this->attr($image, 'width');
        $height = $this->attr($image, 'height');
        $sourceAttrs = $picture instanceof DOMElement ? $this->pictureSourceAttributes($picture) : array();
        if ( '' !== $width || '' !== $height ) {
            $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), 'is-resized');
        }

        $attrs = array_filter(array_merge($attrs, array(
            'url'    => $url,
            'alt'    => $this->attr($image, 'alt'),
            'title'  => $this->attr($image, 'title'),
            'srcset' => $this->resolvedAssetImageSrcset('' !== $this->attr($image, 'srcset') ? $this->attr($image, 'srcset') : (string) ($sourceAttrs['srcset'] ?? '')),
            'sizes'  => '' !== $this->attr($image, 'sizes') ? $this->attr($image, 'sizes') : (string) ($sourceAttrs['sizes'] ?? ''),
            'width'  => $width,
            'height' => $height,
        )), static fn ($value): bool => '' !== $value);

        $attrs = array_filter(array_merge($attrs, $this->imageIdentityAttributes($image, $figure)), static fn ($value): bool => '' !== $value);
        $attrs = array_filter(array_merge($attrs, $this->assetMetadataImageAttributes($originalUrl)), static fn ($value): bool => '' !== $value);

        if ( $figure instanceof DOMElement ) {
            $caption = $this->firstChildElement($figure, 'figcaption');
            if ( $caption instanceof DOMElement ) {
                $attrs['caption'] = $this->innerHtml($caption);
            }
        }

        if ( $link instanceof DOMElement ) {
            $attrs = array_filter(array_merge($attrs, $this->imageLinkAttributes($link)), static fn ($value): bool => '' !== $value);
        }

        return $this->createBlock('core/image', $attrs, array(), $figure ?? $image);
    }

    /**
     * @return array<string, string>
     */
    private function imageLinkAttributes(DOMElement $link): array
    {
        $attrs = array(
            'href'            => $this->safeLinkUrl($this->attr($link, 'href')),
            'linkDestination' => 'custom',
            'linkAnchor'      => $this->safeAnchor($this->attr($link, 'id')),
            'linkTarget'      => $this->attr($link, 'target'),
            'rel'             => $this->attr($link, 'rel'),
            'linkClass'       => $this->attr($link, 'class'),
            'linkAriaLabel'   => $this->attr($link, 'aria-label'),
            'linkAriaHidden'  => $this->attr($link, 'aria-hidden'),
            'linkTabIndex'    => $this->attr($link, 'tabindex'),
        );

        return array_filter($attrs, static fn (string $value): bool => '' !== trim($value));
    }

    private function safeLinkUrl(string $url): string
    {
        $url = trim($url);
        if ( '' === $url || preg_match('/[\x00-\x1f\x7f]|javascript\s*:/i', $url) ) {
            return '';
        }

        return $url;
    }

    /**
     * @return array<string, string>
     */
    private function cardLinkAttributes(DOMElement $anchor): array
    {
        $href = $this->safeLinkUrl($this->attr($anchor, 'href'));
        if ( '' === $href ) {
            return array();
        }

        return array_filter(array(
            'href'      => $href,
            'target'    => $this->attr($anchor, 'target'),
            'rel'       => $this->attr($anchor, 'rel'),
            'ariaLabel' => $this->attr($anchor, 'aria-label'),
        ), static fn (string $value): bool => '' !== trim($value));
    }

    /**
     * Record a content-wrapping anchor whose link could not be preserved on any
     * native link-bearing inner block, because the resulting core/group exposes
     * no native link attribute of its own (#260). The link details (selector +
     * href) are captured for diagnostics so the navigation loss is detectable
     * and a downstream repair loop can act on it, rather than the link being
     * silently dropped.
     */
    private function recordDroppedLinkWrapper(DOMElement $anchor): void
    {
        $link = $this->cardLinkAttributes($anchor);
        if ( array() === $link ) {
            return;
        }

        $this->droppedLinkWrapperFindings[] = array_merge(
            array(
                'kind'     => 'source link wrapper dropped / content no longer navigable',
                'tag'      => strtolower($anchor->tagName),
                'selector' => $this->elementSelector($anchor),
            ),
            $link
        );
    }

    /**
     * Convert a whole-element link wrapper (an `<a href>` wrapping block-level
     * content) into a core/group whose layout/className is preserved while the
     * anchor's href is propagated onto native link-bearing inner blocks, so the
     * card content stays navigable instead of carrying a dead href on the group
     * (#260).
     *
     * Mapping is chosen deterministically from the wrapped content:
     *  - Button-like anchors (carrying a button signal) are routed to
     *    core/button/core/buttons upstream of this method, so they never arrive
     *    here.
     *  - Otherwise (card/tile with heading, image, text) the link is propagated
     *    onto core/image (native link attributes) and core/heading /
     *    core/paragraph / core/list-item (inline `<a>` around the text content),
     *    recursing through layout containers (group/columns/column/…). The
     *    container never carries a bogus href.
     *
     * When the link cannot be preserved on any inner block (e.g. the card holds
     * only non-link-bearing content), a structured finding is emitted instead.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function convertLinkWrapperGroup(DOMElement $anchor, array &$fallbacks): ?array
    {
        $children = $this->convertChildren($anchor, $fallbacks, true);
        if ( array() === $children ) {
            return null;
        }

        $linkAttrs = $this->linkPropagationAttributes($anchor);
        if ( array() !== $linkAttrs && ! $this->propagateLinkWrapper($children, $linkAttrs) ) {
            $this->recordDroppedLinkWrapper($anchor);
        }

        return $this->createBlock('core/group', $this->presentationAttributes($anchor), $children, $anchor);
    }

    /**
     * The subset of a card-link wrapper's attributes used to propagate the link
     * onto inner blocks: href (sanitized), target, and rel. The wrapper's own
     * class/aria-label stay on the container group, not on each inner block.
     *
     * @return array<string, string>
     */
    private function linkPropagationAttributes(DOMElement $anchor): array
    {
        $href = $this->safeLinkUrl($this->attr($anchor, 'href'));
        if ( '' === $href ) {
            return array();
        }

        $declarations = $this->presentationDeclarations($anchor);
        $textDecoration = strtolower(trim((string) ($declarations['text-decoration'] ?? '')));

        return array_filter(array(
            'href'           => $href,
            'target'         => $this->attr($anchor, 'target'),
            'rel'            => $this->attr($anchor, 'rel'),
            'textDecoration' => 'none' === $textDecoration ? 'none' : '',
        ), static fn (string $value): bool => '' !== trim($value));
    }

    /**
     * Walk the converted inner blocks of a link wrapper and propagate the
     * anchor's link onto every native link-bearing descendant so the content
     * remains navigable. core/image receives native link attributes;
     * {@see self::LINK_BEARING_TEXT_BLOCKS} get an inline `<a>` around their text
     * content. Layout containers (group/columns/column/…) are recursed into so a
     * card whose heading/image/text lives behind wrapper `<div>`s is still
     * covered. Blocks that manage their own link
     * ({@see self::LINK_SELF_MANAGING_BLOCKS}) are skipped.
     *
     * Returns true when the link was carried onto at least one inner block.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @param array<string, string> $linkAttrs
     */
    private function propagateLinkWrapper(array &$blocks, array $linkAttrs): bool
    {
        $preserved = false;
        foreach ( $blocks as $index => $block ) {
            if ( ! is_array($block) ) {
                continue;
            }

            $name = (string) ($block['blockName'] ?? '');

            if ( in_array($name, self::LINK_SELF_MANAGING_BLOCKS, true) ) {
                continue;
            }

            if ( 'core/image' === $name ) {
                if ( $this->propagateLinkOntoImage($blocks[$index], $linkAttrs) ) {
                    $preserved = true;
                }
                continue;
            }

            if ( in_array($name, self::LINK_BEARING_TEXT_BLOCKS, true) ) {
                if ( $this->propagateInlineLink($blocks[$index], $linkAttrs) ) {
                    $preserved = true;
                }
                continue;
            }

            if ( isset($blocks[$index]['innerBlocks']) && is_array($blocks[$index]['innerBlocks']) ) {
                if ( $this->propagateLinkWrapper($blocks[$index]['innerBlocks'], $linkAttrs) ) {
                    $preserved = true;
                }
            }
        }

        return $preserved;
    }

    /**
     * Propagate a card-link wrapper's href onto a core/image block via its
     * native link attributes (href/linkDestination/linkTarget/rel). An image
     * that already carries its own link is left untouched.
     *
     * @param array<string, mixed> $block
     * @param array<string, string> $linkAttrs
     */
    private function propagateLinkOntoImage(array &$block, array $linkAttrs): bool
    {
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
        if ( '' !== (string) ($attrs['href'] ?? '') ) {
            return false;
        }

        $href = (string) ($linkAttrs['href'] ?? '');
        if ( '' === $href ) {
            return false;
        }

        $imageLink = array_filter(array(
            'href'            => $href,
            'linkDestination' => 'custom',
            'linkTarget'      => (string) ($linkAttrs['target'] ?? ''),
            'rel'             => (string) ($linkAttrs['rel'] ?? ''),
        ), static fn (string $value): bool => '' !== trim($value));

        $block = $this->rebuildBlock($block, array_merge($attrs, $imageLink));
        return true;
    }

    /**
     * Propagate a card-link wrapper's href onto a RichText content block
     * (heading/paragraph/list-item) by wrapping its text content in an inline
     * `<a>`. Content that is empty or already carries a link is left untouched.
     *
     * @param array<string, mixed> $block
     * @param array<string, string> $linkAttrs
     */
    private function propagateInlineLink(array &$block, array $linkAttrs): bool
    {
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
        $content = (string) ($attrs['content'] ?? '');
        if ( '' === trim($content) ) {
            return false;
        }

        $href = (string) ($linkAttrs['href'] ?? '');
        if ( '' === $href ) {
            return false;
        }

        if ( preg_match('/<a\b/i', $content) ) {
            return false;
        }

        $wrapped = $this->wrapInlineLink($content, $linkAttrs);
        if ( $wrapped === $content ) {
            return false;
        }

        $replacementAttrs = array_merge($attrs, array( 'content' => $wrapped ));
        if ( 'none' === (string) ($linkAttrs['textDecoration'] ?? '') ) {
            $style = is_array($replacementAttrs['style'] ?? null) ? $replacementAttrs['style'] : array();
            $typography = is_array($style['typography'] ?? null) ? $style['typography'] : array();
            $typography['textDecoration'] = 'none';
            $style['typography'] = $typography;
            $replacementAttrs['style'] = $style;
        }

        $block = $this->rebuildBlock($block, $replacementAttrs);
        return true;
    }

    /**
     * Wrap a RichText content string in an inline `<a>` carrying the propagated
     * href/target/rel.
     *
     * @param array<string, string> $linkAttrs
     */
    private function wrapInlineLink(string $content, array $linkAttrs): string
    {
        $href = (string) ($linkAttrs['href'] ?? '');
        if ( '' === $href || '' === trim($content) ) {
            return $content;
        }

        $attributes = ' href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        if ( '' !== trim((string) ($linkAttrs['target'] ?? '')) ) {
            $attributes .= ' target="' . htmlspecialchars($linkAttrs['target'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        if ( '' !== trim((string) ($linkAttrs['rel'] ?? '')) ) {
            $attributes .= ' rel="' . htmlspecialchars($linkAttrs['rel'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }

        return '<a' . $attributes . '>' . $content . '</a>';
    }

    /**
     * Rebuild a converted block with updated attributes so its innerHTML and
     * innerContent stay consistent with the stored attrs after an in-place edit
     * (e.g. a propagated link). Source-provenance linkage is preserved; no new
     * provenance is recorded for the rebuild.
     *
     * @param array<string, mixed> $block
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function rebuildBlock(array $block, array $attrs): array
    {
        $name = (string) ($block['blockName'] ?? '');
        $innerBlocks = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array();
        $rebuilt = $this->blockFactory->create($name, $attrs, $innerBlocks);
        if ( isset($block['_source_provenance_id']) ) {
            $rebuilt['_source_provenance_id'] = $block['_source_provenance_id'];
        }

        return $rebuilt;
    }

    /**
     * @return array<string, string>
     */
    private function pictureSourceAttributes(DOMElement $picture): array
    {
        foreach ( $picture->childNodes as $child ) {
            if ( ! $child instanceof DOMElement || 'source' !== strtolower($child->tagName) ) {
                continue;
            }

            $srcset = $this->attr($child, 'srcset');
            if ( '' === $srcset || preg_match('/javascript\s*:/i', $srcset) ) {
                continue;
            }

            return array_filter(array(
                'srcset' => $srcset,
                'sizes'  => $this->attr($child, 'sizes'),
            ), static fn (string $value): bool => '' !== $value);
        }

        return array();
    }

    private function safeEmbedUrl(string $url): string
    {
        $url = trim($url);
        if ( '' === $url || ! preg_match('#^https?://#i', $url) ) {
            return '';
        }

        return preg_match('/[\x00-\x1f\x7f]|javascript\s*:/i', $url) ? '' : $url;
    }

    private function canonicalEmbedUrl(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ( ( str_ends_with($host, 'youtube.com') || str_ends_with($host, 'youtube-nocookie.com') ) && preg_match('~^/embed/([^/?#]+)~', $path, $matches) ) {
            return 'https://www.youtube.com/watch?v=' . $matches[1];
        }

        if ( 'youtu.be' === $host && '' !== trim($path, '/') ) {
            return 'https://www.youtube.com/watch?v=' . trim($path, '/');
        }

        if ( str_ends_with($host, 'vimeo.com') && preg_match('#/(?:video/)?(\d+)#', $path, $matches) ) {
            return 'https://vimeo.com/' . $matches[1];
        }

        if ( str_ends_with($host, 'dailymotion.com') && preg_match('~^/embed/video/([^/?#]+)~', $path, $matches) ) {
            return 'https://www.dailymotion.com/video/' . $matches[1];
        }

        if ( 'open.spotify.com' === $host && preg_match('~^/embed/((?:track|album|playlist|episode|show|artist)/[^/?#]+)~', $path, $matches) ) {
            return 'https://open.spotify.com/' . $matches[1];
        }

        return $url;
    }

    private function embedProviderSlug(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ( str_ends_with($host, 'youtube.com') || str_ends_with($host, 'youtube-nocookie.com') || 'youtu.be' === $host ) {
            return 'youtube';
        }
        if ( str_ends_with($host, 'vimeo.com') ) {
            return 'vimeo';
        }
        if ( str_ends_with($host, 'dailymotion.com') && preg_match('~^/embed/video/[^/?#]+~', $path) ) {
            return 'dailymotion';
        }
        if ( 'open.spotify.com' === $host && preg_match('~^/embed/(?:track|album|playlist|episode|show|artist)/[^/?#]+~', $path) ) {
            return 'spotify';
        }

        return '';
    }

    private function embedTypeForSlug(string $slug): string
    {
        return 'spotify' === $slug ? 'rich' : 'video';
    }

    /**
     * @return array<string, string>
     */
    private function safeEmbedAttributes(DOMElement $element): array
    {
        $safe = array();
        $allowed = array_flip(array( 'allow', 'allowfullscreen', 'class', 'height', 'loading', 'referrerpolicy', 'sandbox', 'src', 'title', 'width' ));
        foreach ( $this->htmlAttributes($element) as $name => $value ) {
            if ( isset($allowed[$name]) && ! preg_match('/javascript\s*:/i', $value) ) {
                $safe[$name] = strlen($value) > 300 ? substr($value, 0, 300) . '...' : $value;
            }
        }

        return $safe;
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function convertIframeElement(DOMElement $iframe, array &$fallbacks): ?array
    {
        $url = $this->safeEmbedUrl($this->attr($iframe, 'src'));
        $providerNameSlug = '' === $url ? '' : $this->embedProviderSlug($url);
        if ( '' !== $providerNameSlug ) {
            return $this->createBlock('core/embed', array_filter(array_merge($this->presentationAttributes($iframe), array(
                'url'              => $this->canonicalEmbedUrl($url),
                'type'             => $this->embedTypeForSlug($providerNameSlug),
                'providerNameSlug' => $providerNameSlug,
            )), static fn ($value): bool => '' !== $value), array(), $iframe);
        }

        $boundedHtml = $this->boundedFallbackHtml($this->safeFallbackHtml($iframe));
        $this->recordRuntimeIsland($iframe, 'iframe', 'iframe_requires_embed_runtime', 'third_party_embed_runtime', array(
            'preservation_strategy' => 'sanitized_embed_markup',
            'attributes'            => $this->safeEmbedAttributes($iframe),
        ));
        $fallbacks[] = FallbackDiagnostic::build(array(
            'type'            => 'html',
            'reason'          => 'iframe_embed_fallback',
            'diagnostic_code' => 'html_iframe_embed_fallback',
            'message'         => 'Iframe embed HTML was preserved as sanitized bounded fallback metadata.',
            'source_format'   => 'html',
            'tag'             => 'iframe',
            'selector'        => $this->elementSelector($iframe),
            'attributes'      => $this->safeEmbedAttributes($iframe),
            'context'         => $this->sourceContext($iframe),
            'classification'  => $this->fallbackEmitter->classifyFallbackSubtree($iframe),
            'events'          => $this->eventMetadata($iframe),
            'html'            => $boundedHtml['html'],
            'html_bytes'      => $boundedHtml['bytes'],
            'html_truncated'  => $boundedHtml['truncated'],
        ), $this->fallbackProvenance);

        return null;
    }

    private function safeImageUrl(string $url): string
    {
        if ( ! preg_match('#^data:image/svg\+xml(?:[;,][^,]*)?,#i', $url) ) {
            return $url;
        }

        $parts = explode(',', $url, 2);
        if ( 2 !== count($parts) ) {
            return '';
        }

        $metadata = strtolower($parts[0]);
        $svg = str_contains($metadata, ';base64') ? base64_decode($parts[1], true) : rawurldecode($parts[1]);
        if ( false === $svg || ! $this->isSafeSvgContent($svg) ) {
            return '';
        }

        return $url;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, array<string, mixed>>
     */
    private function assetMetadataFromOptions(array $options): array
    {
        $metadata = array();

        foreach ( array( $options['provenance'] ?? null, $options['context'] ?? null, $options ) as $container ) {
            if ( ! is_array($container) || ! isset($container['asset_metadata']) || ! is_array($container['asset_metadata']) ) {
                continue;
            }

            foreach ( $container['asset_metadata'] as $path => $asset ) {
                if ( ! is_string($path) || '' === trim($path) || ! is_array($asset) ) {
                    continue;
                }

                $metadata[trim($path)] = $asset;
            }
        }

        return $metadata;
    }

    /**
     * @return array<string, int|string>
     */
    private function assetMetadataImageAttributes(string $url): array
    {
        $asset = $this->assetMetadataForUrl($url);
        if ( null === $asset ) {
            return array();
        }

        $attrs = array();
        if ( isset($asset['id']) && ( is_int($asset['id']) || ( is_string($asset['id']) && ctype_digit($asset['id']) ) ) ) {
            $attrs['id'] = (int) $asset['id'];
        }

        if ( isset($asset['url']) && is_string($asset['url']) ) {
            $resolvedUrl = $this->safeResolvedAssetImageUrl(trim($asset['url']));
            if ( '' !== $resolvedUrl ) {
                $attrs['url'] = $resolvedUrl;
            }
        }

        return $attrs;
    }

    private function resolvedAssetImageUrl(string $url): string
    {
        if ( '' === $url ) {
            return '';
        }

        $asset = $this->assetMetadataForUrl($url);
        if ( ! is_array($asset) || ! isset($asset['url']) || ! is_string($asset['url']) ) {
            return $url;
        }

        $resolvedUrl = $this->safeResolvedAssetImageUrl(trim($asset['url']));
        return '' !== $resolvedUrl ? $resolvedUrl : $url;
    }

    private function resolvedAssetImageSrcset(string $srcset): string
    {
        if ( '' === trim($srcset) ) {
            return '';
        }

        $candidates = array();
        foreach ( explode(',', $srcset) as $candidate ) {
            $candidate = trim($candidate);
            if ( '' === $candidate ) {
                continue;
            }

            $parts = preg_split('/\s+/', $candidate, 2);
            if ( ! is_array($parts) || '' === ($parts[0] ?? '') ) {
                continue;
            }

            $url = $this->safeImageUrl((string) $parts[0]);
            if ( '' === $url ) {
                continue;
            }

            $descriptor = trim((string) ($parts[1] ?? ''));
            $candidates[] = trim($this->resolvedAssetImageUrl($url) . ('' !== $descriptor ? ' ' . $descriptor : ''));
        }

        return implode(', ', $candidates);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function assetMetadataForUrl(string $url): ?array
    {
        foreach ( $this->assetMetadataLookupKeys($url) as $key ) {
            if ( isset($this->assetMetadata[$key]) ) {
                return $this->assetMetadata[$key];
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function assetMetadataLookupKeys(string $url): array
    {
        $keys = array();
        foreach ( array( trim($url), ltrim(trim($url), '/') ) as $key ) {
            if ( '' !== $key && ! in_array($key, $keys, true) ) {
                $keys[] = $key;
            }
        }

        $path = parse_url($url, PHP_URL_PATH);
        if ( is_string($path) ) {
            foreach ( array( $path, ltrim($path, '/') ) as $key ) {
                if ( '' !== $key && ! in_array($key, $keys, true) ) {
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    }

    private function safeResolvedAssetImageUrl(string $url): string
    {
        if ( '' === $url || preg_match('/[\x00-\x1f\x7f]|javascript\s*:/i', $url) ) {
            return '';
        }

        return $this->safeImageUrl($url);
    }

    /**
     * @return array<string, string>
     */
    private function imagePresentationAttributes(DOMElement $image, ?DOMElement $figure): array
    {
        $attrs = $this->presentationAttributes($figure ?? $image);
        if ( $figure instanceof DOMElement ) {
            $attrs['className'] = $this->mergeClassNames($this->nonCoreImageFigureClassName($figure), $this->nonCoreImageClassName($image));
        }

        return array_filter($attrs, static fn ($value): bool => is_array($value) ? array() !== $value : '' !== trim((string) $value));
    }

    /**
     * @return array<string, int|string>
     */
    private function imageIdentityAttributes(DOMElement $image, ?DOMElement $figure = null): array
    {
        $attrs = array();
        $className = trim($this->attr($image, 'class') . ' ' . ( $figure instanceof DOMElement ? $this->attr($figure, 'class') : '' ));
        if ( preg_match('/(?:^|\s)wp-image-(\d+)(?:\s|$)/', $className, $matches) ) {
            $attrs['id'] = (int) $matches[1];
        }
        if ( preg_match('/(?:^|\s)size-([a-z0-9_-]+)(?:\s|$)/i', $className, $matches) ) {
            $attrs['sizeSlug'] = strtolower($matches[1]);
        }

        return $attrs;
    }

    private function nonCoreImageClassName(DOMElement $image): string
    {
        $classes = array_filter(preg_split('/\s+/', trim($this->attr($image, 'class'))) ?: array(), static function (string $className): bool {
            return ! preg_match('/^(?:wp-image-\d+|size-[a-z0-9_-]+)$/i', $className);
        });

        return implode(' ', $classes);
    }

    private function nonCoreImageFigureClassName(DOMElement $figure): string
    {
        $classes = array_filter(preg_split('/\s+/', trim($this->attr($figure, 'class'))) ?: array(), static function (string $className): bool {
            return ! preg_match('/^(?:wp-block-image|size-[a-z0-9_-]+)$/i', $className);
        });

        return implode(' ', $classes);
    }

    /**
     * @return array<string, mixed>
     */
    private function codePresentationAttributes(DOMElement $pre, DOMElement $code): array
    {
        $attrs = $this->presentationAttributes($pre);
        $codeClassName = $this->attr($code, 'class');
        if ( '' !== trim($codeClassName) ) {
            $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), $codeClassName);
        }

        return array_filter($attrs, static fn ($value): bool => is_array($value) ? array() !== $value : '' !== trim((string) $value));
    }

    private function codeContent(DOMElement $code): string
    {
        foreach ( $code->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                return $this->sanitizedSyntaxHtml($code);
            }
        }

        return $code->textContent ?? '';
    }

    private function sanitizedSyntaxHtml(DOMElement $element): string
    {
        $html = '';
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                $html .= htmlspecialchars($child->textContent ?? '', ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
                continue;
            }

            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $tagName = strtolower($child->tagName);
            if ( in_array($tagName, array( 'span', 'mark', 'b', 'strong', 'i', 'em' ), true) ) {
                $attrs = array_intersect_key($this->htmlAttributes($child), array_flip(array( 'class', 'data-token', 'title' )));
                $attrs = array_filter($attrs, static fn (string $value): bool => '' !== $value && strlen($value) <= 200 && ! preg_match('/javascript\s*:/i', $value));
                $html .= '<' . $tagName . $this->htmlAttributeString($attrs) . '>' . $this->sanitizedSyntaxHtml($child) . '</' . $tagName . '>';
                continue;
            }

            $html .= htmlspecialchars($child->textContent ?? '', ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return $html;
    }

}

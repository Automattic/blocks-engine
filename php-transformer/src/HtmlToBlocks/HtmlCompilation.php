<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\ChromeJournal\ChromeJournal;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\ChromeJournal\ChromeJournalStore;
use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Automattic\BlocksEngine\PhpTransformer\Css\CssNestingFlattener;
use Automattic\BlocksEngine\PhpTransformer\Support\StyleTagScanner;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\CaptureReplayScripts;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\Contract\BlockCompilationOutput;
use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\BoxTreeClassifier;
use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionFindingContract;
use Automattic\BlocksEngine\PhpTransformer\Contract\EditabilityReport;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\CommentTextRunBoundaries;
use Automattic\BlocksEngine\PhpTransformer\WordPress\CoreBlockCapabilityMatrix;
use Automattic\BlocksEngine\PhpTransformer\Contract\CoreHtmlFallbackEvidence;
use Automattic\BlocksEngine\PhpTransformer\Contract\TransformationOptions;
use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredCarouselBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredMarqueeRecognizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\CustomBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\DescriptionListBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\VisualIframeBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolutionContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\IntrinsicVisualMediaHeight;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StaticClassPromotions;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\EmptyElementFootprint;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\DesignUsageCollector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\HostBlockGapEncoder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextInlinePolicy;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextMaterializer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextStylingHookNormalizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\CapturedDialogConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\CapturedChoiceGroupConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\CapturedListboxConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\CapturedSelectableSetConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\CapturedCollectionConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\CustomElementRuntimeDependency;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ElementConversionPrelude;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\InertScaffoldingSuppressor;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\NativeGetFormControlConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\PhrasingSvgConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ProjectedNavigationConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ScrollStateConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\CopyToClipboardConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ThemeToggleConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ButtonElementContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ButtonElementConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ButtonLinkDispatchContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ButtonLinkDispatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\AuthoredFormControlBlockConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\AuthoredMotionMarkerConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormControlMetadataBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\TableAttributesBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\LinkWrapperPropagator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormCompositionPlanner;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormDispatchContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormDispatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormFallbackFindingBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormFallbackFindingContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FigureElementContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FigureElementConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FlowContainerElementContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FlowContainerElementConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ListElementContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ListElementConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ListStructure;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\MediaDispatchElementContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\MediaDispatchElementConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\OrderedElementConverterRegistry;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\PatternElementConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\QuoteElementConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\DescriptionListElementContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\DescriptionListElementConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\MetadataGridConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\DetailsElementContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\DetailsElementConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\SvgElementContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\SvgElementConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\SvgTextRunLowering;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\InlineContentElementContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\InlineContentElementConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormRuntimeRequirementAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormRuntimeIslandRecorder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\NativeGetFormBlockBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormSuccessPanelMetadataBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ReadableFormControlBlockConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ReadableFormBlockBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\PseudoFormAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\RuntimeIslandAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\RuntimeResourceElementConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\TableElementContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\TableElementConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\UnsupportedElementContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\UnsupportedElementRecorder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\WrapperCoalescer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\RichTextElementContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\RichTextElementConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\SearchBlockConversionContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\SearchBlockConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\LoginBlockConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\TextLeafElementContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\TextLeafElementConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\BlockBindingBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\CommerceFallbackReporter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\SourceElementDescription;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackDiagnostic;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\GutenbergGaps;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\InteractionFallbackReporter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CoverPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ButtonPatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CodeWindowPatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\DetailsPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ColumnsPatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CommerceStructureRecognizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\LogoPatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\MarkupPatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\MediaPatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationPatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ParameterTablePattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternRecognizerRegistry;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ProbeBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\QuotePatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorSelectorSemanticContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorSelectorSemanticPreparer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorStyleRuleProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorStylesheetMaterialization;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorStylesheetProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\NavigationStyleProjectionContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\NavigationStyleProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\GeneratedBlockStyleProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\DisclosureControlPresentation;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\ImageDimensionResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjectionContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjectionFacts;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SocialSourceStyleProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StylesheetAnalysisComposer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StylesheetAssetStage;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\BackgroundImageExtractor;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\CapturedNavigationPanels;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\DeclarativeCounterMaterializer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\DefaultCommentAttributes;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\DomHelpersTrait;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorLayoutOwnership;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\NonRenderedMetadata;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\HeadMetadataReport;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\SourceProvenance;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\NavigationToggleSuppressionContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\NavigationToggleSuppressor;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\OwnSaveShapeInverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\CapturedGlyphColor;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\QuirksModeSource;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\RuntimeScriptProjections;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceInterItemSpaces;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\TransformOptions;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\ImageUrlResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SvgMaterializer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMElement;
use DOMNode;

/** Run-scoped compiler for one HTML document. */
final class HtmlCompilation extends SourceBlockCreator implements RichTextInlinePolicy, PatternTreeConverter, Elements\AuthorLayoutLowering
{
    use DomHelpersTrait;

    private readonly AuthorLayoutOwnership $authorLayoutOwnership;
    private readonly Elements\LayoutTableLowering $layoutTables;

    private ?Style\BoxTreeScrollAnimationBinding $boxTreeScrollAnimationBinding = null;

    /** Author CSS visible to the document, captured once per transform for element-dispatch checks. */
    private string $documentAuthorCss = '';

    /**
     * Reference viewport width (px) for resolving responsive image constraints
     * (aspect-ratio/object-fit) to the single value WordPress' core/image carries.
     * min-width @media overrides at or below this width may win over the base rule.
     */
    private const DESKTOP_REFERENCE_WIDTH = 1440;

    /**
     * Tag-only script selectors that must keep their native DOM shape when a
     * first-party runtime binds directly to them.
     *
     * @var array<int, string>
     */
    private const RUNTIME_TAG_SELECTORS = array( 'button', 'input', 'select', 'textarea', 'ul', 'ol', 'li', 'span', 'menu', 'menuitem' );

    private readonly BlockFactory $blockFactory;
    private readonly TableAttributesBuilder $tableAttributesBuilder;
    private readonly Elements\LinkedIconTextRowLowering $linkedIconTextRows;

    private readonly BlockMaterializer $blockMaterializer;


    private readonly TableClassificationPolicy $tableClassificationPolicy;

    private readonly PatternRecognizerRegistry $patternRecognizers;



    private readonly StyleResolver $styleResolver;
    private readonly SourceInterItemSpaces $sourceInterItemSpaces;
    private ?IntrinsicVisualMediaHeight $intrinsicVisualMediaHeight = null;
    private readonly ImageUrlResolver $imageUrls;
    private readonly ImageDimensionResolver $imageDimensions;
    private readonly Elements\ImageLowering $imageLowering;
    private readonly EmptyElementFootprint $emptyElementFootprint;


    private readonly SourceBlockAttributeProjector $sourceBlockAttributeProjector;

    private readonly StylesheetAnalysisComposer $stylesheetAnalysisComposer;

    private readonly StylesheetAssetStage $stylesheetAssetStage;

    private readonly AuthorSelectorSemanticPreparer $authorSelectorSemanticPreparer;

    private readonly AuthorStylesheetProjector $authorStylesheetProjector;

    private readonly NavigationStyleProjector $navigationStyleProjector;

    private readonly SvgMaterializer $svgMaterializer;

    private readonly RichTextMaterializer $richTextMaterializer;
    private readonly RichTextStylingHookNormalizer $richTextStylingHooks;
    private readonly Style\SourceElementVisibility $sourceVisibility;
    private readonly Elements\StandaloneBreakLowering $standaloneBreaks;
    private readonly AuthoredMarqueeRecognizer $authoredMarqueeRecognizer;

    private readonly NavigationToggleSuppressor $navigationToggleSuppressor;

    private readonly RuntimeIslandAnalyzer $runtimeIslands;
    private readonly Elements\GeneratedComponentLowering $generatedComponents;
    private readonly Elements\LayoutShellLowering $layoutShells;
    private readonly Elements\SyntheticImageFigureFlow $syntheticImageFigureFlow;
    private readonly Support\SourceDocumentEnvelope $documentEnvelope;
    private readonly Support\GeneratedComponentCandidates $generatedComponentCandidates;
    private readonly Elements\AnchorLowering $anchors;
    private readonly Elements\RenderNeutralElements $renderNeutral;
    private readonly Elements\EmptyInlineGeometryLowering $emptyInlineGeometry;
    private readonly Elements\CustomWrapperConventions $wrapperConventions;
    private readonly Diagnostics\FallbackReconciliation $fallbackReconciliation;
    private readonly Elements\GlobalShellArtifacts $globalShellArtifacts;
    private readonly Elements\ParagraphImageUnwrapping $paragraphImages;
    private readonly Elements\TextlessMediaLowering $textlessMedia;
    private readonly Elements\EmptyVisualRetentionPolicy $emptyVisualRetention;
    private readonly Elements\AuthorLayoutBlockLowering $authorLayout;
    private readonly Elements\StructuralRichTextContainerLowering $structuralRichTextContainers;
    private readonly SourceProvenance $sourceProvenance;


    private readonly FormRuntimeIslandRecorder $formRuntimeIslandRecorder;

    private readonly InteractionFallbackReporter $interactionFallbackReporter;

    private readonly AuthoredFormControlBlockConverter $authoredFormControlBlockConverter;






    /** @var list<DOMElement> Neutral convention instances retained as author selector hooks. */
    private array $retainedCustomWrapperHooks = array();

    private readonly ElementConversionPrelude $elementPrelude;
    /** Element conversion steps, in dispatch order. */
    private readonly OrderedElementConverterRegistry $elementConverters;

    private readonly WrapperCoalescer $wrapperCoalescer;

    /**
     * Source node ids the box-tree classifier marked neutral, mapped to their
     * sole observed child's node id. Empty on routes without box-tree evidence.
     *
     * @var array<string, string>
     */
    private array $neutralBoxChildren = array();

    /** Converts the page's repeated subtrees through the site's journals. */
    private ?ChromeJournal $chromeJournal = null;

    /**
     * Folded boxes' moves, keyed by the receiving child's node id: the box id
     * (`from`), child property => box property (`moves`), and whether the
     * child's border box equals the box's (`equal_box`).
     *
     * @var array<string, array{from: string, moves: array<string, string>, equal_box: bool}>
     */
    private array $foldedInto = array();

    /** @var array<string, array<string, list<array{0: float|null, 1: string}>>> Lifted author bands by node id. */
    private array $foldFluid = array();

    /** @var array<string, string> Folded box id => node id of the element whose box-tree rule took its declarations. */
    private array $foldsCarried = array();

    /** @var array<string, list<string>> Box-tree carrier classes projected for a source element, by node id. */
    private array $boxTreeCarriers = array();

    /** Node id of the element whose box-tree rule is being lifted. */
    private string $liftingFor = '';













    private readonly UnsupportedElementRecorder $unsupportedRecorder;

    private readonly PatternContext $patternContext;

    private readonly PatternContext $patternProbeContext;

    private readonly ProjectedNavigationConverter $projectedNavigation;

    private readonly SourceElementClassifier $sourceElementClassifier;

    private readonly SourceElementDescription $sourceElementDescription;
    private readonly CommerceFallbackReporter $commerceReporter;
    private readonly Diagnostics\FallbackBindingFinalizer $fallbackBindings;
    private readonly NavigationBlockNormalizer $navigationBlockNormalizer;

    private readonly Diagnostics\CompilationValidation $validation;



    private readonly ReusableComponentRecognizer $reusableComponentRecognizer;

    private HtmlTransformerSession $session;

    private const SYNTHETIC_PARAGRAPH_CLASS = SourceBlockAttributeProjector::SYNTHETIC_PARAGRAPH_CLASS;
    private const SYNTHETIC_SVG_PARAGRAPH_CLASS = SourceBlockAttributeProjector::SYNTHETIC_SVG_PARAGRAPH_CLASS;

    private const SYNTHETIC_ANCHOR_UNDECORATED_CLASS = SourceBlockAttributeProjector::SYNTHETIC_ANCHOR_UNDECORATED_CLASS;

    private const SYNTHETIC_IMAGE_FIGURE_CLASS = SourceBlockAttributeProjector::SYNTHETIC_IMAGE_FIGURE_CLASS;

    private const INLINE_LAYOUT_CARRIER_CLASS = AuthorStylesheetProjector::INLINE_LAYOUT_CARRIER_CLASS;


    public const EMPTY_VISUAL_GROUP_CLASS = 'blocks-engine-empty-visual-group';
    /**
     * Marks a core/paragraph lowered from a margin-styled text-only div. The
     * source div has no user-agent block margins, but the emitted <p> would
     * gain the paragraph's 1em UA margin on any side the author left unset.
     */
    public const LOWERED_PARAGRAPH_CLASS = 'blocks-engine-lowered-paragraph';


    /**
     * Marks an emptied block that exists only as a runtime target. Emitted
     * here and read back by {@see NavigationStyleProjector}, which projects the
     * editor static-state CSS that hides it.
     */
    public const EMPTY_RUNTIME_TARGET_CLASS = 'blocks-engine-empty-runtime-target';


    private const CSS_OWNED_INLINE_FLOW_CLASS = SourceBlockAttributeProjector::CSS_OWNED_INLINE_FLOW_CLASS;

    private const CSS_OWNED_LAYOUT_ITEM_CLASS = SourceBlockAttributeProjector::CSS_OWNED_LAYOUT_ITEM_CLASS;

    public function __construct(
        private readonly Runtime $runtime = new Runtime(),
        private readonly HtmlTransformerAnalysisCache $analysisCache = new HtmlTransformerAnalysisCache()
    )
    {
        $this->sourceElementClassifier = new SourceElementClassifier();
        $listStructure = new ListStructure($this->sourceElementClassifier, fn (DOMElement $element): array => $this->session->boxTreeEvidence($element));
        $commercePattern = $this->createCommercePattern();
        $this->sourceElementDescription = new SourceElementDescription($this->sourceElementClassifier, $commercePattern);
        $this->session = new HtmlTransformerSession($this->runtime, $this->sourceElementDescription);
        parent::__construct($this->session);
        $this->stylesheetAssetStage = new StylesheetAssetStage($this->session);
        $this->blockFactory      = new BlockFactory();
        $backgroundImageExtractor = new BackgroundImageExtractor();
        $this->tableClassificationPolicy = new TableClassificationPolicy();
        $this->navigationBlockNormalizer = new NavigationBlockNormalizer($this->normalizedNavigationLabel(...));
        $this->reusableComponentRecognizer = new ReusableComponentRecognizer();
        $this->styleResolver = new StyleResolver($this->session, $this->createStyleResolutionContext(), $this->analysisCache);
        $this->validation = new Diagnostics\CompilationValidation(
            $this->runtime,
            $this->session,
            $this->analysisCache->cssFontAnalysis,
            $this->styleResolver->isDisplayNoneAtEveryViewport(...)
        );
        $this->sourceInterItemSpaces = new SourceInterItemSpaces($this->styleResolver, $this->sourceElementClassifier);
        $this->imageUrls = new ImageUrlResolver($this->session);
        $mediaLowering = new Elements\MediaLowering($this, $this->styleResolver, $this->imageUrls);
        $this->imageDimensions = new ImageDimensionResolver($this->styleResolver);
        $this->imageLowering = new Elements\ImageLowering(
            $this,
            $this->blockFactory,
            $this->styleResolver,
            $backgroundImageExtractor,
            $this->sourceElementDescription,
            $this->session,
            $this->imageUrls,
            $this->imageDimensions,
            $this->sourceElementClassifier
        );
        $this->emptyElementFootprint = new EmptyElementFootprint($this->styleResolver, $this->sourceElementClassifier,
            new HostBlockGapEncoder($this->styleResolver, $this->blockFactory, $this->session),
            $this->session->sourceStyleResolutionState()
        );
        $linkWrapperPropagator = new LinkWrapperPropagator($this->styleResolver, $this->blockFactory);
        $generatedBlockStyleProjector = new GeneratedBlockStyleProjector($this->runtime, $this->styleResolver, $this->session);
        $this->sourceBlockAttributeProjector = new SourceBlockAttributeProjector($this->styleResolver, $generatedBlockStyleProjector);
        $this->stylesheetAnalysisComposer = new StylesheetAnalysisComposer($this->styleResolver, $this->analysisCache);
        $this->navigationStyleProjector = new NavigationStyleProjector($this->session,
            $this->createNavigationStyleProjectionContext(),
            $this->styleResolver
        );
        $this->svgMaterializer = new SvgMaterializer($this, $this->session, $this->sourceElementClassifier,
            $this->styleResolver,
            $this->runtime
        );
        $capturedDisclosureDialogs = new Elements\CapturedDisclosureDialog($this->styleResolver);
        $this->standaloneBreaks = new Elements\StandaloneBreakLowering($this, $this->styleResolver);
        $flankedSeparators = new Elements\FlankedSeparatorLowering($this, $this->styleResolver, $this->sourceElementClassifier);
        $this->sourceVisibility = new Style\SourceElementVisibility($this->styleResolver);
        $this->richTextMaterializer = new RichTextMaterializer($this->styleResolver, $this->svgMaterializer, $this->session, $this);
        $this->structuralRichTextContainers = new Elements\StructuralRichTextContainerLowering($this, $this, $this->richTextMaterializer, $this->styleResolver);
        $this->richTextStylingHooks = new RichTextStylingHookNormalizer($this->styleResolver, $this->richTextMaterializer, $this->session, $this->sourceElementClassifier);
        $inlineFlows = new Elements\InlineFlowLowering($this, $this, $this->styleResolver, $this->sourceElementClassifier, $this->richTextMaterializer, $this->runtime);
        $authoredStateButtons = new Elements\AuthoredStateButtonLowering($this->session, $this->svgMaterializer);
        $this->linkedIconTextRows = new Elements\LinkedIconTextRowLowering(
            $this,
            $this->styleResolver,
            $this->imageLowering,
            $linkWrapperPropagator,
            $this->svgMaterializer,
            $this->richTextMaterializer,
            $this->runtime
        );
        $inlineSvgTextGroups = new Elements\InlineSvgTextGroupLowering(
            $this,
            $this->session,
            $this->sourceElementClassifier,
            $this->styleResolver,
            $this->svgMaterializer,
            $this->richTextMaterializer,
            $this->runtime
        );
        $this->patternRecognizers = PatternRecognizerRegistry::createDefault($this, $this->runtime, $this->sourceElementClassifier, $this->richTextMaterializer);
        $this->authoredMarqueeRecognizer = new AuthoredMarqueeRecognizer($this->sourceElementClassifier, $this->richTextMaterializer, $this->runtime, $this->session,
            fn (DOMElement $element, string $className): string => $this->sourceBlockAttributeProjector->sourceProjectionClassName($element, $this->sourceBlockAttributeProjectionContext(), $className)
        );
        $this->patternProbeContext = $this->createProbePatternContext();
        $this->navigationToggleSuppressor = new NavigationToggleSuppressor($this->session,
            $this->createNavigationToggleSuppressionContext(),
            $this->styleResolver
        );
        $this->tableAttributesBuilder = new TableAttributesBuilder($this->styleResolver, $this->navigationToggleSuppressor, $this->session);
        $formControlMetadataBuilder = new FormControlMetadataBuilder(
            $this->styleResolver,
            $this->session,
            $this->richTextStylingHooks->formContextContent(...),
            function (DOMElement $element): array {
                if ( '' === $this->session->authorStyleAnalysis()->combinedCss() ) {
                    return array();
                }
                $path = $element->getNodePath() ?? '';
                $classes = $this->session->authorSelectorProjectionState()->controlCarrierClasses($path);
                $marker = $this->session->authorSelectorProjectionState()->controlMarker($path);
                if ( '' !== $marker ) {
                    $rule = $this->controlCarrierLayerRevertRule($element, $marker);
                    if ( '' !== $rule ) {
                        $this->session->generatedSupportStylesheetState()->registerNativeButton($marker, $rule);
                    }
                }
                return $classes;
            },
            $this->styleResolver
        );
        $pseudoFormAnalyzer = new PseudoFormAnalyzer($formControlMetadataBuilder);
        $this->runtimeIslands = new RuntimeIslandAnalyzer($this->session, $this->sourceElementClassifier, $pseudoFormAnalyzer);
        $this->generatedComponents = new Elements\GeneratedComponentLowering($this->session, $this->runtimeIslands, $this, $this);
        $this->renderNeutral = new Elements\RenderNeutralElements($this->session, $this->sourceElementClassifier, $this->sourceElementDescription, $this->runtimeIslands, $this->emptyElementFootprint);
        $styledClassBoundaries = new Support\StyledSourceClassBoundary($this->session);
        $this->emptyVisualRetention = new Elements\EmptyVisualRetentionPolicy(
            $this->styleResolver,
            $this->session,
            $this->sourceElementClassifier,
            $this->sourceElementDescription,
            $this->runtimeIslands,
            $this->emptyElementFootprint,
            $this->sourceVisibility,
            $this->renderNeutral,
            $styledClassBoundaries,
            $this->generatedComponents
        );
        $this->layoutShells = new Elements\LayoutShellLowering($this, $this->session, $this->styleResolver, $this->runtimeIslands);
        $this->sourceProvenance = new SourceProvenance(
            $this->session,
            $this->sourceElementDescription,
            $this->runtimeIslands
        );
        $this->wrapperCoalescer = new WrapperCoalescer(
            $this->sourceElementClassifier,
            $this->runtimeIslands,
            $this->styleResolver,
            $this,
            $this->session,
            $this->sourceElementDescription,
            $this->emptyVisualRetention->soleElementChild(...),
            $this->imageLowering->isImageOnlyAnchor(...),
            $this->emptyVisualRetention->preservesScriptStateWrapper(...),
            $this
        );
        $this->authorLayoutOwnership = new AuthorLayoutOwnership(
            $this->styleResolver,
            $this->sourceElementClassifier,
            $this->runtimeIslands,
            $this->wrapperCoalescer,
            $this->session,
            $this->runtime,
            $listStructure
        );
        $this->authorLayout = new Elements\AuthorLayoutBlockLowering(
            $this,
            $this,
            $this->session,
            $this->styleResolver,
            $this->richTextMaterializer,
            $this->runtime,
            $this->emptyVisualRetention,
            $this->layoutShells,
            $this->wrapperCoalescer,
            $this->authorLayoutOwnership
        );
        $emptyFeatureShells = new Elements\EmptyInteractiveFeatureShell($this->runtimeIslands, $this->wrapperCoalescer, $this->emptyElementFootprint);
        $this->generatedComponentCandidates = new Support\GeneratedComponentCandidates($this->session, $this->sourceElementClassifier);
        $this->documentEnvelope = new Support\SourceDocumentEnvelope($this->styleResolver);
        $customVideoHosts = new Elements\CustomVideoHostLowering($this->session, $this->styleResolver, $this->imageUrls, $this->runtimeIslands, $mediaLowering, $this->imageLowering, $this->layoutShells, $this);
        $listItemLowering = new Elements\ListItemLowering($this, $this, $this->runtime, $this->session, $this->styleResolver, $listStructure, $this->richTextMaterializer, $this->wrapperCoalescer, $this->authorLayoutOwnership);
        $structuredInlineItemLowering = new Elements\StructuredInlineItemLowering($this, $this->runtime, $this->sourceElementClassifier, $this->styleResolver, $inlineSvgTextGroups, $this->richTextMaterializer);
        $inlineTextWrappers = new Elements\InlineTextWrapperLowering(
            $this,
            $this,
            $this->session,
            $this->sourceElementClassifier,
            $this->styleResolver,
            $this->richTextMaterializer,
            $this->richTextStylingHooks,
            $structuredInlineItemLowering,
            $this->authorLayoutOwnership,
            $this->emptyVisualRetention,
            $this->runtime
        );
        $inertElements = new Elements\InertElements($this->styleResolver, $this->sourceElementClassifier, $this->sourceVisibility, $this->runtimeIslands);
        $this->emptyInlineGeometry = new Elements\EmptyInlineGeometryLowering($this, $this->session, $this->sourceElementClassifier, $this->emptyElementFootprint, $authoredStateButtons, $this->layoutShells, $this->runtimeIslands);
        $this->wrapperConventions = new Elements\CustomWrapperConventions($this->renderNeutral);
        $emptyVisualSpacers = new Elements\EmptyVisualSpacerLowering($this, $this, $this->session, $this->styleResolver, $this->emptyElementFootprint, $this->runtimeIslands);
        $this->fallbackReconciliation = new Diagnostics\FallbackReconciliation($this->runtimeIslands);
        $inlineCheckableControls = new Elements\InlineCheckableControlLowering($this, $this->runtime, $this->session, $this->sourceElementClassifier, $this->richTextMaterializer, $authoredStateButtons, $this->runtimeIslands, $this->layoutShells);
        $this->globalShellArtifacts = new Elements\GlobalShellArtifacts($this, $this, $this->runtime, $this->session, $this->navigationBlockNormalizer);
        $linkCarriers = new Elements\LinkCarrierLowering($this, $this, $this->runtime, $this->styleResolver, $this->runtimeIslands, $this->layoutShells);
        $namePriceRows = new Elements\NamePriceRowLowering($this, $this, $this->styleResolver, $this->sourceElementDescription);
        $mediaGalleries = new Elements\MediaGalleryLowering($this->sourceElementClassifier, $this->styleResolver, $this->imageLowering, $this);
        $customImageHosts = new Elements\CustomImageHostLowering($this, $this->blockFactory, $this->styleResolver, $this->session, $this->sourceElementClassifier, $this->sourceElementDescription, $this->imageLowering, $this->layoutShells, $this->runtimeIslands, $this->authorLayoutOwnership, $this->imageDimensions, $this->imageUrls, $this->renderNeutral);
        $this->anchors = new Elements\AnchorLowering($this, $this, $this->styleResolver, $this->session, $this->sourceElementClassifier, $this->sourceElementDescription, $this->imageLowering, $this->imageUrls, $this->layoutShells, $linkWrapperPropagator, $this->runtimeIslands, $customImageHosts);
        $this->layoutTables = new Elements\LayoutTableLowering(
            $this,
            $this->session,
            $this->styleResolver,
            $this->blockFactory,
            $this
        );
        $this->syntheticImageFigureFlow = new Elements\SyntheticImageFigureFlow($this->styleResolver, $this->sourceElementClassifier, $this->tableClassificationPolicy, $this->layoutTables);
        $definitionLists = new Elements\DefinitionListLowering($this, $this->runtime, $this->styleResolver);
        $this->paragraphImages = new Elements\ParagraphImageUnwrapping($this->session, $this->styleResolver, $this->blockFactory, $this->imageLowering, $this->anchors);
        $structuredCardLists = new Elements\StructuredCardListLowering(
            $this,
            $this->styleResolver,
            $this->authorLayoutOwnership,
            $this->richTextMaterializer,
            $this->sourceElementClassifier,
            $this->runtime,
            $this,
            $this->richTextStylingHooks
        );
        $this->authorSelectorSemanticPreparer = new AuthorSelectorSemanticPreparer($this->sourceElementClassifier,
            new AuthorSelectorSemanticContext(
                $this->authorLayoutOwnership,
                $listStructure,
                $this->styleResolver,
                $this->wrapperCoalescer,
                $this->emptyVisualRetention->shouldPreserveEmptyVisualElement(...),
                $this->session,
                $this->tableClassificationPolicy,
                $this->paragraphImages
            ),
            $this->stylesheetAnalysisComposer,
            $this->styleResolver,
            $this->analysisCache
        );
        $authorStyleRuleProjector = new AuthorStyleRuleProjector($this->styleResolver, $this->authorSelectorSemanticPreparer);
        $this->authorStylesheetProjector = new AuthorStylesheetProjector(
            $this->styleResolver,
            $this->authorSelectorSemanticPreparer,
            $authorStyleRuleProjector,
            $this->session->sourceStyleResolutionState()
        );
        $textLeafConverter = new TextLeafElementConverter($this->richTextMaterializer, $this->runtime, $this, $this->styleResolver, $this->sourceElementClassifier, $this->createTextLeafElementContext());
        $richTextConverter = new RichTextElementConverter($this, $this->styleResolver, $this->richTextMaterializer, $this->runtime, $this->createRichTextElementContext());
        $svgConverter = new SvgElementConverter($this, $this->sourceElementClassifier, $this->styleResolver, new SvgElementContext(
            $inertElements->isInertHiddenSvgStorage(...),
            $this->runtimeIslands,
            $this->svgMaterializer->sanitizeInlineSvgMarkup(...),
            SourceDom::isSafeSvgContent(...),
            SourceDom::svgHasDrawableContent(...),
            function (DOMElement $element, array &$fallbacks): void {
                $this->session->fallbackEmitter()->captureInlineSvgFallback($element, $fallbacks);
            },
            $this->svgMaterializer->omitsEmptyPaintDecoration(...),
            (new Elements\ForeignObjectFitTextLowering($this, $richTextConverter, $this->styleResolver, $this->blockFactory))->block(...)
        ), $this->svgMaterializer, new SvgTextRunLowering(
            $this->styleResolver,
            $this->styleResolver->customProperties->resolve(...),
            $this,
            $this->session
        ));
        $inlineContentConverter = new InlineContentElementConverter($this->sourceElementClassifier, $this, $this->richTextMaterializer, new InlineContentElementContext(
            $this,
            $this->runtimeIslands,
            $inlineSvgTextGroups->fromElement(...),
            $this->authorLayoutOwnership->ownsPositioningGeometry(...),
            $inlineTextWrappers->positionedInlineCarrierBlock(...),
            fn (DOMElement $element): bool => $this->session->authorSelectorProjectionState()->hasSemanticMarker($element),
            $this->authorLayoutOwnership->richTextMarker(...),
            $listStructure,
            $this->emptyVisualRetention->shouldPreserveEmptyVisualElement(...),
            $emptyVisualSpacers->emptyVisualSpacerBlock(...),
            $this->session->rememberNativeDisclosure(...),
            $this->runtimeIslands->retainsRuntimeTextLeafInRichText(...)
        ), $this->styleResolver, $this->runtime, $this->sourceBlockAttributeProjector);
        $this->authoredFormControlBlockConverter = new AuthoredFormControlBlockConverter(
            $formControlMetadataBuilder,
            $this->styleResolver->authoredPresentationDeclarations(...),
            $this->styleResolver,
            $this,
            $this->session,
            $this->runtime,
            $this->sourceTagProjectedClone(...),
            fn (DOMElement $label): string => $this->richTextMaterializer->content($label, array( 'input', 'select', 'textarea' )),
            $this->richTextStylingHooks->inlineContent(...),
            $this->wrapperCoalescer
        );
        $this->projectedNavigation = new ProjectedNavigationConverter(
            $this->navigationToggleSuppressor,
            $this->styleResolver,
            $this->session,
            $this,
            $this,
            $this->runtimeIslands,
            $this->svgMaterializer->inlineSvgMarkup(...)
        );
        $this->patternContext = $this->createPatternContext();
        $this->interactionFallbackReporter = new InteractionFallbackReporter(
            $this->navigationToggleSuppressor,
            $this->runtimeIslands,
            $this->runtime,
            $this->session,
            $this->sourceElementDescription
        );
        $blockBindings = new BlockBindingBuilder($this->runtime, $this->session->transformationProvenanceState());
        $formFallbackContext = new FormFallbackFindingContext(
            $this->session,
            $this->runtimeIslands->runtimeDomSelectorsForElement(...),
            $this->sourceElementDescription,
            $blockBindings->binding(...),
            fn (DOMElement $element, string $value): string => $this->styleResolver->customProperties->resolve($value, $element),
            $this->svgMaterializer->inlineSvgMarkup(...)
        );
        $this->commerceReporter = new CommerceFallbackReporter(
            $commercePattern,
            $this->runtime,
            $this->session->transformationProvenanceState(),
            $this->runtimeIslands,
            $this->sourceElementDescription,
            $formControlMetadataBuilder,
            $this->session,
            $formFallbackContext,
            $blockBindings
        );
        $this->fallbackBindings = new Diagnostics\FallbackBindingFinalizer($this, $this->session, $this->commerceReporter);
        $this->blockMaterializer = new BlockMaterializer($this->blockFactory, $this->runtimeIslands);
        $runtimeResourceConverter = new RuntimeResourceElementConverter(
            $this->session,
            $this
        );
        $this->formRuntimeIslandRecorder = new FormRuntimeIslandRecorder(
            $formControlMetadataBuilder,
            $this->runtimeIslands->recordRuntimeIsland(...),
            $this->session
        );
        $readableFormControlBlockConverter = new ReadableFormControlBlockConverter(
            $formControlMetadataBuilder,
            $this->authoredFormControlBlockConverter,
            $this->formRuntimeIslandRecorder,
            $this->runtime,
            $this->runtimeIslands,
            $this->styleResolver,
            $this,
            $this->session
        );
        $nativeGetFormBlockBuilder = new NativeGetFormBlockBuilder(
            $this,
            function (DOMElement $element): array {
                $attrs = $this->styleResolver->presentationAttributes($element);
                $alignment = $this->styleResolver->inheritedTextAlignmentDeclaration($element);
                if (in_array($alignment, array('left', 'center', 'right'), true)) {
                    $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), 'has-text-align-' . $alignment);
                }
                return $attrs;
            },
            $this,
            $this->session
        );
        $searchBlockConverter = new SearchBlockConverter($this->styleResolver, $this->session, $this, $this->svgMaterializer, $this->runtimeIslands, $this->createSearchBlockConversionContext(), $formControlMetadataBuilder, $pseudoFormAnalyzer);
        $formDispatcher = $this->createFormDispatcher($formControlMetadataBuilder, $pseudoFormAnalyzer, $formFallbackContext, $readableFormControlBlockConverter, $nativeGetFormBlockBuilder, $searchBlockConverter);
        $buttonLinkDispatcher = new ButtonLinkDispatcher($this->sourceElementClassifier, $this->createButtonLinkDispatchContext());
        $buttonConverter = new ButtonElementConverter($this->sourceElementClassifier, $this, $this->styleResolver, new ButtonElementContext(
            $searchBlockConverter->isReplacedSearchClusterControl(...),
            $this,
            $buttonLinkDispatcher->convertButton(...),
            $this->runtimeIslands,
            $this->runtimeAuthoredButton(...),
            $this->convertButtonWrapperGroup(...),
            $this->imageDimensions->minimumPixelSize(...)
        ));
        $detailsConverter = new DetailsElementConverter(new DetailsElementContext(
            $capturedDisclosureDialogs->fromDetails(...),
            $this->capturedDialogBlock(...),
            $this
        ));
        $specializedElementConverters = new OrderedElementConverterRegistry(array(
            $detailsConverter,
            $buttonConverter,
            $svgConverter,
        ));
        $tableConverter = new TableElementConverter($this->styleResolver, $this, $this->createTableElementContext());
        $parameterTableConverter = new PatternElementConverter(
            $this,
            array( ParameterTablePattern::class )
        );
        $tableConverters = new OrderedElementConverterRegistry(array(
            $tableConverter,
            $parameterTableConverter,
        ));
        $postStructuralElementConverters = new OrderedElementConverterRegistry(array(
            $textLeafConverter,
            $tableConverters,
            $specializedElementConverters,
            $runtimeResourceConverter,
        ));
        $figureConverter = new FigureElementConverter($this, $this->styleResolver, new FigureElementContext(
            $mediaGalleries->mediaGalleryBlockFromElement(...),
            $this,
            $this->imageLowering->figureLinkedMediaAnchor(...),
            $this->imageLowering->convertPicture(...),
            fn (DOMElement $image, ?DOMElement $figure = null, ?DOMElement $picture = null, ?DOMElement $link = null): ?array => $this->imageLowering->convertImage($image, $figure, $picture, $link),
            SourceDom::figureMediaElement(...),
            (new Elements\GenericFigureLowering($this, $this, $this->styleResolver, $this->runtime, $this->emptyVisualRetention, $emptyVisualSpacers, $this->emptyElementFootprint, $this->session))->block(...),
            $this->runtime->hasVisibleText(...)
        ));
        $quoteConverter = new QuoteElementConverter($this);
        $listConverter = new ListElementConverter($this->styleResolver, $this, new ListElementContext(
            $this,
            $this->session->runtimeBehaviorState()->rememberAccordionDisclosureRoot(...),
            $structuredCardLists->isStructuredCardList(...),
            $structuredCardLists->decomposeStructuredCardList(...),
            $listStructure->listContainsStructuralItemContent(...),
            $listItemLowering->decomposeStructuralList(...),
            $listItemLowering->listItems(...),
            $this->authorLayoutOwnership
        ));
        $descriptionListGenerator = new DescriptionListBlockGenerator(
            $this->sourceElementClassifier,
            $this->session
        );
        $authoredCarouselGenerator = new AuthoredCarouselBlockGenerator(
            $this->sourceElementClassifier,
            $this->styleResolver,
            $this,
            $this->blockFactory,
            $this->runtime,
            $this->session,
            // Carousel slides are editable native images inside authored
            // holders; the carousel owns slide presentation.
            fn (DOMElement $element, ?DOMElement $link = null): ?array => $this->imageLowering->convertImage($element, null, null, $link, nativeImage: true),
            $this
        );
        $visualIframeGenerator = new VisualIframeBlockGenerator(
            $this->sourceElementClassifier,
            $this->styleResolver,
            $this,
            $this->runtimeIslands,
            $this->session,
            $inertElements->isInertRuntimeMediaPlaceholder(...),
            $this->sourceVisibility->startsHidden(...),
            $this->sourceElementDescription
        );
        $this->textlessMedia = new Elements\TextlessMediaLowering($visualIframeGenerator, $this->paragraphImages);
        $customBlockGenerator = new CustomBlockGenerator(
            $this->sourceElementClassifier,
            $this->styleResolver,
            $this,
            fn (DOMElement $element): bool => $this->runtimeIslands->isSafeTransparentCustomElement($element),
            $this,
            fn (DOMElement $element): bool => $this->session->authorSelectorProjectionState()->hasSemanticMarker($element),
            function (array $elements, array $innerBlocks, DOMElement $sourceElement): array {
                return $this->layoutShells->blockForElements($elements, $innerBlocks, $sourceElement);
            }
        );
        $metadataGrid = new MetadataGridConverter(
            $this,
            $this->styleResolver,
            $this->sourceElementClassifier,
            $this->richTextMaterializer,
            $this->runtime
        );
        $descriptionListConverter = new DescriptionListElementConverter($this->sourceElementClassifier, $this, $this->styleResolver, $this->richTextMaterializer, new DescriptionListElementContext(
            $descriptionListGenerator->convert(...),
            $metadataGrid->convert(...),
            $definitionLists->definitionListItems(...),
            $this->authorLayoutOwnership,
            $this,
            $this->runtime->hasVisibleText(...)
        ));
        $structuralContentConverters = new OrderedElementConverterRegistry(array(
            $inlineContentConverter,
            $listConverter,
            $descriptionListConverter,
            $quoteConverter,
            $figureConverter,
        ));
        $flowContainerConverter = new FlowContainerElementConverter($this->sourceElementClassifier, $this, $this->styleResolver, new FlowContainerElementContext(
            runtimeAppShellBlock: fn (DOMElement $element, array &$fallbacks): ?array => $this->runtimeIslands->recordRuntimeAppShell($element) ? $this->htmlPreservationBlock($element) : null,
            tree: $this,
            authorLayoutOwnership: $this->authorLayoutOwnership,
            runtimeDomTargets: $this->runtimeIslands,
            sourceElementClassifier: $this->sourceElementClassifier,
            paragraphBlockFromInlineContentWrapper: $inlineTextWrappers->paragraphBlockFromInlineContentWrapper(...),
            inlineAddressableRunGroupBlock: $inlineTextWrappers->inlineAddressableRunGroupBlock(...),
            proofBackedWrapperCoalescing: $this->wrapperCoalescer->proofBackedWrapperCoalescing(...),
            isInertEmptyElement: $this->emptyVisualRetention->isInertOutOfFlowEmptyElement(...),
            patternContext: $this->patternContext,
            visualTextWrapperBlock: $inlineTextWrappers->visualTextWrapperBlockFromElement(...),
            coalescedSingleGroupWrapper: $this->wrapperCoalescer->coalescedSingleGroupWrapper(...),
            shouldPreserveWrapper: $this->emptyVisualRetention->shouldPreserveWrapper(...)
        ),
            $emptyFeatureShells,
            $formDispatcher,
            $flankedSeparators,
            $this->imageLowering,
            $this->authorLayout,
            $this->generatedComponents,
            $this->emptyVisualRetention,
            $this->session,
            $this->runtimeIslands,
            $metadataGrid,
            $mediaGalleries,
            $namePriceRows,
            $inlineFlows,
            $searchBlockConverter,
            $readableFormControlBlockConverter,
            $this->authoredMarqueeRecognizer,
            $authoredCarouselGenerator,
            $emptyVisualSpacers
        );
        $mediaDispatchConverter = new MediaDispatchElementConverter(new MediaDispatchElementContext(
            $this,
            fn (DOMElement $element): ?array => $this->imageLowering->convertImage($element),
            $this->imageLowering->convertPicture(...),
            $visualIframeGenerator->convert(...),
            $mediaLowering->fromElement(...),
            $this->anchors->imageBlockFromAnchor(...)
        ));
        $this->unsupportedRecorder = new UnsupportedElementRecorder($this->session, $this->createUnsupportedElementContext(), $formControlMetadataBuilder);
        $capturedDialogConverter = new CapturedDialogConverter(
            $this->session,
            $this,
            $this->styleResolver
        );
        $this->elementPrelude = new ElementConversionPrelude(
            new NativeGetFormControlConverter(
                $nativeGetFormBlockBuilder,
                $this->authoredFormControlBlockConverter,
                $formControlMetadataBuilder,
                $this->styleResolver,
                $this
            ),
            new InertScaffoldingSuppressor(
                $this->styleResolver,
                $this->runtimeIslands,
                $this->sourceElementClassifier,
                $this->emptyVisualRetention->soleElementChild(...),
                $this->emptyVisualRetention->shouldPreserveEmptyVisualElement(...)
            ),
            $this->projectedNavigation,
            new PhrasingSvgConverter($this->svgMaterializer, $this, $inertElements),
             $capturedDialogConverter,
             new CapturedChoiceGroupConverter(
                 $this->session,
                 $this,
                 $this->styleResolver,
             ),
             new CapturedListboxConverter($this->authoredFormControlBlockConverter),
             new CapturedSelectableSetConverter(
                $this,
                $this->styleResolver,
                $this,
                $this->session
            ),
            new ScrollStateConverter(
                $this,
                $this->styleResolver,
                fn (DOMElement $element, string $className): string => $this->sourceBlockAttributeProjector->sourceProjectionClassName($element, $this->sourceBlockAttributeProjectionContext(), $className),
                fn (DOMElement $element, array $attrs, array $withheld): array => $this->projectBoxTreeCarrier('core/group', $attrs, $element, $withheld, false)
            ),
            new ThemeToggleConverter(
                $this->svgMaterializer,
                $this->session,
                $this->styleResolver
            ),
            new CopyToClipboardConverter($this->session),
            $formDispatcher,
            $searchBlockConverter,
            $this->runtimeIslands,
            $this->styleResolver,
            $this,
            $this->richTextMaterializer,
            $this->runtime,
            $this->sourceElementClassifier,
            $this->session->transformationProvenanceState(),
            $this,
            $linkCarriers->linkedRuntimeControlBlockFromAnchor(...),
            $this->authorLayoutOwnership,
            $this->wrapperCoalescer->proofBackedWrapperCoalescing(...),
            $this->wrapperCoalescer->layoutGeometryProofFor(...),
            $this->session,
            capturedCollection: new CapturedCollectionConverter(
                $this->session,
                $this
            )
        );
        $this->elementConverters = new OrderedElementConverterRegistry(array(
            new Elements\PostNavigationLinkConverter($this->styleResolver, $this),
            new AuthoredMotionMarkerConverter($this->session, $this),
            $this->elementPrelude,
            $inlineCheckableControls,
            $richTextConverter,
            new Elements\TagScopedElementConverter(array( 'address' ), $textLeafConverter),
            new Elements\ShellLandmarkConverter($this->session, $this->styleResolver, $this, $this),
            $searchBlockConverter,
            $customImageHosts,
            $customVideoHosts,
            new Elements\ImagelessPictureConverter($this->sourceElementClassifier, $flowContainerConverter),
            $mediaDispatchConverter,
            new Elements\FocusRevealedSkipLinkConverter($this->styleResolver, $this->session),
            new Elements\ReducedModeButtonConverter($this->session, $this->styleResolver, $this->sourceElementClassifier, $this->runtime, $this),
            new Elements\AnchorElementConverter($buttonLinkDispatcher, $emptyVisualSpacers, $this->emptyVisualRetention, $this->unsupportedRecorder),
            $structuralContentConverters,
            new Elements\LabelElementConverter($readableFormControlBlockConverter, $flowContainerConverter),
            // These delegates own disjoint tags; their declaration order is the
            // pre-existing precedence before form, navigation, and flow dispatch.
            $postStructuralElementConverters,
            new Elements\NavigationElementConverter($this, $this->session, $this->sourceElementClassifier, $this->authorLayoutOwnership, $this->richTextMaterializer, $this->runtime, $this->sourceBlockAttributeProjector, $this->styleResolver, $this),
            $flowContainerConverter,
            new Elements\AuthoredCarouselElementConverter($authoredCarouselGenerator, $this->runtimeIslands, $this->styleResolver, $this, $this),
            new Elements\NavigationHostConverter($this, $this->layoutShells, $this),

            $this->generatedComponents,
            new Elements\StandaloneFormControlConverter($readableFormControlBlockConverter, $this->formRuntimeIslandRecorder),
            $linkCarriers,
            new Elements\RenderNeutralFlowWrapperConverter($this->renderNeutral, $flowContainerConverter),
            $customBlockGenerator,
            $inertElements,
            new Elements\OrphanListItemConverter($this->runtimeIslands, $this->sourceElementClassifier, $this->richTextMaterializer, $this, $this->layoutShells, $this),
            new Elements\ScriptOwnedCheckoutRegionConverter($commercePattern, $this),
            new Elements\TransparentUnknownElementConverter($this->renderNeutral, $this->session, $this->generatedComponents, $flowContainerConverter, $this, $this->blockFactory),
            new Elements\CustomElementRuntimeConverter($this->session, $this->styleResolver, $this->sourceElementDescription, $this->runtimeIslands, $this->renderNeutral, $this->generatedComponents, $this),
        ));
    }

    /** Collaborator surface for {@see StyleResolver}. */
    private function createStyleResolutionContext(): StyleResolutionContext
    {
        return new StyleResolutionContext(
            fn (string $selector): array => $this->session->sourceStyleResolutionState()->parsedSelector($selector),
            fn (string $className): string => $this->styleResolver->promotedClassName($className),
            fn (string $url): string => $this->imageUrls->resolvedAssetUrl($url)
        );
    }

    /** Collaborator surface for {@see NavigationToggleSuppressor}. */
    private function createNavigationToggleSuppressionContext(): NavigationToggleSuppressionContext
    {
        return new NavigationToggleSuppressionContext(
            $this->sourceVisibility,
            PatternRecognizerRegistry::createDefault(new ProbeBlockCreator(), $this->runtime, $this->sourceElementClassifier, $this->richTextMaterializer),
            $this->patternProbeContext
        );
    }

    /** Collaborator surface for {@see SearchBlockConverter}. */
    private function createSearchBlockConversionContext(): SearchBlockConversionContext
    {
        return new SearchBlockConversionContext(
            $this->styleResolver,
        );
    }

    /** Collaborator surface for {@see NavigationStyleProjector}. */
    private function createNavigationStyleProjectionContext(): NavigationStyleProjectionContext
    {
        return new NavigationStyleProjectionContext(
            fn (string $selector): array => $this->session->sourceStyleResolutionState()->parsedSelector($selector),
            $this->stylesheetAssetStage->generated(...)
        );
    }

    /**
     * Collaborator surface for {@see ButtonLinkDispatcher}.
     */
    private function createButtonLinkDispatchContext(): ButtonLinkDispatchContext
    {
        return new ButtonLinkDispatchContext(
            $this->sourceElementClassifier,
            $this->styleResolver,
            $this,
            $this,
            $this->patternContext,
            $this->runtimeIslands,
            $this->formRuntimeIslandRecorder,
            $this->anchors,
            $this->runtime,
            $this->runtimeAuthoredButton(...)
        );
    }

    /**
     * Collaborator surface for {@see TableElementConverter}.
     */
    private function createTableElementContext(): TableElementContext
    {
        return new TableElementContext(
            $this->tableClassificationPolicy,
            $this->layoutTables->nestedLayoutTableColumnsBlock(...),
            $this->layoutTables->singleColumnLayoutTableGroupBlock(...),
            $this->layoutTables->mediaLayoutTableColumnsBlock(...),
            $this->tableAttributesBuilder->tableAttributes(...)
        );
    }

    /**
     * Collaborator surface for {@see UnsupportedElementRecorder}.
     */
    private function createUnsupportedElementContext(): UnsupportedElementContext
    {
        return new UnsupportedElementContext(
            $this->session,
            $this->generatedComponents->generatedBlock(...),
            $this->sourceElementDescription,
                    );
    }

    /**
     * Collaborator surface for {@see RichTextElementConverter}.
     */
    private function createRichTextElementContext(): RichTextElementContext
    {
        return new RichTextElementContext(
            $this->authoredMarqueeRecognizer->authoredMarqueeBlock(...),
            $this->emptyVisualRetention->hasEmptyVisualInlineChild(...),
            $this->authorLayoutOwnership->hasBoxChromeWrapperStyling(...),
            $this->runtimeIslands,
            fn (DOMElement $element): ?array => $this->paragraphImages->unwrappedParagraphImageBlock($element),
            $this->anchors->mixedMediaLinkGroupFromParagraph(...),
            $this->linkedIconTextRows->fromParagraph(...),
            $this->text(...),
            $this,
            $this->emptyInlineGeometry->emptyInlineGeometryBlock(...),
            function (DOMElement $element, array &$fallbacks): ?array {
                $block = $this->patterns($element, $fallbacks, array( DetailsPattern::class ));
                if ( null !== $block ) {
                    $this->session->rememberNativeDisclosure($element);
                }
                return $block;
            },
            $this->svgMaterializer->decorativeTextSvgBlock(...),
            function (DOMElement $element, array &$fallbacks): ?array {
                return $this->textlessMedia->mediaBlockFromTextlessElement($element, $fallbacks);
            },
            $this->emptyVisualRetention->shouldPreserveEmptyVisualElement(...)
        );
    }

    private ?\Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CodePresentation $codePresentation = null;

    /**
     * Collaborator surface for {@see TextLeafElementConverter}. Enumerating the
     * operations here is the point: the converter cannot reach transformer
     * state that is not listed.
     */
    private function createTextLeafElementContext(): TextLeafElementContext
    {
        return new TextLeafElementContext(
            $this->codePresentation ??= new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CodePresentation($this->styleResolver),
            $this
        );
    }

    /** @var array<string, string> Source box modifiers retained across island reduction. */
    private array $reducedButtonSourceBoxes = array();

    private function sourceBlockAttributeProjectionContext(): SourceBlockAttributeProjectionContext
    {
        return new SourceBlockAttributeProjectionContext(
            $this->session->authorStyleAnalysis(),
            $this->session->authorSelectorProjectionState(),
            $this->session->generatedSupportStylesheetState(),
            $this->reducedButtonSourceBoxes
        );
    }

    /** Backs {@see DomHelpersTrait::sourceTagProjectedClone()} with the session's author-selector tag markers. */
    protected function fallbackSourceTagMarker(string $tagName): string
    {
        return $this->session->authorSelectorProjectionState()->tagMarker($tagName);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function transform(string $html, array $options = array()): TransformerResult
    {
        [$html, $options] = self::withFlatNestedStyleRules($html, $options);
        return $this->transformTimed($html, $options, new \Automattic\BlocksEngine\PhpTransformer\Support\StepTimer('transform.source_preparation'));
    }

    /**
     * Selector analysis and projection read flat style rules only. Desugar
     * native CSS nesting in every author stylesheet input first, so nested
     * rules are matched against the source like their flat equivalents.
     *
     * @param array<string, mixed> $options
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function withFlatNestedStyleRules(string $html, array $options): array
    {
        $styles = StyleTagScanner::scan($html);
        foreach ( array_reverse($styles) as $style ) {
            $content = $style['content'];
            $flat = CssNestingFlattener::flatten($content);
            if ( $flat === $content ) {
                continue;
            }
            $contentEnd = strrpos(substr($html, 0, $style['end_offset']), '</');
            if ( false === $contentEnd || substr($html, $contentEnd - strlen($content), strlen($content)) !== $content ) {
                continue;
            }
            $html = substr_replace($html, $flat, $contentEnd - strlen($content), strlen($content));
        }
        if ( is_string($options['static_css'] ?? null) ) {
            $options['static_css'] = CssNestingFlattener::flatten($options['static_css']);
        }
        foreach ( array( 'stylesheet_payloads', 'author_stylesheet_assets' ) as $key ) {
            if ( ! is_array($options[$key] ?? null) ) {
                continue;
            }
            foreach ( $options[$key] as $index => $asset ) {
                foreach ( array( 'content', 'cascade_content' ) as $field ) {
                    if ( is_array($asset) && is_string($asset[$field] ?? null) ) {
                        $options[$key][$index][$field] = CssNestingFlattener::flatten($asset[$field]);
                    }
                }
            }
        }
        return array( $html, $options );
    }

    /** @param array<string, mixed> $options */
    private function transformTimed(string $html, array $options, \Automattic\BlocksEngine\PhpTransformer\Support\StepTimer $timer): TransformerResult
    {
        $timer->start('transform.source_preparation');
        $this->blockMaterializer->configureDeviceVisibility((string) ($options['declarative_state_html'] ?? $html));
        $validationEvidence = \Automattic\BlocksEngine\PhpTransformer\Contract\ValidationEvidencePolicy::fromOptions($options);
        $context = TransformationOptions::context($options);
        $startedAt = hrtime(true);
        $this->session->installTransformOptions($options);
        $this->foldedInto = $this->foldFluid = $this->foldsCarried = $this->boxTreeCarriers = array();
        $this->neutralBoxChildren = $this->neutralBoxChildren($html, $options);
        $this->documentAuthorCss = '';
        $sharedStylesheetPaths = TransformOptions::sharedStylesheetPaths($options);
        $staticCss = (string) ($options['static_css'] ?? '');
        $styleAnalysis = $this->stylesheetAnalysisComposer->composedStyleAnalysis(
            $this->stylesheetAnalysisComposer->stylesheetPayloads($html, $staticCss, $options)
        );
        $this->session->sourceStyleResolutionState()->installStylesheetAnalysis(StaticClassPromotions::detect($html), $styleAnalysis, $this->styleResolver->hiddenStateStyleRules(...));
        $provenance               = array(
            array_merge(array(
                'source_format' => 'html',
                'input_bytes'   => strlen($html),
                'transformer'   => HtmlTransformer::class,
            ), $this->session->transformationProvenanceState()->fallback()),
        );

        $timer->start('transform.source_parse');
        $sourceBodyClasses = $this->documentEnvelope->documentBodyClassNames($html);
        $this->session->installSourceDocument($this->documentEnvelope->documentRootTheme($html), NativeGetFormBlockBuilder::declaredDocumentHosts($html));
        $normalizedHtml = CaptureReplayScripts::withoutReplayScripts(
            SourceDom::normalizeHtml5VoidElements($this->documentEnvelope->documentBodyHtml($this->documentEnvelope->normalizeExplicitPlaintextElements($html), array() !== $this->session->boxTreeViewports()))
        );
        $document = HtmlSerialization::parseFragment('<body>' . $normalizedHtml . '</body>');
        CommerceStructureRecognizer::registerSourceDocument($document, (string) ($options['declarative_state_html'] ?? $html));
        if ( Support\SourceDocumentEnvelope::isDocument($html) ) {
            Classification\FormControlClassifier::registerWholeDocument($document);
        }

        $resultComposer = new HtmlResultComposer();
        $selectorCache = $this->session->sourceStyleResolutionState()->selectorMatchCache;

        $body = $document->getElementsByTagName('body')->item(0);
        if ( ! $body instanceof DOMElement ) {
            return $timer->result($resultComposer->emptyBody($html, $provenance, $context, $startedAt, $selectorCache));
        }
        $timer->start('transform.source_normalization');
        SourceDom::removeIntegerKeyAttributes($body);
        SourceDom::removeNonRenderingSvgAttributes($body);
        SourceDom::unwrapLineBreakOnlyLinks($body);
        SourceDom::normalizeHashbangPlaceholders($body);
        // Resolve before dispatch: controls, rich text, and preserved markup
        // serialize source images without passing through native image lowering.
        $this->imageUrls->resolveSourceImages($body);
        \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SvgDefinitionClips::rewriteInlineStyles($body, $this->styleResolver);
        CapturedNavigationPanels::restoreNative($body);
        CapturedNavigationPanels::restoreRestingStates($body);
        // Capture evidence, not content: read before anything serializes the source.
        $this->session->installCapturedGlyphColors(CapturedGlyphColor::detach($body));
        $this->session->reusableComponentState()->liftRestingNavigation($body);
        if ( QuirksModeSource::isQuirksMode($html) ) {
            QuirksModeSource::normalize(
                $body,
                fn (DOMElement $element, array $properties): bool => array() !== $this->styleResolver->authorDeclaredPropertyValues($element, $properties)
            );
        }

        if ( array() !== $sourceBodyClasses ) {
            $body->setAttribute('class', implode(' ', $sourceBodyClasses));
        }

        // Body-only parsing must retain the html ancestor that owns inherited
        // custom properties and participates in authored root selectors.
        \Automattic\BlocksEngine\PhpTransformer\Support\DocumentRootAttributes::restoreRoot($body, $html);
        if ( array() !== $this->session->boxTreeViewports() ) {
            \Automattic\BlocksEngine\PhpTransformer\Support\DocumentRootAttributes::restoreEvidenceIdentity($body, $html);
        }

        // Document metadata (`<link>`, `<meta>`, `<base>`, `<title>`) generates no
        // rendered box and belongs in `<head>`, but browsers tolerate it anywhere
        // in `<body>` and static captures occasionally leak it into content
        // (templating artifacts, crawler quirks). Left in place it carries no
        // visual or textual content of its own, yet its raw tag still matches the
        // generic element gates every other converter relies on, so an otherwise
        // convertible parent falls back to a core/html block. Pruning it before
        // any element dispatch begins keeps the parent eligible for native
        // conversion and keeps every downstream pass — style matching, selector
        // projection, rich-text materialization — from ever observing it.
        NonRenderedMetadata::prune($body);
        // Prune before selector/layout analysis so neither frames nor emptied
        // checkout wrappers can contribute a block or captured box geometry.
        $paymentWidgetFindings = \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\PaymentProcessorWidgetPruner::prune($body);
        // Saved block markup re-enters as source; invert save shapes that moved
        // source identity onto synthesized wrappers before anything reads them.
        OwnSaveShapeInverter::invert($body, fn (DOMElement $element): bool => array() !== $this->session->sourceStyleResolutionState()->matchingRules($element, 'static-conditional'));
        $this->navigationBlockNormalizer->hydrateDuplicateSubmenus($body);
        DeclarativeCounterMaterializer::materialize($body, (string) ($options['declarative_state_html'] ?? ''));
        // Remove wrapper-convention custom elements before author selectors are
        // prepared, so style projection and component promotion both observe the
        // content rather than the source's wrapping convention.
        $this->documentAuthorCss = $this->stylesheetAnalysisComposer->combinedAuthorStylesheet($html, $staticCss);
        $this->retainedCustomWrapperHooks = $this->wrapperConventions->unwrapConventionWrappers($body, $this->documentAuthorCss);
        // Evidence-proven image frames merge into their image before any
        // selector projection or conversion observes the frame as a box.
        if ( array() !== $this->session->boxTreeViewports() ) {
            ( new Elements\FilledImageFrameLowering(
                fn (DOMElement $element): array => $this->session->boxTreeEvidence($element),
                fn (DOMElement $element): array => array_map(
                    static fn (array $rule): string => (string) ( $rule['selector'] ?? '' ),
                    $this->session->sourceStyleResolutionState()->matchingRules($element, 'static-conditional')
                )
            ) )->lower($body);
            // A layer's empty colour fill becomes the layer's own paint.
            $this->session->overrideBoxTreeObservations(( new Elements\FilledPaintLayerLowering(
                fn (DOMElement $element): array => $this->session->boxTreeEvidence($element),
                fn (DOMElement $element): array => array_map(
                    static fn (array $rule): string => (string) ( $rule['selector'] ?? '' ),
                    $this->session->sourceStyleResolutionState()->matchingRules($element, 'static-conditional')
                )
            ) )->lower($body));
            $this->session->sourceStyleResolutionState()->invalidateSelectorMatches();
        }
        $timer->start('transform.author_selector_preparation');
        $this->authorSelectorSemanticPreparer->prepare($html, $staticCss, $body, $options, $this->session);
        $this->session->fallbackEmitter()->configure($this->session->transformationProvenanceState()->fallback(), $this->session->runtimeBehaviorState()->runtimeScriptMetadata(), $this->session->runtimeSelectorState(), $this->session->authorSelectorProjectionState()->tagMarkers());
        // Author-selector preparation marks source nodes for later projection.
        // General style matching begins only after those source mutations settle.
        $this->session->sourceStyleResolutionState()->invalidateSelectorMatches();
        $this->styleResolver->dropAuthorOverriddenInlineDeclarations($body);
        $this->session->sourceStyleResolutionState()->invalidateSelectorMatches();
        $this->styleResolver->collectEditorHiddenStateFindings($body);
        $this->session->reusableComponentState()->installRecognition($this->reusableComponentRecognizer->recognize($body));
        $chromeJournal = $options['chrome_journal'] ?? null;
        $this->chromeJournal = is_array($chromeJournal) && ($chromeJournal['store'] ?? null) instanceof ChromeJournalStore
            ? new ChromeJournal($this->session, $chromeJournal['store'], $body, ArrayField::array($chromeJournal, 'selectors'), $options)
            : null;

        $timer->start('transform.conversion');
        $fallbacks = array_map(
            fn (array $finding): array => FallbackDiagnostic::build($finding, $this->session->transformationProvenanceState()->fallback()),
            $paymentWidgetFindings
        );
        \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\DocumentReferenceIndex::install($document, $this->documentAuthorCss);
        \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\InteractiveSurface::indexDocument($document);
        try {
            FormRuntimeRequirementAnalyzer::indexSourceBehavior($document, (string) ($options['declarative_state_html'] ?? ''));
            // Sub-steps name the conversion work that precedes the body walk.
            $interactionCandidates = $timer->measure('transform.conversion.source_relationships', function () use ($body): array {
                $interactionCandidates = $this->interactionFallbackReporter->interactionCandidates($body);
                $this->navigationToggleSuppressor->collectProjectedNavigationRelationships($body);
                $this->navigationToggleSuppressor->collectSupersededNavToggleSelectors($body);
                $this->navigationToggleSuppressor->collectSupersededCapturedSubmenuSelectors($body);
                $this->generatedComponentCandidates->collectGeneratedComponentCandidates($body);
                return $interactionCandidates;
            });
            $shellArtifacts = !array_key_exists('extract_global_shell', $options) || !empty($options['extract_global_shell'])
                ? $timer->measure('transform.conversion.global_shell', fn (): array => $this->globalShellArtifacts->globalShellArtifacts($body, (string) ($options['source'] ?? 'html'), false, max(0, (int) ($options['global_shell_context_depth'] ?? 0))))
                : array();
            $blocks = $this->navigationBlockNormalizer->normalize($this->convertChildren($body, $fallbacks, true), $this->session->transformationProvenanceState()->sources(), $this->session->transformationProvenanceState()->sourceBaseHiddenStates(), $this->session->transformationProvenanceState()->sourceCollapsedWrapperHiddenStates());
        } finally {
            FormRuntimeRequirementAnalyzer::releaseSourceBehavior($document);
            \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\InteractiveSurface::releaseDocument($document);
            \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\DocumentReferenceIndex::release($document);
        }
        $blocks = $this->wrapperCoalescer->compressProjectedGroupChains($blocks);
        $timer->start('transform.fallback_reduction');
        $fallbacks = array_merge($fallbacks, $this->session->transformationEvidenceState()->responsiveImageFallbacks());
        $this->fallbackReconciliation->reconcileNativeListItemFallbacks($fallbacks, $blocks);
        $this->fallbackReconciliation->reconcileFormControlGroupFallbacks($body, $fallbacks);
        if (! $this->session->usesFallbackReductionMode()) {
            $blocks = $this->reduceCoreHtmlFallbackBlocks($blocks, $body);
        }
        $this->runtimeIslands->recordRuntimeIslandsForPreservedHtmlBlocks($blocks);
        $this->interactionFallbackReporter->appendInteractiveControlBehaviorLossFallbacks($body, $fallbacks);
        $this->commerceReporter->appendProductGridFallbacks($body, $fallbacks, $blocks, $options['source_has_purchase_affordance'] ?? null);
        $this->commerceReporter->appendCommerceControlsFallbacks($body, $fallbacks, (string) ($options['declarative_state_html'] ?? $html));
        $this->commerceReporter->appendCartViewFallbacks($body, $fallbacks);
        $this->commerceReporter->appendCheckoutViewFallbacks($body, $fallbacks);
        // Inserted into a theme the engine does not own, core's flow layout
        // spaces group children with the host's block gap; encode the
        // source spacing on the blocks instead.
        if ( is_string($options['host_block_gap'] ?? null) && '' !== trim($options['host_block_gap']) ) {
            $blocks = (new HostBlockGapEncoder(
                $this->styleResolver,
                $this->blockFactory,
                $this->session,
                $this->styleResolver->documentRootFontSize($body) ?? 16.0
            ))->encode($blocks);
        }
        // A fallback's readable tree is the content a provider puts on the page
        // in place of the preserved island, so its blocks need the same
        // generated stylesheet as the page's own blocks. Serialized first:
        // serializing resets the runtime diagnostics of the page serialization.
        $timer->start('transform.serialization');
        $readableMarkup = '';
        foreach ($fallbacks as $fallback) {
            if ( is_array($fallback['readable_blocks'] ?? null) && array() !== $fallback['readable_blocks'] ) {
                $readableMarkup .= $this->runtime->serializeBlocks(DefaultCommentAttributes::strip($fallback['readable_blocks']));
            }
        }
        $this->fallbackBindings->anchorPurchaseControlRuns($blocks, $fallbacks);
        $serializedBlocks = $this->runtime->serializeBlocks(DefaultCommentAttributes::strip($blocks));
        $this->fallbackBindings->finalize($fallbacks, $blocks, $serializedBlocks);
        $cartLinks = $this->commerceReporter->cartOpenerDeclarations($body);
        // Chrome may serialize differently from its page copy. Resolve each
        // opener against the exact captured part, preserving that identity.
        $resolvedCartLinks = array();
        foreach ( $shellArtifacts as &$shellArtifact ) {
            $partLinks = 'artifact-shared-shell' === ($options['source_scope'] ?? '')
                ? array_values(array_filter($cartLinks, static fn (array $link): bool => str_starts_with($link['selector'], $shellArtifact['_binding_source_selector'] . ' > ')))
                : array();
            $this->fallbackBindings->finalize($partLinks, $shellArtifact['_binding_blocks'], $shellArtifact['block_markup']);
            foreach ( $partLinks as $link ) {
                if ( empty($link['binding']) ) continue;
                $link['binding']['source_path'] = $shellArtifact['source_path'];
                $link['_binding_owner_markup'] = $shellArtifact['block_markup'];
                $resolvedCartLinks[] = $link;
                $cartLinks = array_values(array_filter($cartLinks, static fn (array $candidate): bool => $candidate['selector'] !== $link['selector']));
            }
            unset($shellArtifact['_binding_blocks'], $shellArtifact['_binding_source_selector']);
        }
        unset($shellArtifact);
        $this->fallbackBindings->finalize($cartLinks, $blocks, $serializedBlocks);
        $resolvedCartLinks = array_merge($resolvedCartLinks, array_values(array_filter($cartLinks, static fn (array $link): bool => ! empty($link['binding']))));
        $reusableComponentRecognition = $this->session->reusableComponentState()->report($this->session->assetMaterializationState()->assets());
        // Resolve against emitted blocks while the existing source provenance
        // IDs are still attached. Declaration-addressed classes are rewritten below.
        $authorLayoutTopologyFindings = $this->session->transformationEvidenceState()->authorLayoutTopologyFindings($blocks);
        // Capture ownership before resolveBlockPaths consumes the internal IDs.
        // Shared-shell compilation can materialize a source control more than
        // once; the collector observes each DOM identity only once.
        $buttonElements = $this->session->transformationProvenanceState()->sourceElementsOfBlockType($blocks, 'core/button');
        $sourceProvenance = $this->session->transformationProvenanceState()->resolveBlockPaths($blocks);
        $responsiveCounterpartContracts = (new ResponsiveCorrespondence())->declare($blocks, $sourceProvenance);
        $this->session->authorSelectorProjectionState()->confirmRichTextCarriers($serializedBlocks);
        $authorStylesheets = new AuthorStylesheetMaterialization(
            $this->session,
            $this->styleResolver,
            $this->authorStylesheetProjector,
            $this->navigationStyleProjector,
            $this->stylesheetAssetStage,
            $serializedBlocks,
            $sharedStylesheetPaths,
            ! empty($options['project_only_matched_author_rules']),
            ! empty($options['native_document_context']),
            $this->session->sourcePath()
        );
        $authorStylesheetProjections = $timer->measure('transform.serialization.author_stylesheet_projections', fn (): array => $authorStylesheets->projections());
        $runtimeScriptProjections = RuntimeScriptProjections::collect($this->session);
        $timer->start('transform.stylesheet_materialization');
        $authorStylesheets->materialize(
            $html,
            $staticCss,
            true !== ($options['skip_author_stylesheet_materialization'] ?? false),
            $serializedBlocks . $readableMarkup,
            $sourceProvenance,
            $authorStylesheetProjections,
            is_string($options['author_layer_order'] ?? null) ? $options['author_layer_order'] : null
        );
        // The editor reaches anchored source boxes through their editor anchor
        // classes; the after-author content-box restatements follow the
        // author rules there as they do on the front end.
        $this->navigationStyleProjector->materializeEditorStaticStateStylesheet(
            implode("\n\n", array_column($authorStylesheetProjections, 'content'))
            . ( '' === $authorStylesheets->contentBoxCss() ? '' : "\n\n" . $authorStylesheets->contentBoxCss() ),
            $serializedBlocks
        );
        // Publish declaration addresses only after every generated support
        // family has contributed. Provisional source bindings never escape
        // this compilation boundary as the identity of a control's styles.
        $timer->start('transform.class_identity_rewrite');
        $classIdentity = new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\DeclarationAddressedClasses();
        $assets = $this->session->assetMaterializationState()->checkpoint();
        // Author projections may already be materialized; count each source
        // sheet once, then the generated sheets in their cascade placements.
        // Isolated fallback fragments transfer structured support records to
        // their owning compilation. Keep provisional keys until that owner
        // has collected its complete rule set and finalizes once.
        $classReplacements = $this->session->usesFallbackReductionMode() ? array() : $classIdentity->replacements(array_merge(
            $authorStylesheetProjections,
            array_values(array_filter($assets, static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '') && (array() === $authorStylesheetProjections || 'author-css' !== ($asset['source'] ?? ''))))
        ));
        $classReplacements += $classIdentity->assetPathReplacements($assets, $classReplacements);
        $blocks = $classIdentity->rewrite($blocks, $classReplacements);
        $fallbacks = $classIdentity->rewriteBindings($fallbacks, $serializedBlocks, $classReplacements);
        foreach ( $resolvedCartLinks as $link ) {
            $ownerMarkup = $link['_binding_owner_markup'] ?? $serializedBlocks;
            unset($link['_binding_owner_markup']);
            $link = $classIdentity->rewriteBindings($link, $ownerMarkup, $classReplacements);
            $this->session->transformationEvidenceState()->recordConversionDiagnostic($link);
        }
        $serializedBlocks = $classIdentity->rewrite($serializedBlocks, $classReplacements);
        $sourceProvenance = $classIdentity->rewrite($sourceProvenance, $classReplacements);
        $shellArtifacts = $classIdentity->rewrite($shellArtifacts, $classReplacements);
        $authorStylesheetProjections = $classIdentity->rewrite($authorStylesheetProjections, $classReplacements);
        $runtimeScriptProjections = $classIdentity->rewrite($runtimeScriptProjections, $classReplacements);
        $responsiveCounterpartContracts = $classIdentity->rewrite($responsiveCounterpartContracts, $classReplacements);
        $reusableComponentRecognition = $classIdentity->rewrite($reusableComponentRecognition, $classReplacements);
        $projectedSelectorBindings = $classIdentity->rewrite($authorStylesheets->projectedSelectorBindings(), $classReplacements);
        $this->session->assetMaterializationState()->restore($classIdentity->rewrite($assets, $classReplacements));
        $validation = $this->validation->validate($timer, $body, $blocks, $serializedBlocks, $sourceProvenance, $shellArtifacts, $fallbacks, $html, $staticCss, $validationEvidence, $projectedSelectorBindings, $this->session->sourcePath());
        $validationOutcome = $validation->outcome;
        $diagnostics = $validation->diagnostics;
        foreach ( $this->session->transformationEvidenceState()->glyphColorUndecodableFindings() as $finding ) {
            $diagnostics[] = ConversionFindingContract::withClassification($finding);
        }
        $headMetadata = HeadMetadataReport::fromHtml($html);
        $authorLayoutTopologyFindings = $classIdentity->rewrite($authorLayoutTopologyFindings, $classReplacements);
        $this->session->sourceStyleResolutionState()->recordMatchWork($this->analysisCache);
        $nativeTargetBlocks = $this->runtime->availableCoreBlockNames();
        $bundledSnapshotBlocks = $this->runtime->bundledCoreBlockNames();
        $runtimeRegisteredBlocks = $this->runtime->runtimeRegisteredCoreBlockNames();
        $capabilityMatrix = (new CoreBlockCapabilityMatrix($this->runtime))->coverage($nativeTargetBlocks, $runtimeRegisteredBlocks);
        $supportedBlocks = $capabilityMatrix['supported_blocks'];
        $ownershipPaths = BlockCompilationOutput::editabilityOwnershipPaths($sourceProvenance);
        $generatedCarrierCss = $this->session->assetMaterializationState()->engineSupportCss();
        // Selector projection may install engine marker classes on the analysis
        // document root (root-owned attribute states). Only the real root
        // registry can materialize them, so they travel with the compilation
        // facts instead of dying with the analysis DOM.
        $documentRootMarkers = EngineMarker::onDocumentRoot($document);
        // Design usage is observation after conversion. Cache metrics report
        // conversion work, so they are read before the collector reuses the
        // resolver's caches.
        $conversionSelectorCache = clone $selectorCache;
        $timer->start('transform.validation.design_usage');
        $designUsageCollector = new DesignUsageCollector($this->styleResolver);
        $designUsage = $designUsageCollector->collect($body, $buttonElements);
        foreach ( $designUsageCollector->schemeColorFindings() as $finding ) {
            $diagnostics[] = ConversionFindingContract::withClassification($finding + array('source' => HtmlTransformer::class));
        }
        $timer->start('transform.validation.editability');
        $editabilityReport = (new EditabilityReport())->fromBlocks($blocks, (string) ($options['source'] ?? ''), $serializedBlocks, $generatedCarrierCss, $ownershipPaths['runtime'], $ownershipPaths['visual'], $sourceProvenance, $this->session->requireGeneratedBlockRegistry()->definitions());
        $timer->start('transform.result_composition');
        $blockCompilationOutput = new BlockCompilationOutput(
            sourceProvenance: $sourceProvenance,
            editabilityReport: $editabilityReport,
            responsiveCounterpartContracts: $responsiveCounterpartContracts,
            layoutGeometryProof: $this->session->layoutGeometryState()->proofProvenance(),
            reusableComponents: $reusableComponentRecognition,
            runtimeIslands: $this->session->runtimeDomState()->islands(),
            generatedBlocks: $this->session->requireGeneratedBlockRegistry()->definitions(),
            gutenbergGaps: GutenbergGaps::collect($this->session, $blocks),
            interactionCandidates: $interactionCandidates,
            supersededSelectors: $this->session->runtimeSelectorState()->supersededSelectors(),
            authorStylesheetProjections: $authorStylesheetProjections,
            runtimeScriptProjections: $runtimeScriptProjections,
            shellArtifacts: $shellArtifacts,
            coreHtmlFallbackEvidence: CoreHtmlFallbackEvidence::fromBlocks($blocks, $fallbacks, $sourceProvenance),
            validationOutcome: $validationOutcome,
            documentRootMarkers: $documentRootMarkers,
            designUsage: $designUsage
        );

        return $timer->result($resultComposer->result(array(
            'source' => HtmlTransformer::class,
            'html' => $html,
            'blocks' => $blocks,
            'serialized_blocks' => $serializedBlocks,
            'assets' => $this->session->assetMaterializationState()->assets(),
            'fallbacks' => $fallbacks,
            'provenance' => $provenance,
            'context' => $context,
            'started_at' => $startedAt,
            'selector_cache' => $conversionSelectorCache,
            'diagnostics' => array_merge($diagnostics, $this->session->transformationEvidenceState()->conversionDiagnostics()),
            'supported_blocks' => $supportedBlocks,
            'native_target_blocks' => $nativeTargetBlocks,
            'bundled_snapshot_blocks' => $bundledSnapshotBlocks,
            'runtime_registered_blocks' => $runtimeRegisteredBlocks,
            'capability_matrix' => $capabilityMatrix,
            'head_metadata' => $headMetadata,
            'runtime_dom_contracts' => $this->session->runtimeDomState()->preservations(),
            'runtime_dom_fallbacks' => $this->session->runtimeDomState()->fallbacks(),
            'block_validity_report' => $validation->blockValidityReport,
            'semantic_parity_report' => $validation->semanticParityReport,
            'content_round_trip_report' => $validation->contentRoundTripReport,
            'source_media_retention_report' => $validation->sourceMediaRetentionReport,
            'dead_projected_selectors_report' => $validation->deadProjectedSelectorsReport,
            'presentation_signals' => $this->session->transformationProvenanceState()->presentationSignals(),
            'frozen_hidden_state' => $this->session->transformationEvidenceState()->frozenHiddenStateFindings(),
            'dropped_link_wrappers' => $this->session->transformationEvidenceState()->droppedLinkWrapperFindings(),
            'gutenberg_incompatibilities' => $this->session->transformationEvidenceState()->gutenbergIncompatibilities(),
            'author_layout_topology_findings' => $authorLayoutTopologyFindings,
            'structure_signals' => $this->session->transformationProvenanceState()->structureSignals(),
            'script_metadata' => $this->session->runtimeBehaviorState()->scriptMetadata(),
            'source_target_projections' => $this->session->sourceTargetProjectionState()->correspondences(),
            'responsive_geometry_ambiguities' => $this->session->transformationEvidenceState()->responsiveGeometryAmbiguities(),
            'responsive_height_ambiguities' => $this->session->transformationEvidenceState()->responsiveHeightAmbiguities(),
            'grid_placement_carrier_findings' => $this->session->transformationEvidenceState()->gridPlacementCarrierFindings(),
            'unscoped_core_selector_drops' => $this->session->transformationEvidenceState()->unscopedCoreSelectorDrops(),
            'has_description_list_block' => $this->session->requireGeneratedBlockRegistry()->has(DescriptionListBlockGenerator::class),
        ), $blockCompilationOutput));
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    public function reduceCoreHtmlFallbackBlocks(array $blocks, ?DOMElement $sourceRoot = null): array
    {
        return (new Elements\CoreHtmlIslandReducer(
            $this->runtimeIslands,
            $this->sourceBlockAttributeProjector,
            $this->session,
            function (string $html, array $sourceBoxes): array {
                $fragment = new self($this->runtime, $this->analysisCache);
                $fragment->reducedButtonSourceBoxes = $sourceBoxes;

                return array(
                    $fragment->transform($html, array( 'extract_global_shell' => false, 'fallback_reduction_mode' => true ))->toArray(),
                    $fragment->session,
                );
            }
        ))->reduce($blocks, $sourceRoot);
    }

    private function convertChildren(DOMNode $parent, array &$fallbacks, bool $captureUnsupported = false, bool $preserveLinkContainers = false): array
    {
        $blocks = array();
        $unsupportedRuntimeMediaOwner = null;
        $ownerFallbackIndex = null;
        // The inline paragraph a following `<br>` ends a line of.
        $lineIndex = null;

        foreach ( $parent->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                $text = trim($child->textContent ?? '');
                if ( '' !== $text ) {
                    $blocks = array_merge($blocks, $this->text($text));
                    $lineIndex = Elements\StandaloneBreakLowering::inlineParagraphIndex($blocks);
                }
                continue;
            }

            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            if ( 'br' === strtolower($child->tagName) && null !== $lineIndex && $this->styleResolver->breakEndsInlineLine($child) ) {
                $blocks[$lineIndex] = Elements\StandaloneBreakLowering::endLine($blocks[$lineIndex]);
                continue;
            }
            $lineIndex = null;
            $blockCount = count($blocks);

            if ( $unsupportedRuntimeMediaOwner instanceof DOMElement
                && 'svg' === strtolower($child->tagName)
                && $this->sourceElementClassifier->isDependentRuntimeMediaMask($child)
                && is_int($ownerFallbackIndex)
            ) {
                $this->recordDependentRuntimeMediaMaskLoss($unsupportedRuntimeMediaOwner, $child, $fallbacks, $ownerFallbackIndex);
                $unsupportedRuntimeMediaOwner = null;
                $ownerFallbackIndex = null;
                continue;
            }

            // Pairing is deliberately bounded to adjacent element siblings.
            $unsupportedRuntimeMediaOwner = null;
            $ownerFallbackIndex = null;
            if ( 'br' === strtolower($child->tagName) ) {
                $blankLines = $this->standaloneBreaks->blocksFromBreak($child);
                if ( null !== $blankLines ) {
                    $blocks = array_merge($blocks, $blankLines);
                    continue;
                }
            }
            // WordPress supplies this document root at render time. Keep the
            // source DOM intact for selector/style analysis, but emit its children
            // as document siblings rather than an authored layout group.
            if ('div' === strtolower($child->tagName)
                && SourceDom::hasClass($child, 'wp-site-blocks')
                && $parent instanceof DOMElement
                && ('body' === strtolower($parent->tagName) || SourceDom::isDocumentVariantRoot($parent))
                && SourceDom::hasWordPressTemplateParts($child)
            ) {
                $blocks = array_merge($blocks, $this->convertChildren($child, $fallbacks, $captureUnsupported, $preserveLinkContainers));
                continue;
            }
            $fallbackOffset = count($fallbacks);
            // Link preservation needs the source box, not a generated block.
            // A plain div has no native attributes requiring shell transport;
            // keep its children editable without introducing Core flow spacing.
            $block = $preserveLinkContainers && 'div' === strtolower($child->tagName) && 0 === $child->attributes->length
                ? $this->createBlock('core/group', $this->authorLayoutOwnership->cssOwnedGroupAttributes($child), $this->convertChildren($child, $fallbacks, $captureUnsupported, true), $child)
                : $this->convertElement($child, $fallbacks, $captureUnsupported);
            if ( null !== $block ) {
                // Retain the public source boundary rather than private metadata.
                if (SourceDom::hasClass($child, 'wp-block-post-content')) {
                    $block['attrs']['className'] = trim(($block['attrs']['className'] ?? '') . ' wp-block-post-content');
                }
                $blocks[] = $block;
                $lineIndex = $blockCount + 1 === count($blocks) ? Elements\StandaloneBreakLowering::inlineParagraphIndex($blocks) : null;
            } elseif ( $this->sourceElementClassifier->isRuntimeMediaSurfaceElement($child) ) {
                $ownerSelector = SourceDom::elementSelector($child);
                for ( $index = $fallbackOffset; $index < count($fallbacks); ++$index ) {
                    if ( $ownerSelector === ($fallbacks[$index]['selector'] ?? null)
                        && in_array((string) ($fallbacks[$index]['diagnostic_code'] ?? ''), array( 'html_iframe_embed_fallback', 'html_unsupported_element', CustomElementRuntimeDependency::UNPROVEN_CODE ), true)
                    ) {
                        $unsupportedRuntimeMediaOwner = $child;
                        $ownerFallbackIndex = $index;
                        break;
                    }
                }
            }
        }

        return $blocks;
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    private function recordDependentRuntimeMediaMaskLoss(DOMElement $owner, DOMElement $mask, array &$fallbacks, int $fallbackIndex): void
    {
        $boundedHtml = SourceDom::boundedFallbackHtml(SourceDom::safeFallbackHtml($mask, $this->session->authorSelectorProjectionState()->tagMarkers()));
        $fallbacks[$fallbackIndex]['dependent_losses'][] = array(
            'relationship' => 'runtime_media_mask',
            'disposition' => 'omitted',
            'reason' => 'owner_surface_unsupported',
            'owner_selector' => SourceDom::elementSelector($owner),
            'selector' => SourceDom::elementSelector($mask),
            'tag' => 'svg',
            'html' => $boundedHtml['html'],
            'html_bytes' => $boundedHtml['bytes'],
            'html_truncated' => $boundedHtml['truncated'],
        );
    }

    /**
     * Wires everything only form dispatch uses: readable-form layout, composition
     * planning, runtime preservation, fallback findings, and login forms.
     */
    private function createFormDispatcher(
        FormControlMetadataBuilder $formControlMetadataBuilder,
        PseudoFormAnalyzer $pseudoFormAnalyzer,
        FormFallbackFindingContext $formFallbackContext,
        ReadableFormControlBlockConverter $readableFormControlBlockConverter,
        NativeGetFormBlockBuilder $nativeGetFormBlockBuilder,
        SearchBlockConverter $searchBlockConverter
    ): FormDispatcher
    {
        $readableFormBlockBuilder = new ReadableFormBlockBuilder(
            $formControlMetadataBuilder,
            $readableFormControlBlockConverter,
            $this->formRuntimeIslandRecorder,
            $this->runtimeIslands,
            fn (DOMElement $element): array => $this->authorLayoutOwnership->cssOwnedGroupAttributes($element),
            $this->authorLayoutOwnership,
            $this->styleResolver,
            $this,
            function (array $elements, array $innerBlocks, DOMElement $sourceElement): array {
                return $this->layoutShells->blockForElements($elements, $innerBlocks, $sourceElement);
            },
            // A field box keeps the frames the author styles: attributes, or a tag the author's selectors address.
            function (array $elements): ?array {
                $styled = array_values(array_filter($elements, fn (DOMElement $element): bool => $element->hasAttributes() || '' !== $this->session->authorSelectorProjectionState()->tagMarker($element->tagName)));
                return array() === $styled ? null : $this->layoutShells->blockForElements($styled, array(), $styled[0], true);
            },
            $this->session,
            $this
        );
        $formCompositionPlanner = new FormCompositionPlanner(
            $this->session,
            $this,
            $this->styleResolver,
            $this
        );
        $formRuntimeRequirementAnalyzer = new FormRuntimeRequirementAnalyzer(
            $this->runtimeIslands
        );
        $formSuccessPanelMetadataBuilder = new FormSuccessPanelMetadataBuilder(
            $this->session,
            $this->styleResolver->innerHtml(...)
        );
        $formFallbackFindingBuilder = new FormFallbackFindingBuilder($this->session,
            $formFallbackContext,
            $formControlMetadataBuilder,
            $formSuccessPanelMetadataBuilder,
            $pseudoFormAnalyzer
        );
        $loginBlockConverter = new LoginBlockConverter($this);
        return new FormDispatcher(new FormDispatchContext(
            $loginBlockConverter->loginBlockFromForm(...),
            $searchBlockConverter->searchBlockFromForm(...),
            $nativeGetFormBlockBuilder->build(...),
            $formCompositionPlanner->compose(...),
            fn (DOMElement $element, ?array $readableFormBlock, ?array $bindingBlock = null): array => $formFallbackFindingBuilder->build($element, $readableFormBlock, $bindingBlock, $readableFormBlockBuilder->layoutGraph()),
            $this->formRuntimeIslandRecorder->recordForm(...),
            $readableFormBlockBuilder->build(...),
            $formRuntimeRequirementAnalyzer->requiresPreservation(...),
            $pseudoFormAnalyzer->isProviderPseudoForm(...)
        ), $this, function (DOMElement $form): ?array {
            $control = $this->createCommercePattern()->cartControlForCardForm($form);
            if ( null === $control ) {
                return null;
            }
            $button = $this->authoredFormControlBlockConverter->button($control, true);
            return null !== $button ? $this->createBlock($button['blockName'], $button['attrs'], array(), $control) : null;
        });
    }

    private function createPatternContext(): PatternContext
    {
        return new PatternContext(
            $this->styleResolver,
            $this,
            new NavigationPatternContext(
                $this->styleResolver,
                $this->session,
                $this->navigationStyleProjector,
                $this->navigationToggleSuppressor,
                $this->runtimeIslands,
                $this->session->sourceTargetProjectionState(),
                $this->svgMaterializer,
                $this->projectedNavigation
            ),
            new MediaPatternContext($this->styleResolver, $this->imageUrls, $this->imageDimensions, $this->session),
            new ColumnsPatternContext($this->styleResolver),
            new MarkupPatternContext(
                $this->session,
                $this->svgMaterializer->withReferencedDefinitions(...)
            ),
            new ButtonPatternContext(
                $this->styleResolver,
                $this->richTextMaterializer,
                $this,
                $this->session,
                $this->runtimeIslands
            ),
            new QuotePatternContext($this->styleResolver),
            new CodeWindowPatternContext($this->codePresentation ??= new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CodePresentation($this->styleResolver)),
            new LogoPatternContext(
                $this->styleResolver,
                function (DOMElement $element, array &$fallbacks): void { $this->session->fallbackEmitter()->captureInlineSvgFallback($element, $fallbacks); }
            ),
            $this->imageLowering,
            $this->sourceVisibility,
            new DisclosureControlPresentation(
                $this->styleResolver,
                $this->session->generatedSupportStylesheetState(),
                $this->svgMaterializer
            ),
            $this->styleResolver,
            $this->session,
            $this->richTextStylingHooks->inlineContent(...)
        );
    }

    /**
     * @param list<array<string, mixed>> $fallbacks
     * @return list<array<string, mixed>>
     */
    public function children(DOMElement $element, array &$fallbacks, bool $captureUnsupported, bool $preserveLinkContainers = false): array
    {
        $localFallbacks = array();
        $blocks = $this->convertChildren($element, $localFallbacks, $captureUnsupported, $preserveLinkContainers);
        $fallbacks = array_merge($fallbacks, $localFallbacks);

        return $blocks;
    }

    /**
     * @param list<array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    public function element(DOMElement $element, array &$fallbacks, bool $captureUnsupported): ?array
    {
        $localFallbacks = array();
        $block = $this->convertElement($element, $localFallbacks, $captureUnsupported);
        $fallbacks = array_merge($fallbacks, $localFallbacks);

        return $block;
    }

    /**
     * @param list<array<string, mixed>> $fallbacks
     * @param list<string> $excludedTags
     * @return list<array<string, mixed>>
     */
    public function childrenWithoutTags(DOMElement $element, array &$fallbacks, array $excludedTags): array
    {
        $localFallbacks = array();
        $blocks = $this->convertChildrenWithoutTags($element, $localFallbacks, $excludedTags);
        $fallbacks = array_merge($fallbacks, $localFallbacks);

        return $blocks;
    }

    /**
     * @param list<array<string, mixed>> $fallbacks
     * @param list<class-string<\Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternRecognizerInterface>> $allowed
     * @return array<string, mixed>|null
     */
    public function patterns(DOMElement $element, array &$fallbacks, array $allowed): ?array
    {
        if ( $this->runtimeIslands->retainsDataAttributeRuntimeTargetInShell($element) ) {
            return null;
        }
        $result = $this->patternRecognizers->firstMatch($element, $this->patternContext, $allowed);
        if (null === $result) return null;
        // Results own fallback payloads until their block wins the ordered stage.
        // Assign rather than mutating a variadic argument so reference-backed
        // child conversion diagnostics retain their exact order.
        $fallbacks = array_merge($fallbacks, $result->fallbacks());
        return $result->block();
    }

    private function createProbePatternContext(): PatternContext
    {
        return new PatternContext(
            presentation: $this->styleResolver,
            navigationContext: new NavigationPatternContext(
                $this->styleResolver,
                $this->session,
                publishesSubjects: false
            ),
            markupContext: new MarkupPatternContext(
                $this->session,
                $this->svgMaterializer->withReferencedDefinitions(...)
            ),
            codeWindowContext: new CodeWindowPatternContext($this->codePresentation ??= new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CodePresentation($this->styleResolver)),
            logoContext: new LogoPatternContext(
                $this->styleResolver
            ),
            images: $this->imageLowering,
            visibility: $this->sourceVisibility,
             disclosure: new DisclosureControlPresentation(
                 $this->styleResolver,
                 $this->session->generatedSupportStylesheetState(),
                 $this->svgMaterializer
             ),
             styles: $this->styleResolver
         );
    }

    /**
     * Neutral boxes on an evidence route, keyed by node id, each mapped to the
     * node id of its sole observed child. The classifier is the only authority.
     *
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function neutralBoxChildren(string $html, array $options): array
    {
        $tree = is_array($options['box_tree'] ?? null) ? $options['box_tree'] : array();
        if ( array() === ($tree['nodes'] ?? array()) ) {
            return array();
        }
        $classification = BoxTreeClassifier::classify($html, $tree);
        $children = array();
        foreach ( $classification['nodes'] as $id => $node ) {
            if ( 'neutral' === $node['disposition'] || 'folded' === $node['disposition'] ) {
                $children[(string) $id] = (string) $tree['nodes'][$id]['children'][0];
            }
        }
        // ADR 0005 slice 5: a folded box's declarations now belong to its child.
        foreach ( $classification['folds'] as $id => $fold ) {
            $this->foldedInto[(string) $fold['child']] = array('from' => (string) $id, 'moves' => $fold['moves'], 'equal_box' => $fold['equal_box']);
        }
        $this->session->overrideBoxTreeObservations($classification['observations']);
        $this->session->collapseBoxTreeNodes(array_map('strval', array_keys($children)));
        return $children;
    }

    /**
     * Captured observations for lifting author intent, where a collapsed box
     * reads as `display: contents`: it generates no box in the output, so it
     * cannot be the containing block a percentage resolves against.
     */
    private function liftEvidence(): \Closure
    {
        return function (DOMElement $node): array {
            $observations = $this->session->boxTreeEvidence($node);
            if ( !isset($this->neutralBoxChildren[$node->getAttribute(BoxTree::NODE_ATTRIBUTE)]) ) {
                return $observations;
            }
            return array_map(static fn(): array => array('used_styles' => array('display' => 'contents')), $observations);
        };
    }

    /**
     * Author bands lifted for a source element. A child that received a
     * folded box's declarations takes the box's bands for them (lifted in the
     * box's own context), never its own; sizing keeps the child's captured
     * pixels unless the box's intent is an intrinsic keyword that holds for
     * the child too.
     *
     * @return array<string, list<array{0: float|null, 1: string}>>
     */
    private function liftedBands(DOMElement $element, array $observations, array $viewports, array $parents, string $blockName): array
    {
        $fluid = (new Style\BoxTreeFluidIntent())->lift($element, $this->styleResolver, $observations, $viewports, $parents, $blockName, $this->liftEvidence());
        $fold = $this->foldedInto[$element->getAttribute(BoxTree::NODE_ATTRIBUTE)] ?? null;
        if ( null === $fold ) {
            return $fluid;
        }
        $box = $element->parentNode;
        while ( $box instanceof DOMElement && $fold['from'] !== $box->getAttribute(BoxTree::NODE_ATTRIBUTE) ) {
            $box = $box->parentNode;
        }
        if ( !$box instanceof DOMElement ) {
            return array_diff_key($fluid, $fold['moves'], array_flip(self::FOLD_SIZING));
        }
        $this->foldsCarried[$fold['from']] = $this->liftingFor;
        $boxObservations = $this->session->boxTreeEvidence($box);
        if ( !isset($this->foldFluid[$fold['from']]) ) {
            $boxParent = $this->blockParentElement($box);
            $this->foldFluid[$fold['from']] = $this->liftedBands($box, $boxObservations, $viewports, null !== $boxParent ? $this->session->boxTreeEvidence($boxParent) : array(), '');
        }
        $boxFluid = $this->foldFluid[$fold['from']];
        $sameFont = true;
        foreach ( $observations as $key => $observation ) {
            $sameFont = $sameFont && ($observation['used_styles']['font-size'] ?? null) === ($boxObservations[$key]['used_styles']['font-size'] ?? null);
        }
        foreach ( $fold['moves'] as $to => $from ) {
            $bands = $boxFluid[$from] ?? null;
            // An em length resolves against the font size of the box that declares it.
            if ( null !== $bands && !$sameFont && preg_grep('/\d(?:\.\d+)?em\b/i', array_column($bands, 1)) ) {
                $bands = null;
            }
            if ( null === $bands ) {
                unset($fluid[$to]);
            } else {
                $fluid[$to] = $bands;
            }
        }
        foreach ( self::FOLD_SIZING as $property ) {
            $bands = $boxFluid[$property] ?? null;
            $keywords = null !== $bands && array() === array_filter(array_column($bands, 1), static fn(string $value): bool => !in_array($value, self::INTRINSIC_SIZES, true));
            if ( $keywords && ($fold['equal_box'] || ($fluid[$property] ?? null) === $bands) ) {
                $fluid[$property] = $bands;
            } else {
                unset($fluid[$property]);
            }
        }
        return $fluid;
    }

    /**
     * Whether a folded box's declarations reached the block its child became.
     *
     * Lifting the box's bands for the child is not enough: a converter that
     * builds its block from source attributes (a runtime authored button, for
     * example) can project the child's box-tree rule and then emit a block
     * without the carrier class, leaving the box's placement and size nowhere.
     *
     * @param array<string, mixed> $block
     */
    private function foldCarried(string $boxId, array $block): bool
    {
        $carrierNode = $this->foldsCarried[$boxId] ?? null;
        if ( null === $carrierNode ) {
            return false;
        }
        $carriers = $this->boxTreeCarriers[$carrierNode] ?? array();
        return array() === $carriers || array() !== array_intersect($carriers, self::blockClassTokens($block));
    }

    /**
     * @param array<string, mixed> $block
     * @return list<string>
     */
    private static function blockClassTokens(array $block): array
    {
        $tokens = self::classTokens(is_string($block['attrs']['className'] ?? null) ? $block['attrs']['className'] : '');
        foreach ( is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array() as $inner ) {
            if ( is_array($inner) ) {
                $tokens = array_merge($tokens, self::blockClassTokens($inner));
            }
        }
        return $tokens;
    }

    /** @return list<string> */
    private static function classTokens(string $className): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim($className)) ?: array(), static fn(string $token): bool => '' !== $token));
    }

    /** Sizes a folded box's intent decides for its child. */
    private const FOLD_SIZING = array('width', 'height');

    private const INTRINSIC_SIZES = array('auto', 'none', 'fit-content', 'min-content', 'max-content', 'stretch');

    /** The source child that replaces a neutral box, or null when the box is kept. */
    private function neutralBoxChild(DOMElement $element): ?DOMElement
    {
        $childId = $this->neutralBoxChildren[$element->getAttribute(BoxTree::NODE_ATTRIBUTE)] ?? null;
        if ( null === $childId ) {
            return null;
        }
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && $childId === $child->getAttribute(BoxTree::NODE_ATTRIBUTE) ) {
                return $child;
            }
        }
        return null;
    }

    /** The nearest source ancestor that emits a block: neutral boxes are skipped. */
    private function blockParentElement(DOMElement $element): ?DOMElement
    {
        $parent = $element->parentNode;
        while ( $parent instanceof DOMElement && null !== $this->neutralBoxChild($parent) ) {
            $parent = $parent->parentNode;
        }
        return $parent instanceof DOMElement ? $parent : null;
    }

    /**
     * The sole child that replaces a retained custom-wrapper hook, or null when
     * the hook keeps its own Group.
     *
     * A hook stays in the source DOM only so author selectors that key on its
     * data attributes (for example Wix's
     * `[data-interact-key="…"] :is(.image):is(#comp)`) still match during
     * selector preparation. Projection then binds those rules through the
     * matched descendant, never through the hook's block, because the block
     * wrapper drops the data attribute. A hook that is boxless with its styles
     * prepared and wraps exactly one element has no box or rule of its own to
     * keep, so its Group would only add a nesting level.
     */
    private function boxlessWrapperHookChild(DOMElement $element): ?DOMElement
    {
        if ( ! in_array($element, $this->retainedCustomWrapperHooks, true) ) {
            return null;
        }
        $child = null;
        foreach ( $element->childNodes as $node ) {
            if ( $node instanceof DOMElement ) {
                if ( null !== $child ) {
                    return null;
                }
                $child = $node;
            } elseif ( $node instanceof \DOMText && '' !== trim($node->textContent) ) {
                return null;
            }
        }
        if ( null === $child
            || ! $this->runtimeIslands->isSafeTransparentCustomElement($element, true)
            || ! $this->renderNeutral->isBoxlessWrapper($element)
        ) {
            return null;
        }
        return $child;
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function convertElement(DOMElement $element, array &$fallbacks, bool $captureUnsupported = false): ?array
    {
        // A folded child converts with its box's declarations, decided outside
        // the subtree, so it always converts.
        if ( true === $this->chromeJournal?->covers($element) && !isset($this->foldedInto[$element->getAttribute(BoxTree::NODE_ATTRIBUTE)]) ) {
            return $this->chromeJournal->convert($element, $fallbacks, fn (array &$fallbacks): ?array => $this->convertElement($element, $fallbacks, $captureUnsupported));
        }
        $hookChild = $this->boxlessWrapperHookChild($element);
        if ( null !== $hookChild ) {
            return $this->convertElement($hookChild, $fallbacks, $captureUnsupported);
        }
        // ADR 0005: a box the classifier proves neutral emits no block. Its sole
        // child takes its place before any converter can make it a Group or
        // register its engine rule; the child keeps its own rule.
        $neutralChild = $this->neutralBoxChild($element);
        if ( null !== $neutralChild ) {
            $boxId = $element->getAttribute(BoxTree::NODE_ATTRIBUTE);
            $childId = $neutralChild->getAttribute(BoxTree::NODE_ATTRIBUTE);
            if ( $boxId !== ($this->foldedInto[$childId]['from'] ?? null) ) {
                return $this->convertElement($neutralChild, $fallbacks, $captureUnsupported);
            }
            // A folded box's declarations survive only in its child's box-tree
            // rule. A child converted without one (a pattern leaf such as an
            // accessible icon link) would drop the box's placement and size,
            // so the box keeps its own block and rule instead.
            $before = $fallbacks;
            $block = $this->convertElement($neutralChild, $fallbacks, $captureUnsupported);
            if ( null === $block || $this->foldCarried($boxId, $block) ) {
                return $block;
            }
            $fallbacks = $before;
            unset($this->neutralBoxChildren[$boxId], $this->foldedInto[$childId]);
        }
        $tagName = strtolower($element->tagName);

        // An adopted dialog panel's bindings belong to the panel before any
        // converter claims it; its shell then converts it as ordinary content.
        $adopted = $this->elementPrelude->adoptedDialogPanel($element, $fallbacks);
        if ( $adopted->handled ) {
            return $adopted->block;
        }

        // A boxless host is not a cohesive component: keep its selector identity
        // on a native Group, without folding its child (the actual grid/flex
        // item) into that Group. Author projection carries display:contents and
        // attribute compounds onto the native carrier's selector classes.
        // Unlike transparent flattening, this path retains the host and child
        // boundaries, so descendant motion structure does not make it unsafe.
        if ( str_contains($tagName, '-') && $this->runtimeIslands->isSafeTransparentCustomElement($element, true)
            && ( in_array($element, $this->retainedCustomWrapperHooks, true)
                || 'contents' === strtolower(trim((string) ($this->styleResolver->structuralPresentationDeclarations($element)['display'] ?? ''))) )
        ) {
            $block = $this->createBlock('core/group', $this->styleResolver->presentationAttributes($element), $this->convertChildren($element, $fallbacks, $captureUnsupported), $element);
            $block['_native_custom_wrapper_hook'] = true;
            return $block;
        }

        $outcome = $this->elementConverters->convert($element, $tagName, $fallbacks, $captureUnsupported);
        if ( $outcome->handled ) {
            return $outcome->block;
        }

        // Last disposition before the unsupported gate: a custom element whose
        // whole subtree renders nothing is transport scaffolding, not lost
        // content, so omit it without recording a fallback downstream importers
        // would count as an unsupported reduction.
        if ( $this->renderNeutral->isEmptyRenderNeutralCustomElement($element, $this->documentAuthorCss) ) {
            return null;
        }

        if ( $captureUnsupported ) {
            return $this->unsupportedRecorder->record($element, $tagName, $fallbacks);
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function text(string $text): array
    {
        $blocks = array();
        if ( $this->runtime->isShortcodeOnly($text) ) {
            $blocks[] = $this->createBlock('core/shortcode', array( 'text' => $this->runtime->preserveShortcodeText($text) ));
            return $blocks;
        }

        // A source text run has no paragraph box of its own: the paragraph is
        // only the validity carrier, so it renders in its parent's box.
        $blocks[] = $this->createBlock('core/paragraph', array(
            'className' => self::SYNTHETIC_PARAGRAPH_CLASS,
            'content'   => $this->runtime->escapeHtml($text),
        ));
        return $blocks;
    }

    /** @return array<string, mixed> */
    private function runtimeAuthoredButton(DOMElement $element): array
    {
        $this->formRuntimeIslandRecorder->recordControl($element);

        return $this->authoredFormControlBlockConverter->runtimeButton($element);
    }

    /** @return array<string, mixed>|null */
    public function percentageSizedFormButton(DOMElement $button): ?array
    {
        if ( ! $this->buttonHasPercentageSize($button) ) {
            return null;
        }
        $this->session->authorSelectorProjectionState()->markRetainedSourcePath($button->getNodePath() ?? '');
        return $this->authoredFormControlBlockConverter->button($button, true, true);
    }

    /** Keep containing-block-relative sizes on the original, editable control. */
    private function buttonHasPercentageSize(DOMElement $button): bool
    {
        // Standalone CTA buttons have their own wrapper sizing projection.
        // Form controls instead need to remain items of their source form row.
        $form = $button->parentNode;
        while ( $form instanceof DOMElement && 'form' !== strtolower($form->tagName) ) {
            $form = $form->parentNode;
        }
        if ( ! $form instanceof DOMElement ) {
            return false;
        }
        $sizes = array( 'width', 'min-width', 'max-width', 'height', 'min-height', 'max-height' );
        // Retain conditional rules rather than testing only the resting viewport.
        foreach ( $this->styleResolver->authoredControlPresentation($button, $sizes) as $declarations ) {
            foreach ( $declarations as $value ) {
                if ( str_contains($this->styleResolver->customProperties->resolve($value, $button), '%') ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<int, array<string, mixed>> $innerBlocks
     * @return array<string, mixed>
     */
    public function createBlock(string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null, ?DOMElement $logicalSourceElement = null): array
    {
        if ('core/social-link' === $name && $sourceElement instanceof DOMElement) {
            $anchor = 'a' === strtolower($sourceElement->tagName) ? $sourceElement : $sourceElement->getElementsByTagName('a')->item(0);
            if ($anchor instanceof DOMElement) {
                $this->session->authorSelectorProjectionState()->markSocialAnchor($anchor);
                $attrs = (new SocialSourceStyleProjector($this->styleResolver, $this->session->sourceStyleResolutionState()))->project($attrs, $anchor, $this->session->authorStyleAnalysis(), $this->session->sourceTargetProjectionState(), !str_contains((string) ($attrs['className'] ?? ''), 'blocks-engine-social-source-item'));
            }
        }
        if ( 'core/cover' === $name && $sourceElement instanceof DOMElement && in_array(CoverPattern::CONSUMED_BACKGROUND_CLASS, preg_split('/\s+/', (string) ($attrs['className'] ?? '')) ?: array(), true) ) {
            $this->session->authorSelectorProjectionState()->markConsumedCoverBackground($sourceElement->getNodePath() ?? '');
        }
        // Control boxes transport source attributes through runtime metadata.
        // Forms must also keep their core/group identity for provider substitution;
        // their hidden state travels through the generic runtime attributes.
        if ( 'core/group' === $name && ! str_contains((string) ($attrs['className'] ?? ''), SourceBlockAttributeProjector::HIDDEN_RICH_TEXT_MARKER_CLASS) && 'form' !== ($attrs['tagName'] ?? '') && ! isset($attrs['metadata']['blocksEngineControlBox']) && $sourceElement instanceof DOMElement && $this->emptyVisualRetention->preservesScriptStateWrapper($sourceElement) ) {
            return $this->layoutShells->blockForElements(array( $sourceElement ), $innerBlocks, $sourceElement);
        }
        if ( 'core/heading' === $name ) {
            $attrs['content'] = $this->richTextMaterializer->headingPhrasingContent((string) ($attrs['content'] ?? ''));
        }
        if ( $sourceElement instanceof DOMElement ) {
            $container = $this->structuralRichTextContainers->containerBlock($name, $attrs, $sourceElement, $logicalSourceElement);
            if ( null !== $container ) {
                return $container;
            }
        }

        $preserveInlineLayoutLeaf = ! empty($attrs['preserveInlineLayoutLeaf']);
        unset($attrs['preserveInlineLayoutLeaf']);
        // Source comments (framework text-split markers such as React's
        // `<!-- -->`) are not RichText content: the editor shows each one as a
        // chip. The text-run boundaries they make still shape the text.
        $richTextAttribute = match ($name) {
            'core/paragraph', 'core/heading' => 'content',
            'core/button' => 'text',
            default => null,
        };
        if ( null !== $richTextAttribute && isset($attrs[$richTextAttribute]) ) {
            $attrs[$richTextAttribute] = CommentTextRunBoundaries::apply((string) $attrs[$richTextAttribute]);
        }
        // Pass CSS-owned topology changes to IntrinsicVisualMediaHeight::apply()
        // so its independent height carrier cannot re-pin an unsafe constraint.
        $unsafeFixedHeightTopologyChange = ! empty($attrs['unsafeFixedHeightTopologyChange']);
        unset($attrs['unsafeFixedHeightTopologyChange']);
        if ( ! $preserveInlineLayoutLeaf ) {
            $attrs = $this->richTextStylingHooks->hoistContentWrappingSpans($name, $attrs);
        }
        // A RichText carrier whose only classes are its role and subject
        // classes carries no authored class.
        if ( 'core/paragraph' === $name && ! $preserveInlineLayoutLeaf && ! $sourceElement instanceof DOMElement
            && str_contains((string) preg_replace('/\sclass="(?:\s*blocks-engine-(?:role-richtext|subject)[a-z0-9-]*)+\s*"/', '', (string) ($attrs['content'] ?? '')), 'class=')
        ) {
            $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), self::SYNTHETIC_PARAGRAPH_CLASS);
        }
        if ( $sourceElement instanceof DOMElement && in_array($name, array( 'core/paragraph', 'core/heading' ), true) ) {
            $textAlign = $this->styleResolver->inheritedTextAlignmentDeclaration($sourceElement);
            if ( in_array($textAlign, array( 'left', 'center', 'right' ), true) ) {
                $attrs['style']['typography']['textAlign'] = $textAlign;
            }
        }

        if ( $sourceElement instanceof DOMElement
            && in_array($name, array( 'core/paragraph', 'core/heading' ), true)
            && $this->richTextMaterializer->requiresHtmlFallbackForElement($sourceElement, (string) ($attrs['content'] ?? '')) ) {
            $materialized = $this->richTextMaterializer->contentWithMaterializedSvgImages($sourceElement, (string) ($attrs['content'] ?? ''));
            if ( null !== $materialized ) {
                $attrs['content'] = $materialized;
            }
            $attrs['content'] = $this->richTextMaterializer->stripDecorativeSvg((string) ($attrs['content'] ?? ''));
            if ( $this->richTextMaterializer->requiresHtmlFallbackForElement($sourceElement, (string) ($attrs['content'] ?? '')) ) {
                return $this->createBlock('core/html', array( 'content' => SourceDom::safeFallbackHtml($sourceElement, $this->session->authorSelectorProjectionState()->tagMarkers()) ), array(), $sourceElement);
            }
        }

        $sourceMaterializationFacts = null;
        if ( $sourceElement instanceof DOMElement ) {
            $sourceTagName = strtolower($sourceElement->tagName);
            if ( in_array($name, array( 'core/group', 'core/column', 'core/columns' ), true) ) {
                $attrs = ($this->intrinsicVisualMediaHeight ??= new IntrinsicVisualMediaHeight($this->styleResolver, $this->session->sourceStyleResolutionState()))->apply($sourceElement, $attrs, $unsafeFixedHeightTopologyChange);
            }
            $logicalControl = $logicalSourceElement ?? $sourceElement;
            $logicalControlPath = $logicalControl->getNodePath() ?? '';
            $sourceControlPath = $sourceElement->getNodePath() ?? '';
            $hasAuthorControlProjection = in_array($name, array( 'core/button', 'core/buttons' ), true)
                && in_array(strtolower($logicalControl->tagName), array( 'a', 'button' ), true)
                && ($this->session->authorSelectorProjectionState()->isControlPath($logicalControlPath)
                    || $this->session->authorSelectorProjectionState()->isControlPath($sourceControlPath)
                    || ('' !== $this->session->authorStyleAnalysis()->combinedCss()
                        && (( 'a' === strtolower($logicalControl->tagName)
                                && ('' !== trim(SourceDom::attr($logicalControl, 'class')) || '' !== trim(SourceDom::attr($logicalControl, 'id'))))
                            || ( 'button' === $sourceTagName
                                && ('' !== trim(SourceDom::attr($sourceElement, 'class')) || '' !== trim(SourceDom::attr($sourceElement, 'id')))))));
            // Accordion headings project inherited control typography; keep the
            // standard fallback for properties core does not support (line-height).
            $preserveGeneratedStyle = 'core/accordion-heading' === $name
                || ('core/button' === $name && $this->sourceElementClassifier->hasLogoBrandSignal($sourceElement))
                || ('core/spacer' === $name && $this->emptyElementFootprint->isEmptyVisualInlineCandidate($sourceElement));
            $imageFigureFlow = 'core/image' === $name && 'figure' !== $sourceTagName
                ? $this->syntheticImageFigureFlow->syntheticImageFigureFlow($sourceElement)
                : Elements\SyntheticImageFigureFlow::BOX;
            $attrs = $this->sourceBlockAttributeProjector->project(
                $name,
                $attrs,
                $innerBlocks,
                $sourceElement,
                $logicalControl,
                new SourceBlockAttributeProjectionFacts(
                    $this->sourceElementClassifier->isInlineSourceElement($sourceTagName),
                    'core/group' === $name
                        && $this->authorLayoutOwnership->isDirectChildOfAuthorOwnedLayout($sourceElement)
                        && $this->session->authorSelectorProjectionState()->hasSemanticMarker($sourceElement),
                    $hasAuthorControlProjection,
                    'core/button' === $name && '' !== $logicalControlPath && $hasAuthorControlProjection
                        && $this->authorLayoutOwnership->isDirectChildOfAuthorFlexLayout($logicalControl),
                    $preserveGeneratedStyle,
                    Elements\SyntheticImageFigureFlow::INLINE === $imageFigureFlow,
                    Elements\SyntheticImageFigureFlow::STRUT === $imageFigureFlow
                ),
                $this->sourceBlockAttributeProjectionContext()
            );
            $attrs = $this->projectBoxTreeCarrier($name, $attrs, $sourceElement);
            $sourceMaterializationFacts = new SourceBlockMaterializationFacts(
                $sourceElement,
                $sourceTagName,
                SourceDom::elementSelector($sourceElement),
                $this->sourceProvenance->entry($name, $sourceElement),
                // The ancestor-aware, conditional-CSS-resolving check only earns
                // its extra selector-matching cost for core/navigation, the one
                // block NavigationBlockNormalizer deduplicates by this signal.
                'core/navigation' === $name
                    ? $this->sourceVisibility->orAncestorStartsHidden($sourceElement)
                    : $this->sourceVisibility->startsHidden($sourceElement),
                SourceDom::htmlAttributes($sourceElement),
                $this->sourceElementDescription->structureSignals($sourceElement, $attrs),
                // A populated group whose box is responsive and stylesheet-owned
                // carries no block style, yet must stay independently editable
                // rather than folding into a layout-shell wrapper list.
                'core/group' === $name && (array() === $innerBlocks || $this->emptyVisualRetention->responsiveStylesheetOwnsWrapperBox($sourceElement))
                    ? $this->emptyVisualRetention->emptyVisualTopologyEvidence($sourceElement)
                    : array(),
                'core/navigation' === $name && $this->sourceVisibility->wrapperHiddenAtCollapsedViewport($sourceElement)
            );
        }

        if ( $sourceElement instanceof DOMElement && in_array($name, array( 'core/group', 'core/quote' ), true) ) {
            $innerBlocks = $this->withPropagatedWrapperTextAlignment($innerBlocks, $sourceElement);
        }

        // Every RichText carrier a block stores passes through here, so this is
        // where each one gets its role and subject classes; the saved markup
        // is built from these attributes and so carries them too.
        $attrs = $this->session->authorSelectorProjectionState()->withRichTextCarrierAttrs($attrs);

        return $this->sourceInterItemSpaces->apply($this->blockMaterializer->materialize(
            $name,
            $attrs,
            $innerBlocks,
            $sourceMaterializationFacts,
            $this->session->transformationProvenanceState()
        ), $sourceElement);
    }

    /**
     * Give a source element's block its box-tree carrier: the observed
     * presentation of an evidence route, whose author stylesheets are retired.
     * Without box-tree evidence the attributes are returned unchanged.
     *
     * @param array<string, mixed> $attrs
     * @param list<string> $withheld Properties another owner (a runtime toggle) sets on the box, kept out of the carrier.
     * @param bool $inlineSupports Whether invariant declarations may become style attributes; a block whose markup
     *        is built by hand keeps them all in the carrier.
     * @return array<string, mixed>
     */
    public function projectBoxTreeCarrier(string $name, array $attrs, DOMElement $sourceElement, array $withheld = array(), bool $inlineSupports = true): array
    {
        $sourceTagName = strtolower($sourceElement->tagName);
        $withheld = array_fill_keys($withheld, true);
        $observations = $this->session->boxTreeEvidence($sourceElement);
        foreach ($observations as $key => $observation) {
            if (is_array($observation['used_styles'] ?? null)) $observations[$key]['used_styles'] = array_diff_key($observation['used_styles'], $withheld);
        }
        $viewports = $this->session->boxTreeViewports();
        $blockParent = $this->blockParentElement($sourceElement);
        $parents = $this->session->boxTreeParentEvidence($sourceElement, $blockParent);
        $liftingFor = $this->liftingFor;
        $this->liftingFor = $sourceElement->getAttribute(BoxTree::NODE_ATTRIBUTE);
        $fluid = $this->liftedBands($sourceElement, $observations, $viewports, $parents, $name);
        $this->liftingFor = $liftingFor;
        $fluid = array_diff_key($fluid, $withheld);
        $fluidIntent = new Style\BoxTreeFluidIntent();
        $pseudoFluid = array();
        foreach ( array( 'before', 'after' ) as $pseudo ) {
            $pseudoFluid[$pseudo] = $fluidIntent->liftPseudo($sourceElement, $this->styleResolver, $observations, $viewports, $pseudo, $this->liftEvidence());
        }
        // An authored figure (or a custom image host lowered as the figure)
        // keeps its own box; the image it paints is styled from its own evidence.
        $image = array();
        $paintedImage = 'core/image' === $name && ('figure' === $sourceTagName || str_contains($sourceTagName, '-')) ? $sourceElement->getElementsByTagName('img')->item(0) : null;
        if ($paintedImage instanceof DOMElement && $paintedImage->parentNode instanceof DOMElement) {
            $imageObservations = $this->session->boxTreeEvidence($paintedImage);
            $imageParents = $this->session->boxTreeEvidence($paintedImage->parentNode);
            $image = array(
                'observations' => $imageObservations,
                'parents' => $imageParents,
                'fluid' => $fluidIntent->lift($paintedImage, $this->styleResolver, $imageObservations, $viewports, $imageParents, $name, $this->liftEvidence()),
            );
        }
        $scrollAnimation = ($this->boxTreeScrollAnimationBinding ??= new Style\BoxTreeScrollAnimationBinding($this->session))->binding($sourceElement, $observations, $viewports);
        if (null !== $scrollAnimation) {
            // The restored binding names the animation, and the transform
            // it painted at capture is a frozen frame of it, not the
            // carrier's to restate.
            $frozen = array_merge(array('animation-name'), array_intersect($scrollAnimation['animated'], array('transform', 'translate', 'rotate', 'scale')));
            foreach ($observations as &$observation) {
                foreach ($frozen as $property) {
                    if (isset($observation['used_styles'][$property])) $observation['used_styles'][$property] = 'none';
                }
            }
            unset($observation);
        }
        $classesBefore = self::classTokens((string) ($attrs['className'] ?? ''));
        $attrs = (new Style\BoxTreeStyleProjector())->project($attrs, $observations, $viewports, $this->session->layoutGeometryState(), $parents, $name, $fluid, $pseudoFluid, $sourceTagName, $image, $inlineSupports);
        if (null !== $scrollAnimation) {
            $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $this->boxTreeScrollAnimationBinding->carrier($sourceElement, $scrollAnimation, $this->session->layoutGeometryState()));
        }
        $carrierNode = $sourceElement->getAttribute(BoxTree::NODE_ATTRIBUTE);
        if ( '' !== $carrierNode ) {
            $this->boxTreeCarriers[$carrierNode] = array_values(array_diff(self::classTokens((string) ($attrs['className'] ?? '')), $classesBefore));
        }

        return $attrs;
    }

    /**
     * Give a text wrapper's generated inner RichText blocks the wrapper's own
     * alignment.
     *
     * A wrapper that owns box chrome stays a core/group, and a quoted wrapper
     * stays a core/quote; neither carries a native text alignment, and the source
     * alignment reaches neither the block tree nor the generated stylesheet (see
     * StyleResolver::generatedRichTextAlignment()). The paragraph generated for
     * the wrapper's own text is where the alignment belongs natively.
     *
     * Scoped to phrasing-only wrappers, so the inner blocks ARE that wrapper's own
     * text rather than an arbitrary layout subtree, and only the text alignment
     * inheritance the source already expressed is restated. A block that resolved
     * its own alignment keeps it: explicit descendant alignment wins.
     *
     * @param array<int, array<string, mixed>> $innerBlocks
     * @return array<int, array<string, mixed>>
     */
    private function withPropagatedWrapperTextAlignment(array $innerBlocks, DOMElement $sourceElement): array
    {
        if ( array() === $innerBlocks || ! $this->sourceElementClassifier->hasOnlyPhrasingChildren($sourceElement) ) {
            return $innerBlocks;
        }

        $alignment = $this->styleResolver->generatedRichTextAlignment($sourceElement);
        if ( '' === $alignment ) {
            return $innerBlocks;
        }

        foreach ( $innerBlocks as $index => $innerBlock ) {
            if ( ! is_array($innerBlock)
                || ! in_array((string) ($innerBlock['blockName'] ?? ''), array( 'core/paragraph', 'core/heading' ), true)
                || '' !== trim((string) ($innerBlock['attrs']['align'] ?? ''))
            ) {
                continue;
            }
            $innerBlocks[ $index ] = $this->blockFactory->rebuild(
                $innerBlock,
                array_merge(ArrayField::array($innerBlock, 'attrs'), array( 'align' => $alignment ))
            );
        }

        return $innerBlocks;
    }

    /** Read lazily: the projector is built after the closure that needs it. */
    private function controlCarrierLayerRevertRule(DOMElement $element, string $marker): string
    {
        return $this->authorStylesheetProjector->controlCarrierLayerRevertRule($element, $marker);
    }

    /**
     * Read lazily: the prelude is built after the converter that needs it.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>
     */
    private function capturedDialogBlock(DOMElement $element, array &$fallbacks): array
    {
        return $this->elementPrelude->capturedDialogBlock($element, $fallbacks);
    }

    /** Read lazily: closures built before the retention policy call it. */
    public function retainsEmptyRichTextInline(DOMElement $element): bool
    {
        return $this->emptyVisualRetention->retainsEmptyRichTextInline($element);
    }

    public function richTextMarker(DOMElement $element): string
    {
        return $this->authorLayoutOwnership->richTextMarker($element);
    }

    private function createCommercePattern(): CommerceStructureRecognizer
    {
        return new CommerceStructureRecognizer(
            $this->sourceElementClassifier,
            fn (DOMElement $element): ?array => $this->svgMaterializer->inlineSvgImageReference($element)
        );
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
                    $blocks = array_merge($blocks, $this->text($text));
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
     * Preserve a flow-content button as one control container: the source
     * `<button>` saved as a layout-shell wrapper (its type/ARIA attributes and
     * authored classes intact) around the editable inner blocks its children
     * convert to. core/button stores its label as phrasing RichText, so a
     * heading inside the control is erased to plain text — its block
     * semantics, level and authored typography and color are lost. Mirroring
     * the flow-content anchor path keeps the heading a heading while the
     * wrapper keeps the control's semantics, selectors and hitbox. Bare div
     * boundaries inside the control are retained where coalescing would
     * change inline media line boxes, as for anchors.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    public function convertButtonWrapperGroup(DOMElement $button, array &$fallbacks): ?array
    {
        $children = $this->convertChildren($button, $fallbacks, true, true);
        if ( array() === $children ) {
            return null;
        }

        if ( '' === trim($button->textContent) && $button->getElementsByTagName('img')->length > 0 ) {
            $attributes = array();
            foreach ($button->attributes as $attribute) {
                $attributes[strtolower($attribute->nodeName)] = $attribute->nodeValue ?? '';
            }
            if (!isset($attributes['type'])) $attributes['type'] = 'submit';
            return $this->createBlock('core/group', array(
                'tagName' => 'button',
                'className' => SourceDom::attr($button, 'class'),
                'metadata' => array('blocksEngineControlBox' => $attributes, 'blocksEngineControlTagName' => 'button'),
            ), $children, $button);
        }

        return $this->layoutShells->blockForElements(array($button), $children, $button);
    }

    /**
     * GeneratedComponentLowering is built before the author layout lowering
     * it falls back to, so it reaches it through the compiler.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     */
    public function authorLayoutBlockFromElement(DOMElement $element, array &$fallbacks): array
    {
        return $this->authorLayout->authorLayoutBlockFromElement($element, $fallbacks);
    }
}


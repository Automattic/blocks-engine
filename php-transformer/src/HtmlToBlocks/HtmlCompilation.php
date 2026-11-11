<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\CaptureReplayScripts;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\Contract\BlockCompilationOutput;
use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionFindingContract;
use Automattic\BlocksEngine\PhpTransformer\Contract\EditabilityReport;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\CommentTextRunBoundaries;
use Automattic\BlocksEngine\PhpTransformer\WordPress\CoreBlockCapabilityMatrix;
use Automattic\BlocksEngine\PhpTransformer\Contract\CoreHtmlFallbackEvidence;
use Automattic\BlocksEngine\PhpTransformer\Contract\TransformationOptions;
use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthorLayoutBlockGenerator;
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
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
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
use DOMDocument;
use DOMElement;
use DOMNode;

/** Run-scoped compiler for one HTML document. */
final class HtmlCompilation extends SourceBlockCreator implements RichTextInlinePolicy, PatternTreeConverter, Elements\AuthorLayoutLowering
{
    use DomHelpersTrait;

    private readonly AuthorLayoutOwnership $authorLayoutOwnership;
    private readonly Elements\LayoutTableLowering $layoutTables;

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

    private readonly TextLeafElementConverter $textLeafConverter;

    private readonly RichTextElementConverter $richTextConverter;

    private readonly StyleResolver $styleResolver;
    private readonly SourceInterItemSpaces $sourceInterItemSpaces;
    private ?IntrinsicVisualMediaHeight $intrinsicVisualMediaHeight = null;
    private readonly ImageUrlResolver $imageUrls;
    private readonly Elements\MediaLowering $mediaLowering;
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
    private readonly Elements\StructuredInlineItemLowering $structuredInlineItemLowering;
    private readonly Elements\SyntheticImageFigureFlow $syntheticImageFigureFlow;
    private readonly Support\SourceDocumentEnvelope $documentEnvelope;
    private readonly Support\GeneratedComponentCandidates $generatedComponentCandidates;
    private readonly Elements\CustomImageHostLowering $customImageHosts;
    private readonly Elements\AnchorLowering $anchors;
    private readonly Elements\RenderNeutralElements $renderNeutral;
    private readonly Elements\InertElements $inertElements;
    private readonly Elements\EmptyInlineGeometryLowering $emptyInlineGeometry;
    private readonly Elements\CustomWrapperConventions $wrapperConventions;
    private readonly Elements\EmptyVisualSpacerLowering $emptyVisualSpacers;
    private readonly Diagnostics\FallbackReconciliation $fallbackReconciliation;
    private readonly Elements\GlobalShellArtifacts $globalShellArtifacts;
    private readonly Elements\LinkCarrierLowering $linkCarriers;
    private readonly Elements\ParagraphImageUnwrapping $paragraphImages;
    private readonly Elements\TextlessMediaLowering $textlessMedia;
    private readonly Support\StyledSourceClassBoundary $styledClassBoundaries;
    private readonly Elements\EmptyVisualRetentionPolicy $emptyVisualRetention;
    private readonly SourceProvenance $sourceProvenance;


    private readonly FormRuntimeIslandRecorder $formRuntimeIslandRecorder;

    private readonly InteractionFallbackReporter $interactionFallbackReporter;

    /** Held per transform so the context-typography cascade analysis runs once. */

    private readonly AuthoredFormControlBlockConverter $authoredFormControlBlockConverter;

    private readonly ReadableFormControlBlockConverter $readableFormControlBlockConverter;





    /** @var list<DOMElement> Neutral convention instances retained as author selector hooks. */
    private array $retainedCustomWrapperHooks = array();

    private readonly ElementConversionPrelude $elementPrelude;
    /** Element conversion steps, in dispatch order. */
    private readonly OrderedElementConverterRegistry $elementConverters;

    private readonly WrapperCoalescer $wrapperCoalescer;




    private readonly SearchBlockConverter $searchBlockConverter;

    private readonly ButtonLinkDispatcher $buttonLinkDispatcher;


    private readonly FlowContainerElementConverter $flowContainerConverter;


    private readonly AuthoredCarouselBlockGenerator $authoredCarouselGenerator;




    private readonly UnsupportedElementRecorder $unsupportedRecorder;

    private readonly PatternContext $patternContext;

    private readonly PatternContext $patternProbeContext;

    private readonly ProjectedNavigationConverter $projectedNavigation;

    private readonly SourceElementClassifier $sourceElementClassifier;
    private readonly ListStructure $listStructure;
    private readonly CommerceStructureRecognizer $commercePattern;

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
        $this->listStructure = new ListStructure($this->sourceElementClassifier);
        $this->commercePattern = $this->createCommercePattern();
        $this->sourceElementDescription = new SourceElementDescription($this->sourceElementClassifier, $this->commercePattern);
        $this->session = new HtmlTransformerSession($this->runtime, $this->sourceElementDescription);
        parent::__construct($this->session);
        $this->stylesheetAssetStage = new StylesheetAssetStage($this->session);
        $this->blockFactory      = new BlockFactory();
        $backgroundImageExtractor = new BackgroundImageExtractor();
        $this->tableClassificationPolicy = new TableClassificationPolicy();
        $this->navigationBlockNormalizer = new NavigationBlockNormalizer(fn (string $label): string => $this->normalizedNavigationLabel($label));
        $this->validation = new Diagnostics\CompilationValidation($this->runtime, $this->session, $this->analysisCache->cssFontAnalysis);
        $this->reusableComponentRecognizer = new ReusableComponentRecognizer();
        $this->styleResolver = new StyleResolver($this->session, $this->createStyleResolutionContext(), $this->analysisCache);
        $this->sourceInterItemSpaces = new SourceInterItemSpaces($this->styleResolver, $this->sourceElementClassifier);
        $this->imageUrls = new ImageUrlResolver($this->session);
        $this->mediaLowering = new Elements\MediaLowering($this, $this->styleResolver, $this->imageUrls);
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
        $this->emptyElementFootprint = new EmptyElementFootprint($this->styleResolver, $this->sourceElementClassifier);
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
        $this->authoredMarqueeRecognizer = new AuthoredMarqueeRecognizer($this->sourceElementClassifier, $this->styleResolver, $this->richTextMaterializer, $this->runtime, $this->session);
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
        $this->renderNeutral = new Elements\RenderNeutralElements($this->session, $this->styleResolver, $this->sourceElementClassifier, $this->sourceElementDescription, $this->runtimeIslands, $this->emptyElementFootprint);
        $this->styledClassBoundaries = new Support\StyledSourceClassBoundary($this->session);
        $this->emptyVisualRetention = new Elements\EmptyVisualRetentionPolicy(
            $this->styleResolver,
            $this->session,
            $this->sourceElementClassifier,
            $this->sourceElementDescription,
            $this->runtimeIslands,
            $this->emptyElementFootprint,
            $this->sourceVisibility,
            $this->renderNeutral,
            $this->styledClassBoundaries,
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
            fn (DOMElement $element): bool => $this->imageLowering->isImageOnlyAnchor($element),
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
            $this->listStructure
        );
        $emptyFeatureShells = new Elements\EmptyInteractiveFeatureShell($this->runtimeIslands, $this->wrapperCoalescer);
        $this->generatedComponentCandidates = new Support\GeneratedComponentCandidates($this->session, $this->sourceElementClassifier);
        $this->documentEnvelope = new Support\SourceDocumentEnvelope($this->styleResolver);
        $customVideoHosts = new Elements\CustomVideoHostLowering($this->session, $this->styleResolver, $this->imageUrls, $this->runtimeIslands, $this->mediaLowering, $this->imageLowering, $this->layoutShells, $this);
        $listItemLowering = new Elements\ListItemLowering($this, $this, $this->runtime, $this->session, $this->styleResolver, $this->listStructure, $this->richTextMaterializer, $this->wrapperCoalescer, $this->authorLayoutOwnership);
        $this->structuredInlineItemLowering = new Elements\StructuredInlineItemLowering($this, $this->runtime, $this->sourceElementClassifier, $this->styleResolver, $inlineSvgTextGroups);
        $this->inertElements = new Elements\InertElements($this->styleResolver, $this->sourceElementClassifier, $this->sourceVisibility, $this->runtimeIslands);
        $this->emptyInlineGeometry = new Elements\EmptyInlineGeometryLowering($this, $this->session, $this->sourceElementClassifier, $this->emptyElementFootprint, $authoredStateButtons, $this->layoutShells, $this->runtimeIslands);
        $this->wrapperConventions = new Elements\CustomWrapperConventions($this->styleResolver, $this->renderNeutral);
        $this->emptyVisualSpacers = new Elements\EmptyVisualSpacerLowering($this, $this, $this->session, $this->styleResolver, $this->emptyElementFootprint, $this->runtimeIslands);
        $this->fallbackReconciliation = new Diagnostics\FallbackReconciliation($this->runtimeIslands);
        $inlineCheckableControls = new Elements\InlineCheckableControlLowering($this, $this->runtime, $this->session, $this->sourceElementClassifier, $this->richTextMaterializer, $authoredStateButtons, $this->runtimeIslands, $this->layoutShells);
        $this->globalShellArtifacts = new Elements\GlobalShellArtifacts($this, $this, $this->runtime, $this->session, $this->navigationBlockNormalizer);
        $this->linkCarriers = new Elements\LinkCarrierLowering($this, $this, $this->runtime, $this->styleResolver, $this->runtimeIslands, $this->layoutShells);
        $namePriceRows = new Elements\NamePriceRowLowering($this, $this, $this->styleResolver, $this->sourceElementDescription);
        $mediaGalleries = new Elements\MediaGalleryLowering($this->sourceElementClassifier, $this->styleResolver, $this->imageLowering, $this);
        $this->customImageHosts = new Elements\CustomImageHostLowering($this, $this->blockFactory, $this->styleResolver, $this->session, $this->sourceElementClassifier, $this->sourceElementDescription, $this->imageLowering, $this->layoutShells, $this->runtimeIslands, $this->authorLayoutOwnership, $this->imageDimensions, $this->imageUrls);
        $this->anchors = new Elements\AnchorLowering($this, $this, $this->styleResolver, $this->session, $this->sourceElementClassifier, $this->sourceElementDescription, $this->imageLowering, $this->imageUrls, $this->layoutShells, $linkWrapperPropagator, $this->runtimeIslands, $this->customImageHosts);
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
                $this->listStructure,
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
            $authorStyleRuleProjector
        );
        $this->textLeafConverter = new TextLeafElementConverter($this->richTextMaterializer, $this->runtime, $this, $this->styleResolver, $this->sourceElementClassifier, $this->createTextLeafElementContext());
        $this->richTextConverter = new RichTextElementConverter($this, $this->styleResolver, $this->richTextMaterializer, $this->runtime, $this->createRichTextElementContext());
        $svgConverter = new SvgElementConverter($this, $this->sourceElementClassifier, $this->styleResolver, new SvgElementContext(
            fn (DOMElement $element): bool => $this->inertElements->isInertHiddenSvgStorage($element),
            $this->runtimeIslands,
            fn (DOMElement $element): string => $this->svgMaterializer->sanitizeInlineSvgMarkup($element),
            fn (string $html): bool => SourceDom::isSafeSvgContent($html),
            fn (DOMElement $element): bool => SourceDom::svgHasDrawableContent($element),
            function (DOMElement $element, array &$fallbacks): void {
                $this->session->fallbackEmitter()->captureInlineSvgFallback($element, $fallbacks);
            },
            function (DOMElement $element): bool {
                if (!$this->svgMaterializer->paintsNothing($element)) return false;
                $this->session->transformationEvidenceState()->recordConversionDiagnostic(array(
                    'code' => 'html_empty_svg_decoration_omitted',
                    'severity' => 'info',
                    'loss_class' => 'native_conversion',
                    'selector' => SourceDom::elementSelector($element),
                    'message' => 'Omitted SVG decoration proven to paint nothing in the captured state.',
                ));
                return true;
            },
            fn (DOMElement $element, array &$fallbacks): ?array => $this->foreignObjectFitTextBlock($element, $fallbacks)
        ), $this->svgMaterializer, new SvgTextRunLowering(
            $this->styleResolver,
            fn (string $value, DOMElement $element): string => $this->styleResolver->resolveCssVariablesInValue($value, $element),
            $this,
            $this->session
        ));
        $inlineContentConverter = new InlineContentElementConverter($this->sourceElementClassifier, $this, $this->richTextMaterializer, new InlineContentElementContext(
            $this,
            $this->runtimeIslands,
            fn (DOMElement $element): ?array => $inlineSvgTextGroups->fromElement($element),
            $this->authorLayoutOwnership->ownsPositioningGeometry(...),
            fn (DOMElement $element, array &$fallbacks): ?array => $this->positionedInlineCarrierBlock($element, $fallbacks),
            fn (DOMElement $element): bool => $this->session->authorSelectorProjectionState()->hasSemanticMarker($element),
            $this->authorLayoutOwnership->richTextMarker(...),
            $this->listStructure,
            $this->emptyVisualRetention->shouldPreserveEmptyVisualElement(...),
            fn (DOMElement $element): array => $this->emptyVisualSpacers->emptyVisualSpacerBlock($element),
            $this->session->rememberNativeDisclosure(...),
            fn (DOMElement $element): bool => $this->runtimeIslands->retainsRuntimeTextLeafInRichText($element)
        ), $this->styleResolver, $this->runtime, $this->sourceBlockAttributeProjector);
        $this->authoredFormControlBlockConverter = new AuthoredFormControlBlockConverter(
            $formControlMetadataBuilder,
            fn (DOMElement $element): array => $this->styleResolver->authoredPresentationDeclarations($element),
            $this->styleResolver,
            $this,
            $this->session,
            $this->runtime,
            fn (DOMElement $element): ?DOMElement => $this->sourceTagProjectedClone($element),
            fn (DOMElement $label): string => $this->richTextMaterializer->content($label, array( 'input', 'select', 'textarea' )),
            $this->richTextStylingHooks->inlineContent(...)
        );
        $this->projectedNavigation = new ProjectedNavigationConverter(
            $this->navigationToggleSuppressor,
            $this->styleResolver,
            $this->session,
            $this,
            $this,
            $this->runtimeIslands,
            fn (DOMElement $element): string => $this->svgMaterializer->restoreSvgCasing($this->svgMaterializer->sanitizeInlineSvgMarkup($element))
        );
        $this->patternContext = $this->createPatternContext();
        $this->interactionFallbackReporter = new InteractionFallbackReporter(
            $formControlMetadataBuilder,
            $this->navigationToggleSuppressor,
            $this->runtimeIslands,
            $this->runtime,
            $this->session,
            $this->sourceElementDescription
        );
        $blockBindings = new BlockBindingBuilder($this->runtime, $this->session->transformationProvenanceState());
        $formFallbackContext = new FormFallbackFindingContext(
            $this->session,
            fn (DOMElement $element): array => $this->runtimeIslands->runtimeDomSelectorsForElement($element),
            $this->sourceElementDescription,
            $blockBindings->binding(...),
            fn (DOMElement $element, string $value): string => $this->styleResolver->resolveCssVariablesInValue($value, $element),
            fn (DOMElement $element): string => $this->svgMaterializer->restoreSvgCasing($this->svgMaterializer->sanitizeInlineSvgMarkup($element))
        );
        $this->commerceReporter = new CommerceFallbackReporter(
            $this->commercePattern,
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
            function (DOMElement $element, string $kind, string $reason, string $capability, array $metadata): void {
                $this->runtimeIslands->recordRuntimeIsland($element, $kind, $reason, $capability, $metadata);
            },
            $this->session
        );
        $this->readableFormControlBlockConverter = new ReadableFormControlBlockConverter(
            $formControlMetadataBuilder,
            $this->authoredFormControlBlockConverter,
            $this->formRuntimeIslandRecorder,
            $this->runtime,
            $this->runtimeIslands,
            $this->styleResolver,
            $this,
            $this->session
        );
        $readableFormBlockBuilder = new ReadableFormBlockBuilder(
            $formControlMetadataBuilder,
            $this->readableFormControlBlockConverter,
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
            fn (DOMElement $element): string => $this->styleResolver->innerHtml($element)
        );
        $formFallbackFindingBuilder = new FormFallbackFindingBuilder($this->session,
            $formFallbackContext,
            $formControlMetadataBuilder,
            $formSuccessPanelMetadataBuilder,
            $pseudoFormAnalyzer
        );
        $this->searchBlockConverter = new SearchBlockConverter($this->styleResolver, $this->session, $this, $this->svgMaterializer, $this->runtimeIslands, $this->createSearchBlockConversionContext(), $formControlMetadataBuilder, $pseudoFormAnalyzer);
        $loginBlockConverter = new LoginBlockConverter(
            fn (DOMElement $element): array => $this->createBlock('core/loginout', array( 'displayLoginAsForm' => true ), array(), $element)
        );
        $formDispatcher = new FormDispatcher(new FormDispatchContext(
            fn (DOMElement $element): ?array => $loginBlockConverter->loginBlockFromForm($element),
            fn (DOMElement $element): ?array => $this->searchBlockConverter->searchBlockFromForm($element),
            fn (DOMElement $element, array &$fallbacks): ?array => $nativeGetFormBlockBuilder->build($element, $fallbacks),
            function (DOMElement $element, array &$fallbacks) use ($formCompositionPlanner): ?array {
                return $formCompositionPlanner->compose($element, $fallbacks);
            },
            fn (DOMElement $element, ?array $readableFormBlock, ?array $bindingBlock = null): array => $formFallbackFindingBuilder->build($element, $readableFormBlock, $bindingBlock, $readableFormBlockBuilder->layoutGraph()),
            function (DOMElement $element, ?array $readableFormBlock): void {
                $this->formRuntimeIslandRecorder->recordForm($element, $readableFormBlock);
            },
            function (DOMElement $element, bool $allowFormEvents, array &$fallbacks) use ($readableFormBlockBuilder): ?array {
                return $readableFormBlockBuilder->build($element, $allowFormEvents, $fallbacks);
            },
            fn (DOMElement $element): bool => $formRuntimeRequirementAnalyzer->requiresPreservation($element),
            fn (DOMElement $element): bool => $pseudoFormAnalyzer->isProviderPseudoForm($element)
        ), $this);
        $this->buttonLinkDispatcher = new ButtonLinkDispatcher($this->sourceElementClassifier, $this->createButtonLinkDispatchContext());
        $buttonConverter = new ButtonElementConverter($this->sourceElementClassifier, $this, $this->styleResolver, new ButtonElementContext(
            fn (DOMElement $element): bool => $this->searchBlockConverter->isReplacedSearchClusterControl($element),
            $this,
            fn (DOMElement $element): ?array => $this->buttonLinkDispatcher->convertButton($element),
            $this->runtimeIslands,
            fn (DOMElement $element): ?array => $this->runtimeAuthoredButton($element),
            fn (DOMElement $element, array &$fallbacks): ?array => $this->convertButtonWrapperGroup($element, $fallbacks),
            fn (DOMElement $image): array => $this->imageDimensions->minimumPixelSize($image)
        ));
        $detailsConverter = new DetailsElementConverter(new DetailsElementContext(
            fn (DOMElement $element): ?DOMElement => $capturedDisclosureDialogs->fromDetails($element),
            function (DOMElement $element, array &$fallbacks): array {
                return $this->capturedDialogBlock($element, $fallbacks);
            },
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
            $this->textLeafConverter,
            $tableConverters,
            $specializedElementConverters,
            $runtimeResourceConverter,
        ));
        $figureConverter = new FigureElementConverter($this, $this->styleResolver, new FigureElementContext(
            function (DOMElement $element, array &$fallbacks) use ($mediaGalleries): ?array {
                return $mediaGalleries->mediaGalleryBlockFromElement($element, $fallbacks);
            },
            $this,
            $this->imageLowering->figureLinkedMediaAnchor(...),
            $this->imageLowering->convertPicture(...),
            fn (DOMElement $image, ?DOMElement $figure = null, ?DOMElement $picture = null, ?DOMElement $link = null): ?array => $this->imageLowering->convertImage($image, $figure, $picture, $link),
            fn (DOMElement $figure, string $tagName): ?DOMElement => SourceDom::figureMediaElement($figure, $tagName),
            function (DOMElement $figure, array &$fallbacks): ?array {
                return $this->convertFigureGeneric($figure, $fallbacks);
            },
            fn (string $html): bool => '' !== trim($this->runtime->stripAllTags($html))
        ));
        $quoteConverter = new QuoteElementConverter($this);
        $listConverter = new ListElementConverter($this->styleResolver, $this, new ListElementContext(
            $this,
            $this->session->runtimeBehaviorState()->rememberAccordionDisclosureRoot(...),
            fn (DOMElement $element): bool => $structuredCardLists->isStructuredCardList($element),
            function (DOMElement $element, array &$fallbacks) use ($structuredCardLists): ?array {
                return $structuredCardLists->decomposeStructuredCardList($element, $fallbacks);
            },
            fn (DOMElement $element): bool => $this->listStructure->listContainsStructuralItemContent($element),
            $listItemLowering->decomposeStructuralList(...),
            function (DOMElement $element, array &$fallbacks) use ($listItemLowering): array {
                return $listItemLowering->listItems($element, $fallbacks);
            },
            $this->authorLayoutOwnership
        ));
        $descriptionListGenerator = new DescriptionListBlockGenerator(
            $this->sourceElementClassifier,
            $this->session
        );
        $this->authoredCarouselGenerator = new AuthoredCarouselBlockGenerator(
            $this->sourceElementClassifier,
            $this->styleResolver,
            $this,
            $this->blockFactory,
            $this->runtime,
            $this->session,
            // Carousel slides are editable native images inside authored
            // holders; the carousel owns slide presentation.
            fn (DOMElement $element): ?array => $this->imageLowering->convertImage($element, nativeImage: true),
            $this
        );
        $visualIframeGenerator = new VisualIframeBlockGenerator(
            $this->sourceElementClassifier,
            $this->styleResolver,
            $this,
            $this->runtimeIslands,
            $this->session,
            fn (DOMElement $element): bool => $this->inertElements->isInertRuntimeMediaPlaceholder($element),
            fn (DOMElement $element): bool => $this->sourceVisibility->startsHidden($element),
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
            fn (DOMElement $element): ?array => $descriptionListGenerator->convert($element),
            fn (DOMElement $element): ?array => $metadataGrid->convert($element),
            fn (DOMElement $element): array => $definitionLists->definitionListItems($element),
            $this->authorLayoutOwnership,
            $this,
            fn (string $html): bool => '' !== trim($this->runtime->stripAllTags($html))
        ));
        $structuralContentConverters = new OrderedElementConverterRegistry(array(
            $inlineContentConverter,
            $listConverter,
            $descriptionListConverter,
            $quoteConverter,
            $figureConverter,
        ));
        $inlineTextWrappers = new Elements\InlineTextWrapperLowering(
            $this,
            $this,
            $this->session,
            $this->sourceElementClassifier,
            $this->styleResolver,
            $this->richTextMaterializer,
            $this->richTextStylingHooks,
            $this->structuredInlineItemLowering,
            $this->authorLayoutOwnership,
            $this->emptyVisualRetention,
            $this->runtime
        );
        $this->flowContainerConverter = new FlowContainerElementConverter($this->sourceElementClassifier, $this, $this->styleResolver, new FlowContainerElementContext(
            runtimeAppShellBlock: function (DOMElement $element, array &$fallbacks): ?array {
                if ( ! $this->runtimeIslands->shouldPreserveRuntimeAppShell($element) ) {
                    return null;
                }
                $targets = $this->runtimeIslands->runtimeTargetsInSubtree($element, 8);
                $this->runtimeIslands->recordRuntimeIsland($element, 'app_shell', 'runtime_app_shell', 'client_script_execution', array(
                    'events' => SourceDom::eventMetadata($element),
                    'target_count' => count($targets),
                    'targets' => $targets,
                    'app_shell_signals' => $this->runtimeIslands->runtimeAppShellSignals($element),
                    'required_scripts' => $this->session->fallbackEmitter()->requiredScriptsForElement($element),
                ));
                return $this->htmlPreservationBlock($element);
            },
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
            $this,
            $this->generatedComponents,
            $this->emptyVisualRetention,
            $this->session,
            $this->runtimeIslands,
            $metadataGrid,
            $mediaGalleries,
            $namePriceRows,
            $inlineFlows,
            $this->searchBlockConverter,
            $this->readableFormControlBlockConverter,
            $this->authoredMarqueeRecognizer,
            $this->authoredCarouselGenerator,
            $this->emptyVisualSpacers
        );
        $mediaDispatchConverter = new MediaDispatchElementConverter(new MediaDispatchElementContext(
            $this,
            fn (DOMElement $element): ?array => $this->imageLowering->convertImage($element),
            $this->imageLowering->convertPicture(...),
            fn (DOMElement $element, array &$fallbacks): ?array => $visualIframeGenerator->convert($element, $fallbacks),
            fn (DOMElement $element): ?array => $this->mediaLowering->fromElement($element),
            fn (DOMElement $element): ?array => $this->anchors->imageBlockFromAnchor($element)
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
            new PhrasingSvgConverter($this->svgMaterializer, $this, $this->inertElements),
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
                function (): string {
                    $className = CapturedSelectableSetConverter::VISUALLY_HIDDEN_TABLIST_CLASS;
                    $this->session->layoutGeometryState()->registerRule($className, CapturedSelectableSetConverter::visuallyHiddenTabListCss($className));

                    return $className;
                },
                function (bool $listLast): string {
                    $this->session->layoutGeometryState()->registerRule(CapturedSelectableSetConverter::FLOW_CLASS, CapturedSelectableSetConverter::flowCss());
                    if (! $listLast) {
                        return CapturedSelectableSetConverter::FLOW_CLASS;
                    }
                    $this->session->layoutGeometryState()->registerRule(CapturedSelectableSetConverter::FLOW_LIST_LAST_CLASS, CapturedSelectableSetConverter::flowListLastCss());

                    return CapturedSelectableSetConverter::FLOW_CLASS . ' ' . CapturedSelectableSetConverter::FLOW_LIST_LAST_CLASS;
                },
                $this->session
            ),
            new ScrollStateConverter(
                $this->session,
                $this,
                $this->styleResolver
            ),
            new ThemeToggleConverter(
                $this->svgMaterializer,
                $this->session,
                fn (DOMElement $element): string => $this->svgMaterializer->sanitizeInlineSvgMarkup($element),
                function (DOMElement $element): array {
                    $presentation = $this->styleResolver->presentationAttributes($element);
                    $inline = $this->styleResolver->cssDeclarations(SourceDom::attr($element, 'style'));
                    $mapped = $this->styleResolver->styleAttributeMapper()->map($inline);
                    $css = (string) ($this->styleResolver->styleAttributeMapper()->serialize($mapped['style'] ?? array())['style'] ?? '');
                    $declarations = $this->styleResolver->cssDeclarations($css);
                    foreach ( array('display', 'flex-direction', 'flex-wrap', 'justify-content', 'align-items', 'gap', 'row-gap', 'column-gap', 'grid-template-columns', 'grid-template-rows') as $layoutProperty ) {
                        if ( isset($inline[$layoutProperty]) ) $declarations[$layoutProperty] = $inline[$layoutProperty];
                    }
                    foreach ( array('width', 'min-width', 'max-width', 'height', 'min-height', 'max-height') as $dimension ) {
                        $value = trim((string) ($inline[$dimension] ?? ''));
                        $safeLength = CssValueInspector::isAbsoluteLength($value)
                            || 1 === preg_match('/^[+-]?(?:\d+(?:\.\d+)?|\.\d+)%$/', $value)
                            || (preg_match('/^(?:calc|min|max|clamp|var)\s*\(/i', $value) && CssValueSplitter::hasBalancedParens($value));
                        if ( '' !== $value && $safeLength && ! preg_match('/(?:url\s*\(|expression\s*\(|javascript\s*:)/i', $value) ) $declarations[$dimension] = $value;
                    }
                    return array(
                        'className' => (string) ($presentation['className'] ?? ''),
                        'style' => $declarations,
                        'layout' => is_array($presentation['layout'] ?? null) ? $presentation['layout'] : array(),
                    );
                }
            ),
            new CopyToClipboardConverter($this->session),
            $formDispatcher,
            $this->searchBlockConverter,
            $this->runtimeIslands,
            $this->styleResolver,
            $this,
            $this->richTextMaterializer,
            $this->runtime,
            $this->sourceElementClassifier,
            $this->session->transformationProvenanceState(),
            $this,
            fn (DOMElement $element, array &$fallbacks): ?array => $this->linkCarriers->linkedRuntimeControlBlockFromAnchor($element, $fallbacks),
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
            $this->richTextConverter,
            new Elements\TagScopedElementConverter(array( 'address' ), $this->textLeafConverter),
            new Elements\ShellLandmarkConverter($this->session, $this->styleResolver, $this, $this),
            $this->searchBlockConverter,
            $this->customImageHosts,
            $customVideoHosts,
            new Elements\ImagelessPictureConverter($this->sourceElementClassifier, $this->flowContainerConverter),
            $mediaDispatchConverter,
            new Elements\FocusRevealedSkipLinkConverter($this->styleResolver, $this->session),
            new Elements\ReducedModeButtonConverter($this->session, $this->styleResolver, $this->sourceElementClassifier, $this->runtime, $this),
            new Elements\AnchorElementConverter($this->buttonLinkDispatcher, $this->emptyVisualSpacers, $this->emptyVisualRetention, $this->unsupportedRecorder),
            $structuralContentConverters,
            new Elements\LabelElementConverter($this->readableFormControlBlockConverter, $this->flowContainerConverter),
            // These delegates own disjoint tags; their declaration order is the
            // pre-existing precedence before form, navigation, and flow dispatch.
            $postStructuralElementConverters,
            new Elements\NavigationElementConverter($this, $this->session, $this->sourceElementClassifier, $this->authorLayoutOwnership, $this->richTextMaterializer, $this->runtime, $this->sourceBlockAttributeProjector, $this->styleResolver, $this),
            $this->flowContainerConverter,
            new Elements\AuthoredCarouselElementConverter($this->authoredCarouselGenerator, $this->runtimeIslands, $this->styleResolver, $this, $this),
            new Elements\NavigationHostConverter($this, $this->layoutShells, $this),

            $this->generatedComponents,
            new Elements\StandaloneFormControlConverter($this->readableFormControlBlockConverter, $this->formRuntimeIslandRecorder),
            $this->linkCarriers,
            new Elements\RenderNeutralFlowWrapperConverter($this->renderNeutral, $this->flowContainerConverter),
            $customBlockGenerator,
            $this->inertElements,
            new Elements\OrphanListItemConverter($this->runtimeIslands, $this->sourceElementClassifier, $this->richTextMaterializer, $this, $this->layoutShells, $this),
            new Elements\ScriptOwnedCheckoutRegionConverter($this->commercePattern, $this),
            new Elements\TransparentUnknownElementConverter($this->renderNeutral, $this->session, $this->generatedComponents, $this->flowContainerConverter),
            new Elements\CustomElementRuntimeConverter($this->session, $this->styleResolver, $this->sourceElementDescription, $this->runtimeIslands, $this->generatedComponents, $this),
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
            function (array $cssParts, string $source, string $placement, string $pathPrefix, string $target = 'both'): void {
                $this->stylesheetAssetStage->generated($cssParts, $source, $placement, $pathPrefix, $target);
            }
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
            fn (DOMElement $element): array => $this->runtimeAuthoredButton($element)
        );
    }

    /**
     * Collaborator surface for {@see TableElementConverter}.
     */
    private function createTableElementContext(): TableElementContext
    {
        return new TableElementContext(
            $this->tableClassificationPolicy,
            function (DOMElement $element, array &$fallbacks): ?array {
                return $this->layoutTables->nestedLayoutTableColumnsBlock($element, $fallbacks);
            },
            function (DOMElement $element, array &$fallbacks): ?array {
                return $this->layoutTables->singleColumnLayoutTableGroupBlock($element, $fallbacks);
            },
            function (DOMElement $element, array &$fallbacks): ?array {
                return $this->layoutTables->mediaLayoutTableColumnsBlock($element, $fallbacks);
            },
            fn (DOMElement $element): array => $this->tableAttributesBuilder->tableAttributes($element)
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
     * Records a disclosure folded into a native `core/details`. Capture-owned
     * local-disclosure panels it absorbed no longer exist as DOM targets, so the
     * capture runtime selectors that addressed them are superseded by the block.
     */
    /**
     * Collaborator surface for {@see RichTextElementConverter}.
     */
    private function createRichTextElementContext(): RichTextElementContext
    {
        return new RichTextElementContext(
            fn (DOMElement $element): ?array => $this->authoredMarqueeRecognizer->authoredMarqueeBlock($element),
            $this->emptyVisualRetention->hasEmptyVisualInlineChild(...),
            $this->authorLayoutOwnership->hasBoxChromeWrapperStyling(...),
            $this->runtimeIslands,
            fn (DOMElement $element): ?array => $this->paragraphImages->unwrappedParagraphImageBlock($element),
            function (DOMElement $element, array &$fallbacks): ?array {
                return $this->anchors->mixedMediaLinkGroupFromParagraph($element, $fallbacks);
            },
            function (DOMElement $element): ?array {
                return $this->linkedIconTextRows->fromParagraph($element);
            },
            fn (string $text): array => $this->convertText($text),
            $this,
            function (DOMElement $element, array &$fallbacks): ?array {
                return $this->emptyInlineGeometry->emptyInlineGeometryBlock($element, $fallbacks);
            },
            function (DOMElement $element, array &$fallbacks): ?array {
                $block = $this->patterns($element, $fallbacks, array( DetailsPattern::class ));
                if ( null !== $block ) {
                    $this->session->rememberNativeDisclosure($element);
                }
                return $block;
            },
            fn (DOMElement $element): ?array => $this->svgMaterializer->decorativeTextSvgBlock($element),
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
        return $this->transformTimed($html, $options, new \Automattic\BlocksEngine\PhpTransformer\Support\StepTimer('transform.source_preparation'));
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
        $this->documentAuthorCss = '';
        $sharedStylesheetPaths = TransformOptions::sharedStylesheetPaths($options);
        $staticCss = (string) ($options['static_css'] ?? '');
        $styleAnalysis = $this->stylesheetAnalysisComposer->composedStyleAnalysis(
            $this->stylesheetAnalysisComposer->stylesheetPayloads($html, $staticCss, $options)
        );
        $this->session->sourceStyleResolutionState()->installStylesheetAnalysis(StaticClassPromotions::detect($html), $styleAnalysis);
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
            SourceDom::normalizeHtml5VoidElements($this->documentEnvelope->documentBodyHtml($this->documentEnvelope->normalizeExplicitPlaintextElements($html)))
        );
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded   = $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $normalizedHtml . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $resultComposer = new HtmlResultComposer();
        $selectorCache = $this->session->sourceStyleResolutionState()->selectorMatchCache;

        if ( ! $loaded ) {
            return $timer->result($resultComposer->parseFailed(
                $html,
                $this->session->transformationProvenanceState()->fallback(),
                $provenance,
                $context,
                $startedAt,
                $selectorCache
            ));
        }

        $body = $document->getElementsByTagName('body')->item(0);
        if ( ! $body instanceof DOMElement ) {
            return $timer->result($resultComposer->emptyBody($html, $provenance, $context, $startedAt, $selectorCache));
        }
        $timer->start('transform.source_normalization');
        SourceDom::removeIntegerKeyAttributes($body);
        SourceDom::unwrapLineBreakOnlyLinks($body);
        // Resolve before dispatch: controls, rich text, and preserved markup
        // serialize source images without passing through native image lowering.
        $this->imageUrls->resolveSourceImages($body);
        \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SvgDefinitionClips::rewriteInlineStyles($body);
        CapturedNavigationPanels::restoreNative($body);
        CapturedNavigationPanels::restoreRestingStates($body);
        // Capture evidence, not content: read before anything serializes the source.
        $this->session->installCapturedGlyphColors(CapturedGlyphColor::detach($body));
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
        OwnSaveShapeInverter::invert($body, function (DOMElement $element): bool {
            foreach ( $this->styleResolver->styleRuleCandidates($element, 'static-conditional') as $rule ) {
                if ( $this->styleResolver->matchesCssSelector($element, (string) $rule['selector']) ) {
                    return true;
                }
            }
            return false;
        });
        $this->navigationBlockNormalizer->hydrateDuplicateSubmenus($body);
        DeclarativeCounterMaterializer::materialize($body, (string) ($options['declarative_state_html'] ?? ''));
        // Remove wrapper-convention custom elements before author selectors are
        // prepared, so style projection and component promotion both observe the
        // content rather than the source's wrapping convention.
        $this->documentAuthorCss = $this->stylesheetAnalysisComposer->combinedAuthorStylesheet($html, $staticCss);
        $this->retainedCustomWrapperHooks = $this->wrapperConventions->unwrapConventionWrappers($body, $this->documentAuthorCss);
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

        $timer->start('transform.conversion');
        $fallbacks = array_map(
            fn (array $finding): array => FallbackDiagnostic::build($finding, $this->session->transformationProvenanceState()->fallback()),
            $paymentWidgetFindings
        );
        \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\DocumentReferenceIndex::install($document, $this->documentAuthorCss);
        \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\InteractiveSurface::indexDocument($document);
        try {
            $interactionCandidates = $this->interactionFallbackReporter->interactionCandidates($body);
            $this->navigationToggleSuppressor->collectProjectedNavigationRelationships($body);
            $this->navigationToggleSuppressor->collectSupersededNavToggleSelectors($body);
            $this->navigationToggleSuppressor->collectSupersededCapturedSubmenuSelectors($body);
            $this->generatedComponentCandidates->collectGeneratedComponentCandidates($body);
            $shellArtifacts = !array_key_exists('extract_global_shell', $options) || !empty($options['extract_global_shell']) ? $this->globalShellArtifacts->globalShellArtifacts($body, (string) ($options['source'] ?? 'html'), false, max(0, (int) ($options['global_shell_context_depth'] ?? 0))) : array();
            $blocks = $this->navigationBlockNormalizer->normalize($this->convertChildren($body, $fallbacks, true), $this->session->transformationProvenanceState()->sources(), $this->session->transformationProvenanceState()->sourceBaseHiddenStates(), $this->session->transformationProvenanceState()->sourceCollapsedWrapperHiddenStates());
        } finally {
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
        $authorStylesheetProjections = $authorStylesheets->projections();
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
            . ( '' === $authorStylesheets->contentBoxCss() ? '' : "\n\n" . $authorStylesheets->contentBoxCss() )
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
        $this->styleResolver->recordSourceSelectorMatchWork();
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
        $designUsage = (new DesignUsageCollector($this->styleResolver))->collect($body, $buttonElements);
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

    /**
     * Author CSS visible to the document: declared stylesheets plus the styles
     * the source embeds inline.
     */
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
                    $blocks = array_merge($blocks, $this->convertText($text));
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
                && in_array('wp-site-blocks', preg_split('/\s+/', trim($child->getAttribute('class'))) ?: array(), true)
                && $parent instanceof DOMElement
                && ('body' === strtolower($parent->tagName) || SourceDom::isDocumentVariantRoot($parent))
                && SourceDom::hasWordPressTemplateParts($child)
            ) {
                $blocks = array_merge($blocks, $this->convertChildren($child, $fallbacks, $captureUnsupported, $preserveLinkContainers));
                continue;
            }
            $fallbackOffset = count($fallbacks);
            $block = $preserveLinkContainers && 'div' === strtolower($child->tagName) && 0 === $child->attributes->length
                ? $this->layoutShells->blockForElements(array($child), $this->convertChildren($child, $fallbacks, $captureUnsupported, true), $child)
                : $this->convertElement($child, $fallbacks, $captureUnsupported);
            if ( null !== $block ) {
                // Retain the public source boundary rather than private metadata.
                if (in_array('wp-block-post-content', preg_split('/\s+/', trim($child->getAttribute('class'))) ?: array(), true)) {
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

    /**
     * A break-only anonymous inline box between block siblings occupies one line
     * per break. Edge breaks and breaks sharing inline content keep their usual
     * conversion. Only the first break in a bounded run emits its native blocks.
     *
     * @return list<array<string, mixed>>|null
     */
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

    private function createPatternContext(): PatternContext
    {
        return new PatternContext(
            $this->styleResolver,
            $this,
            new NavigationPatternContext(
                $this->styleResolver,
                $this->navigationStyleProjector,
                $this->navigationToggleSuppressor,
                $this->runtimeIslands,
                $this->session->sourceTargetProjectionState(),
                $this->session,
                $this->svgMaterializer,
                $this->projectedNavigation
            ),
            new MediaPatternContext($this->styleResolver, $this->imageUrls, $this->imageDimensions, $this->session),
            new ColumnsPatternContext($this->styleResolver),
            new MarkupPatternContext(
                $this->session
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
                fn (DOMElement $element): string => SvgMaterializer::ensureSvgImageNamespace($this->svgMaterializer->restoreSvgCasing($this->svgMaterializer->sanitizeInlineSvgMarkup($element)))
            ),
            $this->styleResolver,
            $this->session,
            fn (DOMElement $sourceElement): string => $this->richTextStylingHooks->inlineContent($sourceElement)
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
                session: $this->session,
                publishesSubjects: false
            ),
            markupContext: new MarkupPatternContext(
                $this->session
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
                 fn (DOMElement $element): string => SvgMaterializer::ensureSvgImageNamespace($this->svgMaterializer->restoreSvgCasing($this->svgMaterializer->sanitizeInlineSvgMarkup($element)))
             ),
             styles: $this->styleResolver
         );
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function convertElement(DOMElement $element, array &$fallbacks, bool $captureUnsupported = false): ?array
    {
        $tagName = strtolower($element->tagName);

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
    private function convertText(string $text): array
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

    /**
     * @param array<string, mixed> $attrs
     * @param array<int, array<string, mixed>> $innerBlocks
     * @return array<string, mixed>
     */
    public function createBlock(string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null, ?DOMElement $logicalSourceElement = null): array
    {
        if ('core/social-link' === $name && $sourceElement instanceof DOMElement) {
            $anchor = 'a' === strtolower($sourceElement->tagName) ? $sourceElement : $sourceElement->getElementsByTagName('a')->item(0);
            if ($anchor instanceof DOMElement) $this->session->authorSelectorProjectionState()->markSocialAnchor($anchor);
        }
        if ( 'core/cover' === $name && $sourceElement instanceof DOMElement && in_array(CoverPattern::CONSUMED_BACKGROUND_CLASS, preg_split('/\s+/', (string) ($attrs['className'] ?? '')) ?: array(), true) ) {
            $this->session->authorSelectorProjectionState()->markConsumedCoverBackground($sourceElement->getNodePath() ?? '');
        }
        if ('core/social-link' === $name && $sourceElement instanceof DOMElement) {
            $anchor = 'a' === strtolower($sourceElement->tagName) ? $sourceElement : $sourceElement->getElementsByTagName('a')->item(0);
            if ($anchor instanceof DOMElement) $attrs = (new SocialSourceStyleProjector($this->styleResolver))->project($attrs, $anchor, $this->session->authorStyleAnalysis(), $this->session->sourceTargetProjectionState(), !str_contains((string) ($attrs['className'] ?? ''), 'blocks-engine-social-source-item'));
        }
        // Control boxes already transport the exact source attributes through
        // the core group's runtime metadata. A shell would discard that core
        // identity without preserving any additional DOM contract.
        if ( 'core/group' === $name && ! isset($attrs['metadata']['blocksEngineControlBox']) && $sourceElement instanceof DOMElement && $this->emptyVisualRetention->preservesScriptStateWrapper($sourceElement) ) {
            return $this->layoutShells->blockForElements(array( $sourceElement ), $innerBlocks, $sourceElement);
        }
        if ( $sourceElement instanceof DOMElement
            && in_array($name, array( 'core/paragraph', 'core/heading', 'core/list-item' ), true)
            && $this->richTextMaterializer->hasStructuralHtml((string) ($attrs['content'] ?? ''))
        ) {
            $structuralFallbacks = array();
            $children = $this->convertChildren($sourceElement, $structuralFallbacks, true);
            if ( array() !== $children ) {
                // Core headings cannot store a block-level decoration in RichText.
                // Keep the decorated wrapper but retain its one text child as an
                // editable heading rather than silently turning it into a paragraph.
                // Authored paragraphs are flow content, not a decorated
                // heading's synthetic text run; keep them as paragraphs.
                if ( 'core/heading' === $name && 0 === $sourceElement->getElementsByTagName('p')->length ) {
                    $textChildren = array_keys(array_filter($children, static fn (array $child): bool =>
                        'core/paragraph' === ($child['blockName'] ?? null)
                        && '' !== trim(strip_tags((string) ($child['attrs']['content'] ?? '')))
                    ));
                    if ( 1 === count($textChildren) ) {
                        $index = $textChildren[0];
                        $headingText = html_entity_decode(strip_tags((string) $children[$index]['attrs']['content']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        $normalizeText = static fn (string $text): string => trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
                        if ( $normalizeText($headingText) === $normalizeText((string) $sourceElement->textContent) ) {
                            $children[$index] = $this->createBlock('core/heading', array(
                                'content' => $children[$index]['attrs']['content'],
                                'level' => $attrs['level'] ?? 2,
                                // The decorated wrapper keeps the source heading's
                                // classes, so its typography is the source's. The
                                // inner heading inherits all of it instead of adding
                                // the destination's heading defaults (bold, size).
                                'style' => array(
                                    'typography' => array_fill_keys(array( 'fontSize', 'fontWeight', 'lineHeight', 'letterSpacing', 'textTransform', 'fontStyle' ), 'inherit'),
                                    'spacing' => array('margin' => array('top' => '0', 'bottom' => '0')),
               ),
                            ));
                        }
                    }
                }
                $containerAttrs = $this->styleResolver->presentationAttributes($sourceElement);
                if ('core/heading' === $name) {
                    // Keep baked/responsive heading typography, not just the
                    // generic presentation, when RichText becomes a container.
                    $containerAttrs = $attrs;
                    unset($containerAttrs['content'], $containerAttrs['level']);
                    $containerAttrs = $this->styleResolver->headingContainerAttributes($sourceElement, $containerAttrs);
                }
                return $this->createBlock('core/group', $containerAttrs, $children, $sourceElement, $logicalSourceElement);
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
                    'core/image' === $name
                        && 'figure' !== $sourceTagName
                        && $this->syntheticImageFigureFlow->syntheticImageFigureFollowsInlineFlow($sourceElement)
                ),
                $this->sourceBlockAttributeProjectionContext()
            );
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
                array() === $innerBlocks && 'core/group' === $name
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
                array_merge(is_array($innerBlock['attrs'] ?? null) ? $innerBlock['attrs'] : array(), array( 'align' => $alignment ))
            );
        }

        return $innerBlocks;
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function positionedInlineCarrierBlock(DOMElement $element, array &$fallbacks): ?array
    {
        if ( $this->authorLayoutOwnership->positionedCarrierHasStructuredContent($element) ) {
            $children = $this->positionedInlineCarrierChildren($element, $fallbacks);
            return array() === $children
                ? null
                : $this->createBlock('core/group', $this->styleResolver->positionedInlineCarrierAttributes($element), $children, $element);
        }

        $content = $this->richTextMaterializer->content($element);
        if ( '' === trim($this->runtime->stripAllTags($content)) ) {
            return null;
        }

        return $this->createBlock('core/group', $this->styleResolver->positionedInlineCarrierAttributes($element), array(
            $this->createBlock('core/paragraph', array(
                'content' => $content,
                'className' => self::SYNTHETIC_PARAGRAPH_CLASS,
            )),
        ), $element);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<int, array<string, mixed>>
     */
    private function positionedInlineCarrierChildren(DOMElement $element, array &$fallbacks): array
    {
        $blocks = array();
        foreach ( $element->childNodes as $child ) {
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
            if ( FormControlClassifier::isControlElement($child) ) {
                $blocks[] = $this->htmlPreservationBlock($child);
                continue;
            }
            $block = $this->convertElement($child, $fallbacks, true);
            if ( null !== $block ) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function authorLayoutBlockFromElement(DOMElement $element, array &$fallbacks): array
    {
        if ( ! $this->emptyVisualRetention->preservesScriptStateWrapper($element) ) {
            $paragraph = $this->textOnlyLayoutDivParagraph($element);
            if ( null !== $paragraph ) {
                return $paragraph;
            }
        }
        $children = $this->convertChildren($element, $fallbacks, true);
        if ( $this->emptyVisualRetention->preservesScriptStateWrapper($element) ) {
            return $this->layoutShells->blockForElements(array( $element ), $children, $element);
        }
        $isAuthorOwnedLayout = $this->authorLayoutOwnership->isAuthorOwnedLayout($element);
        $sourceChildCount = $isAuthorOwnedLayout ? SourceDom::childElementCount($element) : 0;
        $sourceTags = $isAuthorOwnedLayout ? SourceDom::directChildTags($element) : array();
        $blockTags = $isAuthorOwnedLayout ? $this->directBlockTags($children) : array();
        $topologyChanged = $isAuthorOwnedLayout
            && 0 < $sourceChildCount
            && ( $sourceChildCount !== count($children) || $sourceTags !== $blockTags );
        if ( 1 === count($children) && ! $topologyChanged ) {
            $coalesced = $this->wrapperCoalescer->coalescedSingleGroupWrapper($element, $children[0]);
            if ( null !== $coalesced ) {
                return $coalesced;
            }
        }
        $attrs = $this->authorLayoutOwnership->cssOwnedGroupAttributes($element, false, $topologyChanged);
        if ( 0 === count($children) && '' === SourceDom::renderedTextContent($element) ) {
            // Empty source layout containers are visual boundaries, not new
            // authoring targets. Mark them so the editor suppresses Core's
            // layout picker without changing their saved frontend box.
            $attrs['className'] = SourceDom::mergeClassNames(
                (string) ($attrs['className'] ?? ''),
                self::EMPTY_VISUAL_GROUP_CLASS
            );
            $this->session->runtimeBehaviorState()->markEmptyVisualGroupGenerated();
            // An inline background paint is the reason an empty boundary box
            // exists. The standard carrier declines backgrounds for childless
            // elements because the flow-container path lowers those to a
            // background image block; this path keeps the box itself, so
            // restate the paint on a dedicated carrier.
            $attrs['className'] = SourceDom::mergeClassNames(
                (string) $attrs['className'],
                $this->styleResolver->emptyElementBackgroundCarrierClassName($element)
            );
        }
        $block = $this->createBlock('core/group', $attrs, $children, $element);
        if ( $isAuthorOwnedLayout ) {
            $this->session->transformationEvidenceState()->recordAuthorLayoutTopology(
                SourceDom::elementSelector($element),
                $sourceChildCount,
                count($children),
                $sourceTags,
                $blockTags,
                $block['_source_provenance_id'] ?? null
            );
        }
        if ($topologyChanged && $this->styleResolver->hasTopologyUnsafeFixedHeight($element)) {
            $this->session->layoutGeometryState()->removeTopologyUnsafeFixedHeightRules(
                $this->styleResolver->geometryStructuralPath($element)
            );
        }
        return $block;
    }

    /** @param array<int, array<string, mixed>> $blocks @return list<string> */
    private function directBlockTags(array $blocks): array
    {
        $tags = array();
        foreach ($blocks as $block) {
            $name = $block['blockName'] ?? '';
            $attrs = $block['attrs'] ?? array();
            // A lowered paragraph is its source div's own box; author tag
            // selectors reach it through the source-tag projection.
            if ( 'core/paragraph' === $name && in_array(self::LOWERED_PARAGRAPH_CLASS, preg_split('/\s+/', (string) ($attrs['className'] ?? '')) ?: array(), true) ) {
                $tags[] = 'div';
                continue;
            }
            $tags[] = $this->session->requireGeneratedBlockRegistry()->blockName(AuthorLayoutBlockGenerator::LOCAL_NAME) === $name ? (string) ($attrs['tagName'] ?? 'div') : ('core/group' === $name ? (string) ($attrs['tagName'] ?? 'div') : ('core/image' === $name ? 'img' : ('core/paragraph' === $name ? 'p' : ('core/heading' === $name ? 'h' . (string) ($attrs['level'] ?? 2) : ''))));
        }
        return $tags;
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

    /** @return list<array{key: string, selector: string, parsed: array<string, mixed>, direct_child_parsed: array<string, mixed>, declarations: array<string, string>, rule_order: int}> */

    /**
     * Lower a foreignObject-only "fit text" SVG to the native text block its
     * inner element renders as (`<svg viewBox="..."><foreignObject width="100%"
     * height="100%"><p>Label</p></foreignObject></svg>`). The SVG is a sizing
     * box that paints nothing and survives sanitization as an empty graphic, so
     * it used to fall back as metadata that rendered nothing. The inner p,
     * heading, or phrasing-only div/span converts through the ordinary
     * rich-text path inside a core/group that carries the SVG's own class and
     * presentation: author rules target the text as a descendant of that class
     * (`.fit p`), so merging the class onto the text block itself would sever
     * every one of them. The group keeps the source element relationship — the
     * authored `.fit` sizing applies to the box fit text measures and `.fit p`
     * still reaches the text. Core's fit-text support (`fitText: true`) re-fits
     * the line to that container. The emitted markup must reproduce core's
     * canonical serialization exactly: the `className` attribute stays clean and
     * `has-fit-text` lands only in the saved element's class list — core derives
     * it in `save()` whenever `fitText` is true (BlockFactory's supports-derived
     * class path); a saved markup without it fails the editor's validation and a
     * paragraph deprecation silently migrates the block, dropping `fitText`.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function foreignObjectFitTextBlock(DOMElement $svg, array &$fallbacks): ?array
    {
        $text = SourceDom::foreignObjectSoleTextElement($svg);
        if ( null === $text ) {
            return null;
        }
        $tag = strtolower($text->tagName);
        $outcome = $this->richTextConverter->convert($text, in_array($tag, array( 'p', 'div', 'span' ), true) ? 'p' : $tag, $fallbacks);
        $block = $outcome->block;
        if ( null === $block || ! in_array($block['blockName'] ?? '', array( 'core/paragraph', 'core/heading' ), true) ) {
            return null;
        }

        $attrs = $block['attrs'];
        $attrs['fitText'] = true;

        return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($svg), array($this->blockFactory->rebuild($block, $attrs)), $svg);
    }

    /**
     * A layout `div` that holds only text is one box in the source: its text is
     * an anonymous box laid out by the div's own display. A paragraph that
     * carries the div's classes and presentation is that same box, so the text
     * keeps its source parent instead of gaining a group plus an added
     * paragraph. The lowered-paragraph reset removes only the UA paragraph
     * margins, below every author rule.
     *
     * @return array<string, mixed>|null
     */
    private function textOnlyLayoutDivParagraph(DOMElement $element): ?array
    {
        if ( 'div' !== strtolower($element->tagName) || 0 !== SourceDom::childElementCount($element) || SourceDom::documentVariantRoot($element) === $element ) {
            return null;
        }
        $content = $this->richTextMaterializer->content($element);
        if ( '' === trim($this->runtime->stripAllTags($content)) || $this->richTextMaterializer->requiresHtmlFallback($content) ) {
            return null;
        }
        $attrs = $this->styleResolver->presentationAttributes($element);
        $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), self::LOWERED_PARAGRAPH_CLASS);
        $attrs['content'] = $content;

        return $this->createBlock('core/paragraph', $attrs, array(), $element);
    }

    private function createCommercePattern(): CommerceStructureRecognizer
    {
        return new CommerceStructureRecognizer(
            $this->sourceElementClassifier,
            fn (DOMElement $element): ?array => $this->svgMaterializer->inlineSvgImageReference($element)
        );
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

        $caption = SourceDom::firstChildElement($figure, 'figcaption');
        if ( $caption instanceof DOMElement ) {
            $captionHtml = $this->styleResolver->innerHtml($caption);
            if ( '' !== trim($this->runtime->stripAllTags($captionHtml)) ) {
                $children[] = $this->createBlock('core/paragraph', array_merge($this->styleResolver->presentationAttributes($caption), array( 'content' => $captionHtml )), array(), $caption);
            } elseif ( $this->emptyVisualRetention->shouldPreserveEmptyVisualElement($caption) ) {
                // An empty caption can still hold space (padding, border,
                // height) that the content after its figure depends on.
                $children[] = $this->emptyVisualSpacers->emptyVisualSpacerBlock($caption);
            }
        }

        if ( array() === $children ) {
            if ( $this->emptyElementFootprint->shouldPreserveEmptyVisualFigure($figure, SourceDom::renderedTextContent($figure), $this->session->sourceStyleResolutionState()->pseudoElementRules()) ) {
                return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($figure), array(), $figure);
            }

            return null;
        }

        if ( 1 === count($children) && array() === $this->styleResolver->presentationAttributes($figure) ) {
            return $children[0];
        }

        return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($figure), $children, $figure);
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
     * Whether an image's wrapping link is a lightbox trigger rather than a
     * destination: it has to point at an image resource and be marked as a
     * lightbox/gallery viewer by the source script that handled the click.
     */
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

        return $this->layoutShells->blockForElements(array($button), $children, $button);
    }

}


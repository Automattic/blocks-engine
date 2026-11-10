<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\CaptureReplayScripts;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\AssetMaterializationState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\RuntimeSelectorState;
use Automattic\BlocksEngine\PhpTransformer\Contract\BlockCompilationOutput;
use Automattic\BlocksEngine\PhpTransformer\Contract\RichTextInlineTags;
use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionFindingContract;
use Automattic\BlocksEngine\PhpTransformer\Contract\EditabilityReport;
use Automattic\BlocksEngine\PhpTransformer\Contract\GeneratedBlockEditingSurface;
use Automattic\BlocksEngine\PhpTransformer\Support\ShellLandmarkPolicy;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\CommentTextRunBoundaries;
use Automattic\BlocksEngine\PhpTransformer\WordPress\CoreBlockCapabilityMatrix;
use Automattic\BlocksEngine\PhpTransformer\Contract\CoreHtmlFallbackEvidence;
use Automattic\BlocksEngine\PhpTransformer\Contract\TransformationOptions;
use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\ContentRoundTripReporter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\DeadProjectedSelectorReporter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\SourceMediaRetentionReporter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthorLayoutBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredCarouselBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredButtonBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredMarqueeRecognizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\CustomBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\DescriptionListBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\LayoutShellBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\SvgArtworkBlockGenerator;
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
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\PatternElementContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\PatternElementConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\QuoteElementContext;
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
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\RuntimeIslandContext;
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
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\CommerceFallbackReporter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\SourceElementDescription;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackDiagnostic;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\InteractionFallbackReporter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\TypographyParityAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\DiagnosticsCollector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\SemanticParityReporter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\AccordionPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CoverPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ButtonPatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CodeWindowPatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\DetailsPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ColumnsPatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CommerceStructureRecognizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\GalleryPatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\LogoPatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\MarkupPatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\MediaPatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationPatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ParameterTablePattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternRecognizerRegistry;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PlaceholderMediaPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ProbeBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\QuotePattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\QuotePatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\SocialLinksPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorSelectorSemanticContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorSelectorSemanticPreparer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorStyleRuleProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorStylesheetProjectionContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorStylesheetProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\ProjectedSelectorBindings;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CascadeLayer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\LayeredCssCollector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormPresentationGraphBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\LayoutGeometryState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\NavigationStyleProjectionContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\NavigationStyleProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\RevealAnimationSettler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\AdminBarAccommodation;
use Automattic\BlocksEngine\PhpTransformer\Css\TemplatePartStickyContainment;
use Automattic\BlocksEngine\PhpTransformer\Css\AuthorCascadeLayerOrder;
use Automattic\BlocksEngine\PhpTransformer\Css\EditorCanvasBodyBackground;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\EngineSupportCss;
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
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\ContentBoxRestatements;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\BackgroundImageExtractor;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\CapturedNavigationPanels;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\DeclarativeCounterMaterializer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\DomHelpersTrait;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorLayoutOwnership;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\LinkUrlSanitizer;
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
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\ImageUrlResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SvgMaterializationContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SvgMaterializer;
use Automattic\BlocksEngine\PhpTransformer\StaticSite\FontMaterialization\FontMaterializationPlanBuilder;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMDocument;
use DOMElement;
use DOMNode;

/** Run-scoped compiler for one HTML document. */
final class HtmlCompilation extends SourceBlockCreator implements RichTextInlinePolicy, PatternTreeConverter
{
    private const GENERATED_COMPONENT_MIN_SOURCE_DEPTH = 14;

    use DomHelpersTrait;

    private readonly AuthorLayoutOwnership $authorLayoutOwnership;
    private readonly Elements\LayoutTableLowering $layoutTables;

    private string $capturedRootTheme = '';
    private string $emittedStyleMarkup = '';

    /** After-author content-box restatements collected while projecting the author stylesheet assets. */
    private string $authorStylesheetContentBoxCss = '';

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
    private readonly LinkWrapperPropagator $linkWrapperPropagator;
    private readonly Elements\LinkedIconTextRowLowering $linkedIconTextRows;

    private readonly BlockMaterializer $blockMaterializer;

    private readonly BackgroundImageExtractor $backgroundImageExtractor;

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

    private readonly GeneratedBlockStyleProjector $generatedBlockStyleProjector;

    private readonly SourceBlockAttributeProjector $sourceBlockAttributeProjector;

    private readonly StylesheetAnalysisComposer $stylesheetAnalysisComposer;

    private readonly StylesheetAssetStage $stylesheetAssetStage;

    private readonly AuthorSelectorSemanticPreparer $authorSelectorSemanticPreparer;

    private readonly AuthorStylesheetProjector $authorStylesheetProjector;

    private readonly NavigationStyleProjector $navigationStyleProjector;

    private readonly SvgMaterializer $svgMaterializer;
    private readonly Elements\AuthoredStateButtonLowering $authoredStateButtons;
    private readonly Elements\InlineSvgTextGroupLowering $inlineSvgTextGroups;
    private readonly Elements\InlineFlowLowering $inlineFlows;

    private readonly RichTextMaterializer $richTextMaterializer;
    private readonly Style\SourceElementVisibility $sourceVisibility;
    private readonly Elements\FlankedSeparatorLowering $flankedSeparators;
    private readonly Elements\StandaloneBreakLowering $standaloneBreaks;
    private readonly Elements\CapturedDisclosureDialog $capturedDisclosureDialogs;
    private readonly Elements\StructuredCardListLowering $structuredCardLists;
    private readonly AuthoredMarqueeRecognizer $authoredMarqueeRecognizer;

    private readonly OrderedElementConverterRegistry $structuralContentConverters;

    private readonly OrderedElementConverterRegistry $specializedElementConverters;

    /** Existing post-structural delegates in their conversion precedence order. */
    private readonly OrderedElementConverterRegistry $postStructuralElementConverters;

    private readonly NavigationToggleSuppressor $navigationToggleSuppressor;

    private readonly RuntimeIslandAnalyzer $runtimeIslands;
    private readonly Elements\LayoutShellLowering $layoutShells;
    private readonly Elements\CustomVideoHostLowering $customVideoHosts;
    private readonly Elements\ListItemLowering $listItemLowering;
    private readonly Elements\StructuredInlineItemLowering $structuredInlineItemLowering;
    private readonly Elements\SyntheticImageFigureFlow $syntheticImageFigureFlow;
    private readonly Elements\DefinitionListLowering $definitionLists;
    private readonly Elements\CustomImageHostLowering $customImageHosts;
    private readonly Elements\AnchorLowering $anchors;
    private readonly Elements\RenderNeutralElements $renderNeutral;
    private readonly Elements\InertElements $inertElements;
    private readonly Elements\EmptyInlineGeometryLowering $emptyInlineGeometry;
    private readonly Elements\CustomWrapperConventions $wrapperConventions;
    private readonly Elements\EmptyVisualSpacerLowering $emptyVisualSpacers;
    private readonly Diagnostics\FallbackReconciliation $fallbackReconciliation;
    private readonly Elements\InlineCheckableControlLowering $inlineCheckableControls;
    private readonly Elements\GlobalShellArtifacts $globalShellArtifacts;
    private readonly Elements\LinkCarrierLowering $linkCarriers;
    private readonly Elements\NamePriceRowLowering $namePriceRows;
    private readonly Elements\MediaGalleryLowering $mediaGalleries;
    private readonly SourceProvenance $sourceProvenance;

    private readonly RuntimeResourceElementConverter $runtimeResourceConverter;

    private readonly FormRuntimeIslandRecorder $formRuntimeIslandRecorder;

    private readonly FormControlMetadataBuilder $formControlMetadataBuilder;
    private readonly InteractionFallbackReporter $interactionFallbackReporter;

    /** Held per transform so the context-typography cascade analysis runs once. */
    private ?FormPresentationGraphBuilder $formContextTypographyBuilder = null;

    private readonly AuthoredFormControlBlockConverter $authoredFormControlBlockConverter;

    private readonly ReadableFormControlBlockConverter $readableFormControlBlockConverter;

    private readonly ReadableFormBlockBuilder $readableFormBlockBuilder;

    private readonly FormCompositionPlanner $formCompositionPlanner;

    private readonly FormFallbackFindingBuilder $formFallbackFindingBuilder;

    private readonly FormDispatcher $formDispatcher;

    private readonly NativeGetFormBlockBuilder $nativeGetFormBlockBuilder;
    /** @var list<string> Hosts the current source document declares as its own. */
    private array $sourceDocumentHosts = array();

    /** @var list<DOMElement> Neutral convention instances retained as author selector hooks. */
    private array $retainedCustomWrapperHooks = array();

    private readonly ElementConversionPrelude $elementPrelude;

    private readonly WrapperCoalescer $wrapperCoalescer;

    private readonly PseudoFormAnalyzer $pseudoFormAnalyzer;

    private readonly FormRuntimeRequirementAnalyzer $formRuntimeRequirementAnalyzer;

    private readonly FormSuccessPanelMetadataBuilder $formSuccessPanelMetadataBuilder;

    private readonly SearchBlockConverter $searchBlockConverter;

    private readonly ButtonLinkDispatcher $buttonLinkDispatcher;

    private readonly OrderedElementConverterRegistry $tableConverters;

    private readonly FlowContainerElementConverter $flowContainerConverter;

    private readonly MediaDispatchElementConverter $mediaDispatchConverter;

    private readonly AuthoredCarouselBlockGenerator $authoredCarouselGenerator;

    private readonly DescriptionListBlockGenerator $descriptionListGenerator;

    private readonly VisualIframeBlockGenerator $visualIframeGenerator;

    private readonly CustomBlockGenerator $customBlockGenerator;

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

    private readonly DiagnosticsCollector $diagnosticsCollector;

    private readonly SemanticParityReporter $semanticParityReporter;

    private readonly ContentRoundTripReporter $contentRoundTripReporter;
    private readonly SourceMediaRetentionReporter $sourceMediaRetentionReporter;
    private readonly DeadProjectedSelectorReporter $deadProjectedSelectorReporter;

    /** @var list<array{authored: string, projected: string, stylesheet: string, scope: string}> */
    private array $projectedSelectorBindings = array();

    private string $currentSourcePath = 'html';

    /** @var array<string, array<string, mixed>> Undecodable glyph findings keyed by format and source. */
    private array $glyphColorUndecodable = array();

    /** @var \SplObjectStorage<DOMElement, string>|null Captured `data-blocks-engine-capture-glyph-color` values keyed by glyph element. */
    private ?\SplObjectStorage $capturedGlyphColors = null;

    private readonly ReusableComponentRecognizer $reusableComponentRecognizer;

    private HtmlTransformerSession $session;

    /** @var array<string, true> */
    private array $sharedStylesheetPaths = array();

    private bool $projectsOnlyMatchedAuthorRules = false;

    private bool $nativeDocumentContext = false;

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


    private const ENGINE_RESET_LAYER = 'blocks-engine-reset';


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
        $this->backgroundImageExtractor = new BackgroundImageExtractor();
        $this->tableClassificationPolicy = new TableClassificationPolicy();
        $this->navigationBlockNormalizer = new NavigationBlockNormalizer(fn (string $label): string => $this->normalizedNavigationLabel($label));
        $this->diagnosticsCollector = new DiagnosticsCollector();
        $this->semanticParityReporter = new SemanticParityReporter(
            $this->runtime,
            new TypographyParityAnalyzer(new FontMaterializationPlanBuilder($this->analysisCache->cssFontAnalysis))
        );
        $this->contentRoundTripReporter = new ContentRoundTripReporter();
        $this->sourceMediaRetentionReporter = new SourceMediaRetentionReporter();
        $this->deadProjectedSelectorReporter = new DeadProjectedSelectorReporter();
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
            $this->backgroundImageExtractor,
            $this->sourceElementDescription,
            $this->session,
            $this->imageUrls,
            $this->imageDimensions,
            $this->sourceElementClassifier
        );
        $this->emptyElementFootprint = new EmptyElementFootprint($this->styleResolver, $this->sourceElementClassifier);
        $this->linkWrapperPropagator = new LinkWrapperPropagator($this->styleResolver, $this->blockFactory);
        $this->generatedBlockStyleProjector = new GeneratedBlockStyleProjector($this->runtime, $this->styleResolver, $this->session);
        $this->sourceBlockAttributeProjector = new SourceBlockAttributeProjector($this->styleResolver, $this->generatedBlockStyleProjector);
        $this->stylesheetAnalysisComposer = new StylesheetAnalysisComposer($this->styleResolver, $this->analysisCache);
        $this->navigationStyleProjector = new NavigationStyleProjector($this->session,
            $this->createNavigationStyleProjectionContext(),
            $this->styleResolver
        );
        $this->svgMaterializer = new SvgMaterializer($this, $this->session, $this->sourceElementClassifier,
            $this->createSvgMaterializationContext(),
            $this->styleResolver,
            $this->runtime
        );
        $this->capturedDisclosureDialogs = new Elements\CapturedDisclosureDialog($this->styleResolver);
        $this->standaloneBreaks = new Elements\StandaloneBreakLowering($this, $this->styleResolver);
        $this->flankedSeparators = new Elements\FlankedSeparatorLowering($this, $this->styleResolver, $this->sourceElementClassifier);
        $this->sourceVisibility = new Style\SourceElementVisibility($this->styleResolver);
        $this->richTextMaterializer = new RichTextMaterializer($this->styleResolver, $this->svgMaterializer, $this->session, $this);
        $this->inlineFlows = new Elements\InlineFlowLowering($this, $this, $this->styleResolver, $this->sourceElementClassifier, $this->richTextMaterializer, $this->runtime);
        $this->authoredStateButtons = new Elements\AuthoredStateButtonLowering($this->session, $this->svgMaterializer);
        $this->linkedIconTextRows = new Elements\LinkedIconTextRowLowering(
            $this,
            $this->styleResolver,
            $this->imageLowering,
            $this->linkWrapperPropagator,
            $this->svgMaterializer,
            $this->richTextMaterializer,
            $this->runtime
        );
        $this->inlineSvgTextGroups = new Elements\InlineSvgTextGroupLowering(
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
        $this->formControlMetadataBuilder = new FormControlMetadataBuilder(
            fn (DOMElement $element): string => $this->elementSelector($element),
            fn (DOMElement $element): array => $this->styleResolver->presentationAttributes($element),
            fn (DOMElement $element): array => $this->formContextTypography($element),
            fn (DOMElement $element): array => $this->formContextPaint($element),
            fn (DOMElement $element, DOMElement $boundary): ?array => $this->formContextHiddenState($element, $boundary),
            $this->session,
            fn (DOMElement $element): string => $this->formContextRichText($element),
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
        $this->pseudoFormAnalyzer = new PseudoFormAnalyzer($this->formControlMetadataBuilder, fn (DOMElement $element): string => $this->elementSelector($element));
        $this->runtimeIslands = new RuntimeIslandAnalyzer($this->session, $this->sourceElementClassifier, $this->createRuntimeIslandContext(), $this->pseudoFormAnalyzer);
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
            fn (DOMElement $element): ?DOMElement => $this->soleElementChild($element),
            fn (DOMElement $element): bool => $this->imageLowering->isImageOnlyAnchor($element)
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
        $this->customVideoHosts = new Elements\CustomVideoHostLowering($this->session, $this->styleResolver, $this->imageUrls, $this->runtimeIslands, $this->mediaLowering, $this->imageLowering, $this->layoutShells);
        $this->listItemLowering = new Elements\ListItemLowering($this, $this, $this->runtime, $this->session, $this->styleResolver, $this->listStructure, $this->richTextMaterializer);
        $this->structuredInlineItemLowering = new Elements\StructuredInlineItemLowering($this, $this->runtime, $this->sourceElementClassifier, $this->styleResolver, $this->inlineSvgTextGroups);
        $this->renderNeutral = new Elements\RenderNeutralElements($this->session, $this->styleResolver, $this->sourceElementClassifier, $this->sourceElementDescription, $this->runtimeIslands, $this->emptyElementFootprint);
        $this->inertElements = new Elements\InertElements($this->styleResolver, $this->sourceElementClassifier, $this->sourceVisibility, $this->runtimeIslands);
        $this->emptyInlineGeometry = new Elements\EmptyInlineGeometryLowering($this, $this->session, $this->sourceElementClassifier, $this->emptyElementFootprint, $this->authoredStateButtons, $this->layoutShells, $this->runtimeIslands);
        $this->wrapperConventions = new Elements\CustomWrapperConventions($this->styleResolver);
        $this->emptyVisualSpacers = new Elements\EmptyVisualSpacerLowering($this, $this, $this->session, $this->styleResolver, $this->emptyElementFootprint, $this->runtimeIslands);
        $this->fallbackReconciliation = new Diagnostics\FallbackReconciliation($this->runtimeIslands);
        $this->inlineCheckableControls = new Elements\InlineCheckableControlLowering($this, $this->runtime, $this->session, $this->sourceElementClassifier, $this->richTextMaterializer, $this->authoredStateButtons, $this->runtimeIslands, $this->layoutShells);
        $this->globalShellArtifacts = new Elements\GlobalShellArtifacts($this, $this, $this->runtime, $this->session, $this->navigationBlockNormalizer);
        $this->linkCarriers = new Elements\LinkCarrierLowering($this, $this, $this->runtime, $this->styleResolver, $this->runtimeIslands, $this->layoutShells);
        $this->namePriceRows = new Elements\NamePriceRowLowering($this, $this, $this->styleResolver, $this->sourceElementDescription);
        $this->mediaGalleries = new Elements\MediaGalleryLowering($this->sourceElementClassifier, $this->styleResolver, $this->imageLowering, fn (DOMElement $element, array &$fallbacks, array $patterns): ?array => $this->recognizePatterns($element, $fallbacks, $patterns));
        $this->customImageHosts = new Elements\CustomImageHostLowering($this, $this->blockFactory, $this->styleResolver, $this->session, $this->sourceElementClassifier, $this->sourceElementDescription, $this->imageLowering, $this->layoutShells, $this->runtimeIslands, $this->authorLayoutOwnership);
        $this->anchors = new Elements\AnchorLowering($this, $this, $this->styleResolver, $this->session, $this->sourceElementClassifier, $this->sourceElementDescription, $this->imageLowering, $this->imageUrls, $this->layoutShells, $this->linkWrapperPropagator, $this->runtimeIslands, $this->customImageHosts);
        $this->layoutTables = new Elements\LayoutTableLowering(
            $this,
            $this->session,
            $this->styleResolver,
            $this->blockFactory,
            $this
        );
        $this->syntheticImageFigureFlow = new Elements\SyntheticImageFigureFlow($this->styleResolver, $this->sourceElementClassifier, $this->tableClassificationPolicy, $this->layoutTables);
        $this->definitionLists = new Elements\DefinitionListLowering($this, $this->runtime, $this->styleResolver);
        $this->structuredCardLists = new Elements\StructuredCardListLowering(
            $this,
            $this->styleResolver,
            $this->authorLayoutOwnership,
            $this->richTextMaterializer,
            $this->sourceElementClassifier,
            $this->runtime,
            $this,
            fn (): RichTextStylingHookNormalizer => $this->richTextStylingHooks()
        );
        $this->authorSelectorSemanticPreparer = new AuthorSelectorSemanticPreparer($this->sourceElementClassifier,
            new AuthorSelectorSemanticContext(
                $this->authorLayoutOwnership,
                fn (DOMElement $element): bool => $this->listStructure->isStructuralListItem($element),
                fn (DOMElement $element): bool => $this->requiresIndependentSemanticWrapper($element),
                fn (DOMElement $table): bool => $this->isRepresentableTable($table),
                fn (DOMElement $element): bool => 'p' === strtolower($element->tagName) && $this->paragraphImageChild($element) instanceof DOMElement
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
            fn (DOMElement $element): bool => $this->runtimeIslands->isRuntimeDomTarget($element),
            fn (DOMElement $element): string => $this->sanitizeInlineSvgMarkup($element),
            fn (string $html): bool => $this->isSafeSvgContent($html),
            fn (DOMElement $element): bool => $this->svgHasDrawableContent($element),
            function (DOMElement $element, array &$fallbacks): void {
                $this->captureInlineSvgFallback($element, $fallbacks);
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
            fn (DOMElement $element, array &$fallbacks, array $patterns): ?array => $this->recognizePatterns($element, $fallbacks, $patterns),
            fn (DOMElement $element): bool => $this->runtimeIslands->isRuntimeDomTarget($element),
            fn (DOMElement $element): ?array => $this->inlineSvgTextGroups->fromElement($element),
            fn (DOMElement $element): bool => $this->ownsPositioningGeometry($element),
            fn (DOMElement $element, array &$fallbacks): ?array => $this->positionedInlineCarrierBlock($element, $fallbacks),
            fn (DOMElement $element): bool => $this->hasAuthorSemanticMarker($element),
            fn (DOMElement $element, array &$fallbacks, bool $captureUnsupported): array => $this->convertChildren($element, $fallbacks, $captureUnsupported),
            fn (DOMElement $element): string => $this->richTextMarkerForElement($element),
            fn (DOMElement $element): ?string => $this->dynamicTextContent($element),
            fn (DOMElement $element, string $tagName): ?DOMElement => SourceDom::ancestorElement($element, $tagName),
            fn (DOMElement $element): bool => $this->listStructure->isStructuralListItem($element),
            fn (DOMElement $element): bool => $this->shouldPreserveEmptyVisualElement($element),
            fn (DOMElement $element): array => $this->emptyVisualSpacers->emptyVisualSpacerBlock($element),
            function (DOMElement $element): void { $this->rememberNativeDisclosure($element); },
            fn (DOMElement $element): bool => $this->runtimeIslands->retainsRuntimeTextLeafInRichText($element)
        ), $this->styleResolver, $this->runtime, $this->sourceBlockAttributeProjector);
        $this->authoredFormControlBlockConverter = new AuthoredFormControlBlockConverter(
            $this->formControlMetadataBuilder,
            fn (DOMElement $element): array => $this->styleResolver->authoredPresentationDeclarations($element),
            fn (DOMElement $element): array => $this->styleResolver->presentationAttributes($element),
            $this,
            $this->session,
            $this->runtime,
            fn (string $id): string => $this->safeAnchor($id),
            fn (DOMElement $element): ?DOMElement => $this->sourceTagProjectedClone($element),
            fn (DOMElement $label): string => $this->richTextMaterializer->content($label, array( 'input', 'select', 'textarea' )),
            fn (DOMElement $label): string => $this->inlineRichTextContent($label)
        );
        $this->projectedNavigation = new ProjectedNavigationConverter(
            $this->navigationToggleSuppressor,
            $this->styleResolver,
            $this->session,
            fn (DOMElement $element, array &$fallbacks, array $patterns): ?array => $this->recognizePatterns($element, $fallbacks, $patterns),
            $this,
            fn (DOMElement $element): bool => $this->runtimeIslands->isRuntimeDomTarget($element),
            fn (DOMElement $element): string => $this->svgMaterializer->restoreSvgCasing($this->sanitizeInlineSvgMarkup($element))
        );
        $this->patternContext = $this->createPatternContext();
        $this->interactionFallbackReporter = new InteractionFallbackReporter(
            $this->formControlMetadataBuilder,
            $this->navigationToggleSuppressor,
            $this->runtimeIslands,
            $this->runtime,
            $this->session,
            $this->sourceElementDescription
        );
        $formFallbackContext = new FormFallbackFindingContext(
            $this->session,
            fn (DOMElement $element): array => $this->runtimeIslands->runtimeDomSelectorsForElement($element),
            $this->sourceElementDescription,
            fn (array $block, string $role, array $supersededRuntimeSelectors, ?DOMElement $anchorElement): array => $this->blockBinding($block, $role, $supersededRuntimeSelectors, $anchorElement),
            fn (DOMElement $element, string $value): string => $this->styleResolver->resolveCssVariablesInValue($value, $element),
            fn (DOMElement $element): string => $this->svgMaterializer->restoreSvgCasing($this->sanitizeInlineSvgMarkup($element))
        );
        $this->commerceReporter = new CommerceFallbackReporter(
            $this->commercePattern,
            $this->runtime,
            $this->session->transformationProvenanceState(),
            $this->runtimeIslands,
            $this->sourceElementDescription,
            $this->formControlMetadataBuilder,
            $this->session,
            $formFallbackContext
        );
        $this->fallbackBindings = new Diagnostics\FallbackBindingFinalizer($this, $this->session, $this->commerceReporter);
        $this->blockMaterializer = new BlockMaterializer($this->blockFactory, $this->runtimeIslands);
        $this->runtimeResourceConverter = new RuntimeResourceElementConverter(
            $this->session,
            $this
        );
        $this->formRuntimeIslandRecorder = new FormRuntimeIslandRecorder(
            $this->formControlMetadataBuilder,
            function (DOMElement $element, string $kind, string $reason, string $capability, array $metadata): void {
                $this->runtimeIslands->recordRuntimeIsland($element, $kind, $reason, $capability, $metadata);
            },
            fn (DOMElement $element): array => $this->eventMetadata($element),
            $this->session
        );
        $this->readableFormControlBlockConverter = new ReadableFormControlBlockConverter(
            $this->formControlMetadataBuilder,
            $this->authoredFormControlBlockConverter,
            $this->formRuntimeIslandRecorder,
            $this->runtime,
            fn (DOMElement $element): array => $this->eventMetadata($element),
            fn (DOMElement $element): bool => $this->runtimeIslands->isRuntimeDomTarget($element),
            fn (DOMElement $element): array => $this->styleResolver->presentationAttributes($element),
            $this,
            $this->session
        );
        $this->readableFormBlockBuilder = new ReadableFormBlockBuilder(
            $this->formControlMetadataBuilder,
            $this->readableFormControlBlockConverter,
            $this->formRuntimeIslandRecorder,
            fn (DOMElement $element): array => $this->eventMetadata($element),
            fn (DOMElement $element): bool => $this->runtimeIslands->isRuntimeDomTarget($element),
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
            function (DOMElement $element, array &$fallbacks): array {
                $block = $this->convertElement($element, $fallbacks);
                return null === $block ? array() : array( $block );
            }
        );
        $this->nativeGetFormBlockBuilder = new NativeGetFormBlockBuilder(
            function (DOMElement $element, array &$fallbacks): array {
                return $this->convertChildren($element, $fallbacks, true);
            },
            function (DOMElement $element): array {
                $attrs = $this->styleResolver->presentationAttributes($element);
                $alignment = $this->styleResolver->inheritedTextAlignmentDeclaration($element);
                if (in_array($alignment, array('left', 'center', 'right'), true)) {
                    $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), 'has-text-align-' . $alignment);
                }
                return $attrs;
            },
            $this,
            $this->session,
            fn (): array => $this->sourceDocumentHosts
        );
        $this->formCompositionPlanner = new FormCompositionPlanner(
            $this->session,
            function (DOMElement $element, array &$fallbacks, bool $captureUnsupported): array {
                return $this->convertChildren($element, $fallbacks, $captureUnsupported);
            },
            fn (DOMElement $element): array => $this->styleResolver->presentationAttributes($element),
            $this,
            fn (DOMElement $container, DOMElement $element): bool => $this->elementContains($container, $element)
        );
        $this->formRuntimeRequirementAnalyzer = new FormRuntimeRequirementAnalyzer(
            fn (DOMElement $element): array => $this->eventMetadata($element),
            fn (DOMElement $element): bool => $this->runtimeIslands->isRuntimeDomTarget($element)
        );
        $this->formSuccessPanelMetadataBuilder = new FormSuccessPanelMetadataBuilder(
            fn (DOMElement $element): string => $this->elementSelector($element),
            $this->session,
            fn (DOMElement $element): string => $this->styleResolver->innerHtml($element)
        );
        $this->formFallbackFindingBuilder = new FormFallbackFindingBuilder($this->session,
            $formFallbackContext,
            $this->formControlMetadataBuilder,
            $this->formSuccessPanelMetadataBuilder,
            $this->pseudoFormAnalyzer
        );
        $this->searchBlockConverter = new SearchBlockConverter($this->styleResolver, $this->session, $this, $this->svgMaterializer, $this->runtimeIslands, $this->createSearchBlockConversionContext(), $this->formControlMetadataBuilder, $this->pseudoFormAnalyzer);
        $loginBlockConverter = new LoginBlockConverter(
            fn (DOMElement $element): array => $this->createBlock('core/loginout', array( 'displayLoginAsForm' => true ), array(), $element)
        );
        $this->formDispatcher = new FormDispatcher(new FormDispatchContext(
            fn (DOMElement $element): ?array => $loginBlockConverter->loginBlockFromForm($element),
            fn (DOMElement $element): ?array => $this->searchBlockConverter->searchBlockFromForm($element),
            fn (DOMElement $element, array &$fallbacks): ?array => $this->nativeGetFormBlockBuilder->build($element, $fallbacks),
            function (DOMElement $element, array &$fallbacks): ?array {
                return $this->formCompositionPlanner->compose($element, $fallbacks);
            },
            fn (DOMElement $element, ?array $readableFormBlock, ?array $bindingBlock = null): array => $this->formFallbackFindingBuilder->build($element, $readableFormBlock, $bindingBlock, $this->readableFormBlockBuilder->layoutGraph()),
            function (DOMElement $element, ?array $readableFormBlock): void {
                $this->formRuntimeIslandRecorder->recordForm($element, $readableFormBlock);
            },
            function (DOMElement $element, bool $allowFormEvents, array &$fallbacks): ?array {
                return $this->readableFormBlockBuilder->build($element, $allowFormEvents, $fallbacks);
            },
            fn (DOMElement $element): bool => $this->formRuntimeRequirementAnalyzer->requiresPreservation($element),
            fn (DOMElement $element): bool => $this->pseudoFormAnalyzer->isProviderPseudoForm($element)
        ), $this);
        $this->buttonLinkDispatcher = new ButtonLinkDispatcher($this->sourceElementClassifier, $this->createButtonLinkDispatchContext());
        $buttonConverter = new ButtonElementConverter($this->sourceElementClassifier, $this, $this->styleResolver, new ButtonElementContext(
            fn (DOMElement $element): bool => $this->searchBlockConverter->isReplacedSearchClusterControl($element),
            function (DOMElement $element, array &$fallbacks, bool $captureUnsupported): array {
                return $this->convertChildren($element, $fallbacks, $captureUnsupported);
            },
            fn (DOMElement $element): ?array => $this->buttonLinkDispatcher->convertButton($element),
            fn (DOMElement $element): bool => $this->runtimeIslands->isRuntimeDomTarget($element),
            fn (DOMElement $element): ?array => $this->runtimeAuthoredButton($element),
            fn (DOMElement $element, array &$fallbacks): ?array => $this->convertButtonWrapperGroup($element, $fallbacks),
            fn (DOMElement $image): array => $this->imageDimensions->minimumPixelSize($image)
        ));
        $detailsConverter = new DetailsElementConverter(new DetailsElementContext(
            fn (DOMElement $element): ?DOMElement => $this->capturedDisclosureDialogs->fromDetails($element),
            function (DOMElement $element, array &$fallbacks): array {
                return $this->capturedDialogBlock($element, $fallbacks);
            },
            function (DOMElement $element, array &$fallbacks, array $patterns): ?array {
                return $this->recognizePatterns($element, $fallbacks, $patterns);
            }
        ));
        $this->specializedElementConverters = new OrderedElementConverterRegistry(array(
            $detailsConverter,
            $buttonConverter,
            $svgConverter,
        ));
        $tableConverter = new TableElementConverter($this->styleResolver, $this, $this->createTableElementContext());
        $parameterTableConverter = new PatternElementConverter(
            new PatternElementContext(
                function (DOMElement $element, array &$fallbacks, array $patterns): ?array {
                    return $this->recognizePatterns($element, $fallbacks, $patterns);
                }
            ),
            array( ParameterTablePattern::class )
        );
        $this->tableConverters = new OrderedElementConverterRegistry(array(
            $tableConverter,
            $parameterTableConverter,
        ));
        $this->postStructuralElementConverters = new OrderedElementConverterRegistry(array(
            $this->textLeafConverter,
            $this->tableConverters,
            $this->specializedElementConverters,
            $this->runtimeResourceConverter,
        ));
        $figureConverter = new FigureElementConverter($this, $this->styleResolver, new FigureElementContext(
            function (DOMElement $element, array &$fallbacks): ?array {
                return $this->mediaGalleries->mediaGalleryBlockFromElement($element, $fallbacks);
            },
            function (DOMElement $element, array &$fallbacks, array $patterns): ?array {
                return $this->recognizePatterns($element, $fallbacks, $patterns);
            },
            fn (DOMElement $figure): ?DOMElement => $this->figureLinkedMediaAnchor($figure),
            fn (DOMElement $picture, ?DOMElement $figure = null, ?DOMElement $link = null): ?array => $this->convertPictureElement($picture, $figure, $link),
            fn (DOMElement $image, ?DOMElement $figure = null, ?DOMElement $picture = null, ?DOMElement $link = null): ?array => $this->imageLowering->convertImage($image, $figure, $picture, $link),
            fn (DOMElement $figure, string $tagName): ?DOMElement => SourceDom::figureMediaElement($figure, $tagName),
            function (DOMElement $figure, array &$fallbacks): ?array {
                return $this->convertFigureGeneric($figure, $fallbacks);
            },
            fn (string $html): bool => '' !== trim($this->runtime->stripAllTags($html))
        ));
        $quoteConverter = new QuoteElementConverter(new QuoteElementContext(
            function (DOMElement $element, array &$fallbacks): ?array {
                return $this->recognizePatterns($element, $fallbacks, array( QuotePattern::class ));
            }
        ));
        $listConverter = new ListElementConverter($this->styleResolver, $this, new ListElementContext(
            function (DOMElement $element, array &$fallbacks, array $patterns): ?array {
                return $this->recognizePatterns($element, $fallbacks, $patterns);
            },
            fn (array $block, DOMElement $element): array => $this->rememberAccordionDisclosureRoot($block, $element),
            fn (DOMElement $element): bool => $this->structuredCardLists->isStructuredCardList($element),
            function (DOMElement $element, array &$fallbacks): ?array {
                return $this->structuredCardLists->decomposeStructuredCardList($element, $fallbacks);
            },
            fn (DOMElement $element): bool => $this->listStructure->listContainsStructuralItemContent($element),
            function (DOMElement $element, array &$fallbacks): ?array {
                return $this->decomposeStructuralList($element, $fallbacks);
            },
            function (DOMElement $element, array &$fallbacks): array {
                return $this->listItemLowering->listItems($element, $fallbacks);
            },
            $this->authorLayoutOwnership
        ));
        $this->descriptionListGenerator = new DescriptionListBlockGenerator(
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
            function (DOMElement $element, array &$fallbacks): array {
                return $this->convertChildren($element, $fallbacks, true);
            }
        );
        $this->visualIframeGenerator = new VisualIframeBlockGenerator(
            $this->sourceElementClassifier,
            $this->styleResolver,
            $this,
            $this->runtimeIslands,
            $this->session,
            fn (DOMElement $element): bool => $this->inertElements->isInertRuntimeMediaPlaceholder($element),
            fn (DOMElement $element): bool => $this->sourceVisibility->startsHidden($element),
            $this->sourceElementDescription
        );
        $this->customBlockGenerator = new CustomBlockGenerator(
            $this->sourceElementClassifier,
            $this->styleResolver,
            $this,
            fn (DOMElement $element): bool => $this->runtimeIslands->isSafeTransparentCustomElement($element),
            function (DOMElement $element, array &$fallbacks): array {
                return $this->convertChildren($element, $fallbacks, true);
            },
            fn (DOMElement $element): bool => $this->hasAuthorSemanticMarker($element),
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
            fn (DOMElement $element): ?array => $this->descriptionListGenerator->convert($element),
            fn (DOMElement $element): ?array => $metadataGrid->convert($element),
            fn (DOMElement $element): array => $this->definitionLists->definitionListItems($element),
            $this->authorLayoutOwnership,
            function (DOMElement $element, array &$fallbacks, bool $captureUnsupported): array {
                return $this->convertChildren($element, $fallbacks, $captureUnsupported);
            },
            fn (string $html): bool => '' !== trim($this->runtime->stripAllTags($html))
        ));
        $this->structuralContentConverters = new OrderedElementConverterRegistry(array(
            $inlineContentConverter,
            $listConverter,
            $descriptionListConverter,
            $quoteConverter,
            $figureConverter,
        ));
        $this->flowContainerConverter = new FlowContainerElementConverter($this->sourceElementClassifier, $this, $this->styleResolver, new FlowContainerElementContext(
            runtimeAppShellBlock: function (DOMElement $element, array &$fallbacks): ?array {
                if ( ! $this->runtimeIslands->shouldPreserveRuntimeAppShell($element) ) {
                    return null;
                }
                $targets = $this->runtimeIslands->runtimeTargetsInSubtree($element, 8);
                $this->runtimeIslands->recordRuntimeIsland($element, 'app_shell', 'runtime_app_shell', 'client_script_execution', array(
                    'events' => $this->eventMetadata($element),
                    'target_count' => count($targets),
                    'targets' => $targets,
                    'app_shell_signals' => $this->runtimeIslands->runtimeAppShellSignals($element),
                    'required_scripts' => $this->session->fallbackEmitter()->requiredScriptsForElement($element),
                ));
                return $this->htmlPreservationBlock($element);
            },
            isEmptyInteractiveFeatureShell: fn (DOMElement $element): bool => $this->isEmptyInteractiveFeatureShell($element),
            capturePseudoFormFallback: function (DOMElement $element, array &$fallbacks): void { $this->formDispatcher->capturePseudoFormFallback($element, $fallbacks); },
            recognizePatterns: fn (DOMElement $element, array &$fallbacks, array $patterns): ?array => $this->recognizePatterns($element, $fallbacks, $patterns),
            flankedSeparatorBlock: fn (DOMElement $element): ?array => $this->flankedSeparators->blockFromElement($element),
            canCaptureExternalSvgFragmentDependency: fn (DOMElement $element): bool => ! $this->runtimeIslands->isRuntimeDomTarget($element) && ! $this->runtimeIslands->hasRuntimeTargetInSubtree($element),
            sourceElementClassifier: $this->sourceElementClassifier,
            responsiveMediaBlock: fn (DOMElement $element): array => $this->imageLowering->responsiveMediaBlock($element),
            authorLayoutOwnership: $this->authorLayoutOwnership,
            authorLayoutBlock: fn (DOMElement $element, array &$fallbacks): array => $this->authorLayoutBlockFromElement($element, $fallbacks),
            hasMultipleRuntimeInlineTextTargets: fn (DOMElement $element): bool => $this->hasMultipleRuntimeInlineTextTargets($element),
            paragraphBlockFromInlineContentWrapper: fn (DOMElement $element): ?array => $this->paragraphBlockFromInlineContentWrapper($element),
            inlineAddressableRunGroupBlock: fn (DOMElement $element): ?array => $this->inlineAddressableRunGroupBlock($element),
            isGeneratedComponentCandidate: fn (DOMElement $element): bool => $this->isGeneratedComponentCandidate($element),
            proofBackedWrapperCoalescing: fn (DOMElement $element, array &$fallbacks): ?array => $this->proofBackedWrapperCoalescing($element, $fallbacks),
            shouldPreserveEmptyVisualElement: fn (DOMElement $element): bool => $this->shouldPreserveEmptyVisualElement($element),
            isInertEmptyElement: fn (DOMElement $element): bool => $this->isInertOutOfFlowEmptyElement($element),
            patternContext: $this->patternContext,
            rememberAccordionDisclosureRoot: fn (array $block, DOMElement $element): array => $this->rememberAccordionDisclosureRoot($block, $element),
            metadataGridBlock: fn (DOMElement $element): ?array => $metadataGrid->convert($element),
            rememberNativeDisclosureRoot: function (DOMElement $element): void { $this->rememberNativeDisclosure($element); },
            rememberNativeTabControls: function (DOMElement $element): void {
                foreach ( $element->childNodes as $child ) {
                    if ( ! $child instanceof DOMElement || 'tablist' !== strtolower(trim($this->attr($child, 'role'))) ) {
                        continue;
                    }
                    foreach ( $child->getElementsByTagName('*') as $candidate ) {
                        if ( $candidate instanceof DOMElement && 'tab' === strtolower(trim($this->attr($candidate, 'role'))) ) {
                            $this->session->runtimeBehaviorState()->rememberNativeTabControl($candidate->getNodePath() ?? '');
                        }
                    }
                    break;
                }
            },
            mediaGalleryBlock: fn (DOMElement $element, array &$fallbacks): ?array => $this->mediaGalleries->mediaGalleryBlockFromElement($element, $fallbacks),
            namePriceRowBlock: fn (DOMElement $element, array &$fallbacks): ?array => $this->namePriceRows->namePriceRowBlockFromElement($element, $fallbacks),
            inlineTokenGroupBlock: fn (DOMElement $element, array &$fallbacks): ?array => $this->inlineFlows->inlineTokenGroupBlockFromElement($element, $fallbacks),
            visualTextWrapperBlock: fn (DOMElement $element): ?array => $this->visualTextWrapperBlockFromElement($element),
            standaloneSearchBlock: fn (DOMElement $element): ?array => $this->searchBlockConverter->searchBlockFromStandaloneControl($element),
            readableFormControlBlock: fn (DOMElement $element): ?array => $this->readableFormControlBlockConverter->convert($element),
            cssAuthoredMarqueeBlock: fn (DOMElement $element): ?array => $this->authoredMarqueeRecognizer->cssAuthoredMarqueeBlock($element),
            authoredCarouselBlock: fn (DOMElement $element): ?array => $this->authoredCarouselGenerator->convert($element),
            generatedComponentBlock: fn (DOMElement $element, array &$fallbacks): ?array => $this->nativeOrGeneratedComponentBlock($element, $fallbacks),
            textFlowBlock: fn (DOMElement $element): ?array => $this->inlineFlows->textFlowBlockFromElement($element),
            convertChildren: fn (DOMElement $element, array &$fallbacks): array => $this->convertChildren($element, $fallbacks, true),
            backgroundImageBlock: fn (DOMElement $element): ?array => $this->imageLowering->backgroundImageBlock($element),
            coalescedSingleGroupWrapper: fn (DOMElement $element, array $child): ?array => $this->coalescedSingleGroupWrapper($element, $child),
            shouldPreserveWrapper: fn (DOMElement $element, ?string $asTag = null): bool => $this->shouldPreserveWrapper($element, $asTag),
            emptyVisualSpacerBlock: fn (DOMElement $element): array => $this->emptyVisualSpacers->emptyVisualSpacerBlock($element)
        ));
        $this->mediaDispatchConverter = new MediaDispatchElementConverter(new MediaDispatchElementContext(
            function (DOMElement $element, array &$fallbacks): ?array {
                return $this->recognizePatterns($element, $fallbacks, array( PlaceholderMediaPattern::class ));
            },
            fn (DOMElement $element): ?array => $this->imageLowering->convertImage($element),
            fn (DOMElement $element): ?array => $this->convertPictureElement($element),
            fn (DOMElement $element, array &$fallbacks): ?array => $this->visualIframeGenerator->convert($element, $fallbacks),
            fn (DOMElement $element): ?array => $this->mediaLowering->fromElement($element),
            fn (DOMElement $element): ?array => $this->anchors->imageBlockFromAnchor($element)
        ));
        $this->unsupportedRecorder = new UnsupportedElementRecorder($this->session, $this->createUnsupportedElementContext(), $this->formControlMetadataBuilder);
        $recognizePatterns = function (DOMElement $element, array &$fallbacks, array $patterns): ?array {
            return $this->recognizePatterns($element, $fallbacks, $patterns);
        };
        $capturedDialogConverter = new CapturedDialogConverter(
            $this->session,
            $this,
            $this->styleResolver
        );
        $this->elementPrelude = new ElementConversionPrelude(
            new NativeGetFormControlConverter(
                $this->nativeGetFormBlockBuilder,
                $this->authoredFormControlBlockConverter,
                $this->formControlMetadataBuilder,
                $this->styleResolver,
                $this
            ),
            new InertScaffoldingSuppressor(
                $this->styleResolver,
                $this->runtimeIslands,
                $this->sourceElementClassifier,
                fn (DOMElement $element): ?DOMElement => $this->soleElementChild($element),
                fn (DOMElement $element): bool => $this->shouldPreserveEmptyVisualElement($element)
            ),
            $this->projectedNavigation,
            new PhrasingSvgConverter($this->svgMaterializer, $this, fn (DOMElement $element): bool => $this->inertElements->isInertHiddenSvgStorage($element)),
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
                fn (DOMElement $element): string => $this->sanitizeInlineSvgMarkup($element),
                fn (): string => $this->capturedRootTheme,
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
            $this->formDispatcher,
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
            $recognizePatterns,
            $this->authorLayoutOwnership,
            fn (DOMElement $element, array &$fallbacks): ?array => $this->proofBackedWrapperCoalescing($element, $fallbacks),
            fn (DOMElement $element): ?array => $this->wrapperCoalescer->layoutGeometryProofFor($element),
            $this->session,
            capturedCollection: new CapturedCollectionConverter(
                $this->session,
                $this
            )
        );
    }


    /** Collaborator surface for {@see StyleResolver}. */
    private function createStyleResolutionContext(): StyleResolutionContext
    {
        return new StyleResolutionContext(
            CssValueInspector::comparable(...),
            fn (string $selector): array => $this->parsedCssSelector($selector),
            fn (string $className): string => $this->styleResolver->promotedClassName($className),
            fn (string $url): string => $this->imageUrls->resolvedAssetUrl($url)
        );
    }

    /** Collaborator surface for {@see NavigationToggleSuppressor}. */
    private function createNavigationToggleSuppressionContext(): NavigationToggleSuppressionContext
    {
        return new NavigationToggleSuppressionContext(
            fn (DOMElement $element): bool => $this->sourceVisibility->startsHidden($element),
            fn (DOMElement $element): bool => $this->sourceVisibility->orAncestorStartsHidden($element),
            PatternRecognizerRegistry::createDefault(new ProbeBlockCreator(), $this->runtime, $this->sourceElementClassifier, $this->richTextMaterializer),
            $this->patternProbeContext
        );
    }

    /** Collaborator surface for {@see SvgMaterializer}. */
    private function createSvgMaterializationContext(): SvgMaterializationContext
    {
        return new SvgMaterializationContext(
            $this->session,
            fn (DOMElement $element): ?string => $this->reusableComponentFingerprintFor($element),
            fn (DOMElement $element): string => $this->sanitizeInlineSvgMarkup($element),
            fn (DOMElement $element, string $svg): array => $this->svgArtworkBlock($element, $svg)
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
            fn (string $selector): array => $this->parsedCssSelector($selector),
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
            $this->patternRecognizers,
            $this->patternContext,
            $this->runtimeIslands,
            $this->formRuntimeIslandRecorder,
            $this->anchors,
            $this->runtime,
            fn (DOMElement $element): array => $this->runtimeAuthoredButton($element)
        );
    }

    /** Collaborator surface for {@see RuntimeIslandAnalyzer}. */
    private function createRuntimeIslandContext(): RuntimeIslandContext
    {
        return new RuntimeIslandContext(
            fn (DOMElement $element): iterable => SourceDom::descendantElements($element),
            fn (string $html): ?DOMElement => $this->preservedHtmlRootElement($html),
            fn (DOMElement $element): bool => $this->hasWorkspaceSurface($element)
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
            fn (array $generated, DOMElement $element): array => $this->generatedComponentBlock($generated, $element),
            $this->sourceElementDescription,
                    );
    }

    /**
     * Records a disclosure folded into a native `core/details`. Capture-owned
     * local-disclosure panels it absorbed no longer exist as DOM targets, so the
     * capture runtime selectors that addressed them are superseded by the block.
     */
    private function rememberNativeDisclosure(DOMElement $root): void
    {
        $this->session->runtimeBehaviorState()->rememberNativeDisclosureRoot($root->getNodePath() ?? '');
        foreach ( array_merge(array( $root ), iterator_to_array($root->getElementsByTagName('*'), false)) as $node ) {
            if ( $node instanceof DOMElement && 'true' === strtolower($node->getAttribute(CaptureEvidence::LOCAL_DISCLOSURE)) ) {
                $this->session->runtimeSelectorState()->supersede('[' . CaptureEvidence::LOCAL_DISCLOSURE . ']');
                if ( '' !== $node->getAttribute('id') ) {
                    $this->session->runtimeSelectorState()->supersede('#' . $node->getAttribute('id'));
                }
            }
        }
    }

    /**
     * Collaborator surface for {@see RichTextElementConverter}.
     */
    private function createRichTextElementContext(): RichTextElementContext
    {
        return new RichTextElementContext(
            fn (DOMElement $element): ?array => $this->authoredMarqueeRecognizer->authoredMarqueeBlock($element),
            fn (DOMElement $element): bool => $this->hasEmptyVisualInlineChild($element),
            fn (DOMElement $element): bool => $this->hasBoxChromeWrapperStyling($element),
            fn (DOMElement $element): bool => $this->runtimeIslands->isRuntimeDomTarget($element),
            fn (DOMElement $element): ?array => $this->unwrappedParagraphImageBlock($element),
            function (DOMElement $element, array &$fallbacks): ?array {
                return $this->anchors->mixedMediaLinkGroupFromParagraph($element, $fallbacks);
            },
            function (DOMElement $element): ?array {
                return $this->linkedIconTextRows->fromParagraph($element);
            },
            fn (string $text): array => $this->convertText($text),
            function (DOMElement $element, array &$fallbacks, bool $captureUnsupported): array {
                return $this->convertChildren($element, $fallbacks, $captureUnsupported);
            },
            function (DOMElement $element, array &$fallbacks): ?array {
                return $this->emptyInlineGeometry->emptyInlineGeometryBlock($element, $fallbacks);
            },
            function (DOMElement $element, array &$fallbacks): ?array {
                $block = $this->recognizePatterns($element, $fallbacks, array( DetailsPattern::class ));
                if ( null !== $block ) {
                    $this->rememberNativeDisclosure($element);
                }
                return $block;
            },
            fn (DOMElement $element): ?array => $this->decorativeTextSvgBlock($element),
            function (DOMElement $element, array &$fallbacks): ?array {
                return $this->mediaBlockFromTextlessElement($element, $fallbacks);
            },
            fn (DOMElement $element): bool => $this->shouldPreserveEmptyVisualElement($element)
        );
    }

    /** @return array<string, mixed>|null */
    private function decorativeTextSvgBlock(DOMElement $element): ?array
    {
        if (!SourceDom::isSafeSvgContent(SourceDom::outerHtml($element)) || !SvgMaterializer::isPassiveSvgMarkup($element)) {
            return null;
        }
        foreach ($element->attributes as $attribute) {
            if (str_starts_with(strtolower($attribute->name), 'aria-') || in_array(strtolower($attribute->name), array('role', 'tabindex'), true)) {
                return null;
            }
        }
        $declarations = $this->styleResolver->presentationDeclarations($element);
        if ('absolute' !== strtolower(trim((string) ($declarations['position'] ?? '')))) {
            return null;
        }
        return $this->svgArtworkBlock($element, $this->svgMaterializer->restoreSvgCasing($this->svgMaterializer->sanitizeInlineSvgMarkup($element)));
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
            function (DOMElement $element, array &$fallbacks, bool $captureUnsupported): array {
                return $this->convertChildren($element, $fallbacks, $captureUnsupported);
            }
        );
    }

    /**
     * Resolved typography for one in-form context item element, through the
     * same cascade the form presentation graph reports its roles with. The
     * builder is held per transform so its stylesheet analysis runs once.
     */
    private function formContextTypography(DOMElement $element): array
    {
        return ($this->formContextTypographyBuilder ??= new FormPresentationGraphBuilder())->typographyStyles(
            $element,
            $this->session->authorStyleAnalysis()->stylesheetAssets(),
            $this->session->sourceStyleResolutionState()->formLayoutCss()
        );
    }

    /**
     * The paint one form control renders, through the same cascade. Held per
     * transform so the stylesheet analysis runs once.
     */
    private function formContextPaint(DOMElement $element): array
    {
        return ($this->formContextTypographyBuilder ??= new FormPresentationGraphBuilder())->paintStyles(
            $element,
            $this->session->authorStyleAnalysis()->stylesheetAssets(),
            $this->session->sourceStyleResolutionState()->formLayoutCss()
        );
    }

    /** @return array<string, string>|null */
    private function formContextHiddenState(DOMElement $element, DOMElement $boundary): ?array
    {
        return ($this->formContextTypographyBuilder ??= new FormPresentationGraphBuilder())->hiddenState(
            $element,
            $boundary,
            $this->session->authorStyleAnalysis()->stylesheetAssets(),
            $this->session->sourceStyleResolutionState()->formLayoutCss()
        );
    }

    /**
     * Core limitations this output works around, for the import report.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @return list<array{id: string, block_name: string, references: list<string>}>
     */
    private function gutenbergGaps(array $blocks): array
    {
        $gaps = array();
        if ( $this->session->requireGeneratedBlockRegistry()->has(DescriptionListBlockGenerator::class) ) {
            $gaps[] = array('id' => 'semantic-description-list', 'block_name' => $this->session->requireGeneratedBlockRegistry()->blockName(DescriptionListBlockGenerator::LOCAL_NAME), 'references' => array('https://github.com/WordPress/gutenberg/issues/4880', 'https://github.com/WordPress/gutenberg/pull/20760'));
        }
        // Core's overlay opens from one button and closes from a second one
        // inside it, so a menu the source toggles from a single control gains
        // a close button. `overlayMenu` defaults to `mobile` in block.json.
        $stack = $blocks;
        while ( array() !== $stack ) {
            $block = array_pop($stack);
            if ( 'core/navigation' === ($block['blockName'] ?? '') && 'never' !== ($block['attrs']['overlayMenu'] ?? 'mobile') ) {
                $gaps[] = array('id' => 'navigation-overlay-close-control', 'block_name' => 'core/navigation', 'references' => array('https://github.com/WordPress/gutenberg/issues/49402'));
                break;
            }
            array_push($stack, ...($block['innerBlocks'] ?? array()));
        }

        return $gaps;
    }

    private function controlCarrierLayerRevertRule(DOMElement $element, string $marker): string
    {
        return $this->authorStylesheetProjector->controlCarrierLayerRevertRule($element, $marker);
    }

    private ?RichTextStylingHookNormalizer $richTextStylingHooks = null;

    private function richTextStylingHooks(): RichTextStylingHookNormalizer
    {
        return $this->richTextStylingHooks ??= new RichTextStylingHookNormalizer(
            $this->styleResolver,
            $this->richTextMaterializer,
            $this->session,
            $this->sourceElementClassifier
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

    /**
     * Backs {@see DomHelpersTrait::safeFallbackHtml()}, still used here only
     * through {@see DomHelpersTrait::sanitizeInlineSvgMarkup()} (every other
     * fallback-HTML path now calls {@see SourceDom::safeFallbackHtml()}
     * directly with this same source).
     */
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
        $validationEvidence = \Automattic\BlocksEngine\PhpTransformer\Contract\ValidationEvidencePolicy::fromOptions($options);
        $context = TransformationOptions::context($options);
        $startedAt = hrtime(true);
        $this->session->transformationProvenanceState()->installFallback(TransformationOptions::provenance($options));
        $this->session->installGeneratedBlockRegistry(new GeneratedBlockRegistry($this->generatedBlockNamespaceFromOptions($options)));
        $this->session->installAssetMaterializationState(new AssetMaterializationState(
            trim((string) ($options['generated_asset_root'] ?? ''), '/'),
            $this->assetMetadataFromOptions($options)
        ));
        $this->session->configurePolicy(! empty($options['extract_global_shell']), ! empty($options['fallback_reduction_mode']));
        $this->session->runtimeBehaviorState()->installRuntimeScriptMetadata($this->runtimeIslands->runtimeScriptMetadataFromOptions($options));
        $this->session->runtimeBehaviorState()->installRuntimeProjectionScriptAssets(
            is_array($options['runtime_projection_script_assets'] ?? null) ? $options['runtime_projection_script_assets'] : array()
        );
        $this->session->runtimeBehaviorState()->installThemePreferenceOwnership(
            is_array($options['theme_preference_ownership'] ?? null) ? $options['theme_preference_ownership'] : array()
        );
        $this->projectedSelectorBindings = array();
        $this->glyphColorUndecodable = array();
        $this->capturedGlyphColors = null;
        $this->documentAuthorCss = '';
        $this->currentSourcePath = (string) ($options['source'] ?? 'html');
        $this->session->installSourcePath($this->currentSourcePath);
        $this->sharedStylesheetPaths = array();
        $this->projectsOnlyMatchedAuthorRules = ! empty($options['project_only_matched_author_rules']);
        $this->nativeDocumentContext = ! empty($options['native_document_context']);
        foreach ( is_array($options['shared_stylesheet_paths'] ?? null) ? $options['shared_stylesheet_paths'] : array() as $path ) {
            if ( is_string($path) && '' !== $path ) {
                $this->sharedStylesheetPaths[$path] = true;
            }
        }
        $staticCss = (string) ($options['static_css'] ?? '');
        $this->capturedRootTheme = $this->documentRootTheme($html);
        $styleAnalysis = $this->stylesheetAnalysisComposer->composedStyleAnalysis(
            $this->stylesheetAnalysisComposer->stylesheetPayloads($html, $staticCss, $options)
        );
        $this->session->sourceStyleResolutionState()->installStylesheetAnalysis(StaticClassPromotions::detect($html), $styleAnalysis);
        $runtimeDomSelectors = $this->runtimeIslands->runtimeSelectorsFromOptions($options, 'runtime_dom_selectors');
        $this->session->installRuntimeSelectorState(new RuntimeSelectorState(
            $runtimeDomSelectors,
            $this->runtimeIslands->runtimeSelectorsFromOptions($options, 'runtime_behavioral_selectors'),
            $this->runtimeIslands->runtimeCanvasSelectorsFromOptions($options)
        ));
        $this->session->installLayoutGeometryState(new LayoutGeometryState(
            is_array($options['layout_geometry_proof']['reductions'] ?? null) ? $options['layout_geometry_proof']['reductions'] : array()
        ));
        $provenance               = array(
            array_merge(array(
                'source_format' => 'html',
                'input_bytes'   => strlen($html),
                'transformer'   => HtmlTransformer::class,
            ), $this->session->transformationProvenanceState()->fallback()),
        );

        $timer->start('transform.source_parse');
        $sourceBodyClasses = $this->documentBodyClassNames($html);
        $this->sourceDocumentHosts = NativeGetFormBlockBuilder::declaredDocumentHosts($html);
        $normalizedHtml = CaptureReplayScripts::withoutReplayScripts(
            $this->normalizeHtml5VoidElements($this->documentBodyHtml($this->normalizeExplicitPlaintextElements($html)))
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
        // Use a static node set: indexed iteration of a live DOMNodeList can
        // repeatedly scan the document as the number of gallery images grows.
        $sourceImages = (new \DOMXPath($document))->query('.//img', $body);
        foreach ( $sourceImages as $image ) {
            $source = $this->imageUrls->sourceUrl($image);
            if ( '' !== $source && $source !== $this->attr($image, 'src') ) {
                $image->setAttribute('src', $source);
            }
        }
        $definitionClips = \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SvgDefinitionClips::replacements($body);
        if (array() !== $definitionClips) {
            foreach ($body->getElementsByTagName('*') as $sourceElement) {
                if ($sourceElement->hasAttribute('style')) {
                    $sourceElement->setAttribute('style', \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SvgDefinitionClips::rewrite($sourceElement->getAttribute('style'), $definitionClips));
                }
            }
        }
        CapturedNavigationPanels::restoreNative($body);
        CapturedNavigationPanels::restoreRestingStates($body);
        // Capture evidence, not content: read before anything serializes the source.
        $this->capturedGlyphColors = CapturedGlyphColor::detach($body);
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
        $rootAttributes = \Automattic\BlocksEngine\PhpTransformer\Support\DocumentRootAttributes::fromHtml($html);
        if (array() !== $rootAttributes) {
            $root = $document->createElement('html');
            foreach ($rootAttributes as $name => $value) $root->setAttribute($name, $value);
            $document->replaceChild($root, $body);
            $root->appendChild($body);
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
        $this->documentAuthorCss = $this->authoredCssText($html, (string) ($options['static_css'] ?? ''));
        $this->unwrapRenderNeutralCustomElements($body, $this->documentAuthorCss);
        $timer->start('transform.author_selector_preparation');
        $this->prepareAuthorSelectorSemantics($html, (string) ($options['static_css'] ?? ''), $body, $options);
        $this->session->fallbackEmitter()->configure($this->session->transformationProvenanceState()->fallback(), $this->session->runtimeBehaviorState()->runtimeScriptMetadata(), $this->session->runtimeSelectorState(), $this->session->authorSelectorProjectionState()->tagMarkers());
        // Author-selector preparation marks source nodes for later projection.
        // General style matching begins only after those source mutations settle.
        $this->session->sourceStyleResolutionState()->invalidateSelectorMatches();
        $styledElements = iterator_to_array((new \DOMXPath($document))->query('.//*[@style]', $body));
        foreach ( $styledElements as $styledElement ) {
            $this->styleResolver->dropAuthorOverriddenInlineDeclarations($styledElement);
        }
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
            $this->collectGeneratedComponentCandidates($body);
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
        $this->commerceReporter->appendProductGridFallbacks($body, $fallbacks, $blocks);
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
                $readableMarkup .= $this->runtime->serializeBlocks(self::withoutDefaultCommentAttributes($fallback['readable_blocks']));
            }
        }
        $this->fallbackBindings->anchorPurchaseControlRuns($blocks, $fallbacks);
        $serializedBlocks = $this->runtime->serializeBlocks(self::withoutDefaultCommentAttributes($blocks));
        $this->fallbackBindings->finalize($fallbacks, $blocks, $serializedBlocks);
        $reusableComponentRecognition = $this->session->reusableComponentState()->report($this->session->assetMaterializationState()->assets());
        // Resolve against emitted blocks while the existing source provenance
        // IDs are still attached. Declaration-addressed classes are rewritten below.
        $authorLayoutTopologyFindings = $this->session->transformationEvidenceState()->authorLayoutTopologyFindings($blocks);
        // Capture ownership before resolveBlockPaths consumes the internal IDs.
        // Shared-shell compilation can materialize a source control more than
        // once; the collector observes each DOM identity only once.
        $buttonElements = array();
        $collectButtonElements = function (array $tree) use (&$collectButtonElements, &$buttonElements): void {
            foreach ($tree as $block) {
                if ('core/button' === ($block['blockName'] ?? '')) {
                    $element = $this->session->transformationProvenanceState()->sourceElement((int) ($block['_source_provenance_id'] ?? 0));
                    if ($element instanceof DOMElement) $buttonElements[] = $element;
                }
                $collectButtonElements($block['innerBlocks'] ?? array());
            }
        };
        $collectButtonElements($blocks);
        $sourceProvenance = $this->session->transformationProvenanceState()->resolveBlockPaths($blocks);
        $responsiveCounterpartContracts = (new ResponsiveCorrespondence())->declare($blocks, $sourceProvenance);
        $this->session->authorSelectorProjectionState()->confirmRichTextCarriers($serializedBlocks);
        $this->emittedStyleMarkup = $serializedBlocks;
        $authorStylesheetProjections = $this->authorStylesheetProjections();
        $runtimeScriptProjections = RuntimeScriptProjections::collect($this->session);
        $timer->start('transform.stylesheet_materialization');
        $this->materializeAuthorStylesheet(
            $html,
            (string) ($options['static_css'] ?? ''),
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
            . ( '' === $this->authorStylesheetContentBoxCss ? '' : "\n\n" . $this->authorStylesheetContentBoxCss )
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
        $this->projectedSelectorBindings = $classIdentity->rewrite($this->projectedSelectorBindings, $classReplacements);
        $this->session->assetMaterializationState()->restore($classIdentity->rewrite($assets, $classReplacements));
        $timer->start('transform.validation.block_validity');
        $blockValidityEvaluation = $this->runtime->evaluateBlockSerialization($blocks);
        $blockValidityReport = $blockValidityEvaluation->report();
        $timer->start('transform.validation.semantic_parity');
        $semanticParityEvaluation = $this->semanticParityReporter->evaluate($body, $blocks, $sourceProvenance, $html, (string) ($options['static_css'] ?? ''));
        $semanticParityReport = $semanticParityEvaluation->report($validationEvidence);
        $timer->start('transform.validation.content_round_trip');
        $contentRoundTripEvaluation = $this->contentRoundTripReporter->evaluate($serializedBlocks, $html, $this->session->transformationEvidenceState()->formControlEchoTexts());
        $contentRoundTripReport = $contentRoundTripEvaluation->report();
        // The round-trip check runs output ⊆ source to catch invented copy. This
        // runs the other way for media, which nothing did: an image demoted to
        // its own `alt` text is not absent, so every loss counter stayed at zero
        // while the page lost its pictures.
        $timer->start('transform.validation.media_retention');
        $sourceMediaRetentionReport = $this->sourceMediaRetentionReporter->evaluate(
            $html,
            $serializedBlocks,
            array_map(
                static fn (array $asset): string => (string) ( $asset['content'] ?? '' ),
                array_filter(
                    $this->session->assetMaterializationState()->assets(),
                    static fn (mixed $asset): bool => is_array($asset) && 'css' === ( $asset['kind'] ?? '' )
                )
            )
        )->report();
        $timer->start('transform.validation.dead_selectors');
        $deadProjectedSelectorHaystack = $serializedBlocks;
        foreach ( $shellArtifacts as $shellArtifact ) {
            $deadProjectedSelectorHaystack .= "\n" . (string) ( $shellArtifact['block_markup'] ?? '' );
        }
        $deadProjectedSelectorEvaluation = $this->deadProjectedSelectorReporter->evaluate(
            $this->projectedSelectorBindings,
            array($this->currentSourcePath => $deadProjectedSelectorHaystack),
            array($this->currentSourcePath => $html)
        );
        $deadProjectedSelectorsReport = $deadProjectedSelectorEvaluation->report();
        $timer->start('transform.diagnostics');
        $validationOutcome = \Automattic\BlocksEngine\PhpTransformer\Contract\HtmlValidationOutcome::fromValidationFacts(
            $blockValidityEvaluation->status,
            $blockValidityEvaluation->findings,
            $semanticParityEvaluation->status(),
            $semanticParityEvaluation->findings,
            $contentRoundTripEvaluation->status(),
            $contentRoundTripEvaluation->findings
        );
        $diagnostics = $this->diagnosticsCollector->collect(
            HtmlTransformer::class,
            $this->session->runtimeBehaviorState()->scriptMetadata(),
            $fallbacks,
            $this->session->runtimeDomState()->islands(),
            $this->session->runtimeDomState()->preservations(),
            $this->session->runtimeDomState()->fallbacks(),
            $validationOutcome
        );
        foreach ( $deadProjectedSelectorEvaluation->findings as $finding ) {
            $diagnostics[] = ConversionFindingContract::withClassification(array_merge($finding, array(
                'source'  => HtmlTransformer::class,
                'message' => (string) ($finding['summary'] ?? ''),
            )));
        }
        foreach ( $this->glyphColorUndecodable as $finding ) {
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
        $generatedCarrierCss = $this->engineSupportCss();
        // Selector projection may install engine marker classes on the analysis
        // document root (root-owned attribute states). Only the real root
        // registry can materialize them, so they travel with the compilation
        // facts instead of dying with the analysis DOM.
        $documentRootMarkers = array();
        $analysisRoot = $document->documentElement;
        if ( $analysisRoot instanceof DOMElement && 'html' === strtolower($analysisRoot->tagName) ) {
            $documentRootMarkers = EngineMarker::all($analysisRoot->getAttribute('class'));
        }
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
            gutenbergGaps: $this->gutenbergGaps($blocks),
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
            'block_validity_report' => $blockValidityReport,
            'semantic_parity_report' => $semanticParityReport,
            'content_round_trip_report' => $contentRoundTripReport,
            'source_media_retention_report' => $sourceMediaRetentionReport,
            'dead_projected_selectors_report' => $deadProjectedSelectorsReport,
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

    private function engineSupportCss(): string
    {
        $css = array();
        foreach ($this->session->assetMaterializationState()->assets() as $asset) if ('engine-support' === ($asset['source'] ?? '') && 'css' === ($asset['kind'] ?? '') && is_string($asset['content'] ?? null)) $css[] = $asset['content'];
        return implode("\n", $css);
    }

    /**
     * Reduce safe legacy core/html islands through the producer's native block
     * recognizers. An island is replaced only when its complete fragment maps
     * to native blocks with no fallback diagnostics; otherwise its serialized
     * payload remains untouched.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    public function reduceCoreHtmlFallbackBlocks(array $blocks, ?DOMElement $sourceRoot = null): array
    {
        $reduced = array();
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $name = is_string($block['blockName'] ?? null) ? $block['blockName'] : '';
            if (in_array($name, array('core/html', 'core/freeform'), true)) {
                $html = is_string($block['attrs']['content'] ?? null)
                    ? $block['attrs']['content']
                    : (is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '');
                $replacement = $this->safeFallbackFragmentBlocks($html, $sourceRoot);
                array_push($reduced, ...($replacement ?? array($block)));
                continue;
            }

            $children = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array();
            if (array() !== $children) {
                $reducedChildren = array();
                $childReplacements = array();
                foreach ($children as $child) {
                    $replacement = $this->reduceCoreHtmlFallbackBlocks(array($child), $sourceRoot);
                    $childReplacements[] = $replacement;
                    array_push($reducedChildren, ...$replacement);
                }
                $block['innerBlocks'] = $reducedChildren;
                $innerContent = array();
                $childIndex = 0;
                foreach (is_array($block['innerContent'] ?? null) ? $block['innerContent'] : array() as $content) {
                    if (null !== $content) {
                        $innerContent[] = $content;
                        continue;
                    }
                    foreach ($childReplacements[$childIndex] ?? array() as $_) {
                        $innerContent[] = null;
                    }
                    ++$childIndex;
                }
                $block['innerContent'] = $innerContent;
                $block['innerHTML'] = implode('', array_filter($innerContent, 'is_string'));
            }
            $reduced[] = $block;
        }

        return $reduced;
    }

    /**
     * @return array<int, array<string, mixed>>|null Null keeps the original island.
     */
    private function safeFallbackFragmentBlocks(string $html, ?DOMElement $sourceRoot = null): ?array
    {
        if ('' === trim($html)) {
            return array();
        }
        if (preg_match('/<\s*(?:script|style|iframe|canvas|svg|form|input|select|textarea)\b/i', $html)) {
            return null;
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<!doctype html><html><body>' . $html . '</body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($loaded) {
            foreach ($document->getElementsByTagName('*') as $element) {
                if ($element instanceof DOMElement && $this->runtimeIslands->isRuntimeDomTarget($element)) {
                    return null;
                }
            }
        }

        // Island reduction reparses a detached fragment, so ancestor selectors
        // no longer match. Resolve box modifiers against the original source
        // and pass that evidence to the same button attribute projector.
        $sourceBoxes = array();
        if ( $loaded && $sourceRoot instanceof DOMElement ) {
            foreach ( $document->getElementsByTagName('a') as $anchor ) {
                if ( ! $anchor instanceof DOMElement || '' === $this->attr($anchor, 'id') ) {
                    continue;
                }
                foreach ( $sourceRoot->getElementsByTagName('a') as $sourceAnchor ) {
                    if ( $sourceAnchor instanceof DOMElement && $this->attr($sourceAnchor, 'id') === $this->attr($anchor, 'id') ) {
                        $sourceBoxes[$this->attr($anchor, 'id')] = $this->sourceBlockAttributeProjector->layoutNeutralButtonSourceBoxClasses($sourceAnchor);
                        break;
                    }
                }
            }
        }
        $fragmentCompilation = new self($this->runtime, $this->analysisCache);
        $fragmentCompilation->reducedButtonSourceBoxes = $sourceBoxes;
        $result = $fragmentCompilation->transform($html, array('extract_global_shell' => false, 'fallback_reduction_mode' => true));
        $data = $result->toArray();
        $blocks = is_array($data['blocks'] ?? null) ? $data['blocks'] : array();
        if (array() === $blocks || array() !== ($data['fallbacks'] ?? array())) {
            return null;
        }
        foreach ($blocks as $block) {
            if (!is_array($block) || !str_starts_with((string) ($block['blockName'] ?? ''), 'core/') || in_array($block['blockName'] ?? '', array('core/html', 'core/freeform'), true)) {
                return null;
            }
        }

        if ( $this->session->hasAssetMaterializationState() ) {
            // Fragment-owned support remains in the compiler's structured
            // records until acceptance. Commit only records whose source
            // identities survive in the returned blocks; a rejected exploratory
            // compilation never reaches this boundary.
            $this->session->generatedSupportStylesheetState()->commitAcceptedFrom(
                $fragmentCompilation->session->generatedSupportStylesheetState(),
                $blocks
            );
        }

        return $blocks;
    }

    private function reusableComponentFingerprintFor(DOMElement $element): ?string
    {
        return $this->session->reusableComponentState()->fingerprintForPath((string) $element->getNodePath());
    }

    /**
     * Remove custom elements a source uses as a wrapping convention.
     *
     * Site builders wrap ordinary content in presentation-only custom elements.
     * A custom element tag is not evidence that its subtree needs a generated
     * block, so leaving those wrappers in place freezes headings and copy into
     * opaque markup. A tag is treated as a convention when it recurs across the
     * document while the content it wraps diverges: a genuine component repeats
     * its internal structure, a wrapper does not.
     */
    private function unwrapRenderNeutralCustomElements(DOMElement $body, string $staticCss): void
    {
        $this->retainedCustomWrapperHooks = array();
        $instances = array();
        foreach ( $body->getElementsByTagName('*') as $element ) {
            if ( $element instanceof DOMElement && str_contains(strtolower($element->tagName), '-') ) {
                $instances[strtolower($element->tagName)][] = $element;
            }
        }

        $attributeSelectors = null;
        foreach ( $instances as $tag => $elements ) {
            if ( ! $this->wrapperConventions->isWrapperConventionTag($tag, $elements, $staticCss) ) {
                continue;
            }
            $attributeSelectors ??= $this->wrapperConventions->authorDataAttributeSelectors($staticCss);
            foreach ( $elements as $element ) {
                if ( $this->renderNeutral->isRenderNeutralWrapper($element, false) ) {
                    if ( $this->wrapperConventions->authorAttributeSelectorAddresses($element, $attributeSelectors) ) {
                        $this->retainedCustomWrapperHooks[] = $element;
                    } else {
                        $this->richTextStylingHooks()->unwrapElement($element);
                    }
                }
            }
        }
    }

    /**
     * Author CSS visible to the document: declared stylesheets plus the styles
     * the source embeds inline.
     */
    private function authoredCssText(string $html, string $staticCss): string
    {
        return $this->stylesheetAnalysisComposer->combinedAuthorStylesheet($html, $staticCss);
    }

    private function collectGeneratedComponentCandidates(DOMElement $element, int $depth = 0): void
    {
        if (self::GENERATED_COMPONENT_MIN_SOURCE_DEPTH <= $depth
            && ('div' === strtolower($element->tagName) || str_contains(strtolower($element->tagName), '-'))
            && ($this->sourceElementClassifier->hasRepeatedDirectChildTags($element) || str_contains(strtolower($element->tagName), '-'))
            && ($this->session->fallbackEmitter()->isRepeatableContentComponent($element) || $this->session->fallbackEmitter()->isSafeCustomElementHost($element))
            && ! $this->session->fallbackEmitter()->containsProductGrid($element)
        ) {
            $this->session->reusableComponentState()->markGeneratedCandidate((string) $element->getNodePath());
            return;
        }

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $this->collectGeneratedComponentCandidates($child, $depth + 1);
            }
        }
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    private function nativeOrGeneratedComponentBlock(DOMElement $element, array &$fallbacks): ?array
    {
        $nativeFallbacks = array();
        $hasRuntimeTarget = false;
        foreach (array_merge(array($element), SourceDom::descendantElements($element)) as $target) {
            $hasRuntimeTarget = $hasRuntimeTarget || $this->runtimeIslands->isRuntimeDomTarget($target);
        }
        // A defining script or an exact runtime DOM contract cannot be proven
        // editable just by inspecting the static children of its host.
        $native = $hasRuntimeTarget || str_contains($element->tagName, '-')
            ? null
            : $this->authorLayoutBlockFromElement($element, $nativeFallbacks);
        $reason = 'generated_component_non_native_items';
        if (null !== $native && array() === $nativeFallbacks && $this->isEntirelyNativeComponent($native)) {
            return $native;
        }
        $generated = $this->session->fallbackEmitter()->maybeGenerateCustomBlock($element, $this->session->requireGeneratedBlockRegistry(), true, true);
        if (null === $generated) {
            return null;
        }
        // Explain the preserved editing surface without adding conversion loss
        // to the fallbacks list that drives quality fallback_count.
        $this->session->transformationEvidenceState()->recordConversionDiagnostic(array_merge(array(
            'type' => 'generated_component',
            'severity' => 'info',
            'conversion_classification' => 'editable_approximation',
            'reason' => $reason,
            'code' => $reason,
            'diagnostic_code' => $reason,
            'message' => 'Repeated content retained as generated raw HTML: ' . $reason . '.',
            'source_format' => 'html',
            'tag' => strtolower($element->tagName),
            'selector' => $this->elementSelector($element),
        ), $this->session->transformationProvenanceState()->fallback()));
        return $this->generatedComponentBlock($generated, $element);
    }

    /** @param array<string, mixed> $block */
    private function isEntirelyNativeComponent(array $block): bool
    {
        $name = (string) ($block['blockName'] ?? '');
        $rawHtmlNames = GeneratedBlockEditingSurface::rawHtmlBlockNames($this->session->requireGeneratedBlockRegistry()->definitions());
        $nativeCore = str_starts_with($name, 'core/') && ! in_array($name, array('core/html', 'core/freeform'), true);
        $structuredGenerated = str_starts_with($name, $this->session->requireGeneratedBlockRegistry()->namespace() . '/') && ! isset($rawHtmlNames[$name]);
        if (! $nativeCore && ! $structuredGenerated) {
            return false;
        }
        foreach ($block['innerBlocks'] ?? array() as $child) {
            if (! $this->isEntirelyNativeComponent($child)) {
                return false;
            }
        }
        return true;
    }

    private function containsGeneratedComponentCandidate(DOMElement $element): bool
    {
        foreach (SourceDom::descendantElements($element) as $descendant) {
            if ($this->isGeneratedComponentCandidate($descendant)) return true;
        }
        return false;
    }

    private function isGeneratedComponentCandidate(DOMElement $element): bool
    {
        return $this->session->reusableComponentState()->isGeneratedCandidate((string) $element->getNodePath());
    }

    /** @param array{blockName: string, attrs: array<string, mixed>} $generated @return array<string, mixed> */
    private function generatedComponentBlock(array $generated, DOMElement $element): array
    {
        $block = $this->createBlock($generated['blockName'], $generated['attrs'], array(), $element);
        foreach (array_merge(array($element), SourceDom::descendantElements($element)) as $target) {
            if (! $this->runtimeIslands->isRuntimeDomTarget($target)) {
                continue;
            }
            $block['_editability_runtime_owned'] = true;
            $this->runtimeIslands->recordNativeRuntimeDomPreservation($target, $generated['blockName']);
        }
        return $block;
    }

    /**
     * @param array<int, array<string, mixed>> $sourceProvenance
     * @param list<array{path: string, content: string, bytes: int, hash: string, source_hash: string}> $authorStylesheetProjections
     */
    private function materializeAuthorStylesheet(string $html, string $staticCss, bool $includeAuthorStyles = true, string $serializedBlocks = '', array $sourceProvenance = array(), array $authorStylesheetProjections = array(), ?string $sourceLayerOrder = null): void
    {
        $beforeAuthorCssParts = array();
        $authorCssParts = array();
        $afterAuthorCss = new LayeredCssCollector();
        $authorCss = '';
        // Content-box restatements are support CSS: they reach the
        // after-author stylesheet even when the host keeps its own author CSS
        // (or, as the artifact compiler does, installs the projected
        // stylesheet assets itself).
        $contentBoxSupportCss = $this->authorStylesheetContentBoxCss;
        if ( $includeAuthorStyles && '' !== $this->session->authorStyleAnalysis()->combinedCss() ) {
            $contentBoxRestatements = new ContentBoxRestatements();
            $authorCss = $this->rewriteAuthorStylesheet(
                $this->session->authorStyleAnalysis()->combinedCss(),
                false,
                '',
                array() === $this->session->authorStyleAnalysis()->stylesheetAssets(),
                '',
                $combinedNativeSelectors,
                $contentBoxRestatements
            );
            if ( array() === $this->session->authorStyleAnalysis()->stylesheetAssets() ) {
                $contentBoxSupportCss = $contentBoxRestatements->css();
            }
            $split = ( new CssStylesheetTransformer() )->splitLeadingAtRulePreamble($authorCss);
            if ( array() === $this->session->authorStyleAnalysis()->stylesheetAssets() ) {
                if ( '' !== trim($split['preamble']) ) {
                    $authorCssParts[] = $split['preamble'];
                }
                $authorCssParts[] = $split['stylesheet'];
            }
        }
        $afterAuthorCss->add(CascadeLayer::SOURCE_BOX_MODEL_RESTATEMENT, $contentBoxSupportCss);
        $geometryCss = $this->styleResolver->generatedGeometryCss($serializedBlocks);
        if ( '' !== $geometryCss ) {
            // Important carrier rules precede author CSS: they retain inline
            // precedence over normal selectors while authored !important rules
            // remain able to override them.
            $beforeAuthorCssParts[] = $geometryCss;
        }
        $markerReset = $this->richTextMarkerResetCss();
        if ( '' !== $markerReset ) {
            $beforeAuthorCssParts[] = $markerReset;
        }
        $engineSupportCss = new EngineSupportCss();
        $engineResetParts = $engineSupportCss->beforeAuthorCss($serializedBlocks);
        $engineResetStart = count($beforeAuthorCssParts);
        array_push($beforeAuthorCssParts, ...$engineResetParts);
        $coreDefaultResetParts = $engineSupportCss->coreDefaultResetCss($serializedBlocks);
        $coreDefaultResetStart = count($beforeAuthorCssParts);
        array_push($beforeAuthorCssParts, ...$coreDefaultResetParts);
        array_push($beforeAuthorCssParts, ...$engineSupportCss->coreOverrideCss($serializedBlocks));
        // Precedence for everything below is declared on each contribution's
        // CascadeLayer (see CascadeLayer), not by the order these calls
        // appear in. $afterAuthorCss orders the collected stylesheet by that
        // declared layer once every contribution below has been collected.
        $afterAuthorCss->absorb($engineSupportCss->generatedMarkupRepairCss($serializedBlocks));
        $afterAuthorCss->absorb($this->navigationStyleProjector->navigationLinkTextColorRules($serializedBlocks));
        $afterAuthorCss->absorb($this->navigationStyleProjector->navigationLinkBoxRules($serializedBlocks));
        $afterAuthorCss->absorb($this->navigationStyleProjector->navigationAnchorLineHeightRules($serializedBlocks));
        $afterAuthorCss->addAll(CascadeLayer::SOURCE_STYLE_PROJECTION, $this->session->sourceTargetProjectionState()->rules());
        $afterAuthorCss->absorb($this->navigationStyleProjector->navigationLinkIconRules($serializedBlocks));
        $afterAuthorCss->absorb($this->navigationStyleProjector->navigationLinkLeadingIconRules($serializedBlocks));
        $afterAuthorCss->absorb($engineSupportCss->socialLinkCss($serializedBlocks));
        $afterAuthorCss->absorb($engineSupportCss->deviceVisibilityCss($serializedBlocks));
        $afterAuthorCss->absorb($this->session->generatedSupportStylesheetState()->conditionalAfterAuthorCss($serializedBlocks));
        $nativeSearchTriggerCss = $this->session->generatedSupportStylesheetState()->beforeAuthorCss($serializedBlocks);
        if ( '' !== $nativeSearchTriggerCss ) {
            $beforeAuthorCssParts[] = $nativeSearchTriggerCss;
        }
        if ( '' !== trim($authorCss) ) {
            $authorCssParts[] = $authorCss;
            $afterAuthorCss->add(CascadeLayer::VIEWPORT_CHROME_COMPAT, (new AdminBarAccommodation())->supportCss($authorCss));
            $afterAuthorCss->add(CascadeLayer::VIEWPORT_CHROME_COMPAT, (new TemplatePartStickyContainment())->supportCss($authorCss));
        }
        if ( str_contains($serializedBlocks, 'blocks-engine-list-navigation') ) {
            $mobileOverlayBackground = $this->navigationStyleProjector->sourceMobileNavigationOverlayBackground();
            $afterAuthorCss->absorb($engineSupportCss->listNavigationHostRepairCss($serializedBlocks, $mobileOverlayBackground));
            $afterAuthorCss->absorb($this->navigationStyleProjector->listNavigationInlineMarginRules($serializedBlocks));
            $afterAuthorCss->absorb($this->navigationStyleProjector->listNavigationPaddingRules($serializedBlocks));
            $afterAuthorCss->absorb($this->navigationStyleProjector->listNavigationItemAnchorRules($serializedBlocks, $sourceProvenance));
            $afterAuthorCss->absorb($engineSupportCss->listNavigationOverlayRepairCss($serializedBlocks, $mobileOverlayBackground));
        }
        $afterAuthorCss->absorb($engineSupportCss->secondaryBlockRenderRepairCss($serializedBlocks));
        $afterAuthorCss->absorb($this->navigationStyleProjector->navigationItemStateAnchorRules($serializedBlocks, $sourceProvenance));
        $afterAuthorCss->absorb($this->navigationStyleProjector->directNavigationSupportCss($serializedBlocks));
        $afterAuthorCss->absorb($this->session->generatedSupportStylesheetState()->buttonAfterAuthorCss());
        $afterAuthorCss->addAll(CascadeLayer::CLOSED_STATE_REPAIR, $this->styleResolver->closedStateRepairCssRules());
        // A captured reveal whose driver did not survive import must still
        // settle at the appearance it was travelling towards, not at the hidden
        // keyframe it starts from (#239). Read the projected author CSS the
        // theme actually ships: when the artifact pipeline materializes the
        // author stylesheets itself, that is the per-asset projection rather
        // than the copy inlined here, and only the projected form carries the
        // state markers the repair has to reproduce in its selectors.
        $settleableAuthorCss = '' !== trim($authorCss)
            ? $authorCss
            : implode("\n\n", array_column($authorStylesheetProjections, 'content'));
        // Read replay bindings from the input markup: leaf attributes may have
        // moved into render metadata by the time blocks are serialized.
        $afterAuthorCss->addAll(CascadeLayer::REVEAL_SETTLE, ( new RevealAnimationSettler() )->settleRules($settleableAuthorCss, '', $html));
        // DOMDocument synthesizes a body for fragments; its margin belongs to
        // the host document, not to the imported content.
        $bodyDeclarations = preg_match('/<(?:html|body)\b/i', $html)
            ? $this->styleResolver->authorStructuralDeclarations($this->session->authorStyleAnalysis()->sourceBody())
            : null;
        $afterAuthorCss->add(CascadeLayer::VIEWPORT_CHROME_COMPAT, ( new EditorCanvasBodyBackground() )->supportCss($settleableAuthorCss, $bodyDeclarations));
        // Engine support CSS may join an author cascade layer, and naming a
        // layer registers it. The editor injects author CSS after the enqueued
        // editor styles, so that registration would otherwise arrive first and
        // invert the author's layer order — letting an author reset layer beat
        // the author utilities that centre and pad every section. Stating the
        // author's own order ahead of any engine CSS pins it in both contexts.
        // Artifact compilation has the canonical import graph. Its authoritative
        // empty statement means anonymous source positions cannot be predeclared.
        $authorLayerOrder = $sourceLayerOrder ?? ( new AuthorCascadeLayerOrder() )->statement($settleableAuthorCss);
        // Layered author rules lose to unlayered ones whatever their specificity,
        // so once the author uses layers the engine resets join a layer registered
        // ahead of every author layer. `!important` parts stay unlayered: there
        // the first layer would win and override authored !important rules. When
        // the statement is '' (anonymous author layers) nothing is wrapped, since
        // naming an engine layer could shift anonymous layer positions. Layers in
        // the capture vocabulary are not author layering: a capture-only layer
        // leaves the author's rules unlayered, and those must keep losing to the
        // engine resets by specificity.
        if ( $this->namesAuthorOwnedLayer($authorLayerOrder) ) {
            foreach ( $engineResetParts as $offset => $part ) {
                if ( ! str_contains($part, '!important') ) {
                    $beforeAuthorCssParts[$engineResetStart + $offset] = '@layer ' . self::ENGINE_RESET_LAYER . '{' . $part . '}';
                }
            }
            foreach ( $coreDefaultResetParts as $offset => $part ) {
                // Beat Core's unlayered default, but roll back to the author
                // layers when they declare this margin. The engine layer is
                // the fallback only when no author layer owns the property.
                $bridge = preg_replace('/(?<=[{:;])0(?=[;}])/', 'revert-layer', $part);
                $beforeAuthorCssParts[$coreDefaultResetStart + $offset] = '@layer ' . self::ENGINE_RESET_LAYER . '{' . $part . '}' . $bridge;
            }
            $authorLayerOrder = '@layer ' . self::ENGINE_RESET_LAYER . ',' . substr($authorLayerOrder, strlen('@layer '));
        }
        if ( '' !== $authorLayerOrder ) {
            array_unshift($beforeAuthorCssParts, $authorLayerOrder);
        }
        $this->stylesheetAssetStage->materialize(
            $beforeAuthorCssParts,
            $authorCssParts,
            $afterAuthorCss->orderedCss(),
            $authorStylesheetProjections,
            $includeAuthorStyles,
            array() !== $this->session->authorStyleAnalysis()->stylesheetAssets()
        );
    }

    private function namesAuthorOwnedLayer(string $layerOrderStatement): bool
    {
        $names = explode(',', rtrim(substr($layerOrderStatement, strlen('@layer ')), ';'));
        foreach ( $names as $name ) {
            $name = trim($name);
            if ( '' !== $name && ! str_starts_with($name, CaptureEvidence::ATTRIBUTE_PREFIX) ) {
                return true;
            }
        }
        return false;
    }

    private function richTextMarkerResetCss(): string
    {
        if ( ! $this->session->authorSelectorProjectionState()->hasRichTextMarkers() ) {
            return '';
        }

        // Specificity (0,0,2): above an author rule that selects `mark` by tag
        // alone (Bootstrap's reboot, older normalize.css), which never matched
        // the source span a marker replaces, and below any author class rule
        // wherever its stylesheet lands. A captured document can keep author
        // styles in its own <head>, ahead of this before-author asset.
        return 'html mark:where([style*="--blocks-engine-richtext-marker:"]){background-color:transparent;color:inherit}';
    }

    /** @param array<string, mixed> $options */
    private function prepareAuthorSelectorSemantics(string $html, string $staticCss, DOMElement $sourceBody, array $options): void
    {
        $this->authorSelectorSemanticPreparer->prepare($html, $staticCss, $sourceBody, $options, $this->session);
    }

    /** @return list<array{path: string, content: string, bytes: int, hash: string, source_hash: string}> */
    private function authorStylesheetProjections(): array
    {
        $projections = array();
        $contentBoxRestatements = new ContentBoxRestatements();
        foreach ( $this->session->authorStyleAnalysis()->stylesheetAssets() as $asset ) {
            $content = $this->rewriteAuthorStylesheet(
                $asset['content'],
                isset($this->sharedStylesheetPaths[$asset['path']])
                    || isset($this->sharedStylesheetPaths[$asset['source_path'] ?? '']),
                (string) $asset['path'],
                true,
                (string) ($asset['media'] ?? ''),
                $nativeSelectors,
                $contentBoxRestatements
            );
            $hash = hash('sha256', $content);
            $projections[] = array(
                'path'        => $asset['path'],
                'content'     => $content,
                'bytes'       => strlen($content),
                'hash'        => $hash,
                'source_hash' => $asset['source_hash'],
                'source_path' => $asset['source_path'],
                'media'       => $asset['media'],
                'type'        => $asset['type'] ?? '',
                'attribute_state_markers' => $this->session->authorSelectorProjectionState()->attributeNegationMarkers(),
                'native_selectors' => $nativeSelectors,
            );
        }
        $this->authorStylesheetContentBoxCss = $contentBoxRestatements->css();
        return $projections;
    }

    /**
     * `$media` is the stylesheet's link/style media attribute. Source analysis
     * reads the asset as `@media <media>{...}`, so projection is told the same
     * outer condition for rules that compare against analyzed declarations.
     */
    private function rewriteAuthorStylesheet(string $stylesheet, bool $keepAuthorClassSelectors = false, string $stylesheetPath = '', bool $recordBindings = true, string $media = '', ?array &$nativeSelectors = null, ?ContentBoxRestatements $contentBoxRestatements = null): string
    {
        $bindings = new ProjectedSelectorBindings();
        $media = trim($media);
        $projected = $this->authorStylesheetProjector->project(
            $stylesheet,
            new AuthorStylesheetProjectionContext(
                $this->session->authorStyleAnalysis(),
                $this->session->sourceStyleResolutionState(),
                $this->session->authorSelectorProjectionState(),
                $this->session->transformationEvidenceState(),
                $keepAuthorClassSelectors,
                $bindings,
                $this->emittedStyleMarkup,
                $contentBoxRestatements ?? new ContentBoxRestatements(),
                $this->projectsOnlyMatchedAuthorRules,
                $this->nativeDocumentContext
            ),
            '' === $media ? array() : array( '@media ' . $media )
        );
        $nativeSelectors = $bindings->nativeSelectors();
        if ( $recordBindings ) {
            foreach ( $bindings->all() as $binding ) {
                $this->projectedSelectorBindings[] = array(
                    'authored'    => $binding['authored'],
                    'projected'   => $binding['projected'],
                    'stylesheet'  => $stylesheetPath,
                    'scope'       => $this->currentSourcePath,
                );
            }
        }

        return $projected;
    }

    /** @return array<string, mixed> */
    private function parsedCssSelector(string $selector): array
    {
        return $this->session->sourceStyleResolutionState()->parsedSelector($selector);
    }

    private function isRepresentableTable(DOMElement $table): bool
    {
        $id = spl_object_id($table);
        return $this->session->authorSelectorProjectionState()->tableRepresentable(
            $id,
            fn (): bool => (bool) $this->tableClassificationPolicy->classify($table)['representable']
        );
    }

    private function normalizeHtml5VoidElements(string $html): string
    {
        return preg_replace('/<source\b([^>]*?)(?<!\/)\s*>/i', '<source$1></source>', $html) ?? $html;
    }

    private function normalizeExplicitPlaintextElements(string $html): string
    {
        $html = preg_replace_callback(
            '/<plaintext\b([^>]*)>(.*?)<\/plaintext\s*>/is',
            static fn (array $matches): string => '<pre' . $matches[1] . '>' . str_replace('<', '&lt;', $matches[2]) . '</pre>',
            $html
        ) ?? $html;

        // A real (unclosed) plaintext element consumes everything to EOF,
        // including entities and apparent closing body/html tags. Normalize it
        // before serializing and reparsing the document body: libxml's saved
        // </plaintext> would otherwise become extra visible text on that pass.
        return preg_replace_callback(
            '/<plaintext\b([^>]*)>(.*)\z/is',
            static fn (array $matches): string => '<pre' . $matches[1] . '>' . htmlspecialchars($matches[2], ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>',
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

        return $this->styleResolver->innerHtml($body);
    }

    private function documentRootTheme(string $html): string
    {
        if ( ! preg_match('/<html\b[^>]*\bclass\s*=\s*(["\'])(.*?)\1/is', $html, $match) ) {
            return '';
        }
        $classes = preg_split('/\s+/', trim(html_entity_decode($match[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))) ?: array();
        if ( in_array('dark', $classes, true) ) {
            return 'dark';
        }
        return in_array('light', $classes, true) ? 'light' : '';
    }

    /** @return list<string> */
    private function documentBodyClassNames(string $html): array
    {
        if ( ! preg_match('/<body\b/i', $html) ) {
            return array();
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $body = $loaded ? $document->getElementsByTagName('body')->item(0) : null;
        if ( ! $body instanceof DOMElement ) {
            return array();
        }

        return array_values(array_filter(array_unique(preg_split('/\s+/', trim($this->attr($body, 'class'))) ?: array())));
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<int, array<string, mixed>>
     */
    private function hasWordPressTemplateParts(DOMElement $root): bool
    {
        $areas = array();
        foreach ($root->childNodes as $child) {
            if (!$child instanceof DOMElement) continue;
            if (in_array('wp-block-template-part', preg_split('/\s+/', trim($child->getAttribute('class'))) ?: array(), true)) $areas[strtolower($child->tagName)] = true;
        }
        return isset($areas['header'], $areas['footer']);
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
                    $blocks = array_merge($blocks, $this->convertText($text));
                    $lineIndex = $this->inlineParagraphIndex($blocks);
                }
                continue;
            }

            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            if ( 'br' === strtolower($child->tagName) && null !== $lineIndex && $this->breakEndsInlineLine($child) ) {
                $blocks[$lineIndex] = self::endLine($blocks[$lineIndex]);
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
                && $this->hasWordPressTemplateParts($child)
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
                $lineIndex = $blockCount + 1 === count($blocks) ? $this->inlineParagraphIndex($blocks) : null;
            } elseif ( $this->sourceElementClassifier->isRuntimeMediaSurfaceElement($child) ) {
                $ownerSelector = $this->elementSelector($child);
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
    /**
     * The index of the last block when it is a synthetic paragraph standing
     * for inline source content: it renders inline, so a `<br>` after the
     * source run it stands for has to end the line inside it.
     *
     * @param array<int, array<string, mixed>> $blocks
     */
    private function inlineParagraphIndex(array $blocks): ?int
    {
        $index = array_key_last($blocks);
        $block = null === $index ? null : $blocks[$index];
        if ( ! is_array($block) || 'core/paragraph' !== ($block['blockName'] ?? null) || ! str_ends_with((string) ($block['innerHTML'] ?? ''), '</p>') || array() !== ($block['innerBlocks'] ?? array()) ) {
            return null;
        }
        $classes = preg_split('/\s+/', (string) ($block['attrs']['className'] ?? '')) ?: array();
        return in_array(self::SYNTHETIC_PARAGRAPH_CLASS, $classes, true) && ! in_array(SourceBlockAttributeProjector::SOURCE_BOX_PARAGRAPH_CLASS, $classes, true)
            ? $index
            : null;
    }

    /**
     * @param array<string, mixed> $paragraph
     * @return array<string, mixed>
     */
    private static function endLine(array $paragraph): array
    {
        $html = substr((string) $paragraph['innerHTML'], 0, -4) . '<br></p>';
        $paragraph['innerHTML'] = $html;
        $paragraph['innerContent'] = array( $html );
        if ( isset($paragraph['attrs']['content']) ) {
            $paragraph['attrs']['content'] .= '<br>';
        }
        return $paragraph;
    }

    /** A `<br>` breaks a line only in flow: a flex or grid parent blockifies each run into its own item. */
    private function breakEndsInlineLine(DOMElement $break): bool
    {
        $parent = $break->parentNode;
        $display = $parent instanceof DOMElement ? strtolower($this->styleResolver->resolvedPresentationDeclarations($parent)['display'] ?? 'block') : 'block';
        return ! in_array($display, array( 'flex', 'inline-flex', 'grid', 'inline-grid' ), true);
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    private function recordDependentRuntimeMediaMaskLoss(DOMElement $owner, DOMElement $mask, array &$fallbacks, int $fallbackIndex): void
    {
        $boundedHtml = $this->boundedFallbackHtml(SourceDom::safeFallbackHtml($mask, $this->session->authorSelectorProjectionState()->tagMarkers()));
        $fallbacks[$fallbackIndex]['dependent_losses'][] = array(
            'relationship' => 'runtime_media_mask',
            'disposition' => 'omitted',
            'reason' => 'owner_surface_unsupported',
            'owner_selector' => $this->elementSelector($owner),
            'selector' => $this->elementSelector($mask),
            'tag' => 'svg',
            'html' => $boundedHtml['html'],
            'html_bytes' => $boundedHtml['bytes'],
            'html_truncated' => $boundedHtml['truncated'],
        );
    }

    private function recordGlyphColorUndecodable(string $mimeType, string $src): void
    {
        $format = '' !== $mimeType ? strtolower($mimeType) : 'unknown';
        $this->glyphColorUndecodable[$format . "\0" . $src] = array(
            'code'        => 'glyph_color_undecodable',
            'severity'    => 'warning',
            'message'     => 'A social icon glyph could not be decoded by this PHP image library, so its authored icon colour was not carried and core default icon colours apply.',
            'source'      => HtmlTransformer::class,
            'mime_type'   => $format,
            'src'         => $src,
            'source_path' => $this->currentSourcePath,
        );
    }

    /** @return array<string, mixed> */
    private function socialAnchorColors(DOMElement $element): array
    {
        $declarations = $this->styleResolver->resolvedPresentationDeclarations($element);
        if ( '' === trim($declarations['color'] ?? '') ) {
            $declarations['color'] = $this->styleResolver->authoredInheritedPropertyWinner($element, 'color');
        }
        $declarations['color'] = trim((string) preg_replace('/\s*!important\s*$/i', '', $declarations['color']));
        $mapped = (new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleAttributeMapper())->map(
            array_intersect_key($declarations, array_flip(array('color', 'background', 'background-color'))),
            fn (string $value): string => $this->styleResolver->resolveCssVariablesInValue($value, $element)
        );
        return array_merge($mapped['attrs'] ?? array(), array('style' => $mapped['style'] ?? array()));
    }

    private function createPatternContext(): PatternContext
    {
        return new PatternContext(
            fn (DOMElement $sourceElement, array $excludedGeometryProperties = array()): array => $this->styleResolver->presentationAttributes($sourceElement, $excludedGeometryProperties),
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
            new MediaPatternContext(
                fn (DOMElement $sourceElement): string => $this->styleResolver->coverPresentationStyle($sourceElement),
                fn (string $url): string => $this->imageUrls->resolvedAssetUrl($url),
                fn (DOMElement $sourceElement, array $excludedGeometryProperties = array()): array => array_merge(
                    $this->styleResolver->mediaTextPresentationAttributes($sourceElement, $excludedGeometryProperties),
                    array( 'mediaTextImageMarker' => '' === $this->session->authorStyleAnalysis()->combinedCss()
                        ? ''
                        : $this->session->authorSelectorProjectionState()->ensureMediaTextImageMarker($sourceElement->getNodePath() ?? '') )
                ),
                $this->styleResolver,
                fn (DOMElement $image): array => $this->imageDimensions->fixedPixelSize($image)
            ),
            new ColumnsPatternContext(
                fn (DOMElement $sourceElement): string => $this->styleResolver->cssDeclarationString($this->styleResolver->structuralPresentationDeclarations($sourceElement)),
                $this->styleResolver
            ),
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
            new QuotePatternContext(
                fn (DOMElement $sourceElement): string => $this->citationFromElement($sourceElement)
            ),
            new CodeWindowPatternContext($this->codePresentation ??= new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CodePresentation($this->styleResolver)),
            new LogoPatternContext(
                $this->styleResolver,
                function (DOMElement $element, array &$fallbacks): void { $this->captureInlineSvgFallback($element, $fallbacks); }
            ),
            new GalleryPatternContext(
                fn (DOMElement $image, ?DOMElement $figure = null, ?DOMElement $picture = null, ?DOMElement $link = null): ?array => $this->imageLowering->convertImage($image, $figure, $picture, $link),
                fn (DOMElement $picture, ?DOMElement $figure = null, ?DOMElement $link = null): ?array => $this->convertPictureElement($picture, $figure, $link),
                fn (DOMElement $figure): ?DOMElement => $this->figureLinkedMediaAnchor($figure)
            ),
            fn (DOMElement $sourceElement): bool => $sourceElement->hasAttribute('hidden')
                || 'true' === strtolower(trim($this->attr($sourceElement, 'aria-hidden')))
                || $this->sourceVisibility->startsHidden($sourceElement),
            new DisclosureControlPresentation(
                $this->styleResolver,
                $this->session->generatedSupportStylesheetState(),
                fn (DOMElement $element): string => SvgMaterializer::ensureSvgImageNamespace($this->svgMaterializer->restoreSvgCasing($this->sanitizeInlineSvgMarkup($element)))
            ),
            $this->styleResolver,
            $this->session,
            fn (string $mimeType, string $src) => $this->recordGlyphColorUndecodable($mimeType, $src),
            fn (DOMElement $glyph): string => null !== $this->capturedGlyphColors && isset($this->capturedGlyphColors[$glyph]) ? $this->capturedGlyphColors[$glyph] : '',
            fn (DOMElement $sourceElement): string => $this->inlineRichTextContent($sourceElement),
            fn (DOMElement $sourceElement): array => $this->socialAnchorColors($sourceElement)
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

    /** @param list<class-string<\Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternRecognizerInterface>> $allowed */
    private function recognizePatterns(DOMElement $element, array &$fallbacks, array $allowed): ?array
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
            presentationAttributes: fn (DOMElement $sourceElement, array $excludedGeometryProperties = array()): array => $this->styleResolver->presentationAttributes($sourceElement, $excludedGeometryProperties),
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
            galleryContext: new GalleryPatternContext(
                fn (DOMElement $image, ?DOMElement $figure = null, ?DOMElement $picture = null, ?DOMElement $link = null): ?array => $this->imageLowering->convertImage($image, $figure, $picture, $link),
                fn (DOMElement $picture, ?DOMElement $figure = null, ?DOMElement $link = null): ?array => $this->convertPictureElement($picture, $figure, $link),
                fn (DOMElement $figure): ?DOMElement => $this->figureLinkedMediaAnchor($figure)
            ),
            sourceElementStartsHidden: fn (DOMElement $sourceElement): bool => $sourceElement->hasAttribute('hidden')
                || 'true' === strtolower(trim($this->attr($sourceElement, 'aria-hidden')))
                || $this->sourceVisibility->startsHidden($sourceElement),
             disclosure: new DisclosureControlPresentation(
                 $this->styleResolver,
                 $this->session->generatedSupportStylesheetState(),
                 fn (DOMElement $element): string => SvgMaterializer::ensureSvgImageNamespace($this->svgMaterializer->restoreSvgCasing($this->sanitizeInlineSvgMarkup($element)))
             ),
             socialAnchorColors: fn (DOMElement $sourceElement): array => $this->socialAnchorColors($sourceElement),
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

        // WordPress post navigation is a dynamic post primitive, not a menu.
        $classes = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: array();
        if (in_array('wp-block-post-navigation-link', $classes, true)) {
            foreach ($element->getElementsByTagName('a') as $link) {
                if (!in_array($link->getAttribute('rel'), array('prev', 'next'), true)) continue;
                $title = null;
                $label = '';
                foreach ($link->childNodes as $child) {
                    $class = $child instanceof DOMElement ? $child->getAttribute('class') : '';
                    if (str_contains($class, 'post-navigation-link__title')) { $title = $child; continue; }
                    if (str_contains($class, 'post-navigation-link__arrow')) continue;
                    $label .= $child->textContent;
                }
                // The arrow modifier sits on the arrow span; older markup carries it on the wrapper.
                $arrow = 'none';
                foreach (array_merge(array($element), iterator_to_array($element->getElementsByTagName('span'))) as $node) {
                    $nodeClasses = preg_split('/\s+/', trim($node->getAttribute('class'))) ?: array();
                    if ($node !== $element && array() === preg_grep('/^wp-block-post-navigation-link__arrow-/', $nodeClasses)) continue;
                    if (in_array('is-arrow-arrow', $nodeClasses, true)) $arrow = 'arrow';
                    elseif (in_array('is-arrow-chevron', $nodeClasses, true)) $arrow = 'chevron';
                }
                // A captured label sits inside the link (core's linkLabel); that
                // is also the only label placement core keeps alongside an arrow.
                // Keep the wrapper's own presentation (inline typography, source
                // classes) so authored rules still reach the native block.
                $attrs = $this->styleResolver->presentationAttributes($element);
                $type = 'prev' === $link->getAttribute('rel') ? 'previous' : 'next';
                $ownClasses = array_diff(preg_split('/\s+/', trim(($attrs['className'] ?? '') . ' ' . implode(' ', $classes))) ?: array(), array('', 'wp-block-post-navigation-link', 'post-navigation-link-' . $type, 'is-arrow-arrow', 'is-arrow-chevron'));
                unset($attrs['className']);
                if (array() !== $ownClasses) $attrs['className'] = implode(' ', array_unique($ownClasses));
                return $this->createBlock('core/post-navigation-link', array_merge($attrs, array('type' => $type, 'showTitle' => null !== $title, 'arrow' => $arrow, 'label' => trim($label)), null !== $title && '' !== trim($label) ? array('linkLabel' => true) : array()), array(), $element);
            }
        }

        $authoredMotion = (new AuthoredMotionMarkerConverter($this->session, $this))->convert($element);
        if (null !== $authoredMotion) return $authoredMotion;

        $prelude = $this->elementPrelude->convert($element, $tagName, $fallbacks, $captureUnsupported);
        if ( $prelude->handled ) {
            return $prelude->block;
        }

        $checkable = $this->inlineCheckableControls->inlineCheckableControlBlock($element);
        if (null !== $checkable) return $checkable;

        if ( $this->richTextConverter->handles($tagName) ) {
            return $this->richTextConverter->convert($element, $tagName, $fallbacks)->block;
        }

        if ( 'address' === $tagName ) {
            return $this->textLeafConverter->convert($element, $tagName, $fallbacks)->block;
        }

        // Plain wrappers are transport, not sectioning content. Lower document
        // landmarks consistently so shared-shell identity does not depend on them.
        $documentLandmark = ! $this->hasAncestorTag($element, array('main', 'section', 'aside', 'nav'));
        if ( $this->session->preservesShellLandmarks()
            && in_array(ShellLandmarkPolicy::landmarkKind($tagName, $this->attr($element, 'role')), array('header', 'footer'), true)
            && ($documentLandmark || $this->hasAncestorTag($element, array('article')) || ($this->hasAncestorTag($element, array('main', 'section')) && ShellLandmarkPolicy::isDocumentEdgeLandmark($element)))
        ) {
            $children = $this->convertChildren($element, $fallbacks, true);
            if ( array() !== $children ) {
                $attrs = $this->styleResolver->presentationAttributes($element);
                if ($this->hasAncestorTag($element, array('main', 'section')) && ShellLandmarkPolicy::isDocumentEdgeLandmark($element)) {
                    // Hoisting loses inherited text colour and the transparent
                    // landmark's ancestor backdrop. Keep that paint on the part.
                    foreach (array('text', 'background') as $paint) {
                        if (isset($attrs['style']['color'][$paint])) continue;
                        for ($ancestor = $element->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode) {
                            // Read resolved source paint before class-owned backgrounds
                            // are deferred to selectors that won't follow the detached part.
                            $context = $this->styleResolver->styleAttributeMapper()->map(
                                $this->styleResolver->presentationDeclarations($ancestor),
                                fn (string $value): string => $this->styleResolver->resolveCssVariablesInValue($value, $ancestor)
                            );
                            if (!isset($context['style']['color'][$paint])) continue;
                            $attrs['style']['color'][$paint] = $context['style']['color'][$paint];
                            break;
                        }
                    }
                }
                return $this->createBlock('core/group', $attrs, $children, $element);
            }
        }

        $wrappedSearchBlock = $this->searchBlockConverter->searchBlockFromWrapper($element);
        if ( null !== $wrappedSearchBlock ) {
            return $wrappedSearchBlock;
        }

        $customImage = SourceDom::imageOnlyCustomElement($element);
        if ( $customImage instanceof DOMElement ) {
            $this->imageDimensions->fillParentImageViewportPair($customImage);
            if ('' === $this->imageUrls->sourceUrl($customImage) && !$customImage->hasAttribute('srcset')) {
                return null;
            }
            if ( ! $this->customImageHosts->canPromoteImageOnly($element, $customImage) ) {
                return $this->customImageHosts->nativeImageIn($element, $customImage) ?? $this->imageLowering->responsiveMediaBlock($element);
            }
            $picture = $customImage->parentNode;
            return $this->imageLowering->convertImage(
                $customImage,
                $element,
                $picture instanceof DOMElement && 'picture' === strtolower($picture->tagName) ? $picture : null
            );
        }

        $customVideo = $this->customVideoHosts->customVideoElement($element);
        if ( $customVideo instanceof DOMElement ) {
            $video = $this->mediaLowering->fromElement($customVideo);
            if ( $this->customVideoHosts->hasTransparentCustomVideoHostPresentation($element) ) {
                return $video;
            }

            // The host has a presentation box. Retain it as a core wrapper
            // instead of moving its geometry onto the video element.
            return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($element), array($video), $element);
        }

        $videoPoster = $this->customVideoHosts->customVideoPosterBlock($element);
        if (null !== $videoPoster) return $videoPoster;

        // A `picture` with no image candidate (no `img`, no `source[srcset]`)
        // is not media: authors use it as a plain styled box. Lower it the way
        // a generic container lowers so its children and author CSS survive
        // instead of the media dispatch dropping the subtree.
        if ( 'picture' === $tagName && ! $this->sourceElementClassifier->hasPictureImageCandidate($element) ) {
            return $this->flowContainerConverter->convertGenericContainer($element, $fallbacks)->block;
        }

        $mediaDispatch = $this->mediaDispatchConverter->convert($element, $tagName, $fallbacks);
        if ( $mediaDispatch->handled ) {
            return $mediaDispatch->block;
        }

        // Core owns the template's keyboard skip control, including in reduced mode.
        if ( 'a' === $tagName && $this->styleResolver->isFocusRevealedSkipLink($element, $this->currentSourcePath) ) {
            return null;
        }

        if ($this->session->usesFallbackReductionMode() && ( 'button' === $tagName || ( 'a' === $tagName && ! $this->sourceElementClassifier->hasBlockLevelDescendant($element) && '' === trim($this->attr($element, 'aria-label')) ) )) {
            $text = $this->styleResolver->innerHtml($element);
            if ('' !== trim($this->runtime->stripAllTags($text))) {
                $attrs = array_merge($this->styleResolver->presentationAttributes($element), array('text' => $text));
                if ('a' === $tagName && '' !== trim($this->attr($element, 'href'))) {
                    $attrs['url'] = $this->attr($element, 'href');
                }
                return $this->createBlock('core/buttons', array(), array($this->createBlock('core/button', $attrs, array(), $element)), $element);
            }
        }

        // Anchors are phrasing content, but button-like anchors must be offered
        // to the button dispatcher before generic inline lowering splits their
        // label and decorative SVG into separate paragraph blocks.
        if ( 'a' === $tagName ) {
            $fragmentTarget = $this->emptyNamedFragmentTargetBlock($element);
            if ( null !== $fragmentTarget ) {
                return $fragmentTarget;
            }
            if ( SourceDom::isEmptyNamedFragmentTarget($element) ) {
                return null;
            }
            $anchorBlock = $this->buttonLinkDispatcher->convertAnchor($element, $fallbacks);
            if ( null !== $anchorBlock ) {
                return $anchorBlock;
            }

            // A null here used to end the anchor's conversion outright: no
            // block, no fallback, no finding — the subtree simply disappeared,
            // and nothing downstream could count what was lost. Hand it to the
            // same terminal recorder every other unconvertible element reaches,
            // so it is either generated as a custom block or recorded as an
            // unsupported-element fallback the import report can account for.
            //
            // An anchor that neither navigates nor renders anything is not
            // that loss. It is the same non-rendering inline scaffolding as
            // the empty `<span>` a widget usually leaves beside it, so it
            // follows the empty-inline disposition: carried only when authored
            // styles give its box something to show, never counted as an
            // unsupported-element fallback.
            if ( SourceDom::isEmptyNonNavigatingAnchor($element) ) {
                return $this->shouldPreserveEmptyVisualElement($element)
                    ? $this->emptyVisualSpacers->emptyVisualSpacerBlock($element)
                    : null;
            }

            return $captureUnsupported ? $this->unsupportedRecorder->record($element, $tagName, $fallbacks) : null;
        }

        $structuralContentDispatch = $this->structuralContentConverters->convert($element, $tagName, $fallbacks);
        if ( $structuralContentDispatch->handled ) {
            return $structuralContentDispatch->block;
        }

        if ( 'label' === $tagName ) {
            $label = $this->readableFormControlBlockConverter->convert($element);
            // A label wrapping a control the page cannot represent (a hidden
            // file picker, say) is still the visible box around its content.
            // Lower it as that container: its content stays native blocks and
            // the control reports through standalone control handling.
            if ( (null !== $label && 'core/html' !== ($label['blockName'] ?? null)) || ! $this->wrapsUnrepresentableControl($element) ) {
                return $label;
            }
            return $this->flowContainerConverter->convertGenericContainer($element, $fallbacks)->block;
        }

        // These delegates own disjoint tags; their declaration order is the
        // pre-existing precedence before form, navigation, and flow dispatch.
        $postStructuralDispatch = $this->postStructuralElementConverters->convert($element, $tagName, $fallbacks);
        if ( $postStructuralDispatch->handled ) {
            return $postStructuralDispatch->block;
        }

        if ( 'nav' === $tagName ) {
            $navigation = $this->recognizePatterns($element, $fallbacks, array(AccordionPattern::class, SocialLinksPattern::class, NavigationPattern::class));
            if ( null !== $navigation ) {
                return $this->rememberAccordionDisclosureRoot($navigation, $element);
            }

            $inlineNavigation = $this->inlineNavigationGroupBlockFromElement($element);
            if ( null !== $inlineNavigation ) {
                return $inlineNavigation;
            }
        }

        $flowContainerDispatch = $this->flowContainerConverter->convert($element, $tagName, $fallbacks);
        if ( $flowContainerDispatch->handled ) {
            return $flowContainerDispatch->block;
        }

        // Autoplay cannot change a single slide. Keep its complete light DOM
        // (including responsive images and links), not the image-only slide path.
        if ( $this->authoredCarouselGenerator->isStaticSingleSlideCarousel($element)
            && $this->runtimeIslands->isSafeTransparentCustomElement($element, true)
        ) {
            return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($element), $this->convertChildren($element, $fallbacks, $captureUnsupported), $element);
        }

        $carousel = $this->authoredCarouselGenerator->convert($element);
        if ( null !== $carousel ) {
            return $carousel;
        }

        // A custom-element menu host is not a component. Recognize its landmark
        // before generated-component capture freezes paragraph-wrapped labels
        // into a companion content attribute.
        $navigationHost = $this->recognizedNavigationHostBlock($element, $fallbacks);
        if ( null !== $navigationHost ) {
            return $navigationHost;
        }

        if ( $this->isGeneratedComponentCandidate($element) ) {
            $component = $this->nativeOrGeneratedComponentBlock($element, $fallbacks);
            if ( null !== $component ) {
                return $component;
            }
        }

        $readableControlBlock = $this->readableFormControlBlockConverter->convert($element);
        if ( null !== $readableControlBlock ) {
            return $readableControlBlock;
        }

        if ( $this->formRuntimeIslandRecorder->preserveStandaloneControl($element) ) {
            return null;
        }

        $linkBearingElement = $this->linkCarriers->linkBearingElementBlock($element);
        if ( null !== $linkBearingElement ) {
            return $linkBearingElement;
        }

        $transparentCustomElement = $this->customBlockGenerator->convert($element, $fallbacks);
        if ( null !== $transparentCustomElement ) {
            return $transparentCustomElement;
        }

        if ( $this->inertElements->isInertRuntimeMediaPlaceholder($element) ) {
            return null;
        }

        if ( $this->isOrphanListItem($element) && $this->runtimeIslands->isSafeTransparentCustomElement($element) ) {
            // Invalid list ancestry does not remove browser marker rendering or
            // author `li` selectors. Keep the source root on the existing exact
            // wrapper carrier while its children remain native editable blocks.
            $children = $this->sourceElementClassifier->hasOnlyPhrasingChildren($element)
                ? array($this->createBlock('core/paragraph', array('content' => $this->richTextMaterializer->content($element), 'className' => self::SYNTHETIC_PARAGRAPH_CLASS)))
                : $this->convertChildren($element, $fallbacks, $captureUnsupported);
            return $this->layoutShells->blockForElements(array($element), $children, $element);
        }

        if ( $this->isScriptOwnedCheckoutRegion($element) ) {
            // A checkout view region the commerce recognizer claims is
            // script-owned commerce rendering the engine cannot convert.
            // Preserve its source markup whole so the checkout view binding
            // anchors on the block a shop provider replaces.
            $block = $this->htmlPreservationBlock($element);
            $block['_editability_runtime_owned'] = true;

            return $block;
        }

        if ( $this->renderNeutral->isTransparentUnknownElement($element) ) {
            // A hyphenated custom element is an authored component: when the
            // classifier calls its subtree a cohesive custom block, it still
            // becomes the generated block the unsupported recorder produced.
            // An unknown non-hyphenated name (`bdt`) is never a component.
            $generated = str_contains($element->tagName, '-') ? $this->session->fallbackEmitter()->maybeGenerateCustomBlock($element, $this->session->requireGeneratedBlockRegistry()) : null;
            if ( null !== $generated ) {
                return $this->generatedComponentBlock($generated, $element);
            }

            return $this->flowContainerConverter->convertUnknownElement($element, $fallbacks)->block;
        }

        $customElementRuntime = $this->customElementRuntimeDisposition($element, $tagName, $fallbacks);
        if ( $customElementRuntime['handled'] ) {
            return $customElementRuntime['block'];
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
     * Preserve a textless custom element only when captured script content proves
     * it defines that tag. A configuration-bearing element without that proof
     * stays an explicit missing-functional-content finding rather than a silent
     * drop or a generic unsupported-element silence.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array{handled: bool, block: array<string, mixed>|null}
     */
    private function customElementRuntimeDisposition(DOMElement $element, string $tagName, array &$fallbacks): array
    {
        $decision = ( new CustomElementRuntimeDependency() )->classify(
            $element,
            $this->session->runtimeBehaviorState()->runtimeScriptMetadata(),
            $this->session->runtimeBehaviorState()->runtimeProjectionScriptAssets()
        );
        if ( 'preserve' !== $decision['disposition'] && 'unproven' !== $decision['disposition'] ) {
            return array( 'handled' => false, 'block' => null );
        }
        if ( 'unproven' === $decision['disposition'] ) {
            // A host the source cascade hides at every viewport renders
            // nothing there, so omitting it loses no content or behavior.
            if ( $this->styleResolver->isDisplayNoneAtEveryViewport($element) ) {
                return array( 'handled' => true, 'block' => null );
            }
            $fallbacks[] = FallbackDiagnostic::build(array(
                'type'            => 'missing_functional_content',
                'reason'          => CustomElementRuntimeDependency::UNPROVEN_REASON,
                'diagnostic_code' => CustomElementRuntimeDependency::UNPROVEN_CODE,
                'message'         => 'A functional custom element was not preserved because no captured script proves it defines that element.',
                'source_format'   => 'html',
                'tag'             => $tagName,
                'selector'        => SourceDom::elementSelector($element),
                'attributes'      => SourceDom::htmlAttributes($element),
                'context'         => $this->sourceElementDescription->sourceContext($element),
                'html'            => SourceDom::safeFallbackHtml($element, $this->session->authorSelectorProjectionState()->tagMarkers()),
            ), $this->session->transformationProvenanceState()->fallback());

            return array( 'handled' => true, 'block' => null );
        }

        $this->runtimeIslands->recordRuntimeIsland(
            $element,
            'custom_element',
            'custom_element_requires_defining_script',
            'client_script_execution',
            array(
                'required_scripts'      => $decision['scripts'],
                'preservation_strategy' => 'sanitized_embed_markup',
            )
        );
        $generated = $this->session->fallbackEmitter()->maybeGenerateCustomBlock($element, $this->session->requireGeneratedBlockRegistry(), true, true);
        $block = null !== $generated
            ? $this->generatedComponentBlock($generated, $element)
            : $this->htmlPreservationBlock($element);
        $block['_editability_runtime_owned'] = true;

        return array( 'handled' => true, 'block' => $block );
    }

    /**
     * Whether `$element` is the script-owned region a checkout view binding
     * anchors on: a custom element (script-defined by name) holding the
     * checkout view's customer, address, payment and summary roles, and
     * passing the same preservation safety bar as a proven custom element.
     * Native checkout regions (a `form` or `div` root) convert natively and
     * never reach the custom-element disposition, so they need no preservation.
     */
    private function isScriptOwnedCheckoutRegion(DOMElement $element): bool
    {
        if ( ! CustomElementRuntimeDependency::isCustomElementTag(strtolower($element->tagName)) || ! ( new CustomElementRuntimeDependency() )->isSafe($element) ) {
            return false;
        }

        return $this->commercePattern->isCheckoutRegion($element);
    }

    /** Whether the label wraps a control with no page representation: unrendered or without a readable summary. */
    private function wrapsUnrepresentableControl(DOMElement $element): bool
    {
        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( FormControlClassifier::isControlElement($descendant)
                && (FormControlClassifier::isNonAuthoredControl($descendant) || ! FormControlClassifier::isReadableControl($descendant)) ) {
                return true;
            }
        }

        return false;
    }

    /** A `li` whose parent is not a list: invalid markup the list converter never reaches. */
    private function isOrphanListItem(DOMElement $element): bool
    {
        if ( 'li' !== strtolower($element->tagName) ) {
            return false;
        }
        $parent = $element->parentNode;

        return ! $parent instanceof DOMElement || ! in_array(strtolower($parent->tagName), array( 'ul', 'ol', 'menu' ), true);
    }

    /**
     * Lower a custom-element host of a navigation landmark to that landmark's
     * native navigation, keeping host identity CSS can still address.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function recognizedNavigationHostBlock(DOMElement $element, array &$fallbacks): ?array
    {
        $landmark = ( new NavigationPattern($this) )->hostedNavigationLandmark($element);
        if ( ! $landmark instanceof DOMElement ) {
            return null;
        }

        $navigation = $this->recognizePatterns($landmark, $fallbacks, array( AccordionPattern::class, SocialLinksPattern::class, NavigationPattern::class ));
        if ( null === $navigation ) {
            return null;
        }

        if ( '' === trim($this->attr($element, 'id') . $this->attr($element, 'class') . $this->attr($element, 'style')) ) {
            return $navigation;
        }

        return $this->layoutShells->blockForElements(array( $element ), array( $navigation ), $element);
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

    /** @param array<int, array<string, mixed>> $fallbacks @return array<string, mixed> */
    private function capturedDialogBlock(DOMElement $element, array &$fallbacks): array
    {
        return $this->elementPrelude->capturedDialogBlock($element, $fallbacks);
    }

    /** @return array<string, mixed> */
    private function runtimeAuthoredButton(DOMElement $element): array
    {
        $this->formRuntimeIslandRecorder->recordControl($element);
        // The authored control saves its descendants as source markup, not
        // semantic/RichText carriers. Keep author selectors addressing those
        // actual nodes (including empty CSS-painted icons). A lone close icon is
        // itself saved inside its inline host.
        if ( AuthoredButtonBlockGenerator::isCapturedDialogCloseIcon($element) ) {
            $this->session->authorSelectorProjectionState()->markRetainedSourcePath($element->getNodePath() ?? '');
        }
        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            $this->session->authorSelectorProjectionState()->markRetainedSourcePath($descendant->getNodePath() ?? '');
        }
        $generated = $this->authoredFormControlBlockConverter->runtimeButton($element);
        $materialized = $this->createBlock(
            (string) ($generated['blockName'] ?? ''),
            is_array($generated['attrs'] ?? null) ? $generated['attrs'] : array(),
            array(),
            $element
        );
        if ( isset($materialized['_source_provenance_id']) ) {
            $generated['_source_provenance_id'] = $materialized['_source_provenance_id'];
        }

        return $generated;
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
        if ( 'core/group' === $name && ! isset($attrs['metadata']['blocksEngineControlBox']) && $sourceElement instanceof DOMElement && $this->preservesScriptStateWrapper($sourceElement) ) {
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
            $attrs = $this->richTextStylingHooks()->hoistContentWrappingSpans($name, $attrs);
        }
        // A RichText carrier whose only classes are its role and subject
        // classes carries no authored class.
        if ( 'core/paragraph' === $name && ! $preserveInlineLayoutLeaf && ! $sourceElement instanceof DOMElement
            && str_contains((string) preg_replace('/\sclass="(?:\s*blocks-engine-(?:role-richtext|subject)[a-z0-9-]*)+\s*"/', '', (string) ($attrs['content'] ?? '')), 'class=')
        ) {
            $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), self::SYNTHETIC_PARAGRAPH_CLASS);
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
                                && ('' !== trim($this->attr($logicalControl, 'class')) || '' !== trim($this->attr($logicalControl, 'id'))))
                            || ( 'button' === $sourceTagName
                                && ('' !== trim($this->attr($sourceElement, 'class')) || '' !== trim($this->attr($sourceElement, 'id')))))));
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
                        && $this->hasAuthorSemanticMarker($sourceElement),
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
                $this->elementSelector($sourceElement),
                $this->sourceProvenance->entry($name, $sourceElement),
                // The ancestor-aware, conditional-CSS-resolving check only earns
                // its extra selector-matching cost for core/navigation, the one
                // block NavigationBlockNormalizer deduplicates by this signal.
                'core/navigation' === $name
                    ? $this->sourceVisibility->orAncestorStartsHidden($sourceElement)
                    : $this->sourceVisibility->startsHidden($sourceElement),
                $this->htmlAttributes($sourceElement),
                $this->sourceElementDescription->structureSignals($sourceElement, $attrs),
                array() === $innerBlocks && 'core/group' === $name
                    ? $this->emptyVisualTopologyEvidence($sourceElement)
                    : array(),
                'core/navigation' === $name && $this->sourceWrapperHiddenAtCollapsedViewport($sourceElement)
            );
        }

        if ( $sourceElement instanceof DOMElement && in_array($name, array( 'core/group', 'core/quote' ), true) ) {
            $innerBlocks = $this->withPropagatedWrapperTextAlignment($innerBlocks, $sourceElement);
        }

        // Every RichText carrier a block stores passes through here, so this is
        // where each one gets its role and subject classes; the saved markup
        // is built from these attributes and so carries them too.
        $attrs = $this->withRichTextCarrierClasses($attrs);

        return $this->sourceInterItemSpaces->apply($this->blockMaterializer->materialize(
            $name,
            $attrs,
            $innerBlocks,
            $sourceMaterializationFacts,
            $this->session->transformationProvenanceState()
        ), $sourceElement);
    }

    /**
     * @param array<mixed> $attrs
     * @return array<mixed>
     */
    private function withRichTextCarrierClasses(array $attrs): array
    {
        foreach ( $attrs as $key => $value ) {
            if ( is_string($value) ) {
                $attrs[$key] = $this->session->authorSelectorProjectionState()->withRichTextCarrierClasses($value);
            } elseif ( is_array($value) ) {
                $attrs[$key] = $this->withRichTextCarrierClasses($value);
            }
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
                array_merge(is_array($innerBlock['attrs'] ?? null) ? $innerBlock['attrs'] : array(), array( 'align' => $alignment ))
            );
        }

        return $innerBlocks;
    }

    /**
     * Whether a wrapper around the element hides it at the collapsed (mobile)
     * reference viewport. The element itself is excluded: a navigation becomes
     * core/navigation, whose own overlay replaces the source's collapsed
     * state, while a wrapper stays a group that keeps hiding it.
     */
    private function sourceWrapperHiddenAtCollapsedViewport(DOMElement $element): bool
    {
        for (
            $candidate = $element->parentNode;
            $candidate instanceof DOMElement && 'body' !== strtolower($candidate->tagName);
            $candidate = $candidate->parentNode
        ) {
            if ( SourceDom::documentVariantRoot($candidate) === $candidate ) {
                return false;
            }
            $declarations = $this->styleResolver->collapsedViewportDeclarations($candidate, array( 'display', 'visibility' ));
            if ( 'none' === CssValueInspector::comparable((string) ($declarations['display'] ?? ''))
                || in_array(CssValueInspector::comparable((string) ($declarations['visibility'] ?? '')), array( 'hidden', 'collapse' ), true) ) {
                return true;
            }
        }

        return false;
    }

    private function hasAuthorSemanticMarker(DOMElement $element): bool
    {
        return array() !== $this->session->authorSelectorProjectionState()->semanticMarkersForElement($element);
    }

    private function requiresIndependentSemanticWrapper(DOMElement $element): bool
    {
        if ( 'span' !== strtolower($element->tagName) || $this->isRichTextInlineContext($element) ) {
            return false;
        }

        if ( $this->ownsPositioningGeometry($element) ) {
            return true;
        }

        // An empty painted span outside RichText lowers to its own spacer
        // block (InlineContentElementConverter), never to a `<mark>` carrier.
        // Author selectors that name it must address that block, so it needs
        // a semantic marker rather than a rich-text marker no block carries.
        if ( 0 === $this->childElementCount($element)
            && '' === trim($element->textContent ?? '')
            && $this->shouldPreserveEmptyVisualElement($element)
        ) {
            return true;
        }

        $parent = $element->parentNode instanceof DOMElement ? $element->parentNode : null;
        if ( ! $parent instanceof DOMElement || ! $this->wrapperCoalescer->isStructuralLayoutElement($parent) ) {
            return false;
        }

        $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
        // A grid placement belongs to this inline node. Keep phrasing-only grid
        // siblings in one RichText container rather than replacing their direct
        // grid items with Group/Paragraph wrappers.
        if ( 'grid' === CssValueInspector::comparable((string) ($this->styleResolver->presentationDeclarations($parent)['display'] ?? '')) && ( '' !== trim((string) ($declarations['grid-column'] ?? '')) || '' !== trim((string) ($declarations['grid-row'] ?? '')) ) ) {
            return false;
        }
        $display = CssValueInspector::comparable((string) ($declarations['display'] ?? 'inline'));
        if ( 'block' === $display ) {
            return true;
        }

        foreach ( array( 'font-size', 'line-height', 'letter-spacing', 'text-transform', 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'border', 'border-width', 'border-color', 'border-radius', 'margin', 'width', 'height', 'min-width', 'min-height', 'max-width', 'max-height' ) as $property ) {
            if ( '' !== trim((string) ($declarations[$property] ?? '')) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * A positioned inline leaf cannot survive as RichText: its wrapper owns a
     * containing-block relationship and/or stacking context, rather than text
     * formatting. Preserve it as a native group carrier before RichText drops
     * the source class and geometry.
     */
    private function ownsPositioningGeometry(DOMElement $element): bool
    {
        if ( ! $this->sourceElementClassifier->isInlineContentElement(strtolower($element->tagName)) || $this->isRichTextInlineContext($element) ) {
            return false;
        }

        $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
        $position = strtolower(trim((string) ($declarations['position'] ?? 'static')));
        if ( $this->hasPositionedInlineDescendant($element) ) {
            return true;
        }

        $zIndex = strtolower(trim((string) ($declarations['z-index'] ?? 'auto')));
        $hasZIndex = ! in_array($zIndex, array( '', 'auto', 'inherit', 'initial', 'unset' ), true);
        if ( in_array($position, array( 'absolute', 'fixed' ), true) ) {
            return true;
        }

        if ( 'sticky' === $position ) {
            return $this->sourceElementClassifier->hasResolvedInset($declarations) || $hasZIndex;
        }

        if ( 'relative' === $position ) {
            return $this->sourceElementClassifier->hasResolvedInset($declarations) || $hasZIndex;
        }

        return false;
    }

    private function hasPositionedInlineDescendant(DOMElement $element): bool
    {
        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement && $this->ownsPositioningGeometry($descendant) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function positionedInlineCarrierBlock(DOMElement $element, array &$fallbacks): ?array
    {
        if ( $this->positionedCarrierHasStructuredContent($element) ) {
            $children = $this->positionedInlineCarrierChildren($element, $fallbacks);
            return array() === $children
                ? null
                : $this->createBlock('core/group', $this->positionedInlineCarrierAttributes($element), $children, $element);
        }

        $content = $this->richTextMaterializer->content($element);
        if ( '' === trim($this->runtime->stripAllTags($content)) ) {
            return null;
        }

        return $this->createBlock('core/group', $this->positionedInlineCarrierAttributes($element), array(
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

    private function positionedCarrierHasStructuredContent(DOMElement $element): bool
    {
        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( ! $descendant instanceof DOMElement ) {
                continue;
            }

            $tagName = strtolower($descendant->tagName);
            if ( $this->ownsPositioningGeometry($descendant)
                || ! $this->sourceElementClassifier->isInlineContentElement($tagName)
                || in_array($tagName, array( 'a', 'button', 'input', 'select', 'textarea' ), true)
            ) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function positionedInlineCarrierAttributes(DOMElement $element): array
    {
        return $this->styleResolver->presentationAttributes($element, array(), array(
            'position', 'z-index', 'inset', 'inset-block', 'inset-inline',
            'inset-block-start', 'inset-block-end', 'inset-inline-start',
            'inset-inline-end', 'top', 'right', 'bottom', 'left',
        ));
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    private function authorLayoutBlockFromElement(DOMElement $element, array &$fallbacks): array
    {
        if ( ! $this->preservesScriptStateWrapper($element) ) {
            $paragraph = $this->textOnlyLayoutDivParagraph($element);
            if ( null !== $paragraph ) {
                return $paragraph;
            }
        }
        $children = $this->convertChildren($element, $fallbacks, true);
        if ( $this->preservesScriptStateWrapper($element) ) {
            return $this->layoutShells->blockForElements(array( $element ), $children, $element);
        }
        $isAuthorOwnedLayout = $this->authorLayoutOwnership->isAuthorOwnedLayout($element);
        $sourceChildCount = $isAuthorOwnedLayout ? $this->childElementCount($element) : 0;
        $sourceTags = $isAuthorOwnedLayout ? $this->directChildTags($element) : array();
        $blockTags = $isAuthorOwnedLayout ? $this->directBlockTags($children) : array();
        $topologyChanged = $isAuthorOwnedLayout
            && 0 < $sourceChildCount
            && ( $sourceChildCount !== count($children) || $sourceTags !== $blockTags );
        if ( 1 === count($children) && ! $topologyChanged ) {
            $coalesced = $this->coalescedSingleGroupWrapper($element, $children[0]);
            if ( null !== $coalesced ) {
                return $coalesced;
            }
        }
        $attrs = $this->authorLayoutOwnership->cssOwnedGroupAttributes($element, false, $topologyChanged);
        if ( 0 === count($children) && '' === SourceDom::renderedTextContent($element) ) {
            // Empty source layout containers are visual boundaries, not new
            // authoring targets. Mark them so the editor suppresses Core's
            // layout picker without changing their saved frontend box.
            $attrs['className'] = $this->mergeClassNames(
                (string) ($attrs['className'] ?? ''),
                self::EMPTY_VISUAL_GROUP_CLASS
            );
            $this->session->runtimeBehaviorState()->markEmptyVisualGroupGenerated();
            // An inline background paint is the reason an empty boundary box
            // exists. The standard carrier declines backgrounds for childless
            // elements because the flow-container path lowers those to a
            // background image block; this path keeps the box itself, so
            // restate the paint on a dedicated carrier.
            $attrs['className'] = $this->mergeClassNames(
                (string) $attrs['className'],
                $this->styleResolver->emptyElementBackgroundCarrierClassName($element)
            );
        }
        $block = $this->createBlock('core/group', $attrs, $children, $element);
        if ( $isAuthorOwnedLayout ) {
            $this->session->transformationEvidenceState()->recordAuthorLayoutTopology(
                $this->elementSelector($element),
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

    /** @param array<int, array<string, mixed>> $blocks */
    private function stripOuterAnchorFromBlocks(array &$blocks): void
    {
        foreach ($blocks as &$block) {
            if (! is_array($block)) continue;
            if (isset($block['attrs']['content']) && is_string($block['attrs']['content'])) {
                $content = preg_replace('/^<a\b[^>]*>(.*)<\/a>$/is', '$1', $block['attrs']['content']) ?? $block['attrs']['content'];
                if ($content !== $block['attrs']['content']) $block = $this->blockFactory->rebuild($block, array_merge($block['attrs'], array('content' => $content)));
            }
            if (is_string($block['innerHTML'] ?? null)) $block['innerHTML'] = preg_replace('/<a\b[^>]*>(<img\b[^>]*>)<\/a>/is', '$1', $block['innerHTML']) ?? $block['innerHTML'];
            if (is_array($block['innerContent'] ?? null)) foreach ($block['innerContent'] as &$part) if (is_string($part)) $part = preg_replace('/<a\b[^>]*>(<img\b[^>]*>)<\/a>/is', '$1', $part) ?? $part;
            unset($part);
            if (is_array($block['innerBlocks'] ?? null)) $this->stripOuterAnchorFromBlocks($block['innerBlocks']);
        }
        unset($block);
    }

    /** @return list<string> */
    private function directChildTags(DOMElement $element): array
    {
        $tags = array();
        foreach ($element->childNodes as $child) {
            if (! $child instanceof DOMElement) continue;
            $tag = strtolower($child->tagName);
            $tags[] = 'svg' === $tag ? 'img' : $tag;
        }
        return $tags;
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

    private function isRichTextInlineContext(DOMElement $element): bool
    {
        return $this->authorLayoutOwnership->isRichTextInlineContext($element);
    }

    private function richTextMarkerForElement(DOMElement $element): string
    {
        $marker = trim($this->attr($element, 'data-blocks-engine-richtext-marker'));
        if ( '' !== $marker ) {
            return $marker;
        }

        return $this->session->authorSelectorProjectionState()->richTextMarker(SourceDom::sourceElementIdentity($element));
    }

    public function richTextMarker(DOMElement $element): string
    {
        return $this->richTextMarkerForElement($element);
    }

    /**
     * Inline content as the standalone RichText a block's own content
     * attribute carries — a control label, an in-form introduction, a lowered
     * wrapper paragraph: the materialized source markup, with styling hooks
     * lowered to semantic carriers inline rather than hoisted onto the host.
     */
    private function inlineRichTextContent(DOMElement $element): string
    {
        $content = $this->richTextMaterializer->content($element);
        if ('' === $content) return '';
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $content . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $body = $loaded ? $document->getElementsByTagName('body')->item(0) : null;
        if (!$body instanceof DOMElement) return $content;
        $this->richTextStylingHooks()->normalize($body);
        return SourceDom::innerHtml($body);
    }

    /**
     * In-form copy — an introduction above the fields, a note between them —
     * as the paragraph/heading RichText the page conversion keeps, so the form
     * contract carries the copy's formatting instead of its flattened text.
     * Copy without inline markup, and markup a paragraph cannot hold (structure
     * a RichText attribute rejects, or an object the fallback gate reserves for
     * the page's own conversion), returns '': the item's plain text, styles and
     * classes stay the contract there.
     */
    private function formContextRichText(DOMElement $element): string
    {
        $content = trim($this->inlineRichTextContent($element));
        if ( '' === $content || ! preg_match('/<[a-zA-Z]/', $content)
            || $this->richTextMaterializer->hasStructuralHtml($content)
            || $this->richTextMaterializer->requiresHtmlFallback($content) ) {
            return '';
        }

        return $content;
    }


    private function shouldPreserveWrapper(DOMElement $element, ?string $asTag = null): bool
    {
        $tag = $asTag ?? $element->tagName;

        // Elide only a neutral chain, not a media shell whose positioned child
        // requires an intrinsic-size carrier. Keep identity on its real box.
        $child = 1 === SourceDom::childElementCount($element) ? $element->firstElementChild : null;
        if ('div' === strtolower($tag) && '' === trim($this->attr($element, 'id'))
            && $child instanceof DOMElement && 'div' === strtolower($child->tagName)
            && !$this->hasAuthorSemanticMarker($element) && !$this->hasStyledSourceClassBoundary($element)
            && $this->renderNeutral->isRenderNeutralWrapper($element) && $this->renderNeutral->isRenderNeutralWrapper($child)
            && $this->containsGeneratedComponentCandidate($element)
        ) {
            return false;
        }

        return ( ShellLandmarkPolicy::isWrapperPreservingTag($tag) || RichTextInlineTags::isUnknownHtmlElement($element) ) && ( $this->runtimeIslands->isRuntimeDomTarget($element) || $this->hasAuthorSemanticMarker($element) || array() !== $this->styleResolver->presentationAttributes($element) || array() !== $this->sourceElementDescription->structureSignals($element, array()) || $this->hasStyledSourceClassBoundary($element) );
    }

    /**
     * Presentation attributes omit class-owned CSS. A wrapper can therefore
     * look empty while still owning inherited declarations or the ancestor
     * boundary needed by a descendant rule.
     */
    private function hasStyledSourceClassBoundary(DOMElement $element): bool
    {
        $classes = SourceDom::classNames($element);
        if (array() === $classes) return false;

        $nodes = array($element);
        foreach ($element->getElementsByTagName('*') as $descendant) {
            $nodes[] = $descendant;
        }
        foreach ($nodes as $node) {
            foreach ($this->session->authorStyleRuleCandidates($node) as $candidate) {
                if (array() === $candidate['declarations'] || ! ($candidate['parsed']['supported'] ?? false)) continue;
                $compounds = $candidate['parsed']['compounds'];
                // On descendants the source class must be an ancestor, not
                // merely another class on the selector's subject.
                if ($node !== $element) array_pop($compounds);
                $namesClass = false;
                foreach ($compounds as $compound) {
                    if (array() !== array_intersect($classes, $compound['classes'])) {
                        $namesClass = true;
                        break;
                    }
                }
                if ($namesClass && $this->session->sourceStyleResolutionState()->selectorMatchCache->matches($node, $candidate['selector'], $candidate['parsed'])['matches']) return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $childBlock
     * @return array<string, mixed>|null
     * @see WrapperCoalescer::coalescedSingleGroupWrapper()
     * @see WrapperCoalescer::coalescingDisposition() for the named disqualification reasons.
     */
    private function coalescedSingleGroupWrapper(DOMElement $element, array $childBlock): ?array
    {
        if ( $this->preservesScriptStateWrapper($element) ) {
            return null;
        }
        return $this->wrapperCoalescer->coalescedSingleGroupWrapper($element, $childBlock);
    }

    /**
     * This runs before author-layout lowering, whose custom block deliberately
     * owns CSS flex/grid topology. The contract has already compared source and
     * wrapper-free layouts; this method only carries the wrapper identity and
     * generated geometry carrier onto an existing native child block.
     *
     * @param array<int,array<string,mixed>> $fallbacks
     * @return array<string,mixed>|null
     */
    private function proofBackedWrapperCoalescing(DOMElement $element, array &$fallbacks): ?array
    {
        $proof = $this->wrapperCoalescer->layoutGeometryProofFor($element);
        if (null === $proof || $this->runtimeIslands->isRuntimeDomTarget($element) || '' !== trim($this->attr($element, 'id')) || '' !== trim($this->attr($element, 'role')) || array() !== $this->sourceElementDescription->interactiveAttributes($element) || $this->sourceElementClassifier->hasMotionStructureToken($element)) return null;
        $children = $this->convertChildren($element, $fallbacks, true);
        if (1 !== count($children) || !in_array($children[0]['blockName'] ?? null, array('core/group', 'core/image'), true) || $this->session->transformationProvenanceState()->isBindingAnchor($children[0]['_source_provenance_id'] ?? null)) return null;
        $sourceChild = $this->soleElementChild($element);
        if (!$sourceChild instanceof DOMElement || $this->elementSelector($sourceChild) !== ($proof['target_selector'] ?? null)) return null;
        $childAttrs = is_array($children[0]['attrs'] ?? null) ? $children[0]['attrs'] : array();
        $childAttrs['className'] = $this->mergeClassNames((string) ($childAttrs['className'] ?? ''), $this->wrapperCoalescer->layoutGeometryProofCarrier($proof));
        $childAttrs = array_filter($childAttrs, static fn (mixed $value): bool => !is_string($value) || '' !== trim($value));
        $this->session->layoutGeometryState()->recordProof($proof);
        return $this->createBlock((string) $children[0]['blockName'], $childAttrs, $children[0]['innerBlocks'] ?? array(), $sourceChild);
    }

    private function soleElementChild(DOMElement $element): ?DOMElement
    {
        $child = null;
        foreach ( $element->childNodes as $node ) {
            if ( XML_TEXT_NODE === $node->nodeType && '' === trim($node->textContent ?? '') ) {
                continue;
            }
            // Source comments carry no rendered, selector, or runtime boundary.
            // Treating them as transparent lets generated exports shed an otherwise
            // inert authoring wrapper without interpreting product-specific markup.
            if ( XML_COMMENT_NODE === $node->nodeType ) {
                continue;
            }
            if ( $node instanceof DOMElement && ($this->isInertHiddenEmptyElement($node) || $this->isExplicitlyDisplayNoneEmptyElement($node)) ) {
                continue;
            }
            if ( ! $node instanceof DOMElement || $child instanceof DOMElement ) {
                return null;
            }
            $child = $node;
        }

        return $child;
    }

    /**
     * Return resting stylesheet declarations which prove an otherwise empty group
     * is authored layout, paint, or control topology. Stateful selectors never
     * contribute: source capture has no interaction state to prove.
     *
     * @return list<array{selector: string, declarations: array<string, string>, specificity: int, order: int, source_path: string, source_hash: string}>
     */
    private function emptyVisualTopologyEvidence(DOMElement $element): array
    {
        $evidence = array();
        $matchedRules = array();
        foreach ( $this->session->authorStyleRuleCandidates($element) as $candidate ) {
            $ruleOrder = (int) $candidate['rule_order'];
            if ( isset($matchedRules[$ruleOrder])
                || ! ($candidate['parsed']['supported'] ?? false)
                || null !== ($candidate['parsed']['pseudo_state_suffix_span'] ?? null)
                || ! $this->session->sourceStyleResolutionState()->selectorMatchCache->matches($element, $candidate['selector'], $candidate['parsed'])['matches']
            ) {
                continue;
            }
            $matchedRules[$ruleOrder] = true;
            $declarations = array_filter(
                $candidate['declarations'],
                fn (string $value, string $property): bool => $this->sourceElementClassifier->isNonNeutralVisualTopologyDeclaration($property, $value),
                ARRAY_FILTER_USE_BOTH
            );
            if ( array() !== $declarations ) {
                $evidence[] = array(
                    'selector' => $candidate['selector'],
                    'declarations' => $declarations,
                    'specificity' => CssSelectorMatcher::specificity($candidate['parsed']),
                    'order' => $ruleOrder,
                    'source_path' => (string) ($candidate['source_path'] ?? ''),
                    'source_hash' => (string) ($candidate['source_hash'] ?? ''),
                );
            }
        }
        return $evidence;
    }

    /** @return list<array{key: string, selector: string, parsed: array<string, mixed>, direct_child_parsed: array<string, mixed>, declarations: array<string, string>, rule_order: int}> */
    /** @see WrapperCoalescer::selectorMatchingSurvivesWrapperCoalescing() */
    private function selectorMatchingSurvivesWrapperCoalescing(DOMElement $element, DOMElement $child, bool $exact = false): bool
    {
        return $this->wrapperCoalescer->selectorMatchingSurvivesWrapperCoalescing($element, $child, $exact);
    }

    private function shouldPreserveEmptyVisualElement(DOMElement $element): bool { return $this->emptyElementFootprint->shouldPreserveEmptyVisualElement($element, $this->emptyVisualRetentionPolicy($element)); }

    /** Non-style retention decisions, evaluated only when the footprint query needs them. */
    private function emptyVisualRetentionPolicy(DOMElement $element): iterable
    {
        if ( '' !== SourceDom::renderedTextContent($element) || $this->isInertOutOfFlowEmptyElement($element) ) {
            yield 'drop' => true;
            return;
        }
        yield 'hidden' => $this->isInertHiddenEmptyElement($element);
        if ( $this->hasSubstantiveSourceDescendant($element) && $this->shouldPreserveWrapper($element) ) {
            yield 'keep' => true;
        }
        yield 'layout' => true;
        if ( $this->sourceElementClassifier->isInlineContentElement(strtolower($element->tagName)) ) {
            yield 'inline' => $this->runtimeIslands->isRuntimeDomTarget($element)
                || in_array(strtolower($this->attr($element, 'role')), array( 'presentation', 'none' ), true)
                || 'true' === strtolower($this->attr($element, 'aria-hidden'));
            return;
        }
        $id = SourceDom::namedFragmentTargetId($element);
        yield 'block' => $this->runtimeIslands->isRuntimeDomTarget($element)
            || ( '' !== $id && SourceDom::documentReferencesFragmentId($element, $id) )
            || array() !== $this->eventMetadata($element)
            || array() !== $this->sourceElementDescription->structureSignals($element, array());
        yield 'keep' => $this->hasStaticPseudoElementRule($element);
        yield 'viewport' => $this->emptyVisualTopologyEvidence($element);
    }

    public function retainsEmptyRichTextInline(DOMElement $sourceInline): bool
    {
        return $this->runtimeIslands->isRuntimeDomTarget($sourceInline)
            || $this->shouldPreserveEmptyVisualElement($sourceInline)
            || $this->hasStaticPseudoElementRule($sourceInline);
    }

    private function hasSubstantiveSourceDescendant(DOMElement $element): bool
    {
        if ( '' !== SourceDom::renderedTextContent($element) ) {
            return true;
        }
        foreach ( array( 'audio', 'button', 'canvas', 'form', 'iframe', 'img', 'input', 'object', 'picture', 'select', 'svg', 'textarea', 'video' ) as $tagName ) {
            if ( 0 < $element->getElementsByTagName($tagName)->length ) {
                return true;
            }
        }
        return false;
    }

    private function hasStaticPseudoElementRule(DOMElement $element): bool
    {
        foreach ( $this->session->sourceStyleResolutionState()->pseudoElementRules() as $rule ) {
            if ( $this->styleResolver->matchesCssSelector($element, $rule['selector']) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Empty search and cart shells are dead platform chrome, not authored layout.
     * Content, controls, media, links, and runtime bindings keep their existing
     * native or capability-owned conversion path.
     */
    private function isEmptyInteractiveFeatureShell(DOMElement $element): bool
    {
        $identity = strtolower(trim($this->attr($element, 'class') . ' ' . $this->attr($element, 'id') . ' ' . $this->attr($element, 'role')));
        if ( ! preg_match('/(?:^|[^a-z0-9])(?:search|cart)(?:[^a-z0-9]|$)/', $identity)
            || '' !== SourceDom::renderedTextContent($element)
            || $this->runtimeIslands->isRuntimeDomTarget($element)
            || $this->wrapperCoalescer->isDirectChildOfStructuralLayout($element)
            || $this->hasAuthorInlineAlignment($element)
        ) {
            return false;
        }

        foreach ( array( 'a', 'audio', 'button', 'canvas', 'iframe', 'img', 'input', 'object', 'picture', 'select', 'svg', 'textarea', 'video' ) as $tagName ) {
            if ( 0 < $element->getElementsByTagName($tagName)->length ) {
                return false;
            }
        }

        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement && $this->runtimeIslands->isRuntimeDomTarget($descendant) ) {
                return false;
            }
        }

        return true;
    }

    private function hasAuthorInlineAlignment(DOMElement $element): bool
    {
        $declarations = $this->wrapperCoalescer->matchingAuthorDeclarations($element);
        $display = CssValueInspector::comparable((string) ($declarations['display'] ?? ''));
        $verticalAlign = strtolower(trim((string) ($declarations['vertical-align'] ?? '')));
        return in_array($display, array( 'inline', 'inline-block', 'inline-flex', 'inline-grid', 'inline-table' ), true)
            && ! in_array($verticalAlign, array( '', 'baseline', 'inherit', 'initial', 'revert', 'revert-layer', 'unset' ), true);
    }

    private function preservesScriptStateWrapper(DOMElement $element): bool
    {
        if (SourceDom::isCaptureDeviceVisibilityCarrier($element)) return true;
        if ( $this->isInertHiddenEmptyElement($element) ) {
            return false;
        }
        foreach ( $element->attributes ?? array() as $attribute ) {
            $name = strtolower($attribute->nodeName);
            if ( LayoutShellBlockGenerator::isBooleanAttribute($name) ) {
                return true;
            }
            if ( $this->runtimeIslands->isRuntimeDomTarget($element) && ( str_starts_with($name, 'data-') || str_starts_with($name, 'aria-') || 'role' === $name ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Page builders lay click-capture layers over media: an empty, positioned,
     * fully transparent box their own script listens on. It takes no flex gap
     * or grid cell, paints nothing at rest or in any state, and nothing in
     * WordPress interacts with it, so it is not a box the author can see or edit.
     */
    private function isInertOutOfFlowEmptyElement(DOMElement $element): bool
    {
        if ( 0 !== $this->childElementCount($element)
            || $this->runtimeIslands->isRuntimeDomTarget($element)
            || array() !== $this->sourceElementDescription->interactiveAttributes($element)
            || $this->hasStaticPseudoElementRule($element)
        ) {
            return false;
        }
        foreach ( array( 'opacity', 'visibility', 'background', 'border', 'outline', 'box-shadow', 'filter', 'layout' ) as $family ) {
            if ( $this->styleResolver->hasStateStyleFamily($element, $family)
                || $this->styleResolver->hasConditionalStyleFamily($element, $family)
            ) {
                return false;
            }
        }
        $declarations = array_merge(
            $this->styleResolver->structuralPresentationDeclarations($element),
            $this->styleResolver->matchedCascadedDeclarations($element),
            $this->styleResolver->cssDeclarations(SourceDom::attr($element, 'style'))
        );
        $position = CssValueInspector::comparable((string) ($declarations['position'] ?? ''));
        $opacity = CssValueInspector::comparable($this->styleResolver->resolveCssVariablesInValue((string) ($declarations['opacity'] ?? ''), $element));
        $authoredPaint = array() !== array_intersect_key($declarations, array_flip(array( 'background', 'background-color', 'background-image', 'border', 'border-top', 'border-right', 'border-bottom', 'border-left', 'box-shadow', 'outline', 'mask', 'mask-image' )));

        return in_array($position, array( 'absolute', 'fixed' ), true)
            && is_numeric($opacity) && 0.0 === (float) $opacity
            && ! $authoredPaint;
    }

    private function isInertHiddenEmptyElement(DOMElement $element): bool
    {
        if ( 0 !== $this->childElementCount($element)
            || '' !== trim($element->textContent ?? '')
            || ! $this->sourceVisibility->startsHidden($element)
            || $this->emptyElementFootprint->occupiesInFlowSpace($element)
            || $this->runtimeIslands->isRuntimeDomTarget($element)
            || $this->styleResolver->hasConditionalStyleFamily($element, 'layout')
            || $this->styleResolver->hasConditionalStyleFamily($element, 'visibility')
            || $this->styleResolver->hasConditionalStyleFamily($element, 'opacity')
        ) {
            return false;
        }

        foreach ( array( 'id', 'role', 'title', 'aria-label', 'aria-labelledby', 'aria-describedby' ) as $attribute ) {
            if ( '' !== trim($this->attr($element, $attribute)) ) {
                return false;
            }
        }

        return true;
    }

    private function isExplicitlyDisplayNoneEmptyElement(DOMElement $element): bool
    {
        return 0 === $this->childElementCount($element)
            && '' === trim($element->textContent ?? '')
            && 'none' === CssValueInspector::comparable((string) ($this->styleResolver->cssDeclarations($this->attr($element, 'style'))['display'] ?? ''))
            && '' === trim($this->attr($element, 'class'))
            && '' === trim($this->attr($element, 'id'))
            && '' === trim($this->attr($element, 'role'))
            && array() === $this->sourceElementDescription->interactiveAttributes($element)
            && array() === SourceDom::safeDataAttributes($element);
    }

    /**
     * An empty href-less named `<a>` is a fragment target. Preserve its
     * identifier on a native block HTML anchor rather than recording it as
     * unsupported HTML. Returns null when the element is not that shape, or
     * when the identifier already exists elsewhere in the document.
     *
     * @return array<string, mixed>|null
     */
    private function emptyNamedFragmentTargetBlock(DOMElement $element): ?array
    {
        if ( ! SourceDom::isEmptyNamedFragmentTarget($element) ) {
            return null;
        }

        $id = SourceDom::namedFragmentTargetId($element);
        if ( SourceDom::documentHasOtherFragmentTarget($element, $id) ) {
            return null;
        }

        if ( '' === trim(SourceDom::attr($element, 'id')) ) {
            $element->setAttribute('id', $id);
        }

        return $this->emptyVisualSpacers->emptyVisualSpacerBlock($element);
    }

    private function hasEmptyVisualInlineChild(DOMElement $element): bool
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && $this->sourceElementClassifier->isInlineContentElement(strtolower($child->tagName)) && $this->shouldPreserveEmptyVisualElement($child) ) {
                return true;
            }
        }

        return false;
    }

    private function authoredDisplay(DOMElement $element): string
    {
        return $this->authorLayoutOwnership->authoredDisplay($element);
    }

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

    private function visualTextWrapperBlockFromElement(DOMElement $element): ?array
    {
        if ( ! in_array(strtolower($element->tagName), array( 'div', 'span' ), true) || $this->sourceElementClassifier->hasBlockContentChildren($element) ) {
            return null;
        }

        if ( $this->hasAuthorSemanticMarkedChild($element) || $this->hasRichTextMarkedDescendant($element) ) {
            return null;
        }

        if ( $this->hasEmptyVisualInlineChild($element) ) {
            $fallbacks = array();
            $children = $this->convertChildren($element, $fallbacks, true);
            if ( array() !== $children ) {
                return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($element), $children, $element);
            }
        }

        $content = $this->richTextMaterializer->content($element);
        if ( '' === trim($this->runtime->stripAllTags($content)) || $this->richTextMaterializer->requiresHtmlFallback($content) ) {
            return null;
        }

        if ( ! $this->hasVisualTextWrapperSignal($element) ) {
            return null;
        }

        // A childless styled text wrapper round-trips as a single styled
        // `core/paragraph` carrying the wrapper class and presentation supports.
        // Keeping its box chrome on that same element preserves the source paint
        // structure; a core/group plus inner paragraph produces a separate text
        // paint layer that can differ around rounded borders. Unsupported geometry
        // remains on the generated CSS carrier attached to the paragraph. Real flex
        // containers hold child elements and are already excluded above by the
        // `childElementCount === 0` guard (e.g. `.tier-price` wrapping a `<span>`).
        //
        // Descendant paragraph rules the source used a non-`p` tag to escape (e.g.
        // `.page-header p { font-size: ... }` styling body copy while an eyebrow
        // authored as `<div class="label">` avoided it) do not capture the collapsed
        // paragraph: author `p` type selectors are projected through the source-`p`
        // tag marker, which only elements that were `<p>` in the source carry.
        if ( 0 === $this->childElementCount($element) ) {
            $attrs = array_merge($this->styleResolver->presentationAttributes($element), array( 'content' => $content ));
            // The paragraph is the source div's own block box. The
            // lowered-paragraph reset removes only the UA paragraph margins;
            // it precedes author CSS (and sits in the engine reset layer when
            // the author uses cascade layers), so authored margins still win.
            if ( 'div' === strtolower($element->tagName) ) {
                $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), self::LOWERED_PARAGRAPH_CLASS);
                return $this->createBlock('core/paragraph', $attrs, array(), $element);
            }
            // A non-`p` source did not have paragraph margins. Mark the host so
            // engine-support CSS can neutralize them without touching authored <p>.
            if ( 'p' !== strtolower($element->tagName) ) {
                $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), self::SYNTHETIC_PARAGRAPH_CLASS);
            }

            return $this->createBlock('core/paragraph', $attrs, array(), $element);
        }

        return $this->createBlock(
            'core/group',
            $this->styleResolver->presentationAttributes($element),
            array( $this->createBlock('core/paragraph', array( 'content' => $content )) ),
            $element
        );
    }

    /**
     * Box-model CSS declarations that give a text wrapper block-level geometry
     * (padding, border, explicit sizing, or flex/grid layout) which mark it as a
     * visual text wrapper worth preserving as a distinct block.
     *
     * @var array<int, string>
     */
    private const BOX_MODEL_WRAPPER_PROPERTIES = array( 'display', 'gap', 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'border', 'border-color', 'border-radius', 'width', 'height', 'min-width', 'max-width', 'min-height' );

    /**
     * Box chrome that requires a paragraph with empty visual inline children to
     * retain a structural wrapper rather than flattening those children.
     *
     * @var array<int, string>
     */
    /** @var array<int, string> */
    private const MARGIN_WRAPPER_PROPERTIES = array( 'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left' );

    private function hasVisualTextWrapperSignal(DOMElement $element): bool
    {
        $className = strtolower($this->attr($element, 'class'));
        if ( preg_match('/(?:^|[\s_-])(?:badge|tag|label|eyebrow|kicker|meta|pill|chip|stat|num|price|amount|result|caption|title|name)(?:$|[\s_-])/', $className) ) {
            return true;
        }

        if ( 0 < $this->childElementCount($element) ) {
            return false;
        }

        return $this->hasBoxModelWrapperStyling($element);
    }

    private function hasBoxModelWrapperStyling(DOMElement $element): bool
    {
        return $this->wrapperStylingMatches($element, self::BOX_MODEL_WRAPPER_PROPERTIES);
    }

    private function hasBoxChromeWrapperStyling(DOMElement $element): bool
    {
        return $this->authorLayoutOwnership->hasBoxChromeWrapperStyling($element);
    }

    private function hasMarginWrapperStyling(DOMElement $element): bool
    {
        return $this->wrapperStylingMatches($element, self::MARGIN_WRAPPER_PROPERTIES);
    }

    /**
     * @param array<int, string> $properties
     */
    private function wrapperStylingMatches(DOMElement $element, array $properties): bool
    {
        // Read the raw matched declarations rather than the post-projection
        // presentation set: box-model properties such as padding are consumed
        // into block-supports attributes and would otherwise be invisible here.
        $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
        foreach ( $properties as $property ) {
            if ( isset($declarations[$property]) && CssValueInspector::isNonZero((string) $declarations[$property]) ) {
                return true;
            }
        }

        return false;
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
        if ( 'div' !== strtolower($element->tagName) || 0 !== $this->childElementCount($element) || SourceDom::documentVariantRoot($element) === $element ) {
            return null;
        }
        $content = $this->richTextMaterializer->content($element);
        if ( '' === trim($this->runtime->stripAllTags($content)) || $this->richTextMaterializer->requiresHtmlFallback($content) ) {
            return null;
        }
        $attrs = $this->styleResolver->presentationAttributes($element);
        $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), self::LOWERED_PARAGRAPH_CLASS);
        $attrs['content'] = $content;

        return $this->createBlock('core/paragraph', $attrs, array(), $element);
    }

    private function paragraphBlockFromInlineContentWrapper(DOMElement $element): ?array
    {
        // An unknown element in block position is a generic container too
        // (see isTransparentUnknownElement()): its phrasing run is one paragraph.
        if ( ! ShellLandmarkPolicy::isInlineContentWrapperTag($element->tagName) && ! RichTextInlineTags::isUnknownHtmlElement($element) ) {
            return null;
        }

        // A direct inline child with its own layout geometry needs its source
        // tag as the flex/grid item. Ordinary inline runs still stay RichText.
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && $this->authorLayoutOwnership->requiresStandaloneInlineLayoutLeaf($child) ) {
                return null;
            }
        }

        if ( ! $this->sourceElementClassifier->hasOnlyPhrasingChildren($element) ) {
            return null;
        }

        if ( $this->hasEmptyVisualInlineChild($element) ) {
            $fallbacks = array();
            $children = $this->convertChildren($element, $fallbacks, true);
            if ( array() !== $children ) {
                return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($element), $children, $element);
            }
        }

        $content = $this->richTextMaterializer->content($element);
        $withLowered = $this->richTextMaterializer->contentWithInlineSafeButtonsLowered($element, $content);

        // A lone marked descendant normally needs an independent carrier.
        // Inside a safe inline button it belongs to the paragraph's RichText,
        // just as under a source <p>; splitting it off strands SVG descendants
        // before the existing carrier and image materialization can run. Beside
        // its own text it is part of one line box: splitting there would turn
        // each text run into a paragraph and drop the spaces between them.
        if ( $this->hasSemanticCarrierChild($element) || ( null === $withLowered && $this->hasRichTextMarkedDescendant($element) && 2 > $this->childElementCount($element) && ! $this->hasDirectText($element) ) ) {
            return null;
        }

        $structuredInlineItems = $this->structuredInlineItemLowering->structuredInlineItemBlocks($element);
        if ( null !== $structuredInlineItems ) {
            return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($element), $structuredInlineItems, $element);
        }

        if ( null !== $withLowered ) {
            // Reuse label RichText preparation so wrapper hooks become carriers
            // before button marks are introduced (the normalizer avoids nesting
            // marks when replacing spans). Then use the paragraph primitives.
            $content = $this->inlineRichTextContent($element);
            $content = $this->richTextMaterializer->contentWithInlineSafeButtonsLowered($element, $content) ?? $content;
            $content = $this->richTextMaterializer->contentWithMaterializedSvgImages($element, $content) ?? $content;
        }
        if ( '' === trim($this->runtime->stripAllTags($content)) ) {
            return null;
        }

        if ( 'div' === strtolower($element->tagName) && $this->hasMarginWrapperStyling($element) ) {
            $attrs = $this->styleResolver->presentationAttributes($element);
            $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), self::LOWERED_PARAGRAPH_CLASS);
            return $this->createBlock(
                'core/paragraph',
                array_merge($attrs, array( 'content' => $content )),
                array(),
                $element
            );
        }

        $attrs = $this->styleResolver->presentationAttributes($element);
        // The paragraph is this block-level wrapper's own box (it carries the
        // wrapper's identity and classes), so it must not become transparent.
        $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), self::SYNTHETIC_PARAGRAPH_CLASS . ' ' . SourceBlockAttributeProjector::SOURCE_BOX_PARAGRAPH_CLASS);
        $attrs['content'] = $content;
        return $this->createBlock('core/paragraph', $attrs, array(), $element);
    }

    /** @return array<string, mixed>|null */
    private function inlineAddressableRunGroupBlock(DOMElement $element): ?array
    {
        if ( ! $this->sourceElementClassifier->hasOnlyPhrasingChildren($element) ) return null;

        $addressable = 0;
        foreach ($element->childNodes as $child) {
            if ( ! $child instanceof DOMElement ) continue;
            if ($this->authorLayoutOwnership->requiresStandaloneInlineLayoutLeaf($child)) return null;
            if ('' !== trim($this->attr($child, 'id'))) ++$addressable;
        }
        if ($addressable < 2) return null;
        if ($this->authorLayoutOwnership->isAuthorOwnedLayout($element) || $this->hasEmptyVisualInlineChild($element)) return null;

        $content = $this->richTextMaterializer->content($element);
        if ('' === trim($this->runtime->stripAllTags($content)) || $this->richTextMaterializer->requiresHtmlFallback($content)) return null;

        $paragraph = $this->createBlock('core/paragraph', array('className' => self::INLINE_LAYOUT_CARRIER_CLASS, 'content' => $content));
        return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($element), array($paragraph), $element);
    }

    private function hasMultipleRuntimeInlineTextTargets(DOMElement $element): bool
    {
        if ( ! ShellLandmarkPolicy::isInlineContentWrapperTag($element->tagName) || ! $this->sourceElementClassifier->hasOnlyPhrasingChildren($element) ) {
            return false;
        }

        $targets = 0;
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            if ( $this->runtimeIslands->isRuntimeDomTarget($child) ) {
                ++$targets;
            }
        }

        return 1 < $targets;
    }

    private function inlineNavigationGroupBlockFromElement(DOMElement $element): ?array
    {
        if ( ! $this->sourceElementClassifier->hasOnlyPhrasingChildren($element) ) {
            return null;
        }

        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && $this->authorLayoutOwnership->requiresStandaloneInlineLayoutLeaf($child) ) {
                return null;
            }
        }

        $content = $this->richTextMaterializer->content($element);
        $inlineSvgContent = $this->richTextMaterializer->contentWithMaterializedSvgImages($element, $content);
        if ( null !== $inlineSvgContent ) {
            $content = $inlineSvgContent;
        }
        if ( '' === trim($this->runtime->stripAllTags($content)) || $this->richTextMaterializer->requiresHtmlFallbackWithoutNativeSvgImageObjects($content) ) {
            return null;
        }

        $paragraph = $this->createBlock('core/paragraph', array_merge($this->sourceBlockAttributeProjector->syntheticInlineParagraphAttributes($element), array( 'content' => $content )));
        return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($element), array( $paragraph ), $element);
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

    /**
     * Whether a direct child needs a semantic carrier of its own. Attribute
     * markers are classes an inline element keeps inside RichText, so they
     * leave the phrasing run one paragraph.
     */
    private function hasSemanticCarrierChild(DOMElement $element): bool
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && '' !== $this->session->authorSelectorProjectionState()->semanticMarker(SourceDom::sourceElementIdentity($child)) ) {
                return true;
            }
        }

        return false;
    }

    private function hasDirectText(DOMElement $element): bool
    {
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' !== trim($child->textContent ?? '') ) {
                return true;
            }
        }

        return false;
    }

    private function hasRichTextMarkedDescendant(DOMElement $element): bool
    {
        foreach ( $element->getElementsByTagName('span') as $span ) {
            if ( $span instanceof DOMElement && '' !== $this->richTextMarkerForElement($span) && ! $this->shouldPreserveEmptyVisualElement($span) ) {
                return true;
            }
        }

        return false;
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

    private function createCommercePattern(): CommerceStructureRecognizer
    {
        return new CommerceStructureRecognizer(
            $this->sourceElementClassifier,
            fn (DOMElement $element): ?array => $this->svgMaterializer->inlineSvgImageReference($element)
        );
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<string, mixed>|null
     */
    private function blockForSourceSelector(array $blocks, string $selector): ?array
    {
        foreach ( $blocks as $block ) {
            if ( ! is_array($block) ) {
                continue;
            }
            $provenanceId = $block['_source_provenance_id'] ?? null;
            if ( is_int($provenanceId) && $selector === ($this->session->transformationProvenanceState()->source($provenanceId)['selector'] ?? null) ) {
                return $block;
            }
            $nested = $this->blockForSourceSelector(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array(), $selector);
            if ( null !== $nested ) {
                return $nested;
            }
        }
        return null;
    }

    /**
     * A binding resolves against the emitted block tree in
     * `finalizeFallbackBindings()`. `$block` is the preferred anchor when the
     * caller owns a block that the page actually emits. `$anchorElement` is the
     * source element whose own emitted block anchors the binding when the
     * caller only has a separately synthesized representation of the subtree.
     *
     * @return array<string,mixed>
     */
    private function blockBinding(array $block, string $role, array $supersededRuntimeSelectors = array(), ?DOMElement $anchorElement = null): array
    {
        $provenanceId = $block['_source_provenance_id'] ?? null;
        $markup = array() !== $block ? $this->runtime->serializeBlocks(array($block)) : '';
        $anchorSelector = null !== $anchorElement ? $this->elementSelector($anchorElement) : '';
        if ( ( '' === trim($markup) || ! is_int($provenanceId) ) && '' === $anchorSelector ) {
            return array();
        }
        $binding = array('schema' => 'generic/block-binding/v1', 'search_block_markup' => $markup, 'occurrence' => 1, 'role' => $role);
        if ( is_int($provenanceId) ) {
            $binding['_binding_provenance_id'] = $provenanceId;
            $this->session->transformationProvenanceState()->markBindingAnchor($provenanceId);
        }
        if ( '' !== $anchorSelector ) $binding['_binding_anchor_selector'] = $anchorSelector;
        $supersededRuntimeSelectors = array_values(array_unique(array_filter($supersededRuntimeSelectors, static fn(mixed $selector): bool => is_string($selector) && '' !== trim($selector))));
        if ( array() !== $supersededRuntimeSelectors ) $binding['superseded_runtime_selectors'] = $supersededRuntimeSelectors;
        return $binding;
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
            $this->session->runtimeBehaviorState()->rememberNativeDisclosureRoot($element->getNodePath() ?? '');
        }

        return $block;
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

        return $anchor instanceof DOMElement && $this->imageLowering->isImageOnlyAnchor($anchor) ? $anchor : null;
    }

    private function citationFromElement(DOMElement $element): string
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && in_array(strtolower($child->tagName), array( 'cite', 'footer', 'figcaption' ), true) ) {
                return $this->styleResolver->innerHtml($child);
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
            $captionHtml = $this->styleResolver->innerHtml($caption);
            if ( '' !== trim($this->runtime->stripAllTags($captionHtml)) ) {
                $children[] = $this->createBlock('core/paragraph', array_merge($this->styleResolver->presentationAttributes($caption), array( 'content' => $captionHtml )), array(), $caption);
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
     * Comment attributes whose value is the block's registered default. The
     * block editor leaves these out of saved markup, so emitting them makes
     * converted markup differ from what WordPress itself saves for the same
     * block. Mirrors each block's block.json default.
     */
    private const DEFAULT_COMMENT_ATTRIBUTES = array(
        'core/table' => array( 'hasFixedLayout' => true ),
    );

    /**
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    private static function withoutDefaultCommentAttributes(array $blocks): array
    {
        $strip = static function (array $block) use (&$strip): array {
            $defaults = self::DEFAULT_COMMENT_ATTRIBUTES[ (string) ($block['blockName'] ?? '') ] ?? array();
            if ( is_array($block['attrs'] ?? null) ) {
                foreach ( $defaults as $name => $default ) {
                    if ( array_key_exists($name, $block['attrs']) && $default === $block['attrs'][ $name ] ) {
                        unset($block['attrs'][ $name ]);
                    }
                }
            }
            if ( is_array($block['innerBlocks'] ?? null) ) {
                $block['innerBlocks'] = array_map($strip, $block['innerBlocks']);
            }
            return $block;
        };
        return array_map($strip, $blocks);
    }

    /** @param array<int, array<string, mixed>> $fallbacks @return array<string, mixed> */
    private function decomposeStructuralList(DOMElement $list, array &$fallbacks): array
    {
        $items = array();
        foreach ( $this->listStructure->listMemberElements($list) as $child ) {
            if ( 'li' !== strtolower($child->tagName) ) {
                if ( ! $this->listStructure->listNonItemChildCarriesContent($child) ) {
                    continue;
                }

                $block = $this->convertElement($child, $fallbacks, true);
                if ( null !== $block ) {
                    $items[] = $block;
                }
                continue;
            }

            $children = $this->convertChildren($child, $fallbacks, true);
            if ( array() === $children ) {
                continue;
            }
            if ( 1 === count($children) ) {
                $coalesced = $this->coalescedSingleGroupWrapper($child, $children[0]);
                if ( null !== $coalesced ) {
                    $items[] = $coalesced;
                    continue;
                }
            }
            $items[] = $this->createBlock(
                'core/group',
                array_merge($this->authorLayoutOwnership->cssOwnedGroupAttributes($child), array( 'tagName' => 'li' )),
                $children,
                $child
            );
        }

        return $this->createBlock(
            'core/group',
            // This list is already materializing as a Group, so carrying its own
            // alignment cannot introduce the extra topology avoided by the
            // generic presentation resolver's text-align gate.
            array_merge($this->authorLayoutOwnership->cssOwnedGroupAttributes($list, true), array( 'tagName' => strtolower($list->tagName) )),
            $items,
            $list
        );
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     */
    private function captureInlineSvgFallback(DOMElement $element, array &$fallbacks): void
    {
        $this->session->fallbackEmitter()->captureInlineSvgFallback($element, $fallbacks);
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

    private function hasWorkspaceSurface(DOMElement $element): bool
    {
        foreach ( SourceDom::descendantElements($element) as $descendant ) {
            $tagName = strtolower($descendant->tagName);
            // Templates and their contents are inert, not visitor workspaces.
            if ( 'template' === $tagName || SourceDom::hasNonAuthoredAncestor($descendant) ) {
                continue;
            }
            // A canvas hidden from assistive technology is a decorative backdrop,
            // not a surface the visitor works in, so it does not make its wrapper
            // an application shell.
            if ( 'canvas' === $tagName && ! SourceDom::hasAriaHiddenAncestor($descendant) ) {
                return true;
            }
            // An iframe loading a third-party document by https URL is a content
            // embed. Inline (srcdoc), blank or relative-URL frames host an app surface.
            if ( 'iframe' === $tagName && ( $descendant->hasAttribute('srcdoc') || ! $this->sourceElementClassifier->isSafeVisualIframeUrl(trim($this->attr($descendant, 'src'))) ) ) {
                return true;
            }
            if ( 'textarea' === $tagName && $this->runtimeIslands->textareaIsRuntimeWorkspaceSurface($descendant, $element) ) {
                return true;
            }
            if ( in_array(strtolower(trim($this->attr($descendant, 'contenteditable'))), array('true', 'plaintext-only'), true) ) {
                return true;
            }
        }

        return false;
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
     * @return array<int, array<string, mixed>>
     */
    private function elementContains(DOMElement $ancestor, DOMElement $element): bool
    {
        $ancestorPath = $ancestor->getNodePath();
        for ( $node = $element; $node instanceof DOMElement; $node = $node->parentNode ) if ( $node->getNodePath() === $ancestorPath ) return true;
        return false;
    }

    private function convertPictureElement(DOMElement $picture, ?DOMElement $figure = null, ?DOMElement $link = null): ?array
    {
        $image = $this->firstChildElement($picture, 'img');
        if ( ! $image instanceof DOMElement ) {
            return null;
        }
        $this->imageDimensions->fillParentImageViewportPair($image);

        if ( $this->sourceElementClassifier->hasPictureSourceSelection($picture) ) {
            return $this->imageLowering->responsiveMediaBlock($link ?? $figure ?? $picture);
        }

        return $this->imageLowering->convertImage($image, $figure ?? $picture, $picture, $link);
    }


    /**
     * The image block a paragraph unwraps into keeps the paragraph's selector
     * identity, so rules that matched the paragraph in the source (type,
     * structural position, its box) still reach the block that replaced it.
     *
     * @return array<string, mixed>|null
     */
    private function unwrappedParagraphImageBlock(DOMElement $paragraph, bool $ignoreLineBreaks = false): ?array
    {
        $block = $this->imageBlockFromParagraph($paragraph, $ignoreLineBreaks);
        if ( null === $block ) {
            return null;
        }
        $path = $paragraph->getNodePath() ?? '';
        $projections = $this->session->authorSelectorProjectionState();
        $attrs = $block['attrs'];
        $attrs['className'] = $this->mergeClassNames(
            (string) ( $attrs['className'] ?? '' ),
            ...$projections->semanticCarrierClasses($projections->ensureSemanticMarker($path), $path)
        );
        if ( $this->paragraphImageSetsLineBox($paragraph, $ignoreLineBreaks) ) {
            $attrs['className'] = $this->mergeClassNames((string) $attrs['className'], SourceBlockAttributeProjector::PARAGRAPH_LINE_BOX_IMAGE_FIGURE_CLASS);
        }

        return $this->blockFactory->rebuild($block, $attrs);
    }

    /**
     * An inline image with an authored vertical margin sizes its paragraph's
     * line box: the margin sits inside the paragraph and never collapses with
     * its siblings. A blockified image would let that margin collapse through
     * the figure, so the figure keeps the image on a line instead.
     */
    private function paragraphImageSetsLineBox(DOMElement $paragraph, bool $ignoreLineBreaks = false): bool
    {
        $child = $this->paragraphImageChild($paragraph, $ignoreLineBreaks);
        $image = $child instanceof DOMElement && 'img' === strtolower($child->tagName) ? $child : $child?->getElementsByTagName('img')->item(0);
        if ( ! $image instanceof DOMElement
            || ! in_array($this->styleResolver->resolvedDeclaration($image, 'display'), array( '', 'inline', 'inline-block' ), true)
            || ! in_array($this->styleResolver->resolvedDeclaration($image, 'float'), array( '', 'none' ), true)
            || ! in_array($this->styleResolver->resolvedDeclaration($image, 'position'), array( '', 'static', 'relative' ), true) ) {
            return false;
        }
        foreach ( $this->resolvedVerticalMargins($image) as $value ) {
            if ( '' !== $value && ! in_array($value, array( '0', '0px', 'auto' ), true) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The element's resolved top and bottom margins, from the `margin`
     * shorthand or its longhands, whichever the cascade declared last.
     *
     * @return array{top: string, bottom: string}
     */
    private function resolvedVerticalMargins(DOMElement $element): array
    {
        $margins = array( 'top' => '', 'bottom' => '' );
        foreach ( $this->styleResolver->resolvedPresentationDeclarations($element) as $property => $value ) {
            $value = strtolower(trim(CssValueInspector::withoutImportant((string) $value)));
            if ( 'margin' === $property ) {
                [ $top, , $bottom ] = CssValueInspector::expandBoxShorthand($value);
                $margins = array( 'top' => $top, 'bottom' => $bottom );
            } elseif ( 'margin-top' === $property || 'margin-bottom' === $property ) {
                $margins[ substr($property, 7) ] = $value;
            }
        }

        return $margins;
    }

    /**
     * Route a paragraph containing a single image (or image-only link) through
     * the existing core/image primitive. Passive inline marks around that image
     * add no text formatting to preserve. Mixed content and multiple images
     * remain on the paragraph RichText route.
     *
     * @return array<string, mixed>|null
     */
    private function imageBlockFromParagraph(DOMElement $paragraph, bool $ignoreLineBreaks = false): ?array
    {
        $child = $this->paragraphImageChild($paragraph, $ignoreLineBreaks);
        if ( ! $child instanceof DOMElement ) {
            return null;
        }

        $tagName = strtolower($child->tagName);
        if ( 'img' === $tagName ) {
            return $this->imageLowering->convertImage($child);
        }
        if ( 'picture' === $tagName ) {
            return $this->convertPictureElement($child);
        }

        if ( '' !== LinkUrlSanitizer::sanitize($this->attr($child, 'href')) ) {
            return $this->anchors->imageBlockFromAnchor($child);
        }

        $image = $this->firstChildElement($child, 'img');
        return $image instanceof DOMElement ? $this->imageLowering->convertImage($image) : null;
    }

    /**
     * A heading or paragraph with no text carries no text semantics, so its
     * one iframe (or custom iframe element) lowers as the standalone embed it
     * visually is (#4303, #4666). A heading's image, picture or image-only
     * link takes the paragraph image route, keeping the heading's selector
     * identity the way a text-less paragraph keeps its own. `<br>` line
     * breaks and whitespace are not text; any other text keeps the heading's
     * RichText image behavior (#3496).
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function mediaBlockFromTextlessElement(DOMElement $element, array &$fallbacks): ?array
    {
        $media = $this->soleElementBesideLineBreaks($element);
        if ( null === $media || '' !== trim($this->textBesideLineBreaks($element)) ) {
            return null;
        }

        $tagName = strtolower($media->tagName);
        if ( 'iframe' === $tagName || ( str_contains($tagName, '-') && 1 === preg_match('/(?:^|-)iframe(?:-|$)/', $tagName) ) ) {
            return $this->visualIframeGenerator->convert($media, $fallbacks);
        }
        // Paragraph images keep their own stricter route, which leaves an
        // image beside a `<br>` in RichText.
        if ( 'p' === strtolower($element->tagName) ) {
            return null;
        }

        return $this->unwrappedParagraphImageBlock($element, true);
    }

    /** The element's text content, ignoring `<br>` line breaks and whitespace. */
    private function textBesideLineBreaks(DOMElement $element): string
    {
        $text = '';
        foreach ( $element->childNodes as $node ) {
            if ( $node instanceof DOMElement && 'br' === strtolower($node->tagName) ) {
                continue;
            }
            $text .= $node->textContent ?? '';
        }

        return trim($text);
    }

    /** The one non-`<br>` element child, or null when there is none or several. */
    private function soleElementBesideLineBreaks(DOMElement $element): ?DOMElement
    {
        $sole = null;
        foreach ( $element->childNodes as $node ) {
            if ( ! $node instanceof DOMElement || 'br' === strtolower($node->tagName) ) {
                continue;
            }
            if ( null !== $sole ) {
                return null;
            }
            $sole = $node;
        }

        return $sole;
    }

    /**
     * The image, picture or image-only anchor a paragraph wraps on its own
     * (through passive inline marks), which unwraps the paragraph into a
     * core/image block instead of a paragraph.
     */
    private function paragraphImageChild(DOMElement $paragraph, bool $ignoreLineBreaks = false): ?DOMElement
    {
        $child = null;
        foreach ( $paragraph->childNodes as $node ) {
            if ( $node instanceof DOMElement ) {
                if ( $ignoreLineBreaks && 'br' === strtolower($node->tagName) ) {
                    continue;
                }
                if ( $child instanceof DOMElement ) {
                    return null;
                }
                $child = $node;
                continue;
            }
            if ( '' !== trim($node->textContent ?? '') ) {
                return null;
            }
        }

        if ( ! $child instanceof DOMElement ) {
            return null;
        }
        if ( SourceDom::isInlineImageMark($child) ) {
            return $this->paragraphImageChild($child, $ignoreLineBreaks);
        }
        $tagName = strtolower($child->tagName);
        if ( in_array($tagName, array( 'img', 'picture' ), true) || ( 'a' === $tagName && $this->imageLowering->isImageOnlyAnchor($child) ) ) {
            return $child;
        }

        return null;
    }


    /** @return array<string, mixed> */
    private function svgArtworkBlock(DOMElement $element, string $svg): array
    {
        $generator = new SvgArtworkBlockGenerator();
        $this->session->requireGeneratedBlockRegistry()->register(SvgArtworkBlockGenerator::class, $generator->definition($this->session->requireGeneratedBlockRegistry()->namespace()));

        return $this->createBlock(
            $this->session->requireGeneratedBlockRegistry()->blockName(SvgArtworkBlockGenerator::LOCAL_NAME),
            array( 'svg' => $svg ),
            array(),
            $element
        );
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

    /**
     * Propagate a card-link wrapper's href onto a core/image block via its
     * native link attributes (href/linkDestination/linkTarget/rel). An image
     * that already carries its own link is left untouched.
     *
     * @param array<string, mixed> $block
     * @param array<string, string> $linkAttrs
     */
    /**
     * Rebuild a converted block with updated attributes so its innerHTML and
     * innerContent stay consistent with the stored attrs after an in-place edit
     * (e.g. a propagated link). Engine metadata the materializer attached —
     * source-provenance linkage and editability ownership — is preserved; no new
     * provenance is recorded for the rebuild.
     *
     * @param array<string, mixed> $block
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
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

}

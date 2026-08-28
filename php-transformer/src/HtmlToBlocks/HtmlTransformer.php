<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\AssetMaterializationState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\ReusableComponentState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\RuntimeBehaviorState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\RuntimeDomState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\RuntimeSelectorState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\TransformationEvidenceState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\TransformationProvenanceState;
use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionReportProjection;
use Automattic\BlocksEngine\PhpTransformer\Contract\EditabilityReport;
use Automattic\BlocksEngine\PhpTransformer\WordPress\CoreBlockCapabilityMatrix;
use Automattic\BlocksEngine\PhpTransformer\Contract\CoreHtmlFallbackEvidence;
use Automattic\BlocksEngine\PhpTransformer\Contract\TransformationOptions;
use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\SrcsetParser;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\ContentRoundTripReporter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthorLayoutBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredMarqueeBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\CapturedDialogBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\DescriptionListBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\LayoutShellBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\ResponsiveLayoutBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\ResponsiveMediaBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolutionContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ButtonLinkDispatchContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ButtonLinkDispatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\RuntimeIslandAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\RuntimeIslandContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\TableElementContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\TableElementConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\UnsupportedElementContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\UnsupportedElementRecorder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\RichTextElementContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\RichTextElementConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\TextLeafElementContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\TextLeafElementConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackDiagnostic;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\TypographyParityAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\DiagnosticsCollector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackEmitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\SemanticParityReporter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\AccordionPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ButtonPatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CodeWindowPatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ColumnsPatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\DetailsPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\GalleryPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\GalleryPatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\LogoPatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\MarkupPatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\MathPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\MediaPatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationPatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationUnderlineColorResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ParameterTablePattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternConversionResult;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternRecognitionResult;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternRecognizerRegistry;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternRecursiveConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PlaceholderMediaPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\QuotePattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\QuotePatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\SpacerPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\SocialLinksPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorSelectorProjectionState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorStyleAnalysis;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\LayoutGeometryState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\NavigationStyleProjectionTrait;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\PresentationResolutionCache;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssSelectorMatchCache;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AdminBarAccommodation;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\GeneratedSupportStylesheetState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceStyleResolutionState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\BackgroundImageExtractor;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\DomHelpersTrait;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\ElementConversionTrait;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\FormDispatchTrait;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\LinkUrlSanitizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\NavigationToggleSuppressionTrait;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SvgMaterializationTrait;
use Automattic\BlocksEngine\PhpTransformer\StaticSite\FontMaterialization\FontMaterializationPlanBuilder;
use Automattic\BlocksEngine\PhpTransformer\Support\StyleTagScanner;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMDocument;
use DOMElement;
use DOMNode;

final class HtmlTransformer
{
    private const GENERATED_COMPONENT_MIN_SOURCE_DEPTH = 14;

    /**
     * Instances a custom element tag must reach before recurring usage can be
     * read as a wrapping convention rather than a component.
     */
    private const WRAPPER_CONVENTION_MIN_INSTANCES = 3;

    /**
     * Depth of the shallow tag skeleton compared across wrapper instances.
     */
    private const WRAPPER_CONTENT_SHAPE_DEPTH = 2;
    use DomHelpersTrait;
    use ElementConversionTrait;
    use FormDispatchTrait;
    use NavigationStyleProjectionTrait;
    use NavigationToggleSuppressionTrait;
    use SvgMaterializationTrait;

    private const MAX_INTERACTION_CANDIDATES = 100;
    private const MAX_CAPTURED_LAYOUT_SOURCE_NESTING = 20;
    private const MAX_NATIVE_LIST_VIEW_DEPTH = 20;

    /**
     * Core blocks this transformer can produce, keyed by the contract that
     * verifies that producer. Runtime registration is intentionally not part of
     * this declaration: a runtime may expose blocks the HTML producer does not
     * recognize (for example, the dynamic core/icon block).
     *
     * @return array<string,string>
     */
    public static function emittedCoreBlockContracts(): array
    {
        return array(
            'core/accordion' => 'html_transformer_contract',
            'core/accordion-heading' => 'html_transformer_contract',
            'core/accordion-item' => 'html_transformer_contract',
            'core/accordion-panel' => 'html_transformer_contract',
            'core/audio' => 'html_transformer_contract',
            'core/button' => 'html_transformer_contract',
            'core/buttons' => 'html_transformer_contract',
            'core/code' => 'html_transformer_contract',
            'core/column' => 'html_transformer_contract',
            'core/columns' => 'html_transformer_contract',
            'core/cover' => 'html_transformer_contract',
            'core/details' => 'html_transformer_contract',
            'core/embed' => 'html_transformer_contract',
            'core/file' => 'html_transformer_contract',
            'core/gallery' => 'html_transformer_contract',
            'core/group' => 'html_transformer_contract',
            'core/heading' => 'html_transformer_contract',
            'core/image' => 'html_transformer_contract',
            'core/list' => 'html_transformer_contract',
            'core/list-item' => 'html_transformer_contract',
            'core/math' => 'html_transformer_contract',
            'core/media-text' => 'html_transformer_contract',
            'core/navigation' => 'html_transformer_contract',
            'core/navigation-link' => 'html_transformer_contract',
            'core/navigation-submenu' => 'html_transformer_contract',
            'core/paragraph' => 'html_transformer_contract',
            'core/preformatted' => 'html_transformer_contract',
            'core/pullquote' => 'html_transformer_contract',
            'core/quote' => 'html_transformer_contract',
            'core/search' => 'html_transformer_contract',
            'core/separator' => 'html_transformer_contract',
            'core/shortcode' => 'html_transformer_contract',
            'core/social-link' => 'html_transformer_contract',
            'core/social-links' => 'html_transformer_contract',
            'core/spacer' => 'html_transformer_contract',
            'core/table' => 'html_transformer_contract',
            'core/video' => 'html_transformer_contract',
        );
    }

    /**
     * Reference viewport width (px) for resolving responsive image constraints
     * (aspect-ratio/object-fit) to the single value WordPress' core/image carries.
     * min-width @media overrides at or below this width may win over the base rule.
     */
    private const DESKTOP_REFERENCE_WIDTH = 1440;

    /**
     * Root font size (px) used to resolve `em`/`rem` media-query breakpoints.
     * Media features resolve these against the initial value, not any authored
     * font-size, so the CSS default is the correct constant rather than a guess.
     */
    private const ROOT_FONT_SIZE_PX = 16;

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

    private readonly BlockFactory $blockFactory;

    private readonly BackgroundImageExtractor $backgroundImageExtractor;

    private readonly TableClassificationPolicy $tableClassificationPolicy;

    private readonly PatternRecognizerRegistry $patternRecognizers;

    private readonly TextLeafElementConverter $textLeafConverter;

    private readonly RichTextElementConverter $richTextConverter;

    private readonly StyleResolver $styleResolver;

    private readonly RuntimeIslandAnalyzer $runtimeIslands;

    private readonly ButtonLinkDispatcher $buttonLinkDispatcher;

    private readonly TableElementConverter $tableConverter;

    private readonly UnsupportedElementRecorder $unsupportedRecorder;

    private readonly PatternContext $patternContext;

    private readonly PatternContext $patternContextWithoutRuntimeDomTarget;

    private readonly PatternContext $patternProbeContext;

    private readonly NavigationUnderlineColorResolver $navigationUnderlineColorResolver;

    private readonly NavigationBlockNormalizer $navigationBlockNormalizer;

    private readonly DiagnosticsCollector $diagnosticsCollector;

    private readonly SemanticParityReporter $semanticParityReporter;

    private readonly ContentRoundTripReporter $contentRoundTripReporter;

    private readonly ReusableComponentRecognizer $reusableComponentRecognizer;

    private HtmlTransformerSession $session;

    private const SYNTHETIC_PARAGRAPH_CLASS = 'blocks-engine-synthetic-paragraph';

    private const SYNTHETIC_ANCHOR_UNDECORATED_CLASS = 'blocks-engine-synthetic-anchor-undecorated';

    private const SYNTHETIC_HEADER_ANCHOR_CLASS_PREFIX = 'blocks-engine-synthetic-header-anchor-';

    private const SYNTHETIC_IMAGE_FIGURE_CLASS = 'blocks-engine-synthetic-image-figure';

    private const INLINE_LAYOUT_CARRIER_CLASS = 'blocks-engine-inline-layout-carrier';


    private const EMPTY_FLEX_ITEM_CLASS = 'blocks-engine-empty-flex-item';

    private const EMPTY_RUNTIME_TARGET_CLASS = 'blocks-engine-empty-runtime-target';

    private const CSS_OWNED_LAYOUT_CLASS = 'blocks-engine-css-owned-layout';

    private const CSS_OWNED_FLOW_CLASS = 'blocks-engine-css-owned-flow';

    private const CSS_OWNED_GRID_CLASS = 'blocks-engine-css-owned-grid';

    private const CSS_OWNED_INLINE_FLOW_CLASS = 'blocks-engine-css-owned-inline-flow';

    /** @var list<string> Inline grid declarations carried to the generated stylesheet for css-owned grids. */
    private const CSS_OWNED_GRID_CARRIER_PROPERTIES = array(
        'display',
        'grid',
        'grid-template',
        'grid-template-areas',
        'grid-template-columns',
        'grid-template-rows',
        'grid-auto-flow',
        'grid-auto-columns',
        'grid-auto-rows',
        'gap',
        'row-gap',
        'column-gap',
        'grid-row-gap',
        'grid-column-gap',
        'align-content',
        'align-items',
        'justify-content',
        'justify-items',
        'place-content',
        'place-items',
    );

    /** @var list<string> Inline flex declarations carried to the generated stylesheet for css-owned flex containers. */
    private const CSS_OWNED_FLEX_CARRIER_PROPERTIES = array(
        'display',
        'flex-flow',
        'flex-direction',
        'flex-wrap',
        'gap',
        'row-gap',
        'column-gap',
        'align-content',
        'align-items',
        'justify-content',
        'place-content',
    );

    private const CSS_OWNED_LAYOUT_ITEM_CLASS = 'blocks-engine-css-owned-layout-item';

    public function __construct(
        private readonly Runtime $runtime = new Runtime(),
        private readonly HtmlTransformerAnalysisCache $analysisCache = new HtmlTransformerAnalysisCache()
    )
    {
        $this->session = new HtmlTransformerSession(
            $this->runtime,
            fn (DOMElement $element): array => $this->sourceContext($element)
        );
        $this->blockFactory      = new BlockFactory();
        $this->backgroundImageExtractor = new BackgroundImageExtractor();
        $this->tableClassificationPolicy = new TableClassificationPolicy();
        $this->patternRecognizers = PatternRecognizerRegistry::createDefault();
        $this->navigationUnderlineColorResolver = new NavigationUnderlineColorResolver();
        $this->navigationBlockNormalizer = new NavigationBlockNormalizer(fn (string $label): string => $this->normalizedNavigationLabel($label));
        $this->diagnosticsCollector = new DiagnosticsCollector();
        $this->semanticParityReporter = new SemanticParityReporter(
            $this->runtime,
            new TypographyParityAnalyzer(new FontMaterializationPlanBuilder($this->analysisCache->cssFontAnalysis))
        );
        $this->contentRoundTripReporter = new ContentRoundTripReporter();
        $this->reusableComponentRecognizer = new ReusableComponentRecognizer();
        $this->patternContext = $this->createPatternContext(true);
        $this->patternContextWithoutRuntimeDomTarget = $this->createPatternContext(false);
        $this->patternProbeContext = $this->createProbePatternContext();
        $this->textLeafConverter = new TextLeafElementConverter($this->createTextLeafElementContext());
        $this->richTextConverter = new RichTextElementConverter($this->createRichTextElementContext());
        $this->styleResolver = new StyleResolver($this->createStyleResolutionContext(), $this->analysisCache);
        $this->runtimeIslands = new RuntimeIslandAnalyzer($this->createRuntimeIslandContext());
        $this->buttonLinkDispatcher = new ButtonLinkDispatcher($this->createButtonLinkDispatchContext());
        $this->tableConverter = new TableElementConverter($this->createTableElementContext());
        $this->unsupportedRecorder = new UnsupportedElementRecorder($this->createUnsupportedElementContext());
    }


    /**
     * Link canonicalization rewrote source markup, so cached selector matches
     * for the previous markup are no longer valid.
     */
    private function onSourceMarkupMutated(): void
    {
        $this->styleResolver->invalidateSourceSelectorMatchCache();
    }

    /**
     * Collaborator surface for {@see StyleResolver}. Per-transform state is
     * resolved lazily so the resolver always sees the running transform.
     */
    private function createStyleResolutionContext(): StyleResolutionContext
    {
        return new StyleResolutionContext(
            fn (): AuthorStyleAnalysis => $this->authorStyles(),
            fn (): SourceStyleResolutionState => $this->sourceStyles(),
            fn (): LayoutGeometryState => $this->layoutGeometry(),
            fn (): PresentationResolutionCache => $this->presentationResolutionCache(),
            fn (): TransformationEvidenceState => $this->transformationEvidence(),
            fn (DOMElement $element, string $name): string => $this->attr($element, $name),
            fn (DOMElement $element): string => $this->elementSelector($element),
            fn (DOMElement $element): int => $this->cardLikeChildCount($element),
            fn (DOMElement $element): int => $this->directElementChildCount($element),
            fn (string $value): string => $this->cssComparableValue($value),
            fn (string $selector): array => $this->parsedCssSelector($selector),
            fn (string $className): string => $this->promotedClassName($className),
            fn (string $value, ?DOMElement $element = null): string => $this->resolveCssVariablesInValue($value, $element),
            fn (string $url): string => $this->resolvedAssetImageUrl($url),
            fn (string $id): string => $this->safeAnchor($id)
        );
    }

    /**
     * Collaborator surface for {@see ButtonLinkDispatcher}.
     */
    private function createButtonLinkDispatchContext(): ButtonLinkDispatchContext
    {
        return new ButtonLinkDispatchContext(
            fn (DOMElement $element): bool => $this->runtimeIslands->isRuntimeDomTarget($element),
            function (DOMElement $element): void {
                $this->recordRuntimeControlIsland($element);
            },
            fn (DOMElement $element): array => $this->htmlPreservationBlock($element),
            function (DOMElement $element, array &$fallbacks, array $patterns): ?array {
                return $this->recognizePatterns($element, $fallbacks, $patterns);
            },
            function (DOMElement $element, array &$fallbacks): ?array {
                return $this->linkedSvgLogoBlockFromAnchor($element, $fallbacks);
            },
            fn (DOMElement $element): ?array => $this->imageBlockFromAnchor($element),
            function (DOMElement $element, array &$fallbacks): ?array {
                return $this->convertLinkWrapperGroup($element, $fallbacks);
            },
            fn (DOMElement $element, array $excludedProperties, array $excludedGeometryProperties): array => $this->styleResolver->presentationAttributes($element, $excludedProperties, $excludedGeometryProperties),
            fn (string $name, array $attributes, array $innerBlocks, ?DOMElement $sourceElement): array => $this->createBlock($name, $attributes, $innerBlocks, $sourceElement),
            fn (DOMElement $element, string $name): string => $this->attr($element, $name),
            fn (DOMElement $element): string => $this->outerHtml($element),
            fn (string $href): string => $this->safeLinkUrl($href),
            fn (DOMElement $element): bool => $this->hasBlockContentChildren($element),
            fn (string $first, string $second): string => $this->mergeClassNames($first, $second),
            fn (DOMElement $element): array => $this->styleResolver->structuralPresentationDeclarations($element)
        );
    }

    /**
     * Collaborator surface for {@see RuntimeIslandAnalyzer}. Session-scoped
     * state is resolved lazily so the analyzer always sees the running transform.
     */
    private function createRuntimeIslandContext(): RuntimeIslandContext
    {
        return new RuntimeIslandContext(
            fn (): FallbackEmitter => $this->fallbackEmitter(),
            fn (): RuntimeDomState => $this->runtimeDom(),
            fn (): RuntimeSelectorState => $this->runtimeSelectors(),
            fn (DOMElement $element, string $name): string => $this->attr($element, $name),
            fn (DOMElement $element): iterable => $this->descendantElements($element),
            fn (DOMElement $element): string => $this->runtimeIslandSelector($element),
            fn (DOMElement $element): array => $this->eventMetadata($element),
            fn (DOMElement $element): array => $this->requiredScriptsForElement($element),
            fn (string $html): ?DOMElement => $this->preservedHtmlRootElement($html),
            fn (DOMElement $element): bool => $this->formHasDataEntryControls($element),
            fn (DOMElement $element): bool => $this->hasFormAncestor($element),
            fn (DOMElement $element): bool => $this->hasWorkspaceSurface($element),
            fn (DOMElement $element): bool => $this->isDivBasedPseudoForm($element),
            fn (DOMElement $element): bool => $this->isFormControlElement($element),
            fn (string $tagName): bool => $this->isInlineContentElement($tagName),
            fn (string $selector): bool => $this->isPresentationalAnimationSelector($selector),
            fn (array $rows): array => $this->dedupeArrayRows($rows)
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
                return $this->nestedLayoutTableColumnsBlock($element, $fallbacks);
            },
            function (DOMElement $element, array &$fallbacks): ?array {
                return $this->mediaLayoutTableColumnsBlock($element, $fallbacks);
            },
            fn (DOMElement $element): array => $this->htmlPreservationBlock($element),
            fn (DOMElement $element, array $excludedProperties, array $excludedGeometryProperties): array => $this->styleResolver->presentationAttributes($element, $excludedProperties, $excludedGeometryProperties),
            fn (DOMElement $element): array => $this->tableAttributes($element),
            fn (string $name, array $attributes, array $innerBlocks, ?DOMElement $sourceElement): array => $this->createBlock($name, $attributes, $innerBlocks, $sourceElement)
        );
    }

    /**
     * Collaborator surface for {@see UnsupportedElementRecorder}.
     */
    private function createUnsupportedElementContext(): UnsupportedElementContext
    {
        return new UnsupportedElementContext(
            fn (DOMElement $element): ?array => $this->fallbackEmitter()->maybeGenerateCustomBlock($element, $this->generatedBlocks()),
            fn (array $generated, DOMElement $element): array => $this->generatedComponentBlock($generated, $element),
            fn (DOMElement $element): string => $this->elementSelector($element),
            fn (DOMElement $element): array => $this->htmlAttributes($element),
            fn (DOMElement $element): array => $this->sourceContext($element),
            fn (DOMElement $element): array => $this->fallbackEmitter()->classifyFallbackSubtree($element),
            fn (DOMElement $element): array => $this->eventMetadata($element),
            fn (DOMElement $element): int => $this->childElementCount($element),
            fn (DOMElement $element): string => $this->safeFallbackHtml($element),
            fn (DOMElement $element): array => $this->formControlMetadata($element),
            fn (array $fallback): array => FallbackDiagnostic::build($fallback, $this->transformationProvenance()->fallback())
        );
    }

    /**
     * Collaborator surface for {@see RichTextElementConverter}.
     */
    private function createRichTextElementContext(): RichTextElementContext
    {
        return new RichTextElementContext(
            fn (DOMElement $element, array $excludedProperties, array $excludedGeometryProperties): array => $this->styleResolver->presentationAttributes($element, $excludedProperties, $excludedGeometryProperties),
            fn (string $name, array $attributes, array $innerBlocks, ?DOMElement $sourceElement): array => $this->createBlock($name, $attributes, $innerBlocks, $sourceElement),
            fn (DOMElement $element, array $excludedTags): string => $this->richTextContentWithMaterializedInlineStyles($element, $excludedTags),
            fn (string $content): string => $this->headingRichTextContent($content),
            fn (DOMElement $element, string $content): ?string => $this->richTextContentWithMaterializedSvgImages($element, $content),
            fn (string $content): bool => $this->richTextRequiresHtmlFallbackWithoutNativeSvgImageObjects($content),
            fn (string $content): bool => $this->richTextContainsNativeSvgImageObject($content),
            fn (DOMElement $element): array => $this->htmlPreservationBlock($element),
            fn (DOMElement $element): ?array => $this->authoredMarqueeBlock($element),
            fn (DOMElement $element): bool => $this->hasEmptyVisualInlineChild($element),
            fn (DOMElement $element): bool => $this->hasBoxChromeWrapperStyling($element),
            fn (DOMElement $element): bool => $this->runtimeIslands->isRuntimeDomTarget($element),
            fn (string $text): array => $this->convertText($text),
            fn (string $html): string => $this->runtime->stripAllTags($html),
            function (DOMElement $element, array &$fallbacks, bool $captureUnsupported): array {
                return $this->convertChildren($element, $fallbacks, $captureUnsupported);
            }
        );
    }

    /**
     * Collaborator surface for {@see TextLeafElementConverter}. Enumerating the
     * operations here is the point: the converter cannot reach transformer
     * state that is not listed.
     */
    private function createTextLeafElementContext(): TextLeafElementContext
    {
        return new TextLeafElementContext(
            fn (DOMElement $element, array $excludedProperties, array $excludedGeometryProperties): array => $this->styleResolver->presentationAttributes($element, $excludedProperties, $excludedGeometryProperties),
            fn (DOMElement $element): string => $this->innerHtml($element),
            fn (DOMElement $element): string => $this->innerHtmlPreservingWhitespace($element),
            fn (string $name, array $attributes, array $innerBlocks, ?DOMElement $sourceElement): array => $this->createBlock($name, $attributes, $innerBlocks, $sourceElement),
            fn (DOMElement $element, array $excludedTags): string => $this->richTextContentWithMaterializedInlineStyles($element, $excludedTags),
            fn (string $html): string => $this->runtime->stripAllTags($html),
            fn (string $text): string => $this->runtime->escapeHtml($text),
            fn (DOMElement $element, string $tagName): ?DOMElement => $this->firstChildElement($element, $tagName),
            fn (DOMElement $pre, DOMElement $code): array => $this->codePresentationAttributes($pre, $code),
            fn (DOMElement $code): string => $this->codeContent($code),
            fn (DOMElement $element): bool => $this->hasBlockContentChildren($element),
            function (DOMElement $element, array &$fallbacks, bool $captureUnsupported): array {
                return $this->convertChildren($element, $fallbacks, $captureUnsupported);
            }
        );
    }

    private function authorStyles(): AuthorStyleAnalysis
    {
        return $this->session->authorStyleAnalysis()
            ?? throw new \LogicException('Author styles have not been prepared for this transform.');
    }

    private function layoutGeometry(): LayoutGeometryState
    {
        return $this->session->layoutGeometryState()
            ?? throw new \LogicException('Layout geometry state has not been prepared for this transform.');
    }

    private function transformationProvenance(): TransformationProvenanceState
    {
        return $this->session->transformationProvenanceState();
    }

    private function transformationEvidence(): TransformationEvidenceState
    {
        return $this->session->transformationEvidenceState();
    }

    private function runtimeBehavior(): RuntimeBehaviorState
    {
        return $this->session->runtimeBehaviorState();
    }

    private function fallbackEmitter(): FallbackEmitter
    {
        return $this->session->fallbackEmitter();
    }

    private function presentationResolutionCache(): PresentationResolutionCache
    {
        return $this->session->presentationResolutionCache();
    }

    private function generatedBlocks(): GeneratedBlockRegistry
    {
        return $this->session->generatedBlockRegistry()
            ?? throw new \LogicException('Generated block registry has not been prepared for this transform.');
    }

    private function materializedAssets(): AssetMaterializationState
    {
        return $this->session->assetMaterializationState()
            ?? throw new \LogicException('Asset materialization state has not been prepared for this transform.');
    }

    private function runtimeDom(): RuntimeDomState
    {
        return $this->session->runtimeDomState();
    }

    private function runtimeSelectors(): RuntimeSelectorState
    {
        return $this->session->runtimeSelectorState();
    }

    private function sourceStyles(): SourceStyleResolutionState
    {
        return $this->session->sourceStyleResolutionState();
    }

    private function reusableComponents(): ReusableComponentState
    {
        return $this->session->reusableComponentState();
    }

    private function authorSelectorProjections(): AuthorSelectorProjectionState
    {
        return $this->session->authorSelectorProjectionState();
    }

    private function generatedSupportStyles(): GeneratedSupportStylesheetState
    {
        return $this->session->generatedSupportStylesheetState();
    }

    protected function fallbackSourceTagMarker(string $tagName): string
    {
        return $this->authorSelectorProjections()->tagMarker($tagName);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function transform(string $html, array $options = array()): TransformerResult
    {
        $this->session = new HtmlTransformerSession(
            $this->runtime,
            fn (DOMElement $element): array => $this->sourceContext($element)
        );
        $context = TransformationOptions::context($options);
        $startedAt = hrtime(true);
        $this->transformationProvenance()->installFallback(TransformationOptions::provenance($options));
        $this->session->installGeneratedBlockRegistry(new GeneratedBlockRegistry($this->generatedBlockNamespaceFromOptions($options)));
        $this->session->installAssetMaterializationState(new AssetMaterializationState(
            trim((string) ($options['generated_asset_root'] ?? ''), '/'),
            $this->assetMetadataFromOptions($options)
        ));
        $this->session->configurePolicy(! empty($options['extract_global_shell']), ! empty($options['fallback_reduction_mode']));
        $this->runtimeBehavior()->installRuntimeScriptMetadata($this->runtimeIslands->runtimeScriptMetadataFromOptions($options));
        $staticCss = (string) ($options['static_css'] ?? '');
        $styleAnalysis = $this->composedStyleAnalysis($this->stylesheetPayloads($html, $staticCss, $options));
        $this->sourceStyles()->installStylesheetAnalysis($this->detectStaticClassPromotions($html), $styleAnalysis);
        $this->styleResolver->resetPresentationResolutionCache();
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
                'transformer'   => self::class,
            ), $this->transformationProvenance()->fallback()),
        );

        $sourceBodyClasses = $this->documentBodyClassNames($html);
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
                ), $this->transformationProvenance()->fallback()),
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

        if ( array() !== $sourceBodyClasses ) {
            $body->setAttribute('class', implode(' ', $sourceBodyClasses));
        }

        $this->navigationBlockNormalizer->hydrateDuplicateSubmenus($body);
        $this->materializeDeclarativeCounters($body, (string) ($options['declarative_state_html'] ?? ''));
        // Remove wrapper-convention custom elements before author selectors are
        // prepared, so style projection and component promotion both observe the
        // content rather than the source's wrapping convention.
        $this->unwrapRenderNeutralCustomElements($body, $this->authoredCssText($html, (string) ($options['static_css'] ?? '')));
        $this->prepareAuthorSelectorSemantics($html, (string) ($options['static_css'] ?? ''), $body, $options);
        $this->fallbackEmitter()->configure($this->transformationProvenance()->fallback(), $this->runtimeBehavior()->runtimeScriptMetadata(), $this->runtimeSelectors(), $this->authorSelectorProjections()->tagMarkers());
        // Author-selector preparation marks source nodes for later projection.
        // General style matching begins only after those source mutations settle.
        $this->styleResolver->invalidateSourceSelectorMatchCache();
        $this->styleResolver->collectEditorHiddenStateFindings($body);
        $this->reusableComponents()->installRecognition($this->reusableComponentRecognizer->recognize($body));

        $fallbacks   = array();
        $interactionCandidates = $this->interactionCandidates($body);
        $this->collectProjectedNavigationRelationships($body);
        $this->collectSupersededNavToggleSelectors($body);
        $shellArtifacts = !array_key_exists('extract_global_shell', $options) || !empty($options['extract_global_shell']) ? $this->globalShellArtifacts($body, (string) ($options['source'] ?? 'html')) : array();
        $this->collectGeneratedComponentCandidates($body);
        $blocks      = $this->navigationBlockNormalizer->normalize($this->convertChildren($body, $fallbacks, true), $this->transformationProvenance()->sources(), $this->transformationProvenance()->sourceBaseHiddenStates());
        $blocks = $this->compressProjectedGroupChains($blocks);
        // Last resort under measured depth pressure: past this cap the
        // editability policy hard-fails the document anyway, so admit exact
        // two-wrapper branch shells whether or not layout-geometry proofs
        // accompanied the artifact.
        if (self::MAX_NATIVE_LIST_VIEW_DEPTH < $this->blockTreeDepth($blocks)) {
            $blocks = $this->compressProjectedGroupChains($blocks, true);
        }
        $fallbacks = array_merge($fallbacks, $this->transformationEvidence()->responsiveImageFallbacks());
        if (! $this->session->usesFallbackReductionMode()) {
            $blocks = $this->reduceCoreHtmlFallbackBlocks($blocks);
        }
        $this->runtimeIslands->recordRuntimeIslandsForPreservedHtmlBlocks($blocks);
        $this->appendInteractiveControlBehaviorLossFallbacks($body, $fallbacks);
        $this->appendProductGridFallbacks($body, $fallbacks, $blocks);
        $this->appendCommerceControlsFallbacks($body, $fallbacks);
        $serializedBlocks = $this->runtime->serializeBlocks($blocks);
        $this->finalizeFallbackBindings($fallbacks, $blocks, $serializedBlocks);
        $reusableComponentRecognition = $this->reusableComponents()->report($this->materializedAssets()->assets());
        $sourceProvenance = $this->transformationProvenance()->resolveBlockPaths($blocks);
        $authorStylesheetProjections = $this->authorStylesheetProjections();
        $this->materializeAuthorStylesheet(
            $html,
            (string) ($options['static_css'] ?? ''),
            true !== ($options['skip_author_stylesheet_materialization'] ?? false),
            $serializedBlocks,
            $sourceProvenance
        );
        $this->materializeEditorStaticStateStylesheet();
        $blockValidityReport = $this->runtime->validateBlockSerialization($blocks);
        $semanticParityReport = $this->semanticParityReporter->report($body, $blocks, $sourceProvenance, $html, (string) ($options['static_css'] ?? ''));
        $contentRoundTripReport = $this->contentRoundTripReporter->report($serializedBlocks, $html, $this->transformationEvidence()->formControlEchoTexts());
        $diagnostics = $this->diagnosticsCollector->collect(
            self::class,
            $this->runtimeBehavior()->scriptMetadata(),
            $fallbacks,
            $this->runtimeDom()->islands(),
            $this->runtimeDom()->preservations(),
            $this->runtimeDom()->fallbacks(),
            $blockValidityReport,
            $semanticParityReport,
            $contentRoundTripReport
        );
        foreach ( $this->transformationEvidence()->responsiveGeometryAmbiguities() as $ambiguity ) {
            $diagnostics[] = array(
                'code' => 'responsive_geometry_ambiguous_min_width',
                'message' => 'A wide minimum-width rule matches both page-shell and authored content surfaces, so it was retained without a responsive projection.',
                'source' => self::class,
                'severity' => 'warning',
                'selector' => $ambiguity['selector'],
                'min_width' => $ambiguity['min_width'],
            );
        }
        $headMetadata = $this->headMetadataReport($html);
        if ( array() !== $headMetadata ) {
            $diagnostics[] = array(
                'code' => 'html_head_metadata_not_carried',
                'message' => 'Named head metadata (meta description and social property tags) is not representable in block markup; the entries are surfaced in source_reports.head_metadata for the destination document to adopt deliberately.',
                'source' => self::class,
                'severity' => 'info',
                'entries' => $headMetadata,
            );
        }
        $authorLayoutTopologyFindings = $this->transformationEvidence()->authorLayoutTopologyFindings();
        foreach ( $authorLayoutTopologyFindings as $finding ) {
            $diagnostics[] = array(
                'code' => 'author_layout_topology_changed',
                'message' => 'Gutenberg block conversion changed the direct-child topology of a CSS-owned layout container.',
                'source' => self::class,
                'severity' => 'warning',
                'selector' => $finding['selector'],
                'source_child_count' => $finding['source_child_count'],
                'block_child_count' => $finding['block_child_count'],
            );
        }
        if ( $this->generatedBlocks()->has(DescriptionListBlockGenerator::class) ) {
            $diagnostics[] = array(
                'code' => 'semantic_description_list_gutenberg_gap',
                'message' => 'A semantic description list was materialized with the Blocks Engine companion block because Gutenberg has no core description-list block.',
                'source' => self::class,
                'severity' => 'info',
                'references' => array(
                    'https://github.com/WordPress/gutenberg/issues/4880',
                    'https://github.com/WordPress/gutenberg/pull/20760',
                ),
            );
        }

        $this->styleResolver->recordSourceSelectorMatchWork();
        $metrics = $this->metrics($html, $blocks, $serializedBlocks, $fallbacks, $diagnostics, $startedAt);
        $nativeTargetBlocks = $this->runtime->availableCoreBlockNames();
        $capabilityMatrix = (new CoreBlockCapabilityMatrix())->coverage($nativeTargetBlocks);
        $supportedBlocks = $capabilityMatrix['supported_blocks'];
        $runtimeBlockPaths = array_values(array_filter(array_map(static fn (array $entry): string => !empty($entry['editability_runtime_owned']) ? (string) ($entry['block_path'] ?? '') : '', $sourceProvenance)));
        $visualBlockPaths = array_values(array_filter(array_map(static fn (array $entry): string => !empty($entry['editability_visual_owned']) ? (string) ($entry['block_path'] ?? '') : '', $sourceProvenance)));
        $generatedCarrierCss = $this->engineSupportCss();
        $sourceReports = array(
            'native_target_blocks' => $nativeTargetBlocks,
            'available_core_blocks' => $nativeTargetBlocks,
            'core_block_capabilities' => $capabilityMatrix,
            'head_metadata' => $headMetadata,
            'runtime_islands' => $this->runtimeDom()->islands(),
            'runtime_dom_contracts' => $this->runtimeDom()->preservations(),
            'runtime_dom_fallbacks' => $this->runtimeDom()->fallbacks(),
            'generated_blocks' => $this->generatedBlocks()->definitions(),
            'gutenberg_gaps' => $this->generatedBlocks()->has(DescriptionListBlockGenerator::class) ? array(
                array(
                    'id' => 'semantic-description-list',
                    'block_name' => DescriptionListBlockGenerator::NAME,
                    'references' => array(
                        'https://github.com/WordPress/gutenberg/issues/4880',
                        'https://github.com/WordPress/gutenberg/pull/20760',
                    ),
                ),
            ) : array(),
            'interaction_candidates' => $interactionCandidates,
            'superseded_selectors' => $this->runtimeSelectors()->supersededSelectors(),
            'shell_artifacts' => $shellArtifacts,
            'wp_block_validity' => $blockValidityReport,
            'semantic_parity' => $semanticParityReport,
            'content_round_trip' => $contentRoundTripReport,
            'editability_report' => (new EditabilityReport())->fromBlocks($blocks, (string) ($options['source'] ?? ''), $serializedBlocks, $generatedCarrierCss, $runtimeBlockPaths, $visualBlockPaths, $sourceProvenance),
            'html' => array(
                'presentation_signals' => $this->transformationProvenance()->presentationSignals(),
                'frozen_hidden_state'  => $this->transformationEvidence()->frozenHiddenStateFindings(),
                'dropped_link_wrappers' => $this->transformationEvidence()->droppedLinkWrapperFindings(),
                'gutenberg_incompatibilities' => $this->transformationEvidence()->gutenbergIncompatibilities(),
                'author_layout_topology' => $authorLayoutTopologyFindings,
                'source_provenance'    => $sourceProvenance,
                'core_html_fallback_evidence' => CoreHtmlFallbackEvidence::fromBlocks($blocks, $fallbacks, $sourceProvenance),
                'structure_signals'    => $this->transformationProvenance()->structureSignals(),
                'reusable_components' => $reusableComponentRecognition,
                'script_metadata'      => $this->runtimeBehavior()->scriptMetadata(),
                'runtime_islands'      => $this->runtimeDom()->islands(),
                'layout_geometry_proof' => $this->layoutGeometry()->proofProvenance(),
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
            assets: $this->materializedAssets()->assets(),
            diagnostics: $diagnostics,
            fallbacks: $fallbacks,
            provenance: $provenance,
            sourceReports: $sourceReports,
            coverage: array(
                array(
                    'supported_blocks'      => $supportedBlocks,
                    'runtime_available_blocks' => $nativeTargetBlocks,
                    'capability_matrix'     => $capabilityMatrix,
                    'block_count'           => count($blocks),
                    'fallback_count'        => count($fallbacks),
                    'source_provenance_count' => count($sourceProvenance),
                ),
            ),
            context: $context,
            metrics: $metrics
        );
    }

    private function engineSupportCss(): string
    {
        $css = array();
        foreach ($this->materializedAssets()->assets() as $asset) if ('engine-support' === ($asset['source'] ?? '') && 'css' === ($asset['kind'] ?? '') && is_string($asset['content'] ?? null)) $css[] = $asset['content'];
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
    public function reduceCoreHtmlFallbackBlocks(array $blocks): array
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
                $replacement = $this->safeFallbackFragmentBlocks($html);
                array_push($reduced, ...($replacement ?? array($block)));
                continue;
            }

            $children = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array();
            if (array() !== $children) {
                $reducedChildren = array();
                $childReplacements = array();
                foreach ($children as $child) {
                    $replacement = $this->reduceCoreHtmlFallbackBlocks(array($child));
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
    private function safeFallbackFragmentBlocks(string $html): ?array
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

        $result = (new self($this->runtime, $this->analysisCache))->transform($html, array('extract_global_shell' => false, 'fallback_reduction_mode' => true));
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

        return $blocks;
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
            $blocks = $this->navigationBlockNormalizer->normalize($this->convertChildren($child, $shellFallbacks, true), $this->transformationProvenance()->sources(), $this->transformationProvenance()->sourceBaseHiddenStates());
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
            $templatePartAttrs = $wrapperAttrs;
            unset($templatePartAttrs['tagName']);
            $templatePartMarkup = array() === $templatePartAttrs
                ? $innerMarkup
                : $this->runtime->serializeBlocks(array($this->createBlock('core/group', $templatePartAttrs, $blocks[0]['innerBlocks'] ?? array())));
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
                'template_part_block_markup' => $templatePartMarkup,
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
        $selectorCache = $this->sourceStyles()->selectorMatchCache;
        return array(
            'input_bytes'           => strlen($input),
            'block_count'           => $this->countBlocks($blocks),
            'fallback_count'        => count($fallbacks),
            'diagnostic_count'      => count($diagnostics),
            'transform_duration_ms' => (hrtime(true) - $startedAt) / 1000000,
            'output_bytes'          => strlen($output),
            'selector_match_cache_hits' => $selectorCache?->matchHits ?? 0,
            'selector_match_cache_misses' => $selectorCache?->matchMisses ?? 0,
            'selector_match_cache_evictions' => $selectorCache?->matchEvictions ?? 0,
            'selector_match_cache_peak_entries' => $selectorCache?->matchPeakEntries ?? 0,
            'style_rule_candidate_cache_hits' => $selectorCache?->candidateRuleHits ?? 0,
            'style_rule_candidate_cache_misses' => $selectorCache?->candidateRuleMisses ?? 0,
            'style_rule_candidate_cache_evictions' => $selectorCache?->candidateRuleEvictions ?? 0,
            'style_rule_candidate_cache_peak_entries' => $selectorCache?->candidateRulePeakEntries ?? 0,
            'style_rule_candidate_cache_peak_rule_references' => $selectorCache?->candidateRulePeakRetained ?? 0,
        );
    }

    private function reusableComponentFingerprintFor(DOMElement $element): ?string
    {
        return $this->reusableComponents()->fingerprintForPath((string) $element->getNodePath());
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
        $instances = array();
        foreach ( $body->getElementsByTagName('*') as $element ) {
            if ( $element instanceof DOMElement && str_contains(strtolower($element->tagName), '-') ) {
                $instances[strtolower($element->tagName)][] = $element;
            }
        }

        foreach ( $instances as $tag => $elements ) {
            if ( ! $this->isWrapperConventionTag($tag, $elements, $staticCss) ) {
                continue;
            }
            foreach ( $elements as $element ) {
                if ( $this->isRenderNeutralWrapper($element) ) {
                    $this->unwrapElement($element);
                }
            }
        }
    }

    /**
     * Whether a custom element tag is used as a wrapping convention rather than
     * as a component host.
     *
     * @param array<int, DOMElement> $elements Every instance of the tag.
     */
    private function isWrapperConventionTag(string $tag, array $elements, string $staticCss): bool
    {
        if ( self::WRAPPER_CONVENTION_MIN_INSTANCES > count($elements) ) {
            return false;
        }

        // A tag that carries presentation of its own is not render-neutral.
        // `display:contents` is the exception that proves the rule: it states
        // the element generates no box and renders as its children.
        if ( ! $this->customElementCssIsRenderNeutral($tag, $staticCss) ) {
            return false;
        }

        $shapes = array();
        foreach ( $elements as $element ) {
            $shapes[$this->wrappedContentShape($element)] = true;
            if ( 1 < count($shapes) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Author CSS visible to the document: declared stylesheets plus the styles
     * the source embeds inline.
     */
    private function authoredCssText(string $html, string $staticCss): string
    {
        $embedded = array();
        if ( 0 < preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $html, $matches) ) {
            $embedded = $matches[1];
        }

        return trim($staticCss . "\n" . implode("\n", $embedded));
    }

    /**
     * Whether every author rule addressing a custom element tag leaves it
     * render-neutral, so replacing instances with their children preserves the
     * authored presentation.
     */
    private function customElementCssIsRenderNeutral(string $tag, string $css): bool
    {
        if ( '' === trim($css) ) {
            return true;
        }

        $pattern = '/(?<![\w-])' . preg_quote($tag, '/') . '(?![\w-])/i';
        if ( 1 !== preg_match($pattern, $css) ) {
            return true;
        }

        foreach ( $this->cssRuleBlocks($css) as $rule ) {
            if ( 1 !== preg_match($pattern, $rule['selector']) ) {
                continue;
            }
            foreach ( $this->styleResolver->cssDeclarations($rule['declarations']) as $property => $value ) {
                if ( 'display' !== strtolower(trim($property)) || 'contents' !== strtolower(trim($value)) ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Flat selector/declaration pairs for the top-level rules in a stylesheet.
     *
     * @return array<int, array{selector: string, declarations: string}>
     */
    private function cssRuleBlocks(string $css): array
    {
        $rules = array();
        if ( 1 !== preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $matches, PREG_SET_ORDER) && array() === $matches ) {
            return $rules;
        }

        foreach ( $matches as $match ) {
            $rules[] = array(
                'selector'     => trim($match[1]),
                'declarations' => trim($match[2]),
            );
        }

        return $rules;
    }

    /**
     * Shallow tag skeleton of the content a wrapper carries. Instances of a real
     * component repeat this shape; instances of a wrapper do not.
     */
    private function wrappedContentShape(DOMElement $element, int $depth = 0): string
    {
        $parts = array();
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            $tag = strtolower($child->tagName);
            $parts[] = self::WRAPPER_CONTENT_SHAPE_DEPTH > $depth
                ? $tag . '(' . $this->wrappedContentShape($child, $depth + 1) . ')'
                : $tag;
        }

        return implode(',', $parts);
    }

    /**
     * Whether a wrapper instance carries no identity, behavior, or presentation
     * of its own, and can therefore be replaced by its children.
     */
    private function isRenderNeutralWrapper(DOMElement $element): bool
    {
        return null !== $element->parentNode
            && '' === trim($this->attr($element, 'id'))
            && '' === trim($this->attr($element, 'class'))
            && '' === trim($this->attr($element, 'role'))
            && '' === trim($this->attr($element, 'style'))
            && array() === $this->interactiveAttributes($element)
            && ! $this->runtimeIslands->isRuntimeDomTarget($element)
            && ! $this->hasMotionStructureToken($element);
    }

    private function collectGeneratedComponentCandidates(DOMElement $element, int $depth = 0): void
    {
        if (self::GENERATED_COMPONENT_MIN_SOURCE_DEPTH <= $depth
            && ('div' === strtolower($element->tagName) || str_contains(strtolower($element->tagName), '-'))
            && ($this->hasRepeatedDirectChildTags($element) || str_contains(strtolower($element->tagName), '-'))
            && ($this->fallbackEmitter()->isRepeatableContentComponent($element) || $this->fallbackEmitter()->isSafeCustomElementHost($element))
        ) {
            $this->reusableComponents()->markGeneratedCandidate((string) $element->getNodePath());
            return;
        }

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $this->collectGeneratedComponentCandidates($child, $depth + 1);
            }
        }
    }

    private function hasRepeatedDirectChildTags(DOMElement $element): bool
    {
        $counts = array();
        foreach ($element->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            if (2 <= $counts[$tag]) {
                return true;
            }
        }
        return false;
    }

    private function isGeneratedComponentCandidate(DOMElement $element): bool
    {
        return $this->reusableComponents()->isGeneratedCandidate((string) $element->getNodePath());
    }

    /** @param array{blockName: string, attrs: array<string, mixed>} $generated @return array<string, mixed> */
    private function generatedComponentBlock(array $generated, DOMElement $element): array
    {
        $block = $this->createBlock($generated['blockName'], $generated['attrs'], array(), $element);
        foreach (array_merge(array($element), $this->descendantElements($element)) as $target) {
            if (! $this->runtimeIslands->isRuntimeDomTarget($target)) {
                continue;
            }
            $block['_editability_runtime_owned'] = true;
            $this->runtimeIslands->recordNativeRuntimeDomPreservation($target, $generated['blockName']);
        }
        return $block;
    }

    /**
     * Named head metadata (meta description, social property tags) has no
     * block-markup representation. Surface the entries so consumers can carry
     * them to the destination document deliberately instead of reading the
     * strip as a malformed design. Mechanical entries (charset, viewport)
     * belong to the destination document and are not reported.
     *
     * @return array<int, array<string, string>>
     */
    private function headMetadataReport(string $html): array
    {
        if ( ! preg_match('/<meta[\s>]/i', $html) ) {
            return array();
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $head = $loaded ? $document->getElementsByTagName('head')->item(0) : null;
        if ( ! $head instanceof DOMElement ) {
            return array();
        }

        $entries = array();
        foreach ( $head->getElementsByTagName('meta') as $meta ) {
            if ( ! $meta instanceof DOMElement ) {
                continue;
            }
            $content = trim($meta->getAttribute('content'));
            $name = strtolower(trim($meta->getAttribute('name')));
            $property = strtolower(trim($meta->getAttribute('property')));
            if ( '' === $content || ( '' === $name && '' === $property ) || 'viewport' === $name ) {
                continue;
            }
            $entries[] = array_filter(array(
                'name'     => $name,
                'property' => $property,
                'content'  => substr($content, 0, 500),
            ), static fn (string $value): bool => '' !== $value);
        }

        return array_slice($entries, 0, 20);
    }

    /** @param array<int, array<string, mixed>> $sourceProvenance */
    private function materializeAuthorStylesheet(string $html, string $staticCss, bool $includeAuthorStyles = true, string $serializedBlocks = '', array $sourceProvenance = array()): void
    {
        $beforeAuthorCssParts = array();
        $authorCssParts = array();
        $afterAuthorCssParts = array();
        $authorCss = '';
        if ( $includeAuthorStyles && '' !== $this->authorStyles()->combinedCss() ) {
            $authorCss = $this->rewriteAuthorStylesheet($this->authorStyles()->combinedCss());
            $split = ( new CssStylesheetTransformer() )->splitLeadingAtRulePreamble($authorCss);
            if ( '' !== trim($split['preamble']) ) {
                $authorCssParts[] = $split['preamble'];
            }
            $authorCss = $split['stylesheet'];
        }
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
        if ( str_contains($serializedBlocks, self::SYNTHETIC_PARAGRAPH_CLASS) ) {
            // A paragraph is required for valid block markup, but phrasing content
            // did not have paragraph margins in the source document.
            $beforeAuthorCssParts[] = ':root :where(.' . self::SYNTHETIC_PARAGRAPH_CLASS . '){margin-top:0;margin-bottom:0}'
                . "\n" . ':root :where(p.' . self::SYNTHETIC_PARAGRAPH_CLASS . '.has-text-color)>a{color:inherit}'
                . "\n" . ':where(p.' . self::SYNTHETIC_PARAGRAPH_CLASS . ')>a{text-decoration:underline}'
                . "\n" . ':where(p.' . self::SYNTHETIC_PARAGRAPH_CLASS . '.' . self::SYNTHETIC_ANCHOR_UNDECORATED_CLASS . ')>a{text-decoration:none}';
        }
        if ( str_contains($serializedBlocks, self::SYNTHETIC_IMAGE_FIGURE_CLASS) ) {
            $beforeAuthorCssParts[] = '.' . self::SYNTHETIC_IMAGE_FIGURE_CLASS . '{margin:0}';
        }
        if ( str_contains($serializedBlocks, self::INLINE_LAYOUT_CARRIER_CLASS) ) {
            $beforeAuthorCssParts[] = ':where(p.' . self::INLINE_LAYOUT_CARRIER_CLASS . '){display:contents;margin:0!important;padding:0!important;border:0!important}';
        }
        if ( str_contains($serializedBlocks, self::CSS_OWNED_FLOW_CLASS) ) {
            $beforeAuthorCssParts[] = ':root :where(.' . self::CSS_OWNED_FLOW_CLASS . '>p){margin-top:0;margin-bottom:0}';
        }
        if ( str_contains($serializedBlocks, ButtonLinkDispatcher::POSITIONED_FRAGMENT_LINK_CARRIER_CLASS) ) {
            // Positioned fragment links retain their source anchor and selectors;
            // their valid paragraph host must not create a line box in document flow.
            $beforeAuthorCssParts[] = ':where(.' . ButtonLinkDispatcher::POSITIONED_FRAGMENT_LINK_CARRIER_CLASS . '){display:contents!important}';
        }
        if ( str_contains($serializedBlocks, self::EMPTY_FLEX_ITEM_CLASS) ) {
            $beforeAuthorCssParts[] = ':where(.' . self::EMPTY_FLEX_ITEM_CLASS . '){flex:0 0 0!important;width:0!important;min-width:0!important;margin-left:0!important;margin-right:0!important}';
        }
        if ( str_contains($serializedBlocks, self::CSS_OWNED_FLOW_CLASS) ) {
            // Core flow spacing is not part of a source grid or flex contract.
            // This precedes author CSS so source child margins remain authoritative.
            $beforeAuthorCssParts[] = ':root :where(.wp-block-group.' . self::CSS_OWNED_FLOW_CLASS . ')>*{margin-block-start:0;margin-block-end:0}';
        }
        if ( str_contains($serializedBlocks, self::CSS_OWNED_GRID_CLASS) ) {
            // Core flow margins are not part of a source grid contract; the
            // carried grid geometry (gap) owns the spacing between items. The
            // carrier rides groups and lists, so the reset is class-scoped.
            $beforeAuthorCssParts[] = ':root :where(.' . self::CSS_OWNED_GRID_CLASS . ')>*{margin-block-start:0;margin-block-end:0}';
        }
        if ( str_contains($serializedBlocks, self::CSS_OWNED_INLINE_FLOW_CLASS) ) {
            // Block delimiters may acquire whitespace when Gutenberg saves the
            // post. Flex owns the source's atomic inline flow without counting
            // those text nodes as width; later responsive display rules still win.
            $beforeAuthorCssParts[] = ':where(.' . self::CSS_OWNED_INLINE_FLOW_CLASS . '){display:flex;flex-wrap:wrap;align-items:baseline;gap:0}'
                . "\n" . ':where(.' . self::CSS_OWNED_INLINE_FLOW_CLASS . ')>*{flex:none}';
        }
        if ( str_contains($serializedBlocks, self::CSS_OWNED_LAYOUT_ITEM_CLASS) ) {
            // A semantic Group used as a direct grid/flex item contains native
            // paragraph blocks. Neutralize only those generated inner defaults.
            $beforeAuthorCssParts[] = ':root :where(.wp-block-group.' . self::CSS_OWNED_LAYOUT_ITEM_CLASS . ')>*{margin-block-start:0;margin-block-end:0}';
        }
        foreach ( $this->navigationLinkTextColorRules($serializedBlocks) as $navigationLinkTextColorRule ) {
            $afterAuthorCssParts[] = $navigationLinkTextColorRule;
        }
        array_push($afterAuthorCssParts, ...$this->generatedSupportStyles()->conditionalAfterAuthorCss($serializedBlocks));
        if ( str_contains($serializedBlocks, 'blocks-engine-list-navigation') ) {
            $beforeAuthorCssParts[] = '.wp-block-navigation.blocks-engine-list-navigation .wp-block-navigation-item.wp-block-navigation-link{display:list-item;font:inherit}'
                . "\n" . '.wp-block-navigation.blocks-engine-list-navigation .wp-block-navigation-item__content{display:inline}'
                . "\n" . '.wp-block-navigation.blocks-engine-list-navigation .wp-block-navigation__container{display:flex;flex-direction:row;flex-wrap:wrap;list-style:none}';
        }
        $nativeSearchTriggerCss = $this->generatedSupportStyles()->beforeAuthorCss();
        if ( '' !== $nativeSearchTriggerCss ) {
            $beforeAuthorCssParts[] = $nativeSearchTriggerCss;
        }
        if ( '' !== trim($authorCss) ) {
            $authorCssParts[] = $authorCss;
            $adminBarAccommodation = (new AdminBarAccommodation())->supportCss($authorCss);
            if ( '' !== $adminBarAccommodation ) {
                $afterAuthorCssParts[] = $adminBarAccommodation;
            }
        }
        if ( str_contains($serializedBlocks, 'blocks-engine-list-navigation') ) {
            // Keep only source-responsive navigation hosts visible. Ordinary
            // link rows retain authored mobile display rules without core's
            // overlay control replacing them.
            if ( str_contains($serializedBlocks, 'blocks-engine-native-responsive-navigation') ) {
                $afterAuthorCssParts[] = '.wp-block-navigation.blocks-engine-list-navigation.blocks-engine-native-responsive-navigation{display:flex!important}';
            }
            if ( str_contains($serializedBlocks, 'blocks-engine-projected-dialog-navigation') ) {
                $mobileOverlayBackground = $this->sourceMobileNavigationOverlayBackground();
                $fallbackTextColor = '';
                if ( '' === $mobileOverlayBackground ) {
                    $mobileOverlayBackground = '#fff';
                    $fallbackTextColor = 'color:#111!important;';
                }
                $projectedOpenMenu = '.wp-block-navigation.blocks-engine-projected-dialog-navigation .wp-block-navigation__responsive-container.is-menu-open';
                $afterAuthorCssParts[] = $projectedOpenMenu . '{background:' . $mobileOverlayBackground . '!important;' . $fallbackTextColor . 'position:fixed!important;inset:0!important;padding:clamp(4rem,12vh,7rem) clamp(1.5rem,6vw,4rem) 2rem!important;overflow-y:auto!important;z-index:99998!important}'
                    . "\n" . $projectedOpenMenu . ' .wp-block-navigation__responsive-container-content{align-items:flex-start!important;justify-content:flex-start!important;gap:1rem!important;width:100%!important}'
                    . "\n" . $projectedOpenMenu . ' .wp-block-navigation__container{align-items:flex-start!important;gap:.75rem!important;width:100%!important}'
                    . "\n" . $projectedOpenMenu . ' .wp-block-navigation-item__content{' . $fallbackTextColor . 'font-size:clamp(1.125rem,4vw,1.5rem)!important;line-height:1.4!important;padding:.5rem 0!important}'
                    . "\n" . $projectedOpenMenu . ' .wp-block-navigation__responsive-container-close{background:#fff!important;color:#111!important;position:fixed!important;top:1rem!important;right:1rem!important;padding:.75rem!important;z-index:1!important}'
                    . "\n" . 'body.admin-bar ' . $projectedOpenMenu . '{top:var(--wp-admin--admin-bar--height,32px)!important}'
                    . "\n" . 'body.admin-bar ' . $projectedOpenMenu . ' .wp-block-navigation__responsive-container-close{top:calc(1rem + var(--wp-admin--admin-bar--height,32px))!important}';
            }
            // Size a carried menu to its content when it sits inside a brand
            // carrier. The carrier renders <nav> and core/navigation renders
            // another <nav> inside it, so an authored `header nav` rule matches
            // both, and the block's auto flex-basis resolves to the whole
            // available width where the authored <ul> was content-sized. The
            // landmark's `justify-content:space-between` then has nothing left
            // to distribute and the brand is squeezed until it wraps: measured
            // on silver-summit at 1366px, brand 181x44 and menu 308 at x=962
            // became 155x82 and menu 1005 at x=265. `max-width:100%` keeps the
            // block shrinkable, so a narrow viewport still hands over to core's
            // responsive overlay rather than overflowing the page.
            $afterAuthorCssParts[] = 'nav.wp-block-group>.wp-block-navigation.blocks-engine-list-navigation{width:max-content;max-width:100%}';
            foreach ( $this->listNavigationInlineMarginRules($serializedBlocks) as $inlineMarginRule ) {
                $afterAuthorCssParts[] = $inlineMarginRule;
            }
            foreach ( $this->listNavigationPaddingRules($serializedBlocks) as $paddingRule ) {
                $afterAuthorCssParts[] = $paddingRule;
            }
            foreach ( $this->listNavigationItemAnchorRules($serializedBlocks, $sourceProvenance) as $itemAnchorRule ) {
                $afterAuthorCssParts[] = $itemAnchorRule;
            }
            $mobileOverlayBackground = $this->sourceMobileNavigationOverlayBackground();
            if ( '' !== $mobileOverlayBackground ) {
                $afterAuthorCssParts[] = '.wp-block-navigation.blocks-engine-list-navigation .wp-block-navigation__responsive-container.is-menu-open{background:' . $mobileOverlayBackground . '!important}';
            }
            if ( str_contains($serializedBlocks, 'wp:navigation-submenu') ) {
                // Source shell containers commonly clip their original, in-flow
                // menu. Core's generated desktop submenu extends outside that
                // box, so release only converted Group ancestors that contain it.
                // Zero specificity lets an authored !important overflow remain
                // authoritative, and leaves Core's mobile overlay untouched.
                $afterAuthorCssParts[] = ':where(.wp-block-group:has(.wp-block-navigation.blocks-engine-list-navigation .wp-block-navigation-submenu)){overflow:visible!important}';
            }
        }
        if ( str_contains($serializedBlocks, 'blocks-engine-inline-navigation') ) {
            $afterAuthorCssParts[] = '.wp-block-navigation.blocks-engine-native-responsive-navigation.blocks-engine-inline-navigation{display:inline-flex!important}';
        }
        if ( str_contains($serializedBlocks, 'wp:social-links') ) {
            $afterAuthorCssParts[] = '.wp-block-social-links.is-style-logos-only .wp-social-link{background-image:none;background-color:transparent}';
        }
        if ( str_contains($serializedBlocks, 'blocks-engine-source-social-item-spacing') ) {
            $afterAuthorCssParts[] = '.wp-block-social-links.blocks-engine-source-social-item-spacing{gap:0}';
        }
        foreach ( $this->navigationItemStateAnchorRules($serializedBlocks, $sourceProvenance) as $itemAnchorRule ) {
            $afterAuthorCssParts[] = $itemAnchorRule;
        }
        $directNavigationCss = $this->directNavigationSupportCss($serializedBlocks);
        if ( '' !== $directNavigationCss ) {
            $afterAuthorCssParts[] = $directNavigationCss;
        }
        array_push($afterAuthorCssParts, ...$this->generatedSupportStyles()->buttonAfterAuthorCss());
        $this->materializeStylesheetAsset($beforeAuthorCssParts, 'engine-support', 'before-author', 'engine-support-before-author');
        $this->materializeStylesheetAsset($authorCssParts, 'author-css', 'author', 'source-author');
        $this->materializeStylesheetAsset($afterAuthorCssParts, 'engine-support', 'after-author', 'engine-support-after-author');
    }

    private function richTextMarkerResetCss(): string
    {
        if ( ! $this->authorSelectorProjections()->hasRichTextMarkers() ) {
            return '';
        }

        return ':where(mark)[style*="--blocks-engine-richtext-marker:"]{background-color:transparent;color:inherit}';
    }

    /** @param array<string, mixed> $options */
    private function prepareAuthorSelectorSemantics(string $html, string $staticCss, DOMElement $sourceBody, array $options): void
    {
        $stylesheetAssets = $this->authorStylesheetAssetsFromOptions($options);
        $combinedAuthorCss = array() === $stylesheetAssets
            ? $this->combinedAuthorStylesheet($html, $staticCss)
            : implode("\n\n", array_column($stylesheetAssets, 'content'));
        $this->session->installAuthorStyleAnalysis(new AuthorStyleAnalysis($html, $combinedAuthorCss, $stylesheetAssets, $sourceBody));
        $this->sourceStyles()->setFormLayoutCss($combinedAuthorCss);

        if ( '' === $combinedAuthorCss ) {
            return;
        }

        $authorAnalysis = $this->composedAuthorSelectorAnalysis($this->authorStylesheetPayloads($html, $staticCss));
        $sourceTagSelectorNames = $authorAnalysis['source_tags'];
        $authorSelectors = $authorAnalysis['selectors'];
        $authorStyleRules = $authorAnalysis['rules'];
        foreach ( array_keys($sourceTagSelectorNames) as $tagName ) {
            $this->authorSelectorProjections()->ensureTagMarker($tagName);
        }
		$this->discoverAuthorControlPaths($authorSelectors);
		$this->authorStyles()->installStyleRules($authorStyleRules);
		$this->discoverAuthorInlineSemanticPaths($authorSelectors);
		$this->discoverAuthorAttributePaths($authorSelectors);
		$this->discoverAuthorRootChildPaths($authorSelectors);
		$this->discoverAuthorTablePaths($authorSelectors);
        $this->authorStyles()->setSourceBodyProjectionClasses($this->referencedSourceBodyClasses($sourceBody));
        $matchCache = $this->authorStyles()->releaseSelectorMatchCache();
        $this->analysisCache->authorSelectorClassTokenBuilds += $matchCache->classTokenBuilds;
        $this->analysisCache->authorSelectorClassTokenHits += $matchCache->classTokenHits;
        $this->analysisCache->authorSelectorAttributeReads += $matchCache->attributeReads;
    }

    /** @return list<string> */
    private function referencedSourceBodyClasses(DOMElement $sourceBody): array
    {
        $classes = preg_split('/\s+/', trim($this->attr($sourceBody, 'class'))) ?: array();
        return array_values(array_filter(array_unique($classes), function (string $class): bool {
            return '' !== $class && (bool) preg_match('/\.' . preg_quote($class, '/') . '(?:\b|(?=[.#:\[]))/', $this->authorStyles()->combinedCss());
        }));
    }

	/** @param list<array{selector:string,parsed:array<string,mixed>}> $authorSelectors */
    private function discoverAuthorControlPaths(array $authorSelectors): void
    {
		foreach ( $authorSelectors as $authorSelector ) {
				$selector = $authorSelector['selector'];
				$parsed = $authorSelector['parsed'];
                if ( ! $parsed['supported'] ) {
                    continue;
                }
                $matches = $this->matchingAuthorSourceElements($selector, $parsed);
                $controls = array_filter($matches, static fn (DOMElement $element): bool => in_array(strtolower($element->tagName), array( 'a', 'button' ), true));
                if ( array() === $controls ) {
                    continue;
                }
                foreach ( $controls as $control ) {
                    $path = $control->getNodePath() ?? '';
                    if ( '' !== $path ) {
                        $this->authorSelectorProjections()->markControlPath($path);
                    }
                }
		}
    }

	/** @param list<array{selector:string,parsed:array<string,mixed>}> $authorSelectors */
    private function discoverAuthorInlineSemanticPaths(array $authorSelectors): void
    {
		foreach ( $authorSelectors as $authorSelector ) {
				$selector = $authorSelector['selector'];
				$parsed = $authorSelector['parsed'];
                if ( ! $parsed['supported'] ) {
                    continue;
                }
                foreach ( $this->matchingAuthorSourceElements($selector, $parsed) as $element ) {
                    $inlineTag = strtolower($element->tagName);
                    $directChildSelector = '>' === ($parsed['combinators'][count($parsed['combinators']) - 1] ?? null);
                    $directAuthorLayoutItem = $directChildSelector && $this->isDirectChildOfAuthorOwnedLayout($element);
                    if ( ! $this->isInlineContentElement($inlineTag) || ('span' !== $inlineTag && ! $directAuthorLayoutItem) ) {
                        continue;
                    }
                    $path = $this->sourceElementIdentity($element);
                    if ( '' === $path ) {
                        continue;
                    }
                    $listItem = $this->ancestorElement($element, 'li');
                    $structuralListItem = $listItem instanceof DOMElement && $this->isStructuralListItem($listItem);
                    // Normal list-item content serializes through RichText. A
                    // structural item receives native child blocks instead.
                    if ( $listItem instanceof DOMElement && ! $structuralListItem && $this->richTextSelectorNeedsHook($parsed) ) {
                        $marker = $this->authorSelectorProjections()->ensureRichTextMarker($path);
                        $element->setAttribute('data-blocks-engine-richtext-marker', $marker);
                    } elseif ( $directAuthorLayoutItem
                        || ($structuralListItem && $this->richTextSelectorNeedsHook($parsed))
                        || $this->requiresIndependentSemanticWrapper($element)
                    ) {
                        if ( '' !== $path ) {
                            $this->authorSelectorProjections()->ensureSemanticMarker($path);
                        }
                    } elseif ( $this->richTextSelectorNeedsHook($parsed) ) {
                        $marker = $this->authorSelectorProjections()->ensureRichTextMarker($path);
                        // Carry the generated identity through intermediate
                        // wrapper conversions before RichText normalizes spans.
                        $element->setAttribute('data-blocks-engine-richtext-marker', $marker);
                    }
                }
		}
    }

	/** @param list<array{selector:string,parsed:array<string,mixed>}> $authorSelectors */
    private function discoverAuthorAttributePaths(array $authorSelectors): void
    {
		foreach ( $authorSelectors as $authorSelector ) {
            $parsed = $authorSelector['parsed'];
            if ( ! $parsed['supported'] || null !== $parsed['pseudo_state_suffix_span'] ) {
                continue;
            }

            if ( $this->hasRightmostNegatedDataAttribute($parsed) ) {
                $matches = $this->matchingAuthorSourceElements($authorSelector['selector'], $parsed);
                $marker = '';
                foreach ( $this->matchingAuthorSourceElementsIgnoringNegation($parsed) as $element ) {
                    if ( in_array($element, $matches, true) ) {
                        continue;
                    }
                    $path = $this->sourceElementIdentity($element);
                    if ( '' !== $path ) {
                        $marker = '' === $marker ? $this->allocateAuthorMarker('attribute-state') : $marker;
                        $this->authorSelectorProjections()->addAttributeStateMarker($path, $marker);
                        $element->setAttribute('class', $this->mergeClassNames($this->attr($element, 'class'), $marker));
                    }
                }
                if ( '' !== $marker ) {
                    $this->authorSelectorProjections()->installAttributeNegationMarker($authorSelector['selector'], $marker);
                }
            }

            $compounds = $parsed['compounds'] ?? array();
            $rightmost = $compounds[array_key_last($compounds)] ?? array();
            $hasDataAttribute = array_filter($rightmost['attributes'] ?? array(), static fn (array $attribute): bool => str_starts_with($attribute['name'] ?? '', 'data-'));
            if ( array() === $hasDataAttribute ) {
                continue;
            }
            foreach ( $this->matchingAuthorSourceElements($authorSelector['selector'], $parsed) as $element ) {
                $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
                $hasBoxGeometry = array() !== array_intersect_key($declarations, array_flip(array(
                    'display', 'position', 'inset', 'top', 'right', 'bottom', 'left',
                    'width', 'min-width', 'max-width', 'height', 'min-height', 'max-height',
                    'margin', 'padding', 'flex', 'flex-basis', 'flex-grow', 'flex-shrink', 'grid', 'grid-area',
                )));
                if ( ! $hasBoxGeometry && 'img' !== strtolower($element->tagName) ) {
                    continue;
                }
                $path = $this->sourceElementIdentity($element);
                if ( '' !== $path ) {
                    $marker = $this->authorSelectorProjections()->ensureAttributeMarker($path);
                    $element->setAttribute('class', $this->mergeClassNames($this->attr($element, 'class'), $marker));
                }
            }
		}
    }

	/** @param list<array{selector:string,parsed:array<string,mixed>}> $authorSelectors */
    private function discoverAuthorRootChildPaths(array $authorSelectors): void
    {
		foreach ( $authorSelectors as $authorSelector ) {
				$selector = $authorSelector['selector'];
				$parsed = $authorSelector['parsed'];
                if ( ! $parsed['supported'] || ! $this->isRootChildSelector($parsed) ) {
                    continue;
                }
                foreach ( $this->matchingAuthorSourceElements($selector, $parsed) as $element ) {
                    if ( in_array(strtolower($element->tagName), array( 'link', 'meta', 'script', 'style', 'template', 'title' ), true) ) {
                        continue;
                    }
                    $path = $this->sourceElementIdentity($element);
                    if ( '' !== $path ) {
                        $this->authorSelectorProjections()->ensureRootChildMarker($path);
                    }
                }
		}
    }

	/** @param list<array{selector:string,parsed:array<string,mixed>}> $authorSelectors */
    private function discoverAuthorTablePaths(array $authorSelectors): void
    {
		foreach ( $authorSelectors as $authorSelector ) {
				$selector = $authorSelector['selector'];
				$parsed = $authorSelector['parsed'];
                if ( ! $parsed['supported'] ) {
                    continue;
                }
                foreach ( $this->matchingAuthorSourceElements($selector, $parsed) as $element ) {
                    if ( ! in_array(strtolower($element->tagName), array( 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th' ), true) ) {
                        continue;
                    }
                    if ( ! $this->tableSelectorNeedsStructuralProjection($parsed, $element) ) {
                        continue;
                    }
                    $table = $this->ancestorTable($element);
                    if ( ! $table instanceof DOMElement || ! $this->isRepresentableTable($table) ) {
                        continue;
                    }
                    $path = $this->sourceElementIdentity($table);
                    if ( '' !== $path ) {
                        $this->authorSelectorProjections()->ensureTableMarker($path);
                    }
                }
		}
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
        foreach ( StyleTagScanner::scan($html) as $style ) {
            $styleBlock = trim(html_entity_decode($style['content'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ( '' !== $styleBlock ) {
                $cssParts[] = $styleBlock;
            }
        }
        $staticCss = trim($staticCss);
        if ( '' !== $staticCss ) {
            $cssParts[] = $staticCss;
        }
        return trim(implode("\n\n", $cssParts));
    }

    /** @return list<string> */
    private function stylesheetPayloads(string $html, string $staticCss, array $options): array
    {
        $staticPayloads = $this->staticStylesheetPayloads($staticCss, $options);
        $inlinePayloads = $this->inlineStylesheetPayloads($html);
        $payloads = array_merge($staticPayloads, $inlinePayloads);
        if ( ! $this->hasSafeStylesheetBoundaries($payloads) ) {
            // Preserve the legacy parser's recovery across a concatenated stream.
            $combined = trim($staticCss . ('' === trim($staticCss) || array() === $inlinePayloads ? '' : "\n") . implode("\n", $inlinePayloads));
            return '' === $combined ? array() : array($combined);
        }

        return array_values(array_filter(array_map('trim', $payloads), static fn (string $payload): bool => '' !== $payload));
    }

    /** @param array<string, mixed> $options @return list<string> */
    private function staticStylesheetPayloads(string $staticCss, array $options): array
    {
        if ( ! is_array($options['stylesheet_payloads'] ?? null) ) {
            return array($staticCss);
        }
        $payloads = array();
        foreach ( $options['stylesheet_payloads'] as $payload ) {
            if ( is_array($payload) && is_string($payload['content'] ?? null) ) {
                $payloads[] = $payload['content'];
            }
        }

        return array() === $payloads ? array($staticCss) : $payloads;
    }

    /** @return list<array{content: string, source_path: string, source_hash: string}> */
    private function authorStylesheetPayloads(string $html, string $staticCss): array
    {
        if ( array() !== $this->authorStyles()->stylesheetAssets() ) {
            $payloads = array_values(array_filter($this->authorStyles()->stylesheetAssets(), static fn (array $asset): bool => '' !== trim($asset['content'])));
            if ( $this->hasSafeStylesheetBoundaries(array_column($payloads, 'content')) ) {
                return array_map(static fn (array $asset): array => array('content' => $asset['content'], 'source_path' => $asset['source_path'], 'source_hash' => $asset['source_hash']), $payloads);
            }
            $content = implode("\n\n", array_column($payloads, 'content'));
            return array(array('content' => $content, 'source_path' => 'combined-stylesheets', 'source_hash' => hash('sha256', $content)));
        }

        $payloads = array();
        // This order is intentionally distinct from presentation analysis: it
        // matches combinedAuthorStylesheet(), which emits inline CSS first.
        foreach ( array_merge($this->inlineStylesheetPayloads($html), array($staticCss)) as $payload ) {
            $payload = trim(html_entity_decode($payload, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ( '' !== $payload ) {
                $payloads[] = $payload;
            }
        }

        if ( $this->hasSafeStylesheetBoundaries($payloads) ) {
            return array_map(static fn (string $content): array => array('content' => $content, 'source_path' => 'inline-style', 'source_hash' => hash('sha256', $content)), $payloads);
        }
        $content = implode("\n\n", $payloads);
        return array(array('content' => $content, 'source_path' => 'inline-style', 'source_hash' => hash('sha256', $content)));
    }

    /** @return list<string> */
    private function inlineStylesheetPayloads(string $html): array
    {
        return array_map(static fn (array $style): string => trim($style['content']), StyleTagScanner::scan($html));
    }

    /** @param list<string> $payloads */
    private function hasSafeStylesheetBoundaries(array $payloads): bool
    {
        foreach ( $payloads as $payload ) {
            $depth = 0;
            $quote = '';
            $comment = false;
            for ( $index = 0, $length = strlen($payload); $index < $length; ++$index ) {
                $character = $payload[$index];
                $next = $index + 1 < $length ? $payload[$index + 1] : '';
                if ( $comment ) {
                    if ( '*' === $character && '/' === $next ) {
                        $comment = false;
                        ++$index;
                    }
                    continue;
                }
                if ( '' !== $quote ) {
                    if ( '\\' === $character ) {
                        ++$index;
                    } elseif ( $quote === $character ) {
                        $quote = '';
                    }
                    continue;
                }
                if ( '/' === $character && '*' === $next ) {
                    $comment = true;
                    ++$index;
                } elseif ( '"' === $character || "'" === $character ) {
                    $quote = $character;
                } elseif ( '{' === $character ) {
                    ++$depth;
                } elseif ( '}' === $character ) {
                    --$depth;
                    if ( $depth < 0 ) {
                        return false;
                    }
                }
            }
            if ( $comment || '' !== $quote || 0 !== $depth ) {
                return false;
            }
        }

        return true;
    }

    /** @return array{static: array, conditional: array, navigation_state: array, image_shape: array, pseudo: array, custom_properties: array} */
    private function composedStyleAnalysis(array $payloads): array
    {
        $composed = array('static' => array(), 'conditional' => array(), 'navigation_state' => array(), 'image_shape' => array(), 'pseudo' => array(), 'custom_properties' => array('root' => array(), 'fallback' => array()));
        foreach ( $payloads as $payload ) {
            $key = hash('sha256', $payload);
            $analysis = $this->analysisCache->style($key);
            if ( null === $analysis ) {
                ++$this->analysisCache->styleBuilds;
                $ruleCss = trim($payload);
                $ruleCss = preg_replace('@/\*.*?\*/@s', '', $ruleCss) ?? $ruleCss;
                $topLevel = $this->styleResolver->topLevelStyleAnalysis($ruleCss);
                $structured = $this->styleResolver->structuredStyleAnalysis($ruleCss);
                $analysis = array(
                    'static' => $topLevel['static'],
                    'conditional' => $structured['conditional'],
                    'navigation_state' => $topLevel['navigation_state'],
                    'image_shape' => $structured['image_shape'],
                    'pseudo' => $topLevel['pseudo'],
                    'custom_properties' => $this->cssCustomPropertyAnalysis($payload),
                );
                $this->analysisCache->rememberStyle($key, $analysis);
            } else {
                ++$this->analysisCache->styleHits;
            }
            foreach ( array('static', 'conditional', 'navigation_state', 'pseudo') as $part ) {
                $composed[$part] = array_merge($composed[$part], $analysis[$part]);
            }
            foreach ( $analysis['image_shape'] as $rule ) {
                $rule['order'] = count($composed['image_shape']);
                $composed['image_shape'][] = $rule;
            }
            $composed['custom_properties']['root'] = array_merge($composed['custom_properties']['root'], $analysis['custom_properties']['root']);
            $composed['custom_properties']['fallback'] = array_merge($composed['custom_properties']['fallback'], $analysis['custom_properties']['fallback']);
        }

        $composed['custom_properties'] = array() !== $composed['custom_properties']['root']
            ? $composed['custom_properties']['root']
            : $composed['custom_properties']['fallback'];

        return $composed;
    }

    /** @return array{root: array<string, string>, fallback: array<string, string>} */
    private function cssCustomPropertyAnalysis(string $css): array
    {
        $root = array();
        (new CssStylesheetTransformer())->transform($css, static function (string $prelude, string $body) use (&$root): string {
            $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
            if ( null === $selectors || ! array_filter($selectors, static function (string $selector): bool {
                $selector = preg_replace('/\/\*.*?\*\//s', '', $selector) ?? $selector;
                return in_array(strtolower(trim($selector)), array(':root', 'html'), true);
            }) ) {
                return $prelude;
            }
            if ( preg_match_all('/(--[A-Za-z0-9_-]+)\s*:\s*([^;{}]+)/', $body, $matches, PREG_SET_ORDER) ) {
                foreach ( $matches as $match ) {
                    $root[(string) $match[1]] = trim((string) $match[2]);
                }
            }

            return $prelude;
        });
        $fallback = array();
        if ( preg_match_all('/(--[A-Za-z0-9_-]+)\s*:\s*([^;{}]+)/', $css, $matches, PREG_SET_ORDER) ) {
            foreach ( $matches as $match ) {
                $fallback[(string) $match[1]] = trim((string) $match[2]);
            }
        }

        return array('root' => $root, 'fallback' => $fallback);
    }

    /** @return array{source_tags: array<string, bool>, selectors: list<array{selector: string, parsed: array<string, mixed>}>, rules: list<array<string, mixed>>} */
    private function composedAuthorSelectorAnalysis(array $payloads): array
    {
        $composed = array('source_tags' => array(), 'selectors' => array(), 'rules' => array());
        foreach ( $payloads as $payload ) {
            $key = hash('sha256', $payload['content']);
            $analysis = $this->analysisCache->authorSelectors($key);
            if ( null === $analysis ) {
                ++$this->analysisCache->authorSelectorBuilds;
                $analysis = $this->authorSelectorAnalysis($payload['content']);
                ++$this->analysisCache->authorStyleRuleBuilds;
                $this->analysisCache->rememberAuthorSelectors($key, $analysis);
            } else {
                ++$this->analysisCache->authorSelectorHits;
            }
            $composed['source_tags'] += $analysis['source_tags'];
            $composed['selectors'] = array_merge($composed['selectors'], $analysis['selectors']);
            foreach ( $analysis['rules'] as $rule ) {
                $rule['order'] = count($composed['rules']);
                $rule['source_path'] = $payload['source_path'];
                $rule['source_hash'] = $payload['source_hash'];
                $composed['rules'][] = $rule;
            }
        }

        return $composed;
    }

    /** @return array{source_tags: array<string, bool>, selectors: list<array{selector: string, parsed: array<string, mixed>}>, rules: list<array<string, mixed>>} */
    private function authorSelectorAnalysis(string $css): array
    {
        $sourceTags = array();
        $selectors = array();
        $rules = array();
        (new CssStylesheetTransformer())->transform($css, function (string $prelude, string $body) use (&$sourceTags, &$selectors, &$rules): string {
            $ruleSelectors = array();
            foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
                $parsed = $this->parsedCssSelector($selector);
                $selectors[] = array('selector' => $selector, 'parsed' => $parsed);
                $directSelector = preg_replace('/::[a-z-]+(?:\([^)]*\))?$/i', '', trim($selector)) ?? $selector;
                $ruleSelectors[] = array('selector' => $selector, 'parsed' => $parsed, 'direct_child_parsed' => $this->parsedCssSelector($directSelector));
                foreach ( $parsed['type_spans'] ?? array() as $typeSpan ) {
                    $tagName = strtolower($typeSpan['name']);
                    if ( in_array($tagName, array('div', 'li', 'nav', 'p'), true) ) {
                        $sourceTags[$tagName] = true;
                    }
                }
            }
            if ( array() !== $ruleSelectors ) {
                $rules[] = array('order' => count($rules), 'declarations' => $this->styleResolver->cssDeclarations($body), 'selectors' => $ruleSelectors);
            }

            return $prelude;
        });

        return array('source_tags' => $sourceTags, 'selectors' => $selectors, 'rules' => $rules);
    }

    /** @param array<string, mixed> $options @return list<array{path: string, source_path: string, content: string, source_hash: string, media: string}> */
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
            $assets[] = array( 'path' => $asset['path'], 'source_path' => is_string($asset['source_path'] ?? null) ? $asset['source_path'] : $asset['path'], 'content' => $asset['content'], 'source_hash' => is_string($asset['source_hash'] ?? null) ? $asset['source_hash'] : hash('sha256', $asset['content']), 'media' => is_string($asset['media'] ?? null) ? $asset['media'] : '' );
        }
        return $assets;
    }

    /** @return list<array{path: string, content: string, bytes: int, hash: string, source_hash: string}> */
    private function authorStylesheetProjections(): array
    {
        $projections = array();
        foreach ( $this->authorStyles()->stylesheetAssets() as $asset ) {
            $content = $this->rewriteAuthorStylesheet($asset['content']);
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
        return $this->authorStyles()->allocateMarker($kind);
    }

    private function rewriteAuthorStylesheet(string $stylesheet): string
    {
        return ( new CssStylesheetTransformer() )->transformStyleRules($stylesheet, function (string $prelude, string $body): string {
            $body = $this->projectResponsiveCanvasMinimumWidth($prelude, $body);
            $declarations = $this->styleResolver->cssDeclarations($body);
            $margins = array_filter($declarations, static fn (string $name): bool => 'margin' === $name || str_starts_with($name, 'margin-'), ARRAY_FILTER_USE_KEY);
            $imagePrelude = $this->projectAuthorImageSelectorPrelude($prelude);
            $svgImagePrelude = $this->projectAuthorImageSelectorPrelude($prelude, 'svg', $declarations);
            $imageRule = '' === $imagePrelude
                ? ''
                : $imagePrelude . '{' . $this->imageProjectionBridgeDeclarations($declarations) . '}';
            $svgImageRule = '' === $svgImagePrelude
                ? ''
                : $svgImagePrelude . '{' . $this->imageProjectionBridgeDeclarations($declarations, true) . '}';
            if ( array() === $margins ) {
                return $this->rewriteAuthorStyleRule($prelude, $body) . $imageRule . $svgImageRule;
            }

            $inner = array_diff_key($declarations, $margins);
            $rules = '' === $this->styleResolver->cssDeclarationString($inner)
                ? ''
                : $this->rewriteAuthorStyleRule($prelude, $this->styleResolver->cssDeclarationString($inner));
            return $rules . $this->rewriteAuthorSelectorPrelude($prelude, true) . '{' . $this->styleResolver->cssDeclarationString($margins) . '}' . $imageRule . $svgImageRule;
        });
    }

    /**
     * Captured builders commonly impose a desktop canvas minimum on a document
     * root and its immediate section strips. That is runtime viewport scaffolding,
     * not an authored content constraint: retaining it forces a desktop-wide
     * WordPress document on narrow viewports. Only project broad absolute values
     * when every matched source element is a structural shell or section surface.
     */
    private function projectResponsiveCanvasMinimumWidth(string $prelude, string $body): string
    {
        $declarations = $this->styleResolver->cssDeclarations($body);
        $minimumWidth = (string) ($declarations['min-width'] ?? '');
        if ( ! $this->isWideAbsoluteMinimumWidth($minimumWidth) ) {
            return $body;
        }

        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors ) {
            return $body;
        }

        $matchedSurface = false;
        foreach ( $selectors as $selector ) {
            $parsed = $this->parsedCssSelector($selector);
            if ( ! $parsed['supported'] ) {
                return $body;
            }
            $matches = $this->matchingAuthorSourceElements($selector, $parsed);
            if ( array() === $matches ) {
                continue;
            }
            $matchedSurface = true;
            $shellMatches = array_filter($matches, fn (DOMElement $element): bool => $this->isPageShellOrSectionSurface($element));
            if ( count($shellMatches) !== count($matches) ) {
                if ( array() !== $shellMatches ) {
                    $this->transformationEvidence()->recordResponsiveGeometryAmbiguity($selector, $minimumWidth);
                }
                return $body;
            }
        }

        if ( ! $matchedSurface ) {
            return $body;
        }

        $important = $this->cssValueIsImportant($minimumWidth) ? '!important' : '';
        $retained = array();
        foreach ( CssValueSplitter::splitTopLevel($body, array( ';' )) as $declaration ) {
            if ( 'min-width' !== strtolower(trim(strtok($declaration, ':'))) ) {
                $retained[] = $declaration;
            }
        }
        $retained[] = 'min-width:0' . $important;
        $retained[] = 'max-width:100%' . $important;
        return implode(';', $retained);
    }

    private function isWideAbsoluteMinimumWidth(string $value): bool
    {
        $value = $this->cssValueWithoutImportant($value);
        if ( 1 !== preg_match('/^(\d+(?:\.\d+)?)\s*(px|r?em)$/i', $value, $matches) ) {
            return false;
        }
        $pixels = (float) $matches[1];
        if ( 'px' !== strtolower($matches[2]) ) {
            $pixels *= self::ROOT_FONT_SIZE_PX;
        }
        return $pixels >= 640;
    }

    private function isPageShellOrSectionSurface(DOMElement $element): bool
    {
        if ( $element->parentNode === $this->authorStyles()->sourceBody() ) {
            return true;
        }

        if ( in_array(strtolower($element->tagName), array( 'header', 'main', 'footer', 'section' ), true) ) {
            return true;
        }

        $parent = $element->parentNode;
        return $parent instanceof DOMElement
            && $parent->parentNode === $this->authorStyles()->sourceBody()
            && $this->elementChildCount($parent) > 1;
    }

    private function elementChildCount(DOMElement $element): int
    {
        $count = 0;
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                ++$count;
            }
        }
        return $count;
    }

    private function rewriteAuthorStyleRule(string $prelude, string $body): string
    {
        $projectedPrelude = $this->rewriteAuthorSelectorPrelude($prelude);
        $wrapperPrelude = $this->buttonPresentationWrapperPrelude($prelude);
        if ( '' === $wrapperPrelude ) {
            $directWrapperPrelude = $this->directButtonGeometryWrapperPrelude($prelude);
            [ $geometry, $inner ] = $this->splitDirectButtonGeometryDeclarations($body);
            if ( '' === $directWrapperPrelude || '' === $geometry ) {
                return $projectedPrelude . '{' . $body . '}';
            }
            return $this->withButtonWrapperInnerFill($directWrapperPrelude, $geometry, '' === $inner ? '' : $projectedPrelude . '{' . $inner . '}');
        }

        [ $layout, $control ] = $this->splitButtonPresentationDeclarations($body);
        if ( '' === $layout ) {
            return $projectedPrelude . '{' . $body . '}';
        }
        if ( '' === $control ) {
            return $this->withButtonWrapperInnerFill($wrapperPrelude, $body);
        }

        return $this->withButtonWrapperInnerFill($wrapperPrelude, $layout, $projectedPrelude . '{' . $control . '}');
    }

    private function buttonPresentationWrapperPrelude(string $prelude): string
    {
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors ) {
            return '';
        }

        $rewritten = array();
        foreach ( $selectors as $selector ) {
            $selector = $this->projectSourceBodyStateSelector($selector);
            $parsed = $this->parsedCssSelector($selector);
            if ( ! $parsed['supported'] || null !== $parsed['pseudo_state_suffix_span'] ) {
                continue;
            }
            $matches = $this->matchingAuthorSourceElements($selector, $parsed);
            if ( array() === $matches ) {
                continue;
            }
            $markers = array();
            foreach ( $matches as $element ) {
                $path = $element->getNodePath() ?? '';
                $marker = $this->authorSelectorProjections()->isButtonPresentationPath($path)
                    ? $this->authorSelectorProjections()->controlMarker($path)
                    : '';
                if ( '' === $marker ) {
                    continue 2;
                }
                $markers[] = $marker;
            }
            foreach ( array_unique($markers) as $marker ) {
                $rewritten[] = ':where(.' . $marker . ')' . $this->selectorSpecificityShims($parsed);
            }
        }

        return implode(',', $rewritten);
    }

    private function directButtonGeometryWrapperPrelude(string $prelude): string
    {
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors ) {
            return '';
        }

        $rewritten = array();
        foreach ( $selectors as $selector ) {
            $selector = $this->projectSourceBodyStateSelector($selector);
            $parsed = $this->parsedCssSelector($selector);
            if ( ! $parsed['supported'] || null !== $parsed['pseudo_state_suffix_span'] ) {
                continue;
            }
            $matches = $this->matchingAuthorSourceElements($selector, $parsed);
            if ( array() === $matches ) {
                continue;
            }
            foreach ( $matches as $element ) {
                $path = $element->getNodePath() ?? '';
                $marker = $this->authorSelectorProjections()->controlMarker($path);
                if ( '' === $marker || $this->authorSelectorProjections()->isButtonPresentationPath($path) ) {
                    continue 2;
                }
                $rewritten[] = $this->projectControlSelector($selector, $parsed, $marker, true);
            }
        }

        return implode(',', array_values(array_unique($rewritten)));
    }

    /** @return array{string, string} */
    private function splitDirectButtonGeometryDeclarations(string $body): array
    {
        $geometry = array();
        $inner = array();
        foreach ( CssValueSplitter::splitTopLevel($body, array( ';' )) as $declaration ) {
            $colon = strpos($declaration, ':');
            $name = strtolower(trim(false === $colon ? $declaration : substr($declaration, 0, $colon)));
            $value = false === $colon ? '' : trim(substr($declaration, $colon + 1));
            // The buttons wrapper is the source control's box in the parent
            // layout: it is the grid/flex item. Placement and self-alignment
            // belong there, not on the inner control, which would leave the
            // wrapper unplaced and push it into normal flow.
            $wrapperOwned = array(
                'position', 'top', 'right', 'bottom', 'left', 'z-index',
                'width', 'min-width', 'max-width', 'height', 'min-height', 'max-height',
                'grid-area', 'grid-column', 'grid-row',
                'grid-column-start', 'grid-column-end', 'grid-row-start', 'grid-row-end',
                'align-self', 'justify-self', 'order',
            );
            if ( '' !== $name && false !== $colon && in_array($name, $wrapperOwned, true)
                && ! $this->isButtonControlBoxSize($name, $value)
            ) {
                $geometry[] = $declaration;
            } else {
                $inner[] = $declaration;
            }
        }
        return array( implode(';', $geometry), implode(';', $inner) );
    }

    private function projectSourceBodyStateSelector(string $selector): string
    {
        if ( array() === $this->authorStyles()->sourceBodyProjectionClasses() ) {
            return $selector;
        }

        $classes = implode('|', array_map(static fn (string $class): string => preg_quote($class, '/'), $this->authorStyles()->sourceBodyProjectionClasses()));
        return preg_replace('/^\s*body(?=\.(?:' . $classes . ')(?:\b|[.#:\[]))/', '', $selector, 1) ?? $selector;
    }

    /** @return array{string, string} */
    private function splitButtonPresentationDeclarations(string $body): array
    {
        $layout = array();
        $control = array();
        foreach ( CssValueSplitter::splitTopLevel($body, array( ';' )) as $declaration ) {
            $colon = strpos($declaration, ':');
            $name = strtolower(trim(false === $colon ? $declaration : substr($declaration, 0, $colon)));
            $value = false === $colon ? '' : trim(substr($declaration, $colon + 1));
            if ( '' === $name || false === $colon ) {
                $control[] = $declaration;
                continue;
            }
            if ( $this->isButtonWrapperLayoutProperty($name) && ! $this->isButtonControlBoxSize($name, $value) ) {
                $layout[] = $declaration;
            } else {
                $control[] = $declaration;
            }
        }

        return array( implode(';', $layout), implode(';', $control) );
    }

    /**
     * When the buttons wrapper carries a definite width, the inner Gutenberg
     * button and link must fill it. Otherwise they shrink-wrap and wrap text
     * into a one-character column.
     */
    private function withButtonWrapperInnerFill(string $wrapperPrelude, string $layoutCss, string $rest = ''): string
    {
        $css = $wrapperPrelude . '{' . $layoutCss . '}';
        if ( $this->cssHasDefiniteWidth($layoutCss) ) {
            $selectors = CssStylesheetTransformer::splitSelectorList($wrapperPrelude) ?? array( $wrapperPrelude );
            $button = implode(',', array_map(static fn (string $selector): string => rtrim($selector) . '> :where(.wp-block-button)', $selectors));
            $link = implode(',', array_map(static fn (string $selector): string => rtrim($selector) . '> :where(.wp-block-button)> :where(.wp-block-button__link)', $selectors));
            // Width only. `box-sizing` would move the border box of source
            // controls that already fill their wrapper.
            $css .= $button . '{width:100%!important}'
                . $link . '{width:100%!important;max-width:100%!important}';
        }

        return $css . $rest;
    }

    private function cssHasDefiniteWidth(string $css): bool
    {
        foreach ( CssValueSplitter::splitTopLevel($css, array( ';' )) as $declaration ) {
            $colon = strpos($declaration, ':');
            if ( false === $colon ) {
                continue;
            }
            $name = strtolower(trim(substr($declaration, 0, $colon)));
            if ( 'width' !== $name && 'min-width' !== $name ) {
                continue;
            }
            $value = strtolower(trim((string) preg_replace('/\s*!\s*important\s*$/i', '', trim(substr($declaration, $colon + 1)))));
            if ( '' === $value || in_array($value, array( 'auto', 'inherit', 'initial', 'unset', 'none', 'min-content', 'max-content', 'fit-content', 'content' ), true) ) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Keyword sizes describe the control's box. On `.wp-block-buttons` they
     * shrink-wrap the flex wrapper and override a definite source width.
     */
    private function isButtonControlBoxSize(string $property, string $value): bool
    {
        if ( ! in_array($property, array( 'width', 'min-width', 'max-width', 'height', 'min-height', 'max-height' ), true) ) {
            return false;
        }

        $value = strtolower(trim((string) preg_replace('/\s*!\s*important\s*$/i', '', $value)));

        return in_array($value, array( 'min-content', 'max-content', 'fit-content', 'content' ), true);
    }

    private function isButtonWrapperLayoutProperty(string $property): bool
    {
        return in_array($property, array(
            'align-content', 'align-items', 'align-self', 'clear', 'display', 'float',
            'flex', 'flex-basis', 'flex-direction', 'flex-flow', 'flex-grow', 'flex-shrink',
            'flex-wrap', 'gap', 'grid', 'grid-area', 'grid-auto-columns', 'grid-auto-flow',
            'grid-auto-rows', 'grid-column', 'grid-row', 'grid-template', 'grid-template-areas',
            'grid-template-columns', 'grid-template-rows', 'isolation', 'justify-content',
            'justify-items', 'justify-self', 'order', 'overflow', 'overflow-x', 'overflow-y',
            'place-content', 'place-items', 'place-self', 'position', 'top', 'right', 'bottom',
            'left', 'z-index', 'width', 'min-width', 'max-width', 'height', 'min-height', 'max-height',
        ), true);
    }

    private function rewriteAuthorSelectorPrelude(string $prelude, bool $controlWrapper = false): string
    {
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors ) {
            return $prelude;
        }

        $rewritten = array();
        foreach ( $selectors as $selector ) {
            $selector = $this->projectSourceAttributeNegationStateSelector($selector);
            $selector = $this->projectSourceBodyStateSelector($selector);
            $parsed = $this->parsedCssSelector($selector);
            if ( ! $parsed['supported'] ) {
                $rewritten[] = $selector;
                continue;
            }
            $matches = $this->matchingAuthorSourceElements($selector, $parsed);
            if ( array() === $matches ) {
                // A type selector (e.g. `.page-header p`) that matches no source
                // element must still be projected through its source-tag marker
                // rather than emitted bare. Otherwise a `<div>` later collapsed to a
                // `<p>` (an eyebrow `<div class="label">`) would be newly captured by
                // the dormant `.page-header p` rule and lose its own type scale.
                // Rewriting to `:where(.source-p-marker)` — carried only by elements
                // that were `<p>` in the source — makes the rule match exactly what
                // the author intended and nothing that was structurally promoted.
                $rewritten[] = $this->rewriteSourceTagTypes($selector, $parsed);
                continue;
            }
            $attributeAncestryProjection = $this->projectSourceAttributeAncestrySelector($selector, $parsed, $matches);
            if ( null !== $attributeAncestryProjection ) {
                array_push($rewritten, ...$attributeAncestryProjection);
                continue;
            }
            $attributeProjection = $this->projectSourceAttributeSelector($parsed, $matches);
            if ( null !== $attributeProjection ) {
                array_push($rewritten, ...$attributeProjection);
                continue;
            }
            if ( $this->isRootChildSelector($parsed) ) {
                $shellTags = array_values(array_unique(array_filter(array_map(
                    function (DOMElement $element): string {
                        if ( $element->parentNode !== $this->authorStyles()->sourceBody() ) {
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
                            : $this->authorSelectorProjections()->rootChildMarker($this->sourceElementIdentity($element));
                    },
                    $matches
                ))));
                if ( array() === $markers && array() === $shellTags ) {
                    $rewritten[] = $selector;
                    continue;
                }
                foreach ( $markers as $marker ) {
                    $rewritten[] = $this->projectSemanticLeafSelector($selector, $parsed, $marker);
                }
                foreach ( $shellTags as $tag ) {
                    $rewritten[] = ':where(' . $tag . '.wp-block-template-part)' . $this->selectorSpecificityShims($parsed);
                }
                continue;
            }

            $tableDescendants = array();
            $nonTableMatches = array();
            foreach ( $matches as $element ) {
                $projected = $this->projectTableDescendantSelector($selector, $parsed, $element);
                if ( null === $projected ) {
                    $nonTableMatches[] = $element;
                } else {
                    $tableDescendants[] = $projected;
                }
            }
            foreach ( array_values(array_unique($tableDescendants)) as $projected ) {
                $rewritten[] = $projected;
            }
            if ( array() === $nonTableMatches ) {
                continue;
            }
            $matches = $nonTableMatches;

            $controls = array();
            $semanticLeaves = array();
            $richTextLeaves = array();
            $inlineLayoutCarriers = false;
            $hasNonProjected = false;
            foreach ( $matches as $element ) {
                $path = $element->getNodePath() ?? '';
                if ( $this->requiresStandaloneInlineLayoutLeaf($element) && ! $this->isDirectChildOfLoweredAuthorControl($element) ) {
                    $inlineLayoutCarriers = true;
                } elseif ( '' !== ($marker = $this->authorSelectorProjections()->controlMarker($path)) ) {
                    $controls[] = $marker;
                } elseif ( '' !== ($marker = $this->authorSelectorProjections()->semanticMarker($this->sourceElementIdentity($element))) ) {
                    $semanticLeaves[] = $marker;
                } elseif ( '' !== ($marker = $this->authorSelectorProjections()->richTextMarker($this->sourceElementIdentity($element))) ) {
                    $richTextLeaves[] = $marker;
                } else {
                    $hasNonProjected = true;
                }
            }
            $controls = array_values(array_unique($controls));
            $semanticLeaves = array_values(array_unique($semanticLeaves));
            $richTextLeaves = array_values(array_unique($richTextLeaves));
            if ( array() === $controls && array() === $semanticLeaves && array() === $richTextLeaves && empty($inlineLayoutCarriers) ) {
                $rewritten[] = $this->rewriteSourceTagTypes($selector, $parsed);
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
            if ( ! empty($inlineLayoutCarriers) ) {
                $rewritten[] = $this->projectInlineLayoutCarrierSelector($selector, $parsed);
            }
        }
        return implode(',', $rewritten);
    }

    /** @param array<string, mixed> $parsed @param array<int, DOMElement> $matches @return array<int, string>|null */
    private function projectSourceAttributeAncestrySelector(string $selector, array $parsed, array $matches): ?array
    {
        $rightmost = $parsed['rightmost_compound_span'] ?? null;
        $ancestry = is_array($rightmost) ? substr($selector, 0, (int) $rightmost['start']) : '';
        if ( null !== $parsed['pseudo_state_suffix_span'] || ! preg_match('/\[\s*data-[a-z0-9_-]+(?:\s*[~|^$*]?=|\s*\])/i', $ancestry) ) {
            return null;
        }

        $projected = array();
        $scope = '';
        if ( preg_match('/^\s*((?::where\([^(),]+\)\s+)+)/i', $selector, $scopeMatch) ) {
            $scope = trim((string) $scopeMatch[1]) . ' ';
        }
        foreach ( $matches as $element ) {
            $id = trim($this->attr($element, 'id'));
            if ( ! preg_match('/^[a-z_][a-z0-9_-]*$/i', $id) ) {
                return null;
            }
            $projected[] = $scope . ':where(#' . $id . ')' . $this->selectorSpecificityShims($parsed);
        }

        return array_values(array_unique($projected));
    }

    /** @param array<string, mixed> $parsed @param array<int, DOMElement> $matches @return array<int, string>|null */
    private function projectSourceAttributeSelector(array $parsed, array $matches): ?array
    {
        if ( null !== $parsed['pseudo_state_suffix_span'] ) {
            return null;
        }
        $compounds = $parsed['compounds'] ?? array();
        $rightmost = $compounds[array_key_last($compounds)] ?? array();
        $hasDataAttribute = array_filter($rightmost['attributes'] ?? array(), static fn (array $attribute): bool => str_starts_with($attribute['name'] ?? '', 'data-'));
        if ( array() === $hasDataAttribute ) {
            return null;
        }

        $projected = array();
        foreach ( $matches as $element ) {
            $marker = $this->authorSelectorProjections()->attributeMarker($this->sourceElementIdentity($element));
            if ( '' === $marker ) {
                return null;
            }
            $projected[] = ':where(.' . $marker . ')' . $this->selectorSpecificityShims($parsed);
        }

        return array_values(array_unique($projected));
    }

    private function projectSourceAttributeNegationStateSelector(string $selector): string
    {
        $marker = $this->authorSelectorProjections()->attributeNegationMarker(trim($selector));
        if ( '' === $marker ) {
            return $selector;
        }
        return preg_replace(
            '/:not\(\s*\[\s*data-[a-z0-9_-]+(?:\s*[~|^$*]?=\s*(?:"[^"]*"|\'[^\']*\'|[^\]\s]+))?\s*\]\s*\)/i',
            ':not(.' . $marker . ')',
            $selector
        ) ?? $selector;
    }

    private function projectAuthorImageSelectorPrelude(string $prelude, string $tagName = 'img', array $declarations = array()): string
    {
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors ) {
            return '';
        }

        $projected = array();
        foreach ( $selectors as $selector ) {
            $parsed = $this->parsedCssSelector($selector);
            if ( ! $parsed['supported'] ) {
                continue;
            }
            $matches = $this->matchingAuthorSourceElements($selector, $parsed);
            $imageMatches = array_values(array_filter($matches, fn (DOMElement $element): bool => $tagName === strtolower($element->tagName) && ('svg' !== $tagName || $this->isProjectableFillSvg($element, $declarations))));
            if ( array() === $imageMatches ) {
                continue;
            }

            if ( $this->isRootChildSelector($parsed) ) {
                foreach ( $imageMatches as $element ) {
                    $marker = $this->authorSelectorProjections()->rootChildMarker($this->sourceElementIdentity($element));
                    if ( '' !== $marker ) {
                        $projected[] = $this->projectSemanticLeafSelector($selector, $parsed, $marker) . '.wp-block-image > img';
                    }
                }
                continue;
            }

            if ( 'svg' === $tagName ) {
                $projected[] = $this->projectImageSelector($selector, $parsed, true);
            }
            $projected[] = $this->projectImageSelector($selector, $parsed);
        }

        return implode(',', array_values(array_unique($projected)));
    }

    /** @param array<string, string> $declarations */
    private function isExplicitParentFillSvg(DOMElement $element, array $declarations): bool
    {
        if ( '' === $this->explicitObjectFit($declarations)
            || '100%' !== trim((string) ($declarations['width'] ?? ''))
            || '100%' !== trim((string) ($declarations['height'] ?? ''))
        ) {
            return false;
        }
        $parent = $element->parentNode;
        if ( ! $parent instanceof DOMElement ) {
            return false;
        }
        $parentStyle = $this->styleResolver->structuralPresentationDeclarations($parent);
        if ( ! in_array(strtolower(trim((string) ($parentStyle['position'] ?? ''))), array( 'absolute', 'fixed' ), true) ) {
            return false;
        }
        return isset($parentStyle['inset']) && '' !== trim((string) $parentStyle['inset']);
    }

    /** @param array<string, string> $declarations */
    private function isProjectableFillSvg(DOMElement $element, array $declarations): bool
    {
        if ( $this->isExplicitParentFillSvg($element, $declarations) ) {
            return true;
        }
        if ( '100%' !== trim((string) ($declarations['width'] ?? ''))
            || '100%' !== trim((string) ($declarations['height'] ?? ''))
            || ! preg_match('/(?:^|\s)(?:defer\s+)?x(?:min|mid|max)y(?:min|mid|max)\s+slice(?:\s|$)/i', trim($this->attr($element, 'preserveaspectratio')))
        ) {
            return false;
        }
        return true;
    }

    /**
     * The author's explicit object-fit keyword, or '' when absent or invalid.
     *
     * @param array<string, string> $declarations
     */
    private function explicitObjectFit(array $declarations): string
    {
        $objectFit = strtolower(trim((string) ($declarations['object-fit'] ?? '')));
        return in_array($objectFit, array( 'contain', 'cover', 'fill', 'none', 'scale-down' ), true) ? $objectFit : '';
    }

    /** @param array<string, string> $declarations */
    private function imageProjectionBridgeDeclarations(array $declarations, bool $preserveObjectFit = false): string
    {
        $bridge = array( 'display:block' );
        $position = strtolower(trim((string) ($declarations['position'] ?? '')));
        $width = strtolower(trim((string) ($declarations['width'] ?? '')));
        $height = strtolower(trim((string) ($declarations['height'] ?? '')));
        $ownsBox = ! in_array($width, array( '', 'auto' ), true) && ! in_array($height, array( '', 'auto' ), true);
        if ( $ownsBox || in_array($position, array( 'absolute', 'fixed' ), true) ) {
            $bridge[] = 'width:100%';
            $bridge[] = 'height:100%';
        }
        $bridge[] = 'max-width:100%';
        $objectFit = $this->explicitObjectFit($declarations);
        $bridge[] = 'object-fit:' . ($preserveObjectFit && '' !== $objectFit ? $objectFit : 'inherit');
        $bridge[] = 'object-position:inherit';
        $bridge[] = 'border-radius:inherit';
        return implode(';', $bridge);
    }

    /** @return array<string, mixed> */
    private function parsedCssSelector(string $selector): array
    {
        return $this->sourceStyles()->parsedSelector($selector);
    }

    /** @param array<string, mixed> $parsed @return list<DOMElement> */
    private function matchingAuthorSourceElements(string $selector, array $parsed): array
    {
        if ( $this->authorStyles()->hasSelectorMatches($selector) ) {
            ++$this->analysisCache->authorSelectorMatchResultHits;
            return $this->authorStyles()->selectorMatches($selector);
        }
		++$this->analysisCache->authorSelectorMatchResultBuilds;
		if ( ! $this->authorStyles()->selectorCanMatch($parsed) ) {
			return $this->authorStyles()->rememberSelectorMatches($selector, array());
		}
        $matches = array();
        foreach ( $this->authorStyles()->selectorCandidates($parsed) as $element ) {
            if ( CssSelectorMatcher::matches($element, $parsed, true, $this->authorStyles()->selectorMatchCache())['matches'] ) {
                $matches[] = $element;
            }
        }
        return $this->authorStyles()->rememberSelectorMatches($selector, $matches);
    }

	/** @param array<string, mixed> $parsed @return list<DOMElement> */
	private function matchingAuthorSourceElementsIgnoringNegation(array $parsed): array
	{
		$positive = $parsed;
		foreach ( $positive['compounds'] as $index => $compound ) {
			$positive['compounds'][$index]['not'] = array();
		}
		$matches = array();
		foreach ( $this->authorStyles()->selectorCandidates($positive) as $element ) {
			if ( CssSelectorMatcher::matches($element, $positive, true, $this->authorStyles()->selectorMatchCache())['matches'] ) {
				$matches[] = $element;
			}
		}
		return $matches;
	}

	/** @param array<string, mixed> $parsed */
	private function hasRightmostNegatedDataAttribute(array $parsed): bool
	{
		$compounds = $parsed['compounds'] ?? array();
		$rightmost = $compounds[array_key_last($compounds)] ?? array();
		foreach ( $rightmost['not'] ?? array() as $negated ) {
			foreach ( $negated['attributes'] ?? array() as $attribute ) {
				if ( str_starts_with($attribute['name'] ?? '', 'data-') ) {
					return true;
				}
			}
		}
		return false;
	}

    /** @param array<string, mixed> $parsed */
    private function rewriteSourceTagTypes(string $selector, array $parsed, string $rightmostInsertion = ''): string
    {
        $replacements = array();
        foreach ( $parsed['type_spans'] as $typeSpan ) {
            $marker = $this->authorSelectorProjections()->tagMarker((string) $typeSpan['name']);
            if ( '' !== $marker ) {
                $replacements[$typeSpan['start']] = array( 'end' => $typeSpan['end'], 'value' => ':where(.' . $marker . ')' . $this->typeSpecificityShim() );
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
        return ':where(mark[style*="--blocks-engine-richtext-marker:' . $marker . '"],span[data-blocks-engine-richtext-marker="' . $marker . '"])' . $this->selectorSpecificityShims($parsed) . $suffix;
    }

    /** @param array<string, mixed> $parsed */
    private function projectInlineLayoutCarrierSelector(string $selector, array $parsed): string
    {
        $rightmost = $parsed['rightmost_compound_span'] ?? null;
        if ( ! is_array($rightmost) ) {
            return $selector;
        }

        return substr($selector, 0, (int) $rightmost['start'])
            . 'p.' . self::INLINE_LAYOUT_CARRIER_CLASS . ' > '
            . substr($selector, (int) $rightmost['start']);
    }

    /** @param array<string, mixed> $parsed */
    private function projectTableDescendantSelector(string $selector, array $parsed, DOMElement $element): ?string
    {
        if ( ! in_array(strtolower($element->tagName), array( 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th' ), true) ) {
            return null;
        }
        if ( ! $this->tableSelectorNeedsStructuralProjection($parsed, $element) ) {
            return null;
        }
        $table = $this->ancestorTable($element);
        $marker = $table instanceof DOMElement ? $this->authorSelectorProjections()->tableMarker($this->sourceElementIdentity($table)) : '';
        $path = $table instanceof DOMElement ? $this->serializedTableDescendantPath($table, $element) : '';
        if ( '' === $marker || '' === $path ) {
            return null;
        }

        $suffix = null === $parsed['pseudo_state_suffix_span'] ? '' : substr($selector, $parsed['pseudo_state_suffix_span']['start']);
        // Use the real isolated table marker rather than :where() so the exact
        // cell path beats core's .wp-block-table td/th defaults without a global
        // Gutenberg override.
        return '.' . $marker . '>table>' . $path . $this->selectorSpecificityShims($parsed) . $suffix;
    }

    /** @param array<string, mixed> $parsed */
    private function tableSelectorNeedsStructuralProjection(array $parsed, DOMElement $element): bool
    {
        $classes = array();
        $ids = array();
        $attributes = array();
        foreach ( $parsed['compounds'] ?? array() as $compound ) {
            if ( in_array(strtolower((string) ($compound['type'] ?? '')), array( 'thead', 'tbody', 'tfoot' ), true)
                && ( null !== $compound['nth_child'] || $compound['first_child'] || $compound['last_child'] ) ) {
                return true;
            }
            foreach ( $compound['classes'] ?? array() as $className ) {
                $classes[$className] = true;
            }
            foreach ( $compound['ids'] ?? array() as $id ) {
                $ids[$id] = true;
            }
            foreach ( $compound['attributes'] ?? array() as $attribute ) {
                if ( is_string($attribute['name'] ?? null) && ! in_array($attribute['name'], array( 'class', 'id' ), true) ) {
                    $attributes[$attribute['name']] = true;
                }
            }
        }

        // core/table serializes anonymous cells directly instead of through
        // createBlock(), so scope bare th/td selectors to their native position.
        if ( in_array(strtolower($element->tagName), array( 'td', 'th' ), true)
            && array() === $classes && array() === $ids && array() === $attributes
        ) {
            return true;
        }

        for ( $node = $element; $node instanceof DOMElement && 'table' !== strtolower($node->tagName); $node = $node->parentNode ) {
            $nodeClasses = preg_split('/\s+/', trim($this->attr($node, 'class'))) ?: array();
            if ( array_intersect(array_keys($classes), $nodeClasses) ) {
                return true;
            }
            if ( isset($ids[$this->attr($node, 'id')]) ) {
                return true;
            }
            foreach ( array_keys($attributes) as $attributeName ) {
                if ( $node->hasAttribute($attributeName) ) {
                    return true;
                }
            }
        }
        return false;
    }

    private function serializedTableDescendantPath(DOMElement $table, DOMElement $element): string
    {
        $tableId = spl_object_id($table);
        return $this->authorSelectorProjections()->tableDescendantPath($tableId, spl_object_id($element), function () use ($table): array {
            $paths = array();
            foreach ( array( 'thead', 'tbody', 'tfoot' ) as $section ) {
                $rowIndex = 0;
                foreach ( $table->getElementsByTagName($section) as $sectionElement ) {
                    if ( $sectionElement instanceof DOMElement && $this->belongsToTable($sectionElement, $table) ) {
                        $paths[spl_object_id($sectionElement)] = $section;
                    }
                }
                foreach ( $table->getElementsByTagName('tr') as $row ) {
                    if ( ! $row instanceof DOMElement || ! $this->belongsToTable($row, $table) || $section !== $this->serializedTableSection($row) ) {
                        continue;
                    }
                    ++$rowIndex;
                    $rowPath = $section . '>tr:nth-child(' . $rowIndex . ')';
                    $paths[spl_object_id($row)] = $rowPath;
                    $cellIndex = 0;
                    foreach ( $row->childNodes as $cell ) {
                        if ( ! $cell instanceof DOMElement || ! in_array(strtolower($cell->tagName), array( 'td', 'th' ), true) ) {
                            continue;
                        }
                        ++$cellIndex;
                        $paths[spl_object_id($cell)] = $rowPath . '>' . strtolower($cell->tagName) . ':nth-child(' . $cellIndex . ')';
                    }
                }
            }
            return $paths;
        });
    }

    private function isRepresentableTable(DOMElement $table): bool
    {
        $id = spl_object_id($table);
        return $this->authorSelectorProjections()->tableRepresentable(
            $id,
            fn (): bool => (bool) $this->tableClassificationPolicy->classify($table)['representable']
        );
    }

    /** Convert invalid block wrappers inside a heading into valid RichText breaks. */
    private function headingRichTextContent(string $content): string
    {
        if ( ! preg_match('/<\/?(?:div|p)\b/i', $content) ) return $content;
        $content = preg_replace_callback('/<\s*(\/)?\s*(?:div|p)\b[^>]*>/i', static fn (array $match): string => ! empty($match[1]) ? '<br>' : '', $content) ?? $content;
        return preg_replace('/(?:<br>\s*){2,}/i', '<br>', $content) ?? $content;
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>
     */
    private function nestedLayoutTableColumnsBlock(DOMElement $table, array &$fallbacks): array
    {
        $rows = $table->getElementsByTagName('tr');
        $row = $rows->item(0);
        if ( ! $row instanceof DOMElement ) {
            return $this->htmlPreservationBlock($table);
        }

        $columns = array();
        foreach ( $row->childNodes as $cell ) {
            if ( ! $cell instanceof DOMElement || 'td' !== strtolower($cell->tagName) ) {
                continue;
            }

            $column = $this->createBlock(
                'core/column',
                $this->layoutTableColumnAttributes($cell),
                $this->convertChildren($cell, $fallbacks, true),
                $cell
            );
            // A blank layout-table cell remains a real native column: removing it
            // changes the rendered Columns topology.
            $column['_editability_visual_owned'] = true;
            $columns[] = $column;
        }

        return $this->createBlock('core/columns', $this->styleResolver->presentationAttributes($table), $columns, $table);
    }

    /**
     * @return array<string, mixed>
     */
    private function layoutTableColumnAttributes(DOMElement $cell): array
    {
        $attrs = $this->styleResolver->presentationAttributes($cell);
        $style = strtolower($this->attr($cell, 'style'));
        if ( preg_match('/(?:^|;)\s*width\s*:\s*(\d+(?:\.\d+)?)%/i', $style, $matches) ) {
            $attrs['width'] = $matches[1] . '%';
        }

        return $attrs;
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>
     */
    private function mediaLayoutTableColumnsBlock(DOMElement $table, array &$fallbacks): array
    {
        $rows = array();
        foreach ($table->getElementsByTagName('tr') as $row) {
            if (! $row instanceof DOMElement || ! $this->belongsToTable($row, $table)) {
                continue;
            }

            $columns = array();
            foreach ($row->childNodes as $cell) {
                if (! $cell instanceof DOMElement || 'td' !== strtolower($cell->tagName)) {
                    continue;
                }
                $columns[] = $this->createBlock(
                    'core/column',
                    $this->layoutTableColumnAttributes($cell),
                    $this->convertChildren($cell, $fallbacks, true),
                    $cell
                );
            }
            if (array() !== $columns) {
                $rows[] = $this->createBlock('core/columns', array(), $columns, $row);
            }
        }

        $tableAttributes = $this->styleResolver->presentationAttributes($table);
        if (1 === count($rows)) {
            return array() === $tableAttributes
                ? $rows[0]
                : $this->createBlock('core/group', $tableAttributes, $rows, $table);
        }

        return $this->createBlock('core/group', $tableAttributes, $rows, $table);
    }

    private function serializedTableSection(DOMElement $element): string
    {
        $section = $this->ancestorElement($element, 'thead') instanceof DOMElement
            ? 'thead'
            : ($this->ancestorElement($element, 'tfoot') instanceof DOMElement ? 'tfoot' : 'tbody');
        return $section;
    }

    private function ancestorTable(DOMElement $element): ?DOMElement
    {
        return $this->ancestorElement($element, 'table');
    }

    private function ancestorElement(DOMElement $element, string $tagName): ?DOMElement
    {
        for ( $parent = $element->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
            if ( $tagName === strtolower($parent->tagName) ) {
                return $parent;
            }
        }
        return null;
    }

    /** @param array<string, mixed> $parsed */
    private function projectImageSelector(string $selector, array $parsed, bool $wrapperOnly = false): string
    {
        $replacements = array(
            (int) $parsed['rightmost_rewrite_end'] => array(
                'end'   => (int) $parsed['rightmost_rewrite_end'],
                'value' => $wrapperOnly ? '.wp-block-image' : '.wp-block-image > img',
            ),
        );
        $rightmostType = $parsed['compounds'][count($parsed['compounds']) - 1]['type'] ?? null;
        if ( is_string($rightmostType) && in_array(strtolower($rightmostType), array( 'img', 'svg' ), true) ) {
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

    private function typeSpecificityShim(): string
    {
        return '' === $this->authorStyles()->specificityShim() ? '' : ':not(' . $this->authorStyles()->specificityShim() . ')';
    }

    /** @param array<string, mixed> $parsed */
    private function selectorSpecificityShims(array $parsed): string
    {
        // A wrapper-driven button can collapse selector ancestors onto its one
        // canonical wrapper. Collision-checked impossible sentinels preserve the
        // source selector's specificity without coupling to Gutenberg classes.
        $shims = '';
        foreach ( $parsed['compounds'] as $compound ) {
            $zeroSpecificity = $compound['zero_specificity'] ?? array();
            if ( null !== $compound['type'] && 0 === (int) ($zeroSpecificity['types'] ?? 0) ) {
                $shims .= $this->typeSpecificityShim();
            }
            $classCount = count($compound['classes']) - (int) ($zeroSpecificity['classes'] ?? 0);
            for ( $index = 0; $index < $classCount; ++$index ) {
                $shims .= ':not(.' . $this->authorStyles()->classSpecificityShim() . ')';
            }
            $attributeCount = count($compound['attributes']) - (int) ($zeroSpecificity['attributes'] ?? 0);
            for ( $index = 0; $index < $attributeCount; ++$index ) {
                $shims .= ':not(.' . $this->authorStyles()->classSpecificityShim() . ')';
            }
            $idCount = count($compound['ids']) - (int) ($zeroSpecificity['ids'] ?? 0);
            for ( $index = 0; $index < $idCount; ++$index ) {
                $shims .= ':not(#' . $this->authorStyles()->idSpecificityShim() . ')';
            }
            if ( null !== $compound['nth_child'] || $compound['first_child'] || $compound['last_child'] ) {
                $shims .= ':not(.' . $this->authorStyles()->classSpecificityShim() . ')';
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
        $unsupportedRuntimeMediaOwner = null;
        $ownerFallbackIndex = null;

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

            if ( $unsupportedRuntimeMediaOwner instanceof DOMElement
                && 'svg' === strtolower($child->tagName)
                && $this->isDependentRuntimeMediaMask($child)
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
            $fallbackOffset = count($fallbacks);
            $block = $this->convertElement($child, $fallbacks, $captureUnsupported);
            if ( null !== $block ) {
                $blocks[] = $block;
            } elseif ( $this->isRuntimeMediaSurfaceElement($child) ) {
                $ownerSelector = $this->elementSelector($child);
                for ( $index = $fallbackOffset; $index < count($fallbacks); ++$index ) {
                    if ( $ownerSelector === ($fallbacks[$index]['selector'] ?? null)
                        && in_array((string) ($fallbacks[$index]['diagnostic_code'] ?? ''), array( 'html_iframe_embed_fallback', 'html_unsupported_element' ), true)
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

    private function isRuntimeMediaSurfaceElement(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( in_array($tagName, array( 'iframe', 'canvas', 'embed', 'object' ), true) ) {
            return true;
        }

        return str_contains($tagName, '-')
            && 1 === preg_match('/(?:^|-)(?:audio|carousel|gallery|iframe|media|player|slideshow|video)(?:-|$)/', $tagName);
    }

    private function isDependentRuntimeMediaMask(DOMElement $element): bool
    {
        if ( '' !== trim($this->attr($element, 'aria-label'))
            || in_array(strtolower(trim($this->attr($element, 'role'))), array( 'img', 'graphics-document', 'graphics-symbol' ), true)
            || 0 < $element->getElementsByTagName('title')->length
            || 0 < $element->getElementsByTagName('desc')->length
        ) {
            return false;
        }

        $identity = strtolower(implode(' ', array(
            $this->attr($element, 'id'),
            $this->attr($element, 'class'),
            $this->attr($element, 'data-role'),
        )));
        if ( 1 === preg_match('/\b(?:clip|mask|overlay)\b/', $identity) ) {
            return true;
        }

        $paths = $element->getElementsByTagName('path');
        $path = 1 === $paths->length ? $paths->item(0) : null;
        return $path instanceof DOMElement
            && 1 === $element->getElementsByTagName('*')->length
            && '' !== trim($this->attr($path, 'd'))
            && '' === trim($this->attr($element, 'fill'))
            && '' === trim($this->attr($element, 'stroke'))
            && '' === trim($this->attr($path, 'fill'))
            && '' === trim($this->attr($path, 'stroke'));
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    private function recordDependentRuntimeMediaMaskLoss(DOMElement $owner, DOMElement $mask, array &$fallbacks, int $fallbackIndex): void
    {
        $boundedHtml = $this->boundedFallbackHtml($this->safeFallbackHtml($mask));
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

    private function patternContext(bool $includeRuntimeDomTarget = true): PatternContext
    {
        return $includeRuntimeDomTarget ? $this->patternContext : $this->patternContextWithoutRuntimeDomTarget;
    }

    private function createPatternContext(bool $includeRuntimeDomTarget): PatternContext
    {
        return new PatternContext(
            fn (DOMElement $sourceElement, array $excludedGeometryProperties = array()): array => $this->styleResolver->presentationAttributes($sourceElement, $excludedGeometryProperties),
            fn (DOMElement $sourceElement): string => $this->innerHtml($sourceElement),
            fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null, ?DOMElement $logicalSourceElement = null): array => $this->createBlock($name, $attrs, $innerBlocks, $sourceElement, $logicalSourceElement),
            new PatternRecursiveConverter(
                function (DOMElement $sourceElement, bool $captureUnsupported): PatternConversionResult {
                    $fallbacks = array();
                    return new PatternConversionResult($this->convertChildren($sourceElement, $fallbacks, $captureUnsupported), $fallbacks);
                },
                function (DOMElement $sourceElement, bool $captureUnsupported): PatternConversionResult {
                    $fallbacks = array();
                    $block = $this->convertElement($sourceElement, $fallbacks, $captureUnsupported);
                    return new PatternConversionResult(null === $block ? array() : array($block), $fallbacks);
                },
                function (DOMElement $sourceElement, array $excludedTags): PatternConversionResult {
                    $fallbacks = array();
                    return new PatternConversionResult($this->convertChildrenWithoutTags($sourceElement, $fallbacks, $excludedTags), $fallbacks);
                }
            ),
            new NavigationPatternContext(
                $includeRuntimeDomTarget ? fn (DOMElement $sourceElement): bool => $this->runtimeIslands->isRuntimeDomTarget($sourceElement) : null,
                fn (DOMElement $item, DOMElement $anchor): string => $this->navigationUnderlineColor($item, $anchor),
                fn (DOMElement $sourceElement): string => $this->resolveCssVariablesInValue($this->styleResolver->specificityResolvedPresentationStyle($sourceElement)),
                fn (DOMElement $sourceElement): array => $this->navigationColorInteractionStates($sourceElement),
                fn (DOMElement $sourceElement): string => $this->navigationOverlayMenu($sourceElement)
            ),
            new MediaPatternContext(
                fn (DOMElement $sourceElement): string => $this->styleResolver->mergedPresentationStyle($sourceElement),
                fn (DOMElement $sourceElement): array => $this->htmlAttributes($sourceElement),
                fn (string $url): string => $this->resolvedAssetImageUrl($url),
                fn (DOMElement $sourceElement, array $excludedGeometryProperties = array()): array => $this->styleResolver->mediaTextPresentationAttributes($sourceElement, $excludedGeometryProperties),
                fn (DOMElement $sourceElement): string => $this->styleResolver->mediaTextPresentationStyle($sourceElement)
            ),
            new ColumnsPatternContext(
                fn (DOMElement $sourceElement): string => $this->styleResolver->cssDeclarationString($this->styleResolver->structuralPresentationDeclarations($sourceElement))
            ),
            new MarkupPatternContext(
                fn (DOMElement $sourceElement): string => $this->safeFallbackHtml($sourceElement),
                fn (string $text): string => $this->runtime->escapeHtml($text)
            ),
            new ButtonPatternContext(
                fn (DOMElement $anchor): ?array => $this->fileBlockFromAnchor($anchor),
                fn (DOMElement $sourceElement): string => $this->resolveCssVariablesInValue($this->styleResolver->specificityResolvedPresentationStyle($sourceElement)),
                fn (DOMElement $sourceElement): string => $this->richTextContentWithMaterializedInlineStyles($sourceElement),
                fn (DOMElement $sourceElement, string $content): ?string => $this->richTextContentWithMaterializedSvgImages($sourceElement, $content),
                fn (DOMElement $sourceElement, string $name): string => $this->attr($sourceElement, $name),
                fn (DOMElement $sourceElement): bool => $sourceElement->parentNode instanceof DOMElement && in_array($this->authoredDisplay($sourceElement->parentNode), array('grid', 'inline-grid'), true),
                fn (DOMElement $anchor): PatternRecognitionResult => new PatternRecognitionResult(
                    $this->htmlPreservationBlock($anchor),
                    array(FallbackDiagnostic::build(array('type' => 'html', 'reason' => 'stylable_button_accessible_name_requires_typed_companion', 'diagnostic_code' => 'html_stylable_button_accessible_name_fallback', 'source_format' => 'html', 'tag' => 'a', 'html' => $this->safeFallbackHtml($anchor)), $this->transformationProvenance()->fallback()))
                )
            ),
            new QuotePatternContext(
                fn (DOMElement $sourceElement): string => $this->citationFromElement($sourceElement),
                fn (DOMElement $sourceElement, array $excludedTags): string => $this->innerHtmlWithoutTags($sourceElement, $excludedTags),
                fn (string $html): string => $this->runtime->stripAllTags($html),
                fn (string $inlineTagName): bool => $this->isInlineContentElement($inlineTagName)
            ),
            new CodeWindowPatternContext(
                fn (DOMElement $sourcePre, DOMElement $sourceCode): array => $this->codePresentationAttributes($sourcePre, $sourceCode),
                fn (DOMElement $sourceCode): string => $this->codeContent($sourceCode)
            ),
            new LogoPatternContext(
                fn (DOMElement $sourceElement): string => $this->richTextContentWithMaterializedInlineStyles($sourceElement),
                fn (DOMElement $sourceElement): string => $this->restoreSvgCasing($this->outerHtml($sourceElement)),
                fn (DOMElement $sourceElement, string $content): ?string => $this->richTextContentWithMaterializedSvgImages($sourceElement, $content)
            ),
            new GalleryPatternContext(
                fn (DOMElement $image, ?DOMElement $figure = null, ?DOMElement $picture = null, ?DOMElement $link = null): ?array => $this->convertImageElement($image, $figure, $picture, $link),
                fn (DOMElement $picture, ?DOMElement $figure = null, ?DOMElement $link = null): ?array => $this->convertPictureElement($picture, $figure, $link),
                fn (DOMElement $figure): ?DOMElement => $this->figureLinkedMediaAnchor($figure)
            )
        );
    }

    /** @param list<class-string<\Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternRecognizerInterface>> $allowed */
    private function recognizePatterns(DOMElement $element, array &$fallbacks, array $allowed, bool $includeRuntimeDomTarget = true): ?array
    {
        $result = $this->patternRecognizers->firstMatch($element, $this->patternContext($includeRuntimeDomTarget), $allowed);
        if (null === $result) return null;
        // Results own fallback payloads until their block wins the ordered stage.
        // Assign rather than mutating a variadic argument so reference-backed
        // child conversion diagnostics retain their exact order.
        $fallbacks = array_merge($fallbacks, $result->fallbacks());
        return $result->block();
    }

    /**
     * A side-effect-free pattern context for probing whether an element would
     * convert to a given block, without recording provenance or runtime islands.
     */
    private function probePatternContext(): PatternContext
    {
        return $this->patternProbeContext;
    }

    private function createProbePatternContext(): PatternContext
    {
        return new PatternContext(
            presentationAttributes: fn (DOMElement $sourceElement, array $excludedGeometryProperties = array()): array => $this->styleResolver->presentationAttributes($sourceElement, $excludedGeometryProperties),
            innerHtml: fn (DOMElement $sourceElement): string => $this->innerHtml($sourceElement),
            createBlock: static fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => array(
                'blockName'   => $name,
                'attrs'       => $attrs,
                'innerBlocks' => $innerBlocks,
            ),
            navigationContext: new NavigationPatternContext(
                null,
                fn (DOMElement $item, DOMElement $anchor): string => $this->navigationUnderlineColor($item, $anchor),
                fn (DOMElement $sourceElement): string => $this->resolveCssVariablesInValue($this->styleResolver->specificityResolvedPresentationStyle($sourceElement))
            ),
            markupContext: new MarkupPatternContext(
                fn (DOMElement $sourceElement): string => $this->safeFallbackHtml($sourceElement),
                fn (string $text): string => $this->runtime->escapeHtml($text)
            ),
            codeWindowContext: new CodeWindowPatternContext(
                fn (DOMElement $sourcePre, DOMElement $sourceCode): array => $this->codePresentationAttributes($sourcePre, $sourceCode),
                fn (DOMElement $sourceCode): string => $this->codeContent($sourceCode)
            ),
            logoContext: new LogoPatternContext(
                fn (DOMElement $sourceElement): string => $this->richTextContentWithMaterializedInlineStyles($sourceElement),
                fn (DOMElement $sourceElement): string => $this->restoreSvgCasing($this->outerHtml($sourceElement)),
                fn (DOMElement $sourceElement, string $content): ?string => $this->richTextContentWithMaterializedSvgImages($sourceElement, $content)
            ),
            galleryContext: new GalleryPatternContext(
                fn (DOMElement $image, ?DOMElement $figure = null, ?DOMElement $picture = null, ?DOMElement $link = null): ?array => $this->convertImageElement($image, $figure, $picture, $link),
                fn (DOMElement $picture, ?DOMElement $figure = null, ?DOMElement $link = null): ?array => $this->convertPictureElement($picture, $figure, $link),
                fn (DOMElement $figure): ?DOMElement => $this->figureLinkedMediaAnchor($figure)
            )
        );
    }

    private function navigationUnderlineColor(DOMElement $item, DOMElement $anchor): string
    {
        return $this->navigationUnderlineColorResolver->resolve(
            $item,
            $anchor,
            fn (DOMElement $element): array => $this->styleResolver->presentationDeclarations($element),
            $this->sourceStyles()->pseudoElementRules(),
            fn (DOMElement $element, string $selector): bool => $this->styleResolver->matchesCssSelector($element, $selector)
        );
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function convertElement(DOMElement $element, array &$fallbacks, bool $captureUnsupported = false): ?array
    {
        // Conversion helpers may rewrite source markup. Do not reuse selector
        // results or cached inputs across independently converted elements.
        $this->styleResolver->invalidateSourceSelectorMatchCache();
        $tagName = strtolower($element->tagName);

        // Capturers sometimes append hidden, sourceless frames as internal
        // scaffolding. They cannot render or load anything, so omit them before
        // media dispatch can turn them into fallback/runtime-island evidence.
        if ( 'iframe' === $tagName && $this->isInertHiddenCaptureIframe($element) ) {
            return null;
        }

        // A direct phrasing child participates in its parent's flex or grid
        // layout. Preserve that source element as the editable leaf rather
        // than introducing a paragraph wrapper with core paragraph margins.
        if ( $this->requiresStandaloneInlineLayoutLeaf($element) && $this->authorLayoutLeafSupportsRichText($element) ) {
            $leaf = $this->inlineLayoutCarrierBlock($element);
            if ( null !== $leaf ) {
                return $leaf;
            }
        }

        $formControlSlotToken = $this->transformationProvenance()->formControlSlotToken($element->getNodePath());
        if ( null !== $formControlSlotToken ) {
            $block = $this->htmlPreservationBlock($element);
            $block['_binding_token'] = $formControlSlotToken;
            return $block;
        }

        $projectedNavigation = $this->projectedNavigationTargetForControl($element);
        if ( $projectedNavigation instanceof DOMElement ) {
            $block = $this->recognizePatterns($projectedNavigation, $fallbacks, array(NavigationPattern::class));
            if ( null !== $block ) {
                $controlAttrs = $this->styleResolver->presentationAttributes($element);
                $nativeClassNames = 'blocks-engine-list-navigation blocks-engine-native-responsive-navigation';
                if ( $this->isImplicitDialogNavigationControl($element) ) {
                    $nativeClassNames .= ' blocks-engine-projected-dialog-navigation';
                }
                $block['attrs']['className'] = $this->mergeClassNames(
                    $nativeClassNames,
                    (string) ($controlAttrs['className'] ?? ''),
                    $this->sourceProjectionClassName($element)
                );
                $block['attrs']['overlayMenu'] = 'mobile';
                return $block;
            }
        }

        if ( $this->isProjectedNavigationSuppressed($element) || $this->isRedundantMenuToggleControl($element) ) {
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

        if ('dialog' === $tagName && 'true' === $this->attr($element, 'data-blocks-engine-captured-dialog')) {
            return $this->capturedDialogBlock($element, $fallbacks);
        }

        if ( $this->runtimeIslands->shouldPreserveDataAttributeRuntimeTarget($element) ) {
            return $this->htmlPreservationBlock($element);
        }

        // Geometry proof may also cover provider custom-element shells. Keep
        // semantic HTML elements outside this reduction boundary.
        if ( ('div' === $tagName || str_contains($tagName, '-')) && null !== $this->layoutGeometryProofFor($element) ) {
            $proofBacked = $this->proofBackedWrapperCoalescing($element, $fallbacks);
            if ( null !== $proofBacked ) {
                return $proofBacked;
            }
        }

        // Stylesheet and document-resource links are collected by the artifact
        // compiler. They are metadata, not page-content blocks.
        if ( 'link' === $tagName ) {
            return null;
        }

        $mathBlock = $this->recognizePatterns($element, $fallbacks, array(MathPattern::class));
        if ( null !== $mathBlock ) {
            return $mathBlock;
        }

        if ( $this->richTextConverter->handles($tagName) ) {
            return $this->richTextConverter->convert($element, $tagName, $fallbacks)->block;
        }

        if ( 'address' === $tagName ) {
            return $this->textLeafConverter->convert($element, $tagName, $fallbacks)->block;
        }

        if ( $this->session->preservesShellLandmarks() && (in_array($tagName, array('header', 'footer'), true) || in_array(strtolower($this->attr($element, 'role')), array('banner', 'contentinfo'), true)) && ('body' === strtolower($element->parentNode?->nodeName ?? '') || $this->hasAncestorTag($element, array('article'))) ) {
            $children = $this->convertChildren($element, $fallbacks, true);
            if ( array() !== $children ) {
                return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($element), $children, $element);
            }
        }

        $wrappedSearchBlock = $this->searchBlockFromWrapper($element);
        if ( null !== $wrappedSearchBlock ) {
            return $wrappedSearchBlock;
        }

        $customImage = $this->imageOnlyCustomElement($element);
        if ( $customImage instanceof DOMElement ) {
            $picture = $customImage->parentNode;
            return $this->convertImageElement(
                $customImage,
                $element,
                $picture instanceof DOMElement && 'picture' === strtolower($picture->tagName) ? $picture : null
            );
        }

        $customVideo = $this->customVideoElement($element);
        if ( $customVideo instanceof DOMElement ) {
            return $this->hasTransparentCustomVideoHostPresentation($element)
                ? $this->convertMediaElement($customVideo)
                : $this->responsiveMediaBlock($element);
        }

        $mediaDispatch = $this->convertMediaDispatchElement($element, $tagName, $fallbacks);
        if ( $mediaDispatch['handled'] ) {
            return $mediaDispatch['block'];
        }

        if ($this->session->usesFallbackReductionMode() && ( 'button' === $tagName || ( 'a' === $tagName && '' === trim($this->attr($element, 'aria-label')) ) )) {
            $text = $this->innerHtml($element);
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
            return $this->buttonLinkDispatcher->convertAnchor($element, $fallbacks);
        }

if ( $this->isInlineContentElement($tagName) ) {
            return $this->convertInlineContentElement($element, $fallbacks);
        }

        if ( in_array( $tagName, array( 'ul', 'ol', 'dl', 'dt', 'dd' ), true ) ) {
            return $this->convertListElement($element, $fallbacks);
        }

        if ( 'blockquote' === $tagName ) {
            return $this->recognizePatterns($element, $fallbacks, array(QuotePattern::class));
        }

        if ( 'figure' === $tagName || 'figcaption' === $tagName ) {
            return $this->convertFigureElement($element, $fallbacks);
        }

        if ( 'noscript' === $tagName ) {
            return $this->textLeafConverter->convert($element, $tagName, $fallbacks)->block;
        }

        if ( 'marquee' === $tagName || 'blink' === $tagName ) {
            return $this->textLeafConverter->convert($element, $tagName, $fallbacks)->block;
        }

        if ( 'label' === $tagName ) {
            return $this->readableFormControlBlockFromElement($element);
        }

        if ( 'pre' === $tagName || 'plaintext' === $tagName ) {
            return $this->textLeafConverter->convert($element, $tagName, $fallbacks)->block;
        }

        if ( $this->tableConverter->handles($tagName) ) {
            return $this->tableConverter->convert($element, $tagName, $fallbacks)->block;
        }

        $parameterTable = $this->recognizePatterns($element, $fallbacks, array(ParameterTablePattern::class));
        if ( null !== $parameterTable ) {
            return $parameterTable;
        }

        if ( 'hr' === $tagName || 'br' === $tagName ) {
            return $this->textLeafConverter->convert($element, $tagName, $fallbacks)->block;
        }

        if ( 'details' === $tagName ) {
            return $this->recognizePatterns($element, $fallbacks, array(DetailsPattern::class));
        }

        if ( 'a' === $tagName ) {
            return $this->buttonLinkDispatcher->convertAnchor($element, $fallbacks);
        }

        if ( 'button' === $tagName ) {
            if ( $this->isReplacedSearchClusterControl($element) ) {
                return null;
            }
            if ( $this->isImageCarrierButton($element) ) {
                $children = $this->convertChildren($element, $fallbacks, true);
                if ( array() !== $children ) {
                    return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($element), $children, $element);
                }
            }
            return $this->buttonLinkDispatcher->convertButton($element);
        }

if ( 'svg' === $tagName ) {
            return $this->convertSvgElement($element, $fallbacks);
        }

        if ( 'canvas' === $tagName ) {
            if ( ! $this->runtimeIslands->isRuntimeCanvasTarget($element) ) {
                return null;
            }

            $this->runtimeIslands->recordRuntimeIsland($element, 'canvas', 'canvas_requires_runtime', 'canvas_element_and_client_script_execution', array(
                'script_dependency_hint' => 'Scripts may target this canvas and call canvas APIs such as getContext(); preserving the native element keeps the runtime addressable.',
                'required_scripts'        => $this->requiredScriptsForElement($element),
            ));
            return $this->htmlPreservationBlock($element);
        }

        if ( 'script' === $tagName ) {
            if ( $this->captureStaticScriptMetadata($element) ) {
                if ( $this->isAddressableStaticJsonTarget($element) ) {
                    $this->runtimeIslands->recordRuntimeIsland($element, 'static_script', 'static_script_runtime_target', 'client_script_configuration', array(
                        'script_role' => 'data',
                        'required_scripts' => $this->requiredScriptsForElement($element),
                    ));
                    return $this->staticJsonTargetBlock($element);
                }
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
            $navigation = $this->recognizePatterns($element, $fallbacks, array(AccordionPattern::class, SocialLinksPattern::class, NavigationPattern::class));
            if ( null !== $navigation ) {
                return $this->rememberAccordionDisclosureRoot($navigation, $element);
            }

            $inlineNavigation = $this->inlineNavigationGroupBlockFromElement($element);
            if ( null !== $inlineNavigation ) {
                return $inlineNavigation;
            }
        }

        if ( ShellLandmarkPolicy::isFlowContainerTag($tagName) ) {
            return $this->convertFlowContainerElement($element, $fallbacks);
        }

        if ( $this->isGeneratedComponentCandidate($element) ) {
            $generated = $this->fallbackEmitter()->maybeGenerateCustomBlock($element, $this->generatedBlocks(), true, true);
            if ( null !== $generated ) {
                return $this->generatedComponentBlock($generated, $element);
            }
        }

        $readableControlBlock = $this->readableFormControlBlockFromElement($element);
        if ( null !== $readableControlBlock ) {
            return $readableControlBlock;
        }

        if ( $this->preserveStandaloneFormControlAsRuntimeIsland($element) ) {
            return null;
        }

        $transparentCustomElement = $this->transparentCustomElementBlock($element, $fallbacks);
        if ( null !== $transparentCustomElement ) {
            return $transparentCustomElement;
        }

        if ( $captureUnsupported ) {
            return $this->unsupportedRecorder->record($element, $tagName, $fallbacks);
        }

        return null;
    }

    /**
     * Custom elements are presentation-only only when their host exposes no
     * component API and every child can stand on its own as a native block.
     * Explicit ARIA list topology is retained with semantic Group wrappers.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function transparentCustomElementBlock(DOMElement $element, array &$fallbacks): ?array
    {
        $tagName = strtolower($element->tagName);
        if ( ! str_contains($tagName, '-') || ! $this->isSafeTransparentCustomElement($element) ) {
            return null;
        }

        $children = array();
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }
            if ( XML_COMMENT_NODE === $child->nodeType ) {
                continue;
            }
            if ( ! $child instanceof DOMElement ) {
                return null;
            }
            $children[] = $child;
        }
        if ( array() === $children ) {
            return null;
        }

        $isList = 'list' === strtolower($this->attr($element, 'role'));
        if ( $isList && ! array_reduce($children, fn (bool $valid, DOMElement $child): bool => $valid && 'listitem' === strtolower($this->attr($child, 'role')), true) ) {
            return null;
        }
        // A non-semantic custom element is transparent only as a single
        // structural wrapper. Larger arbitrary subtrees remain eligible for
        // the custom-block generator rather than being prematurely flattened.
        if ( ! $isList && (1 !== count($children) || ! $this->isStructuralTransparentCustomWrapperChild($children[0])) ) {
            return null;
        }

        $converted = array();
        $childFallbacks = array();
        foreach ( $children as $child ) {
            if ( $isList && ! $this->isSafeTransparentCustomElement($child) ) {
                return null;
            }
            $childBlocks = $this->convertChildren($child, $childFallbacks, true);
            if ( array() === $childBlocks ) {
                return null;
            }
            if ( $isList ) {
                $converted[] = $this->createBlock('core/group', array_merge($this->styleResolver->presentationAttributes($child), array( 'tagName' => 'li' )), $childBlocks, $child);
            } else {
                array_push($converted, ...$childBlocks);
            }
        }
        if ( array() !== $childFallbacks ) {
            return null;
        }

        if ( $isList ) {
            return $this->createBlock('core/group', array_merge($this->styleResolver->presentationAttributes($element), array( 'tagName' => 'ul' )), $converted, $element);
        }

        if ( 1 === count($converted) && array() === $this->styleResolver->presentationAttributes($element) ) {
            return $converted[0];
        }

        return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($element), $converted, $element);
    }

    private function isSafeTransparentCustomElement(DOMElement $element): bool
    {
        if ( $this->runtimeIslands->isRuntimeDomTarget($element) || array() !== $this->eventMetadata($element) || $this->hasMotionStructureToken($element) ) {
            return false;
        }

        return true;
    }

    private function isStructuralTransparentCustomWrapperChild(DOMElement $element): bool
    {
        return in_array(strtolower($element->tagName), array( 'article', 'aside', 'blockquote', 'div', 'dl', 'figure', 'footer', 'form', 'header', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'table', 'ul' ), true);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array{handled: bool, block: array<string, mixed>|null}
     */
    private function convertMediaDispatchElement(DOMElement $element, string $tagName, array &$fallbacks): array
    {
        $placeholderMedia = $this->recognizePatterns($element, $fallbacks, array(PlaceholderMediaPattern::class));
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
    private function mediaGalleryBlockFromElement(DOMElement $element, array &$fallbacks): ?array
    {
        if ( ! $this->isGalleryCompatibleMediaLayout($element) ) {
            return null;
        }

        if ( $this->hasResponsiveImageSources($element) ) {
            // GalleryPattern probes child conversions before it knows whether it
            // has enough images. Preserve the collection as one companion block.
            return $this->hasGalleryMediaItems($element) ? $this->responsiveMediaBlock($element) : null;
        }

        return $this->recognizePatterns($element, $fallbacks, array(GalleryPattern::class));
    }

    private function isGalleryCompatibleMediaLayout(DOMElement $element): bool
    {
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement || 'figcaption' === strtolower($child->tagName) ) {
                continue;
            }

            $layoutElements = array( $child );
            foreach ( $child->getElementsByTagName('*') as $descendant ) {
                if ( $descendant instanceof DOMElement ) {
                    $layoutElements[] = $descendant;
                }
            }

            foreach ( $layoutElements as $layoutElement ) {
                $declarations = $this->styleResolver->structuralPresentationDeclarations($layoutElement);
                $position = strtolower(trim((string) ($declarations['position'] ?? '')));
                if ( in_array($position, array( 'absolute', 'fixed', 'sticky' ), true) ) {
                    return false;
                }

                $zIndex = strtolower(trim((string) ($declarations['z-index'] ?? '')));
                if ( '' !== $zIndex && 'auto' !== $zIndex ) {
                    return false;
                }
            }
        }

        return true;
    }

    private function hasGalleryMediaItems(DOMElement $element): bool
    {
        $items = 0;
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }
            if ( ! $child instanceof DOMElement || 'figcaption' === strtolower($child->tagName) ) {
                if ( ! $child instanceof DOMElement ) {
                    return false;
                }
                continue;
            }
            if ( ! in_array(strtolower($child->tagName), array( 'figure', 'img', 'picture' ), true) ) {
                return false;
            }
            ++$items;
        }

        return $items >= 2;
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

    /** @param array<int, array<string, mixed>> $fallbacks @return array<string, mixed> */
    private function capturedDialogBlock(DOMElement $element, array &$fallbacks): array
    {
        $blockName = $this->generatedBlocks()->blockName(CapturedDialogBlockGenerator::LOCAL_NAME);
        $this->generatedBlocks()->register(CapturedDialogBlockGenerator::class, (new CapturedDialogBlockGenerator())->definition($blockName));

        $attrs = array_filter(array(
            'dialogId' => trim($this->attr($element, 'id')),
            'triggerIds' => array_values(array_filter(preg_split('/\s+/', trim($this->attr($element, 'data-blocks-engine-triggers'))) ?: array())),
            'ariaLabel' => trim($this->attr($element, 'aria-label')),
            'ariaLabelledby' => trim($this->attr($element, 'aria-labelledby')),
            'ariaDescribedby' => trim($this->attr($element, 'aria-describedby')),
            'className' => trim($this->attr($element, 'class')),
            'addCloseButton' => 'true' === $this->attr($element, 'data-blocks-engine-add-close'),
        ), static fn(mixed $value): bool => false !== $value && '' !== $value && array() !== $value);
        $children = $this->convertChildren($element, $fallbacks, true);
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $opening = '<dialog';
        foreach (array('dialogId' => 'id', 'className' => 'class', 'ariaLabel' => 'aria-label', 'ariaLabelledby' => 'aria-labelledby', 'ariaDescribedby' => 'aria-describedby') as $key => $attribute) {
            if (isset($attrs[$key])) $opening .= ' ' . $attribute . '="' . $escape((string) $attrs[$key]) . '"';
        }
        if (isset($attrs['triggerIds'])) $opening .= ' data-blocks-engine-triggers="' . $escape(implode(' ', $attrs['triggerIds'])) . '"';
        $opening .= '>';
        if (! empty($attrs['addCloseButton'])) $opening .= '<button type="button" data-blocks-engine-dialog-close="true" aria-label="Close">Close</button>';
        $innerContent = array($opening);
        foreach ($children as $_) $innerContent[] = null;
        $innerContent[] = '</dialog>';

        return array(
            'blockName' => $blockName,
            'attrs' => $attrs,
            'innerBlocks' => $children,
            'innerHTML' => $opening . '</dialog>',
            'innerContent' => $innerContent,
        );
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<int, array<string, mixed>> $innerBlocks
     * @return array<string, mixed>
     */
    private function createBlock(string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null, ?DOMElement $logicalSourceElement = null): array
    {
        if ( $sourceElement instanceof DOMElement
            && in_array($name, array( 'core/paragraph', 'core/heading', 'core/list-item' ), true)
            && $this->richTextContentHasStructuralHtml((string) ($attrs['content'] ?? ''))
        ) {
            $structuralFallbacks = array();
            $children = $this->convertChildren($sourceElement, $structuralFallbacks, true);
            if ( array() !== $children ) {
                return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($sourceElement), $children, $sourceElement, $logicalSourceElement);
            }
        }

        $preserveInlineLayoutLeaf = ! empty($attrs['preserveInlineLayoutLeaf']);
        unset($attrs['preserveInlineLayoutLeaf']);
        if ( ! $preserveInlineLayoutLeaf ) {
            $attrs = $this->hoistContentWrappingSpans($name, $attrs);
        }
        if ( $sourceElement instanceof DOMElement && in_array($name, array( 'core/paragraph', 'core/heading' ), true) ) {
            $textAlign = strtolower(trim((string) ($this->styleResolver->presentationDeclarations($sourceElement)['text-align'] ?? '')));
            if ( in_array($textAlign, array( 'left', 'center', 'right' ), true) ) {
                $attrs['align'] = $textAlign;
            }
        }

        if ( $sourceElement instanceof DOMElement && in_array($name, array( 'core/paragraph', 'core/heading' ), true) && $this->richTextRequiresHtmlFallbackWithoutNativeSvgImageObjects((string) ($attrs['content'] ?? '')) ) {
            $attrs['content'] = $this->stripDecorativeSvgFromRichText((string) ($attrs['content'] ?? ''));
            if ( $this->richTextRequiresHtmlFallbackWithoutNativeSvgImageObjects((string) ($attrs['content'] ?? '')) ) {
                return $this->createBlock('core/html', array( 'content' => $this->safeFallbackHtml($sourceElement) ), array(), $sourceElement);
            }
        }

        $runtimeOwned = false;
        if ( $sourceElement instanceof DOMElement ) {
            $sourceTagName = strtolower($sourceElement->tagName);
            if ( in_array($name, array( 'core/group', 'core/column', 'core/columns' ), true) ) {
                $attrs = $this->applyIntrinsicVisualMediaHeight($sourceElement, $attrs);
            }
            if ( 'core/image' === $name && 'figure' !== $sourceTagName ) {
                $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), self::SYNTHETIC_IMAGE_FIGURE_CLASS);
            }
            if ( 'core/paragraph' === $name && $this->isInlineSourceElement($sourceTagName) ) {
                $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), self::SYNTHETIC_PARAGRAPH_CLASS);
                if ( 'a' === $sourceTagName && $this->sourceAnchorHasNoTextDecoration($sourceElement) ) {
                    $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), self::SYNTHETIC_ANCHOR_UNDECORATED_CLASS);
                }
                if ( 'a' === $sourceTagName ) {
                    $this->applySyntheticHeaderAnchorCarrier($attrs, $sourceElement);
                }
            }
            $projectionClassName = $this->sourceProjectionClassName($sourceElement, (string) ($attrs['className'] ?? ''));
            if ( '' !== $projectionClassName ) {
                $attrs['className'] = $projectionClassName;
            }
            if ( 'core/group' === $name
                && $this->isDirectChildOfAuthorOwnedLayout($sourceElement)
                && $this->hasAuthorSemanticMarker($sourceElement)
            ) {
                $attrs['className'] = $this->mergeClassNames(
                    (string) ($attrs['className'] ?? ''),
                    self::CSS_OWNED_LAYOUT_ITEM_CLASS
                );
            }
            if ( 'core/group' === $name && $this->isAtomicInlineChildFlow($sourceElement, $innerBlocks) ) {
                $attrs['className'] = $this->mergeClassNames(
                    (string) ($attrs['className'] ?? ''),
                    self::CSS_OWNED_INLINE_FLOW_CLASS
                );
            }
            if ( 'core/group' === $name && 'grid' === (string) ($attrs['layout']['type'] ?? '') ) {
                // Core Group's save() does not reproduce a blockGap declaration.
                // Preserve an authored inline gap in a generated carrier instead
                // of storing markup that the editor will mark invalid.
                $gapCarrier = $this->styleResolver->inlineGeometryClassName($sourceElement, array(), array( 'gap' ));
                if ( '' !== $gapCarrier ) {
                    $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), $gapCarrier);
                }
            }
            $tableMarker = $this->authorSelectorProjections()->tableMarker($this->sourceElementIdentity($sourceElement));
            if ( 'core/table' === $name && '' !== $tableMarker ) {
                $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), $tableMarker);
            }
            $logicalControl = $logicalSourceElement ?? $sourceElement;
            $logicalControlPath = $logicalControl->getNodePath() ?? '';
            $nativeButtonTextAlignment = '';
            $hasNativeButtonColor = false;
            $hasNativeButtonStyle = false;
            if ( 'core/button' === $name && in_array(strtolower($logicalControl->tagName), array( 'a', 'button' ), true) ) {
                $existingTextColor = (string) ($attrs['style']['color']['text'] ?? '');
                $nativeButtonTextAlignment = $this->applyNativeButtonInheritedStyle($logicalControl, $attrs, 'a' === strtolower($logicalControl->tagName) && ($sourceElement === $logicalControl || $sourceElement->parentNode === $logicalControl));
                $hasNativeButtonColor = $existingTextColor !== (string) ($attrs['style']['color']['text'] ?? '');
                $hasNativeButtonStyle = '' !== $nativeButtonTextAlignment || $hasNativeButtonColor;
            }
            if ( in_array($name, array( 'core/button', 'core/buttons' ), true) && in_array(strtolower($logicalControl->tagName), array( 'a', 'button' ), true) && ( $this->authorSelectorProjections()->isControlPath($logicalControlPath) || ( '' !== $this->authorStyles()->combinedCss() && 'a' === strtolower($logicalControl->tagName) && ( '' !== trim($this->attr($logicalControl, 'class')) || '' !== trim($this->attr($logicalControl, 'id')) ) ) ) ) {
                $controlMarker = '' !== $logicalControlPath
                    ? $this->authorSelectorProjections()->ensureControlMarker($logicalControlPath)
                    : '';
                if ( '' !== $controlMarker ) {
                    $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), $controlMarker);
                    if ( 'core/button' === $name ) {
                        $this->registerNativeButtonStyleRule($controlMarker, $attrs, $nativeButtonTextAlignment, $logicalControl);
                        if ( $this->isDirectChildOfAuthorFlexLayout($logicalControl) ) {
                            $this->generatedSupportStyles()->registerDirectFlexButton($controlMarker, $this->directFlexButtonStyleRule($controlMarker, $logicalControl));
                        }
                        $buttonWidth = (int) ($attrs['width'] ?? 0);
                        if ( in_array($buttonWidth, array( 25, 50, 75, 100 ), true) ) {
                            $this->generatedSupportStyles()->registerButtonWidth($controlMarker, $this->buttonWidthStyleRule($controlMarker, $buttonWidth));
                        }
                    }
                }
                $presentationPath = $sourceElement->getNodePath() ?? '';
                if ( '' !== $controlMarker && '' !== $presentationPath && $presentationPath !== $logicalControlPath ) {
                    $this->authorSelectorProjections()->installButtonPresentationMarker($presentationPath, $controlMarker);
                }
            }
            if ( 'core/button' === $name && $hasNativeButtonStyle && '' === $this->authorSelectorProjections()->controlMarker($logicalControlPath) ) {
                $nativeButtonMarker = $hasNativeButtonColor
                    ? $this->allocateAuthorMarker('native-button')
                    : 'blocks-engine-native-button-alignment-' . $nativeButtonTextAlignment;
                $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), $nativeButtonMarker);
                $this->registerNativeButtonStyleRule($nativeButtonMarker, $hasNativeButtonColor ? $attrs : array(), $nativeButtonTextAlignment);
                $buttonWidth = (int) ($attrs['width'] ?? 0);
                if ( in_array($buttonWidth, array( 25, 50, 75, 100 ), true) ) {
                    $this->generatedSupportStyles()->registerButtonWidth($nativeButtonMarker, $this->buttonWidthStyleRule($nativeButtonMarker, $buttonWidth));
                }
            }
            $attrs = $this->applyDeclaredBlockSupport($name, $attrs, $sourceElement);
            $this->recordPresentationProvenance($name, $attrs, $sourceElement);
            $this->recordStructureProvenance($name, $attrs, $sourceElement);
            if ( $this->runtimeIslands->isRuntimeDomTarget($sourceElement) && ! $this->isFormControlElement($sourceElement) && ! in_array($sourceTagName, array( 'canvas', 'form', 'script' ), true) ) {
                $runtimeOwned = true;
                if ( ! $this->runtimeIslands->canRetainRuntimeDomContractNatively($sourceElement, $name) ) {
                    $this->runtimeIslands->recordRuntimeIsland($sourceElement, 'dom', 'runtime_dom_target', 'client_script_execution', array(
                        'events'          => $this->eventMetadata($sourceElement),
                        'required_scripts' => $this->requiredScriptsForElement($sourceElement),
                    ));
                    $this->runtimeIslands->recordRuntimeDomFallback($sourceElement, $name);
                } else {
                    $this->runtimeIslands->recordNativeRuntimeDomPreservation($sourceElement, $name, in_array($name, array('core/paragraph', 'core/heading'), true));
                }
            }
            $provenanceId = $this->transformationProvenance()->registerSource(
                $this->sourceProvenanceEntry($name, $sourceElement),
                $this->sourceElementStartsHidden($sourceElement)
            );
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
        if ($runtimeOwned) $block['_editability_runtime_owned'] = true;
        if ( $sourceElement instanceof DOMElement && array() === $innerBlocks && 'core/group' === $name ) {
            $visualTopologyEvidence = $this->emptyVisualTopologyEvidence($sourceElement);
            if ( array() !== $visualTopologyEvidence ) {
                $block['_editability_visual_owned'] = true;
                $this->transformationProvenance()->addSourceEvidence($provenanceId, array( 'visual_topology_evidence' => $visualTopologyEvidence ));
            }
        }

        return $block;
    }

    /** @param array<int, array<string, mixed>> $innerBlocks */
    private function isAtomicInlineChildFlow(DOMElement $element, array $innerBlocks): bool
    {
        $children = array();
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                $children[] = $child;
                continue;
            }
            if ( '' !== trim((string) ($child->textContent ?? '')) ) {
                return false;
            }
        }

        if ( count($children) < 2 || count($children) !== count($innerBlocks) ) {
            return false;
        }

        foreach ( $children as $child ) {
            $display = strtolower(trim((string) preg_replace('/\s*!important\s*$/i', '', (string) ($this->styleResolver->cssDeclarations($this->attr($child, 'style'))['display'] ?? ''))));
            if ( ! in_array($display, array( 'inline-block', 'inline-flex', 'inline-grid', 'inline-table' ), true) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * A linked text logo becomes a paragraph for valid block markup. Carry
     * non-inherited header anchor behavior onto the saved inner anchor instead
     * of re-pointing its source selector at a higher-specificity target.
     *
     * @param array<string, mixed> $attrs
     */
    private function applySyntheticHeaderAnchorCarrier(array &$attrs, DOMElement $anchor): void
    {
        if ( 'a' !== strtolower($anchor->tagName)
            || ! $this->hasAncestorTag($anchor, array( 'header' ))
        ) {
            return;
        }

        $direct = $this->styleResolver->cssDeclarations($this->styleResolver->specificityResolvedPresentationStyle($anchor));
        $declarations = array();
        if ( 'inherit' === strtolower(trim((string) ($direct['color'] ?? ''))) ) {
            $inheritedColor = $this->styleResolver->authoredInheritedPropertyWinner($anchor, 'color');
            if ( '' !== $inheritedColor ) {
                $declarations['color'] = $inheritedColor;
            }
        }

        foreach ( array( 'display', 'align-items', 'justify-content' ) as $property ) {
            $value = trim((string) ($direct[$property] ?? ''));
            if ( '' !== $value && ! str_contains(strtolower($value), '!important') ) {
                $declarations[$property] = $this->resolveCssVariablesInValue($value);
            }
        }
        foreach ( $this->styleResolver->specificityResolvedGapDeclarations($anchor) as $property => $value ) {
            if ( ! str_contains(strtolower($value), '!important') ) {
                $declarations[$property] = $this->resolveCssVariablesInValue($value);
            }
        }
        if ( array() === $declarations ) {
            return;
        }

        $css = $this->styleResolver->cssDeclarationString($declarations);
        $className = self::SYNTHETIC_HEADER_ANCHOR_CLASS_PREFIX . substr(hash('sha256', $css), 0, 16);
        $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), $className);
        $this->generatedSupportStyles()->registerSyntheticHeaderAnchor($className, 'p.' . $className . '>a{' . $css . '}');
    }

    /**
     * WordPress ignores style.border components that the registered block type
     * does not declare. Keep supported components native; move only unsupported
     * width/style/color values into the existing deterministic carrier. Border
     * radius deliberately stays on the pre-existing native path unchanged.
     *
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function applyDeclaredBlockSupport(string $name, array $attrs, DOMElement $sourceElement): array
    {
        $normalized = $this->runtime->normalizeBlockSupportAttributes($name, $attrs);
        $fallback = $normalized['fallbackStyle'];
        $attrs = $normalized['attrs'];
        $submenuBackground = 'core/navigation-submenu' === $name ? trim((string) ($fallback['color']['background'] ?? '')) : '';
        if ( '' !== $submenuBackground && array() !== $this->styleResolver->cssDeclarations('background-color:' . $submenuBackground) ) {
            $className = 'blocks-engine-navigation-submenu-background-' . hash('sha256', $submenuBackground);
            $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), $className);
            $this->generatedSupportStyles()->registerNavigationSubmenuBackground($className, $submenuBackground);
        }
        $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array();
        if ( 'core/navigation' === $name && is_array($fallback['spacing']['padding'] ?? null) ) {
            foreach ( $classes as $class ) {
                if ( 'blocks-engine-list-navigation' !== $class && ! str_starts_with($class, 'blocks-engine-') ) {
                    $this->generatedSupportStyles()->registerListNavigationPadding($class, $fallback['spacing']['padding']);
                }
            }
        }
        if ( 'core/navigation' === $name && is_array($fallback['spacing'] ?? null) ) {
            $declarations = $this->styleResolver->styleAttributeMapper()->serialize(array( 'spacing' => $fallback['spacing'] ))['style'];
            foreach ( $classes as $class ) {
                if ( '' !== $declarations && 'blocks-engine-list-navigation' !== $class && ! str_starts_with($class, 'blocks-engine-') ) {
                    $this->generatedSupportStyles()->registerNavigationSpacing($class, $declarations);
                    break;
                }
            }
        }
        if ( 'core/buttons' === $name && is_array($fallback['spacing'] ?? null) ) {
            $declarations = $this->styleResolver->styleAttributeMapper()->serialize(array( 'spacing' => $fallback['spacing'] ))['style'];
            foreach ( $classes as $class ) {
                if ( '' !== $declarations && str_starts_with($class, 'blocks-engine-control-') ) {
                    $this->generatedSupportStyles()->registerButtonWrapperSpacing($class, $declarations);
                    break;
                }
            }
        }
        if ( in_array($name, array( 'core/navigation-link', 'core/navigation-submenu' ), true) && '' !== trim((string) ($fallback['color']['text'] ?? '')) ) {
            foreach ( $classes as $class ) {
                if ( (str_starts_with($class, 'blocks-engine-navigation-link-color-')
                        && ! str_starts_with($class, 'blocks-engine-navigation-link-color-states-'))
                    || str_starts_with($class, 'blocks-engine-navigation-current-color-')
                ) {
                    $this->generatedSupportStyles()->registerNavigationLinkColor($class, (string) $fallback['color']['text']);
                }
            }
        }
        if ( array() === $fallback ) {
            return $attrs;
        }

        $fallbackStyle = $this->styleResolver->styleAttributeMapper()->serialize($fallback)['style'];
        $fallbackDeclarations = $this->styleResolver->cssDeclarations($fallbackStyle);
        $inlineDeclarations = $this->styleResolver->cssDeclarations($this->attr($sourceElement, 'style'));
        $inlineMapped = $this->styleResolver->styleAttributeMapper()->map($inlineDeclarations);
        $inlineFallbackDeclarations = $this->styleResolver->cssDeclarations($this->styleResolver->styleAttributeMapper()->serialize($inlineMapped['style'] ?? array())['style']);
        $preserveGeneratedStyle = ('core/button' === $name && $this->hasLogoBrandSignal($sourceElement))
            || ('core/spacer' === $name && $this->isEmptyVisualInlineCandidate($sourceElement));
        foreach ( array_keys($fallbackDeclarations) as $property ) {
            if ( 'core/button' === $name
                && 'border-radius' === $property
                && '0' === (string) ($fallback['border']['radius'] ?? '')
            ) {
                // ButtonStyleResolver adds a square radius to suppress the
                // theme's default rounded button chrome. It is generated
                // compatibility geometry, not a missing source declaration.
                continue;
            }
            if ( ! $preserveGeneratedStyle && ! isset($inlineDeclarations[ $property ]) && ! isset($inlineFallbackDeclarations[ $property ]) ) {
                unset($fallbackDeclarations[ $property ]);
            }
        }
        if ( preg_match('/(?:^|\s)be-inline-geometry-[^\s]+/', (string) ($attrs['className'] ?? '')) ) {
            // The source geometry carrier preserves declaration priority and
            // custom-property case. Do not add a lossy mapped duplicate.
            foreach ( $this->styleResolver->inlineGeometryProperties() as $property ) {
                unset($fallbackDeclarations[ $property ]);
            }
            unset($fallbackDeclarations['box-shadow']);
        }
        if ( array() === $fallbackDeclarations ) {
            return $attrs;
        }
        $carrier = $this->styleResolver->inlineGeometryClassName(
            $sourceElement,
            array_diff($this->styleResolver->inlineGeometryProperties(), array_keys($fallbackDeclarations)),
            array_keys($fallbackDeclarations),
            $fallbackDeclarations
        );
        if ( '' !== $carrier ) {
            $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), $carrier);
        }

        return $attrs;
    }

    /**
     * Project the inherited foreground because core/button supplies a default link
     * color. Text alignment uses the same scoped link rule for direct and inherited
     * values, so a local anchor declaration remains authoritative.
     *
     * @param array<string, mixed> $attrs
     */
    private function applyNativeButtonInheritedStyle(DOMElement $anchor, array &$attrs, bool $useInitialTextAlignment): string
    {
        $anchorDeclarations = $this->styleResolver->presentationDeclarations($anchor);
        $anchorColorInherits = ! isset($anchorDeclarations['color']) || $this->isInheritedCssWideValue((string) $anchorDeclarations['color']);
        $anchorTextAlignmentInherits = ! isset($anchorDeclarations['text-align']) || $this->isInheritedCssWideValue((string) $anchorDeclarations['text-align']);
        $inheritedColor = '';
        $inheritedTextAlignment = '';

        for ( $ancestor = $anchor->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode ) {
            $declarations = $this->styleResolver->presentationDeclarations($ancestor);
            if ( '' === $inheritedColor && $anchorColorInherits && isset($declarations['color']) ) {
                $inheritedColor = (string) $declarations['color'];
            }
            if ( '' === $inheritedTextAlignment && $anchorTextAlignmentInherits && isset($declarations['text-align']) ) {
                $inheritedTextAlignment = strtolower(trim((string) $declarations['text-align']));
            }
            if ( '' !== $inheritedColor && '' !== $inheritedTextAlignment ) {
                break;
            }
        }

        if ( '' !== $inheritedColor && ( '' === trim((string) ($attrs['style']['color']['text'] ?? '')) || $this->isInheritedCssWideValue((string) $attrs['style']['color']['text']) ) ) {
            $mappedColor = $this->styleResolver->styleAttributeMapper()->map(array( 'color' => $inheritedColor ))['style']['color']['text'] ?? '';
            if ( '' !== trim((string) $mappedColor) ) {
                $attrs['style']['color']['text'] = $mappedColor;
            }
        }

        $textAlignment = $anchorTextAlignmentInherits
            ? $inheritedTextAlignment
            : strtolower(trim((string) $anchorDeclarations['text-align']));
        if ( '' === $textAlignment || 'initial' === $textAlignment ) {
            return $useInitialTextAlignment ? 'start' : '';
        }
        return in_array($textAlignment, array( 'start', 'end', 'left', 'center', 'right' ), true)
            ? $textAlignment
            : '';
    }

    private function isInheritedCssWideValue(string $value): bool
    {
        return in_array(strtolower(trim($value)), array( 'inherit', 'unset' ), true);
    }

    /** @param array<string, mixed> $attrs */
    private function registerNativeButtonStyleRule(string $marker, array $attrs, string $inheritedTextAlignment = '', ?DOMElement $sourceControl = null): void
    {
        $style = is_array($attrs['style'] ?? null) ? $attrs['style'] : array();
        $declarations = array();
        $wrapperDeclarations = array();
        $outerWrapperDeclarations = array();
        $intrinsicWrapperDeclarations = array();
        foreach ( array(
            'background-color' => $style['color']['background'] ?? '',
            'color'            => $style['color']['text'] ?? '',
            'border-color'     => $style['border']['color'] ?? '',
            'border-style'     => $style['border']['style'] ?? '',
            'border-width'     => $style['border']['width'] ?? '',
            'border-radius'    => $style['border']['radius'] ?? '',
            'font-size'        => $style['typography']['fontSize'] ?? '',
            'font-weight'      => $style['typography']['fontWeight'] ?? '',
            'letter-spacing'   => $style['typography']['letterSpacing'] ?? '',
            'line-height'      => $style['typography']['lineHeight'] ?? '',
            'text-transform'   => $style['typography']['textTransform'] ?? '',
            'padding-top'      => $style['spacing']['padding']['top'] ?? '',
            'padding-right'    => $style['spacing']['padding']['right'] ?? '',
            'padding-bottom'   => $style['spacing']['padding']['bottom'] ?? '',
            'padding-left'     => $style['spacing']['padding']['left'] ?? '',
        ) as $property => $value ) {
            $value = trim((string) $value);
            if ( '' !== $value && ! preg_match('/[{}<>;]/', $value) ) {
                $declarations[] = $property . ':' . $value . '!important';
            }
        }
        if ( $sourceControl instanceof DOMElement ) {
            $sourceDeclarations = $this->styleResolver->cssDeclarations($this->styleResolver->specificityResolvedPresentationStyle($sourceControl));
            $sourceStructuralDeclarations = $this->styleResolver->structuralPresentationDeclarations($sourceControl);
            $inlineDeclarations = $this->styleResolver->cssDeclarations($this->attr($sourceControl, 'style'));
            $hasAuthoredWidth = isset($inlineDeclarations['width'])
                || array() !== $this->styleResolver->authorDeclaredPropertyValues($sourceControl, array( 'width' ));
            if ( ! $hasAuthoredWidth && in_array($this->cssComparableValue((string) ($sourceDeclarations['display'] ?? '')), array( 'flex', 'inline-flex' ), true) ) {
                // Preserve the source flex CTA's content-plus-padding contribution
                // through the synthetic wrappers of its native button topology.
                $outerWrapperDeclarations[] = 'width:max-content';
                $outerWrapperDeclarations[] = 'max-width:100%';
                $intrinsicWrapperDeclarations[] = 'width:max-content';
                $intrinsicWrapperDeclarations[] = 'max-width:100%';
                $declarations[] = 'box-sizing:border-box';
                $declarations[] = 'width:max-content';
                $declarations[] = 'max-width:100%';
            }
            $background = $this->cssComparableValue((string) ($sourceDeclarations['background'] ?? ''));
            if ( '' === trim((string) ($style['color']['background'] ?? '')) && preg_match('/^(?:0(?:px)?(?:\s+0(?:px)?)*|none|transparent)(?:\s+none)?$/', $background) ) {
                $declarations[] = 'background-color:transparent!important';
            }
            $border = $this->cssComparableValue((string) ($sourceDeclarations['border'] ?? ''));
            if ( preg_match('/^(?:0(?:px)?|none)$/', $border) ) {
                if ( '' === trim((string) ($style['border']['style'] ?? '')) ) {
                    $declarations[] = 'border-style:none!important';
                }
                if ( '' === trim((string) ($style['border']['width'] ?? '')) ) {
                    $declarations[] = 'border-width:0!important';
                }
            }
            $height = $this->cssComparableValue((string) ($sourceDeclarations['height'] ?? ''));
            if ( preg_match('/^(?:\d+(?:\.\d+)?|\.\d+)(?:px|em|rem|vh|vw)$/', $height) ) {
                $wrapperDeclarations[] = 'height:100%';
                $declarations[] = 'height:100%!important';
            }
            foreach ( array( 'border-top-left-radius', 'border-top-right-radius', 'border-bottom-right-radius', 'border-bottom-left-radius' ) as $property ) {
                $value = $this->cssComparableValue((string) ($sourceStructuralDeclarations[$property] ?? ''));
                if ( '' !== $value && ! preg_match('/[{}<>;]/', $value) ) {
                    $declarations[] = $property . ':' . $value . '!important';
                }
            }
        }
        if ( '' !== $inheritedTextAlignment ) {
            $declarations[] = 'text-align:' . $inheritedTextAlignment . '!important';
        }
        if ( array() === $declarations ) {
            return;
        }

        $outerWrapperRule = array() === $outerWrapperDeclarations
            ? ''
            : '.' . $marker . '.' . $marker . '.wp-block-buttons{' . implode(';', $outerWrapperDeclarations) . '}';
        $wrapperRule = array() === $wrapperDeclarations
            ? ''
            : '.' . $marker . '.' . $marker . '.wp-block-button{' . implode(';', $wrapperDeclarations) . '}';
        $intrinsicWrapperRule = array() === $intrinsicWrapperDeclarations
            ? ''
            : '.' . $marker . '.' . $marker . '.wp-block-button{' . implode(';', $intrinsicWrapperDeclarations) . '}';
        $this->generatedSupportStyles()->registerNativeButton($marker, $outerWrapperRule . $wrapperRule . $intrinsicWrapperRule . '.' . $marker . '.' . $marker . '>.wp-block-button__link{' . implode(';', $declarations) . '}');
    }

    private function sourceElementStartsHidden(DOMElement $element): bool
    {
        $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
        $display = $this->cssComparableValue((string) ($declarations['display'] ?? ''));
        $visibility = $this->cssComparableValue((string) ($declarations['visibility'] ?? ''));
        $opacity = $this->cssComparableValue((string) ($declarations['opacity'] ?? ''));
        return 'none' === $display
            || in_array($visibility, array( 'hidden', 'collapse' ), true)
            || (is_numeric($opacity) && 0.0 === (float) $opacity);
    }

    private function cssComparableValue(string $value): string
    {
        return strtolower(trim(preg_replace('/\s*!important\s*$/i', '', $value) ?? $value));
    }

    private function hasAuthorSemanticMarker(DOMElement $element): bool
    {
        return array() !== $this->authorSemanticMarkersForElement($element);
    }

    private function sourceProjectionClassName(DOMElement $element, string $className = ''): string
    {
        $sourceTagName = strtolower($element->tagName);
        $sourceTagMarker = $this->authorSelectorProjections()->tagMarker($sourceTagName);
        if ( '' !== $sourceTagMarker ) {
            $className = $this->mergeClassNames($className, $sourceTagMarker);
        }
        if ( $element->parentNode instanceof DOMElement
            && 'body' === strtolower($element->parentNode->tagName)
            && array() !== $this->authorStyles()->sourceBodyProjectionClasses() ) {
            $className = $this->mergeClassNames($className, ...$this->authorStyles()->sourceBodyProjectionClasses());
        }
        $semanticMarkers = $this->authorSemanticMarkersForElement($element);
        if ( array() !== $semanticMarkers ) {
            $className = $this->mergeClassNames($className, ...$semanticMarkers);
        }
        return $className;
    }

    private function sourceAnchorHasNoTextDecoration(DOMElement $anchor): bool
    {
        $decorationLine = null;
        foreach ( $this->styleResolver->cssDeclarations($this->styleResolver->mergedPresentationStyle($anchor)) as $property => $value ) {
            $value = $this->cssComparableValue($this->resolveCssVariablesInValue($value));
            if ( 'text-decoration' === $property ) {
                if ( preg_match('/\b(?:underline|overline|line-through)\b/', $value) ) {
                    $decorationLine = 'line';
                } elseif ( ! in_array($value, array( 'inherit', 'revert', 'revert-layer' ), true) ) {
                    $decorationLine = 'none';
                }
            } elseif ( 'text-decoration-line' === $property ) {
                if ( preg_match('/\b(?:underline|overline|line-through)\b/', $value) ) {
                    $decorationLine = 'line';
                } elseif ( in_array($value, array( 'none', 'initial', 'unset' ), true) ) {
                    $decorationLine = 'none';
                }
            }
        }

        return 'none' === $decorationLine;
    }

    /** @return list<string> */
    private function authorSemanticMarkersForElement(DOMElement $element): array
    {
        $path = $this->sourceElementIdentity($element);
        return $this->authorSelectorProjections()->semanticMarkersForPath($path);
    }

    private function requiresIndependentSemanticWrapper(DOMElement $element): bool
    {
        if ( 'span' !== strtolower($element->tagName) || $this->isRichTextInlineContext($element) ) {
            return false;
        }

        if ( $this->ownsPositioningGeometry($element) ) {
            return true;
        }

        $parent = $element->parentNode instanceof DOMElement ? $element->parentNode : null;
        if ( ! $parent instanceof DOMElement || ! $this->isStructuralLayoutElement($parent) ) {
            return false;
        }

        $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
        // A grid placement belongs to this inline node. Keep phrasing-only grid
        // siblings in one RichText container rather than replacing their direct
        // grid items with Group/Paragraph wrappers.
        if ( 'grid' === strtolower(trim((string) ($this->styleResolver->presentationDeclarations($parent)['display'] ?? ''))) && ( '' !== trim((string) ($declarations['grid-column'] ?? '')) || '' !== trim((string) ($declarations['grid-row'] ?? '')) ) ) {
            return false;
        }
        $display = strtolower(trim((string) ($declarations['display'] ?? 'inline')));
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
        if ( ! $this->isInlineContentElement(strtolower($element->tagName)) || $this->isRichTextInlineContext($element) ) {
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
            return $this->hasResolvedInset($declarations) || $hasZIndex;
        }

        if ( 'relative' === $position ) {
            return $this->hasResolvedInset($declarations) || $hasZIndex;
        }

        return false;
    }

    /** @param array<string, string> $declarations */
    private function hasResolvedInset(array $declarations): bool
    {
        foreach ( array( 'inset', 'inset-block', 'inset-inline', 'inset-block-start', 'inset-block-end', 'inset-inline-start', 'inset-inline-end', 'top', 'right', 'bottom', 'left' ) as $property ) {
            $value = strtolower(trim((string) ($declarations[$property] ?? '')));
            if ( ! in_array($value, array( '', 'auto', 'inherit', 'initial', 'unset', '0', '0px', '0rem', '0em', '0%' ), true) ) {
                return true;
            }
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
        if ( $this->hasAuthorDirectChildSelector($element) ) {
            return $this->positionedInlineHtmlPreservationBlock($element);
        }

        if ( $this->positionedCarrierHasStructuredContent($element) ) {
            $children = $this->positionedInlineCarrierChildren($element, $fallbacks);
            return array() === $children
                ? null
                : $this->createBlock('core/group', $this->positionedInlineCarrierAttributes($element), $children, $element);
        }

        $content = $this->richTextContentWithMaterializedInlineStyles($element);
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

    private function positionedInlineHtmlPreservationBlock(DOMElement $element): array
    {
        $preserved = $element->cloneNode(true);
        if ( $preserved instanceof DOMElement ) {
            $markers = $this->authorSemanticMarkersForElement($element);
            if ( array() !== $markers ) {
                $preserved->setAttribute('class', $this->mergeClassNames($this->attr($preserved, 'class'), ...$markers));
            }
            return $this->htmlPreservationBlock($preserved);
        }

        return $this->htmlPreservationBlock($element);
    }

    private function hasAuthorDirectChildSelector(DOMElement $element): bool
    {
        if ( '' === $this->authorStyles()->combinedCss() ) {
            return false;
        }

        $directChildren = array();
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                $directChildren[] = $child;
            }
        }
        if ( array() === $directChildren ) {
            return false;
        }

        foreach ( $directChildren as $child ) {
            foreach ( $this->authorStyleRuleCandidates($child) as $selector ) {
                $parsed = $selector['direct_child_parsed'];
                $last = count($parsed['compounds'] ?? array()) - 1;
                if ( $parsed['supported'] && $last >= 1 && '>' === ($parsed['combinators'][$last - 1] ?? '')
                    && ($this->sourceStyles()->selectorMatchCache ??= new CssSelectorMatchCache())->matches($child, $selector['selector'], $parsed, true)['matches'] ) {
                    return true;
                }
            }
        }

        return false;
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
            if ( $this->isFormControlElement($child) ) {
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
                || ! $this->isInlineContentElement($tagName)
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

    private function isStructuralLayoutElement(DOMElement $element): bool
    {
        $declarations = array_merge($this->styleResolver->presentationDeclarations($element), $this->authorSemanticDeclarations($element));
        return in_array(strtolower(trim((string) ($declarations['display'] ?? ''))), array( 'flex', 'inline-flex', 'grid', 'inline-grid' ), true);
    }

    /**
     * Author CSS owns a container's geometry when it establishes flex or grid.
     */
    private function isAuthorOwnedLayout(DOMElement $element): bool
    {
        if ( 0 === $this->childElementCount($element) ) {
            return false;
        }
        $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
        $display = strtolower(trim((string) ($declarations['display'] ?? '')));
        if ( in_array($display, array( 'flex', 'inline-flex', 'grid', 'inline-grid' ), true) ) {
            return true;
        }

        foreach ( $this->styleResolver->styleRuleCandidates($element, 'conditional') as $rule ) {
            if ( $this->styleResolver->matchesCssSelector($element, $rule['selector']) && in_array(strtolower(trim((string) ($rule['declarations']['display'] ?? ''))), array( 'flex', 'inline-flex', 'grid', 'inline-grid' ), true) ) {
                return true;
            }
        }

        return false;
    }

    private function authorLayoutLeafSupportsRichText(DOMElement $element): bool
    {
        $supports = function (DOMElement $candidate) use (&$supports): bool {
            $tag = strtolower($candidate->tagName);
            // SVG and img are atomic RichText media. SVG's drawing descendants
            // belong to the materialized image, not to the phrasing-content test.
            if ( in_array($tag, array( 'img', 'svg' ), true) ) {
                return true;
            }
            if ( 'br' !== $tag && ! $this->isInlineContentElement($tag) ) {
                return false;
            }
            foreach ( $candidate->childNodes as $child ) {
                if ( $child instanceof DOMElement && ! $supports($child) ) {
                    return false;
                }
            }
            return true;
        };

        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && ! $supports($child) ) {
                return false;
            }
        }
        return true;
    }


    private function isDirectChildOfAuthorOwnedLayout(DOMElement $element): bool
    {
        return $element->parentNode instanceof DOMElement && $this->isAuthorOwnedLayout($element->parentNode);
    }

    private function isDirectChildOfAuthorFlexLayout(DOMElement $element): bool
    {
        return $element->parentNode instanceof DOMElement
            && in_array($this->authoredDisplay($element->parentNode), array( 'flex', 'inline-flex' ), true);
    }

    private function directFlexButtonStyleRule(string $marker, DOMElement $control): string
    {
        $parent = $control->parentNode;
        $parentStyle = $parent instanceof DOMElement ? $this->styleResolver->structuralPresentationDeclarations($parent) : array();
        $isColumn = str_starts_with(strtolower(trim((string) ($parentStyle['flex-direction'] ?? 'row'))), 'column');
        $wrapper = ':where(.' . $marker . '.wp-block-buttons)';
        $button = ':where(.' . $marker . '.wp-block-buttons)>:where(.' . $marker . '.wp-block-button)';
        $link = $button . '>:where(.wp-block-button__link)';
        $columnGeometry = $isColumn ? ';width:100%!important' : '';

        // The outer core/buttons wrapper is the lowered source flex item, so its
        // authored margins must remain intact. Only core/button is synthetic.
        return $wrapper . '{display:block!important;gap:0!important;min-width:0' . $columnGeometry . '}'
            . $button . '{display:block!important;margin:0!important;min-width:0' . $columnGeometry . '}'
            . $link . '{box-sizing:border-box' . ($isColumn ? ';width:100%!important' : '') . '}';
    }

    private function buttonWidthStyleRule(string $marker, int $width): string
    {
        $wrapper = ':where(.' . $marker . '.wp-block-buttons)';
        $button = ':where(.' . $marker . '.wp-block-buttons)>:where(.' . $marker . '.wp-block-button)';
        $link = $button . '>:where(.wp-block-button__link)';

        if ( 100 !== $width ) {
            return $button . '{width:' . (string) $width . '%!important}'
                . $link . '{box-sizing:border-box;width:100%!important}';
        }

        return $wrapper . '{display:block!important;gap:0!important;width:100%!important}'
            . $button . '{display:block!important;margin:0!important;width:100%!important}'
            . $link . '{box-sizing:border-box;width:100%!important}';
    }

    private function isDirectChildOfStructuralLayout(DOMElement $element): bool
    {
        return $element->parentNode instanceof DOMElement && $this->isStructuralLayoutElement($element->parentNode);
    }

    private function isDirectChildOfLoweredAuthorControl(DOMElement $element): bool
    {
        return $element->parentNode instanceof DOMElement
            && $this->authorSelectorProjections()->isControlPath($element->parentNode->getNodePath() ?? '');
    }

    private function requiresStandaloneInlineLayoutLeaf(DOMElement $element): bool
    {
        if ( ! $this->isInlineContentElement(strtolower($element->tagName))
            || '' === trim($this->runtime->stripAllTags($this->innerHtml($element))) ) {
            return false;
        }

        // Native RichText image objects keep their existing inline paragraph
        // carrier so their media save shape remains editor-valid.
        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement && in_array(strtolower($descendant->tagName), array( 'img', 'svg' ), true) ) {
                return false;
            }
        }

        $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
        $display = strtolower(trim((string) ($declarations['display'] ?? 'inline')));
        if ( 'block' === $display ) {
            return true;
        }

        if ( ! $this->isDirectChildOfStructuralLayout($element) ) {
            return false;
        }

        $typographyProperties = array( 'font', 'font-family', 'font-size', 'font-style', 'font-variant', 'font-weight', 'letter-spacing', 'line-height', 'text-align', 'text-decoration', 'text-indent', 'text-shadow', 'text-transform', 'word-spacing' );
        if ( array() !== $declarations && array() === array_diff(array_keys($declarations), $typographyProperties) ) {
            return true;
        }

        foreach ( array( 'grid-column', 'grid-row', 'order', 'align-self', 'justify-self', 'flex', 'flex-grow', 'flex-shrink', 'flex-basis', 'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left' ) as $property ) {
            if ( $this->cssValueIsNonZero((string) ($declarations[$property] ?? '')) ) {
                return true;
            }
        }

        if ( $this->ancestorElement($element, 'li') instanceof DOMElement ) {
            return false;
        }

        // Selector-addressed phrasing children still need an independent box,
        // but a valid RichText paragraph carrier can host that box directly.
        // Avoid wrapping the paragraph in an otherwise redundant core/group.
        return $this->hasAuthorSemanticMarker($element);
    }

    /** @return array<string, mixed>|null */
    private function inlineLayoutCarrierBlock(DOMElement $element): ?array
    {
        $content = $this->outerHtml($element);
        if ( '' === trim($this->runtime->stripAllTags($content)) ) {
            return null;
        }

        return $this->createBlock('core/paragraph', array(
            'className' => self::INLINE_LAYOUT_CARRIER_CLASS,
            'content' => $content,
            'preserveInlineLayoutLeaf' => true,
        ));
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    private function authorLayoutBlockFromElement(DOMElement $element, array &$fallbacks): array
    {
        $children = $this->convertChildren($element, $fallbacks, true);
        if ( 1 === count($children) ) {
            $coalesced = $this->coalescedSingleGroupWrapper($element, $children[0]);
            if ( null !== $coalesced ) {
                return $coalesced;
            }
        }
        if ( $this->isAuthorOwnedLayout($element) ) {
            $this->transformationEvidence()->recordAuthorLayoutTopology(
                $this->elementSelector($element),
                $this->childElementCount($element),
                count($children),
                $this->directChildTags($element),
                $this->directBlockTags($children)
            );
        }
        return $this->createBlock('core/group', $this->cssOwnedGroupAttributes($element), $children, $element);
    }

    /** @return array<string, mixed> */
    private function cssOwnedGroupAttributes(DOMElement $element): array
    {
        $attrs = $this->styleResolver->presentationAttributes($element);
        $layout = $attrs['layout'] ?? null;
        if ( is_array($layout) && 'grid' === (string) ($layout['type'] ?? '') && '' !== (string) ($layout['minimumColumnWidth'] ?? '') ) {
            // The source track list is exactly expressible as native grid
            // layout, so WordPress owns the track geometry. Group save markup
            // does not serialize blockGap, so source gap remains stylesheet
            // owned by the normalization in createBlock().
            $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
            $style = is_array($attrs['style'] ?? null) ? $attrs['style'] : array();
            unset($style['spacing']['blockGap']);
            if ( empty($style['spacing']) ) {
                unset($style['spacing']);
            }
            $background = trim((string) ($declarations['background-color'] ?? $declarations['background'] ?? ''));
            if ( 1 === preg_match('/^(#[0-9a-f]{3,8}|[a-z][a-z-]*|(?:rgb|rgba|hsl|hsla|hwb|lab|lch|oklab|oklch|var)\([^()]*\))$/i', $background)
                && ! in_array(strtolower($background), array( 'none', 'inherit', 'initial', 'unset', 'revert', 'revert-layer' ), true)
                && ! isset($style['color']['background'])
                && ! $this->styleResolver->hasConditionalStyleFamily($element, 'background')
            ) {
                $style['color'] = array_merge(is_array($style['color'] ?? null) ? $style['color'] : array(), array( 'background' => $background ));
            }
            if ( array() !== $style ) {
                $attrs['style'] = $style;
            }

            return $attrs;
        }

        if ( $this->isCssOwnedGridElement($element) ) {
            return $this->cssOwnedGridAttributes($element);
        }

        if ( $this->isCssOwnedFlexElement($element) ) {
            $attrs = $this->cssOwnedFlexAttributes($element);
        }

        unset($attrs['layout']);
        $attrs['className'] = $this->mergeClassNames(
            (string) ($attrs['className'] ?? ''),
            self::CSS_OWNED_LAYOUT_CLASS
        );
        if ( ! $this->authorOwnsChildFlowSpacing($element) ) {
            return $attrs;
        }
        $attrs['className'] = $this->mergeClassNames(
            (string) $attrs['className'],
            self::CSS_OWNED_FLOW_CLASS
        );
        $attrs['style'] = array_merge(
            is_array($attrs['style'] ?? null) ? $attrs['style'] : array(),
            array( 'spacing' => array( 'blockGap' => '0' ) )
        );

        return $attrs;
    }

    private function isCssOwnedFlexElement(DOMElement $element): bool
    {
        $display = strtolower(trim((string) preg_replace(
            '/\s*!important\s*$/i',
            '',
            (string) ($this->styleResolver->structuralPresentationDeclarations($element)['display'] ?? '')
        )));

        return in_array($display, array( 'flex', 'inline-flex' ), true);
    }

    /**
     * Attributes for a block hosting an author flex container demoted to CSS
     * ownership. The demotion below drops the native `layout` attribute, which
     * was the only thing expressing the flex container, so without carrying the
     * authored `display:flex` the children stack. The inline declarations ride
     * to the generated stylesheet on a carrier class exactly as
     * CSS_OWNED_GRID_CARRIER_PROPERTIES does for grids; class-owned ones are
     * already retained by author stylesheet materialization.
     *
     * An inline display that overrides class-owned layout is already carried
     * complete by inlineGeometryClassName(), at the non-important specificity
     * tier that keeps authored !important rules winning. Forcing those same
     * properties would move them to the !important tier, so that case is left
     * alone.
     *
     * @return array<string, mixed>
     */
    private function cssOwnedFlexAttributes(DOMElement $element): array
    {
        $inlineDeclarations = $this->styleResolver->cssDeclarations($this->attr($element, 'style'));
        // Deliberately the CONFLICT-only predicate, not the wider carrier one.
        // This branch chooses a priority TIER: taking it drops the layout carrier
        // to the non-important tier, which is only sound when the inline display
        // is overriding a different author display. An inline display that merely
        // differs from the tag default has no such guarantee, and demoting it
        // lets any author selector above (0,2,0) win.
        if ( $this->styleResolver->inlineDisplayConflictsWithAuthorLayout($element, $inlineDeclarations) ) {
            return $this->styleResolver->presentationAttributes($element);
        }

        // Carry only the inline-present properties so the fallback to
        // mapper-synthesized declarations cannot invent a `gap` that
        // overrides explicit row-gap/column-gap values.
        $carriedProperties = array_values(array_intersect(self::CSS_OWNED_FLEX_CARRIER_PROPERTIES, array_keys($inlineDeclarations)));

        return $this->styleResolver->presentationAttributes($element, array(), $carriedProperties);
    }

    private function isCssOwnedGridElement(DOMElement $element): bool
    {
        $display = strtolower(trim((string) preg_replace(
            '/\s*!important\s*$/i',
            '',
            (string) ($this->styleResolver->structuralPresentationDeclarations($element)['display'] ?? '')
        )));

        return in_array($display, array( 'grid', 'inline-grid' ), true);
    }

    /**
     * Attributes for a block hosting an author grid that WordPress layout
     * cannot express — asymmetric tracks on groups, or any grid on blocks
     * without grid layout support (core/list). The geometry stays under CSS
     * ownership: inline grid declarations ride to the generated stylesheet on
     * a carrier class, class-owned ones stay retained by author stylesheet
     * materialization. A flow demotion would drop the tracks and stack the
     * items vertically.
     *
     * @return array<string, mixed>
     */
    private function cssOwnedGridAttributes(DOMElement $element): array
    {
        // Carry only the inline-present properties so the fallback to
        // mapper-synthesized declarations cannot invent a `gap` that
        // overrides explicit row-gap/column-gap values.
        $inlineDeclarations = $this->styleResolver->cssDeclarations($this->attr($element, 'style'));
        $carriedProperties = array_values(array_intersect(self::CSS_OWNED_GRID_CARRIER_PROPERTIES, array_keys($inlineDeclarations)));
        $attrs = $this->styleResolver->presentationAttributes($element, array(), $carriedProperties);
        unset($attrs['layout']);
        $attrs['className'] = $this->mergeClassNames(
            (string) ($attrs['className'] ?? ''),
            self::CSS_OWNED_LAYOUT_CLASS,
            self::CSS_OWNED_GRID_CLASS
        );

        return $attrs;
    }

    private function authorOwnsChildFlowSpacing(DOMElement $element): bool
    {
        $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
        foreach ( array( 'gap', 'row-gap', 'column-gap' ) as $property ) {
            if ( '' !== trim((string) ($declarations[$property] ?? '')) ) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, array<string, mixed>> $blocks */
    private function stripOuterAnchorFromBlocks(array &$blocks): void
    {
        foreach ($blocks as &$block) {
            if (! is_array($block)) continue;
            if (isset($block['attrs']['content']) && is_string($block['attrs']['content'])) {
                $content = preg_replace('/^<a\b[^>]*>(.*)<\/a>$/is', '$1', $block['attrs']['content']) ?? $block['attrs']['content'];
                if ($content !== $block['attrs']['content']) $block = $this->rebuildBlock($block, array_merge($block['attrs'], array('content' => $content)));
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
            $tags[] = AuthorLayoutBlockGenerator::NAME === $name ? (string) ($attrs['tagName'] ?? 'div') : ('core/group' === $name ? (string) ($attrs['tagName'] ?? 'div') : ('core/image' === $name ? 'img' : ('core/paragraph' === $name ? 'p' : ('core/heading' === $name ? 'h' . (string) ($attrs['level'] ?? 2) : ''))));
        }
        return $tags;
    }

    /** @return array<string, string> */
    private function authorLayoutSourceAttributes(DOMElement $element): array
    {
        $attributes = array();
        foreach ( $this->htmlAttributes($element) as $name => $value ) {
            if ( ('role' === strtolower($name) || preg_match('/^(?:aria|data)-[a-z0-9_-]+$/i', $name)) && strlen($value) <= 300 ) {
                $attributes[$name] = $value;
            }
        }
        return $attributes;
    }

    /** @param array<string, mixed> $attrs */
    private function authorLayoutHtmlAttributes(array $attrs): string
    {
        $attributes = array();
        if ( '' !== (string) ($attrs['anchor'] ?? '') ) {
            $attributes['id'] = (string) $attrs['anchor'];
        }
        if ( 'a' === ($attrs['tagName'] ?? '') && '' !== (string) ($attrs['url'] ?? '') ) {
            $attributes['href'] = (string) $attrs['url'];
        }
        $classes = array_filter(array( 'wp-block-blocks-engine-author-layout', (string) ($attrs['className'] ?? '') ));
        $attributes['class'] = implode(' ', $classes);
        foreach ( $attrs['sourceAttributes'] ?? array() as $name => $value ) {
            if ( is_string($name) && is_string($value) ) {
                $attributes[$name] = $value;
            }
        }
        $html = '';
        foreach ( $attributes as $name => $value ) {
            $html .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        return $html;
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
        foreach ( $this->styleResolver->styleRuleCandidates($element, 'static') as $rule ) {
            if ( $this->styleResolver->matchesCssSelector($element, $rule['selector']) ) {
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
            if ( '' !== trim($element->textContent ?? '') && in_array(strtolower($parent->tagName), array( 'a', 'button' ), true) && $this->authorSelectorProjections()->isControlPath($parent->getNodePath() ?? '') ) {
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

        return $this->authorSelectorProjections()->richTextMarker($this->sourceElementIdentity($element));
    }

    /**
     * Lift class/style styling hooks out of a block's RichText source so the
     * stored block round-trips through RichText unchanged.
     *
     * Core text blocks store `content`, and core/file stores `fileName`, as
     * RichText. RichText only preserves a fixed set of inline formats (a,
     * strong, em, br, …). A `<span class="…">` / `<span style="…">` is not a format, so
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
     * A list item whose content carries block-level children keeps that topology:
     * its inline hooks still become RichText-safe marks, but no wrapper is
     * hoisted onto the list item.
     *
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function hoistContentWrappingSpans(string $name, array $attrs): array
    {
        $richTextAttribute = 'core/file' === $name ? 'fileName' : 'content';
        if ( ! in_array($name, array( 'core/file', 'core/paragraph', 'core/heading', 'core/list-item' ), true) ) {
            return $attrs;
        }

        $content = (string) ($attrs[$richTextAttribute] ?? '');
        if ( '' === $content || ! preg_match('/<(?:span|font|a|em|i|strong|b|mark|small|sub|sup)\b/i', $content) ) {
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

        $listItemHasBlockContent = 'core/list-item' === $name && $this->hasBlockContentChildren($body);

        $hoistedClasses      = '';
        $hoistedDeclarations = array();

        // Peel a single styling-hook span wrapping the whole content, hoisting it
        // onto the block. A source identity needs to remain on the inline node so
        // author selectors continue to address the saved RichText carrier.
        while ( ! $listItemHasBlockContent && ( $wrapper = $this->soleStylingHookSpan($body) ) instanceof DOMElement ) {
            $wrapperDeclarations = $this->styleResolver->cssDeclarations($this->attr($wrapper, 'style'));
            if ( array() !== $this->richTextSafeIdentityAttributes($wrapper) || isset($wrapperDeclarations['--blocks-engine-richtext-marker']) ) {
                break;
            }
            $hoistedClasses = trim($hoistedClasses . ' ' . $this->attr($wrapper, 'class'));
            $wrapperStyle   = trim($this->attr($wrapper, 'style'));
            if ( '' !== $wrapperStyle ) {
                $hoistedDeclarations = array_merge($hoistedDeclarations, $this->styleResolver->cssDeclarations($wrapperStyle));
            }
            $this->unwrapElement($wrapper);
        }

        // Unwrap any remaining styling hooks (sibling / partial content) unless
        // their visual style can be carried by RichText's mark format.
        foreach ( $this->richTextStylingHookElements($body) as $inline ) {
            if ( 'font' === strtolower($inline->tagName) && ! $inline->hasAttributes() ) {
                $this->unwrapElement($inline);
                continue;
            }
            if ( $this->replaceRichTextStylingHookWithMark($inline) ) {
                continue;
            }
            if ( 'span' === strtolower($inline->tagName) ) {
                $this->unwrapElement($inline);
            }
        }

        foreach ( $this->richTextAnchors($body) as $anchor ) {
            $anchor->removeAttribute('style');
        }

        $newContent = $this->innerHtml($body);
        if ( $newContent === $content && '' === $hoistedClasses && array() === $hoistedDeclarations ) {
            return $attrs;
        }

        $attrs[$richTextAttribute] = $newContent;

        if ( '' !== $hoistedClasses ) {
            $promoted = $this->promotedClassName($hoistedClasses);
            if ( '' !== trim($promoted) ) {
                $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), $promoted);
            }
        }

        if ( array() !== $hoistedDeclarations ) {
            $mapped = $this->styleResolver->styleAttributeMapper()->map($hoistedDeclarations)['style'];
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

    /**
     * A `<span>` whose attributes can be represented by a semantic RichText
     * carrier. Class/id/data identity and inline styles move together onto a
     * `<mark>` so selector hooks survive without storing an invalid span.
     */
    private function isStylingHookSpan(DOMElement $element): bool
    {
        if ( 'span' !== strtolower($element->tagName) ) {
            return false;
        }

        $hasStyling = false;
        foreach ( $element->attributes ?? array() as $attribute ) {
            $attributeName = strtolower($attribute->nodeName);
            if ( ! in_array($attributeName, array( 'class', 'id', 'style', 'data-blocks-engine-richtext-marker' ), true) && ! str_starts_with($attributeName, 'data-') ) {
                return false;
            }
            if ( in_array($attributeName, array( 'class', 'style', 'data-blocks-engine-richtext-marker' ), true) && '' !== trim($attribute->nodeValue ?? '') ) {
                $hasStyling = true;
            }
        }

        if ( $hasStyling ) {
            return true;
        }

        foreach ( $this->styleResolver->styleRuleCandidates($element, 'static') as $rule ) {
            if ( $this->styleResolver->matchesCssSelector($element, $rule['selector']) ) {
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

        if ( 'font' === $tagName ) {
            return true;
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

    /**
     * Source identity that RichText can retain on a semantic inline carrier.
     * Classes, safe ids, and data attributes are selector hooks, unlike an
     * arbitrary inline style that RichText cannot safely round-trip.
     *
     * @return array<string, string>
     */
    private function richTextSafeIdentityAttributes(DOMElement $element): array
    {
        $attributes = array();
        foreach ( $element->attributes ?? array() as $attribute ) {
            $name = strtolower($attribute->nodeName);
            if ( 'class' === $name && '' !== trim($attribute->nodeValue ?? '') ) {
                $attributes['class'] = $attribute->nodeValue ?? '';
            } elseif ( 'id' === $name && '' !== $this->safeAnchor($attribute->nodeValue ?? '') ) {
                $attributes['id'] = $this->safeAnchor($attribute->nodeValue ?? '');
            } elseif ( str_starts_with($name, 'data-') && 'data-blocks-engine-richtext-marker' !== $name ) {
                $attributes[$name] = $attribute->nodeValue ?? '';
            }
        }

        return $attributes;
    }

    private function richTextRequiresHtmlFallback(string $content): bool
    {
        return (bool) preg_match('/<(?:svg|canvas|img|picture|video|audio|iframe|object|embed|input|button|select|textarea|form)\b/i', $content);
    }

    private function richTextContentHasStructuralHtml(string $content): bool
    {
        return (bool) preg_match('/<(?:address|article|aside|blockquote|details|div|dl|figure|h[1-6]|hr|main|menu|nav|ol|p|pre|section|table|ul)\b/i', $content);
    }

    /**
     * @param array<int, string> $excludedTags
     */
    private function richTextContentWithMaterializedInlineStyles(DOMElement $element, array $excludedTags = array()): string
    {
        $content = array() === $excludedTags ? $this->innerHtml($element) : $this->innerHtmlWithoutTags($element, $excludedTags);
        if ( '' === $content || ! preg_match('/<(?:span|font|em|i|strong|b|mark|small|sub|sup)\b/i', $content) ) {
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
            if ( $sourceInline instanceof DOMElement && in_array(strtolower($sourceInline->tagName), array( 'span', 'font', 'em', 'i', 'strong', 'b', 'mark', 'small', 'sub', 'sup' ), true) ) {
                for ( $parent = $sourceInline->parentNode; $parent instanceof DOMElement && $parent !== $element; $parent = $parent->parentNode ) {
                    if ( in_array(strtolower($parent->tagName), $excludedTags, true) ) {
                        continue 2;
                    }
                }
                $sourceInlines[] = $sourceInline;
            }
        }

        $targetInlines = array();
        foreach ( $body->getElementsByTagName('*') as $targetInline ) {
            if ( $targetInline instanceof DOMElement && in_array(strtolower($targetInline->tagName), array( 'span', 'font', 'em', 'i', 'strong', 'b', 'mark', 'small', 'sub', 'sup' ), true) ) {
                $targetInlines[] = $targetInline;
            }
        }

        foreach ( $targetInlines as $index => $targetInline ) {
            $sourceInline = $sourceInlines[$index] ?? null;
            if ( ! $sourceInline instanceof DOMElement ) {
                continue;
            }

            if ( '' === trim($sourceInline->textContent ?? '') && 0 === $this->childElementCount($sourceInline) && ! $this->runtimeIslands->isRuntimeDomTarget($sourceInline) && ! $this->shouldPreserveEmptyVisualElement($sourceInline) ) {
                $targetInline->parentNode?->removeChild($targetInline);
                continue;
            }

            $inline = $this->richTextInlineVisualDeclarations($sourceInline);
            $marker = $this->richTextMarkerForElement($sourceInline);
            if ( '' !== $marker ) {
                $headerCarrier = array_intersect_key($inline, array( 'place-items' => true, 'box-shadow' => true ));
                if ( array() !== $headerCarrier && $this->hasAncestorTag($sourceInline, array( 'header' )) ) {
                    $selector = 'mark[style*="--blocks-engine-richtext-marker:' . $marker . '"]'
                        . ',span[data-blocks-engine-richtext-marker="' . $marker . '"]';
                    $this->generatedSupportStyles()->registerHeaderRichText($marker, $selector . '{' . $this->styleResolver->cssDeclarationString($headerCarrier) . '}');
                }
                $inline['--blocks-engine-richtext-marker'] = $marker;
            }
            if ( array() === $inline ) {
                continue;
            }

            $existing = $this->styleResolver->cssDeclarations($this->attr($targetInline, 'style'));
            $targetInline->setAttribute('style', $this->styleResolver->cssDeclarationString(array_merge($inline, $existing)));
        }

        // Source comments are authoring metadata, not RichText. Gutenberg exposes comments inside
        // editable content as visible text, so remove them while retaining comments elsewhere in
        // the document where they may delimit templates or runtime payloads.
        $xpath = new \DOMXPath($document);
        foreach ( $xpath->query('//body//comment()') ?: array() as $comment ) {
            $comment->parentNode?->removeChild($comment);
        }

        return $this->innerHtml($body);
    }

    /**
     * @return array<string, string>
     */
    private function richTextInlineVisualDeclarations(DOMElement $element): array
    {
        $allowed = array_flip(array(
            '-webkit-background-clip',
            '-webkit-text-fill-color',
            'background',
            'background-clip',
            'background-color',
            'border',
            'border-bottom',
            'border-color',
            'border-left',
            'border-radius',
            'border-right',
            'border-top',
            'box-shadow',
            'color',
            'display',
            'font-family',
            'font-size',
            'font-style',
            'font-weight',
            'letter-spacing',
            'line-height',
            'height',
            'max-height',
            'max-width',
            'margin',
            'margin-bottom',
            'margin-left',
            'margin-right',
            'margin-top',
            'padding',
            'padding-bottom',
            'padding-left',
            'padding-right',
            'padding-top',
            'place-items',
            'text-decoration',
            'text-transform',
            'width',
        ));

        $declarations = $this->styleResolver->cssDeclarations($this->styleResolver->specificityResolvedPresentationStyle($element));
        if ('font' === strtolower($element->tagName)) {
            $color = trim($this->attr($element, 'color'));
            $face = trim($this->attr($element, 'face'));
            $size = trim($this->attr($element, 'size'));
            if ('' !== $color && !isset($declarations['color'])) $declarations['color'] = $color;
            if ('' !== $face && !isset($declarations['font-family'])) $declarations['font-family'] = $face;
            $resolvedSize = $this->legacyFontSize($element);
            if ('' !== $resolvedSize && !isset($declarations['font-size'])) $declarations['font-size'] = $resolvedSize;
        }

        if ( 'transparent' === strtolower((string) ($declarations['-webkit-text-fill-color'] ?? '')) ) {
            $declarations['color'] = 'transparent';
        }

        $declarations = array_intersect_key($declarations, $allowed);
        if ( ! $this->hasAncestorTag($element, array( 'header' )) ) {
            unset($declarations['box-shadow'], $declarations['place-items']);
        }
        if ( in_array(strtolower($element->tagName), array( 'em', 'i' ), true) ) {
            if ( 'italic' === strtolower((string) ($declarations['font-style'] ?? '')) ) {
                unset($declarations['font-style']);
            }
            if ( 'inherit' === strtolower((string) ($declarations['font-weight'] ?? '')) ) {
                unset($declarations['font-weight']);
            }
            foreach ( array( 'margin', 'margin-bottom', 'margin-left', 'margin-right', 'margin-top', 'padding', 'padding-bottom', 'padding-left', 'padding-right', 'padding-top' ) as $property ) {
                if ( isset($declarations[$property]) && ! $this->cssValueIsNonZero($declarations[$property]) ) {
                    unset($declarations[$property]);
                }
            }
        }

        return $declarations;
    }

    private function legacyFontSize(DOMElement $element): string
    {
        $sizes = array('1' => '10px', '2' => '13px', '3' => '16px', '4' => '18px', '5' => '24px', '6' => '32px', '7' => '48px');
        $level = 3;
        $found = false;
        $fonts = array();
        for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode instanceof DOMElement ? $node->parentNode : null) if ('font' === strtolower($node->tagName)) $fonts[] = $node;
        foreach (array_reverse($fonts) as $font) {
            $size = trim($this->attr($font, 'size'));
            if (preg_match('/^[1-7]$/', $size)) {
                $level = (int) $size;
                $found = true;
            } elseif (preg_match('/^[+-]\d+$/', $size)) {
                $level = min(7, max(1, $level + (int) $size));
                $found = true;
            }
        }
        return $found ? $sizes[(string) $level] : '';
    }

    private function replaceRichTextStylingHookWithMark(DOMElement $element): bool
    {
        if ( $element->getElementsByTagName('mark')->length > 0 ) {
            return false;
        }

        $declarations = $this->richTextInlineVisualDeclarations($element);
        $existingDeclarations = $this->styleResolver->cssDeclarations($this->attr($element, 'style'));
        $marker = trim((string) ($existingDeclarations['--blocks-engine-richtext-marker'] ?? ''));
        if ( '' === $marker ) {
            $marker = trim($this->attr($element, 'data-blocks-engine-richtext-marker'));
        }
        if ( '' === $marker && array() === $declarations && array() === $this->richTextSafeIdentityAttributes($element) ) {
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
        foreach ( $this->richTextSafeIdentityAttributes($element) as $name => $value ) {
            $mark->setAttribute($name, $value);
        }
        $mark->setAttribute('style', $this->styleResolver->cssDeclarationString($declarations));
        while ( null !== $element->firstChild ) {
            $mark->appendChild($element->firstChild);
        }

        $parent = $element->parentNode;
        if ( ! $parent instanceof DOMNode ) {
            return false;
        }

        if ( in_array(strtolower($element->tagName), array( 'span', 'font', 'mark' ), true) ) {
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

    /** @param array<int, array<string, mixed>> $blocks @return array<int, array<string, mixed>> */
    private function compressProjectedGroupChains(array $blocks, bool $depthPressure = false): array
    {
        return array_values(array_map(fn (array $block): array => $this->compressProjectedGroupBlock($block, $depthPressure), $blocks));
    }

    /** @param array<string, mixed> $block @return array<string, mixed> */
    private function compressProjectedGroupBlock(array $block, bool $depthPressure = false): array
    {
        $chain = array();
        $cursor = $block;
        while ($this->isSingleGroupShellCandidate($cursor)) {
            $descriptor = $this->groupWrapperDescriptor($cursor);
            if (null === $descriptor) {
                break;
            }
            $chain[] = array('block' => $cursor, 'descriptor' => $descriptor);
            $cursor = $cursor['innerBlocks'][0];
        }

        $branchEndpoint = false;
        $emptyEndpoint = false;
        $cursorChildren = is_array($cursor['innerBlocks'] ?? null) ? $cursor['innerBlocks'] : array();
        if ('core/group' === ($cursor['blockName'] ?? null)
            && 1 < count($cursorChildren)
            && !isset($cursor['_binding_token'])
            && !in_array(strtolower((string) ($cursor['attrs']['tagName'] ?? 'div')), array('ul', 'ol', 'li'), true)
            && null !== ($branchDescriptor = $this->groupWrapperDescriptor($cursor))
        ) {
            $chain[] = array('block' => $cursor, 'descriptor' => $branchDescriptor);
            $terminalBlocks = $this->compressProjectedGroupChains($cursorChildren, $depthPressure);
            $terminal = array();
            $terminalIsShell = false;
            $branchEndpoint = true;
        } elseif ('core/group' === ($cursor['blockName'] ?? null)
            && array() === $cursorChildren
            && !isset($cursor['_binding_token'])
            && !in_array(strtolower((string) ($cursor['attrs']['tagName'] ?? 'div')), array('ul', 'ol', 'li'), true)
            && null !== ($emptyDescriptor = $this->groupWrapperDescriptor($cursor))
        ) {
            $chain[] = array('block' => $cursor, 'descriptor' => $emptyDescriptor);
            $terminalBlocks = array();
            $terminal = array();
            $terminalIsShell = false;
            $emptyEndpoint = true;
        } else {
            $terminal = array() !== $chain ? $this->compressProjectedGroupBlock($cursor, $depthPressure) : $cursor;
            $terminalIsShell = $this->isLayoutShellBlock($terminal);
            $terminalBlocks = $terminalIsShell
                ? $terminal['innerBlocks']
                : array($terminal);
        }
        $projectedCount = count(array_filter($chain, fn (array $entry): bool => $this->hasSourceProjectionClass($entry['block'])));
        $minimumLength = $branchEndpoint ? ($depthPressure ? 2 : 3) : ($emptyEndpoint ? 2 : ($projectedCount === count($chain) ? 2 : 3));
        if ((0 < $projectedCount && $minimumLength <= count($chain)) || (1 === count($chain) && $terminalIsShell && 0 < $projectedCount)) {
            $wrappers = array_column($chain, 'descriptor');
            $terminalRuntimeOwned = $terminalIsShell && !empty($terminal['_editability_runtime_owned']);
            $terminalVisualOwned = $terminalIsShell && !empty($terminal['_editability_visual_owned']);
            if ($terminalIsShell) {
                $wrappers = array_merge($wrappers, is_array($terminal['_layout_shell_wrappers'] ?? null) ? $terminal['_layout_shell_wrappers'] : array());
            }
            $opening = implode('', array_column($wrappers, 'opening'));
            $closing = implode('', array_reverse(array_column($wrappers, 'closing')));
            $provenanceIds = array_values(array_filter(array_map(static fn (array $entry): mixed => $entry['block']['_source_provenance_id'] ?? null, $chain), 'is_int'));
            if ($terminalIsShell) {
                $provenanceIds = array_merge($provenanceIds, is_array($terminal['_source_provenance_ids'] ?? null) ? $terminal['_source_provenance_ids'] : array());
            }
            $blockName = $this->generatedBlocks()->blockName('layout-shell');
            $this->generatedBlocks()->register(LayoutShellBlockGenerator::class, (new LayoutShellBlockGenerator())->definition($blockName));
            return array_filter(array(
                'blockName' => $blockName,
                'attrs' => array('wrappers' => array_map(static fn (array $wrapper): array => array('tagName' => $wrapper['tagName'], 'attributes' => $wrapper['attributes']), $wrappers)),
                'innerBlocks' => $terminalBlocks,
                'innerHTML' => $opening . $closing,
                'innerContent' => array_merge(array($opening), array_fill(0, count($terminalBlocks), null), array($closing)),
                '_source_provenance_ids' => $provenanceIds,
                '_layout_shell_wrappers' => $wrappers,
                '_editability_runtime_owned' => (bool) array_filter($chain, static fn (array $entry): bool => !empty($entry['block']['_editability_runtime_owned'])) || $terminalRuntimeOwned,
                '_editability_visual_owned' => (bool) array_filter($chain, static fn (array $entry): bool => !empty($entry['block']['_editability_visual_owned'])) || $terminalVisualOwned,
            ), static fn (mixed $value): bool => false !== $value && array() !== $value);
        }

        if (is_array($block['innerBlocks'] ?? null)) {
            $block['innerBlocks'] = $this->compressProjectedGroupChains($block['innerBlocks'], $depthPressure);
        }
        return $block;
    }

    /** @param array<int,array<string,mixed>> $blocks */
    private function blockTreeDepth(array $blocks): int
    {
        $maximum = 0;
        foreach ($blocks as $block) {
            if (!is_array($block)) continue;
            $children = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array();
            $maximum = max($maximum, 1 + $this->blockTreeDepth($children));
        }
        return $maximum;
    }

    /** @param array<string, mixed> $block */
    private function isLayoutShellBlock(array $block): bool
    {
        return str_ends_with((string) ($block['blockName'] ?? ''), '/layout-shell')
            && 0 < count(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array());
    }

    /** @param array<string, mixed> $block */
    private function isSingleGroupShellCandidate(array $block): bool
    {
        if ('core/group' !== ($block['blockName'] ?? null)
            || 1 !== count(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array())
            || isset($block['_binding_token'])
            || in_array(strtolower((string) ($block['attrs']['tagName'] ?? 'div')), array('ul', 'ol', 'li'), true)
        ) {
            return false;
        }
        return true;
    }

    /** @param array<string, mixed> $block */
    private function hasSourceProjectionClass(array $block): bool
    {
        return (bool) preg_match('/(?:^|\s)blocks-engine-(?:attribute|css-owned|editor-anchor|semantic|source)-/', (string) ($block['attrs']['className'] ?? ''));
    }

    /** @param array<string, mixed> $block @return array{tagName: string, attributes: array<string, string>, opening: string, closing: string}|null */
    private function groupWrapperDescriptor(array $block): ?array
    {
        $content = is_array($block['innerContent'] ?? null) ? $block['innerContent'] : array();
        $opening = is_string($content[0] ?? null) ? $content[0] : '';
        $closing = is_string($content[array_key_last($content)] ?? null) ? $content[array_key_last($content)] : '';
        if (! preg_match('/^<([a-z][a-z0-9-]*)\b/i', $opening, $match) || '' === $closing) {
            return null;
        }
        $tagName = strtolower($match[1]);
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $opening . $closing . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $element = $loaded ? $document->getElementsByTagName($tagName)->item(0) : null;
        if (! $element instanceof DOMElement) {
            return null;
        }
        $attributes = array();
        foreach ($element->attributes ?? array() as $attribute) {
            $attributes[strtolower($attribute->nodeName)] = (string) $attribute->nodeValue;
        }
        // Core serializes style declarations differently from React's save path
        // (notably unitless zero lengths). Keep styled wrappers as core groups.
        if ('' !== trim((string) ($attributes['style'] ?? ''))) {
            return null;
        }
        return array('tagName' => $tagName, 'attributes' => $attributes, 'opening' => $opening, 'closing' => $closing);
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceProvenanceEntry(string $blockName, DOMElement $element): array
    {
        $sourceHtml = $this->safeFallbackHtml($element);
        return array_merge(array(
            'block_name'        => $blockName,
            'tag'               => strtolower($element->tagName),
            'selector'          => $this->elementSelector($element),
            'source_attributes' => $this->safeSourceAttributes($element),
            'source_fragment'   => $this->safeSourceFragment($element),
            'source_digest'     => hash('sha256', $sourceHtml),
            'source_bytes'      => strlen($sourceHtml),
            'source_path'       => $this->transformationProvenance()->fallback()['source'] ?? '',
            'context'           => $this->sourceContext($element),
        ), $this->sourceConversionMetadata($blockName, $element), $this->navigationSourceOwnership($blockName, $element));
    }

    /** @return array<string, array<string, array<string, string>>> */
    private function navigationSourceOwnership(string $blockName, DOMElement $element): array
    {
        if ( ! in_array($blockName, array( 'core/navigation-link', 'core/navigation-submenu' ), true) ) {
            return array();
        }

        $anchor = 'a' === strtolower($element->tagName) ? $element : null;
        $submenu = null;
        if ( 'core/navigation-submenu' === $blockName ) {
            foreach ( $element->childNodes as $child ) {
                if ( ! $child instanceof DOMElement ) {
                    continue;
                }
                if ( 'a' === strtolower($child->tagName) && ! $anchor instanceof DOMElement ) {
                    $anchor = $child;
                }
                if ( 'a' !== strtolower($child->tagName)
                    && 0 < $child->getElementsByTagName('a')->length
                    && ! $submenu instanceof DOMElement
                ) {
                    $submenu = $child;
                }
            }
        }

        $ownership = array();
        foreach ( array( 'anchor' => $anchor, 'submenu' => $submenu ) as $kind => $source ) {
            if ( ! $source instanceof DOMElement ) {
                continue;
            }
            $ownership[$kind] = array(
                'selector'   => $this->elementSelector($source),
                'class_name' => trim($this->attr($source, 'class')),
            );
        }

        return array() === $ownership ? array() : array( 'navigation_source_ownership' => $ownership );
    }

    /** @return list<string> */
    private function navigationSourceOwnershipClasses(array $entry, string $kind): array
    {
        $className = (string) ($entry['navigation_source_ownership'][$kind]['class_name'] ?? '');
        return array_values(array_filter(preg_split('/\s+/', trim($className)) ?: array()));
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

        if ( $this->runtimeIslands->isRuntimeDomTarget($element) ) {
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
        if ( '' === trim($className) || ! $this->sourceStyles()->hasClassPromotions() ) {
            return $this->styleResolver->presentationClassName($className);
        }

        $classes = preg_split('/\s+/', trim($className)) ?: array();
        foreach ( $classes as $class ) {
            foreach ( $this->sourceStyles()->classPromotions($class) as $terminalClass ) {
                if ( ! in_array($terminalClass, $classes, true) ) {
                    $classes[] = $terminalClass;
                }
            }
        }

        return $this->styleResolver->presentationClassName(implode(' ', $classes));
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

        $this->transformationProvenance()->recordPresentationSignal(array(
            'block_name'        => $blockName,
            'tag'               => strtolower($element->tagName),
            'selector'          => $this->elementSelector($element),
            'signals'           => $signals,
            'source_attributes' => array_intersect_key($this->htmlAttributes($element), array_flip(array( 'class', 'style', 'data-layout', 'data-wp-layout' ))),
        ));
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

        $this->transformationProvenance()->recordStructureSignal(array(
            'block_name'        => $blockName,
            'tag'               => strtolower($element->tagName),
            'selector'          => $this->elementSelector($element),
            'signals'           => $signals,
            'source_attributes' => array_intersect_key($this->htmlAttributes($element), array_flip(array( 'class', 'id', 'role', 'style', 'data-layout', 'data-wp-layout' ))),
        ));
    }

    private function shouldPreserveWrapper(DOMElement $element): bool
    {
        return ShellLandmarkPolicy::isWrapperPreservingTag($element->tagName) && ( $this->runtimeIslands->isRuntimeDomTarget($element) || $this->hasAuthorSemanticMarker($element) || array() !== $this->styleResolver->presentationAttributes($element) || array() !== $this->structureSignals($element, array()) );
    }

    /** @param array<string, mixed> $childBlock @return array<string, mixed>|null */
    private function coalescedSingleGroupWrapper(DOMElement $element, array $childBlock): ?array
    {
        $proof = $this->layoutGeometryProofFor($element);
        $fullWidthTransparentShell = $this->hasOnlyFullWidthTransparentInlineGeometry($element);
        $redundantNestedLayout = $this->isRedundantNestedLayoutWrapper($element, $childBlock);
        if ( 'div' !== strtolower($element->tagName)
            || ! in_array($childBlock['blockName'] ?? null, array('core/group', 'core/image'), true)
            || ($fullWidthTransparentShell && 'core/group' !== ($childBlock['blockName'] ?? null))
            || $this->runtimeIslands->isRuntimeDomTarget($element)
            || (null === $proof && $this->isDirectChildOfStructuralLayout($element))
            || '' !== trim($this->attr($element, 'id'))
            || '' !== trim($this->attr($element, 'role'))
            || (null === $proof && ! $fullWidthTransparentShell && ! $this->hasOnlyRenderNeutralInlineGeometry($element) && ! $redundantNestedLayout)
            || array() !== $this->interactiveAttributes($element)
            || (null === $proof && array() !== $this->safeDataAttributes($element))
            || (null === $proof && array() !== $this->structureSignals($element, array()) && ! $redundantNestedLayout)
            || $this->hasMotionStructureToken($element)
        ) {
            return null;
        }

        $attrs = $this->styleResolver->presentationAttributes($element);
        if ( ! $redundantNestedLayout && array_diff(array_keys($attrs), array( 'className', 'style' )) ) {
            return null;
        }

        $provenanceId = $childBlock['_source_provenance_id'] ?? null;
        $sourceChild = is_int($provenanceId) ? $this->sameSourceGroupChainLeaf($element, (string) ($this->transformationProvenance()->source($provenanceId)['source_digest'] ?? '')) : null;
        if (! $sourceChild instanceof DOMElement && 'core/image' === ($childBlock['blockName'] ?? null)) $sourceChild = $this->imageLeafInGroupChain($element);
        if ( ! $sourceChild instanceof DOMElement
            || ('core/image' === ($childBlock['blockName'] ?? null) && ! in_array(strtolower($sourceChild->tagName), array( 'img', 'svg' ), true) && ! str_contains($sourceChild->tagName, '-'))
            || $this->hasMotionStructureToken($sourceChild)
            || (null === $proof && ! $redundantNestedLayout && ($fullWidthTransparentShell ? ! $this->hasOnlyFullWidthTransparentBoxAffectingDeclarations($element) : ! $this->hasOnlyRenderNeutralBoxAffectingDeclarations($element)))
            || (null === $proof && $fullWidthTransparentShell && ! $this->isNormalFlowFullWidthShellChild($sourceChild))
            || ('core/image' !== ($childBlock['blockName'] ?? null) && ! $redundantNestedLayout && $this->hasContainingBlockDependentAuthorDeclarations($sourceChild))
            || (null === $proof && ! $this->syntheticImageGeometryLeaf($childBlock) && ! $this->selectorMatchingSurvivesWrapperCoalescing($element, $sourceChild, $fullWidthTransparentShell))
        ) {
            return null;
        }

        $childAttrs = is_array($childBlock['attrs'] ?? null) ? $childBlock['attrs'] : array();
        $childAttrs['className'] = null === $proof
            ? $this->mergeClassNames((string) ($attrs['className'] ?? ''), (string) ($childAttrs['className'] ?? ''), ...$this->classNames($element))
            : $this->mergeClassNames((string) ($childAttrs['className'] ?? ''), $this->layoutGeometryProofCarrier($proof));
        $childAttrs = array_filter($childAttrs, static fn (mixed $value): bool => ! is_string($value) || '' !== trim($value));
        if (null !== $proof) $this->layoutGeometry()->recordProof($proof);

        return $this->createBlock((string) $childBlock['blockName'], $childAttrs, $childBlock['innerBlocks'] ?? array(), $sourceChild);
    }

    /** @return array<string,mixed>|null */
    private function layoutGeometryProofFor(DOMElement $element): ?array
    {
        foreach ($this->layoutGeometry()->proofReductions() as $proof) {
            // The normalizer binds the document digest. This lookup uses the
            // canonical structural selector, not a reusable author class.
            if (!is_array($proof) || $this->elementSelector($element) !== ($proof['wrapper_selector'] ?? null)) continue;
            $child = $this->soleElementChild($element);
            if ($child instanceof DOMElement && $this->elementSelector($child) === ($proof['target_selector'] ?? null)) return $proof;
        }
        return null;
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
        $proof = $this->layoutGeometryProofFor($element);
        if (null === $proof || $this->runtimeIslands->isRuntimeDomTarget($element) || '' !== trim($this->attr($element, 'id')) || '' !== trim($this->attr($element, 'role')) || array() !== $this->interactiveAttributes($element) || $this->hasMotionStructureToken($element)) return null;
        $children = $this->convertChildren($element, $fallbacks, true);
        if (1 !== count($children) || !in_array($children[0]['blockName'] ?? null, array('core/group', 'core/image'), true)) return null;
        $sourceChild = $this->soleElementChild($element);
        if (!$sourceChild instanceof DOMElement || $this->elementSelector($sourceChild) !== ($proof['target_selector'] ?? null)) return null;
        $childAttrs = is_array($children[0]['attrs'] ?? null) ? $children[0]['attrs'] : array();
        $childAttrs['className'] = $this->mergeClassNames((string) ($childAttrs['className'] ?? ''), $this->layoutGeometryProofCarrier($proof));
        $childAttrs = array_filter($childAttrs, static fn (mixed $value): bool => !is_string($value) || '' !== trim($value));
        $this->layoutGeometry()->recordProof($proof);
        return $this->createBlock((string) $children[0]['blockName'], $childAttrs, $children[0]['innerBlocks'] ?? array(), $sourceChild);
    }

    /** @param array<string,mixed> $proof */
    private function layoutGeometryProofCarrier(array $proof): string
    {
        $declarations = $proof['corrective_css']['declarations'] ?? array();
        if (!is_array($declarations)) return '';
        $parts = array();
        foreach ($declarations as $declaration) if (is_array($declaration)) $parts[] = $declaration['property'] . ':' . $declaration['value'];
        if (array() === $parts) return '';
        $className = 'be-layout-proof-' . substr(hash('sha256', (string) $proof['source_hash'] . "\n" . (string) $proof['wrapper_selector'] . "\n" . implode(';', $parts)), 0, 32);
        $this->layoutGeometry()->registerRule($className, ':root .' . $className . '{' . implode(';', $parts) . '}');
        return $className;
    }

    private function sameSourceGroupChainLeaf(DOMElement $element, string $sourceDigest): ?DOMElement
    {
        if ( '' === $sourceDigest ) {
            return null;
        }

        $child = $this->soleElementChild($element);
        while ( $child instanceof DOMElement && hash('sha256', $this->safeFallbackHtml($child)) !== $sourceDigest ) {
            // A native image block may take its source provenance from the img
            // while retaining an image-only anchor as block attributes.
            $anchorChild = 'a' === strtolower($child->tagName) ? $this->soleElementChild($child) : null;
            if ( $anchorChild instanceof DOMElement
                && 'a' === strtolower($child->tagName)
                && ($this->isImageOnlyAnchor($child) || in_array(strtolower($anchorChild->tagName), array('img', 'picture'), true))
            ) {
                $child = $anchorChild;
                continue;
            }
            if ( ! $this->isNeutralGroupChainWrapper($child) ) {
                return null;
            }
            $child = $this->soleElementChild($child);
        }

        return $child;
    }

    /** @param array<string,mixed> $block */
    private function syntheticImageGeometryLeaf(array $block): bool
    {
        $className = (string) ($block['attrs']['className'] ?? '');
        return 'core/image' === ($block['blockName'] ?? null)
            && str_contains($className, self::SYNTHETIC_IMAGE_FIGURE_CLASS)
            && (bool) preg_match('/(?:^|\s)be-inline-geometry-[a-f0-9-]+(?:\s|$)/', $className);
    }

    private function imageLeafInGroupChain(DOMElement $element): ?DOMElement
    {
        for ($child = $this->soleElementChild($element); $child instanceof DOMElement; $child = $this->soleElementChild($child)) {
            $tagName = strtolower($child->tagName);
            if (in_array($tagName, array('img', 'svg'), true)) return $child;
            // Captured media exports commonly place their native image behind a
            // passive custom-element carrier. Its own conversion already proves
            // it has no retained block boundary. Use the carrier as the source
            // leaf so selector survival is checked against its actual identity.
            if (str_contains($tagName, '-')) {
                $mediaChild = $this->soleElementChild($child);
                if ($mediaChild instanceof DOMElement && in_array(strtolower($mediaChild->tagName), array('img', 'svg'), true)) return $child;
            }
            if (! in_array($tagName, array('div', 'a'), true) && ! str_contains($tagName, '-')) return null;
        }
        return null;
    }

    private function isNeutralGroupChainWrapper(DOMElement $element): bool
    {
        if ( 'div' !== strtolower($element->tagName)
            || $this->runtimeIslands->isRuntimeDomTarget($element)
            || $this->isDirectChildOfStructuralLayout($element)
            || '' !== trim($this->attr($element, 'id'))
            || '' !== trim($this->attr($element, 'role'))
            || ! $this->hasOnlyRenderNeutralInlineGeometry($element)
            || array() !== $this->interactiveAttributes($element)
            || array() !== $this->safeDataAttributes($element)
            || array() !== $this->structureSignals($element, array())
            || $this->hasMotionStructureToken($element)
            || ! $this->hasOnlyRenderNeutralBoxAffectingDeclarations($element)
        ) {
            return false;
        }

        $attrs = $this->styleResolver->presentationAttributes($element);
        return ! array_diff(array_keys($attrs), array( 'className', 'style' )) && $this->soleElementChild($element) instanceof DOMElement;
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

    private function hasMotionStructureToken(DOMElement $element): bool
    {
        $identity = strtolower($this->attr($element, 'class') . ' ' . $this->attr($element, 'id'));
        return (bool) preg_match('/(?:^|[^a-z0-9])(?:band|carousel|loop|marquee|mask|rail|scroller|slider|ticker|track|viewport)(?:[^a-z0-9]|$)/', $identity);
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
        foreach ( $this->authorStyleRuleCandidates($element) as $candidate ) {
            $ruleOrder = (int) $candidate['rule_order'];
            if ( isset($matchedRules[$ruleOrder])
                || ! ($candidate['parsed']['supported'] ?? false)
                || null !== ($candidate['parsed']['pseudo_state_suffix_span'] ?? null)
                || ! ($this->sourceStyles()->selectorMatchCache ??= new CssSelectorMatchCache())->matches($element, $candidate['selector'], $candidate['parsed'])['matches']
            ) {
                continue;
            }
            $matchedRules[$ruleOrder] = true;
            $declarations = array_filter(
                $candidate['declarations'],
                fn (string $value, string $property): bool => $this->isNonNeutralVisualTopologyDeclaration($property, $value),
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

    private function isNonNeutralVisualTopologyDeclaration(string $property, string $value): bool
    {
        $value = strtolower(trim(preg_replace('/\s*!important\s*$/i', '', $value) ?? $value));
        if ( '' === $value || in_array($value, array( 'auto', 'none', 'normal', 'static', 'visible', 'transparent', 'inherit', 'initial', 'revert', 'revert-layer', 'unset' ), true) ) {
            return false;
        }
        if ( in_array($property, array( 'color', 'font-family', 'font-size', 'font-style', 'font-weight', 'letter-spacing', 'line-height', 'text-align', 'text-decoration' ), true) ) {
            return false;
        }
        return 1 === preg_match('/^(?:align-|appearance|aspect-ratio|background|border|bottom|box-shadow|column|contain|cursor|display|filter|flex|gap|grid|height|inset|isolation|left|margin|max-|min-|opacity|outline|overflow|padding|perspective|position|right|row-gap|table-layout|top|transform|vertical-align|width|z-index)/', $property);
    }

    /**
     * A sole nested flex/grid wrapper is redundant when it only restates display
     * and the child group already carries its own geometry carrier.
     *
     * @param array<string, mixed> $childBlock
     */
    private function isRedundantNestedLayoutWrapper(DOMElement $element, array $childBlock): bool
    {
        if ( 'core/group' !== ($childBlock['blockName'] ?? null) ) {
            return false;
        }

        $childClass = (string) ($childBlock['attrs']['className'] ?? '');
        if ( ! str_contains($childClass, 'blocks-engine-css-owned-layout')
            || ! (bool) preg_match('/(?:^|\s)be-inline-geometry-[a-f0-9-]+(?:\s|$)/', $childClass)
        ) {
            return false;
        }

        if ( '' !== trim($this->attr($element, 'class')) ) {
            return false;
        }

        $declarations = $this->styleResolver->cssDeclarations($this->attr($element, 'style'));
        $display = strtolower(trim($this->cssValueWithoutImportant((string) ($declarations['display'] ?? ''))));
        if ( ! in_array($display, array( 'flex', 'inline-flex', 'grid', 'inline-grid' ), true) ) {
            return false;
        }

        unset($declarations['display']);
        foreach ( $declarations as $property => $value ) {
            if ( ! $this->isRenderNeutralGeometryDeclaration($property, $value) ) {
                return false;
            }
        }

        return $this->hasOnlyRenderNeutralBoxAffectingDeclarationMap(
            array_diff_key($this->matchingAuthorDeclarations($element), array( 'display' => true ))
        );
    }

    private function hasOnlyRenderNeutralInlineGeometry(DOMElement $element): bool
    {
        foreach ($this->styleResolver->cssDeclarations($this->attr($element, 'style')) as $property => $value) {
            if (! $this->isRenderNeutralGeometryDeclaration($property, $value)) return false;
        }
        return true;
    }

    private function hasOnlyFullWidthTransparentInlineGeometry(DOMElement $element): bool
    {
        $declarations = $this->styleResolver->cssDeclarations($this->attr($element, 'style'));
        if ( '100%' !== strtolower(trim($this->cssValueWithoutImportant((string) ($declarations['width'] ?? '')))) ) {
            return false;
        }
        unset($declarations['width']);
        foreach ($declarations as $property => $value) {
            if (! $this->isRenderNeutralGeometryDeclaration($property, $value)) {
                return false;
            }
        }
        return true;
    }

    private function hasOnlyFullWidthTransparentBoxAffectingDeclarations(DOMElement $element): bool
    {
        $declarations = $this->matchingAuthorDeclarations($element);
        if ( '100%' !== strtolower(trim($this->cssValueWithoutImportant((string) ($declarations['width'] ?? '')))) ) {
            return false;
        }
        unset($declarations['width']);
        return $this->hasOnlyRenderNeutralBoxAffectingDeclarationMap($declarations);
    }

    private function isNormalFlowFullWidthShellChild(DOMElement $element): bool
    {
        if ( $this->hasContainingBlockDependentAuthorDeclarations($element) ) {
            return false;
        }
        $declarations = $this->styleResolver->presentationDeclarations($element);
        return ! isset($declarations['width'])
            && ! isset($declarations['min-width'])
            && ! isset($declarations['max-width']);
    }

    private function hasOnlyRenderNeutralBoxAffectingDeclarations(DOMElement $element): bool
    {
        return $this->hasOnlyRenderNeutralBoxAffectingDeclarationMap($this->matchingAuthorDeclarations($element));
    }

    /** @param array<string,string> $declarations */
    private function hasOnlyRenderNeutralBoxAffectingDeclarationMap(array $declarations): bool
    {
        foreach ($declarations as $property => $value) {
            if (! preg_match('/^(?:align-content|align-items|align-self|background|border|bottom|column|contain|display|filter|flex|float|gap|grid|height|inset|isolation|left|margin|max-|min-|opacity|outline|overflow|padding|perspective|position|right|row-gap|top|transform|width|z-index)/', $property)) continue;
            if (! $this->isRenderNeutralGeometryDeclaration($property, $value)) return false;
        }
        return true;
    }

    private function isRenderNeutralGeometryDeclaration(string $property, string $value): bool
    {
        $value = strtolower(trim($this->cssValueWithoutImportant($value)));
        if (preg_match('/^(?:margin|padding)(?:-(?:top|right|bottom|left))?$/', $property)) return in_array($value, array('0', '0px', '0em', '0rem', '0%'), true);
        if (str_starts_with($property, 'border') || 'outline' === $property) return in_array($value, array('0', '0 none', 'none'), true);
        return 'text-align' === $property && 'left' === $value;
    }

    /** @param array<string,string> $declarations */
    private function hasOnlyRenderNeutralDeclarations(array $declarations): bool
    {
        foreach ($declarations as $property => $value) if (! $this->isRenderNeutralGeometryDeclaration($property, $value)) return false;
        return array() !== $declarations;
    }

    private function hasContainingBlockDependentAuthorDeclarations(DOMElement $element): bool
    {
        $declarations = $this->matchingAuthorDeclarations($element);
        foreach ( array_keys($declarations) as $property ) {
            if ( preg_match('/^(?:align-self|bottom|flex|float|grid-column|grid-row|height|inset|left|margin|max-height|max-width|min-height|min-width|order|position|right|top|transform|width)$/', $property) ) {
                return true;
            }
        }
        $display = strtolower(trim((string) ($declarations['display'] ?? '')));
        return '' !== $display && ! in_array($display, array( 'block', 'flow-root' ), true);
    }

    /** @return array<string, string> */
    private function matchingAuthorDeclarations(DOMElement $element): array
    {
        $declarations = $this->styleResolver->presentationDeclarations($element);
        $matchedRules = array();
        foreach ( $this->authorStyleRuleCandidates($element) as $selector ) {
            $ruleOrder = $selector['rule_order'];
            if ( isset($matchedRules[$ruleOrder]) || ! $selector['parsed']['supported'] ) {
                continue;
            }
            if ( ($this->sourceStyles()->selectorMatchCache ??= new CssSelectorMatchCache())->matches($element, $selector['selector'], $selector['parsed'], true)['matches'] ) {
                $matchedRules[$ruleOrder] = true;
                $declarations = $this->styleResolver->mergeCssDeclarationMaps($declarations, $selector['declarations']);
            }
        }
        return $declarations;
    }

    /** @return list<array{key: string, selector: string, parsed: array<string, mixed>, direct_child_parsed: array<string, mixed>, declarations: array<string, string>, rule_order: int}> */
    private function authorStyleRuleCandidates(DOMElement $element): array
    {
        $index = $this->authorStyles()->styleRuleCandidateIndex();
        return ($this->sourceStyles()->selectorMatchCache ??= new CssSelectorMatchCache())->styleRuleCandidates($element, 'author-rules', $index);
    }

    private function selectorMatchingSurvivesWrapperCoalescing(DOMElement $element, DOMElement $child, bool $exact = false): bool
    {
        $parent = $element->parentNode;
        if ( ! $parent instanceof DOMElement ) {
            return false;
        }

        $chain = array();
        for ( $node = $child; $node instanceof DOMElement; $node = $node->parentNode instanceof DOMElement ? $node->parentNode : null ) {
            $chain[] = $node;
            if ( $node === $element ) {
                break;
            }
        }
        if ( $element !== end($chain) ) {
            return false;
        }

        $beforeCandidatesByKey = array();
        foreach ( $chain as $node ) {
            foreach ( $this->authorStyleRuleCandidates($node) as $selector ) {
                $beforeCandidatesByKey[$selector['key']] = $selector;
            }
        }
        $beforeCandidates = array_values($beforeCandidatesByKey);
        $matchesBefore = array();
        foreach ( $beforeCandidates as $selector ) {
            $matchesBefore[$selector['key']] = $selector['parsed']['supported'] && (bool) array_filter(
                $chain,
                fn (DOMElement $node): bool => ($this->sourceStyles()->selectorMatchCache ??= new CssSelectorMatchCache())->matches($node, $selector['selector'], $selector['parsed'], true)['matches']
            );
        }

        $childClass = $this->attr($child, 'class');
        $chainClasses = array_map(fn (DOMElement $node): string => $this->attr($node, 'class'), $chain);
        $childParent = $child->parentNode;
        $childNextSibling = $child->nextSibling;
        $parent->insertBefore($child, $element);
        $parent->removeChild($element);
        $child->setAttribute('class', $this->mergeClassNames(...$chainClasses));
        $this->styleResolver->invalidateSourceSelectorMatchCache();

        $survives = true;
        $afterCandidates = $this->authorStyleRuleCandidates($child);
        $candidates = array();
        foreach ( array_merge($beforeCandidates, $afterCandidates) as $selector ) {
            $candidates[$selector['key']] = $selector;
        }
        foreach ( $candidates as $key => $selector ) {
            $matchesAfter = $selector['parsed']['supported']
                && ($this->sourceStyles()->selectorMatchCache ??= new CssSelectorMatchCache())->matches($child, $selector['selector'], $selector['parsed'], true)['matches'];
            if ( ($matchesBefore[$key] ?? false) !== $matchesAfter && ($exact || ! $this->hasOnlyRenderNeutralDeclarations($selector['declarations'])) ) {
                $survives = false;
                break;
            }
        }

        $parent->insertBefore($element, $child);
        $parent->removeChild($child);
        if ( $childParent instanceof DOMNode ) {
            $childParent->insertBefore($child, $childNextSibling);
        }
        if ( '' === $childClass ) {
            $child->removeAttribute('class');
        } else {
            $child->setAttribute('class', $childClass);
        }
        $this->styleResolver->invalidateSourceSelectorMatchCache();

        return $survives;
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
        if ( '' !== $this->renderedTextContent($element) ) {
            return false;
        }

        if ( $this->isInertHiddenEmptyElement($element) ) {
            return false;
        }

        if ( $this->hasSubstantiveSourceDescendant($element) && $this->shouldPreserveWrapper($element) ) {
            return true;
        }

        if ( $this->isInlineContentElement(strtolower($element->tagName)) ) {
            return $this->runtimeIslands->isRuntimeDomTarget($element)
                || in_array(strtolower($this->attr($element, 'role')), array( 'presentation', 'none' ), true)
                || 'true' === strtolower($this->attr($element, 'aria-hidden'))
                || $this->isEmptyVisualInlineCandidate($element);
        }

        if ( $this->runtimeIslands->isRuntimeDomTarget($element)
            || '' !== trim($this->attr($element, 'id'))
            || '' !== trim($this->attr($element, 'role'))
            || array() !== $this->interactiveAttributes($element)
            || array() !== $this->safeDataAttributes($element)
            || array() !== $this->structureSignals($element, array())
            || $this->hasRenderableEmptyBlockBox($element)
            || $this->hasStaticPseudoElementRule($element)
        ) {
            return true;
        }

        return $this->isEmptyVisualInlineCandidate($element);
    }

    private function hasSubstantiveSourceDescendant(DOMElement $element): bool
    {
        if ( '' !== $this->renderedTextContent($element) ) {
            return true;
        }
        foreach ( array( 'audio', 'button', 'canvas', 'form', 'iframe', 'img', 'input', 'object', 'picture', 'select', 'svg', 'textarea', 'video' ) as $tagName ) {
            if ( 0 < $element->getElementsByTagName($tagName)->length ) {
                return true;
            }
        }
        return false;
    }

    private function hasRenderableEmptyBlockBox(DOMElement $element): bool
    {
        $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
        foreach ( array( 'height', 'min-height', 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left' ) as $property ) {
            if ( isset($declarations[$property]) && $this->isPositiveCssLength($this->resolveCssVariablesInValue($declarations[$property], $element)) ) {
                return true;
            }
        }
        if ( $this->hasVisibleEmptyVisualPaint($declarations, $element) ) {
            return true;
        }
        $position = strtolower(trim((string) ($declarations['position'] ?? 'static')));
        return in_array($position, array( 'absolute', 'fixed' ), true)
            && array_intersect_key($declarations, array_flip(array( 'inset', 'top', 'right', 'bottom', 'left', 'width', 'min-width', 'max-width' ))) !== array();
    }

    private function hasStaticPseudoElementRule(DOMElement $element): bool
    {
        foreach ( $this->sourceStyles()->pseudoElementRules() as $rule ) {
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
            || '' !== $this->renderedTextContent($element)
            || $this->runtimeIslands->isRuntimeDomTarget($element)
            || $this->isDirectChildOfStructuralLayout($element)
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
        $declarations = $this->matchingAuthorDeclarations($element);
        $display = strtolower(trim((string) ($declarations['display'] ?? '')));
        $verticalAlign = strtolower(trim((string) ($declarations['vertical-align'] ?? '')));
        return in_array($display, array( 'inline', 'inline-block', 'inline-flex', 'inline-grid', 'inline-table' ), true)
            && ! in_array($verticalAlign, array( '', 'baseline', 'inherit', 'initial', 'revert', 'revert-layer', 'unset' ), true);
    }

    private function isInertHiddenEmptyElement(DOMElement $element): bool
    {
        if ( 0 !== $this->childElementCount($element)
            || '' !== trim($element->textContent ?? '')
            || ! $this->sourceElementStartsHidden($element)
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
            && 'none' === strtolower(trim((string) ($this->styleResolver->cssDeclarations($this->attr($element, 'style'))['display'] ?? '')))
            && '' === trim($this->attr($element, 'class'))
            && '' === trim($this->attr($element, 'id'))
            && '' === trim($this->attr($element, 'role'))
            && array() === $this->interactiveAttributes($element)
            && array() === $this->safeDataAttributes($element);
    }

    private function renderedTextContent(DOMElement $element): string
    {
        $text = '';
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                $text .= $child->textContent ?? '';
                continue;
            }

            if ( ! $child instanceof DOMElement || in_array(strtolower($child->tagName), array( 'script', 'style', 'template' ), true) ) {
                continue;
            }

            $text .= $this->renderedTextContent($child);
        }

        return trim($text);
    }

    /** @return array<string, mixed> */
    private function emptyVisualElementAttributes(DOMElement $element): array
    {
        $attrs = $this->styleResolver->presentationAttributes($element);
        $parent = $element->parentNode;
        if ( ! $parent instanceof DOMElement ) {
            return $attrs;
        }

        $parentDisplay = strtolower(trim((string) ($this->styleResolver->structuralPresentationDeclarations($parent)['display'] ?? '')));
        if ( ! in_array($parentDisplay, array( 'flex', 'inline-flex' ), true) ) {
            return $attrs;
        }

        $declarations = $this->styleResolver->presentationDeclarations($element);
        $position = strtolower(trim((string) ($declarations['position'] ?? 'static')));
        if ( in_array($position, array( 'absolute', 'fixed' ), true) ) {
            return $attrs;
        }
        foreach ( array( 'width', 'min-width', 'max-width', 'flex', 'flex-basis' ) as $property ) {
            if ( isset($declarations[$property]) && '' !== trim($declarations[$property]) && 'auto' !== strtolower(trim($declarations[$property])) ) {
                return $attrs;
            }
        }
        foreach ( $this->sourceStyles()->pseudoElementRules() as $rule ) {
            if ( $this->styleResolver->matchesCssSelector($element, $rule['selector']) && array_intersect_key($rule['declarations'], array_flip(array( 'content', 'display', 'width', 'min-width' ))) ) {
                return $attrs;
            }
        }

        $attrs['className'] = trim((string) ($attrs['className'] ?? '') . ' ' . self::EMPTY_FLEX_ITEM_CLASS);
        if ( $this->runtimeIslands->isRuntimeDomTarget($element) ) {
            $attrs['className'] = trim($attrs['className'] . ' ' . self::EMPTY_RUNTIME_TARGET_CLASS);
            $this->runtimeBehavior()->markEmptyRuntimeTargetGenerated();
        }
        return $attrs;
    }

    /** @return array<string, mixed> */
    private function emptyVisualSpacerBlock(DOMElement $element): array
    {
        $attrs = $this->emptyVisualElementAttributes($element);
        if ( ! $this->isEmptyVisualInlineCandidate($element) ) {
            $block = $this->createBlock('core/group', $attrs, array(), $element);
            $block['_editability_visual_owned'] = true;
            return $block;
        }

        $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
        $paint = $this->styleResolver->styleAttributeMapper()->map(array_intersect_key($declarations, array_flip(array(
            'background',
            'background-color',
            'background-image',
            'background-position',
            'background-size',
            'background-repeat',
            'background-attachment',
            'background-origin',
            'background-clip',
            'background-blend-mode',
        ))));
        $attrs = array_merge($attrs, $paint['attrs']);
        if ( array() !== $paint['style'] ) {
            $attrs['style'] = array_replace_recursive($attrs['style'] ?? array(), $paint['style']);
        }
        $attrs['height'] = $this->resolveCssVariablesInValue($declarations['height']);
        $attrs['width'] = $this->resolveCssVariablesInValue($declarations['width']);

        return $this->createBlock('core/spacer', $attrs, array(), $element);
    }

    /** @return array<string, mixed>|null */
    private function flankedSeparatorBlockFromElement(DOMElement $element): ?array
    {
        if ( array() !== $this->htmlAttributes($element) || '' !== trim($element->textContent ?? '') ) {
            return null;
        }

        $children = array();
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                $children[] = $child;
            }
        }
        if ( 3 !== count($children) || 'hr' !== strtolower($children[1]->tagName) ) {
            return null;
        }

        $margins = array();
        foreach ( array( 'top' => $children[0], 'bottom' => $children[2] ) as $side => $flank ) {
            if ( 0 !== $this->childElementCount($flank) || '' !== trim($flank->textContent ?? '') || array( 'style' ) !== array_keys($this->htmlAttributes($flank)) ) {
                return null;
            }

            $declarations = $this->styleResolver->cssDeclarations($this->attr($flank, 'style'));
            if ( array() !== array_diff(array_keys($declarations), array( 'height', 'overflow', 'width' ))
                || 'hidden' !== strtolower(trim((string) ($declarations['overflow'] ?? '')))
                || ! in_array(strtolower(trim((string) ($declarations['width'] ?? ''))), array( '', '100%' ), true)
            ) {
                return null;
            }

            $height = SpacerPattern::heightFromStyle($this->attr($flank, 'style'));
            if ( '' === $height || ! $this->isPositiveCssLength($this->resolveCssVariablesInValue($height, $flank)) ) {
                return null;
            }
            $margins[ $side ] = $height;
        }

        $separator = $children[1];
        $attrs = $this->styleResolver->presentationAttributes($separator, array(), array( 'margin-left', 'margin-right' ));
        $attrs['style']['spacing']['margin'] = array_merge($attrs['style']['spacing']['margin'] ?? array(), $margins);

        return $this->createBlock('core/separator', $attrs, array(), $separator);
    }

    private function isEmptyVisualInlineCandidate(DOMElement $element): bool
    {
        if ( '' !== trim($element->textContent ?? '') || 0 !== $this->childElementCount($element) || ! $this->isInlineContentElement(strtolower($element->tagName)) ) {
            return false;
        }

        $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
        return $this->hasExplicitEmptyVisualDimensions($declarations) && $this->hasVisibleEmptyVisualPaint($declarations);
    }

    /** @param array<string, string> $declarations */
    private function hasExplicitEmptyVisualDimensions(array $declarations): bool
    {
        foreach ( array( 'width', 'height' ) as $property ) {
            if ( ! isset($declarations[$property]) || ! $this->isPositiveCssLength($this->resolveCssVariablesInValue($declarations[$property])) ) {
                return false;
            }
        }

        return true;
    }

    private function isPositiveCssLength(string $value): bool
    {
        if ( ! preg_match('/^([+]?(?:\d+(?:\.\d+)?|\.\d+))(?:px|em|rem|ex|ch|cm|mm|in|pt|pc|vw|vh|vmin|vmax)$/i', trim($value), $matches) ) {
            return false;
        }

        return (float) $matches[1] > 0;
    }

    /** @param array<string, string> $declarations */
    private function hasVisibleEmptyVisualPaint(array $declarations, ?DOMElement $element = null): bool
    {
        foreach ( array( 'background', 'background-color', 'box-shadow', 'outline' ) as $property ) {
            if ( isset($declarations[$property]) && $this->isVisibleEmptyVisualPaint($this->resolveCssVariablesInValue($declarations[$property], $element)) ) {
                return true;
            }
        }

        foreach ( array( 'border', 'border-top', 'border-right', 'border-bottom', 'border-left' ) as $property ) {
            if ( isset($declarations[$property]) && $this->isVisibleEmptyVisualBorder($this->resolveCssVariablesInValue($declarations[$property], $element)) ) {
                return true;
            }
        }

        return isset($declarations['border-color'], $declarations['border-width'])
            && $this->isVisibleEmptyVisualPaint($this->resolveCssVariablesInValue($declarations['border-color'], $element))
            && $this->isPositiveCssLength($this->resolveCssVariablesInValue($declarations['border-width'], $element));
    }

    private function isVisibleEmptyVisualPaint(string $value): bool
    {
        $value = strtolower(trim($value));
        if ( '' === $value || 'none' === $value || 'transparent' === $value || preg_match('/^rgba?\([^)]*,\s*0(?:\.0+)?\s*\)$/', $value) ) {
            return false;
        }

        return ! preg_match('/^#[0-9a-f]{4}$|^#[0-9a-f]{8}$/i', $value) || ! str_ends_with($value, '0');
    }

    private function isVisibleEmptyVisualBorder(string $value): bool
    {
        return ! str_contains(strtolower($value), 'transparent')
            && ! preg_match('/rgba?\([^)]*,\s*0(?:\.0+)?\s*\)/i', $value)
            && $this->isVisibleEmptyVisualPaint($value)
            && ! preg_match('/(?:^|\s)0(?:\.0+)?(?:px|em|rem|ex|ch|cm|mm|in|pt|pc|vw|vh|vmin|vmax)?(?:\s|$)/i', trim($value));
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

    private function authoredDisplay(DOMElement $element): string
    {
        $display = '';
        foreach ( $this->styleResolver->styleRuleCandidates($element, 'static') as $rule ) {
            if ( isset($rule['declarations']['display']) && $this->styleResolver->matchesCssSelector($element, $rule['selector']) ) {
                $display = (string) $rule['declarations']['display'];
            }
        }

        $inline = $this->styleResolver->cssDeclarations($this->attr($element, 'style'));
        return strtolower(trim(preg_replace('/\s*!important\s*$/i', '', (string) ($inline['display'] ?? $display)) ?? ''));
    }

    private function isInlineContentElement(string $tagName): bool
    {
        return in_array($tagName, array( 'abbr', 'b', 'cite', 'code', 'em', 'font', 'i', 'kbd', 'mark', 'rp', 'rt', 'ruby', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'var' ), true);
    }

    private function isInlineSourceElement(string $tagName): bool
    {
        return $this->isInlineContentElement($tagName)
            || in_array($tagName, array( 'a', 'audio', 'bdi', 'bdo', 'button', 'canvas', 'data', 'del', 'dfn', 'img', 'ins', 'label', 'meter', 'output', 'picture', 'progress', 'q', 's', 'select', 'svg', 'textarea', 'u', 'video' ), true);
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
        return $this->hasLogoBrandSignal($anchor)
            && 0 < $anchor->getElementsByTagName('svg')->length
            && '' === trim($this->runtime->stripAllTags($this->innerHtmlWithoutTags($anchor, array( 'svg' ))));
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

        return $this->createBlock('core/paragraph', array_merge($this->styleResolver->presentationAttributes($element), array( 'content' => $content )), array(), $element);
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

        $textRun = '';
        $generatedAssets = $this->materializedAssets()->checkpoint();
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                $textRun .= htmlspecialchars($child->textContent ?? '', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
                continue;
            }

            if ( ! $child instanceof DOMElement ) {
                $this->materializedAssets()->restore($generatedAssets);
                return null;
            }

            if ( 'svg' !== strtolower($child->tagName) ) {
                $textRun .= $this->outerHtml($child);
                continue;
            }

            $image = $this->inlineSvgRichTextImageMarkup($child);
            if ( null === $image ) {
                $this->materializedAssets()->restore($generatedAssets);
                return null;
            }

            $textRun .= $image;
        }

        $content = trim($textRun);
        if ( '' === trim($this->runtime->stripAllTags($content)) || $this->richTextRequiresHtmlFallbackWithoutNativeSvgImageObjects($content) ) {
            $this->materializedAssets()->restore($generatedAssets);
            return null;
        }

        return $this->createBlock('core/paragraph', array_merge($this->styleResolver->presentationAttributes($element), array( 'content' => $content )), array(), $element);
    }

    private function richTextContentWithMaterializedSvgImages(DOMElement $element, string $content): ?string
    {
        if ( 0 === $element->getElementsByTagName('svg')->length ) {
            return $content;
        }

        $generatedAssets = $this->materializedAssets()->checkpoint();
        foreach ( $element->getElementsByTagName('svg') as $svg ) {
            if ( ! $svg instanceof DOMElement ) {
                continue;
            }
            $image = $this->inlineSvgRichTextImageMarkup($svg, false);
            if ( null === $image ) {
                $this->materializedAssets()->restore($generatedAssets);
                return null;
            }
            // RichText preparation may normalize SVG casing (viewBox -> viewbox),
            // so the DOM serialization is not a stable replacement key.
            $replaced = preg_replace('@<svg\b[^>]*>.*?</svg>@is', $image, $content, 1);
            if ( ! is_string($replaced) || $replaced === $content ) {
                $this->materializedAssets()->restore($generatedAssets);
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
        return $this->materializedAssets()->hasInlineSvgSource($source);
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
            $children[] = $this->createBlock('core/paragraph', array_merge($this->styleResolver->presentationAttributes($child), array( 'content' => $content )), array(), $child);
        }

        if ( count($children) < 2 ) {
            return null;
        }

        return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($element), $children, $element);
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

        if ( $this->hasEmptyVisualInlineChild($element) ) {
            $fallbacks = array();
            $children = $this->convertChildren($element, $fallbacks, true);
            if ( array() !== $children ) {
                return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($element), $children, $element);
            }
        }

        $content = $this->richTextContentWithMaterializedInlineStyles($element);
        if ( '' === trim($this->runtime->stripAllTags($content)) || $this->richTextRequiresHtmlFallback($content) ) {
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
            return $this->createBlock(
                'core/paragraph',
                array_merge($this->styleResolver->presentationAttributes($element), array( 'content' => $content )),
                array(),
                $element
            );
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
    private const BOX_CHROME_WRAPPER_PROPERTIES = array( 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'border', 'border-color', 'border-radius', 'width', 'height', 'min-width', 'max-width', 'min-height' );

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
        return $this->wrapperStylingMatches($element, self::BOX_CHROME_WRAPPER_PROPERTIES);
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
            if ( isset($declarations[$property]) && $this->cssValueIsNonZero((string) $declarations[$property]) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a CSS length/box value contributes real geometry. A universal reset
     * (`* { margin: 0; padding: 0 }`) sets zero-valued box properties on every
     * element; those must not be treated as box chrome or every wrapper would be
     * disqualified from collapsing to a paragraph. Treats empty, `0`, `none`, and
     * all-zero shorthand values (`0 0 0 0`, `0px`) as no geometry.
     */
    private function cssValueIsNonZero(string $value): bool
    {
        $normalized = strtolower(trim($value));
        if ( '' === $normalized || 'none' === $normalized ) {
            return false;
        }

        foreach ( preg_split('/[\s,]+/', $normalized) ?: array() as $token ) {
            if ( '' === $token ) {
                continue;
            }
            if ( ! preg_match('/^0(?:\.0+)?[a-z%]*$/', $token) ) {
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

        // A direct inline child with its own layout geometry needs its source
        // tag as the flex/grid item. Ordinary inline runs still stay RichText.
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && $this->requiresStandaloneInlineLayoutLeaf($child) ) {
                return null;
            }
        }

        if ( ! $this->hasOnlyPhrasingChildren($element) ) {
            return null;
        }

        if ( $this->hasEmptyVisualInlineChild($element) ) {
            $fallbacks = array();
            $children = $this->convertChildren($element, $fallbacks, true);
            if ( array() !== $children ) {
                return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($element), $children, $element);
            }
        }

        // A lone marked descendant needs an independent carrier. Phrasing-only
        // sibling runs remain together in this RichText block so authored
        // flex/grid child geometry is not replaced with block wrappers.
        if ( $this->hasAuthorSemanticMarkedChild($element) || ( $this->hasRichTextMarkedDescendant($element) && 2 > $this->childElementCount($element) ) ) {
            return null;
        }

        $structuredInlineItems = $this->structuredInlineItemBlocks($element);
        if ( null !== $structuredInlineItems ) {
            return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($element), $structuredInlineItems, $element);
        }

        $content = $this->richTextContentWithMaterializedInlineStyles($element);
        if ( '' === trim($this->runtime->stripAllTags($content)) ) {
            return null;
        }

        $attrs = $this->styleResolver->presentationAttributes($element);
        $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), self::SYNTHETIC_PARAGRAPH_CLASS);
        $attrs['content'] = $content;
        return $this->createBlock('core/paragraph', $attrs, array(), $element);
    }

    private function hasMultipleRuntimeInlineTextTargets(DOMElement $element): bool
    {
        if ( ! ShellLandmarkPolicy::isInlineContentWrapperTag($element->tagName) || ! $this->hasOnlyPhrasingChildren($element) ) {
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
        if ( ! $this->hasOnlyPhrasingChildren($element) ) {
            return null;
        }

        $content = $this->richTextContentWithMaterializedInlineStyles($element);
        $inlineSvgContent = $this->richTextContentWithMaterializedSvgImages($element, $content);
        if ( null !== $inlineSvgContent ) {
            $content = $inlineSvgContent;
        }
        if ( '' === trim($this->runtime->stripAllTags($content)) || $this->richTextRequiresHtmlFallbackWithoutNativeSvgImageObjects($content) ) {
            return null;
        }

        $paragraph = $this->createBlock('core/paragraph', array( 'content' => $content ));
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

    private function hasRichTextMarkedDescendant(DOMElement $element): bool
    {
        foreach ( $element->getElementsByTagName('span') as $span ) {
            if ( $span instanceof DOMElement && '' !== $this->richTextMarkerForElement($span) && ! $this->shouldPreserveEmptyVisualElement($span) ) {
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

            // The paragraph inherits a span's class for its layout role, but
            // semantic inline elements (time, links, emphasis, etc.) must retain
            // their source markup inside the editable RichText content.
            $content = 'span' === strtolower($child->tagName) ? $this->innerHtml($child) : $this->outerHtml($child);
            if ( '' === trim($this->runtime->stripAllTags($content)) ) {
                return null;
            }

            $blocks[] = $this->createBlock('core/paragraph', array_merge($this->styleResolver->presentationAttributes($child), array( 'content' => $content )), array(), $child);
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
        $priceText = 'div' === strtolower($element->tagName) ? $this->directTextContent($element) : ($element->textContent ?? '');
        if ( $this->hasCommerceToken($element, array( 'price', 'pricing', 'amount', 'cost' )) || $this->looksLikePriceText($priceText) ) {
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

    private function directTextContent(DOMElement $element): string
    {
        $text = '';
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                $text .= ' ' . ($child->textContent ?? '');
            }
        }
        return trim($text);
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
            // ARIA state and inert data attributes describe a possible control,
            // not executable behavior. Report loss only when source code actually
            // supplies a handler or an available script targets the control.
            if ( array() === $this->eventMetadata($element)
                && ! $this->runtimeBehavior()->hasRuntimeScriptMetadata() ) {
                continue;
            }
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
            ), static fn (mixed $value): bool => null !== $value && '' !== $value && array() !== $value), $this->transformationProvenance()->fallback());
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
     * @param array<int, array<string, mixed>> $blocks
     */
    private function appendProductGridFallbacks(DOMElement $body, array &$fallbacks, array $blocks): void
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

            $products = $this->productCardsForContainer($element, $blocks);
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
            ), static fn (mixed $value): bool => null !== $value && '' !== $value && array() !== $value), $this->transformationProvenance()->fallback());
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
            ), static fn (mixed $value): bool => null !== $value && '' !== $value && array() !== $value), $this->transformationProvenance()->fallback());
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
    private function productCardsForContainer(DOMElement $container, array $blocks = array()): array
    {
        $products = array();
        foreach ( $container->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $product = $this->productCardData($child);
            if ( null !== $product ) {
                $binding = $this->commerceBindingForCard($child, $blocks);
                if ( array() !== $binding ) {
                    $product['binding'] = $binding;
                }
                $products[] = $product;
            }
        }

        return $products;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<string, mixed>
     */
    private function commerceBindingForCard(DOMElement $card, array $blocks): array
    {
        if ( array() === $blocks ) {
            return array();
        }
        $control = $this->cartControlElement($card);
        if ( null === $control ) {
            return array();
        }
        $block = $this->blockForSourceSelector($blocks, $this->elementSelector($control));
        if ( null === $block ) {
            return array();
        }
        return $this->blockBinding($block, 'commerce_controls', $this->runtimeIslands->runtimeDomSelectorsForElement($control));
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
            if ( is_int($provenanceId) && $selector === ($this->transformationProvenance()->source($provenanceId)['selector'] ?? null) ) {
                return $block;
            }
            $nested = $this->blockForSourceSelector(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array(), $selector);
            if ( null !== $nested ) {
                return $nested;
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function blockBinding(array $block, string $role, array $supersededRuntimeSelectors = array()): array
    {
        $provenanceId = $block['_source_provenance_id'] ?? null;
        $markup = $this->runtime->serializeBlocks(array($block));
        if ( '' === trim($markup) || ! is_int($provenanceId) ) {
            return array();
        }
        $binding = array('schema' => 'generic/block-binding/v1', 'search_block_markup' => $markup, 'occurrence' => 1, 'role' => $role, '_binding_provenance_id' => $provenanceId);
        $supersededRuntimeSelectors = array_values(array_unique(array_filter($supersededRuntimeSelectors, static fn(mixed $selector): bool => is_string($selector) && '' !== trim($selector))));
        if ( array() !== $supersededRuntimeSelectors ) $binding['superseded_runtime_selectors'] = $supersededRuntimeSelectors;
        return $binding;
    }

    /** @param array<int,array<string,mixed>> $fallbacks @param array<int,array<string,mixed>> $blocks */
    private function finalizeFallbackBindings(array &$fallbacks, array $blocks, string $markup): void
    {
        $provenanceIndexes = array(); $index = 0;
        $this->bindingProvenanceIndexes($blocks, $provenanceIndexes, $index);
        $ranges = $this->serializedBlockRanges($markup);
        $finalize = function (array &$binding) use ($markup, $provenanceIndexes, $ranges): void {
            $provenanceId = $binding['_binding_provenance_id'] ?? null;
            $blockIndex = is_int($provenanceId) ? ($provenanceIndexes[$provenanceId] ?? null) : null;
            $range = is_int($blockIndex) ? ($ranges[$blockIndex] ?? null) : null;
            if (is_array($range)) {
                $search = substr($markup, $range['offset'], $range['length']);
                if (is_string($search) && '' !== $search) {
                    $binding['search_block_markup'] = $search;
                    $binding['occurrence'] = $this->occurrenceAtOffset($markup, $search, $range['offset']);
                    $binding['position'] = array('schema' => 'blocks-engine/runtime-binding-position/v1', 'block_index' => $blockIndex, 'offset' => $range['offset'], 'length' => $range['length']);
                } else {
                    $binding = array();
                }
            } else {
                $binding = array();
            }
            unset($binding['_binding_provenance_id']);
        };
        foreach ( $fallbacks as &$fallback ) {
            if (is_array($fallback['binding'] ?? null)) $finalize($fallback['binding']);
            if (is_array($fallback['products'] ?? null)) {
                foreach ($fallback['products'] as &$product) if (is_array($product['binding'] ?? null)) $finalize($product['binding']);
                unset($product);
            }
        }
        unset($fallback);
    }

    /** @param array<int,array<string,mixed>> $blocks @param array<int,int|null> $provenanceIndexes */
    private function bindingProvenanceIndexes(array $blocks, array &$provenanceIndexes, int &$index): void
    {
        foreach ( $blocks as $block ) {
            if ( ! is_array($block) ) continue;
            $provenanceId = $block['_source_provenance_id'] ?? null;
            if (is_int($provenanceId)) $provenanceIndexes[$provenanceId] = isset($provenanceIndexes[$provenanceId]) ? null : $index;
            ++$index;
            $this->bindingProvenanceIndexes(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array(), $provenanceIndexes, $index);
        }
    }

    /** @return array<int,array{offset:int,length:int}> */
    private function serializedBlockRanges(string $markup): array
    {
        $ranges = array(); $stack = array();
        if (!preg_match_all('/<!--\s*(\/?)wp:[^>]*?(\/?)\s*-->/s', $markup, $matches, PREG_OFFSET_CAPTURE)) return $ranges;
        foreach ($matches[0] as $match) {
            $token = $match[0]; $offset = $match[1];
            if (str_starts_with($token, '<!-- /wp:')) {
                $open = array_pop($stack);
                if (is_array($open)) $ranges[$open['index']]['length'] = $offset + strlen($token) - $open['offset'];
            } elseif (str_ends_with(rtrim($token), '/-->')) {
                $ranges[] = array('offset' => $offset, 'length' => strlen($token));
            } else {
                $index = count($ranges); $ranges[] = array('offset' => $offset, 'length' => 0); $stack[] = array('index' => $index, 'offset' => $offset);
            }
        }
        return array_values(array_filter($ranges, static fn(array $range): bool => 0 < $range['length']));
    }

    private function occurrenceAtOffset(string $markup, string $search, int $offset): int
    {
        $occurrence = 0; $cursor = 0;
        while (false !== ($found = strpos($markup, $search, $cursor))) { ++$occurrence; if ($found === $offset) return $occurrence; $cursor = $found + strlen($search); }
        return 0;
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
        return null !== $this->cartControlElement($card);
    }

    private function cartControlElement(DOMElement $card): ?DOMElement
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
                    return $descendant;
                }
            }

            // "add" alone is ambiguous, so require it to co-occur with a commerce
            // context word ("cart"/"bag"/"basket") to count as a cart control.
            if ( preg_match('/\badd\b/', $haystack) && preg_match('/\b(?:cart|bag|basket)\b/', $haystack) ) {
                return $descendant;
            }
        }

        return null;
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

        if ( $this->runtimeIslands->isRuntimeDomTarget($element) ) {
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
            $this->runtimeBehavior()->rememberNativeDisclosureRoot($element->getNodePath() ?? '');
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
        if ( ! $this->runtimeBehavior()->hasNativeDisclosureRoots() ) {
            return false;
        }

        $hasDisclosureState = '' !== trim($this->attr($element, 'aria-expanded'))
            || '' !== trim($this->attr($element, 'aria-controls'));
        if ( ! $hasDisclosureState ) {
            return false;
        }

        for ( $node = $element; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( $this->runtimeBehavior()->isNativeDisclosureRoot($node->getNodePath() ?? '') ) {
                return true;
            }
        }

        return false;
    }

    private function isPreservedRuntimeIslandElement(DOMElement $element): bool
    {
        $selector = $this->runtimeIslandSelector($element);
        return $this->runtimeDom()->hasIslandSelector($selector);
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
            if ( $this->shouldPreserveEmptyVisualFigure($figure) ) {
                return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($figure), array(), $figure);
            }

            return null;
        }

        if ( 1 === count($children) && array() === $this->styleResolver->presentationAttributes($figure) ) {
            return $children[0];
        }

        return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($figure), $children, $figure);
    }

    private function shouldPreserveEmptyVisualFigure(DOMElement $figure): bool
    {
        if ( '' !== $this->renderedTextContent($figure) || 0 !== $this->childElementCount($figure) ) {
            return false;
        }

        $declarations = $this->styleResolver->structuralPresentationDeclarations($figure);
        $hasBoundedHeight = false;
        foreach ( array( 'height', 'min-height' ) as $property ) {
            if ( isset($declarations[$property]) && $this->isPositiveCssLength($this->resolveCssVariablesInValue($declarations[$property], $figure)) ) {
                $hasBoundedHeight = true;
                break;
            }
        }
        if ( ! $hasBoundedHeight ) {
            return false;
        }

        if ( $this->hasVisibleEmptyVisualPaint($declarations, $figure) ) {
            return true;
        }

        foreach ( $this->sourceStyles()->pseudoElementRules() as $rule ) {
            if ( $this->styleResolver->matchesCssSelector($figure, $rule['selector']) && $this->hasVisibleEmptyVisualPaint($rule['declarations'], $figure) ) {
                return true;
            }
        }

        return false;
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
        $attrs = array(
            'hasFixedLayout' => 'fixed' === strtolower(trim((string) ($this->styleResolver->structuralPresentationDeclarations($table)['table-layout'] ?? ''))),
        );
        $this->registerTablePresentationNormalization($table);
        $this->registerTableCellGeometry($table);
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

    private function registerTablePresentationNormalization(DOMElement $table): void
    {
        $path = $this->sourceElementIdentity($table);
        $marker = $this->authorSelectorProjections()->ensureTableMarker($path);
        $tableDeclarations = $this->styleResolver->structuralPresentationDeclarations($table);
        // A single marker class ties core's .wp-block-table margin while later
        // source classes promoted onto the figure retain their authored margins.
        $rules = array( '.' . $marker . '{margin:0}' );
        $borderModel = array();
        foreach ( array( 'border-collapse', 'border-spacing' ) as $property ) {
            $value = trim((string) ($tableDeclarations[$property] ?? ''));
            if ( '' !== $value ) {
                $borderModel[] = $property . ':' . $value;
            }
        }
        if ( array() !== $borderModel ) {
            $rules[] = '.' . $marker . '>table{' . implode(';', $borderModel) . '}';
        }

        // Core supplies borders on every cell. Clear them before projected author
        // CSS restores only the source-declared cell sides.
        $rules[] = '.' . $marker . '>table th,.' . $marker . '>table td{border:0}';

        $head = $this->firstChildElement($table, 'thead');
        if ( $head instanceof DOMElement ) {
            $headDeclarations = $this->styleResolver->structuralPresentationDeclarations($head);
            if ( ! isset($headDeclarations['border']) && ! isset($headDeclarations['border-bottom']) && ! isset($headDeclarations['border-bottom-width']) ) {
                // core/table adds a 3px header separator that did not exist in source.
                $rules[] = '.' . $marker . '>table>thead{border-bottom:0}';
            }
        }

        $this->layoutGeometry()->registerRule($marker, implode("\n", $rules));
    }

    private function registerTableCellGeometry(DOMElement $table): void
    {
        $rules = array();
        $sectionRows = array( 'thead' => 0, 'tbody' => 0, 'tfoot' => 0 );
        foreach ( $table->getElementsByTagName('tr') as $row ) {
            if ( ! $row instanceof DOMElement || ! $this->belongsToTable($row, $table) ) {
                continue;
            }
            $section = $this->closestTagName($row);
            $section = isset($sectionRows[$section]) ? $section : 'tbody';
            $rowIndex = ++$sectionRows[$section];
            $cellIndex = 0;
            foreach ( $row->childNodes as $cell ) {
                if ( ! $cell instanceof DOMElement || ! in_array(strtolower($cell->tagName), array( 'td', 'th' ), true) ) {
                    continue;
                }
                ++$cellIndex;
                $declarations = $this->styleResolver->cssDeclarations($this->attr($cell, 'style'));
                $geometry = array();
                $width = trim((string) ($declarations['width'] ?? ''));
                if ( '' !== $width && preg_match('/^(?:\d+(?:\.\d+)?(?:%|px|em|rem|vw|ch)|calc\(.+\)|var\(.+\))$/i', $width) ) {
                    $geometry[] = 'width:' . $width . '!important';
                }
                foreach ( array( 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left' ) as $property ) {
                    $value = trim((string) ($declarations[$property] ?? ''));
                    if ( '' !== $value && preg_match('/^(?:-?\d+(?:\.\d+)?(?:px|em|rem|%|vw|vh|ch)?)(?:\s+-?\d+(?:\.\d+)?(?:px|em|rem|%|vw|vh|ch)?){0,3}$/i', $value) ) {
                        $geometry[] = $property . ':' . $value . '!important';
                    }
                }
                if ( array() !== $geometry ) {
                    $rules[] = $section . '>tr:nth-child(' . $rowIndex . ')>' . strtolower($cell->tagName) . ':nth-child(' . $cellIndex . '){' . implode(';', $geometry) . '}';
                }
            }
        }
        if ( array() === $rules ) {
            return;
        }

        $path = $this->sourceElementIdentity($table);
        $marker = $this->authorSelectorProjections()->ensureTableMarker($path);
        $scopedRules = array_map(static fn (string $rule): string => '.' . $marker . '>table>' . $rule, $rules);
        $this->layoutGeometry()->appendRule($marker, implode("\n", $scopedRules));
    }

    private function materializeDeclarativeCounters(DOMElement $body, string $declarativeStateHtml = ''): void
    {
        $document = $body->ownerDocument;
        if ( ! $document instanceof DOMDocument ) {
            return;
        }

        $scriptSources = array();
        foreach ( $body->getElementsByTagName('script') as $script ) {
            if ( $script instanceof DOMElement ) {
                $scriptSources[] = (string) $script->textContent;
            }
        }
        if ( '' !== $declarativeStateHtml && preg_match_all('@<script\b[^>]*>(.*?)</script>@is', $declarativeStateHtml, $scriptMatches) ) {
            $scriptSources = array_merge($scriptSources, $scriptMatches[1]);
        }

        foreach ( array_unique($scriptSources) as $source ) {
            if ( ! str_contains($source, 'PlatformElementSettings')
                || ! preg_match('/\.prototype\.element_id\s*=\s*(["\'])([a-z0-9-]+)\1/i', $source, $elementMatch)
            ) {
                continue;
            }

            $settingsStart = strpos($source, 'new PlatformElementSettings(');
            $settingsEnd = false !== $settingsStart ? strpos($source, ');', $settingsStart) : false;
            if ( false === $settingsStart || false === $settingsEnd || $settingsEnd - $settingsStart > 262144 ) {
                continue;
            }
            $settingsLiteral = substr($source, $settingsStart, $settingsEnd - $settingsStart);
            if ( ! preg_match('/["\']end["\']\s*:\s*(-?(?:0|[1-9]\d*)(?:\.\d+)?)\s*[,}]/', $settingsLiteral, $endMatch) ) {
                continue;
            }
            $end = str_contains($endMatch[1], '.') ? (float) $endMatch[1] : (int) $endMatch[1];

            $container = $document->getElementById('element-' . $elementMatch[2]);
            if ( ! $container instanceof DOMElement ) {
                continue;
            }
            foreach ( $container->getElementsByTagName('*') as $target ) {
                if ( ! $target instanceof DOMElement
                    || ! in_array('content-number-bold', preg_split('/\s+/', trim($target->getAttribute('class'))) ?: array(), true)
                    || '' !== trim((string) $target->textContent)
                ) {
                    continue;
                }
                $target->appendChild($document->createTextNode((string) $end));
                break;
            }
        }
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
            foreach ( $cell->getElementsByTagName('*') as $descendant ) {
                if ( ! $descendant instanceof DOMElement ) {
                    continue;
                }
                $sourceTagName = strtolower($descendant->tagName);
                $sourceTagMarker = $this->authorSelectorProjections()->tagMarker($sourceTagName);
                if ( '' !== $sourceTagMarker ) {
                    $descendant->setAttribute('class', $this->mergeClassNames($this->attr($descendant, 'class'), $sourceTagMarker));
                }
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
                $items[] = $this->createBlock('core/list-item', array_merge($this->styleResolver->presentationAttributes($child), array(
                    'content' => trim($prefix . ( '' !== $prefix && '' !== trim($description) ? ' ' : '' ) . $description),
                )), array(), $child);
            }
        }

        return $items;
    }

    /**
     * Preserve valid direct and div-grouped description lists as a static
     * companion block while retaining the existing direct-list group schema.
     *
     * @return array<string, mixed>|null
     */
    private function descriptionListBlockFromElement(DOMElement $list): ?array
    {
        $groups = array();
        $group = null;

        foreach ( $list->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }
            if ( ! $child instanceof DOMElement ) {
                return null;
            }

            $tag = strtolower($child->tagName);
            if ( 'div' === $tag ) {
                if ( null !== $group ) {
                    $groups[] = $group;
                    $group = null;
                }
                $wrappedGroup = $this->descriptionListWrappedGroup($child);
                if ( null === $wrappedGroup ) {
                    return null;
                }
                $groups[] = $wrappedGroup;
                continue;
            }
            if ( ! in_array($tag, array( 'dt', 'dd' ), true) || ! $this->descriptionListItemSupportsRichText($child) ) {
                return null;
            }
            if ( 'dt' === $tag ) {
                if ( null === $group || array() !== $group['descriptions'] ) {
                    if ( null !== $group ) {
                        $groups[] = $group;
                    }
                    $group = array( 'terms' => array(), 'descriptions' => array() );
                }
                $group['terms'][] = $this->descriptionListItem($child);
                continue;
            }
            if ( 'dd' !== $tag || null === $group || array() === $group['terms'] ) {
                return null;
            }
            $group['descriptions'][] = $this->descriptionListItem($child);
        }

        if ( null !== $group ) {
            if ( array() === $group['descriptions'] ) {
                return null;
            }
            $groups[] = $group;
        }
        if ( array() === $groups ) {
            return null;
        }

        $this->generatedBlocks()->register(DescriptionListBlockGenerator::class, ( new DescriptionListBlockGenerator() )->definition());

        $markup = $this->descriptionListMarkup($list, $groups);
        return array(
            'blockName' => DescriptionListBlockGenerator::NAME,
            'attrs' => array_filter(array(
                'className' => $list->getAttribute('class'),
                'style' => $list->getAttribute('style'),
                'groups' => $groups,
            ), static fn (mixed $value): bool => '' !== $value),
            'innerBlocks' => array(),
            'innerHTML' => $markup,
            'innerContent' => array( $markup ),
        );
    }

    private function descriptionListItemSupportsRichText(DOMElement $element): bool
    {
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                continue;
            }
            if ( ! $child instanceof DOMElement ) {
                return false;
            }

            $tag = strtolower($child->tagName);
            if ( 'a' !== $tag && 'br' !== $tag && ! $this->isInlineContentElement($tag) ) {
                return false;
            }
            foreach ( $child->attributes as $attribute ) {
                $attributeName = strtolower($attribute->name);
                if ( ! ( 'a' === $tag && in_array($attributeName, array( 'href', 'target', 'rel' ), true) ) && ! ( 'time' === $tag && 'datetime' === $attributeName ) ) {
                    return false;
                }
            }
            if ( ! $this->descriptionListItemSupportsRichText($child) ) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed>|null */
    private function descriptionListWrappedGroup(DOMElement $wrapper): ?array
    {
        $items = array();
        $hasTerm = false;
        $hasDescription = false;
        foreach ( $wrapper->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }
            if ( ! $child instanceof DOMElement || ! in_array(strtolower($child->tagName), array( 'dt', 'dd' ), true) || ! $this->descriptionListItemSupportsRichText($child) ) {
                return null;
            }
            $tag = strtolower($child->tagName);
            if ( 'dt' === $tag ) {
                if ( $hasTerm && ! $hasDescription ) {
                    // Multiple terms may describe the same following definition.
                } elseif ( $hasDescription ) {
                    $hasDescription = false;
                }
                $hasTerm = true;
            } elseif ( ! $hasTerm ) {
                return null;
            } else {
                $hasDescription = true;
            }
            $items[] = array_merge(array( 'tagName' => $tag ), $this->descriptionListItem($child));
        }

        if ( ! $hasDescription ) {
            return null;
        }

        return array(
            'wrapper' => $this->descriptionListWrapper($wrapper),
            'items' => $items,
        );
    }

    /** @return array<string, string> */
    private function descriptionListItem(DOMElement $element): array
    {
        return array_filter(array(
            'content' => $this->innerHtml($element),
            'className' => $element->getAttribute('class'),
            'style' => $element->getAttribute('style'),
        ), static fn (mixed $value): bool => '' !== $value);
    }

    /** @return array<string, mixed> */
    private function descriptionListWrapper(DOMElement $element): array
    {
        $wrapper = array_filter(array(
            'className' => $element->getAttribute('class'),
            'style' => $element->getAttribute('style'),
        ), static fn (mixed $value): bool => '' !== $value);
        $attributes = array();
        foreach ( $element->attributes as $attribute ) {
            $name = strtolower($attribute->name);
            if ( $this->descriptionListWrapperAttributeIsSafe($name) ) {
                $attributes[$name] = $attribute->value;
            }
        }
        if ( array() !== $attributes ) {
            $wrapper['attributes'] = $attributes;
        }
        return $wrapper;
    }

    private function descriptionListWrapperAttributeIsSafe(string $name): bool
    {
        if ( in_array($name, array( 'id', 'role' ), true) || str_starts_with($name, 'aria-') ) {
            return true;
        }

        // Keep passive data hooks but exclude WordPress Interactivity API directives.
        return str_starts_with($name, 'data-') && ! str_starts_with($name, 'data-wp-');
    }

    /** @param array<int, array<string, mixed>> $groups */
    private function descriptionListMarkup(DOMElement $list, array $groups): string
    {
        $markup = '<dl' . $this->descriptionListMarkupAttributes(array(
            'className' => $list->getAttribute('class'),
            'style' => $list->getAttribute('style'),
        )) . '>';
        foreach ( $groups as $group ) {
            if ( isset($group['wrapper']) && is_array($group['wrapper']) ) {
                $markup .= '<div' . $this->descriptionListMarkupAttributes($group['wrapper']) . '>';
                foreach ( $group['items'] ?? array() as $item ) {
                    $tag = $item['tagName'] ?? '';
                    $markup .= '<' . $tag . $this->descriptionListMarkupAttributes($item) . '>' . ($item['content'] ?? '') . '</' . $tag . '>';
                }
                $markup .= '</div>';
                continue;
            }
            foreach ( $group['terms'] as $term ) {
                $markup .= '<dt' . $this->descriptionListMarkupAttributes($term) . '>' . ($term['content'] ?? '') . '</dt>';
            }
            foreach ( $group['descriptions'] as $description ) {
                $markup .= '<dd' . $this->descriptionListMarkupAttributes($description) . '>' . ($description['content'] ?? '') . '</dd>';
            }
        }
        return $markup . '</dl>';
    }

    /** @param array<string, mixed> $attributes */
    private function descriptionListMarkupAttributes(array $attributes): string
    {
        $markup = '';
        foreach ( array( 'className' => 'class', 'style' => 'style' ) as $key => $name ) {
            if ( '' !== (string) ($attributes[$key] ?? '') ) {
                $markup .= ' ' . $name . '="' . htmlspecialchars((string) $attributes[$key], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            }
        }
        foreach ( $attributes['attributes'] ?? array() as $name => $value ) {
            $markup .= ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        return $markup;
    }

    /**
     * Convert compact label/value grids into native blocks without letting the
     * paragraph block's default margins turn each record into prose flow.
     *
     * A definition list provides the relationship semantically. Generic wrappers
     * need both a grid/flex layout and repeated, visually distinguished labels;
     * this keeps ordinary text wrappers out of the recognizer.
     *
     * @return array<string, mixed>|null
     */
    private function metadataGridBlockFromElement(DOMElement $element): ?array
    {
        $children = $this->directMetadataCells($element);
        if ( count($children) < 2 || 0 !== count($children) % 2 ) {
            return null;
        }

        $isDefinitionList = 'dl' === strtolower($element->tagName);
        if ( $isDefinitionList ) {
            if ( count($children) < 4 ) {
                return null;
            }
            foreach ( $children as $index => $child ) {
                if ( (0 === $index % 2 && 'dt' !== strtolower($child->tagName)) || (1 === $index % 2 && 'dd' !== strtolower($child->tagName)) ) {
                    return null;
                }
            }
        } elseif ( ! $this->isRepeatedMetadataRow($element, $children) ) {
            return null;
        }

        $style = $this->metadataPresentationStyle($element);
        if ( ! $this->isMetadataLayoutStyle($style) ) {
            return null;
        }

        if ( $this->isFlexMetadataStyle($style) && ! $this->hasStrongFlexMetadataEvidence($element, $children, $isDefinitionList, $style) ) {
            return null;
        }

        $blocks = array();
        foreach ( $children as $child ) {
            $content = $this->metadataCellContent($child);
            if ( '' === trim($this->runtime->stripAllTags($content)) ) {
                return null;
            }
            $blocks[] = $this->createBlock('core/paragraph', $this->metadataCellAttributes($child, $content), array(), $child);
        }

        $attrs = $this->styleResolver->presentationAttributes($element);
        // The source stylesheet owns the grid tracks and independent gaps. Core's
        // layout support emits classes and a gap shorthand that can override both.
        unset($attrs['layout'], $attrs['style']['spacing']['blockGap']);
        if ( empty($attrs['style']['spacing']) ) {
            unset($attrs['style']['spacing']);
        }
        if ( empty($attrs['style']) ) {
            unset($attrs['style']);
        }

        return $this->createBlock('core/group', $attrs, $blocks, $element);
    }

    /** @return array<int, DOMElement> */
    private function directMetadataCells(DOMElement $element): array
    {
        $cells = array();
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }
            if ( ! $child instanceof DOMElement ) {
                return array();
            }
            if ( $this->hasBlockContentChildren($child) ) {
                return array();
            }
            $cells[] = $child;
        }

        return $cells;
    }

    /** @param array<int, DOMElement> $children */
    private function isRepeatedMetadataRow(DOMElement $element, array $children): bool
    {
        if ( 2 !== count($children) || ! $this->hasMetadataLabelPresentation($children[0]) ) {
            return false;
        }

        $parent = $element->parentNode;
        if ( ! $parent instanceof DOMElement ) {
            return false;
        }

        $matchingRows = 0;
        foreach ( $parent->childNodes as $sibling ) {
            if ( ! $sibling instanceof DOMElement || ! $this->isMetadataLayoutStyle($this->metadataPresentationStyle($sibling)) ) {
                continue;
            }
            $cells = $this->directMetadataCells($sibling);
            if ( 2 === count($cells) && $this->hasMetadataLabelPresentation($cells[0]) ) {
                ++$matchingRows;
            }
        }

        return 2 <= $matchingRows;
    }

    private function hasMetadataLabelPresentation(DOMElement $element): bool
    {
        if ( in_array(strtolower($element->tagName), array( 'b', 'strong' ), true) ) {
            return true;
        }

        $style = $this->styleResolver->cssDeclarations($this->metadataPresentationStyle($element));
        $weight = (int) preg_replace('/\D.*/', '', (string) ($style['font-weight'] ?? ''));
        if ( 600 <= $weight || in_array(strtolower(trim((string) ($style['text-transform'] ?? ''))), array( 'uppercase', 'capitalize' ), true) ) {
            return true;
        }

        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement && in_array(strtolower($descendant->tagName), array( 'b', 'strong' ), true) ) {
                return true;
            }
        }

        return false;
    }

    private function isMetadataLayoutStyle(string $style): bool
    {
        return 1 === preg_match('/(?:^|;)\s*display\s*:\s*(?:inline-)?(?:grid|flex)\b/i', $style);
    }

    private function isFlexMetadataStyle(string $style): bool
    {
        return 1 === preg_match('/(?:^|;)\s*display\s*:\s*(?:inline-)?flex\b/i', $style);
    }

    /** @param array<int, DOMElement> $children */
    private function hasStrongFlexMetadataEvidence(DOMElement $element, array $children, bool $isDefinitionList, string $style): bool
    {
        if ( 1 !== preg_match('/(?:^|;)\s*flex-wrap\s*:\s*wrap(?:-reverse)?\b/i', $style) ) {
            return false;
        }

        // A definition list supplies repeated term/description records. Generic
        // rows additionally need the repeated labelled-row evidence above.
        return $isDefinitionList
            ? 4 <= count($children)
            : $this->isRepeatedMetadataRow($element, $children);
    }

    private function metadataPresentationStyle(DOMElement $element): string
    {
        // Layout is structural evidence, so inspect matching stylesheet rules even
        // when the element is not otherwise a high-value style boundary.
        return $this->styleResolver->cssDeclarationString($this->styleResolver->structuralPresentationDeclarations($element));
    }

    /** @return array<string, mixed> */
    private function metadataCellAttributes(DOMElement $element, string $content): array
    {
        $attrs = $this->styleResolver->presentationAttributes($element);
        $attrs['content'] = $content;
        $attrs['style']['spacing']['margin']['top'] = '0';
        $attrs['style']['spacing']['margin']['bottom'] = '0';

        return $attrs;
    }

    private function metadataCellContent(DOMElement $element): string
    {
        $content = $this->richTextContentWithMaterializedInlineStyles($element);
        if ( in_array(strtolower($element->tagName), array( 'dt', 'b', 'strong' ), true) ) {
            return '<strong>' . $content . '</strong>';
        }

        return $content;
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
            foreach ( $this->nestedListRoots($child) as $nestedList ) {
                $nestedBlock = $this->convertElement($nestedList, $fallbacks, true);
                if ( null !== $nestedBlock ) {
                    $nested[] = $nestedBlock;
                }
            }

            $content = $this->listItemContentWithoutNestedLists($child);
            if ( '' === trim($this->runtime->stripAllTags($content)) && array() === $nested ) {
                continue;
            }

            $items[] = $this->createBlock('core/list-item', array_merge($this->styleResolver->presentationAttributes($child), array( 'content' => $content )), $nested, $child);
        }

        return $items;
    }

    /** @return list<DOMElement> */
    private function nestedListRoots(DOMElement $item): array
    {
        $lists = array();
        foreach ( $item->childNodes as $child ) {
            if ( $child instanceof DOMElement && in_array(strtolower($child->tagName), array( 'ul', 'ol' ), true) ) {
                $lists[] = $child;
            }
        }

        return $lists;
    }

    private function listContainsStructuralItemContent(DOMElement $list): bool
    {
        foreach ( $list->childNodes as $child ) {
            if ( ! $child instanceof DOMElement || 'li' !== strtolower($child->tagName) ) {
                continue;
            }

            $content = $child->cloneNode(true);
            if ( ! $content instanceof DOMElement ) {
                continue;
            }

            foreach ( $this->nestedListRoots($content) as $nestedList ) {
                $content->removeChild($nestedList);
            }

            foreach ( $content->getElementsByTagName('*') as $descendant ) {
                if ( ! $descendant instanceof DOMElement ) {
                    continue;
                }

                $tagName = strtolower($descendant->tagName);
                if ( 'a' !== $tagName && 'br' !== $tagName && ! $this->isInlineContentElement($tagName) ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isStructuralListItem(DOMElement $item): bool
    {
        $list = $item->parentNode;
        return $list instanceof DOMElement
            && in_array(strtolower($list->tagName), array( 'ul', 'ol' ), true)
            && $this->listContainsStructuralItemContent($list);
    }

    /** @param array<int, array<string, mixed>> $fallbacks @return array<string, mixed> */
    private function decomposeStructuralList(DOMElement $list, array &$fallbacks): array
    {
        $items = array();
        foreach ( $list->childNodes as $child ) {
            if ( ! $child instanceof DOMElement || 'li' !== strtolower($child->tagName) ) {
                continue;
            }

            $children = $this->convertChildren($child, $fallbacks, true);
            if ( array() !== $children ) {
                $items[] = $this->createBlock(
                    'core/group',
                    array_merge($this->cssOwnedGroupAttributes($child), array( 'tagName' => 'li' )),
                    $children,
                    $child
                );
            }
        }

        return $this->createBlock(
            'core/group',
            array_merge($this->cssOwnedGroupAttributes($list), array( 'tagName' => strtolower($list->tagName) )),
            $items,
            $list
        );
    }

    private function listItemContentWithoutNestedLists(DOMElement $item): string
    {
        $content = $item->cloneNode(true);
        if ( ! $content instanceof DOMElement ) {
            return $this->innerHtmlWithoutTags($item, array( 'ul', 'ol' ));
        }

        $directLists = array();
        foreach ( $content->childNodes as $child ) {
            if ( $child instanceof DOMElement && in_array(strtolower($child->tagName), array( 'ul', 'ol' ), true) ) {
                $directLists[] = $child;
            }
        }
        foreach ( $directLists as $directList ) {
            $content->removeChild($directList);
        }

        // Wrapped rich HTML remains inside core/list-item content so its authored
        // topology survives. Materialize every source-tag marker that author CSS
        // rewrites, not just nested list leaves.
        foreach ( $content->getElementsByTagName('*') as $descendant ) {
            if ( ! $descendant instanceof DOMElement ) {
                continue;
            }
            $marker = $this->authorSelectorProjections()->tagMarker($descendant->tagName);
            if ( '' !== $marker ) {
                $descendant->setAttribute('class', $this->mergeClassNames($this->attr($descendant, 'class'), $marker));
            }
        }

        return $this->richTextContentWithMaterializedInlineStyles($content);
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

            if ( $this->hasBlockContentChildren($child) || $this->richTextContentHasStructuralHtml($this->innerHtml($child)) ) {
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

        return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($list), $itemGroups, $list);
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

        return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($item), $fragmentBlocks, $item);
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
            $mapped = $this->styleResolver->styleAttributeMapper()->map($this->styleResolver->cssDeclarations($style))['style'];
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
        $this->fallbackEmitter()->captureInlineSvgFallback($element, $fallbacks);
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

    private function hasWorkspaceSurface(DOMElement $element): bool
    {
        foreach ( $this->descendantElements($element) as $descendant ) {
            $tagName = strtolower($descendant->tagName);
            if ( in_array($tagName, array( 'canvas', 'iframe', 'template' ), true) ) {
                return true;
            }
            if ( 'textarea' === $tagName && $this->runtimeIslands->textareaIsRuntimeWorkspaceSurface($descendant, $element) ) {
                return true;
            }
            if ( '' !== trim($this->attr($descendant, 'contenteditable')) ) {
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function requiredScriptsForElement(DOMElement $element): array
    {
        return $this->fallbackEmitter()->requiredScriptsForElement($element);
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
     * @param array<int, array<string, mixed>> $fallbacks
     */
    private function captureScriptFallback(DOMElement $element, array &$fallbacks): void
    {
        $this->fallbackEmitter()->captureScriptFallback($element, $fallbacks, $this->runtimeDom());
    }

    private function captureStaticScriptMetadata(DOMElement $element): bool
    {
        $metadata = $this->fallbackEmitter()->staticScriptMetadata($element);
        if ( null === $metadata ) {
            return false;
        }

        $this->runtimeBehavior()->recordScriptMetadata($metadata);

        return true;
    }

    /**
     * Keep a static JSON script in the page only when a carried runtime script
     * addresses its id. JSON script types never execute, unlike static JavaScript
     * assignments that remain metadata-only.
     */
    private function isAddressableStaticJsonTarget(DOMElement $element): bool
    {
        $id = trim($this->attr($element, 'id'));
        $type = strtolower(trim($this->attr($element, 'type')));
        if ( '' === $id || ! in_array($type, array('application/json', 'application/ld+json'), true) || ! $this->runtimeSelectors()->hasDom('#' . $id) ) {
            return false;
        }

        $metadata = $this->runtimeBehavior()->latestScriptMetadata();
        if ( null === $metadata || ! empty($metadata['body_truncated']) ) {
            return false;
        }

        return null !== json_decode((string) ($metadata['body'] ?? ''), true);
    }

    private function staticJsonTargetBlock(DOMElement $element): array
    {
        $metadata = $this->runtimeBehavior()->latestScriptMetadata() ?? array();
        $attributes = is_array($metadata['attributes'] ?? null) ? $metadata['attributes'] : array();
        ksort($attributes, SORT_STRING);
        $attributeHtml = '';
        foreach ( $attributes as $name => $value ) {
            if ( ! is_string($name) || ! is_string($value) ) {
                continue;
            }
            $attributeHtml .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        $body = str_replace('</script', '<\\/script', (string) ($metadata['body'] ?? ''));

        return $this->createBlock('core/html', array('content' => '<script' . $attributeHtml . '>' . $body . '</script>'), array(), $element);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     */
    private function captureTemplateFallback(DOMElement $element, array &$fallbacks): void
    {
        $this->fallbackEmitter()->captureTemplateFallback($element, $fallbacks, $this->runtimeDom());
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

    private function htmlPreservationBlock(DOMElement $element): array
    {
        return $this->createBlock('core/html', array( 'content' => $this->safeFallbackHtml($element) ), array(), $element);
    }

    private function backgroundImageBlockFromElement(DOMElement $element): ?array
    {
        $declarations = $this->styleResolver->presentationDeclarations($element);
        $url = $this->backgroundImageExtractor->urlFromStyle($this->styleResolver->mergedPresentationStyle($element));
        if ( '' === $url ) {
            return null;
        }

        $width = trim((string) ($declarations['width'] ?? ''));
        $height = trim((string) ($declarations['height'] ?? ''));
        $scale = strtolower(trim((string) ($declarations['background-size'] ?? '')));

        return $this->createBlock('core/image', array_filter(array(
            'url'       => $this->resolvedAssetImageUrl($url),
            'alt'       => $this->backgroundImageExtractor->altFromAttributes($this->htmlAttributes($element)),
            'className' => 'blocks-engine-background-image',
            'width'     => ! in_array(strtolower($width), array( '', 'auto' ), true) ? $width : '',
            'height'    => ! in_array(strtolower($height), array( '', 'auto' ), true) ? $height : '',
            'scale'     => in_array($scale, array( 'cover', 'contain' ), true) ? $scale : '',
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
        if ( null === $children || ! $this->hasEqualWidthFlexColumnsGeometry($element, $children) ) {
            return null;
        }

        // core/columns is a flex layout; WordPress rejects it with is-layout-grid.
        // Decline when the resolved layout is grid so the container demotes to
        // core/group, where grid layout is native.
        $layout = $this->styleResolver->presentationAttributes($element)['layout'] ?? null;
        if ( is_array($layout) && 'grid' === (string) ($layout['type'] ?? '') ) {
            return null;
        }

        $rowFallbacks = array();
        $columns = array();
        foreach ( $children as $child ) {
            $converted = array_filter(array( $this->convertElement($child, $rowFallbacks, true) ));
            if ( array() === $converted ) {
                return null;
            }

            $columns[] = $this->createBlock('core/column', $this->styleResolver->presentationAttributes($child), $converted, $child);
        }
        array_push($fallbacks, ...$rowFallbacks);

        return $this->createBlock('core/columns', $this->styleResolver->presentationAttributes($element), $columns, $element);
    }

    /**
     * core/columns gives each child an equal share of its row. Name/price and
     * label/value semantics alone say nothing about that geometry: ordinary
     * block flow is stacked, and flex items retain their content-sized basis.
     * Restrict this decomposition to the source layout signal that core/columns
     * can reproduce: a horizontal, non-wrapping flex row with equal zero-basis
     * flex items.
     *
     * @param array<int, DOMElement> $children
     */
    private function hasEqualWidthFlexColumnsGeometry(DOMElement $element, array $children): bool
    {
        $container = $this->styleResolver->structuralPresentationDeclarations($element);
        if ( 'flex' !== strtolower(trim((string) ($container['display'] ?? ''))) ) {
            return false;
        }

        $direction = strtolower(trim((string) ($container['flex-direction'] ?? 'row')));
        $wrap = strtolower(trim((string) ($container['flex-wrap'] ?? 'nowrap')));
        if ( ! in_array($direction, array( 'row', 'row-reverse' ), true) || 'nowrap' !== $wrap ) {
            return false;
        }

        $flex = null;
        foreach ( $children as $child ) {
            $childFlex = $this->equalWidthFlexSignal($this->styleResolver->structuralPresentationDeclarations($child));
            if ( null === $childFlex || ( null !== $flex && $flex !== $childFlex ) ) {
                return false;
            }
            $flex = $childFlex;
        }

        return null !== $flex;
    }

    /**
     * @param array<string, string> $declarations
     */
    private function equalWidthFlexSignal(array $declarations): ?string
    {
        $flex = preg_replace('/\s+/', ' ', strtolower(trim((string) ($declarations['flex'] ?? '')))) ?? '';
        if ( preg_match('/^([1-9][0-9]*(?:\.[0-9]+)?)(?: [0-9]+(?:\.[0-9]+)? (?:0|0%|0px))?$/', $flex, $matches) ) {
            return $matches[1];
        }

        $grow = trim((string) ($declarations['flex-grow'] ?? ''));
        $basis = strtolower(trim((string) ($declarations['flex-basis'] ?? '')));
        if ( is_numeric($grow) && 0 < (float) $grow && in_array($basis, array( '0', '0%', '0px' ), true) ) {
            return $grow;
        }

        return null;
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
        $overlayMenu = $this->navigationOverlayMenu($element);
        $navigationAttrs = array( 'overlayMenu' => $overlayMenu );
        if ( 'mobile' === $overlayMenu ) {
            $navigationAttrs['className'] = 'blocks-engine-native-responsive-navigation';
        }
        $blocks[] = $this->createBlock('core/navigation', $navigationAttrs, $links, $element);

        return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($element), array_values(array_filter($blocks)), $element);
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

        $attrs = array_filter(array_merge($this->styleResolver->presentationAttributes($element), array(
            'src'      => $src,
            'poster'   => 'video' === $tagName ? $this->safeImageUrl($this->attr($element, 'poster')) : '',
            'preload'  => $this->attr($element, 'preload'),
            'width'    => $this->attr($element, 'width'),
            'height'   => $this->attr($element, 'height'),
            'controls' => $element->hasAttribute('controls'),
        )), static fn (mixed $value): bool => is_bool($value) ? $value : '' !== $value);

        if ( 'video' === $tagName ) {
            foreach ( array( 'autoplay', 'loop', 'muted' ) as $attribute ) {
                if ( $element->hasAttribute($attribute) ) {
                    $attrs[$attribute] = true;
                }
            }
            if ( $element->hasAttribute('playsinline') ) {
                $attrs['playsInline'] = true;
            }
            $tracks = $this->videoTracks($element);
            if ( array() !== $tracks ) {
                $attrs['tracks'] = $tracks;
            }
        }

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

        $attrs = array_filter(array_merge($this->styleResolver->presentationAttributes($anchor), array(
            'href'               => $href,
            'fileName'           => $this->richTextContentWithMaterializedInlineStyles($anchor),
            'textLinkHref'       => $href,
            'showDownloadButton' => $anchor->hasAttribute('download'),
        )), static fn (mixed $value): bool => is_bool($value) ? true : '' !== $value);

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

        if ( $this->hasPictureSourceSelection($picture) ) {
            return $this->responsiveMediaBlock($link ?? $figure ?? $picture);
        }

        return $this->convertImageElement($image, $figure ?? $picture, $picture, $link);
    }

    private function imageBlockFromAnchor(DOMElement $anchor): ?array
    {
        $href = $this->safeLinkUrl($this->attr($anchor, 'href'));
        if ( ! $this->isImageOnlyAnchor($anchor) ) {
            return null;
        }
        if ( '' === $href ) {
            $picture = $this->firstChildElement($anchor, 'picture');
            if ( $picture instanceof DOMElement ) {
                $image = $this->firstChildElement($picture, 'img');
                return $image instanceof DOMElement ? $this->convertImageElement($image, null, $picture) : null;
            }
            $image = $this->firstChildElement($anchor, 'img');
            return $image instanceof DOMElement ? $this->convertImageElement($image) : null;
        }
        $link = '' !== $href ? $anchor : null;

        $picture = $this->firstChildElement($anchor, 'picture');
        if ( $picture instanceof DOMElement ) {
            $image = $this->firstChildElement($picture, 'img');
            return $image instanceof DOMElement ? $this->responsiveMediaBlock($anchor) : null;
        }

        $image = $this->firstChildElement($anchor, 'img');
        if ( ! $image instanceof DOMElement ) {
            foreach ( $anchor->childNodes as $child ) {
                if ( $child instanceof DOMElement ) {
                    $image = $this->imageOnlyCarrierElement($child);
                    if ( $image instanceof DOMElement ) {
                        break;
                    }
                }
            }
        }
        return $image instanceof DOMElement ? $this->responsiveMediaBlock($anchor) : null;
    }

    private function isImageOnlyAnchor(DOMElement $anchor): bool
    {
        $imageChildren = 0;
        foreach ( $anchor->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                if ( ! in_array(strtolower($child->tagName), array( 'img', 'picture' ), true) && ! ( $this->imageOnlyCarrierElement($child) instanceof DOMElement ) ) {
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
        if ( $picture instanceof DOMElement && $this->hasPictureSourceSelection($picture) ) {
            return $this->responsiveMediaBlock($link ?? $figure ?? $picture ?? $image);
        }

        $originalUrl = $this->imageSourceUrl($image);
        $url = $this->resolvedAssetImageUrl($originalUrl);
        if ( '' === $url ) {
            return null;
        }

        $attrs = $this->imagePresentationAttributes($image, $figure);
        if ( null !== $picture && ! $figure instanceof DOMElement ) {
            $attrs = array_merge($this->styleResolver->presentationAttributes($picture), $attrs);
        }
        $linked = $link instanceof DOMElement;
        $width = $this->imageDisplayDimension($image, 'width', $linked);
        $height = $this->imageDisplayDimension($image, 'height', $linked);
        if ( '' !== $width || '' !== $height ) {
            $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), 'is-resized');
        }

        $attrs = array_filter(array_merge($attrs, array(
            'url'    => $url,
            'alt'    => $this->attr($image, 'alt'),
            'title'  => $this->attr($image, 'title'),
            'width'  => $width,
            'height' => $height,
        )), static fn ($value): bool => '' !== $value);

        $attrs = array_filter(array_merge($attrs, $this->imageIdentityAttributes($image, $figure)), static fn ($value): bool => '' !== $value);
        $attrs = array_filter(array_merge($attrs, $this->assetMetadataImageAttributes($originalUrl)), static fn ($value): bool => '' !== $value);

        // A shape constraint (aspect-ratio + object-fit) applied to the <img> via
        // a wrapper the transform flattens (e.g. `.hero-frame img`) is lost once
        // the carrier element is gone. Promote it to native aspectRatio/scale
        // attributes so WordPress reproduces the authored crop. `+` never
        // overrides an attribute already resolved above.
        $attrs += $this->imageShapeConstraintAttributes($image);

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

    private function imageDisplayDimension(DOMElement $image, string $property, bool $linked): string
    {
        $inline = trim($this->cssValueWithoutImportant((string) ($this->styleResolver->cssDeclarations($this->attr($image, 'style'))[ $property ] ?? '')));
        if ( '' !== $inline && ! in_array(strtolower($inline), array( 'auto', 'inherit', 'initial', 'unset', 'revert', 'revert-layer' ), true) ) {
            return ! $linked && preg_match('/^(?:\d+|\d*\.\d+)$/', $inline) ? $inline . 'px' : $inline;
        }
        $attribute = trim($this->attr($image, $property));
        return ! $linked && preg_match('/^(?:\d+|\d*\.\d+)$/', $attribute) ? $attribute . 'px' : $attribute;
    }

    /** @return array<string, mixed> */
    private function applyIntrinsicVisualMediaHeight(DOMElement $element, array $attrs): array
    {
        $geometry = array();
        $presentation = $this->styleResolver->presentationDeclarations($element);
        $inline = $this->styleResolver->cssDeclarations($this->attr($element, 'style'));
        foreach ( array( 'height', 'min-height' ) as $property ) {
            $family = $this->styleResolver->responsivePropertyFamily($property);
            if ( array() !== $this->sourceStyles()->conditionalRules()
                && $this->styleResolver->hasConditionalStyleFamily($element, $family)
                && ! $this->styleResolver->inlineOwnsResponsiveProperty($property, $family, $inline)
            ) {
                continue;
            }
            $value = trim($this->cssValueWithoutImportant((string) ($presentation[ $property ] ?? '')));
            if ( preg_match('/^(?:\d+|\d*\.\d+)(?:px)?$/', $value) && 0.0 < (float) $value ) {
                $geometry[ $property ] = str_ends_with($value, 'px') ? $value : $value . 'px';
            }
        }
        $height = $this->intrinsicVisualMediaHeight($element);
        if ( '' !== $height && ! isset($geometry['height']) && ! isset($geometry['min-height']) ) {
            $geometry['min-height'] = $height;
        }
        if ( array() === $geometry ) {
            return $attrs;
        }

        $carrier = $this->styleResolver->inlineGeometryClassName(
            $element,
            array(),
            array_keys($geometry),
            $geometry
        );
        if ( '' !== $carrier ) {
            $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), $carrier);
        }
        return $attrs;
    }

    private function intrinsicVisualMediaHeight(DOMElement $element): string
    {
        $parent = $element->parentNode;
        if ( $parent instanceof DOMElement ) {
            $parentPosition = strtolower(trim((string) ($this->styleResolver->structuralPresentationDeclarations($parent)['position'] ?? '')));
            if ( in_array($parentPosition, array( 'absolute', 'fixed' ), true) ) {
                return '';
            }
        }
        $own = $this->styleResolver->presentationDeclarations($element);
        foreach ( array( 'height', 'min-height' ) as $property ) {
            $value = trim($this->cssValueWithoutImportant((string) ($own[ $property ] ?? '')));
            if ( preg_match('/^(?:\d+|\d*\.\d+)(?:px)?$/', $value) && 0.0 < (float) $value ) {
                return '';
            }
        }
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && $this->hasInFlowContent($child) ) {
                return '';
            }
        }

        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            $position = strtolower(trim((string) ($this->styleResolver->structuralPresentationDeclarations($child)['position'] ?? '')));
            if ( ! in_array($position, array( 'absolute', 'fixed' ), true) ) {
                continue;
            }
            foreach ( $child->getElementsByTagName('img') as $image ) {
                if ( ! $image instanceof DOMElement ) {
                    continue;
                }
                for ( $carrier = $image->parentNode; $carrier instanceof DOMElement && $carrier !== $child; $carrier = $carrier->parentNode ) {
                    $carrierPosition = strtolower(trim((string) ($this->styleResolver->structuralPresentationDeclarations($carrier)['position'] ?? '')));
                    if ( 'sticky' === $carrierPosition ) {
                        continue 2;
                    }
                }
                $height = trim($this->cssValueWithoutImportant((string) ($this->styleResolver->cssDeclarations($this->attr($image, 'style'))['height'] ?? '')));
                if ( preg_match('/^(?:\d+|\d*\.\d+)$/', $height) ) {
                    $height .= 'px';
                }
                if ( preg_match('/^(?:\d+|\d*\.\d+)px$/', $height) && 0.0 < (float) $height ) {
                    return $height;
                }
            }
        }

        return '';
    }

    private function hasInFlowContent(DOMElement $element): bool
    {
        $position = strtolower(trim((string) ($this->styleResolver->structuralPresentationDeclarations($element)['position'] ?? '')));
        if ( in_array($position, array( 'absolute', 'fixed' ), true) ) {
            return false;
        }
        if ( in_array(strtolower($element->tagName), array( 'img', 'picture', 'svg', 'video', 'audio', 'iframe', 'canvas' ), true) ) {
            return true;
        }
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                if ( $this->hasInFlowContent($child) ) {
                    return true;
                }
            } elseif ( '' !== trim($child->textContent ?? '') ) {
                return true;
            }
        }
        return false;
    }

    private function imageSourceUrl(DOMElement $image): string
    {
        $src = $this->safeImageUrl($this->attr($image, 'src'));
        if ( '' !== $src && ! str_starts_with(strtolower($src), 'data:') ) {
            return $src;
        }

        foreach ( array( 'data-src', 'data-lazy-src', 'data-original', 'data-image-src' ) as $attribute ) {
            $candidate = $this->safeImageUrl($this->attr($image, $attribute));
            if ( '' !== $candidate ) {
                return $candidate;
            }
        }

        return $src;
    }

    private function hasPictureSourceSelection(DOMElement $element): bool
    {
        foreach ( $element->getElementsByTagName('source') as $source ) {
            if ( $source instanceof DOMElement && '' !== $this->attr($source, 'srcset') ) {
                return true;
            }
        }

        return false;
    }

    private function imageOnlyCustomElement(DOMElement $element): ?DOMElement
    {
        if ( ! str_contains($element->tagName, '-') || '' !== trim($element->textContent ?? '') ) {
            return null;
        }

        $images = $element->getElementsByTagName('img');
        if ( 1 !== $images->length || ! $images->item(0) instanceof DOMElement ) {
            return null;
        }

        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement && ! in_array(strtolower($descendant->tagName), array( 'img', 'picture', 'source' ), true) ) {
                return null;
            }
        }

        return $images->item(0);
    }

    private function customVideoElement(DOMElement $element): ?DOMElement
    {
        if ( ! str_contains($element->tagName, '-') || '' !== trim($element->textContent ?? '') || ! $this->isSafeTransparentCustomElement($element) ) {
            return null;
        }

        $videos = $element->getElementsByTagName('video');
        if ( 1 !== $videos->length || ! $videos->item(0) instanceof DOMElement ) {
            return null;
        }

        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement && ! in_array(strtolower($descendant->tagName), array( 'video', 'source', 'track' ), true) ) {
                return null;
            }
        }

        return $videos->item(0);
    }

    private function hasTransparentCustomVideoHostPresentation(DOMElement $element): bool
    {
        // Flattening the host drops its box. A class, id, inline declaration, or
        // matched author rule means that box may carry presentation we cannot
        // faithfully move onto core/video.
        if ( '' !== $this->attr($element, 'class') || '' !== $this->attr($element, 'id') || '' !== $this->attr($element, 'style') || array() !== $this->styleResolver->structuralPresentationDeclarations($element) ) {
            return false;
        }

        foreach ( $this->styleResolver->styleRuleCandidates($element, 'static-conditional') as $rule ) {
            if ( $this->styleResolver->matchesCssSelector($element, $rule['selector']) && array() !== ($rule['declarations'] ?? array()) ) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, array<string, mixed>> */
    private function videoTracks(DOMElement $video): array
    {
        $tracks = array();
        foreach ( $video->getElementsByTagName('track') as $track ) {
            if ( ! $track instanceof DOMElement || 32 === count($tracks) ) {
                break;
            }
            $src = $this->safeMediaUrl($this->attr($track, 'src'));
            if ( '' === $src ) {
                continue;
            }
            $tracks[] = array_filter(array(
                'kind'    => $this->attr($track, 'kind'),
                'src'     => $src,
                'srcLang' => $this->attr($track, 'srclang'),
                'label'   => $this->attr($track, 'label'),
                'default' => $track->hasAttribute('default'),
            ), static fn (mixed $value): bool => is_bool($value) ? $value : '' !== $value);
        }

        return $tracks;
    }

    private function imageOnlyCarrierElement(DOMElement $element): ?DOMElement
    {
        $customImage = $this->imageOnlyCustomElement($element);
        if ( $customImage instanceof DOMElement ) {
            return $customImage;
        }
        if ( ! in_array(strtolower($element->tagName), array( 'div', 'span' ), true) || '' !== trim($element->textContent ?? '') ) {
            return null;
        }

        $image = null;
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                if ( '' !== trim($child->textContent ?? '') ) {
                    return null;
                }
                continue;
            }

            $candidate = 'img' === strtolower($child->tagName) ? $child : $this->imageOnlyCarrierElement($child);
            if ( ! $candidate instanceof DOMElement || $image instanceof DOMElement ) {
                return null;
            }
            $image = $candidate;
        }

        return $image;
    }

    private function isImageCarrierButton(DOMElement $element): bool
    {
        if ( '' !== trim($element->textContent ?? '') || 'submit' === strtolower($this->attr($element, 'type')) ) {
            return false;
        }

        return 0 < $element->getElementsByTagName('img')->length;
    }

    private function hasResponsiveImageSources(DOMElement $element): bool
    {
        if ( 'img' === strtolower($element->tagName) ) {
            return '' !== $this->attr($element, 'srcset') || '' !== $this->attr($element, 'sizes');
        }

        if ( $this->hasPictureSourceSelection($element) ) {
            return true;
        }

        foreach ( $element->getElementsByTagName('img') as $image ) {
            if ( $image instanceof DOMElement && ( '' !== $this->attr($image, 'srcset') || '' !== $this->attr($image, 'sizes') ) ) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function responsiveMediaBlock(DOMElement $element): array
    {
        if ( $this->hasUnsafeResponsiveImageSources($element) ) {
            return $this->responsiveImageFallbackBlock($element);
        }

        $this->generatedBlocks()->register(ResponsiveMediaBlockGenerator::class, ( new ResponsiveMediaBlockGenerator() )->definition($this->generatedBlocks()->namespace()));

        return $this->createBlock(
            $this->generatedBlocks()->blockName(ResponsiveMediaBlockGenerator::LOCAL_NAME),
            array( 'content' => $this->safeFallbackHtml($element), 'kind' => 'media' ),
            array(),
            $element
        );
    }

    /** @return array<string, mixed>|null */
    private function capturedMediaLayoutBoundaryBlock(DOMElement $element): ?array
    {
        if ( ! in_array(strtolower($element->tagName), array('main', 'article', 'section', 'div', 'figure'), true)
            || $this->runtimeIslands->isRuntimeDomTarget($element)
            || $this->hasRuntimeTargetInSubtree($element)
            || $this->hasLayoutGeometryProofInSubtree($element)
            || $this->sourceElementNestingDepth($element) <= self::MAX_CAPTURED_LAYOUT_SOURCE_NESTING
            || ! $this->hasCapturedMediaContent($element)
            || ('main' !== strtolower($element->tagName) && '' === trim((string) $element->textContent))
        ) {
            return null;
        }

        if ( ! $this->isStaticLayoutV1($element) ) {
            return null;
        }

        $this->generatedBlocks()->register(ResponsiveLayoutBlockGenerator::class, ( new ResponsiveLayoutBlockGenerator() )->definition($this->generatedBlocks()->namespace()));

        return $this->createBlock(
            $this->generatedBlocks()->blockName(ResponsiveLayoutBlockGenerator::LOCAL_NAME),
            array( 'content' => $this->staticLayoutHtml($element) ),
            array(),
            $element
        );
    }

    /**
     * The responsive-layout renderer accepts this fixed static-layout-v1
     * subset. Keep admission narrower than fallback sanitizing
     * so a captured layout never depends on stripped source semantics.
     */
    private function isStaticLayoutV1(DOMElement $element): bool
    {
        $tags = array(
            'main', 'article', 'aside', 'section', 'header', 'footer', 'nav', 'div', 'figure',
            'figcaption', 'p', 'span', 'strong', 'em', 'b', 'i', 'small', 'br',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'a', 'button', 'ul', 'ol', 'li',
            'dl', 'dt', 'dd', 'picture', 'source', 'img', 'video', 'audio',
            'svg', 'defs', 'symbol', 'lineargradient', 'radialgradient', 'stop', 'clippath',
            'mask', 'use', 'g', 'path', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
            'rect', 'text', 'tspan', 'title', 'desc', 'link',
        );
        $globalAttributes = array(
            'class', 'id', 'role', 'title', 'style', 'tabindex', 'dir', 'lang', 'hidden', 'xml:lang',
            'aria-controls', 'aria-current', 'aria-describedby', 'aria-details', 'aria-expanded',
            'aria-hidden', 'aria-label', 'aria-labelledby', 'aria-live',
        );
        $tagAttributes = array(
            'a' => array('download', 'href', 'target', 'rel'),
            'button' => array('disabled', 'name', 'type', 'value'),
            'img' => array('src', 'alt', 'width', 'height', 'loading', 'decoding', 'fetchpriority', 'longdesc', 'srcset', 'sizes', 'usemap'),
            'source' => array('src', 'srcset', 'sizes', 'media', 'type'),
            'video' => array('autoplay', 'controls', 'height', 'loop', 'muted', 'playsinline', 'poster', 'preload', 'src', 'width'),
            'audio' => array('autoplay', 'controls', 'loop', 'muted', 'preload', 'src'),
            'svg' => array('fill', 'stroke', 'viewbox', 'width', 'height', 'focusable', 'preserveaspectratio', 'xmlns', 'xmlns:xlink'),
            'symbol' => array('viewbox'),
            'lineargradient' => array('gradientunits', 'x1', 'x2', 'y1', 'y2'),
            'radialgradient' => array('cx', 'cy', 'r'),
            'stop' => array('offset', 'stop-color', 'stop-opacity'),
            'use' => array('href', 'xlink:href'),
            'g' => array('clip-path', 'fill', 'fill-opacity', 'opacity', 'stroke', 'stroke-width', 'transform'),
            'path' => array('d', 'fill', 'fill-rule', 'opacity', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'transform'),
            'circle' => array('cx', 'cy', 'r', 'fill', 'opacity', 'stroke', 'stroke-width'),
            'ellipse' => array('cx', 'cy', 'rx', 'ry', 'fill', 'opacity', 'stroke', 'stroke-width'),
            'line' => array('x1', 'x2', 'y1', 'y2', 'fill', 'stroke', 'stroke-width', 'stroke-linecap'),
            'polyline' => array('points', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin'),
            'polygon' => array('points', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin'),
            'rect' => array('x', 'y', 'width', 'height', 'rx', 'ry', 'fill', 'opacity', 'stroke', 'stroke-width'),
            'text' => array('fill', 'font-family', 'font-size', 'font-weight', 'text-anchor', 'x', 'y'),
            'tspan' => array('dx', 'dy', 'fill', 'x', 'y'),
            'link' => array('href', 'rel'),
        );

        foreach (array_merge(array($element), $this->descendantElements($element)) as $candidate) {
            $tag = strtolower($candidate->tagName);
            $customElement = (bool) preg_match('/^[a-z][a-z0-9]*-[a-z0-9-]+$/D', $tag);
            if ( (! $customElement && ! in_array($tag, $tags, true)) || $this->isDeclaredRuntimeDomTarget($candidate) || array() !== $this->eventMetadata($candidate) ) {
                return false;
            }
            if ('svg' === $tag && ! $this->isSafeSvgContent($this->outerHtml($candidate))) {
                return false;
            }
            if ('link' === $tag && ('stylesheet' !== strtolower($this->attr($candidate, 'rel')) || ! $this->hasAncestorTag($candidate, array('defs')) || ! $this->hasAncestorTag($candidate, array('svg')) || ! $this->safeFallbackUrl($this->attr($candidate, 'href'), 'href'))) {
                return false;
            }

            $allowed = array_merge($globalAttributes, $tagAttributes[$tag] ?? array());
            foreach ($this->htmlAttributes($candidate) as $attribute => $value) {
                $attribute = strtolower($attribute);
                if ( (! str_starts_with($attribute, 'data-') && ! str_starts_with($attribute, 'aria-') && ! in_array($attribute, $allowed, true))
                    || str_starts_with($attribute, 'data-wp-')
                    || ('srcset' === $attribute && $this->safeFallbackSrcset($value) !== $value)
                    || (in_array($attribute, array('href', 'src'), true) && ! $this->safeFallbackUrl($value, $attribute))
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    private function staticLayoutHtml(DOMElement $element): string
    {
        return preg_replace('/<link\b[^>]*\/?\s*>/i', '', $this->safeFallbackHtml($element)) ?? '';
    }

    private function hasLayoutGeometryProofInSubtree(DOMElement $element): bool
    {
        $prefix = $this->elementSelector($element) . ' > ';
        foreach ( $this->layoutGeometry()->proofReductions() as $proof ) {
            if ( is_array($proof) && str_starts_with((string) ($proof['wrapper_selector'] ?? ''), $prefix) ) {
                return true;
            }
        }
        return false;
    }

    private function hasCapturedMediaContent(DOMElement $element): bool
    {
        return 0 < $element->getElementsByTagName('img')->length
            || 0 < $element->getElementsByTagName('svg')->length
            || 0 < $element->getElementsByTagName('video')->length;
    }

    private function hasRuntimeTargetInSubtree(DOMElement $element): bool
    {
        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement && $this->isDeclaredRuntimeDomTarget($descendant) ) {
                return true;
            }
        }
        return false;
    }

    private function isDeclaredRuntimeDomTarget(DOMElement $element): bool
    {
        foreach (array_keys($this->runtimeSelectors()->domSelectors()) as $selector) {
            if (str_starts_with((string) $selector, '#') && substr((string) $selector, 1) === $this->attr($element, 'id')) {
                return true;
            }
            if (str_starts_with((string) $selector, '.') && in_array(substr((string) $selector, 1), $this->classNames($element), true)) {
                return true;
            }
            if ($this->runtimeIslands->elementMatchesRuntimeSelector($element, (string) $selector)) {
                return true;
            }
        }

        return false;
    }

    private function sourceElementNestingDepth(DOMElement $element): int
    {
        $depth = 0;
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                $depth = max($depth, 1 + $this->sourceElementNestingDepth($child));
            }
        }
        return $depth;
    }

    /** @return array<string, mixed>|null */
    private function authoredMarqueeBlock(DOMElement $element): ?array
    {
        $track = null;
        foreach ( $element->getElementsByTagName('*') as $candidate ) {
            if ( $candidate instanceof DOMElement && in_array($this->attr($candidate, 'data-marquee-animation'), array( 'left', 'right' ), true) ) {
                $track = $candidate;
                break;
            }
        }
        if ( ! $track instanceof DOMElement ) {
            return null;
        }

        $content = '';
        foreach ( $track->getElementsByTagName('*') as $candidate ) {
            if ( ! $candidate instanceof DOMElement || 'true' === $this->attr($candidate, 'aria-hidden') || 0 !== $candidate->childElementCount ) {
                continue;
            }
            $text = trim($candidate->textContent ?? '');
            if ( '' !== $text ) {
                $content = $this->runtime->escapeHtml($text);
                break;
            }
        }
        if ( '' === $content ) {
            return null;
        }

        $this->generatedBlocks()->register(AuthoredMarqueeBlockGenerator::class, ( new AuthoredMarqueeBlockGenerator() )->definition($this->generatedBlocks()->namespace()));

        $duration = 40.0;
        $durationCandidates = array( $this->styleResolver->cssDeclarations($this->attr($track, 'style'))['--marquee-duration'] ?? '' );
        for ( $carrier = $element; $carrier instanceof DOMElement && 'body' !== strtolower($carrier->tagName); $carrier = $carrier->parentNode instanceof DOMElement ? $carrier->parentNode : null ) {
            $durationCandidates[] = $this->styleResolver->cssDeclarations($this->attr($carrier, 'style'))['--marquee-duration'] ?? '';
        }
        foreach ( $durationCandidates as $value ) {
            if ( preg_match('/^([0-9]+(?:\.[0-9]+)?)s$/', trim((string) $value), $matches) ) {
                $duration = (float) $matches[1];
                break;
            }
        }

        $attributes = array(
            'content' => $content,
            'direction' => $this->attr($track, 'data-marquee-animation'),
            'duration' => min(600, max(1, $duration)),
        );
        $markup = ( new AuthoredMarqueeBlockGenerator() )->markup($attributes);

        return array(
            'blockName' => $this->generatedBlocks()->blockName(AuthoredMarqueeBlockGenerator::LOCAL_NAME),
            'attrs' => $attributes,
            'innerBlocks' => array(),
            'innerHTML' => $markup,
            'innerContent' => array( $markup ),
        );
    }

    /**
     * Preserve responsive sources as valid raw HTML rather than placing
     * unsupported attributes in a core/image save shape.
     *
     * @return array<string, mixed>
     */
    private function responsiveImageFallbackBlock(DOMElement $element): array
    {
        if ( ! $this->hasUnsafeResponsiveImageSources($element) ) {
            $generated = $this->fallbackEmitter()->maybeGenerateCustomBlock(
                $element,
                $this->generatedBlocks(),
                true
            );
            if ( null !== $generated ) {
                return $this->createBlock($generated['blockName'], $generated['attrs'], array(), $element);
            }
        }

        $boundedHtml = $this->boundedFallbackHtml($this->safeFallbackHtml($element));
        $selector = $this->elementSelector($element);
        if ( ! $this->transformationEvidence()->hasResponsiveImageFallback($selector) ) {
            $this->transformationEvidence()->recordResponsiveImageFallback($selector, FallbackDiagnostic::build(array(
                'type'            => 'html',
                'reason'          => 'responsive_image_fallback',
                'diagnostic_code' => 'html_responsive_image_fallback',
                'message'         => 'Responsive image sources were preserved as sanitized core/html because core/image cannot serialize srcset, sizes, or picture source selection.',
                'source_format'   => 'html',
                'tag'             => strtolower($element->tagName),
                'selector'        => $selector,
                'attributes'      => $this->htmlAttributes($element),
                'context'         => $this->sourceContext($element),
                'classification'  => $this->fallbackEmitter()->classifyFallbackSubtree($element),
                'html'            => $boundedHtml['html'],
                'html_bytes'      => $boundedHtml['bytes'],
                'html_truncated'  => $boundedHtml['truncated'],
            ), $this->transformationProvenance()->fallback()));
        }

        return $this->createBlock('core/html', array( 'content' => $this->safeFallbackHtml($element) ), array(), $element);
    }

    private function hasUnsafeResponsiveImageSources(DOMElement $element): bool
    {
        foreach ( $element->getElementsByTagName('*') as $candidate ) {
            if ( ! $candidate instanceof DOMElement || ! in_array(strtolower($candidate->tagName), array( 'img', 'source' ), true) ) {
                continue;
            }

            foreach ( SrcsetParser::parse($this->attr($candidate, 'srcset')) as $source ) {
                if ( '' === $this->safeImageUrl($source['url'])
                    || preg_match('/^(?:javascript|blob)\s*:/i', trim($source['url'])) ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isInertHiddenSvgStorage(DOMElement $element): bool
    {
        if ( ! $this->sourceElementStartsHidden($element)
            || $this->styleResolver->hasConditionalStyleFamily($element, 'layout')
            || $this->styleResolver->hasConditionalStyleFamily($element, 'visibility')
            || $this->styleResolver->hasConditionalStyleFamily($element, 'opacity')
            || '' !== trim($this->attr($element, 'aria-label'))
            || '' !== trim($this->attr($element, 'aria-labelledby'))
            || '' !== trim($this->attr($element, 'title'))
            || ! $this->hasOnlySvgDefinitions($element)
            || $this->hiddenSvgStoreHasExternalReference($element) ) {
            return false;
        }

        return true;
    }

    private function isInertHiddenCaptureIframe(DOMElement $element): bool
    {
        if ( ! $this->sourceElementStartsHidden($element)
            || $this->styleResolver->hasConditionalStyleFamily($element, 'layout')
            || $this->styleResolver->hasConditionalStyleFamily($element, 'visibility')
            || $this->styleResolver->hasConditionalStyleFamily($element, 'opacity')
            || $this->runtimeIslands->isRuntimeDomTarget($element)
            || '' !== trim($this->attr($element, 'src'))
            || '' !== trim($this->attr($element, 'srcdoc'))
            || '' !== trim($this->attr($element, 'name'))
            || '' !== trim($element->textContent ?? '')
            || 0 !== $this->childElementCount($element)
            || array() !== $this->eventMetadata($element)
            || array() !== $this->safeDataAttributes($element) ) {
            return false;
        }

        return true;
    }

    private function hasOnlySvgDefinitions(DOMElement $element): bool
    {
        $hasDefinition = false;
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }
            if ( ! $child instanceof DOMElement || ! in_array(strtolower($child->tagName), array( 'defs', 'symbol' ), true) ) {
                return false;
            }
            $hasDefinition = true;
        }

        return $hasDefinition;
    }

    private function hiddenSvgStoreHasExternalReference(DOMElement $store): bool
    {
        $ids = array();
        foreach ( $store->getElementsByTagName('*') as $definition ) {
            if ( $definition instanceof DOMElement && '' !== trim($this->attr($definition, 'id')) ) {
                $ids[] = trim($this->attr($definition, 'id'));
            }
        }
        if ( array() === $ids || ! $store->ownerDocument instanceof DOMDocument ) {
            return false;
        }

        foreach ( $store->ownerDocument->getElementsByTagName('*') as $candidate ) {
            if ( ! $candidate instanceof DOMElement || $this->isDescendantOf($candidate, $store) ) {
                continue;
            }
            foreach ( $candidate->attributes as $attribute ) {
                foreach ( $ids as $id ) {
                    if ( preg_match('/(?:^|[\\s,(])(?:url\\(\\s*["\']?#' . preg_quote($id, '/') . '["\']?\\s*\\)|#' . preg_quote($id, '/') . '(?:$|[\\s,)]))/', $attribute->value) ) {
                        return true;
                    }
                }
            }
            if ( 'style' === strtolower($candidate->tagName) ) {
                foreach ( $ids as $id ) {
                    if ( preg_match('/url\\(\\s*["\']?#' . preg_quote($id, '/') . '["\']?\\s*\\)/', $candidate->textContent ?? '') ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function isDescendantOf(DOMElement $element, DOMElement $ancestor): bool
    {
        for ( $node = $element; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( $node->isSameNode($ancestor) ) {
                return true;
            }
        }

        return false;
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
        );

        return array_filter($attrs, static fn (string $value): bool => '' !== trim($value));
    }

    private function safeLinkUrl(string $url): string
    {
        return LinkUrlSanitizer::sanitize($url);
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

        $this->transformationEvidence()->recordDroppedLinkWrapper(array_merge(
            array(
                'kind'     => 'source link wrapper dropped / content no longer navigable',
                'tag'      => strtolower($anchor->tagName),
                'selector' => $this->elementSelector($anchor),
            ),
            $link
        ));
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

        return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($anchor), $children, $anchor);
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

        $declarations = $this->styleResolver->presentationDeclarations($anchor);
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

        $facebookVideoUrl = $this->facebookPluginVideoUrl($url);
        if ( '' !== $facebookVideoUrl ) {
            return $facebookVideoUrl;
        }

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

    private function facebookPluginVideoUrl(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ( ! str_ends_with($host, 'facebook.com') || '/plugins/video.php' !== $path ) {
            return '';
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $videoUrl = $this->safeEmbedUrl(is_string($query['href'] ?? null) ? $query['href'] : '');
        $videoHost = strtolower((string) parse_url($videoUrl, PHP_URL_HOST));

        return str_ends_with($videoHost, 'facebook.com') ? $videoUrl : '';
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
        if ( '' !== $this->facebookPluginVideoUrl($url) ) {
            return 'facebook';
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
            return $this->createBlock('core/embed', array_filter(array_merge($this->styleResolver->presentationAttributes($iframe), array(
                'url'              => $this->canonicalEmbedUrl($url),
                'type'             => $this->embedTypeForSlug($providerNameSlug),
                'providerNameSlug' => $providerNameSlug,
            )), static fn ($value): bool => '' !== $value), array(), $iframe);
        }

        $boundedHtml = $this->boundedFallbackHtml($this->safeFallbackHtml($iframe));
        $this->runtimeIslands->recordRuntimeIsland($iframe, 'iframe', 'iframe_requires_embed_runtime', 'third_party_embed_runtime', array(
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
            'classification'  => $this->fallbackEmitter()->classifyFallbackSubtree($iframe),
            'events'          => $this->eventMetadata($iframe),
            'html'            => $boundedHtml['html'],
            'html_bytes'      => $boundedHtml['bytes'],
            'html_truncated'  => $boundedHtml['truncated'],
        ), $this->transformationProvenance()->fallback());

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
            $resolvedUrl = $this->resolvedAssetImageUrl($url);
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
        if ( '' === $resolvedUrl ) {
            return $url;
        }

        preg_match('/^[^?#]*(.*)$/s', $url, $parts);
        $resolvedUrl = $this->safeResolvedAssetImageUrl($resolvedUrl . ($parts[1] ?? ''));
        return '' !== $resolvedUrl ? $resolvedUrl : $url;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function assetMetadataForUrl(string $url): ?array
    {
        return $this->materializedAssets()->metadataForUrl($url);
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
        $attrs = $this->styleResolver->presentationAttributes($figure ?? $image);
        if ( $figure instanceof DOMElement ) {
            $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), $this->nonCoreImageFigureClassName($figure), $this->nonCoreImageClassName($image), ...$this->authorSemanticMarkersForElement($image));
        } else {
            $attrs['className'] = $this->styleResolver->mergePresentationClassNames((string) ($attrs['className'] ?? ''), $this->styleResolver->injectedFigureHeightClassName($image));
        }

        return array_filter($attrs, static fn ($value): bool => is_array($value) ? array() !== $value : '' !== trim((string) $value));
    }

    /**
     * Resolve the shape constraint the authored CSS applies to the <img> at the
     * desktop viewport, and carry it as native core/image attributes.
     *
     * This applies to ANY image whose resolved CSS carries the pair, however the
     * rule reaches it -- a rule on the image itself, a blanket `img` rule, or a
     * descendant rule. The flattened wrapper (`.hero-frame img`) is the
     * motivating case rather than the boundary: core/image has no slot for such
     * a wrapper, so once the carrier is dropped the constraint survives only as
     * block attributes. An image already matching the pair loses nothing by
     * having it stated natively.
     *
     * Conservative by design: emit aspectRatio/scale only when the resolved
     * style carries BOTH an explicit, well-formed aspect-ratio AND
     * object-fit:cover|contain. Plain images gain nothing.
     *
     * @return array<string, string>
     */
    private function imageShapeConstraintAttributes(DOMElement $image): array
    {
        $declarations = array(
            'aspect-ratio' => $this->imageShapeDeclaration($image, 'aspect-ratio'),
            'object-fit' => $this->imageShapeDeclaration($image, 'object-fit'),
        );
        $aspectRatio  = $this->normalizedAspectRatio(
            (string) $declarations['aspect-ratio']
        );
        // `object-fit:cover !important` is a common defence against core's
        // `.wp-block-image img` rules. Strip importance symmetrically with
        // normalizedAspectRatio, or the keyword never matches the allowlist below
        // and the whole promotion silently declines.
        $scale        = strtolower($this->cssValueWithoutImportant(
            (string) $declarations['object-fit']
        ));

        if ( ! in_array($scale, array( 'cover', 'contain' ), true) ) {
            return array();
        }

        if ( '' === $aspectRatio ) {
            $inlineScale = strtolower($this->cssValueWithoutImportant((string) ($this->styleResolver->cssDeclarations($this->attr($image, 'style'))['object-fit'] ?? '')));
            return $scale === $inlineScale ? array( 'scale' => $scale ) : array();
        }

        return array(
            'aspectRatio' => $aspectRatio,
            'scale'       => $scale,
        );
    }

    /** Resolve one crop declaration at the desktop viewport using the CSS cascade. */
    private function imageShapeDeclaration(DOMElement $element, string $property): string
    {
        $winner = null;
        foreach ($this->sourceStyles()->imageShapeRules() as $rule) {
            if ($property !== $rule['property'] || ! $this->styleResolver->matchesCssSelector($element, $rule['selector'])) {
                continue;
            }
            if (array() !== $rule['conditions']) {
                $minWidth = $this->conditionsDesktopMinWidth($rule['conditions']);
                if (null === $minWidth || $minWidth > self::DESKTOP_REFERENCE_WIDTH) {
                    continue;
                }
            }
            $candidate = array(
                'value' => $rule['value'],
                'specificity' => $this->styleResolver->mediaTextSelectorSpecificity($rule['selector']),
                'order' => $rule['order'],
                'inline' => false,
            );
            if ($this->imageShapeDeclarationWins($candidate, $winner)) {
                $winner = $candidate;
            }
        }
        $inlineEntries = $this->styleResolver->imageShapeDeclarationEntries($this->attr($element, 'style'));
        foreach ($inlineEntries as $index => $entry) {
            if ($property !== $entry['property']) {
                continue;
            }
            $candidate = array('value' => $entry['value'], 'specificity' => array(PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX), 'order' => PHP_INT_MAX - count($inlineEntries) + $index, 'inline' => true);
            if ($this->imageShapeDeclarationWins($candidate, $winner)) {
                $winner = $candidate;
            }
        }

        return is_array($winner) ? $winner['value'] : '';
    }

    /** @param array{value:string,specificity:array{int,int,int},order:int,inline:bool} $candidate @param array{value:string,specificity:array{int,int,int},order:int,inline:bool}|null $current */
    private function imageShapeDeclarationWins(array $candidate, ?array $current): bool
    {
        if (null === $current) {
            return true;
        }
        $candidateImportant = $this->cssValueIsImportant($candidate['value']);
        $currentImportant = $this->cssValueIsImportant($current['value']);
        if ($candidateImportant !== $currentImportant) {
            return $candidateImportant;
        }
        $specificity = $this->styleResolver->compareMediaTextSpecificity($candidate['specificity'], $current['specificity']);
        return 0 < $specificity || (0 === $specificity && $candidate['order'] >= $current['order']);
    }

    /**
     * The min-width (px) at which a conditional rule's `@media` prelude(s) begin
     * to apply, or null when a condition cannot be positively evaluated at the
     * desktop viewport. `@layer` is an unconditional grouping wrapper. A bounded
     * set of modern crop-relevant `@supports` tests is accepted; unknown feature
     * queries remain unresolved rather than being flattened. Nested conditions
     * must all qualify; the effective breakpoint is the widest.
     *
     * @param list<string> $conditions
     */
    private function conditionsDesktopMinWidth(array $conditions): ?int
    {
        $minWidth = 0;

        foreach ( $conditions as $condition ) {
            $condition = trim($condition);
            if ( 1 === preg_match('/^@layer\b/i', $condition) ) {
                continue;
            }
            if ( 1 === preg_match('/^@supports\b/i', $condition) ) {
                if ($this->supportsDesktopImageCropCondition($condition)) {
                    continue;
                }
                return null;
            }
            if ( 1 !== preg_match('/^@media\b/i', $condition) ) {
                return null;
            }
            // `not` inverts the feature test, so a min-width it names is the
            // breakpoint below which the rule applies -- the opposite of a
            // desktop override.
            if ( 1 === preg_match('/\bnot\b/i', $condition) ) {
                return null;
            }
            // Only `screen`/`all` describe the viewport the block renders at; a
            // `print` (or speech/tv) crop must never become the block's crop.
            if ( 1 === preg_match('/^@media\s+(?:only\s+)?([a-z-]+)/i', $condition, $typeMatch)
                && ! in_array(strtolower($typeMatch[1]), array( 'all', 'screen' ), true)
            ) {
                return null;
            }
            if ( 1 === preg_match('/max-width\s*:/i', $condition) ) {
                return null;
            }
            if ( 1 !== preg_match('/min-width\s*:\s*(\d+(?:\.\d+)?)\s*(px|r?em)\b/i', $condition, $matches) ) {
                return null;
            }
            // `em`/`rem` breakpoints resolve against the root font size in a
            // media query -- an `em` here is never the element's own font size.
            $breakpoint = (float) $matches[1];
            if ( 'px' !== strtolower($matches[2]) ) {
                $breakpoint *= self::ROOT_FONT_SIZE_PX;
            }
            $minWidth = max($minWidth, (int) round($breakpoint));
        }

        return $minWidth;
    }

    /** Bounded browser-capability facts used for crop rules under @supports. */
    private function supportsDesktopImageCropCondition(string $condition): bool
    {
        $query = strtolower(trim(preg_replace('/^@supports\s*/i', '', $condition) ?? ''));
        $query = preg_replace('/^\((.*)\)$/s', '$1', $query) ?? $query;
        [$property, $value] = array_pad(array_map('trim', explode(':', $query, 2)), 2, '');

        if ('display' === $property) {
            return in_array($value, array('flex', 'grid', 'inline-flex', 'inline-grid'), true);
        }
        if ('aspect-ratio' === $property) {
            return '' !== $this->normalizedAspectRatio($value);
        }
        if ('object-fit' === $property) {
            return in_array($value, array('cover', 'contain'), true);
        }

        return false;
    }

    private function cssValueWithoutImportant(string $value): string
    {
        return trim(preg_replace('/\s*!\s*important\s*$/i', '', $value) ?? $value);
    }

    private function cssValueIsImportant(string $value): bool
    {
        return 1 === preg_match('/\s*!\s*important\s*$/i', $value);
    }

    /**
     * Normalize a CSS aspect-ratio value to WordPress' attribute form (`4/3`,
     * `1`). Returns '' for auto/keyword/compound values the crop cannot rely on.
     *
     * A zero component is also refused. `aspect-ratio:0/3` is a degenerate ratio
     * that collapses the box to nothing; authored CSS gets away with it because
     * some other declaration constrains the element, but the promoted block
     * attribute carries no such company.
     */
    private function normalizedAspectRatio(string $value): string
    {
        $value = strtolower($this->cssValueWithoutImportant($value));
        if ( '' === $value || in_array($value, array( 'auto', 'inherit', 'initial', 'unset', 'revert', 'revert-layer', 'none' ), true) ) {
            return '';
        }

        if ( preg_match('#^(\d+(?:\.\d+)?)\s*/\s*(\d+(?:\.\d+)?)$#', $value, $matches) ) {
            if ( 0.0 === (float) $matches[1] || 0.0 === (float) $matches[2] ) {
                return '';
            }

            return $matches[1] . '/' . $matches[2];
        }

        if ( preg_match('#^\d+(?:\.\d+)?$#', $value) ) {
            return 0.0 === (float) $value ? '' : $value;
        }

        return '';
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
        $attrs = $this->styleResolver->presentationAttributes($pre);
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

<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionReportProjection;
use Automattic\BlocksEngine\PhpTransformer\Contract\EditabilityReport;
use Automattic\BlocksEngine\PhpTransformer\WordPress\CoreBlockCapabilityMatrix;
use Automattic\BlocksEngine\PhpTransformer\Contract\CoreHtmlFallbackEvidence;
use Automattic\BlocksEngine\PhpTransformer\Contract\TransformationOptions;
use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\SrcsetParser;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\ContentRoundTripReporter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\DiagnosticsCollector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackEmitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\SemanticParityReporter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\AccordionPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CallbackPatternRecognizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ButtonsPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CodeWindowPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ColumnsPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CoverPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\DetailsPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\GalleryPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\LogoPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\MathPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\MediaTextPattern;
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
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\SpacerPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\SocialLinksPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolutionTrait;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssSelectorMatchCache;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleAttributeMapper;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\BackgroundImageExtractor;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\ButtonLinkDispatchTrait;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\DomHelpersTrait;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\FormDispatchTrait;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\LinkUrlSanitizer;
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
    private const MAX_CAPTURED_LAYOUT_SOURCE_NESTING = 20;

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

    private const MAX_FORM_TOPOLOGY_DEPTH = 8;

    private const MAX_FORM_TOPOLOGY_NODES = 128;

    private const MAX_FORM_TOPOLOGY_CLASSES = 8;

    /** @var array<int, string> */
    private const FORM_TOPOLOGY_WRAPPER_TAGS = array(
        'article', 'aside', 'dd', 'div', 'dl', 'dt', 'fieldset', 'footer', 'header',
        'label', 'li', 'main', 'nav', 'ol', 'p', 'section', 'span', 'table', 'tbody',
        'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
    );

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

    private readonly ButtonsPattern $buttonsPattern;

    private readonly CodeWindowPattern $codeWindowPattern;

    private readonly ColumnsPattern $columnsPattern;

    private readonly CoverPattern $coverPattern;

    private readonly MediaTextPattern $mediaTextPattern;

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

    private readonly PatternContext $patternContext;

    private readonly PatternContext $patternContextWithoutRuntimeDomTarget;

    private readonly PatternContext $patternProbeContext;

    private readonly NavigationUnderlineColorResolver $navigationUnderlineColorResolver;

    private readonly NavigationBlockNormalizer $navigationBlockNormalizer;

    private readonly DiagnosticsCollector $diagnosticsCollector;

    private readonly SemanticParityReporter $semanticParityReporter;

    private readonly ContentRoundTripReporter $contentRoundTripReporter;

    private readonly ReusableComponentRecognizer $reusableComponentRecognizer;

    /** @var array<string, string> */
    private HtmlTransformerSession $session;

    /**
     * Text the transformer SYNTHESIZES from form controls (label + value/
     * placeholder/required state) rather than extracting from visible source.
     * Declared to the content round-trip reporter so it is not mistaken for
     * invented copy. Reset per transform().
     *
     * @var array<int, string>
     */


    /**
     * Responsive image markup core/image cannot represent without invalidating
     * its native save shape. Collected separately because image conversion is
     * also used by pattern callbacks that do not receive the fallback accumulator.
     *
     * @var array<int, array<string, mixed>>
     */

    /** @var array<string, bool> */

    /**
     * @var array<string, string>
     */

    /**
     * @var array<int, array<string, mixed>>
     */

    /**
     * Responsive/JS-revealed hidden base states normalized away during style
     * resolution (#259), surfaced for diagnostics.
     *
     * @var array<int, array<string, mixed>>
     */

    /**
     * Whole-element link wrappers (an <a> wrapping block-level content) whose
     * link could not be propagated onto any native link-bearing inner block, so
     * the resulting content is no longer navigable (#260). Surfaced for
     * diagnostics so the navigation loss is detectable and a downstream repair
     * loop can act on it, rather than emitted as an unsupported attribute.
     *
     * @var array<int, array<string, mixed>>
     */

    /**
     * @var array<int, array<string, mixed>>
     */

    /** @var array<int, bool> */

    /** @var array<string,true> */

    /**
     * @var array<int, array<string, mixed>>
     */

    /**
     * @var array<int, array<string, mixed>>
     */

    /**
     * @var array<int, array<string, mixed>>
     */

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

    /**
     * Generated static-render custom-block definitions produced at `core/html`
     * fallback decisions (issue #497). Surfaced under
     * `source_reports.generated_blocks` and packaged into the companion-plugin
     * payload by the ArtifactCompiler.
     *
     * @var array<int, array<string, mixed>>
     */






    /**
     * Block namespace for generated custom-block references. The ArtifactCompiler
     * sets this to the per-site companion-plugin namespace (`ssi-<site_slug>`) so
     * emitted references match the blocks SSI registers; standalone transforms
     * fall back to a generic namespace.
     */


    /**
     * @var array<int, array<string, mixed>>
     */

    /**
     * @var array<string, array<string, mixed>>
     */

    /**
     * @var array<string, array<string, mixed>>
     */

    /** @var array<string, string> */

    /** @var array<string, string> */

    /** @var array<string, string> Header anchor carriers keyed by generated class. */

    /** @var array<string, string> Header RichText carriers keyed by marker. */

    /**
     * @var array<int, array<string, mixed>>
     */

    /**
     * @var array<string, string>
     */

    /**
     * @var array<string, array<int, string>>
     */

    /**
     * @var array<int, array{selector: string, declarations: array<string, string>}>
     */

    /**
     * @var array<int, array{selector: string, declarations: array<string, string>}>
     */

    /**
     * @var list<array{selector: string, base_selector: string, state: string, declarations: array<string, string>}>
     */

    /** @var array<string, array<string, string>> */

    /** @var array<string, string> */

    /** @var array<string, string> */

    /** @var array<string, string> */

    /** @var array<string, string> */

    /** @var list<array{selector: string, property: string, value: string, conditions: list<string>, order: int}> Ordered crop declarations, including duplicates. */

    /**
     * @var array<int, array{selector: string, pseudo: string, declarations: array<string, string>}>
     */

    /**
     * @var array<string, bool>
     */

    /**
     * @var array<string, bool>
     */

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

    /** @var array<string, string> Source tag names whose serialized blocks need provenance classes. */

    private const SYNTHETIC_PARAGRAPH_CLASS = 'blocks-engine-synthetic-paragraph';

    private const SYNTHETIC_ANCHOR_UNDECORATED_CLASS = 'blocks-engine-synthetic-anchor-undecorated';

    private const SYNTHETIC_HEADER_ANCHOR_CLASS_PREFIX = 'blocks-engine-synthetic-header-anchor-';

    private const SYNTHETIC_IMAGE_FIGURE_CLASS = 'blocks-engine-synthetic-image-figure';

    private const INLINE_LAYOUT_CARRIER_CLASS = 'blocks-engine-inline-layout-carrier';

    private const POSITIONED_FRAGMENT_LINK_CARRIER_CLASS = 'blocks-engine-positioned-fragment-link-carrier';

    private const EMPTY_FLEX_ITEM_CLASS = 'blocks-engine-empty-flex-item';

    private const EMPTY_RUNTIME_TARGET_CLASS = 'blocks-engine-empty-runtime-target';

    private const CSS_OWNED_LAYOUT_CLASS = 'blocks-engine-css-owned-layout';

    private const CSS_OWNED_FLOW_CLASS = 'blocks-engine-css-owned-flow';

    private const CSS_OWNED_GRID_CLASS = 'blocks-engine-css-owned-grid';

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

    /** @var array<string, string> Source control DOM paths mapped to core/button wrapper classes. */

    /** @var array<string, string> Direct flex-child controls mapped to synthetic wrapper bridge CSS. */

    /** @var array<string, string> Full-width controls mapped to synthetic wrapper bridge CSS. */

    /** @var array<string, string> Source wrapper paths promoted into core/button. */

    /** @var array<string, true> Source controls that need selector projection. */

    /** @var array<string, string> CSS-addressed inline leaves keyed by stable source DOM path. */

    /** @var array<string, string> Structural elements addressed by non-serializable source data attributes. */

    /** @var array<string, string> Source body children that need wrapper-safe selector projection. */

    /** @var list<string> Source body-state classes referenced by authored CSS. */

    /** @var array<string, array{selector: string, min_width: string}> */

    /** @var array<string, string> Native tables whose descendant selectors need structural projection. */

    /** @var array<int, bool> */

    /** @var array<int, array<int, string>> */

    /** @var array<string, string> CSS-addressed RichText spans keyed by stable source DOM path. */

    /** @var array<int, array{selector: string, direct_child_count: int, block_child_count: int, source_tags: list<string>, block_tags: list<string>}> */




    /** @var list<DOMElement> */

	/** @var array<string, list<DOMElement>> */

	/** @var array<string, list<DOMElement>> */

	/** @var array<string, list<DOMElement>> */

	/** @var array<string, true> */

	/** @var array<string, true> */

	/** @var array<string, true> */

    /** @var array<string, list<DOMElement>> */

    /** @var array<string, array<string, mixed>> */


    /** @var list<array{selector:string,parsed:array<string,mixed>}> */

    /** @var list<array{order: int, declarations: array<string, string>, selectors: list<array{selector: string, parsed: array<string, mixed>, direct_child_parsed: array<string, mixed>}>}> */

    /** @var array<string, array<string, mixed>> */




    /** @var list<array{path: string, content: string, source_hash: string}> */


    /** A collision-checked custom element used solely to retain type specificity. */






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
        $this->buttonsPattern    = new ButtonsPattern();
        $this->codeWindowPattern = new CodeWindowPattern();
        $this->columnsPattern    = new ColumnsPattern();
        $this->coverPattern      = new CoverPattern();
        $this->mediaTextPattern  = new MediaTextPattern();
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
            $this->mediaTextPattern,
            $this->coverPattern,
            $this->columnsPattern,
            new CallbackPatternRecognizer('math', function (DOMElement $element, PatternContext $context): ?PatternRecognitionResult {
                $block = $this->mathPattern->match($element, fn (DOMElement $sourceElement, string $name): string => $this->attr($sourceElement, $name), $context->presentationAttributesCallback(), $context->innerHtmlCallback(), fn (DOMElement $sourceElement): string => $this->safeFallbackHtml($sourceElement), fn (string $text): string => $this->runtime->escapeHtml($text), $context->createBlockCallback());
                return null === $block ? null : new PatternRecognitionResult($block);
            }),
            new CallbackPatternRecognizer('parameter-table', function (DOMElement $element, PatternContext $context): ?PatternRecognitionResult {
                $block = $this->parameterTablePattern->match($element, $context->presentationAttributesCallback(), $context->innerHtmlCallback(), $context->createBlockCallback());
                return null === $block ? null : new PatternRecognitionResult($block);
            }),
            new CallbackPatternRecognizer('spacer', function (DOMElement $element, PatternContext $context): ?PatternRecognitionResult {
                $block = $this->spacerPattern->match($element, fn (DOMElement $sourceElement): int => $this->childElementCount($sourceElement), fn (DOMElement $sourceElement, string $name): string => $this->attr($sourceElement, $name), fn (DOMElement $sourceElement, string $className): bool => $this->hasClass($sourceElement, $className), $context->presentationAttributesCallback(), $context->createBlockCallback());
                return null === $block ? null : new PatternRecognitionResult($block);
            }),
            new CallbackPatternRecognizer('code-window', function (DOMElement $element, PatternContext $context): ?PatternRecognitionResult {
                $block = $this->codeWindowPattern->match($element, $context->presentationAttributesCallback(), $context->innerHtmlCallback(), fn (DOMElement $sourcePre, DOMElement $sourceCode): array => $this->codePresentationAttributes($sourcePre, $sourceCode), fn (DOMElement $sourceCode): string => $this->codeContent($sourceCode), $context->createBlockCallback());
                return null === $block ? null : new PatternRecognitionResult($block);
            }),
            new CallbackPatternRecognizer('logo', function (DOMElement $element, PatternContext $context): ?PatternRecognitionResult {
                $block = $this->logoPattern->match($element, $context->presentationAttributesCallback(), fn (DOMElement $sourceElement): string => $this->richTextContentWithMaterializedInlineStyles($sourceElement), fn (DOMElement $sourceElement): string => $this->restoreSvgCasing($this->outerHtml($sourceElement)), fn (DOMElement $sourceElement, string $content): ?string => $this->richTextContentWithMaterializedSvgImages($sourceElement, $content), $context->createBlockCallback());
                return null === $block ? null : new PatternRecognitionResult($block);
            }),
            new CallbackPatternRecognizer('placeholder-media', function (DOMElement $element, PatternContext $context): ?PatternRecognitionResult {
                $block = $this->placeholderMediaPattern->match($element, $context->presentationAttributesCallback(), fn (string $value): string => $this->runtime->escapeHtml($value), $context->createBlockCallback());
                return null === $block ? null : new PatternRecognitionResult($block);
            }),
            new CallbackPatternRecognizer('quote', function (DOMElement $element, PatternContext $context): ?PatternRecognitionResult {
                $fallbacks = array();
                $block = $this->quotePattern->matchBlockquote($element, $fallbacks, fn (DOMElement $sourceElement): string => $this->citationFromElement($sourceElement), fn (DOMElement $sourceElement, array $excludedTags): string => $this->innerHtmlWithoutTags($sourceElement, $excludedTags), fn (string $html): string => $this->runtime->stripAllTags($html), $context->presentationAttributesCallback(), fn (DOMElement $sourceElement, array &$sourceFallbacks, array $excludedTags): array => $this->convertChildrenWithoutTags($sourceElement, $sourceFallbacks, $excludedTags), fn (string $inlineTagName): bool => $this->isInlineContentElement($inlineTagName), $context->createBlockCallback());
                return null === $block ? null : new PatternRecognitionResult($block, $fallbacks);
            }),
            new CallbackPatternRecognizer('figure-quote', function (DOMElement $element, PatternContext $context): ?PatternRecognitionResult {
                $blockquote = $this->firstChildElement($element, 'blockquote');
                if (! $blockquote instanceof DOMElement) return null;
                $fallbacks = array();
                $block = $this->quotePattern->matchFigureBlockquote($element, $blockquote, $fallbacks, fn (DOMElement $sourceElement): string => $this->citationFromElement($sourceElement), $context->innerHtmlCallback(), fn (DOMElement $sourceElement, array $excludedTags): string => $this->innerHtmlWithoutTags($sourceElement, $excludedTags), fn (string $html): string => $this->runtime->stripAllTags($html), $context->presentationAttributesCallback(), fn (DOMElement $sourceElement, array &$sourceFallbacks, array $excludedTags): array => $this->convertChildrenWithoutTags($sourceElement, $sourceFallbacks, $excludedTags), $context->createBlockCallback());
                return null === $block ? null : new PatternRecognitionResult($block, $fallbacks);
            }),
            new CallbackPatternRecognizer('details', function (DOMElement $element, PatternContext $context): ?PatternRecognitionResult {
                $fallbacks = array();
                $block = $this->detailsPattern->match($element, $fallbacks, fn (DOMElement $sourceElement, array &$sourceFallbacks, array $excludedTags): array => $this->convertChildrenWithoutTags($sourceElement, $sourceFallbacks, $excludedTags), $context->presentationAttributesCallback(), $context->innerHtmlCallback(), $context->createBlockCallback());
                return null === $block ? null : new PatternRecognitionResult($block, $fallbacks);
            }),
            new CallbackPatternRecognizer('disclosure', function (DOMElement $element, PatternContext $context): ?PatternRecognitionResult {
                $converter = $context->recursiveConverter();
                if ( null === $converter ) {
                    return null;
                }
                $fallbacks = array();
                $block = $this->detailsPattern->matchDisclosure($element, function (DOMElement $sourceElement) use ($converter, &$fallbacks): array {
                    return $converter->children($sourceElement, $fallbacks, true);
                }, $context->presentationAttributesCallback(), $context->innerHtmlCallback(), $context->createBlockCallback());
                return null === $block ? null : new PatternRecognitionResult($block, $fallbacks);
            }),
            new CallbackPatternRecognizer('gallery', function (DOMElement $element, PatternContext $context): ?PatternRecognitionResult {
                $block = $this->galleryPattern->match($element, fn (DOMElement $image, ?DOMElement $figure = null, ?DOMElement $picture = null, ?DOMElement $link = null): ?array => $this->convertImageElement($image, $figure, $picture, $link), fn (DOMElement $picture, ?DOMElement $figure = null, ?DOMElement $link = null): ?array => $this->convertPictureElement($picture, $figure, $link), fn (DOMElement $figure): ?DOMElement => $this->figureLinkedMediaAnchor($figure), $context->presentationAttributesCallback(), $context->innerHtmlCallback(), $context->createBlockCallback());
                return null === $block ? null : new PatternRecognitionResult($block);
            }),
            new CallbackPatternRecognizer('buttons-container', function (DOMElement $element, PatternContext $context): ?PatternRecognitionResult {
                $block = $this->buttonsPattern->matchContainer($element, $context->presentationAttributesCallback(), fn (DOMElement $sourceElement): string => $this->resolveCssVariablesInValue($this->mergedPresentationStyle($sourceElement)), $context->innerHtmlCallback(), fn (DOMElement $sourceElement, string $content): ?string => $this->richTextContentWithMaterializedSvgImages($sourceElement, $content), fn (DOMElement $sourceElement, string $name): string => $this->attr($sourceElement, $name), $context->createBlockCallback());
                return null === $block ? null : new PatternRecognitionResult($block);
            }),
            new CallbackPatternRecognizer('button-anchor', function (DOMElement $element, PatternContext $context): ?PatternRecognitionResult {
                $fallbacks = array();
                $block = $this->buttonsPattern->matchAnchor($element, fn (DOMElement $anchor): ?array => $this->fileBlockFromAnchor($anchor), $context->presentationAttributesCallback(), fn (DOMElement $sourceElement): string => $this->resolveCssVariablesInValue($this->mergedPresentationStyle($sourceElement)), fn (DOMElement $sourceElement): string => $this->richTextContentWithMaterializedInlineStyles($sourceElement), fn (DOMElement $sourceElement, string $content): ?string => $this->richTextContentWithMaterializedSvgImages($sourceElement, $content), fn (DOMElement $sourceElement, string $name): string => $this->attr($sourceElement, $name), $context->createBlockCallback(), function (DOMElement $anchor) use (&$fallbacks): array {
                    $fallbacks[] = FallbackDiagnostic::build(array('type' => 'html', 'reason' => 'stylable_button_accessible_name_requires_typed_companion', 'diagnostic_code' => 'html_stylable_button_accessible_name_fallback', 'source_format' => 'html', 'tag' => 'a', 'html' => $this->safeFallbackHtml($anchor)), $this->fallbackProvenance);
                    return $this->htmlPreservationBlock($anchor);
                });
                return null === $block ? null : new PatternRecognitionResult($block, $fallbacks);
            }),
            new CallbackPatternRecognizer('button', function (DOMElement $element, PatternContext $context): ?PatternRecognitionResult {
                $block = $this->buttonsPattern->matchButton($element, $context->presentationAttributesCallback(), fn (DOMElement $sourceElement): string => $this->resolveCssVariablesInValue($this->mergedPresentationStyle($sourceElement)), fn (DOMElement $sourceElement): string => $this->richTextContentWithMaterializedInlineStyles($sourceElement), fn (DOMElement $sourceElement, string $content): ?string => $this->richTextContentWithMaterializedSvgImages($sourceElement, $content), fn (DOMElement $sourceElement): bool => $sourceElement->parentNode instanceof DOMElement && in_array($this->authoredDisplay($sourceElement->parentNode), array('grid', 'inline-grid'), true), $context->createBlockCallback());
                return new PatternRecognitionResult($block);
            }),
            new AccordionPattern(),
            new SocialLinksPattern(),
            new NavigationPattern(),
        ));
        $this->navigationUnderlineColorResolver = new NavigationUnderlineColorResolver();
        $this->navigationBlockNormalizer = new NavigationBlockNormalizer(fn (string $label): string => $this->normalizedNavigationLabel($label));
        $this->diagnosticsCollector = new DiagnosticsCollector();
        $this->semanticParityReporter = new SemanticParityReporter($this->runtime);
        $this->contentRoundTripReporter = new ContentRoundTripReporter();
        $this->reusableComponentRecognizer = new ReusableComponentRecognizer();
        $this->patternContext = $this->createPatternContext(true);
        $this->patternContextWithoutRuntimeDomTarget = $this->createPatternContext(false);
        $this->patternProbeContext = $this->createProbePatternContext();
    }

    public function &__get(string $name): mixed
    {
        $value =& $this->session->{$name};

        return $value;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->session->{$name} = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->session->{$name});
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
        $this->fallbackProvenance = TransformationOptions::provenance($options);
        $this->generatedBlockNamespace = $this->generatedBlockNamespaceFromOptions($options);
        $this->generatedAssetRoot = trim((string) ($options['generated_asset_root'] ?? ''), '/');
        $this->preserveShellLandmarks = !empty($options['extract_global_shell']);
        $this->fallbackReductionMode = !empty($options['fallback_reduction_mode']);
        $this->runtimeScriptMetadata = $this->runtimeScriptMetadataFromOptions($options);
        $this->assetMetadata = $this->assetMetadataFromOptions($options);
        $this->staticClassPromotions = $this->detectStaticClassPromotions($html);
        $staticCss = (string) ($options['static_css'] ?? '');
        $styleAnalysis = $this->composedStyleAnalysis($this->stylesheetPayloads($html, $staticCss, $options));
        $this->staticStyleRules = $styleAnalysis['static'];
        $this->conditionalStyleRules = $styleAnalysis['conditional'];
        $this->navigationStateStyleRules = $styleAnalysis['navigation_state'];
        $this->imageShapeStyleRules = $styleAnalysis['image_shape'];
        $this->staticPseudoElementStyleRules = $styleAnalysis['pseudo'];
        $this->cssCustomProperties = $styleAnalysis['custom_properties'];
        $this->resetPresentationResolutionCache();
        $this->runtimeDomSelectors = $this->runtimeSelectorsFromOptions($options, 'runtime_dom_selectors');
        $this->runtimeBehavioralSelectors = $this->runtimeSelectorsFromOptions($options, 'runtime_behavioral_selectors');
        $this->runtimeCanvasSelectors = $this->runtimeCanvasSelectorsFromOptions($options);
        $this->layoutGeometryProofReductions = is_array($options['layout_geometry_proof']['reductions'] ?? null) ? $options['layout_geometry_proof']['reductions'] : array();
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

        if ( array() !== $sourceBodyClasses ) {
            $body->setAttribute('class', implode(' ', $sourceBodyClasses));
        }

        $this->navigationBlockNormalizer->hydrateDuplicateSubmenus($body);
        $this->materializeDeclarativeCounters($body, (string) ($options['declarative_state_html'] ?? ''));
        $this->prepareAuthorSelectorSemantics($html, (string) ($options['static_css'] ?? ''), $body, $options);
        // Author-selector preparation marks source nodes for later projection.
        // General style matching begins only after those source mutations settle.
        $this->invalidateSourceSelectorMatchCache();
        $this->collectEditorHiddenStateFindings($body);
        $reusableComponentRecognition = $this->reusableComponentRecognizer->recognize($body);
        foreach ($reusableComponentRecognition['candidates'] as $candidate) {
            if (is_array($candidate) && is_string($candidate['path'] ?? null) && is_string($candidate['fingerprint'] ?? null)) $this->reusableComponentFingerprints[$candidate['path']] = $candidate['fingerprint'];
        }

        $fallbacks   = array();
        $interactionCandidates = $this->interactionCandidates($body);
        $this->collectSupersededNavToggleSelectors($body);
        $shellArtifacts = !array_key_exists('extract_global_shell', $options) || !empty($options['extract_global_shell']) ? $this->globalShellArtifacts($body, (string) ($options['source'] ?? 'html')) : array();
        $blocks      = $this->navigationBlockNormalizer->normalize($this->convertChildren($body, $fallbacks, true), $this->sourceProvenance, $this->sourceBaseHiddenStates);
        $fallbacks = array_merge($fallbacks, $this->responsiveImageFallbacks);
        if (!$this->fallbackReductionMode) {
            $blocks = $this->reduceCoreHtmlFallbackBlocks($blocks);
        }
        $this->recordRuntimeIslandsForPreservedHtmlBlocks($blocks);
        $this->appendInteractiveControlBehaviorLossFallbacks($body, $fallbacks);
        $this->appendProductGridFallbacks($body, $fallbacks, $blocks);
        $this->appendCommerceControlsFallbacks($body, $fallbacks);
        $serializedBlocks = $this->runtime->serializeBlocks($blocks);
        $this->finalizeFallbackBindings($fallbacks, $blocks, $serializedBlocks);
        $reusableComponentRecognition = $this->finalizeReusableComponentRecognition($reusableComponentRecognition);
        $sourceProvenance = $this->sourceProvenanceForBlocks($blocks);
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
        $contentRoundTripReport = $this->contentRoundTripReporter->report($serializedBlocks, $html, $this->formControlEchoTexts);
        $diagnostics = $this->diagnosticsCollector->collect(
            self::class,
            $this->scriptMetadata,
            $fallbacks,
            $this->runtimeIslands,
            array_values($this->runtimeDomPreservations),
            array_values($this->runtimeDomFallbacks),
            $blockValidityReport,
            $semanticParityReport,
            $contentRoundTripReport
        );
        foreach ( $this->responsiveGeometryAmbiguities as $ambiguity ) {
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
        $authorLayoutTopologyFindings = $this->authorLayoutTopologyFindings();
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
        if ( $this->descriptionListBlockGenerated ) {
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

        $this->recordSourceSelectorMatchWork();
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
            'runtime_islands' => $this->runtimeIslands,
            'runtime_dom_contracts' => array_values($this->runtimeDomPreservations),
            'runtime_dom_fallbacks' => array_values($this->runtimeDomFallbacks),
            'generated_blocks' => $this->generatedBlocks,
            'gutenberg_gaps' => $this->descriptionListBlockGenerated ? array(
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
            'superseded_selectors' => array_keys($this->supersededRuntimeSelectors),
            'shell_artifacts' => $shellArtifacts,
            'wp_block_validity' => $blockValidityReport,
            'semantic_parity' => $semanticParityReport,
            'content_round_trip' => $contentRoundTripReport,
            'editability_report' => (new EditabilityReport())->fromBlocks($blocks, (string) ($options['source'] ?? ''), $serializedBlocks, $generatedCarrierCss, $runtimeBlockPaths, $visualBlockPaths, $sourceProvenance),
            'html' => array(
                'presentation_signals' => $this->presentationProvenance,
                'frozen_hidden_state'  => $this->frozenHiddenStateFindings,
                'dropped_link_wrappers' => $this->droppedLinkWrapperFindings,
                'gutenberg_incompatibilities' => $this->gutenbergIncompatibilities,
                'author_layout_topology' => $authorLayoutTopologyFindings,
                'source_provenance'    => $sourceProvenance,
                'core_html_fallback_evidence' => CoreHtmlFallbackEvidence::fromBlocks($blocks, $fallbacks, $sourceProvenance),
                'structure_signals'    => $this->structureProvenance,
                'reusable_components' => $reusableComponentRecognition,
                'script_metadata'      => $this->scriptMetadata,
                'runtime_islands'      => $this->runtimeIslands,
                'layout_geometry_proof' => $this->layoutGeometryProofProvenance,
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
        foreach ($this->generatedAssets as $asset) if ('engine-support' === ($asset['source'] ?? '') && 'css' === ($asset['kind'] ?? '') && is_string($asset['content'] ?? null)) $css[] = $asset['content'];
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
                if ($element instanceof DOMElement && $this->isRuntimeDomTarget($element)) {
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
            $blocks = $this->navigationBlockNormalizer->normalize($this->convertChildren($child, $shellFallbacks, true), $this->sourceProvenance, $this->sourceBaseHiddenStates);
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
        $selectorCache = $this->session->sourceStyleResolutionState->selectorMatchCache;
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
        return $this->reusableComponentFingerprints[$element->getNodePath()] ?? null;
    }

    /** @param array<string, mixed> $recognition @return array<string, mixed> */
    private function finalizeReusableComponentRecognition(array $recognition): array
    {
        $assetOccurrences = array();
        foreach ($this->generatedAssets as $asset) {
            if (!is_array($asset) || 'inline-svg' !== ($asset['source'] ?? null)) continue;
            foreach (is_array($asset['component_occurrence_counts'] ?? null) ? $asset['component_occurrence_counts'] : array() as $fingerprint => $count) if (is_string($fingerprint) && is_int($count)) $assetOccurrences[$fingerprint] = (int) ($assetOccurrences[$fingerprint] ?? 0) + $count;
        }
        foreach ($recognition['components'] as &$component) {
            if (!is_array($component) || 'svg' !== ($component['tag'] ?? null)) continue;
            $mapped = (int) ($assetOccurrences[$component['fingerprint']] ?? 0);
            $component['mapping'] = $mapped === ($component['occurrence_count'] ?? 0) && 0 < $mapped
                ? 'shared_core_image_asset'
                : 'capability_gap:svg_instances_not_all_core_image_assets';
            $component['mapped_asset_occurrence_count'] = $mapped;
        }
        unset($component);
        return $recognition;
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
        if ( $includeAuthorStyles && '' !== $this->combinedAuthorCss ) {
            $authorCss = $this->rewriteAuthorStylesheet($this->combinedAuthorCss);
            $split = ( new CssStylesheetTransformer() )->splitLeadingAtRulePreamble($authorCss);
            if ( '' !== trim($split['preamble']) ) {
                $authorCssParts[] = $split['preamble'];
            }
            $authorCss = $split['stylesheet'];
        }
        $geometryCss = $this->generatedGeometryCss($serializedBlocks);
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
        if ( str_contains($serializedBlocks, self::POSITIONED_FRAGMENT_LINK_CARRIER_CLASS) ) {
            // Positioned fragment links retain their source anchor and selectors;
            // their valid paragraph host must not create a line box in document flow.
            $beforeAuthorCssParts[] = ':where(.' . self::POSITIONED_FRAGMENT_LINK_CARRIER_CLASS . '){display:contents!important}';
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
        if ( str_contains($serializedBlocks, self::CSS_OWNED_LAYOUT_ITEM_CLASS) ) {
            // A semantic Group used as a direct grid/flex item contains native
            // paragraph blocks. Neutralize only those generated inner defaults.
            $beforeAuthorCssParts[] = ':root :where(.wp-block-group.' . self::CSS_OWNED_LAYOUT_ITEM_CLASS . ')>*{margin-block-start:0;margin-block-end:0}';
        }
        foreach ( $this->navigationLinkTextColorRules($serializedBlocks) as $navigationLinkTextColorRule ) {
            $afterAuthorCssParts[] = $navigationLinkTextColorRule;
        }
        foreach ( $this->navigationSubmenuBackgroundFallbacks as $className => $color ) {
            if ( str_contains($serializedBlocks, $className) ) {
                $afterAuthorCssParts[] = '.wp-block-navigation-item.' . $className . '>.wp-block-navigation__submenu-container{background-color:' . $color . '}';
            }
        }
        foreach ( $this->navigationSpacingFallbacks as $className => $declarations ) {
            if ( str_contains($serializedBlocks, $className) ) {
                $afterAuthorCssParts[] = '.wp-block-navigation.' . $className . '{' . $declarations . '}';
            }
        }
        foreach ( $this->buttonWrapperSpacingFallbacks as $className => $declarations ) {
            if ( str_contains($serializedBlocks, $className) ) {
                $afterAuthorCssParts[] = '.wp-block-buttons.' . $className . '{' . $declarations . '}';
            }
        }
        foreach ( $this->syntheticHeaderAnchorStyleRules as $className => $rule ) {
            if ( str_contains($serializedBlocks, $className) ) {
                $afterAuthorCssParts[] = $rule;
            }
        }
        foreach ( $this->headerRichTextStyleRules as $marker => $rule ) {
            if ( str_contains($serializedBlocks, $marker) ) {
                $afterAuthorCssParts[] = $rule;
            }
        }
        if ( str_contains($serializedBlocks, 'blocks-engine-list-navigation') ) {
            $beforeAuthorCssParts[] = '.wp-block-navigation.blocks-engine-list-navigation .wp-block-navigation-item.wp-block-navigation-link{display:list-item;font:inherit}'
                . "\n" . '.wp-block-navigation.blocks-engine-list-navigation .wp-block-navigation-item__content{display:inline}';
        }
        if ( array() !== $this->nativeSearchTriggerCssRules ) {
            $beforeAuthorCssParts[] = implode("\n", $this->nativeSearchTriggerCssRules);
        }
        if ( '' !== trim($authorCss) ) {
            $authorCssParts[] = $authorCss;
        }
        if ( str_contains($serializedBlocks, 'blocks-engine-list-navigation') ) {
            // Keep only source-responsive navigation hosts visible. Ordinary
            // link rows retain authored mobile display rules without core's
            // overlay control replacing them.
            if ( str_contains($serializedBlocks, 'blocks-engine-native-responsive-navigation') ) {
                $afterAuthorCssParts[] = '.wp-block-navigation.blocks-engine-list-navigation.blocks-engine-native-responsive-navigation{display:flex!important}';
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
        foreach ( $this->navigationItemStateAnchorRules($serializedBlocks, $sourceProvenance) as $itemAnchorRule ) {
            $afterAuthorCssParts[] = $itemAnchorRule;
        }
        $directNavigationCss = $this->directNavigationSupportCss($serializedBlocks);
        if ( '' !== $directNavigationCss ) {
            $afterAuthorCssParts[] = $directNavigationCss;
        }
        if ( array() !== $this->nativeButtonStyleRules ) {
            $afterAuthorCssParts[] = implode("\n", $this->nativeButtonStyleRules);
        }
        if ( array() !== $this->directFlexButtonStyleRules ) {
            $afterAuthorCssParts[] = implode("\n", $this->directFlexButtonStyleRules);
        }
        if ( array() !== $this->fullWidthButtonStyleRules ) {
            $afterAuthorCssParts[] = implode("\n", $this->fullWidthButtonStyleRules);
        }

        $this->materializeStylesheetAsset($beforeAuthorCssParts, 'engine-support', 'before-author', 'engine-support-before-author');
        $this->materializeStylesheetAsset($authorCssParts, 'author-css', 'author', 'source-author');
        $this->materializeStylesheetAsset($afterAuthorCssParts, 'engine-support', 'after-author', 'engine-support-after-author');
    }

    private function directNavigationSupportCss(string $serializedBlocks): string
    {
        if ( ! str_contains($serializedBlocks, 'blocks-engine-direct-navigation') ) {
            return '';
        }

        $host = '.wp-block-group.blocks-engine-brand-navigation-carrier>.wp-block-navigation.blocks-engine-direct-navigation';
        $rules = array();
        foreach ( array(
            'margin' => 'margin:0',
            'padding' => 'padding:0',
            'max-width' => 'max-width:none',
        ) as $family => $declaration ) {
            $marker = 'blocks-engine-direct-navigation-reset-' . $family;
            if ( str_contains($serializedBlocks, $marker) ) {
                $rules[] = $host . '.' . $marker . '{' . $declaration . '}';
            }
        }

        if ( preg_match_all('/<!--\s*wp:navigation-(?:link|submenu)\s+(\{.*?\})\s*\/?-->/s', $serializedBlocks, $matches) ) {
            foreach ( $matches[1] as $json ) {
                $attrs = json_decode($json, true);
                if ( ! is_array($attrs) ) {
                    continue;
                }

                $color = trim((string) ($attrs['style']['color']['text'] ?? ''));
                if ( '' === $color ) {
                    continue;
                }
                $safeColor = (string) ($this->styleAttributeMapper()->map(array( 'color' => $color ))['style']['color']['text'] ?? '');
                if ( '' === $safeColor ) {
                    continue;
                }

                $expectedMarker = 'blocks-engine-direct-navigation-link-color-' . substr(hash('sha256', $safeColor), 0, 12);
                $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array();
                if ( ! in_array($expectedMarker, $classes, true) ) {
                    continue;
                }

                $selector = '.wp-block-navigation.blocks-engine-direct-navigation '
                    . '.wp-block-navigation-item.' . $expectedMarker
                    . '>.wp-block-navigation-item__content';
                $rules[$selector] = $selector . '{color:' . $safeColor . '}';
            }
        }

        return implode("\n", array_values($rules));
    }

    /** @param array<int, string> $cssParts */
    private function materializeStylesheetAsset(array $cssParts, string $source, string $placement, string $pathPrefix, string $target = 'both'): void
    {
        $css = trim(implode("\n\n", $cssParts));
        if ( '' === $css ) {
            return;
        }

        $content = $css . "\n";
        $hash = hash('sha256', $content);
        $path = 'assets/css/' . $pathPrefix . '-' . substr($hash, 0, 16) . '.css';

        $this->generatedAssets[$path] = array(
            'source'      => $source,
            'source_path' => '',
            'path'        => $path,
            'target_path' => $path,
            'kind'        => 'css',
            'role'        => 'stylesheet',
            'stylesheet_placement' => $placement,
            'stylesheet_target' => $target,
            'mime_type'   => 'text/css',
            'media_type'  => 'text/css',
            'content'     => $content,
            'bytes'       => strlen($content),
            'encoding'    => 'utf-8',
            'binary'      => false,
            'hash'        => $hash,
            'source_hash' => $hash,
        );
    }

    private function materializeEditorStaticStateStylesheet(): void
    {
        $rules = array();
        $anchorProjectionCss = $this->editorAnchorProjectionCss();
        if ( '' !== $anchorProjectionCss ) {
            $rules[] = $anchorProjectionCss;
        }
        if ( preg_match('/(?:^|[;{])\s*(?:-webkit-)?animation(?:-[a-z-]+)?\s*:/i', $this->combinedAuthorCss) ) {
            $rules[] = ':root *,:root *::before,:root *::after{animation-delay:-999999s!important;animation-iteration-count:1!important;animation-fill-mode:both!important;transition:none!important}';
        }
        if ( $this->emptyRuntimeTargetGenerated ) {
            $selector = ':root .' . self::EMPTY_RUNTIME_TARGET_CLASS . '.wp-block-group__placeholder';
            $rules[] = $selector . '{flex-basis:auto!important;width:auto!important;min-width:10ch!important;min-height:1.2em!important}'
                . $selector . '>*{display:none!important}'
                . $selector . '::before{content:"Dynamic content";display:block;opacity:.45;white-space:nowrap}';
        }
        if ( preg_match('/\bbody\b[^{}]*\{[^}]*(?:overflow\s*:\s*(?:hidden|clip)|height\s*:\s*100(?:d|s|l)?vh)/is', $this->combinedAuthorCss) ) {
            $rules[] = ':root body{overflow:auto!important;height:auto!important;min-height:100%!important;width:auto!important}';
        }

        $repairs = array();
        foreach ( $this->frozenHiddenStateFindings as $finding ) {
            $selector = (string) ($finding['editor_selector'] ?? '');
            if ( '' === $selector ) {
                continue;
            }
            foreach ( (array) ($finding['declarations'] ?? array()) as $declaration ) {
                if ( 'display:none' === $declaration ) {
                    $repairs[$selector]['display'] = 'revert!important';
                } elseif ( 'visibility:hidden' === $declaration ) {
                    $repairs[$selector]['visibility'] = 'visible!important';
                } elseif ( 'opacity:0' === $declaration ) {
                    $repairs[$selector]['opacity'] = '1!important';
                    $repairs[$selector]['transform'] = 'none!important';
                }
            }
        }
        ksort($repairs, SORT_STRING);
        foreach ( $repairs as $selector => $declarations ) {
            ksort($declarations, SORT_STRING);
            $body = '';
            foreach ( $declarations as $property => $value ) {
                $body .= $property . ':' . $value . ';';
            }
            $rules[] = ':root ' . $selector . '{' . rtrim($body, ';') . '}';
        }

        $this->materializeStylesheetAsset($rules, 'editor-static-state', 'after-author', 'editor-static-state', 'editor');
    }

    private function editorAnchorProjectionCss(): string
    {
        $ids = array_fill_keys(array_filter(
            array_keys($this->authorStyleSourceElementsById),
            fn (string $id): bool => '' !== $this->safeAnchor($id)
        ), true);
        if ( array() === $ids ) {
            return '';
        }

        return trim(( new CssStylesheetTransformer() )->transform(
            $this->combinedAuthorCss,
            static function (string $prelude, string $body) use ($ids): array {
                $projected = array();
                foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
                    $replacement = preg_replace_callback(
                        '/(^|[\s>+~,(])#([A-Za-z][A-Za-z0-9_-]*)/',
                        static fn (array $match): string => isset($ids[$match[2]])
                            ? $match[1] . '.blocks-engine-editor-anchor-' . $match[2]
                            : $match[0],
                        $selector
                    );
                    if ( is_string($replacement) && $replacement !== $selector ) {
                        $projected[] = $replacement;
                    }
                }

                return array() === $projected
                    ? array()
                    : array(array('prelude' => implode(',', $projected), 'body' => $body));
            }
        ));
    }

    /**
     * Re-assert an authored inline-axis `auto` margin on the navigation block
     * host, after author CSS.
     *
     * A menu authored as `.navlinks{margin:0 0 0 auto}` inside `nav{display:flex}`
     * sits at the far end of its landmark. The class survives onto the promoted
     * navigation, but core's own navigation stylesheet owns the inner list —
     * `.wp-block-navigation ul{margin-left:0}`, specificity 0,1,1 — and outranks
     * the authored 0,1,0 class, so the menu snaps back to the start of the
     * landmark and the authored end-alignment is lost.
     *
     * The block host is the flex item that actually moves, so the margin is
     * restated there. The selector is self-limiting: it matches only an element
     * that is both a promoted list navigation and carries the authored class.
     *
     * Only `auto` is carried. An authored length is left to the author rule,
     * which core does not contest on the host.
     *
     * @return array<int, string>
     */
    private function listNavigationInlineMarginRules(string $serializedBlocks): array
    {
        if ( ! str_contains($serializedBlocks, 'blocks-engine-list-navigation') ) {
            return array();
        }

        $navigationClasses = $this->listNavigationHostClasses($serializedBlocks);
        if ( array() === $navigationClasses ) {
            return array();
        }

        $rules = array();
        foreach ( array_merge($this->staticStyleRules, $this->conditionalStyleRules) as $rule ) {
            $selector = trim((string) ($rule['selector'] ?? ''));
            if ( 1 !== preg_match('/^\.([A-Za-z_][A-Za-z0-9_-]*)$/', $selector, $match) ) {
                continue;
            }

            $class = $match[1];
            // The class has to sit on a promoted navigation host, not merely
            // appear somewhere in the document. A page wrapper's `.wrap{margin:0
            // auto}` is not a statement about a menu, and emitting a rule for it
            // would be dead CSS on every page that has one.
            if ( ! isset($navigationClasses[$class]) ) {
                continue;
            }

            $margins = $this->inlineAxisAutoMargins(is_array($rule['declarations'] ?? null) ? $rule['declarations'] : array());
            if ( array() === $margins ) {
                continue;
            }

            $declarations = array();
            foreach ( $margins as $side => $value ) {
                $declarations[] = 'margin-' . $side . ':' . $value . '!important';
            }

            $selectorText = '.wp-block-navigation.blocks-engine-list-navigation.' . $class;
            $rules[$selectorText] = $selectorText . '{' . implode(';', $declarations) . '}';
        }

        return array_values($rules);
    }

    /** @return array<int, string> */
    private function listNavigationPaddingRules(string $serializedBlocks): array
    {
        if ( ! preg_match_all('/<!--\s*wp:navigation\s*(\{.*?\})\s*-->/s', $serializedBlocks, $matches, PREG_SET_ORDER) ) {
            return array();
        }

        $paddingSets = array();
        foreach ( $matches as $match ) {
            $attrs = json_decode($match[1], true);
            if ( ! is_array($attrs) ) {
                continue;
            }

            $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array();
            if ( ! in_array('blocks-engine-list-navigation', $classes, true) ) {
                continue;
            }

            $padding = is_array($attrs['style']['spacing']['padding'] ?? null)
                ? $attrs['style']['spacing']['padding']
                : array();
            if ( array() === $padding ) {
                foreach ( $classes as $class ) {
                    if ( isset($this->listNavigationPaddingFallbacks[ $class ]) ) {
                        $padding = $this->listNavigationPaddingFallbacks[ $class ];
                        break;
                    }
                }
            }
            $declarations = array();
            foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
                $property = 'padding-' . $side;
                $value = trim((string) ($padding[$side] ?? ''));
                $safe = $this->safeVisualDeclarations($this->cssDeclarations($property . ':' . $value));
                if ( '' !== $value
                    && ($safe[$property] ?? null) === $value
                    && ! $this->navigationDeclarationIsImportant($value)
                ) {
                    $declarations[] = $property . ':' . $value;
                }
            }
            if ( array() === $declarations ) {
                $paddingSets['__no_list_navigation_padding__'] = true;
                continue;
            }
            $paddingSets[implode(';', $declarations)] = true;
        }

        // One transform can contain multiple promoted menus. A shared selector
        // is exact only when their source-list padding agrees; otherwise fail
        // closed instead of letting source order assign one menu's box to all.
        if ( 1 !== count($paddingSets) || isset($paddingSets['__no_list_navigation_padding__']) ) {
            return array();
        }

        $selector = 'nav.wp-block-group>.wp-block-navigation.blocks-engine-list-navigation';
        return array( $selector . '{' . array_key_first($paddingSets) . '}' );
    }

    /**
     * Re-point an authored ANCHOR-scoped menu-item rule at the element core
     * actually renders.
     *
     * A design styles a menu CTA through its anchor — sunny-ember writes
     * `.navlinks a.nav-cta{background;color;padding}`. core/navigation-link puts
     * the authored class on the `<li>` and hard-codes the anchor's own class in
     * `render_block_core_navigation_link()`, so the source anchor class lands on
     * the navigation item rather than the rendered anchor. The authored selector
     * therefore matches nothing and the pill renders as plain text.
     *
     * The rule is rewritten onto `.wp-block-navigation-item.<class> >
     * .wp-block-navigation-item__content`, which is the anchor the class-bearing
     * item owns. Emitted after the author stylesheet and carrying five class
     * tokens, so it outranks both core's item styles and the authored rule it
     * stands in for.
     *
     * Source ownership, rather than selector spelling, triggers the mapping. A
     * bare `.nav-cta` is mapped when that class sat on the authored anchor just
     * like `.navlinks a.nav-cta`; a class authored on the source `<li>` remains
     * item-owned. Scope stays narrow: the class must ride a real navigation-link
     * in this document, and any ancestor part of the authored selector must name
     * a promoted navigation host — otherwise `.footer a.nav-cta` would be hoisted
     * into a menu it was never about.
     *
     * A mapped declaration is emitted only when its source rule actually wins
     * that exact property on every source anchor the mapped selector will reach.
     * This prevents the stronger compatibility selector from promoting a losing
     * authored declaration over the rule that beat it in the design.
     *
     * @param array<int, array<string, mixed>> $sourceProvenance
     * @return array<int, string>
     */
    private function listNavigationItemAnchorRules(string $serializedBlocks, array $sourceProvenance): array
    {
        if ( ! str_contains($serializedBlocks, 'blocks-engine-list-navigation') ) {
            return array();
        }

        $itemClasses = $this->listNavigationItemClasses($serializedBlocks);
        if ( array() === $itemClasses ) {
            return array();
        }

        $anchorClasses = $this->listNavigationAnchorClasses($sourceProvenance);
        if ( array() === $anchorClasses ) {
            return array();
        }

        $hostClasses = $this->listNavigationHostClasses($serializedBlocks);
        $authoredRules = $this->navigationAuthorStyleRules();
        if ( array() === $authoredRules ) {
            return array();
        }

        $rules = array();
        $emitted = array();
        foreach ( $authoredRules as $rule ) {
            // Existing navigation compatibility CSS covers resting paint only;
            // pseudo-state mapping remains a deliberate, tested omission. Keep
            // pseudo context in the collector so it cannot compete with base.
            if ( '' !== ($rule['pseudo'] ?? '') ) {
                continue;
            }
            $selector = trim((string) ($rule['selector'] ?? ''));
            $ancestor = '';
            $class = '';
            $pseudo = '';
            $bareAnchorClassRule = false;
            if ( 1 === preg_match('/^(.*?)(?:^|\s)a\.([A-Za-z_][A-Za-z0-9_-]*)((?::[a-z-]+)*)$/', $selector, $match) ) {
                $ancestor = trim($match[1]);
                $class = $match[2];
                $pseudo = $match[3];
            } elseif ( 1 === preg_match('/^\.([A-Za-z_][A-Za-z0-9_-]*)((?::[a-z-]+)*)$/', $selector, $match) ) {
                $class = $match[1];
                $pseudo = $match[2];
                $bareAnchorClassRule = true;
            } else {
                continue;
            }

            if ( ! isset($itemClasses[$class], $anchorClasses[$class]) ) {
                continue;
            }

            if ( '' !== $ancestor && ! $this->namesNavigationHost($ancestor, $hostClasses) ) {
                continue;
            }

            $sourceAnchors = $this->navigationSourceAnchorsForClass($class, $sourceProvenance);
            if ( array() === $sourceAnchors ) {
                continue;
            }

            $declarations = array();
            $itemNeutralizers = array();
            foreach ( is_array($rule['declarations'] ?? null) ? $rule['declarations'] : array() as $property => $value ) {
                $property = trim((string) $property);
                $value = trim((string) $value);
                if ( '' === $property || '' === $value ) {
                    continue;
                }

                if ( 'border' === $property ) {
                    foreach ( $this->navigationBorderWinnerDeclarations($rule, $value, $authoredRules, $sourceAnchors) as $borderDeclaration ) {
                        $declarations[] = $borderDeclaration;
                    }
                } elseif ( $this->navigationRuleWinsPropertyOnAnchors($rule, $property, $authoredRules, $sourceAnchors) ) {
                    $declarations[] = $property . ':' . $value;
                }

                if ( $bareAnchorClassRule ) {
                    // core/navigation-link moves the authored anchor class onto
                    // its li. The bare rule then paints a second box that did not
                    // exist in the source, even for declarations also projected
                    // onto the rendered anchor. Restore the source li's exact
                    // winner, or its lower-origin value when no author rule owned
                    // that property. Ambiguous shorthand/longhand overlap fails
                    // closed instead of inventing a reset.
                    $resetValue = $this->navigationSourceListItemResetValue($class, $property, $sourceAnchors);
                    if ( null !== $resetValue ) {
                        $itemNeutralizers[] = $property . ':' . $resetValue;
                    }
                }
            }
            if ( array() === $declarations && array() === $itemNeutralizers ) {
                continue;
            }

            $emissionKey = implode("\0", array(
                (string) ($rule['id'] ?? ''),
                $class,
                (string) ($rule['pseudo'] ?? ''),
                (string) json_encode($rule['conditions'] ?? array()),
            ));
            if ( isset($emitted[$emissionKey]) ) {
                continue;
            }
            $emitted[$emissionKey] = true;

            $conditions = is_array($rule['conditions'] ?? null) ? $rule['conditions'] : array();
            if ( array() !== $declarations ) {
                $selectorText = '.wp-block-navigation.blocks-engine-list-navigation .wp-block-navigation-item.'
                    . $class . '>.wp-block-navigation-item__content' . $pseudo;
                $mappedRule = $selectorText . '{' . implode(';', $declarations) . '}';
                foreach ( array_reverse($conditions) as $condition ) {
                    $mappedRule = $condition . '{' . $mappedRule . '}';
                }
                $rules[] = $mappedRule;
            }

            if ( array() !== $itemNeutralizers ) {
                $itemRule = '.wp-block-navigation.blocks-engine-list-navigation .wp-block-navigation-item.'
                    . $class . '{' . implode(';', array_values(array_unique($itemNeutralizers))) . '}';
                foreach ( array_reverse($conditions) as $condition ) {
                    $itemRule = $condition . '{' . $itemRule . '}';
                }
                $rules[] = $itemRule;
            }
        }

        return $rules;
    }

    /**
     * Re-point authored interaction colours at rendered navigation anchors.
     *
     * Resting declarations are handled by listNavigationItemAnchorRules(),
     * which proves each source-cascade winner before increasing specificity.
     * State rules apply the same winner proof before mapping design-time current
     * classes onto WordPress runtime current state, including direct-anchor
     * navigation. Compatibility output stays colour-only. Conditional state
     * rules fail closed: their active cascade also includes unconditional rules,
     * so comparing an isolated condition stack cannot prove a global winner.
     *
     * @param array<int, array<string, mixed>> $sourceProvenance
     * @return array<int, string>
     */
    private function navigationItemStateAnchorRules(string $serializedBlocks, array $sourceProvenance): array
    {
        $hasListNavigation = str_contains($serializedBlocks, 'blocks-engine-list-navigation');
        if ( ! str_contains($serializedBlocks, '<!-- wp:navigation ') ) {
            return array();
        }

        $itemClasses = $this->listNavigationItemClasses($serializedBlocks);
        $listHostClasses = $this->listNavigationHostClasses($serializedBlocks);
        $allHostClasses = $this->listNavigationHostClasses($serializedBlocks, false);
        $authoredRules = $this->navigationAuthorStyleRules();
        $rules = array();
        foreach ( $authoredRules as $rule ) {
            if ( array() !== ($rule['conditions'] ?? array()) ) {
                continue;
            }
            $selector = trim((string) ($rule['selector'] ?? ''));
            $match = array();
            if ( 1 === preg_match('/^(.*?)(?:^|\s)a\.([A-Za-z_][A-Za-z0-9_-]*)((?::[a-z-]+)*)$/', $selector, $anchorMatch) ) {
                $match = array( $anchorMatch[1], $anchorMatch[2], $anchorMatch[3], 'anchor' );
            } elseif ( 1 === preg_match('/^(.*?)(?:^|\s)\.([A-Za-z_][A-Za-z0-9_-]*)\s*>\s*a((?::[a-z-]+)*)$/', $selector, $itemMatch) ) {
                $match = array( $itemMatch[1], $itemMatch[2], $itemMatch[3], 'item' );
            }
            if ( array() === $match ) {
                continue;
            }

            $ancestor = trim($match[0]);
            $class = $match[1];
            $pseudo = strtolower($match[2]);
            $classOwner = $match[3];
            if ( ! in_array($pseudo, array( ':hover', ':focus', ':focus-visible', ':active' ), true) ) {
                continue;
            }
            $isCurrentClass = $this->isAuthoredCurrentNavigationClass($class);
            if ( ! $isCurrentClass && (! $hasListNavigation || ! isset($itemClasses[$class])) ) {
                continue;
            }

            $hostClasses = $isCurrentClass ? $allHostClasses : $listHostClasses;
            if ( '' !== $ancestor && ! $this->namesNavigationHost($ancestor, $hostClasses) ) {
                continue;
            }

            $sourceAnchors = 'anchor' === $classOwner
                ? $this->navigationSourceAnchorsForClass($class, $sourceProvenance)
                : $this->navigationSourceAnchorsForItemClass($class, $sourceProvenance);
            if ( array() === $sourceAnchors
                || ! $this->navigationRuleWinsPropertyOnAnchors($rule, 'color', $authoredRules, $sourceAnchors)
                || $this->navigationRuleHasConditionalPropertyCompetitorOnAnchors($rule, 'color', $authoredRules, $sourceAnchors)
            ) {
                continue;
            }

            $source = is_array($rule['declarations'] ?? null) ? $rule['declarations'] : array();
            $source = isset($source['color']) ? array( 'color' => $source['color'] ) : array();
            if ( array() === $source ) {
                continue;
            }

            $declarations = array();
            foreach ( $source as $property => $value ) {
                $property = trim((string) $property);
                $value = trim((string) $value);
                if ( '' === $property || '' === $value ) {
                    continue;
                }
                $declarations[] = $property . ':' . $value;
            }
            if ( array() === $declarations ) {
                continue;
            }

            if ( $isCurrentClass ) {
                $hostSelector = '.wp-block-navigation';
                if ( preg_match_all('/\.([A-Za-z_][A-Za-z0-9_-]*)/', $ancestor, $hostMatches) ) {
                    foreach ( $hostMatches[1] as $hostClass ) {
                        if ( isset($hostClasses[$hostClass]) ) {
                            $hostSelector .= '.' . $hostClass;
                        }
                    }
                }
                $selectorText = $hostSelector
                    . ' .wp-block-navigation-item.current-menu-item>.wp-block-navigation-item__content' . $pseudo
                    . ',' . $hostSelector
                    . ' .wp-block-navigation-item__content[aria-current]' . $pseudo;
            } else {
                $selectorText = '.wp-block-navigation.blocks-engine-list-navigation .wp-block-navigation-item.'
                    . $class . '>.wp-block-navigation-item__content' . $pseudo;
            }
            $rules[$selectorText] = $selectorText . '{' . implode(';', $declarations) . '}';
        }

        return array_values($rules);
    }

    /**
     * Ordered authored rules used only by navigation anchor compatibility CSS.
     *
     * Shared presentation rule sets intentionally flatten contexts and omit
     * pseudo states. This collector keeps the authored rule identity, condition
     * stack, pseudo suffix, specificity, and source order needed to decide
     * whether a declaration was a source-cascade winner before re-pointing it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function navigationAuthorStyleRules(): array
    {
        if ( '' === trim($this->combinedAuthorCss) ) {
            return array();
        }

        $rules = array();
        $order = 0;
        $css = preg_replace('@/\*.*?\*/@s', '', $this->combinedAuthorCss) ?? $this->combinedAuthorCss;
        $this->collectNavigationAuthorStyleRules($css, array(), $rules, $order);
        return $rules;
    }

    /**
     * @param list<string> $conditions
     * @param array<int, array<string, mixed>> $rules
     */
    private function collectNavigationAuthorStyleRules(string $css, array $conditions, array &$rules, int &$order): void
    {
        $directCss = $css;
        $events = array();
        for ( $offset = 0, $length = strlen($css); $offset < $length; ++$offset ) {
            if ( '@' !== $css[$offset] ) {
                continue;
            }
            $blockStart = $this->findCssToken($css, '{', $offset);
            $statementEnd = $this->findCssToken($css, ';', $offset);
            if ( null === $blockStart || (null !== $statementEnd && $statementEnd < $blockStart) ) {
                continue;
            }
            $end = $this->findMatchingCssBrace($css, $blockStart);
            if ( null === $end ) {
                continue;
            }
            $prelude = trim(substr($css, $offset, $blockStart - $offset));
            $directCss = substr_replace($directCss, str_repeat(' ', $end - $offset + 1), $offset, $end - $offset + 1);
            if ( preg_match('/^@(media|container|supports|layer|scope|starting-style)\b/i', $prelude) ) {
                $events[] = array(
                    'offset' => $offset,
                    'css' => substr($css, $blockStart + 1, $end - $blockStart - 1),
                    'conditions' => array_merge($conditions, array( $prelude )),
                );
            }
            $offset = $end;
        }

        if ( preg_match_all('/([^{}]+)\{([^{}]+)\}/', $directCss, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) ) {
            foreach ( $matches as $match ) {
                $events[] = array(
                    'offset' => $match[0][1],
                    'prelude' => $match[1][0],
                    'body' => $match[2][0],
                    'conditions' => $conditions,
                );
            }
        }

        usort($events, static fn (array $left, array $right): int => $left['offset'] <=> $right['offset']);
        foreach ( $events as $event ) {
            if ( isset($event['css']) ) {
                $this->collectNavigationAuthorStyleRules($event['css'], $event['conditions'], $rules, $order);
                continue;
            }

            $declarations = $this->safeVisualDeclarations($this->cssDeclarations((string) $event['body']));
            if ( array() === $declarations ) {
                continue;
            }
            $ruleId = $order++;
            foreach ( CssStylesheetTransformer::splitSelectorList((string) $event['prelude']) ?? array() as $selector ) {
                $selector = trim($selector);
                if ( '' === $selector || str_starts_with($selector, '@') ) {
                    continue;
                }
                $parsed = $this->parsedCssSelector($selector);
                if ( ! ($parsed['supported'] ?? false) ) {
                    continue;
                }
                $pseudo = '';
                $pseudoSpan = $parsed['pseudo_state_suffix_span'] ?? null;
                if ( is_array($pseudoSpan) ) {
                    $pseudo = strtolower(substr($selector, $pseudoSpan['start'], $pseudoSpan['end'] - $pseudoSpan['start']));
                }
                $rules[] = array(
                    'id' => $ruleId,
                    'selector' => $selector,
                    'parsed' => $parsed,
                    'declarations' => $declarations,
                    'conditions' => $event['conditions'],
                    'pseudo' => $pseudo,
                    'specificity' => $this->navigationSelectorSpecificity($parsed, $pseudo),
                    'order' => $ruleId,
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $parsed
     * @return array{int, int, int}
     */
    private function navigationSelectorSpecificity(array $parsed, string $pseudo): array
    {
        $specificity = array( 0, 0, 0 );
        $addCompound = function (array $compound) use (&$addCompound, &$specificity): void {
            $specificity[0] += count($compound['ids'] ?? array());
            $specificity[1] += count($compound['classes'] ?? array()) + count($compound['attributes'] ?? array());
            if ( null !== ($compound['nth_child'] ?? null) || ($compound['first_child'] ?? false) || ($compound['last_child'] ?? false) ) {
                ++$specificity[1];
            }
            if ( null !== ($compound['type'] ?? null) ) {
                ++$specificity[2];
            }
            foreach ( $compound['not'] ?? array() as $negated ) {
                $addCompound($negated);
            }
        };
        foreach ( $parsed['compounds'] ?? array() as $compound ) {
            $addCompound($compound);
        }
        $specificity[1] += preg_match_all('/:[a-z-]+/i', $pseudo);
        return $specificity;
    }

    /**
     * @param array<int, array<string, mixed>> $sourceProvenance
     * @return list<DOMElement>
     */
    private function navigationSourceAnchorsForClass(string $class, array $sourceProvenance): array
    {
        $selectors = array();
        foreach ( $sourceProvenance as $entry ) {
            if ( ! in_array($entry['block_name'] ?? '', array( 'core/navigation-link', 'core/navigation-submenu' ), true) ) {
                continue;
            }
            if ( in_array($class, $this->navigationSourceOwnershipClasses($entry, 'anchor'), true) ) {
                $selector = (string) ($entry['navigation_source_ownership']['anchor']['selector'] ?? $entry['selector'] ?? '');
                if ( '' !== $selector ) {
                    $selectors[$selector] = true;
                }
            }
        }
        if ( array() === $selectors ) {
            return array();
        }

        $anchors = array();
        foreach ( $this->authorStyleSourceElementsByClass[$class] ?? array() as $element ) {
            if ( $element instanceof DOMElement
                && 'a' === strtolower($element->tagName)
                && isset($selectors[$this->elementSelector($element)])
            ) {
                $anchors[] = $element;
            }
        }
        return $anchors;
    }

    /**
     * Source navigation anchors directly owned by a class-bearing source item.
     *
     * @param array<int, array<string, mixed>> $sourceProvenance
     * @return list<DOMElement>
     */
    private function navigationSourceAnchorsForItemClass(string $class, array $sourceProvenance): array
    {
        $selectors = array();
        foreach ( $sourceProvenance as $entry ) {
            if ( 'core/navigation-link' === ($entry['block_name'] ?? '') && 'a' === ($entry['tag'] ?? '') ) {
                $selectors[(string) ($entry['selector'] ?? '')] = true;
            }
        }
        if ( array() === $selectors ) {
            return array();
        }

        $anchors = array();
        foreach ( $this->authorStyleSourceElementsByClass[$class] ?? array() as $item ) {
            if ( ! $item instanceof DOMElement ) {
                continue;
            }
            foreach ( $item->childNodes as $child ) {
                if ( $child instanceof DOMElement
                    && 'a' === strtolower($child->tagName)
                    && isset($selectors[$this->elementSelector($child)])
                ) {
                    $anchors[] = $child;
                }
            }
        }
        return $anchors;
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<int, array<string, mixed>> $authoredRules
     * @param list<DOMElement> $anchors
     */
    private function navigationRuleWinsPropertyOnAnchors(array $candidate, string $property, array $authoredRules, array $anchors): bool
    {
        foreach ( $anchors as $anchor ) {
            $winner = null;
            foreach ( $authoredRules as $rule ) {
                if ( ($candidate['conditions'] ?? array()) !== ($rule['conditions'] ?? array())
                    || ($candidate['pseudo'] ?? '') !== ($rule['pseudo'] ?? '')
                    || ! array_key_exists($property, is_array($rule['declarations'] ?? null) ? $rule['declarations'] : array())
                ) {
                    continue;
                }
                $match = CssSelectorMatcher::matches($anchor, $rule['parsed'], true);
                if ( ! $match['supported'] || ! $match['matches'] ) {
                    continue;
                }
                $entry = array(
                    'id' => $rule['id'],
                    'important' => $this->navigationDeclarationIsImportant((string) $rule['declarations'][$property]),
                    'specificity' => $rule['specificity'],
                    'order' => $rule['order'],
                );
                if ( null === $winner || $this->navigationCascadeEntryWins($entry, $winner) ) {
                    $winner = $entry;
                }
            }

            if ( array() === ($candidate['conditions'] ?? array()) && '' === ($candidate['pseudo'] ?? '') ) {
                $inline = $this->safeVisualDeclarations($this->cssDeclarations($this->attr($anchor, 'style')));
                if ( array_key_exists($property, $inline) ) {
                    $entry = array(
                        'id' => -1,
                        'important' => $this->navigationDeclarationIsImportant((string) $inline[$property]),
                        'specificity' => array( PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX ),
                        'order' => PHP_INT_MAX,
                    );
                    if ( null === $winner || $this->navigationCascadeEntryWins($entry, $winner) ) {
                        $winner = $entry;
                    }
                }
            }

            if ( ! is_array($winner) || ($candidate['id'] ?? null) !== $winner['id'] ) {
                return false;
            }
        }
        return array() !== $anchors;
    }

    /**
     * Fail closed when a conditioned rule can join the same source cascade.
     *
     * Condition stacks include layers and scopes whose ordering cannot be
     * proven by the selector-only comparison above. Restrict the abstention to
     * rules that set the same property in the same state on a mapped anchor.
     *
     * @param array<string, mixed> $candidate
     * @param array<int, array<string, mixed>> $authoredRules
     * @param list<DOMElement> $anchors
     */
    private function navigationRuleHasConditionalPropertyCompetitorOnAnchors(array $candidate, string $property, array $authoredRules, array $anchors): bool
    {
        foreach ( $authoredRules as $rule ) {
            if ( array() === ($rule['conditions'] ?? array())
                || ($candidate['pseudo'] ?? '') !== ($rule['pseudo'] ?? '')
                || ! array_key_exists($property, is_array($rule['declarations'] ?? null) ? $rule['declarations'] : array())
            ) {
                continue;
            }
            foreach ( $anchors as $anchor ) {
                $match = CssSelectorMatcher::matches($anchor, $rule['parsed'], true);
                if ( $match['supported'] && $match['matches'] ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Expand a border shorthand before projection so stronger authored side
     * rules keep winning the same longhands they won in the source cascade.
     *
     * @param array<string, mixed> $candidate
     * @param array<int, array<string, mixed>> $authoredRules
     * @param list<DOMElement> $anchors
     * @return list<string>
     */
    private function navigationBorderWinnerDeclarations(array $candidate, string $value, array $authoredRules, array $anchors): array
    {
        $mapped = ( new StyleAttributeMapper() )->map(array( 'border' => $value ));
        $border = is_array($mapped['style']['border'] ?? null) ? $mapped['style']['border'] : array();
        $components = array_filter(array(
            'width' => trim((string) ($border['width'] ?? '')),
            'style' => trim((string) ($border['style'] ?? '')),
            'color' => trim((string) ($border['color'] ?? '')),
        ), static fn (string $componentValue): bool => '' !== $componentValue);
        if ( 3 !== count($components) ) {
            return array();
        }

        $declarations = array();
        foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
            foreach ( $components as $component => $componentValue ) {
                $virtualProperty = 'border-' . $side . '-' . $component;
                if ( $this->navigationRuleWinsBorderVirtualPropertyOnAnchors(
                    $candidate,
                    $virtualProperty,
                    $authoredRules,
                    $anchors
                ) ) {
                    $declarations[] = $virtualProperty . ':' . $componentValue;
                }
            }
        }

        return $declarations;
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<int, array<string, mixed>> $authoredRules
     * @param list<DOMElement> $anchors
     */
    private function navigationRuleWinsBorderVirtualPropertyOnAnchors(array $candidate, string $virtualProperty, array $authoredRules, array $anchors): bool
    {
        $virtualRules = array();
        foreach ( $authoredRules as $rule ) {
            $virtualValue = null;
            foreach ( is_array($rule['declarations'] ?? null) ? $rule['declarations'] : array() as $property => $value ) {
                if ( $this->navigationBorderDeclarationAffectsVirtualProperty((string) $property, $virtualProperty) ) {
                    $virtualValue = (string) $value;
                }
            }
            if ( null === $virtualValue ) {
                continue;
            }
            $rule['declarations'] = array( $virtualProperty => $virtualValue );
            $virtualRules[] = $rule;
        }

        return $this->navigationRuleWinsPropertyOnAnchors($candidate, $virtualProperty, $virtualRules, $anchors);
    }

    private function navigationBorderDeclarationAffectsVirtualProperty(string $property, string $virtualProperty): bool
    {
        if ( 1 !== preg_match('/^border-(top|right|bottom|left)-(width|style|color)$/', $virtualProperty, $match) ) {
            return false;
        }

        return in_array($property, array(
            'border',
            'border-' . $match[1],
            'border-' . $match[2],
            $virtualProperty,
        ), true);
    }

    /**
     * @param list<DOMElement> $anchors
     */
    private function navigationSourceListItemResetValue(string $class, string $property, array $anchors): ?string
    {
        $values = array();
        foreach ( $anchors as $anchor ) {
            $item = $anchor->parentNode;
            if ( ! $item instanceof DOMElement || 'li' !== strtolower($item->tagName) ) {
                return null;
            }

            $itemClasses = preg_split('/\s+/', trim($this->attr($item, 'class'))) ?: array();
            if ( in_array($class, $itemClasses, true) ) {
                // The class also belonged to the source item. Its item paint is
                // authored, not an artifact of core moving the anchor class.
                return null;
            }

            $itemDeclarations = $this->safeVisualDeclarations(
                $this->cssDeclarations($this->specificityResolvedPresentationStyle($item))
            );
            foreach ( $itemDeclarations as $itemProperty => $_itemValue ) {
                if ( $itemProperty !== $property && $this->navigationPropertiesOverlap($property, $itemProperty) ) {
                    return null;
                }
            }

            $value = trim((string) ($itemDeclarations[$property] ?? 'revert'));
            if ( '' === $value || $this->navigationDeclarationIsImportant($value) ) {
                return null;
            }
            $values[$value] = true;
        }

        return 1 === count($values) ? (string) array_key_first($values) : null;
    }

    private function navigationPropertiesOverlap(string $first, string $second): bool
    {
        if ( $first === $second ) {
            return true;
        }

        foreach ( array( 'background', 'border', 'font', 'margin', 'padding' ) as $family ) {
            $firstInFamily = $family === $first || str_starts_with($first, $family . '-');
            $secondInFamily = $family === $second || str_starts_with($second, $family . '-');
            if ( $firstInFamily && $secondInFamily && ($family === $first || $family === $second) ) {
                return true;
            }
        }

        return false;
    }

    private function navigationDeclarationIsImportant(string $value): bool
    {
        return 1 === preg_match('/\s*!\s*important\s*$/i', $value);
    }

    /**
     * @param array{id: int, important: bool, specificity: array{int, int, int}, order: int} $candidate
     * @param array{id: int, important: bool, specificity: array{int, int, int}, order: int} $current
     */
    private function navigationCascadeEntryWins(array $candidate, array $current): bool
    {
        if ( $candidate['important'] !== $current['important'] ) {
            return $candidate['important'];
        }
        $specificity = $this->compareMediaTextSpecificity($candidate['specificity'], $current['specificity']);
        return 0 < $specificity || (0 === $specificity && $candidate['order'] >= $current['order']);
    }

    /**
     * Classes authored on anchors that became navigation-link blocks.
     *
     * Source provenance distinguishes an anchor-owned class from one authored on
     * the source `<li>`, whose `className` legitimately belongs on the item.
     *
     * @return array<string, true>
     */
    private function listNavigationAnchorClasses(array $sourceProvenance): array
    {
        $classes = array();
        foreach ( $sourceProvenance as $entry ) {
            if ( ! in_array($entry['block_name'] ?? '', array( 'core/navigation-link', 'core/navigation-submenu' ), true) ) {
                continue;
            }

            foreach ( $this->navigationSourceOwnershipClasses($entry, 'anchor') as $candidate ) {
                if ( '' !== $candidate && ! str_starts_with($candidate, 'blocks-engine-') ) {
                    $classes[$candidate] = true;
                }
            }
        }

        return $classes;
    }

    /** @return list<string> */
    private function navigationColorInteractionStates(DOMElement $element): array
    {
        $matched = array();
        foreach ( $this->navigationStateStyleRules as $rule ) {
            if ( ! isset($rule['declarations']['color'])
                || ! $this->matchesCssSelector($element, $rule['base_selector'])
            ) {
                continue;
            }
            $matched[$rule['state']] = true;
        }

        return array_values(array_filter(
            array( 'hover', 'focus', 'focus-visible', 'active' ),
            static fn (string $state): bool => isset($matched[$state])
        ));
    }

    private function isAuthoredCurrentNavigationClass(string $className): bool
    {
        foreach ( preg_split('/[^a-z0-9]+/', strtolower($className)) ?: array() as $token ) {
            if ( in_array($token, array( 'active', 'current', 'selected' ), true) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Carry each navigation-link's resolved resting colour to the anchor core
     * renders. core/navigation-link does not consume style.color.text, while
     * adaptive header chrome can target the rendered anchor directly and beat
     * an inherited parent navigation colour.
     *
     * @return array<int, string>
     */
    private function navigationLinkTextColorRules(string $serializedBlocks): array
    {
        $prefix = 'blocks-engine-navigation-link-color-';
        $currentPrefix = 'blocks-engine-navigation-current-color-';
        $statePrefix = 'blocks-engine-navigation-link-color-states-';
        if ( (! str_contains($serializedBlocks, $prefix) && ! str_contains($serializedBlocks, $currentPrefix))
            || ! preg_match_all('/<!--\s*wp:navigation-(?:link|submenu)\s*(\{.*?\})\s*\/?-->/s', $serializedBlocks, $matches, PREG_SET_ORDER)
        ) {
            return array();
        }

        $rules = array();
        $currentColors = array();
        $defaultColors = array();
        foreach ( $matches as $match ) {
            $attrs = json_decode($match[1], true);
            if ( ! is_array($attrs) ) {
                continue;
            }

            $color = trim((string) ($attrs['style']['color']['text'] ?? ''));
            if ( '' === $color ) {
                foreach ( preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array() as $class ) {
                    if ( isset($this->navigationLinkColorFallbacks[ $class ]) ) {
                        $color = $this->navigationLinkColorFallbacks[ $class ];
                        break;
                    }
                }
            }
            if ( '' === $color
                || preg_match('~[{}<>;]|/\*|(?:expression|url)\s*\(|javascript\s*:~i', $color)
                || array() === $this->cssDeclarations('color:' . $color)
            ) {
                continue;
            }

            $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array();
            $stateMask = $this->navigationColorStateMaskFromClasses($classes, $statePrefix);
            if ( null === $stateMask ) {
                continue;
            }
            $expectedClass = $prefix . hash('sha256', $color . "\0" . $stateMask);
            $restingSuffix = $this->navigationColorRestingSuffix($stateMask);
            if ( in_array($expectedClass, $classes, true) ) {
                $selector = '.wp-block-navigation .wp-block-navigation-item.' . $expectedClass
                    . '>.wp-block-navigation-item__content' . $restingSuffix;
                $rules[$expectedClass] = $selector . '{color:' . $color . '}';
                $defaultColors[$expectedClass] = $color;
            }

            if ( in_array('blocks-engine-current-navigation-item', $classes, true) ) {
                $currentColors[$currentPrefix . hash('sha256', $color . "\0" . $stateMask)] = array(
                    'color' => $color,
                    'state_mask' => $stateMask,
                );
            }
        }

        if ( (array() !== $currentColors || array() !== $defaultColors)
            && preg_match_all('/<!--\s*wp:navigation\s*(\{.*?\})\s*-->/s', $serializedBlocks, $navigationMatches, PREG_SET_ORDER)
        ) {
            foreach ( $navigationMatches as $navigationMatch ) {
                $attrs = json_decode($navigationMatch[1], true);
                if ( ! is_array($attrs) ) {
                    continue;
                }

                $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array();
                foreach ( $classes as $className ) {
                    if ( isset($defaultColors[$className]) && in_array('blocks-engine-native-responsive-navigation', $classes, true) ) {
                        $selector = '.wp-block-navigation.blocks-engine-native-responsive-navigation.' . $className;
                        $rules['responsive:' . $className] = $selector . '>.wp-block-navigation__responsive-container-open,'
                            . $selector . ' .wp-block-navigation__responsive-container-close{color:' . $defaultColors[$className] . '}';
                    }
                    if ( ! isset($currentColors[$className]) ) {
                        continue;
                    }

                    $restingSuffix = $this->navigationColorRestingSuffix($currentColors[$className]['state_mask']);
                    $selector = '.wp-block-navigation.' . $className
                        . ' .wp-block-navigation-item.current-menu-item>.wp-block-navigation-item__content' . $restingSuffix
                        . ',.wp-block-navigation.' . $className
                        . ' .wp-block-navigation-item__content[aria-current]' . $restingSuffix;
                    $rules['current:' . $className] = $selector . '{color:' . $currentColors[$className]['color'] . '}';
                }
            }
        }

        return array_values($rules);
    }

    /** @param list<string> $classes */
    private function navigationColorStateMaskFromClasses(array $classes, string $prefix): ?int
    {
        $masks = array();
        foreach ( $classes as $className ) {
            if ( ! str_starts_with($className, $prefix) ) {
                continue;
            }
            $value = substr($className, strlen($prefix));
            if ( ! ctype_digit($value) || 15 < (int) $value ) {
                return null;
            }
            $masks[(int) $value] = true;
        }

        if ( 1 < count($masks) ) {
            return null;
        }

        return array() === $masks ? 0 : (int) array_key_first($masks);
    }

    private function navigationColorRestingSuffix(int $stateMask): string
    {
        $suffix = '';
        foreach ( array( 'hover' => 1, 'focus' => 2, 'focus-visible' => 4, 'active' => 8 ) as $state => $bit ) {
            if ( 0 !== ($stateMask & $bit) ) {
                $suffix .= ':not(:' . $state . ')';
            }
        }

        return $suffix;
    }

    /**
     * Whether an authored selector's ancestor part names a promoted navigation
     * host, so a rule about a menu is not confused with one about a footer.
     *
     * @param array<string, true> $hostClasses
     */
    private function namesNavigationHost(string $ancestor, array $hostClasses): bool
    {
        if ( ! preg_match_all('/\.([A-Za-z_][A-Za-z0-9_-]*)/', $ancestor, $matches) ) {
            return false;
        }

        foreach ( $matches[1] as $candidate ) {
            if ( isset($hostClasses[$candidate]) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Classes carried by navigation-link items in the serialized output.
     *
     * @return array<string, true>
     */
    private function listNavigationItemClasses(string $serializedBlocks): array
    {
        if ( ! preg_match_all('/<!--\s*wp:navigation-link\s*(\{.*?\})\s*\/-->/s', $serializedBlocks, $matches, PREG_SET_ORDER) ) {
            return array();
        }

        $classes = array();
        foreach ( $matches as $match ) {
            $attrs = json_decode($match[1], true);
            if ( ! is_array($attrs) ) {
                continue;
            }

            foreach ( preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array() as $candidate ) {
                if ( '' !== $candidate && ! str_starts_with($candidate, 'blocks-engine-') ) {
                    $classes[$candidate] = true;
                }
            }
        }

        return $classes;
    }

    /**
     * Classes carried by promoted list-navigation hosts in the serialized
     * output, as a lookup.
     *
     * @return array<string, true>
     */
    private function listNavigationHostClasses(string $serializedBlocks, bool $listOnly = true): array
    {
        if ( ! preg_match_all('/<!--\s*wp:navigation\s*(\{.*?\})\s*-->/s', $serializedBlocks, $matches, PREG_SET_ORDER) ) {
            return array();
        }

        $classes = array();
        foreach ( $matches as $match ) {
            $attrs = json_decode($match[1], true);
            if ( ! is_array($attrs) ) {
                continue;
            }

            $className = (string) ($attrs['className'] ?? '');
            if ( $listOnly && ! str_contains($className, 'blocks-engine-list-navigation') ) {
                continue;
            }

            foreach ( preg_split('/\s+/', trim($className)) ?: array() as $candidate ) {
                if ( '' !== $candidate && ! str_starts_with($candidate, 'blocks-engine-') ) {
                    $classes[$candidate] = true;
                }
            }
        }

        return $classes;
    }

    /**
     * The authored inline-axis margins of a rule, but only when at least one
     * side is `auto` — that is the declaration that positions a flex item, and
     * the one core's list reset destroys. The opposite side rides along so a
     * one-sided `auto` cannot be read as centring once both sides are restated.
     *
     * @param array<string, mixed> $declarations
     * @return array<string, string>
     */
    private function inlineAxisAutoMargins(array $declarations): array
    {
        $sides = array( 'left' => '', 'right' => '' );

        $shorthand = trim((string) ($declarations['margin'] ?? ''));
        if ( '' !== $shorthand ) {
            $parts = preg_split('/\s+/', $shorthand) ?: array();
            $count = count($parts);
            if ( 4 === $count ) {
                $sides['right'] = $parts[1];
                $sides['left'] = $parts[3];
            } elseif ( 2 === $count || 3 === $count ) {
                $sides['right'] = $parts[1];
                $sides['left'] = $parts[1];
            } elseif ( 1 === $count ) {
                $sides['right'] = $parts[0];
                $sides['left'] = $parts[0];
            }
        }

        foreach ( array( 'left' => array( 'margin-left', 'margin-inline-start' ), 'right' => array( 'margin-right', 'margin-inline-end' ) ) as $side => $properties ) {
            foreach ( $properties as $property ) {
                $value = trim((string) ($declarations[$property] ?? ''));
                if ( '' !== $value ) {
                    $sides[$side] = $value;
                }
            }
        }

        if ( 'auto' !== strtolower($sides['left']) && 'auto' !== strtolower($sides['right']) ) {
            return array();
        }

        $carried = array();
        foreach ( $sides as $side => $value ) {
            if ( '' !== $value ) {
                $carried[$side] = 'auto' === strtolower($value) ? 'auto' : $value;
            }
        }

        return $carried;
    }

    private function sourceMobileNavigationOverlayBackground(): string
    {
        $background = '';
        foreach ( array_merge($this->staticStyleRules, $this->conditionalStyleRules) as $rule ) {
            $selector = strtolower((string) ($rule['selector'] ?? ''));
            if ( ! str_contains($selector, 'nav') || ! preg_match('/(?:^|[^a-z0-9])(?:mobile|drawer|offcanvas|overlay|menu-panel|nav-panel)(?:[^a-z0-9]|$)/', $selector) ) {
                continue;
            }

            $declarations = is_array($rule['declarations'] ?? null) ? $rule['declarations'] : array();
            $candidate = trim((string) ($declarations['background-color'] ?? $declarations['background'] ?? ''));
            if ( '' !== $candidate && ! in_array(strtolower($candidate), array( 'none', 'transparent', 'inherit', 'initial', 'unset' ), true) ) {
                $background = $candidate;
            }
        }

        return $background;
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
        $this->formLayoutCss = $this->combinedAuthorCss;
        // Ignore already-generated-looking markers when seeding so collision
        // avoidance remains deterministic even when source CSS contains one.
        $seedInput = preg_replace('/blocks-engine-(?:source-[a-z][a-z0-9-]*|control|table|specificity(?:-(?:class|id))?)-[a-f0-9]+-\d+/', '', $html . "\0" . $this->combinedAuthorCss) ?? '';
        $this->authorMarkerSeed = substr(hash('sha256', $seedInput), 0, 12);
        $this->authorMarkerCollisionText = $html . "\0" . $this->combinedAuthorCss;
        $this->authorSpecificityShim = $this->allocateAuthorMarker('specificity');
        $this->authorClassSpecificityShim = $this->allocateAuthorMarker('specificity-class');
        $this->authorIdSpecificityShim = $this->allocateAuthorMarker('specificity-id');

        if ( '' === $this->combinedAuthorCss ) {
            return;
        }

        $this->authorStyleSourceBody = $sourceBody;
        $this->authorSelectorMatchCache = new CssSelectorMatchCache();
		for ( $ancestor = $sourceBody; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode ) {
			$this->recordAuthorSelectorSignals($ancestor);
		}
        foreach ( $sourceBody->getElementsByTagName('*') as $element ) {
            if ( $element instanceof DOMElement ) {
                $this->authorStyleSourceElements[] = $element;
				$this->recordAuthorSelectorSignals($element);
				$this->authorStyleSourceElementsByTag[strtolower($element->tagName)][] = $element;
				$id = $this->attr($element, 'id');
				if ( '' !== $id ) {
					$this->authorStyleSourceElementsById[$id][] = $element;
				}
				foreach ( preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array() as $class ) {
					if ( '' !== $class ) {
						$this->authorStyleSourceElementsByClass[$class][] = $element;
					}
				}
            }
        }

        $authorAnalysis = $this->composedAuthorSelectorAnalysis($this->authorStylesheetPayloads($html, $staticCss));
        $sourceTagSelectorNames = $authorAnalysis['source_tags'];
        $authorSelectors = $authorAnalysis['selectors'];
        $authorStyleRules = $authorAnalysis['rules'];
        foreach ( array_keys($sourceTagSelectorNames) as $tagName ) {
            $this->sourceTagMarkers[ $tagName ] = $this->allocateAuthorMarker('source-' . $tagName);
        }
		$this->discoverAuthorControlPaths($authorSelectors);
		$this->authorSelectors = $authorSelectors;
		$this->authorStyleRules = $authorStyleRules;
		$this->discoverAuthorInlineSemanticPaths($authorSelectors);
		$this->discoverAuthorAttributePaths($authorSelectors);
		$this->discoverAuthorRootChildPaths($authorSelectors);
		$this->discoverAuthorTablePaths($authorSelectors);
        $this->sourceBodyProjectionClasses = $this->referencedSourceBodyClasses($sourceBody);
        $this->analysisCache->authorSelectorClassTokenBuilds += $this->authorSelectorMatchCache->classTokenBuilds;
        $this->analysisCache->authorSelectorClassTokenHits += $this->authorSelectorMatchCache->classTokenHits;
        $this->analysisCache->authorSelectorAttributeReads += $this->authorSelectorMatchCache->attributeReads;
        $this->authorSelectorMatchCache = null;
    }

    /** @return list<string> */
    private function referencedSourceBodyClasses(DOMElement $sourceBody): array
    {
        $classes = preg_split('/\s+/', trim($this->attr($sourceBody, 'class'))) ?: array();
        return array_values(array_filter(array_unique($classes), function (string $class): bool {
            return '' !== $class && (bool) preg_match('/\.' . preg_quote($class, '/') . '(?:\b|(?=[.#:\[]))/', $this->combinedAuthorCss);
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
                        $this->sourceControlPaths[$path] = true;
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
                        $marker = $this->sourceRichTextSemanticMarkers[$path] ??= $this->allocateAuthorMarker('richtext');
                        $element->setAttribute('data-blocks-engine-richtext-marker', $marker);
                    } elseif ( $directAuthorLayoutItem
                        || ($structuralListItem && $this->richTextSelectorNeedsHook($parsed))
                        || $this->requiresIndependentSemanticWrapper($element)
                    ) {
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
    }

	/** @param list<array{selector:string,parsed:array<string,mixed>}> $authorSelectors */
    private function discoverAuthorAttributePaths(array $authorSelectors): void
    {
		foreach ( $authorSelectors as $authorSelector ) {
            $parsed = $authorSelector['parsed'];
            if ( ! $parsed['supported'] || null !== $parsed['pseudo_state_suffix_span'] ) {
                continue;
            }
            $compounds = $parsed['compounds'] ?? array();
            $rightmost = $compounds[array_key_last($compounds)] ?? array();
            $hasDataAttribute = array_filter($rightmost['attributes'] ?? array(), static fn (array $attribute): bool => str_starts_with($attribute['name'] ?? '', 'data-'));
            if ( array() === $hasDataAttribute ) {
                continue;
            }
            foreach ( $this->matchingAuthorSourceElements($authorSelector['selector'], $parsed) as $element ) {
                $declarations = $this->structuralPresentationDeclarations($element);
                $hasBoxGeometry = array() !== array_intersect_key($declarations, array_flip(array(
                    'display', 'position', 'inset', 'top', 'right', 'bottom', 'left',
                    'width', 'min-width', 'max-width', 'height', 'min-height', 'max-height',
                    'margin', 'padding', 'flex', 'flex-basis', 'grid', 'grid-area',
                )));
                if ( ! $hasBoxGeometry && 'img' !== strtolower($element->tagName) ) {
                    continue;
                }
                $path = $this->sourceElementIdentity($element);
                if ( '' !== $path ) {
                    $this->sourceAttributeMarkers[$path] ??= $this->allocateAuthorMarker('attribute');
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
                        $this->sourceRootChildMarkers[$path] ??= $this->allocateAuthorMarker('root-child');
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
                        $this->sourceTableMarkers[$path] ??= $this->allocateAuthorMarker('table');
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
        if ( array() !== $this->authorStylesheetAssets ) {
            $payloads = array_values(array_filter($this->authorStylesheetAssets, static fn (array $asset): bool => '' !== trim($asset['content'])));
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
        if ( ! preg_match_all('@<style\b[^>]*>(.*?)</style>@is', $html, $matches) ) {
            return array();
        }

        return array_map(static fn (string $payload): string => trim($payload), $matches[1]);
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
                $analysis = array(
                    'static' => $this->staticStyleRules('', $payload),
                    'conditional' => $this->conditionalStyleRules('', $payload),
                    'navigation_state' => $this->navigationStateStyleRules('', $payload),
                    'image_shape' => $this->imageShapeStyleRules('', $payload),
                    'pseudo' => $this->staticPseudoElementStyleRules('', $payload),
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
                $rules[] = array('order' => count($rules), 'declarations' => $this->cssDeclarations($body), 'selectors' => $ruleSelectors);
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
        foreach ( $this->authorStylesheetAssets as $asset ) {
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
        do {
            $marker = 'blocks-engine-' . $kind . '-' . $this->authorMarkerSeed . '-' . $this->authorMarkerCounter++;
        } while ( str_contains($this->authorMarkerCollisionText, $marker) );
        return $marker;
    }

    private function rewriteAuthorStylesheet(string $stylesheet): string
    {
        return ( new CssStylesheetTransformer() )->transformStyleRules($stylesheet, function (string $prelude, string $body): string {
            $body = $this->projectResponsiveCanvasMinimumWidth($prelude, $body);
            $declarations = $this->cssDeclarations($body);
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
            $rules = '' === $this->cssDeclarationString($inner)
                ? ''
                : $this->rewriteAuthorStyleRule($prelude, $this->cssDeclarationString($inner));
            return $rules . $this->rewriteAuthorSelectorPrelude($prelude, true) . '{' . $this->cssDeclarationString($margins) . '}' . $imageRule . $svgImageRule;
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
        $declarations = $this->cssDeclarations($body);
        $minimumWidth = (string) ($declarations['min-width'] ?? '');
        if ( ! $this->isWideAbsoluteMinimumWidth($minimumWidth) ) {
            return $body;
        }

        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors || ! $this->authorStyleSourceBody instanceof DOMElement ) {
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
                    $this->responsiveGeometryAmbiguities[$selector . "\0" . $minimumWidth] = array('selector' => $selector, 'min_width' => $minimumWidth);
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
        if ( $element->parentNode === $this->authorStyleSourceBody ) {
            return true;
        }

        if ( in_array(strtolower($element->tagName), array( 'header', 'main', 'footer', 'section' ), true) ) {
            return true;
        }

        $parent = $element->parentNode;
        return $parent instanceof DOMElement
            && $parent->parentNode === $this->authorStyleSourceBody
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
            return $directWrapperPrelude . '{' . $geometry . '}' . ('' === $inner ? '' : $projectedPrelude . '{' . $inner . '}');
        }

        [ $layout, $control ] = $this->splitButtonPresentationDeclarations($body);
        if ( '' === $layout ) {
            return $projectedPrelude . '{' . $body . '}';
        }
        if ( '' === $control ) {
            return $wrapperPrelude . '{' . $body . '}';
        }

        return $wrapperPrelude . '{' . $layout . '}' . $projectedPrelude . '{' . $control . '}';
    }

    private function buttonPresentationWrapperPrelude(string $prelude): string
    {
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors || ! $this->authorStyleSourceBody instanceof DOMElement ) {
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
                $marker = $this->sourceButtonPresentationMarkers[$element->getNodePath() ?? ''] ?? null;
                if ( ! is_string($marker) ) {
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
        if ( null === $selectors || ! $this->authorStyleSourceBody instanceof DOMElement ) {
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
                $marker = $this->sourceControlMarkers[$path] ?? null;
                if ( ! is_string($marker) || isset($this->sourceButtonPresentationMarkers[$path]) ) {
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
            $name = strtolower(trim(strtok($declaration, ':')));
            if ( '' !== $name && str_contains($declaration, ':') && in_array($name, array( 'position', 'top', 'right', 'bottom', 'left', 'z-index', 'width', 'min-width', 'max-width', 'height', 'min-height', 'max-height' ), true) ) {
                $geometry[] = $declaration;
            } else {
                $inner[] = $declaration;
            }
        }
        return array( implode(';', $geometry), implode(';', $inner) );
    }

    private function projectSourceBodyStateSelector(string $selector): string
    {
        if ( array() === $this->sourceBodyProjectionClasses ) {
            return $selector;
        }

        $classes = implode('|', array_map(static fn (string $class): string => preg_quote($class, '/'), $this->sourceBodyProjectionClasses));
        return preg_replace('/^\s*body(?=\.(?:' . $classes . ')(?:\b|[.#:\[]))/', '', $selector, 1) ?? $selector;
    }

    /** @return array{string, string} */
    private function splitButtonPresentationDeclarations(string $body): array
    {
        $layout = array();
        $control = array();
        foreach ( CssValueSplitter::splitTopLevel($body, array( ';' )) as $declaration ) {
            $name = strtolower(trim(strtok($declaration, ':')));
            if ( '' === $name || ! str_contains($declaration, ':') ) {
                $control[] = $declaration;
                continue;
            }
            if ( $this->isButtonWrapperLayoutProperty($name) ) {
                $layout[] = $declaration;
            } else {
                $control[] = $declaration;
            }
        }

        return array( implode(';', $layout), implode(';', $control) );
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
        if ( null === $selectors || ! $this->authorStyleSourceBody instanceof DOMElement ) {
            return $prelude;
        }

        $rewritten = array();
        foreach ( $selectors as $selector ) {
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
                } elseif ( isset($this->sourceControlMarkers[$path]) ) {
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
            $marker = $this->sourceAttributeMarkers[$this->sourceElementIdentity($element)] ?? null;
            if ( ! is_string($marker) ) {
                return null;
            }
            $projected[] = ':where(.' . $marker . ')' . $this->selectorSpecificityShims($parsed);
        }

        return array_values(array_unique($projected));
    }

    private function projectAuthorImageSelectorPrelude(string $prelude, string $tagName = 'img', array $declarations = array()): string
    {
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors || ! $this->authorStyleSourceBody instanceof DOMElement ) {
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
                    $marker = $this->sourceRootChildMarkers[$this->sourceElementIdentity($element)] ?? '';
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
        $parentStyle = $this->structuralPresentationDeclarations($parent);
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
        return $this->parsedCssSelectors[$selector] ??= CssSelectorMatcher::parse($selector);
    }

    /** @param array<string, mixed> $parsed @return list<DOMElement> */
    private function matchingAuthorSourceElements(string $selector, array $parsed): array
    {
        if ( array_key_exists($selector, $this->authorSourceSelectorMatches) ) {
            ++$this->analysisCache->authorSelectorMatchResultHits;
            return $this->authorSourceSelectorMatches[$selector];
        }
		++$this->analysisCache->authorSelectorMatchResultBuilds;
		if ( ! $this->authorSelectorCanMatch($parsed) ) {
			return $this->authorSourceSelectorMatches[$selector] = array();
		}
        $matches = array();
        foreach ( $this->authorSelectorCandidates($parsed) as $element ) {
            if ( CssSelectorMatcher::matches($element, $parsed, true, $this->authorSelectorMatchCache)['matches'] ) {
                $matches[] = $element;
            }
        }
        return $this->authorSourceSelectorMatches[$selector] = $matches;
    }

	/** @param array<string, mixed> $parsed @return list<DOMElement> */
	private function authorSelectorCandidates(array $parsed): array
	{
		$compounds = $parsed['compounds'] ?? array();
		$rightmost = $compounds[array_key_last($compounds)] ?? array();
		$candidates = array();
		foreach ( $rightmost['ids'] ?? array() as $id ) {
			$candidates[] = $this->authorStyleSourceElementsById[$id] ?? array();
		}
		foreach ( $rightmost['classes'] ?? array() as $class ) {
			$candidates[] = $this->authorStyleSourceElementsByClass[$class] ?? array();
		}
		if ( is_string($rightmost['type'] ?? null) && '' !== $rightmost['type'] ) {
			$candidates[] = $this->authorStyleSourceElementsByTag[strtolower($rightmost['type'])] ?? array();
		}
		if ( array() === $candidates ) {
			return $this->authorStyleSourceElements;
		}
		usort($candidates, static fn (array $left, array $right): int => count($left) <=> count($right));
		return $candidates[0];
	}

	/** @param array<string, mixed> $parsed */
	private function authorSelectorCanMatch(array $parsed): bool
	{
		foreach ( $parsed['compounds'] ?? array() as $compound ) {
			if ( is_string($compound['type'] ?? null) && '' !== $compound['type'] && ! isset($this->authorStyleSourceTags[strtolower($compound['type'])]) ) {
				return false;
			}
			foreach ( $compound['ids'] ?? array() as $id ) {
				if ( ! isset($this->authorStyleSourceIds[$id]) ) {
					return false;
				}
			}
			foreach ( $compound['classes'] ?? array() as $class ) {
				if ( ! isset($this->authorStyleSourceClasses[$class]) ) {
					return false;
				}
			}
		}
		return true;
	}

	private function recordAuthorSelectorSignals(DOMElement $element): void
	{
		$this->authorStyleSourceTags[strtolower($element->tagName)] = true;
		$id = $this->attr($element, 'id');
		if ( '' !== $id ) {
			$this->authorStyleSourceIds[$id] = true;
		}
		foreach ( preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array() as $class ) {
			if ( '' !== $class ) {
				$this->authorStyleSourceClasses[$class] = true;
			}
		}
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
        $marker = $table instanceof DOMElement ? ($this->sourceTableMarkers[$this->sourceElementIdentity($table)] ?? '') : '';
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
        if ( ! isset($this->sourceTableDescendantPaths[$tableId]) ) {
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
            $this->sourceTableDescendantPaths[$tableId] = $paths;
        }
        return $this->sourceTableDescendantPaths[$tableId][spl_object_id($element)] ?? '';
    }

    private function isRepresentableTable(DOMElement $table): bool
    {
        $id = spl_object_id($table);
        return $this->sourceTableRepresentability[$id] ??= (bool) $this->tableClassificationPolicy->classify($table)['representable'];
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
                $this->presentationAttributes($cell),
                $this->convertChildren($cell, $fallbacks, true),
                $cell
            );
            // A blank layout-table cell remains a real native column: removing it
            // changes the rendered Columns topology.
            $column['_editability_visual_owned'] = true;
            $columns[] = $column;
        }

        return $this->createBlock('core/columns', $this->presentationAttributes($table), $columns, $table);
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
                    $this->presentationAttributes($cell),
                    $this->convertChildren($cell, $fallbacks, true),
                    $cell
                );
            }
            if (array() !== $columns) {
                $rows[] = $this->createBlock('core/columns', array(), $columns, $row);
            }
        }

        $tableAttributes = $this->presentationAttributes($table);
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
            $zeroSpecificity = $compound['zero_specificity'] ?? array();
            if ( null !== $compound['type'] && 0 === (int) ($zeroSpecificity['types'] ?? 0) ) {
                $shims .= $this->typeSpecificityShim();
            }
            $classCount = count($compound['classes']) - (int) ($zeroSpecificity['classes'] ?? 0);
            for ( $index = 0; $index < $classCount; ++$index ) {
                $shims .= ':not(.' . $this->authorClassSpecificityShim . ')';
            }
            $attributeCount = count($compound['attributes']) - (int) ($zeroSpecificity['attributes'] ?? 0);
            for ( $index = 0; $index < $attributeCount; ++$index ) {
                $shims .= ':not(.' . $this->authorClassSpecificityShim . ')';
            }
            $idCount = count($compound['ids']) - (int) ($zeroSpecificity['ids'] ?? 0);
            for ( $index = 0; $index < $idCount; ++$index ) {
                $shims .= ':not(#' . $this->authorIdSpecificityShim . ')';
            }
            if ( null !== $compound['nth_child'] || $compound['first_child'] || $compound['last_child'] ) {
                $shims .= ':not(.' . $this->authorClassSpecificityShim . ')';
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
        return $includeRuntimeDomTarget ? $this->patternContext : $this->patternContextWithoutRuntimeDomTarget;
    }

    private function createPatternContext(bool $includeRuntimeDomTarget): PatternContext
    {
        return new PatternContext(
            fn (DOMElement $sourceElement, array $excludedGeometryProperties = array()): array => $this->presentationAttributes($sourceElement, $excludedGeometryProperties),
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
                $includeRuntimeDomTarget ? fn (DOMElement $sourceElement): bool => $this->isRuntimeDomTarget($sourceElement) : null,
                fn (DOMElement $item, DOMElement $anchor): string => $this->navigationUnderlineColor($item, $anchor),
                fn (DOMElement $sourceElement): string => $this->resolveCssVariablesInValue($this->specificityResolvedPresentationStyle($sourceElement)),
                fn (DOMElement $sourceElement): array => $this->navigationColorInteractionStates($sourceElement),
                fn (DOMElement $sourceElement): string => $this->navigationOverlayMenu($sourceElement)
            ),
            fn (DOMElement $sourceElement): string => $this->mergedPresentationStyle($sourceElement),
            fn (DOMElement $sourceElement): array => $this->htmlAttributes($sourceElement),
            fn (string $url): string => $this->resolvedAssetImageUrl($url),
            fn (DOMElement $sourceElement, array $excludedGeometryProperties = array()): array => $this->mediaTextPresentationAttributes($sourceElement, $excludedGeometryProperties),
            fn (DOMElement $sourceElement): string => $this->mediaTextPresentationStyle($sourceElement),
            fn (DOMElement $sourceElement): string => $this->cssDeclarationString($this->structuralPresentationDeclarations($sourceElement))
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
            fn (DOMElement $sourceElement, array $excludedGeometryProperties = array()): array => $this->presentationAttributes($sourceElement, $excludedGeometryProperties),
            fn (DOMElement $sourceElement): string => $this->innerHtml($sourceElement),
            static fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => array(
                'blockName'   => $name,
                'attrs'       => $attrs,
                'innerBlocks' => $innerBlocks,
            ),
            null,
            new NavigationPatternContext(
                null,
                fn (DOMElement $item, DOMElement $anchor): string => $this->navigationUnderlineColor($item, $anchor),
                fn (DOMElement $sourceElement): string => $this->resolveCssVariablesInValue($this->specificityResolvedPresentationStyle($sourceElement))
            )
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
        // Conversion helpers may rewrite source markup. Do not reuse selector
        // results or cached inputs across independently converted elements.
        $this->invalidateSourceSelectorMatchCache();
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

        if ( isset($this->formControlSlotPaths[$element->getNodePath()]) ) {
            $block = $this->htmlPreservationBlock($element);
            $token = $this->formControlSlotPaths[$element->getNodePath()];
            if (is_string($token)) $block['_binding_token'] = $token;
            return $block;
        }

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

        if ('dialog' === $tagName && 'true' === $this->attr($element, 'data-blocks-engine-captured-dialog')) {
            return $this->capturedDialogBlock($element, $fallbacks);
        }

        if ( $this->shouldPreserveDataAttributeRuntimeTarget($element) ) {
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

        $mathBlock = $this->recognizePatterns($element, $fallbacks, array('math'));
        if ( null !== $mathBlock ) {
            return $mathBlock;
        }

        if ( preg_match('/^h([1-6])$/', $tagName, $matches) ) {
            $content = $this->richTextContentWithMaterializedInlineStyles($element);
            $content = $this->headingRichTextContent($content);
            if ( $this->richTextRequiresHtmlFallbackWithoutNativeSvgImageObjects($content) ) {
                return $this->htmlPreservationBlock($element);
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
            $marquee = $this->authoredMarqueeBlock($element);
            if ( null !== $marquee ) {
                return $marquee;
            }
            $content = $this->richTextContentWithMaterializedInlineStyles($element);
            $inlineSvgContent = $this->richTextContentWithMaterializedSvgImages($element, $content);
            if ( null !== $inlineSvgContent ) {
                $content = $inlineSvgContent;
            }
            if ( $this->richTextRequiresHtmlFallbackWithoutNativeSvgImageObjects($content) ) {
                return $this->htmlPreservationBlock($element);
            }
            if ( $this->hasEmptyVisualInlineChild($element) && $this->hasBoxChromeWrapperStyling($element) ) {
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

        $customVideo = $this->videoOnlyCustomElement($element);
        if ( $customVideo instanceof DOMElement ) {
            return $this->convertMediaElement($customVideo);
        }

        $mediaDispatch = $this->convertMediaDispatchElement($element, $tagName, $fallbacks);
        if ( $mediaDispatch['handled'] ) {
            return $mediaDispatch['block'];
        }

        if ($this->fallbackReductionMode && ( 'button' === $tagName || ( 'a' === $tagName && '' === trim($this->attr($element, 'aria-label')) ) )) {
            $text = $this->innerHtml($element);
            if ('' !== trim($this->runtime->stripAllTags($text))) {
                $attrs = array_merge($this->presentationAttributes($element), array('text' => $text));
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
            return $this->convertAnchorDispatchElement($element, $fallbacks);
        }

        if ( $this->isInlineContentElement($tagName) ) {
            if ( $this->isRuntimeDomTarget($element) ) {
                return $this->htmlPreservationBlock($element);
            }

            $inlineSvgTextGroup = $this->inlineSvgTextGroupBlockFromElement($element);
            if ( null !== $inlineSvgTextGroup ) {
                return $inlineSvgTextGroup;
            }

            if ( $this->ownsPositioningGeometry($element) ) {
                $carrier = $this->positionedInlineCarrierBlock($element, $fallbacks);
                if ( null !== $carrier ) {
                    return $carrier;
                }
            }

            if ( $this->hasAuthorSemanticMarker($element) ) {
                $content = $this->innerHtml($element);
                if ( '' !== trim($this->runtime->stripAllTags($content)) ) {
                    if ( $this->richTextContentHasStructuralHtml($content) ) {
                        $children = $this->convertChildren($element, $fallbacks, true);
                        if ( array() !== $children ) {
                            return $this->createBlock('core/group', $this->presentationAttributes($element), $children, $element);
                        }
                    }
                    return $this->createBlock('core/group', $this->presentationAttributes($element), array(
                        $this->createBlock('core/paragraph', array( 'content' => $content )),
                    ), $element);
                }
            }

            $richTextMarker = $this->richTextMarkerForElement($element);
            if ( '' !== $richTextMarker ) {
                // RichText only accepts phrasing content. Keep a selector-addressed
                // inline wrapper editable when it contains layout/content blocks by
                // lowering its children instead of storing structural HTML in content.
                if ( $this->hasBlockContentChildren($element) || $this->richTextContentHasStructuralHtml($this->innerHtml($element)) ) {
                    $children = $this->convertChildren($element, $fallbacks, true);
                    if ( array() !== $children ) {
                        return $this->createBlock('core/group', $this->presentationAttributes($element), $children, $element);
                    }
                }
                $content = $this->innerHtml($element);
                if ( '' !== trim($this->runtime->stripAllTags($content)) ) {
                    $declarations = $this->richTextInlineVisualDeclarations($element);
                    if ( 'transparent' === strtolower((string) ($declarations['-webkit-text-fill-color'] ?? '')) ) {
                        $declarations['color'] = 'transparent';
                    }
                    $declarations['--blocks-engine-richtext-marker'] = $richTextMarker;
                    return $this->createBlock('core/paragraph', array(
                        'content' => '<mark style="' . htmlspecialchars($this->cssDeclarationString($declarations), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . $content . '</mark>',
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
                    return $this->emptyVisualSpacerBlock($element);
                }

                return null;
            }

            $listItem = $this->ancestorElement($element, 'li');
            $sourceElement = $this->richTextContentHasStructuralHtml($content)
                || ($listItem instanceof DOMElement && $this->isStructuralListItem($listItem))
                ? $element
                : null;
            return $this->createBlock('core/paragraph', array( 'content' => $content ), array(), $sourceElement);
        }

        if ( 'ul' === $tagName || 'ol' === $tagName ) {
            $navigation = $this->recognizePatterns($element, $fallbacks, array(AccordionPattern::class, SocialLinksPattern::class, NavigationPattern::class));
            if ( null !== $navigation ) {
                return $this->rememberAccordionDisclosureRoot($navigation, $element);
            }

            if ( $this->isStructuredCardList($element) ) {
                $decomposed = $this->decomposeStructuredCardList($element, $fallbacks);
                if ( null !== $decomposed ) {
                    return $decomposed;
                }
            }

            if ( $this->listContainsStructuralItemContent($element) ) {
                return $this->decomposeStructuralList($element, $fallbacks);
            }

            $items = $this->listItems($element, $fallbacks);

            if ( array() === $items ) {
                return null;
            }

            // core/list has no layout support, so an author grid on the list
            // element rides the css-owned grid carrier instead of a layout attr.
            $listAttrs = $this->isCssOwnedGridElement($element)
                ? $this->cssOwnedGridAttributes($element)
                : $this->presentationAttributes($element);

            return $this->createBlock('core/list', array_merge($listAttrs, 'ol' === $tagName ? array( 'ordered' => true ) : array()), $items, $element);
        }

        if ( 'dl' === $tagName ) {
            $descriptionList = $this->descriptionListBlockFromElement($element);
            if ( null !== $descriptionList ) {
                return $descriptionList;
            }

            $metadataGrid = $this->metadataGridBlockFromElement($element);
            if ( null !== $metadataGrid ) {
                return $metadataGrid;
            }

            $items = $this->definitionListItems($element);
            if ( array() !== $items ) {
                $definitionListAttrs = $this->isCssOwnedGridElement($element)
                    ? $this->cssOwnedGridAttributes($element)
                    : $this->presentationAttributes($element);

                return $this->createBlock('core/list', $definitionListAttrs, $items, $element);
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
            return $this->recognizePatterns($element, $fallbacks, array('quote'));
        }

        if ( 'figure' === $tagName ) {
            $gallery = $this->mediaGalleryBlockFromElement($element, $fallbacks);
            if ( null !== $gallery ) {
                return $gallery;
            }

            $codeWindow = $this->recognizePatterns($element, $fallbacks, array('code-window'));
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
                return $this->recognizePatterns($element, $fallbacks, array('figure-quote'));
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
            if ( $this->tableClassificationPolicy->isNestedLayoutTableMember($element) ) {
                return $this->nestedLayoutTableColumnsBlock($element, $fallbacks);
            }

            if ( $this->tableClassificationPolicy->isMediaLayoutTable($element) ) {
                return $this->mediaLayoutTableColumnsBlock($element, $fallbacks);
            }

            $classification = $this->tableClassificationPolicy->classify($element);
            if ( ! $classification['representable'] ) {
                return $this->htmlPreservationBlock($element);
            }

            return $this->createBlock('core/table', array_merge($this->presentationAttributes($element), $this->tableAttributes($element)), array(), $element);
        }

        $parameterTable = $this->recognizePatterns($element, $fallbacks, array('parameter-table'));
        if ( null !== $parameterTable ) {
            return $parameterTable;
        }

        if ( 'hr' === $tagName ) {
            return $this->createBlock('core/separator', $this->presentationAttributes($element, array(), array( 'margin-left', 'margin-right' )), array(), $element);
        }

        if ( 'br' === $tagName ) {
            return null;
        }

        if ( 'details' === $tagName ) {
            return $this->recognizePatterns($element, $fallbacks, array('details'));
        }

        if ( 'a' === $tagName ) {
            return $this->convertAnchorDispatchElement($element, $fallbacks);
        }

        if ( 'button' === $tagName ) {
            if ( $this->isReplacedSearchClusterControl($element) ) {
                return null;
            }
            if ( $this->isImageCarrierButton($element) ) {
                $children = $this->convertChildren($element, $fallbacks, true);
                if ( array() !== $children ) {
                    return $this->createBlock('core/group', $this->presentationAttributes($element), $children, $element);
                }
            }
            return $this->convertButtonDispatchElement($element);
        }

        if ( 'svg' === $tagName ) {
            if ( $this->isInertHiddenSvgStorage($element) ) {
                return null;
            }
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
                // A proven positioned visual layer can collapse to its CSS-owned
                // carrier. Stretching alone is not evidence that artwork is
                // recreated elsewhere; preserve drawable stretched SVGs.
                $isDecorativeChrome = $this->isVisualLayerElement($element);
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
            return $this->htmlPreservationBlock($element);
        }

        if ( 'script' === $tagName ) {
            if ( $this->captureStaticScriptMetadata($element) ) {
                if ( $this->isAddressableStaticJsonTarget($element) ) {
                    $this->recordRuntimeIsland($element, 'static_script', 'static_script_runtime_target', 'client_script_configuration', array(
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
            $navigation = $this->recognizePatterns($element, $fallbacks, array(AccordionPattern::class, SocialLinksPattern::class, NavigationPattern::class), false);
            if ( null !== $navigation ) {
                return $this->rememberAccordionDisclosureRoot($navigation, $element);
            }

            $inlineNavigation = $this->inlineNavigationGroupBlockFromElement($element);
            if ( null !== $inlineNavigation ) {
                return $inlineNavigation;
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

            if ( $this->isEmptyInteractiveFeatureShell($element) ) {
                return null;
            }

            $this->captureDivBasedPseudoFormFallback($element, $fallbacks);

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

            $flankedSeparator = $this->flankedSeparatorBlockFromElement($element);
            if ( null !== $flankedSeparator ) {
                return $flankedSeparator;
            }

            $capturedMediaLayout = $this->capturedMediaLayoutBoundaryBlock($element);
            if ( null !== $capturedMediaLayout ) {
                return $capturedMediaLayout;
            }

            // A gallery can only contain native image blocks. Preserve the
            // complete media collection in the responsive-media companion before
            // author-layout recognition can create an invalid core/gallery child.
            if ( $this->hasResponsiveImageSources($element) && $this->hasGalleryMediaItems($element) ) {
                return $this->responsiveMediaBlock($element);
            }

            if ( $this->isDirectChildOfAuthorOwnedLayout($element) && '' !== $this->attr($element, 'role') ) {
                return $this->authorLayoutBlockFromElement($element, $fallbacks);
            }

            if ( in_array($tagName, array( 'div', 'section', 'article' ), true) && ! $this->hasResponsiveImageSources($element) ) {
                // A strict two-pane media/text candidate is a more specific
                // recognition than generic author-owned layout preservation:
                // media-text candidates are by definition authored flex/grid
                // containers, so they must be recognized before the layout is
                // demoted to a css-owned core/group.
                $mediaText = $this->recognizePatterns($element, $fallbacks, array(MediaTextPattern::class));
                if ( null !== $mediaText ) {
                    return $mediaText;
                }
            }

            // Keep safe phrasing runs together before generic flex/grid preservation can split
            // selector-addressed inline targets into block-level children. The recognizer rejects
            // children with independent layout geometry, so structural inline items still fall
            // through to the author-owned layout path below.
            if ( $this->hasMultipleRuntimeInlineTextTargets($element) ) {
                $inlineContent = $this->paragraphBlockFromInlineContentWrapper($element);
                if ( null !== $inlineContent ) {
                    return $inlineContent;
                }
            }

            if ( 'button' !== strtolower($this->attr($element, 'role'))
                && ! $this->hasClass($element, 'wp-block-columns')
                && $this->isAuthorOwnedLayout($element)
            ) {
                $proofBacked = $this->proofBackedWrapperCoalescing($element, $fallbacks);
                if (null !== $proofBacked) return $proofBacked;
                return $this->authorLayoutBlockFromElement($element, $fallbacks);
            }

            // A direct child of an author-owned layout is itself a layout item.
            // Keep its semantic container instead of allowing a core Group to
            // contribute flow layout defaults to the author-owned parent.
            if ( $this->isDirectChildOfAuthorOwnedLayout($element) && in_array($tagName, array( 'div', 'section', 'article', 'aside', 'header', 'footer', 'main' ), true) ) {
                if ( 0 === $this->childElementCount($element) && '' === trim($element->textContent) && $this->shouldPreserveEmptyVisualElement($element) ) {
                    return $this->createBlock('core/group', $this->emptyVisualElementAttributes($element), array(), $element);
                }
                return $this->authorLayoutBlockFromElement($element, $fallbacks);
            }

            $logo = $this->recognizePatterns($element, $fallbacks, array('logo'));
            if ( null !== $logo ) {
                return $logo;
            }

            $spacer = $this->recognizePatterns($element, $fallbacks, array('spacer'));
            if ( null !== $spacer ) {
                return $spacer;
            }

            $navigationSection = $this->navigationSectionBlockFromElement($element);
            if ( null !== $navigationSection ) {
                return $navigationSection;
            }

            if ( ! $this->shouldDeferNavigationPatternToChildren($element) ) {
                $navigation = $this->recognizePatterns($element, $fallbacks, array(AccordionPattern::class, SocialLinksPattern::class, NavigationPattern::class));
                if ( null !== $navigation ) {
                    return $this->rememberAccordionDisclosureRoot($navigation, $element);
                }
            }

            if ( in_array($tagName, array( 'div', 'section', 'article' ), true) ) {
                $metadataGrid = $this->metadataGridBlockFromElement($element);
                if ( null !== $metadataGrid ) {
                    return $metadataGrid;
                }

                $disclosure = $this->recognizePatterns($element, $fallbacks, array('disclosure'));
                if ( null !== $disclosure ) {
                    $this->nativeDisclosureRootIds[ $element->getNodePath() ?? '' ] = true;

                    return $disclosure;
                }

                $cover = $this->recognizePatterns($element, $fallbacks, array(CoverPattern::class));
                if ( null !== $cover ) {
                    return $cover;
                }

                // core/media-text is dispatched earlier in this method, before
                // author-owned layout preservation — its candidates are by
                // definition authored flex/grid containers.
            }

            $columns = $this->recognizePatterns($element, $fallbacks, array(ColumnsPattern::class));
            if ( null !== $columns ) {
                return $columns;
            }

            $gallery = $this->mediaGalleryBlockFromElement($element, $fallbacks);
            if ( null !== $gallery ) {
                return $gallery;
            }

            $codeWindow = $this->recognizePatterns($element, $fallbacks, array('code-window'));
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

            $buttons = $this->recognizePatterns($element, $fallbacks, array('buttons-container'));
            if ( null !== $buttons ) {
                return $buttons;
            }

            // A select's option text is not prose. Route it before generic text
            // flow can flatten the control into a paragraph.
            if ( 'select' === $tagName ) {
                $selectBlock = $this->readableFormControlBlockFromElement($element);
                if ( null !== $selectBlock ) {
                    return $selectBlock;
                }
            }

            $textFlow = $this->textFlowBlockFromElement($element);
            if ( null !== $textFlow ) {
                return $textFlow;
            }

            $children = $this->convertChildren($element, $fallbacks, true);
            if ( array() === $children && ! $this->hasDirectMediaChild($element) ) {
                $backgroundImage = $this->backgroundImageBlockFromElement($element);
                if ( null !== $backgroundImage ) {
                    $children[] = $backgroundImage;
                }
            }
            if ( 1 === count($children) ) {
                $coalesced = $this->coalescedSingleGroupWrapper($element, $children[0]);
                if ( null !== $coalesced ) {
                    return $coalesced;
                }
                if ( $this->shouldPreserveWrapper($element) || $this->isDirectChildOfAuthorOwnedLayout($element) ) {
                    return $this->createBlock('core/group', $this->presentationAttributes($element), $children, $element);
                }
                return $children[0];
            }
            if ( array() !== $children ) {
                return $this->createBlock('core/group', $this->presentationAttributes($element), $children, $element);
            }
            if ( $this->shouldPreserveEmptyVisualElement($element) ) {
                return $this->emptyVisualSpacerBlock($element);
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

        $transparentCustomElement = $this->transparentCustomElementBlock($element, $fallbacks);
        if ( null !== $transparentCustomElement ) {
            return $transparentCustomElement;
        }

        if ( $captureUnsupported ) {
            // Producer link (issue #497): this is a core/html fallback decision —
            // the element mapped to nothing native/Automattic. If the structural
            // classifier identifies it as a high-confidence custom_block, generate
            // a static-render block and emit a self-closing reference instead of raw
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
                $converted[] = $this->createBlock('core/group', array_merge($this->presentationAttributes($child), array( 'tagName' => 'li' )), $childBlocks, $child);
            } else {
                array_push($converted, ...$childBlocks);
            }
        }
        if ( array() !== $childFallbacks ) {
            return null;
        }

        if ( $isList ) {
            return $this->createBlock('core/group', array_merge($this->presentationAttributes($element), array( 'tagName' => 'ul' )), $converted, $element);
        }

        if ( 1 === count($converted) && array() === $this->presentationAttributes($element) ) {
            return $converted[0];
        }

        return $this->createBlock('core/group', $this->presentationAttributes($element), $converted, $element);
    }

    private function isSafeTransparentCustomElement(DOMElement $element): bool
    {
        if ( $this->isRuntimeDomTarget($element) || array() !== $this->eventMetadata($element) || $this->hasMotionStructureToken($element) ) {
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
        $placeholderMedia = $this->recognizePatterns($element, $fallbacks, array('placeholder-media'));
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

        return $this->recognizePatterns($element, $fallbacks, array('gallery'));
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
                $declarations = $this->structuralPresentationDeclarations($layoutElement);
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
        $blockName = $this->generatedBlockNamespace . '/' . CapturedDialogBlockGenerator::LOCAL_NAME;
        if (! $this->capturedDialogBlockGenerated) {
            $this->generatedBlocks[] = (new CapturedDialogBlockGenerator())->definition($blockName);
            $this->capturedDialogBlockGenerated = true;
        }

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
                return $this->createBlock('core/group', $this->presentationAttributes($sourceElement), $children, $sourceElement, $logicalSourceElement);
            }
        }

        $preserveInlineLayoutLeaf = ! empty($attrs['preserveInlineLayoutLeaf']);
        unset($attrs['preserveInlineLayoutLeaf']);
        if ( ! $preserveInlineLayoutLeaf ) {
            $attrs = $this->hoistContentWrappingSpans($name, $attrs);
        }
        if ( $sourceElement instanceof DOMElement && in_array($name, array( 'core/paragraph', 'core/heading' ), true) ) {
            $textAlign = strtolower(trim((string) ($this->presentationDeclarations($sourceElement)['text-align'] ?? '')));
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
            if ( 'core/group' === $name && 'grid' === (string) ($attrs['layout']['type'] ?? '') ) {
                // Core Group's save() does not reproduce a blockGap declaration.
                // Preserve an authored inline gap in a generated carrier instead
                // of storing markup that the editor will mark invalid.
                $gapCarrier = $this->inlineGeometryClassName($sourceElement, array(), array( 'gap' ));
                if ( '' !== $gapCarrier ) {
                    $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), $gapCarrier);
                }
            }
            if ( 'core/table' === $name && isset($this->sourceTableMarkers[$this->sourceElementIdentity($sourceElement)]) ) {
                $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), $this->sourceTableMarkers[$this->sourceElementIdentity($sourceElement)]);
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
            if ( in_array($name, array( 'core/button', 'core/buttons' ), true) && in_array(strtolower($logicalControl->tagName), array( 'a', 'button' ), true) && ( isset($this->sourceControlPaths[$logicalControlPath]) || ( '' !== $this->combinedAuthorCss && 'a' === strtolower($logicalControl->tagName) && ( '' !== trim($this->attr($logicalControl, 'class')) || '' !== trim($this->attr($logicalControl, 'id')) ) ) ) ) {
                if ( '' !== $logicalControlPath && ! isset($this->sourceControlMarkers[$logicalControlPath]) ) {
                    $this->sourceControlMarkers[$logicalControlPath] = $this->allocateAuthorMarker('control');
                }
                if ( isset($this->sourceControlMarkers[$logicalControlPath]) ) {
                    $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), $this->sourceControlMarkers[$logicalControlPath]);
                    if ( 'core/button' === $name ) {
                        $this->registerNativeButtonStyleRule($this->sourceControlMarkers[$logicalControlPath], $attrs, $nativeButtonTextAlignment, $logicalControl);
                        if ( $this->isDirectChildOfAuthorFlexLayout($logicalControl) ) {
                            $this->directFlexButtonStyleRules[$this->sourceControlMarkers[$logicalControlPath]] = $this->directFlexButtonStyleRule($this->sourceControlMarkers[$logicalControlPath], $logicalControl);
                        }
                        if ( 100 === (int) ($attrs['width'] ?? 0) ) {
                            $this->fullWidthButtonStyleRules[$this->sourceControlMarkers[$logicalControlPath]] = $this->fullWidthButtonStyleRule($this->sourceControlMarkers[$logicalControlPath]);
                        }
                    }
                }
                $presentationPath = $sourceElement->getNodePath() ?? '';
                if ( '' !== $presentationPath && $presentationPath !== $logicalControlPath ) {
                    $this->sourceControlMarkers[$presentationPath] = $this->sourceControlMarkers[$logicalControlPath];
                    $this->sourceButtonPresentationMarkers[$presentationPath] = $this->sourceControlMarkers[$logicalControlPath];
                }
            }
            if ( 'core/button' === $name && $hasNativeButtonStyle && ! isset($this->sourceControlMarkers[$logicalControlPath]) ) {
                $nativeButtonMarker = $hasNativeButtonColor
                    ? $this->allocateAuthorMarker('native-button')
                    : 'blocks-engine-native-button-alignment-' . $nativeButtonTextAlignment;
                $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), $nativeButtonMarker);
                $this->registerNativeButtonStyleRule($nativeButtonMarker, $hasNativeButtonColor ? $attrs : array(), $nativeButtonTextAlignment);
            }
            $attrs = $this->applyDeclaredBlockSupport($name, $attrs, $sourceElement);
            $provenanceId = $this->nextSourceProvenanceId++;
            $this->recordPresentationProvenance($name, $attrs, $sourceElement);
            $this->recordStructureProvenance($name, $attrs, $sourceElement);
            if ( $this->isRuntimeDomTarget($sourceElement) && ! $this->isFormControlElement($sourceElement) && ! in_array($sourceTagName, array( 'canvas', 'form', 'script' ), true) ) {
                $runtimeOwned = true;
                if ( ! $this->canRetainRuntimeDomContractNatively($sourceElement, $name) ) {
                    $this->recordRuntimeIsland($sourceElement, 'dom', 'runtime_dom_target', 'client_script_execution', array(
                        'events'          => $this->eventMetadata($sourceElement),
                        'required_scripts' => $this->requiredScriptsForElement($sourceElement),
                    ));
                    $this->recordRuntimeDomFallback($sourceElement, $name);
                } else {
                    $this->recordNativeRuntimeDomPreservation($sourceElement, $name, in_array($name, array('core/paragraph', 'core/heading'), true));
                }
            }
            $this->sourceProvenance[$provenanceId] = $this->sourceProvenanceEntry($name, $sourceElement);
            $this->sourceBaseHiddenStates[$provenanceId] = $this->sourceElementStartsHidden($sourceElement);
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
                $this->sourceProvenance[$provenanceId]['visual_topology_evidence'] = $visualTopologyEvidence;
            }
        }

        return $block;
    }

    /**
     * A linked text logo becomes a paragraph for valid block markup. Carry the
     * source anchor's exact header winners onto the saved inner anchor instead
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

        $direct = $this->cssDeclarations($this->specificityResolvedPresentationStyle($anchor));
        $declarations = array();
        if ( 'inherit' === strtolower(trim((string) ($direct['color'] ?? ''))) ) {
            $inheritedColor = $this->authoredInheritedPropertyWinner($anchor, 'color');
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
        foreach ( $this->specificityResolvedGapDeclarations($anchor) as $property => $value ) {
            if ( ! str_contains(strtolower($value), '!important') ) {
                $declarations[$property] = $this->resolveCssVariablesInValue($value);
            }
        }
        foreach ( array( 'font-family', 'font-size', 'font-style', 'letter-spacing', 'line-height', 'text-transform', 'white-space' ) as $property ) {
            $value = $this->authoredInheritedPropertyWinner($anchor, $property);
            if ( '' !== $value && ! str_contains(strtolower($value), '!important') ) {
                $declarations[$property] = $value;
            }
        }

        if ( array() === $declarations ) {
            return;
        }

        $css = $this->cssDeclarationString($declarations);
        $className = self::SYNTHETIC_HEADER_ANCHOR_CLASS_PREFIX . substr(hash('sha256', $css), 0, 16);
        $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), $className);
        $this->syntheticHeaderAnchorStyleRules[$className] = 'p.' . $className . '>a{' . $css . '}';
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
        if ( '' !== $submenuBackground && array() !== $this->cssDeclarations('background-color:' . $submenuBackground) ) {
            $className = 'blocks-engine-navigation-submenu-background-' . hash('sha256', $submenuBackground);
            $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), $className);
            $this->navigationSubmenuBackgroundFallbacks[ $className ] = $submenuBackground;
        }
        $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array();
        if ( 'core/navigation' === $name && is_array($fallback['spacing']['padding'] ?? null) ) {
            foreach ( $classes as $class ) {
                if ( 'blocks-engine-list-navigation' !== $class && ! str_starts_with($class, 'blocks-engine-') ) {
                    $this->listNavigationPaddingFallbacks[ $class ] = $fallback['spacing']['padding'];
                }
            }
        }
        if ( 'core/navigation' === $name && is_array($fallback['spacing'] ?? null) ) {
            $declarations = $this->styleAttributeMapper()->serialize(array( 'spacing' => $fallback['spacing'] ))['style'];
            foreach ( $classes as $class ) {
                if ( '' !== $declarations && 'blocks-engine-list-navigation' !== $class && ! str_starts_with($class, 'blocks-engine-') ) {
                    $this->navigationSpacingFallbacks[ $class ] = $declarations;
                    break;
                }
            }
        }
        if ( 'core/buttons' === $name && is_array($fallback['spacing'] ?? null) ) {
            $declarations = $this->styleAttributeMapper()->serialize(array( 'spacing' => $fallback['spacing'] ))['style'];
            foreach ( $classes as $class ) {
                if ( '' !== $declarations && str_starts_with($class, 'blocks-engine-control-') ) {
                    $this->buttonWrapperSpacingFallbacks[ $class ] = $declarations;
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
                    $this->navigationLinkColorFallbacks[ $class ] = (string) $fallback['color']['text'];
                }
            }
        }
        if ( array() === $fallback ) {
            return $attrs;
        }

        $fallbackStyle = $this->styleAttributeMapper()->serialize($fallback)['style'];
        $fallbackDeclarations = $this->cssDeclarations($fallbackStyle);
        $inlineDeclarations = $this->cssDeclarations($this->attr($sourceElement, 'style'));
        $inlineMapped = $this->styleAttributeMapper()->map($inlineDeclarations);
        $inlineFallbackDeclarations = $this->cssDeclarations($this->styleAttributeMapper()->serialize($inlineMapped['style'] ?? array())['style']);
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
            foreach ( $this->inlineGeometryProperties() as $property ) {
                unset($fallbackDeclarations[ $property ]);
            }
            unset($fallbackDeclarations['box-shadow']);
        }
        if ( array() === $fallbackDeclarations ) {
            return $attrs;
        }
        $carrier = $this->inlineGeometryClassName(
            $sourceElement,
            array_diff($this->inlineGeometryProperties(), array_keys($fallbackDeclarations)),
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
        $anchorDeclarations = $this->presentationDeclarations($anchor);
        $anchorColorInherits = ! isset($anchorDeclarations['color']) || $this->isInheritedCssWideValue((string) $anchorDeclarations['color']);
        $anchorTextAlignmentInherits = ! isset($anchorDeclarations['text-align']) || $this->isInheritedCssWideValue((string) $anchorDeclarations['text-align']);
        $inheritedColor = '';
        $inheritedTextAlignment = '';

        for ( $ancestor = $anchor->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode ) {
            $declarations = $this->presentationDeclarations($ancestor);
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
            $mappedColor = $this->styleAttributeMapper()->map(array( 'color' => $inheritedColor ))['style']['color']['text'] ?? '';
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
            $sourceDeclarations = $this->cssDeclarations($this->specificityResolvedPresentationStyle($sourceControl));
            $sourceStructuralDeclarations = $this->structuralPresentationDeclarations($sourceControl);
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
                if ( '' === trim((string) ($style['border']['radius'] ?? '')) ) {
                    $declarations[] = 'border-radius:0!important';
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

        $wrapperRule = array() === $wrapperDeclarations
            ? ''
            : '.' . $marker . '.' . $marker . '.wp-block-button{' . implode(';', $wrapperDeclarations) . '}';
        $this->nativeButtonStyleRules[$marker] = $wrapperRule . '.' . $marker . '.' . $marker . '>.wp-block-button__link{' . implode(';', $declarations) . '}';
    }

    private function sourceElementStartsHidden(DOMElement $element): bool
    {
        $declarations = $this->structuralPresentationDeclarations($element);
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
        if ( isset($this->sourceTagMarkers[$sourceTagName]) ) {
            $className = $this->mergeClassNames($className, $this->sourceTagMarkers[$sourceTagName]);
        }
        if ( $element->parentNode instanceof DOMElement
            && 'body' === strtolower($element->parentNode->tagName)
            && array() !== $this->sourceBodyProjectionClasses ) {
            $className = $this->mergeClassNames($className, ...$this->sourceBodyProjectionClasses);
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
        foreach ( $this->cssDeclarations($this->mergedPresentationStyle($anchor)) as $property => $value ) {
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
        $markers = array();
        $path = $this->sourceElementIdentity($element);
        if ( isset($this->sourceSemanticMarkers[$path]) ) {
            $markers[] = $this->sourceSemanticMarkers[$path];
        }
        if ( isset($this->sourceAttributeMarkers[$path]) ) {
            $markers[] = $this->sourceAttributeMarkers[$path];
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

        if ( $this->ownsPositioningGeometry($element) ) {
            return true;
        }

        $parent = $element->parentNode instanceof DOMElement ? $element->parentNode : null;
        if ( ! $parent instanceof DOMElement || ! $this->isStructuralLayoutElement($parent) ) {
            return false;
        }

        $declarations = $this->structuralPresentationDeclarations($element);
        // A grid placement belongs to this inline node. Keep phrasing-only grid
        // siblings in one RichText container rather than replacing their direct
        // grid items with Group/Paragraph wrappers.
        if ( 'grid' === strtolower(trim((string) ($this->presentationDeclarations($parent)['display'] ?? ''))) && ( '' !== trim((string) ($declarations['grid-column'] ?? '')) || '' !== trim((string) ($declarations['grid-row'] ?? '')) ) ) {
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

        $declarations = $this->structuralPresentationDeclarations($element);
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
        if ( '' === $this->combinedAuthorCss ) {
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
                    && ($this->session->sourceStyleResolutionState->selectorMatchCache ??= new CssSelectorMatchCache())->matches($child, $selector['selector'], $parsed, true)['matches'] ) {
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
        return $this->presentationAttributes($element, array(), array(
            'position', 'z-index', 'inset', 'inset-block', 'inset-inline',
            'inset-block-start', 'inset-block-end', 'inset-inline-start',
            'inset-inline-end', 'top', 'right', 'bottom', 'left',
        ));
    }

    private function isStructuralLayoutElement(DOMElement $element): bool
    {
        $declarations = array_merge($this->presentationDeclarations($element), $this->authorSemanticDeclarations($element));
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
        $declarations = $this->structuralPresentationDeclarations($element);
        $display = strtolower(trim((string) ($declarations['display'] ?? '')));
        if ( in_array($display, array( 'flex', 'inline-flex', 'grid', 'inline-grid' ), true) ) {
            return true;
        }

        foreach ( $this->styleRuleCandidates($element, 'conditional') as $rule ) {
            if ( $this->matchesCssSelector($element, $rule['selector']) && in_array(strtolower(trim((string) ($rule['declarations']['display'] ?? ''))), array( 'flex', 'inline-flex', 'grid', 'inline-grid' ), true) ) {
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
        $parentStyle = $parent instanceof DOMElement ? $this->structuralPresentationDeclarations($parent) : array();
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

    private function fullWidthButtonStyleRule(string $marker): string
    {
        $wrapper = ':where(.' . $marker . '.wp-block-buttons)';
        $button = ':where(.' . $marker . '.wp-block-buttons)>:where(.' . $marker . '.wp-block-button)';
        $link = $button . '>:where(.wp-block-button__link)';

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
            && isset($this->sourceControlPaths[$element->parentNode->getNodePath() ?? '']);
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

        $declarations = $this->structuralPresentationDeclarations($element);
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

    /**
     * @return array<int, array{selector: string, source_child_count: int, block_child_count: int, source_tags: list<string>, block_tags: list<string>}>
     */
    private function authorLayoutTopologyFindings(): array
    {
        $findings = array();
        foreach ( $this->authorLayoutTopologies as $layout ) {
            // Text-only leaves have no element-child topology to compare. Their
            // text may become a paragraph, but that is not a container loss.
            if ( 0 === $layout['direct_child_count'] ) {
                continue;
            }
            if ( $layout['direct_child_count'] === $layout['block_child_count'] && $layout['source_tags'] === $layout['block_tags'] ) {
                continue;
            }
            $findings[] = array(
                'selector' => $layout['selector'],
                'source_child_count' => $layout['direct_child_count'],
                'block_child_count' => $layout['block_child_count'],
                'source_tags' => $layout['source_tags'],
                'block_tags' => $layout['block_tags'],
            );
        }

        return array_slice($findings, 0, 20);
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    private function authorLayoutBlockFromElement(DOMElement $element, array &$fallbacks): array
    {
        $children = $this->convertChildren($element, $fallbacks, true);
        if ( $this->isAuthorOwnedLayout($element) ) {
            $this->authorLayoutTopologies[] = array(
                'selector' => $this->elementSelector($element),
                'direct_child_count' => $this->childElementCount($element),
                'block_child_count' => count($children),
                'source_tags' => $this->directChildTags($element),
                'block_tags' => $this->directBlockTags($children),
            );
        }
        return $this->createBlock('core/group', $this->cssOwnedGroupAttributes($element), $children, $element);
    }

    /** @return array<string, mixed> */
    private function cssOwnedGroupAttributes(DOMElement $element): array
    {
        $attrs = $this->presentationAttributes($element);
        $layout = $attrs['layout'] ?? null;
        if ( is_array($layout) && 'grid' === (string) ($layout['type'] ?? '') && '' !== (string) ($layout['minimumColumnWidth'] ?? '') ) {
            // The source track list is exactly expressible as native grid
            // layout, so WordPress owns the track geometry. Group save markup
            // does not serialize blockGap, so source gap remains stylesheet
            // owned by the normalization in createBlock().
            $declarations = $this->structuralPresentationDeclarations($element);
            $style = is_array($attrs['style'] ?? null) ? $attrs['style'] : array();
            unset($style['spacing']['blockGap']);
            if ( empty($style['spacing']) ) {
                unset($style['spacing']);
            }
            $background = trim((string) ($declarations['background-color'] ?? $declarations['background'] ?? ''));
            if ( 1 === preg_match('/^(#[0-9a-f]{3,8}|[a-z][a-z-]*|(?:rgb|rgba|hsl|hsla|hwb|lab|lch|oklab|oklch|var)\([^()]*\))$/i', $background)
                && ! in_array(strtolower($background), array( 'none', 'inherit', 'initial', 'unset', 'revert', 'revert-layer' ), true)
                && ! isset($style['color']['background'])
                && ! $this->hasConditionalStyleFamily($element, 'background')
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
            (string) ($this->structuralPresentationDeclarations($element)['display'] ?? '')
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
        $inlineDeclarations = $this->cssDeclarations($this->attr($element, 'style'));
        // Deliberately the CONFLICT-only predicate, not the wider carrier one.
        // This branch chooses a priority TIER: taking it drops the layout carrier
        // to the non-important tier, which is only sound when the inline display
        // is overriding a different author display. An inline display that merely
        // differs from the tag default has no such guarantee, and demoting it
        // lets any author selector above (0,2,0) win.
        if ( $this->inlineDisplayConflictsWithAuthorLayout($element, $inlineDeclarations) ) {
            return $this->presentationAttributes($element);
        }

        // Carry only the inline-present properties so the fallback to
        // mapper-synthesized declarations cannot invent a `gap` that
        // overrides explicit row-gap/column-gap values.
        $carriedProperties = array_values(array_intersect(self::CSS_OWNED_FLEX_CARRIER_PROPERTIES, array_keys($inlineDeclarations)));

        return $this->presentationAttributes($element, array(), $carriedProperties);
    }

    private function isCssOwnedGridElement(DOMElement $element): bool
    {
        $display = strtolower(trim((string) preg_replace(
            '/\s*!important\s*$/i',
            '',
            (string) ($this->structuralPresentationDeclarations($element)['display'] ?? '')
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
        $inlineDeclarations = $this->cssDeclarations($this->attr($element, 'style'));
        $carriedProperties = array_values(array_intersect(self::CSS_OWNED_GRID_CARRIER_PROPERTIES, array_keys($inlineDeclarations)));
        $attrs = $this->presentationAttributes($element, array(), $carriedProperties);
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
        $declarations = $this->structuralPresentationDeclarations($element);
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

    private function authorLayoutLeafBlockFromElement(DOMElement $element): ?array
    {
        if ( ! $this->authorLayoutBlockGenerated ) {
            $this->generatedBlocks[] = ( new AuthorLayoutBlockGenerator() )->definition();
            $this->authorLayoutBlockGenerated = true;
        }
        $content = $this->richTextContentWithMaterializedInlineStyles($element);
        $content = $this->richTextContentWithMaterializedSvgImages($element, $content);
        if ( null === $content ) {
            return null;
        }

        $tagName = strtolower($element->tagName);
        $presentationAttrs = $this->presentationAttributes($element);
        $attrs = array_filter(array(
            'anchor' => $this->safeAnchor($this->attr($element, 'id')),
            'className' => $this->sourceProjectionClassName($element, $this->mergePresentationClassNames(
                (string) ($presentationAttrs['className'] ?? $this->promotedClassName($this->attr($element, 'class'))),
                $this->editorAnchorClassName($element)
            )),
            'content' => $content,
            'contentMode' => 'rich-text',
            'sourceAttributes' => array_filter(array_merge(
                $this->authorLayoutSourceAttributes($element),
                array_intersect_key($this->htmlAttributes($element), array_flip(array( 'target', 'rel', 'type' )))
            )),
            'tagName' => $tagName,
            'url' => 'a' === $tagName ? $this->safeLinkUrl($this->attr($element, 'href')) : '',
        ), static fn (mixed $value): bool => array() !== $value && '' !== $value);
        $opening = '<' . $tagName . $this->authorLayoutHtmlAttributes($attrs) . '>';
        $closing = '</' . $tagName . '>';

        $provenanceId = $this->nextSourceProvenanceId++;
        $this->recordPresentationProvenance(AuthorLayoutBlockGenerator::NAME, $attrs, $element);
        $this->recordStructureProvenance(AuthorLayoutBlockGenerator::NAME, $attrs, $element);
        $this->sourceProvenance[$provenanceId] = $this->sourceProvenanceEntry(AuthorLayoutBlockGenerator::NAME, $element);
        $this->sourceBaseHiddenStates[$provenanceId] = $this->sourceElementStartsHidden($element);

        return array(
            'blockName' => AuthorLayoutBlockGenerator::NAME,
            'attrs' => $attrs,
            'innerBlocks' => array(),
            'innerHTML' => $opening . ($attrs['content'] ?? '') . $closing,
            'innerContent' => array($opening . ($attrs['content'] ?? '') . $closing),
            '_source_provenance_id' => $provenanceId,
        );
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
        foreach ( $this->styleRuleCandidates($element, 'static') as $rule ) {
            if ( $this->matchesCssSelector($element, $rule['selector']) ) {
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
            if ( '' !== trim($element->textContent ?? '') && in_array(strtolower($parent->tagName), array( 'a', 'button' ), true) && isset($this->sourceControlPaths[$parent->getNodePath() ?? '']) ) {
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
     * A list item whose content carries block-level children keeps that topology:
     * its inline hooks still become RichText-safe marks, but no wrapper is
     * hoisted onto the list item.
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
            $wrapperDeclarations = $this->cssDeclarations($this->attr($wrapper, 'style'));
            if ( array() !== $this->richTextSafeIdentityAttributes($wrapper) || isset($wrapperDeclarations['--blocks-engine-richtext-marker']) ) {
                break;
            }
            $hoistedClasses = trim($hoistedClasses . ' ' . $this->attr($wrapper, 'class'));
            $wrapperStyle   = trim($this->attr($wrapper, 'style'));
            if ( '' !== $wrapperStyle ) {
                $hoistedDeclarations = array_merge($hoistedDeclarations, $this->cssDeclarations($wrapperStyle));
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

        foreach ( $this->styleRuleCandidates($element, 'static') as $rule ) {
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

            if ( '' === trim($sourceInline->textContent ?? '') && 0 === $this->childElementCount($sourceInline) && ! $this->isRuntimeDomTarget($sourceInline) && ! $this->shouldPreserveEmptyVisualElement($sourceInline) ) {
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
                    $this->headerRichTextStyleRules[$marker] = $selector . '{' . $this->cssDeclarationString($headerCarrier) . '}';
                }
                $inline['--blocks-engine-richtext-marker'] = $marker;
            }
            if ( array() === $inline ) {
                continue;
            }

            $existing = $this->cssDeclarations($this->attr($targetInline, 'style'));
            $targetInline->setAttribute('style', $this->cssDeclarationString(array_merge($inline, $existing)));
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

        $declarations = $this->cssDeclarations($this->specificityResolvedPresentationStyle($element));
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
        $existingDeclarations = $this->cssDeclarations($this->attr($element, 'style'));
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
        $mark->setAttribute('style', $this->cssDeclarationString($declarations));
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
                $resolved[] = array_merge(array( 'block_path' => $blockPath ), $this->sourceProvenance[$provenanceId], !empty($block['_editability_runtime_owned']) ? array('editability_runtime_owned' => true) : array(), !empty($block['_editability_visual_owned']) ? array('editability_visual_owned' => true) : array());
            }
            unset($block['_source_provenance_id']);
            unset($block['_binding_token']);
            unset($block['_editability_runtime_owned']);
            unset($block['_editability_visual_owned']);

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
        $sourceHtml = $this->safeFallbackHtml($element);
        return array_merge(array(
            'block_name'        => $blockName,
            'tag'               => strtolower($element->tagName),
            'selector'          => $this->elementSelector($element),
            'source_attributes' => $this->safeSourceAttributes($element),
            'source_fragment'   => $this->safeSourceFragment($element),
            'source_digest'     => hash('sha256', $sourceHtml),
            'source_bytes'      => strlen($sourceHtml),
            'source_path'       => $this->fallbackProvenance['source'] ?? '',
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
        return ShellLandmarkPolicy::isWrapperPreservingTag($element->tagName) && ( $this->isRuntimeDomTarget($element) || $this->hasAuthorSemanticMarker($element) || array() !== $this->presentationAttributes($element) || array() !== $this->structureSignals($element, array()) );
    }

    /** @param array<string, mixed> $childBlock @return array<string, mixed>|null */
    private function coalescedSingleGroupWrapper(DOMElement $element, array $childBlock): ?array
    {
        $proof = $this->layoutGeometryProofFor($element);
        $fullWidthTransparentShell = $this->hasOnlyFullWidthTransparentInlineGeometry($element);
        if ( 'div' !== strtolower($element->tagName)
            || ! in_array($childBlock['blockName'] ?? null, array('core/group', 'core/image'), true)
            || ($fullWidthTransparentShell && 'core/group' !== ($childBlock['blockName'] ?? null))
            || $this->isRuntimeDomTarget($element)
            || (null === $proof && $this->isDirectChildOfStructuralLayout($element))
            || '' !== trim($this->attr($element, 'id'))
            || '' !== trim($this->attr($element, 'role'))
            || (null === $proof && ! $fullWidthTransparentShell && ! $this->hasOnlyRenderNeutralInlineGeometry($element))
            || array() !== $this->interactiveAttributes($element)
            || (null === $proof && array() !== $this->safeDataAttributes($element))
            || (null === $proof && array() !== $this->structureSignals($element, array()))
            || $this->hasMotionStructureToken($element)
        ) {
            return null;
        }

        $attrs = $this->presentationAttributes($element);
        if ( array_diff(array_keys($attrs), array( 'className', 'style' )) ) {
            return null;
        }

        $provenanceId = $childBlock['_source_provenance_id'] ?? null;
        $sourceChild = is_int($provenanceId) ? $this->sameSourceGroupChainLeaf($element, (string) ($this->sourceProvenance[$provenanceId]['source_digest'] ?? '')) : null;
        if (! $sourceChild instanceof DOMElement && 'core/image' === ($childBlock['blockName'] ?? null)) $sourceChild = $this->imageLeafInGroupChain($element);
        if ( ! $sourceChild instanceof DOMElement
            || ('core/image' === ($childBlock['blockName'] ?? null) && ! in_array(strtolower($sourceChild->tagName), array( 'img', 'svg' ), true) && ! str_contains($sourceChild->tagName, '-'))
            || $this->hasMotionStructureToken($sourceChild)
            || (null === $proof && ($fullWidthTransparentShell ? ! $this->hasOnlyFullWidthTransparentBoxAffectingDeclarations($element) : ! $this->hasOnlyRenderNeutralBoxAffectingDeclarations($element)))
            || (null === $proof && $fullWidthTransparentShell && ! $this->isNormalFlowFullWidthShellChild($sourceChild))
            || ('core/image' !== ($childBlock['blockName'] ?? null) && $this->hasContainingBlockDependentAuthorDeclarations($sourceChild))
            || (null === $proof && ! $this->syntheticImageGeometryLeaf($childBlock) && ! $this->selectorMatchingSurvivesWrapperCoalescing($element, $sourceChild, $fullWidthTransparentShell))
        ) {
            return null;
        }

        $childAttrs = is_array($childBlock['attrs'] ?? null) ? $childBlock['attrs'] : array();
        $childAttrs['className'] = null === $proof
            ? $this->mergeClassNames((string) ($attrs['className'] ?? ''), (string) ($childAttrs['className'] ?? ''), ...$this->classNames($element))
            : $this->mergeClassNames((string) ($childAttrs['className'] ?? ''), $this->layoutGeometryProofCarrier($proof));
        $childAttrs = array_filter($childAttrs, static fn (mixed $value): bool => ! is_string($value) || '' !== trim($value));
        if (null !== $proof) $this->layoutGeometryProofProvenance[] = $proof;

        return $this->createBlock((string) $childBlock['blockName'], $childAttrs, $childBlock['innerBlocks'] ?? array(), $sourceChild);
    }

    /** @return array<string,mixed>|null */
    private function layoutGeometryProofFor(DOMElement $element): ?array
    {
        foreach ($this->layoutGeometryProofReductions as $proof) {
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
        if (null === $proof || $this->isRuntimeDomTarget($element) || '' !== trim($this->attr($element, 'id')) || '' !== trim($this->attr($element, 'role')) || array() !== $this->interactiveAttributes($element) || $this->hasMotionStructureToken($element)) return null;
        $children = $this->convertChildren($element, $fallbacks, true);
        if (1 !== count($children) || !in_array($children[0]['blockName'] ?? null, array('core/group', 'core/image'), true)) return null;
        $sourceChild = $this->soleElementChild($element);
        if (!$sourceChild instanceof DOMElement || $this->elementSelector($sourceChild) !== ($proof['target_selector'] ?? null)) return null;
        $childAttrs = is_array($children[0]['attrs'] ?? null) ? $children[0]['attrs'] : array();
        $childAttrs['className'] = $this->mergeClassNames((string) ($childAttrs['className'] ?? ''), $this->layoutGeometryProofCarrier($proof));
        $childAttrs = array_filter($childAttrs, static fn (mixed $value): bool => !is_string($value) || '' !== trim($value));
        $this->layoutGeometryProofProvenance[] = $proof;
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
        $this->generatedGeometryRules[$className] = ':root .' . $className . '{' . implode(';', $parts) . '}';
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
            || $this->isRuntimeDomTarget($element)
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

        $attrs = $this->presentationAttributes($element);
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

    private function hasBoxAffectingAuthorDeclarations(DOMElement $element): bool
    {
        foreach ( array_keys($this->matchingAuthorDeclarations($element)) as $property ) {
            if ( preg_match('/^(?:align-content|align-items|align-self|background|border|bottom|column|contain|display|filter|flex|float|gap|grid|height|inset|isolation|left|margin|max-|min-|opacity|outline|overflow|padding|perspective|position|right|row-gap|top|transform|width|z-index)/', $property) ) {
                return true;
            }
        }
        return false;
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
                || ! ($this->session->sourceStyleResolutionState->selectorMatchCache ??= new CssSelectorMatchCache())->matches($element, $candidate['selector'], $candidate['parsed'])['matches']
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

    private function hasOnlyRenderNeutralInlineGeometry(DOMElement $element): bool
    {
        foreach ($this->cssDeclarations($this->attr($element, 'style')) as $property => $value) {
            if (! $this->isRenderNeutralGeometryDeclaration($property, $value)) return false;
        }
        return true;
    }

    private function hasOnlyFullWidthTransparentInlineGeometry(DOMElement $element): bool
    {
        $declarations = $this->cssDeclarations($this->attr($element, 'style'));
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
        $declarations = $this->presentationDeclarations($element);
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
        $declarations = $this->presentationDeclarations($element);
        $matchedRules = array();
        foreach ( $this->authorStyleRuleCandidates($element) as $selector ) {
            $ruleOrder = $selector['rule_order'];
            if ( isset($matchedRules[$ruleOrder]) || ! $selector['parsed']['supported'] ) {
                continue;
            }
            if ( ($this->session->sourceStyleResolutionState->selectorMatchCache ??= new CssSelectorMatchCache())->matches($element, $selector['selector'], $selector['parsed'], true)['matches'] ) {
                $matchedRules[$ruleOrder] = true;
                $declarations = $this->mergeCssDeclarationMaps($declarations, $selector['declarations']);
            }
        }
        return $declarations;
    }

    /** @return list<array{key: string, selector: string, parsed: array<string, mixed>, direct_child_parsed: array<string, mixed>, declarations: array<string, string>, rule_order: int}> */
    private function authorStyleRuleCandidates(DOMElement $element): array
    {
        $index = $this->authorStyleRuleCandidateIndexes['rules'] ??= $this->authorStyleRuleCandidateIndex();
        return ($this->session->sourceStyleResolutionState->selectorMatchCache ??= new CssSelectorMatchCache())->styleRuleCandidates($element, 'author-rules', $index);
    }

    /** @return array{universal: list<array<string, mixed>>, ids: array<string, list<array<string, mixed>>>, classes: array<string, list<array<string, mixed>>>, tags: array<string, list<array<string, mixed>>>, attributes: array<string, list<array<string, mixed>>>, total: int} */
    private function authorStyleRuleCandidateIndex(): array
    {
        $index = array('universal' => array(), 'ids' => array(), 'classes' => array(), 'tags' => array(), 'attributes' => array(), 'total' => 0);
        $sequence = 0;
        foreach ( $this->authorStyleRules as $rule ) {
            foreach ( $rule['selectors'] as $selectorIndex => $selector ) {
                $parsed = $selector['parsed'];
                $compounds = $parsed['compounds'] ?? array();
                $rightmost = array() === $compounds ? null : $compounds[array_key_last($compounds)];
                $target = 'universal';
                $key = '';
                if ( $parsed['supported'] && is_array($rightmost) ) {
                    if ( array() !== ($rightmost['ids'] ?? array()) ) {
                        $target = 'ids';
                        $key = (string) $rightmost['ids'][0];
                    } elseif ( array() !== ($rightmost['classes'] ?? array()) ) {
                        $target = 'classes';
                        $key = (string) $rightmost['classes'][0];
                    } elseif ( is_string($rightmost['type'] ?? null) && '' !== $rightmost['type'] ) {
                        $target = 'tags';
                        $key = strtolower((string) $rightmost['type']);
                    } elseif ( array() !== ($rightmost['attributes'] ?? array()) ) {
                        $name = (string) ($rightmost['attributes'][0]['name'] ?? '');
                        if ( 1 === preg_match('/^[a-z][a-z0-9_-]*$/', $name) ) {
                            $target = 'attributes';
                            $key = $name;
                        }
                    }
                }
                $entry = array(
                    'order' => $rule['order'],
                    'sequence' => $sequence++,
                    'key' => $rule['order'] . ':' . $selectorIndex,
                    'rule' => array_merge($selector, array('declarations' => $rule['declarations'], 'rule_order' => $rule['order'], 'key' => $rule['order'] . ':' . $selectorIndex, 'source_path' => $rule['source_path'] ?? '', 'source_hash' => $rule['source_hash'] ?? '')),
                );
                if ( 'universal' === $target ) {
                    $index['universal'][] = $entry;
                } else {
                    $index[$target][$key][] = $entry;
                }
                ++$index['total'];
            }
        }
        return $index;
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
                fn (DOMElement $node): bool => ($this->session->sourceStyleResolutionState->selectorMatchCache ??= new CssSelectorMatchCache())->matches($node, $selector['selector'], $selector['parsed'], true)['matches']
            );
        }

        $childClass = $this->attr($child, 'class');
        $chainClasses = array_map(fn (DOMElement $node): string => $this->attr($node, 'class'), $chain);
        $childParent = $child->parentNode;
        $childNextSibling = $child->nextSibling;
        $parent->insertBefore($child, $element);
        $parent->removeChild($element);
        $child->setAttribute('class', $this->mergeClassNames(...$chainClasses));
        $this->invalidateSourceSelectorMatchCache();

        $survives = true;
        $afterCandidates = $this->authorStyleRuleCandidates($child);
        $candidates = array();
        foreach ( array_merge($beforeCandidates, $afterCandidates) as $selector ) {
            $candidates[$selector['key']] = $selector;
        }
        foreach ( $candidates as $key => $selector ) {
            $matchesAfter = $selector['parsed']['supported']
                && ($this->session->sourceStyleResolutionState->selectorMatchCache ??= new CssSelectorMatchCache())->matches($child, $selector['selector'], $selector['parsed'], true)['matches'];
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
        $this->invalidateSourceSelectorMatchCache();

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
            return $this->isRuntimeDomTarget($element)
                || in_array(strtolower($this->attr($element, 'role')), array( 'presentation', 'none' ), true)
                || 'true' === strtolower($this->attr($element, 'aria-hidden'))
                || $this->isEmptyVisualInlineCandidate($element);
        }

        if ( $this->isRuntimeDomTarget($element)
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
        $declarations = $this->structuralPresentationDeclarations($element);
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
        foreach ( $this->staticPseudoElementStyleRules as $rule ) {
            if ( $this->matchesCssSelector($element, $rule['selector']) ) {
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
            || $this->isRuntimeDomTarget($element)
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
            if ( $descendant instanceof DOMElement && $this->isRuntimeDomTarget($descendant) ) {
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
            || $this->isRuntimeDomTarget($element)
            || $this->hasConditionalStyleFamily($element, 'layout')
            || $this->hasConditionalStyleFamily($element, 'visibility')
            || $this->hasConditionalStyleFamily($element, 'opacity')
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
            && 'none' === strtolower(trim((string) ($this->cssDeclarations($this->attr($element, 'style'))['display'] ?? '')))
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
        $attrs = $this->presentationAttributes($element);
        $parent = $element->parentNode;
        if ( ! $parent instanceof DOMElement ) {
            return $attrs;
        }

        $parentDisplay = strtolower(trim((string) ($this->structuralPresentationDeclarations($parent)['display'] ?? '')));
        if ( ! in_array($parentDisplay, array( 'flex', 'inline-flex' ), true) ) {
            return $attrs;
        }

        $declarations = $this->presentationDeclarations($element);
        $position = strtolower(trim((string) ($declarations['position'] ?? 'static')));
        if ( in_array($position, array( 'absolute', 'fixed' ), true) ) {
            return $attrs;
        }
        foreach ( array( 'width', 'min-width', 'max-width', 'flex', 'flex-basis' ) as $property ) {
            if ( isset($declarations[$property]) && '' !== trim($declarations[$property]) && 'auto' !== strtolower(trim($declarations[$property])) ) {
                return $attrs;
            }
        }
        foreach ( $this->staticPseudoElementStyleRules as $rule ) {
            if ( $this->matchesCssSelector($element, $rule['selector']) && array_intersect_key($rule['declarations'], array_flip(array( 'content', 'display', 'width', 'min-width' ))) ) {
                return $attrs;
            }
        }

        $attrs['className'] = trim((string) ($attrs['className'] ?? '') . ' ' . self::EMPTY_FLEX_ITEM_CLASS);
        if ( $this->isRuntimeDomTarget($element) ) {
            $attrs['className'] = trim($attrs['className'] . ' ' . self::EMPTY_RUNTIME_TARGET_CLASS);
            $this->emptyRuntimeTargetGenerated = true;
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

        $declarations = $this->structuralPresentationDeclarations($element);
        $paint = $this->styleAttributeMapper()->map(array_intersect_key($declarations, array_flip(array(
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

            $declarations = $this->cssDeclarations($this->attr($flank, 'style'));
            if ( array() !== array_diff(array_keys($declarations), array( 'height', 'overflow', 'width' ))
                || 'hidden' !== strtolower(trim((string) ($declarations['overflow'] ?? '')))
                || ! in_array(strtolower(trim((string) ($declarations['width'] ?? ''))), array( '', '100%' ), true)
            ) {
                return null;
            }

            $height = $this->spacerPattern->heightFromStyle($this->attr($flank, 'style'));
            if ( '' === $height || ! $this->isPositiveCssLength($this->resolveCssVariablesInValue($height, $flank)) ) {
                return null;
            }
            $margins[ $side ] = $height;
        }

        $separator = $children[1];
        $attrs = $this->presentationAttributes($separator, array(), array( 'margin-left', 'margin-right' ));
        $attrs['style']['spacing']['margin'] = array_merge($attrs['style']['spacing']['margin'] ?? array(), $margins);

        return $this->createBlock('core/separator', $attrs, array(), $separator);
    }

    private function isEmptyVisualInlineCandidate(DOMElement $element): bool
    {
        if ( '' !== trim($element->textContent ?? '') || 0 !== $this->childElementCount($element) || ! $this->isInlineContentElement(strtolower($element->tagName)) ) {
            return false;
        }

        $declarations = $this->structuralPresentationDeclarations($element);
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
        foreach ( $this->styleRuleCandidates($element, 'static') as $rule ) {
            if ( isset($rule['declarations']['display']) && $this->matchesCssSelector($element, $rule['selector']) ) {
                $display = (string) $rule['declarations']['display'];
            }
        }

        $inline = $this->cssDeclarations($this->attr($element, 'style'));
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

        $textRun = '';
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

        $content = trim($textRun);
        if ( '' === trim($this->runtime->stripAllTags($content)) || $this->richTextRequiresHtmlFallbackWithoutNativeSvgImageObjects($content) ) {
            $this->generatedAssets = $generatedAssets;
            return null;
        }

        return $this->createBlock('core/paragraph', array_merge($this->presentationAttributes($element), array( 'content' => $content )), array(), $element);
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
        if (isset($this->generatedAssets[$source]) && 'inline-svg' === ($this->generatedAssets[$source]['source'] ?? '')) return true;
        foreach ($this->generatedAssets as $asset) if (is_array($asset) && 'inline-svg' === ($asset['source'] ?? '') && $source === ($asset['source_url'] ?? null)) return true;
        return false;
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

        if ( $this->hasEmptyVisualInlineChild($element) ) {
            $fallbacks = array();
            $children = $this->convertChildren($element, $fallbacks, true);
            if ( array() !== $children ) {
                return $this->createBlock('core/group', $this->presentationAttributes($element), $children, $element);
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
                array_merge($this->presentationAttributes($element), array( 'content' => $content )),
                array(),
                $element
            );
        }

        return $this->createBlock(
            'core/group',
            $this->presentationAttributes($element),
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
        $declarations = $this->structuralPresentationDeclarations($element);
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
                return $this->createBlock('core/group', $this->presentationAttributes($element), $children, $element);
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
            return $this->createBlock('core/group', $this->presentationAttributes($element), $structuredInlineItems, $element);
        }

        $content = $this->richTextContentWithMaterializedInlineStyles($element);
        if ( '' === trim($this->runtime->stripAllTags($content)) ) {
            return null;
        }

        $attrs = $this->presentationAttributes($element);
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
            if ( $this->isRuntimeDomTarget($child) ) {
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
        return $this->createBlock('core/group', $this->presentationAttributes($element), array( $paragraph ), $element);
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
                && array() === $this->runtimeScriptMetadata ) {
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
        return $this->blockBinding($block, 'commerce_controls', $this->runtimeDomSelectorsForElement($control));
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
            if ( is_int($provenanceId) && $selector === ($this->sourceProvenance[$provenanceId]['selector'] ?? null) ) {
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
            if ( $this->shouldPreserveEmptyVisualFigure($figure) ) {
                return $this->createBlock('core/group', $this->presentationAttributes($figure), array(), $figure);
            }

            return null;
        }

        if ( 1 === count($children) && array() === $this->presentationAttributes($figure) ) {
            return $children[0];
        }

        return $this->createBlock('core/group', $this->presentationAttributes($figure), $children, $figure);
    }

    private function shouldPreserveEmptyVisualFigure(DOMElement $figure): bool
    {
        if ( '' !== $this->renderedTextContent($figure) || 0 !== $this->childElementCount($figure) ) {
            return false;
        }

        $declarations = $this->structuralPresentationDeclarations($figure);
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

        foreach ( $this->staticPseudoElementStyleRules as $rule ) {
            if ( $this->matchesCssSelector($figure, $rule['selector']) && $this->hasVisibleEmptyVisualPaint($rule['declarations'], $figure) ) {
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
            'hasFixedLayout' => 'fixed' === strtolower(trim((string) ($this->structuralPresentationDeclarations($table)['table-layout'] ?? ''))),
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
        $marker = $this->sourceTableMarkers[$path] ??= $this->allocateAuthorMarker('table');
        $tableDeclarations = $this->structuralPresentationDeclarations($table);
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
            $headDeclarations = $this->structuralPresentationDeclarations($head);
            if ( ! isset($headDeclarations['border']) && ! isset($headDeclarations['border-bottom']) && ! isset($headDeclarations['border-bottom-width']) ) {
                // core/table adds a 3px header separator that did not exist in source.
                $rules[] = '.' . $marker . '>table>thead{border-bottom:0}';
            }
        }

        $this->generatedGeometryRules[$marker] = implode("\n", $rules);
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
                $declarations = $this->cssDeclarations($this->attr($cell, 'style'));
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
        $marker = $this->sourceTableMarkers[$path] ??= $this->allocateAuthorMarker('table');
        $scopedRules = array_map(static fn (string $rule): string => '.' . $marker . '>table>' . $rule, $rules);
        $this->generatedGeometryRules[$marker] = implode("\n", array_filter(array(
            $this->generatedGeometryRules[$marker] ?? '',
            implode("\n", $scopedRules),
        )));
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
                if ( isset($this->sourceTagMarkers[$sourceTagName]) ) {
                    $descendant->setAttribute('class', $this->mergeClassNames($this->attr($descendant, 'class'), $this->sourceTagMarkers[$sourceTagName]));
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
                $items[] = $this->createBlock('core/list-item', array_merge($this->presentationAttributes($child), array(
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

        if ( ! $this->descriptionListBlockGenerated ) {
            $this->generatedBlocks[] = ( new DescriptionListBlockGenerator() )->definition();
            $this->descriptionListBlockGenerated = true;
        }

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

        $attrs = $this->presentationAttributes($element);
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

        $style = $this->cssDeclarations($this->metadataPresentationStyle($element));
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
        return $this->cssDeclarationString($this->structuralPresentationDeclarations($element));
    }

    /** @return array<string, mixed> */
    private function metadataCellAttributes(DOMElement $element, string $content): array
    {
        $attrs = $this->presentationAttributes($element);
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

            $items[] = $this->createBlock('core/list-item', array_merge($this->presentationAttributes($child), array( 'content' => $content )), $nested, $child);
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
            $marker = $this->sourceTagMarkers[strtolower($descendant->tagName)] ?? '';
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
        if ( '' !== $id && isset($this->runtimeDomSelectors['#' . $id]) && ! $this->isPresentationalRuntimeSelector('#' . $id) ) {
            return true;
        }

        foreach ( preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array() as $class ) {
            if ( '' !== $class && isset($this->runtimeDomSelectors['.' . $class]) && ! $this->isPresentationalRuntimeSelector('.' . $class) ) {
                return true;
            }
        }

        foreach ( array_keys($this->runtimeDomSelectors) as $selector ) {
            if ( ! $this->isPresentationalRuntimeSelector((string) $selector) && $this->elementMatchesRuntimeSelector($element, (string) $selector) ) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int,string> */
    private function runtimeDomSelectorsForElement(DOMElement $element): array
    {
        $selectors = array();
        $id = trim($this->attr($element, 'id'));
        if ( '' !== $id && isset($this->runtimeDomSelectors['#' . $id]) ) $selectors[] = '#' . $id;
        foreach ( preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array() as $class ) if ( '' !== $class && isset($this->runtimeDomSelectors['.' . $class]) ) $selectors[] = '.' . $class;
        foreach ( array_keys($this->runtimeDomSelectors) as $selector ) {
            if ( str_starts_with((string) $selector, '.') || str_starts_with((string) $selector, '#') || strtolower((string) $selector) === strtolower($element->tagName) ) continue;
            if ( ! $this->isPresentationalRuntimeSelector((string) $selector) && $this->elementMatchesRuntimeSelector($element, (string) $selector) ) $selectors[] = (string) $selector;
        }
        return array_values(array_unique($selectors));
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

    private function isPresentationalRuntimeSelector(string $selector): bool
    {
        return $this->isPresentationalAnimationSelector($selector) && ! isset($this->runtimeBehavioralSelectors[$selector]);
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

    private function recordNativeRuntimeDomPreservation(DOMElement $element, string $blockName, bool $includeRichTextDescendants = false): void
    {
        $elements = array($element);
        if ($includeRichTextDescendants) {
            foreach ($this->descendantElements($element) as $descendant) {
                if ($this->isInlineContentElement(strtolower($descendant->tagName))) {
                    $elements[] = $descendant;
                }
            }
        }
        foreach ($elements as $target) {
            foreach ($this->runtimeDomSelectorsForElement($target) as $selector) {
                $key = $blockName . "\n" . $selector;
                if (isset($this->runtimeDomPreservations[$key])) {
                    continue;
                }
                $this->runtimeDomPreservations[$key] = array(
                    'block_name' => $blockName,
                    'tag' => strtolower($target->tagName),
                    'selector' => $selector,
                );
            }
        }
    }

    private function recordRuntimeDomFallback(DOMElement $element, string $blockName): void
    {
        foreach ($this->runtimeDomSelectorsForElement($element) as $selector) {
            $key = $blockName . "\n" . $selector;
            if (isset($this->runtimeDomFallbacks[$key])) {
                continue;
            }
            $this->runtimeDomFallbacks[$key] = array(
                'block_name' => $blockName,
                'tag' => strtolower($element->tagName),
                'selector' => $selector,
            );
        }
    }

    private function canRetainRuntimeDomContractNatively(DOMElement $element, string $blockName): bool
    {
        if ( ! in_array($blockName, array('core/group', 'core/paragraph', 'core/heading'), true) ) {
            return false;
        }

        // Group can serialize these semantic wrappers exactly. Generic div app
        // surfaces retain their existing bounded-island treatment.
        if ('core/group' === $blockName && ! in_array(strtolower($element->tagName), array('article', 'aside', 'footer', 'header', 'main', 'section'), true)) {
            return false;
        }

        if (array_intersect($this->runtimeAppShellSignals($element), array('app_root_token', 'workspace_surface'))) {
            return false;
        }

        foreach ($this->descendantElements($element) as $descendant) {
            if (in_array(strtolower($descendant->tagName), array('button', 'input', 'select', 'textarea', 'canvas', 'form', 'template'), true)) {
                return false;
            }
        }

        return true;
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
     * Keep a static JSON script in the page only when a carried runtime script
     * addresses its id. JSON script types never execute, unlike static JavaScript
     * assignments that remain metadata-only.
     */
    private function isAddressableStaticJsonTarget(DOMElement $element): bool
    {
        $id = trim($this->attr($element, 'id'));
        $type = strtolower(trim($this->attr($element, 'type')));
        if ( '' === $id || ! in_array($type, array('application/json', 'application/ld+json'), true) || ! isset($this->runtimeDomSelectors['#' . $id]) ) {
            return false;
        }

        $metadata = end($this->scriptMetadata);
        if ( ! is_array($metadata) || ! empty($metadata['body_truncated']) ) {
            return false;
        }

        return null !== json_decode((string) ($metadata['body'] ?? ''), true);
    }

    private function staticJsonTargetBlock(DOMElement $element): array
    {
        $metadata = end($this->scriptMetadata);
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
     * Preserve only control-bearing wrapper ancestry. The node table is bounded,
     * source ordered, and references the compatibility controls by flat index.
     *
     * @return array<string, mixed>
     */
    private function formControlTopology(DOMElement $form): array
    {
        $controlIndexes = array();
        $relevantElements = array();
        foreach ( $this->formControlElements($form) as $index => $control ) {
            $controlIndexes[$control->getNodePath()] = $index;
            for ( $ancestor = $control->parentNode; $ancestor instanceof DOMElement && $ancestor !== $form; $ancestor = $ancestor->parentNode ) {
                $relevantElements[$ancestor->getNodePath()] = true;
            }
        }

        $nodes = array();
        $wrapperIndex = 0;
        $truncated = false;
        $order = 0;
        foreach ( $form->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) continue;
            if ( $this->appendFormTopologyNode($child, null, $order, 0, $controlIndexes, $relevantElements, $nodes, $wrapperIndex, $truncated) ) ++$order;
        }

        return array(
            'schema'    => 'generic/form-control-topology/v1',
            'max_depth' => self::MAX_FORM_TOPOLOGY_DEPTH,
            'max_nodes' => self::MAX_FORM_TOPOLOGY_NODES,
            'nodes'     => $nodes,
            'truncated' => $truncated,
        );
    }

    /**
     * @param array<string, int> $controlIndexes
     * @param array<string, bool> $relevantElements
     * @param array<int, array<string, mixed>> $nodes
     */
    private function appendFormTopologyNode(DOMElement $element, ?string $parent, int $order, int $depth, array $controlIndexes, array $relevantElements, array &$nodes, int &$wrapperIndex, bool &$truncated): bool
    {
        $nodePath = $element->getNodePath();
        if ( ! isset($controlIndexes[$nodePath]) && ! isset($relevantElements[$nodePath]) ) return false;
        if ( $depth > self::MAX_FORM_TOPOLOGY_DEPTH || count($nodes) >= self::MAX_FORM_TOPOLOGY_NODES ) {
            $truncated = true;
            return false;
        }

        if ( isset($controlIndexes[$nodePath]) ) {
            $controlIndex = $controlIndexes[$nodePath];
            $nodes[] = array_filter(array(
                'id'      => 'control-' . $controlIndex,
                'kind'    => 'control',
                'parent'  => $parent,
                'order'   => $order,
                'depth'   => $depth,
                'control' => $controlIndex,
            ), static fn (mixed $value): bool => null !== $value);
            return true;
        }

        $id = 'wrapper-' . $wrapperIndex++;
        $nodes[] = array_filter(array_merge(array(
            'id'     => $id,
            'kind'   => 'wrapper',
            'parent' => $parent,
            'order'  => $order,
            'depth'  => $depth,
        ), $this->formTopologyPresentation($element)), static fn (mixed $value): bool => null !== $value && '' !== $value);

        $childOrder = 0;
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) continue;
            if ( $this->appendFormTopologyNode($child, $id, $childOrder, $depth + 1, $controlIndexes, $relevantElements, $nodes, $wrapperIndex, $truncated) ) ++$childOrder;
        }

        return true;
    }

    /** @return array<string, string> */
    private function formTopologyPresentation(DOMElement $element): array
    {
        $tag = strtolower($element->tagName);
        $presentation = array();
        if ( in_array($tag, self::FORM_TOPOLOGY_WRAPPER_TAGS, true) ) $presentation['tag'] = $tag;

        $id = trim($this->attr($element, 'id'));
        if ( 1 === preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,79}$/D', $id) ) $presentation['source_id'] = $id;

        $classes = array();
        foreach ( preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array() as $class ) {
            if ( count($classes) >= self::MAX_FORM_TOPOLOGY_CLASSES ) break;
            if ( 1 === preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,79}$/D', $class) ) $classes[] = $class;
        }
        if ( array() !== $classes ) $presentation['class'] = implode(' ', $classes);

        return $presentation;
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

        $label = $this->formControlLabel($textInput);
        $showLabel = '' !== $label;
        if ( '' === $label ) {
            $label = trim($this->attr($form, 'aria-label'));
        }
        if ( '' === $label ) {
            $label = trim($this->attr($textInput, 'placeholder'));
        }

        $attrs = array_merge($this->presentationAttributes($form), array(
            'label'       => '' !== $label ? $label : 'Search',
            'showLabel'   => $showLabel,
            'placeholder' => $this->attr($textInput, 'placeholder'),
        ));
        if ( $submitControl instanceof DOMElement ) {
            $attrs['buttonPosition'] = 'button-outside';
            $attrs['buttonText'] = $this->submitButtonText($submitControl);
            if ( $this->isIconOnlySearchControl($submitControl) ) {
                $attrs['buttonUseIcon'] = true;
            }
        } elseif ( null !== ($searchTrigger = $this->adjacentSearchTrigger($form)) ) {
            $attrs['buttonPosition'] = 'button-only';
            $attrs['buttonUseIcon'] = true;
            $attrs['style']['color']['text'] = '#000000';
            $attrs['style']['color']['background'] = 'transparent';
            $attrs['style']['border']['width'] = '0px';
            $triggerAttrs = $this->presentationAttributes($searchTrigger);
            $attrs['className'] = trim(implode(' ', array_filter(array(
                (string) ($attrs['className'] ?? ''),
                (string) ($triggerAttrs['className'] ?? ''),
                $this->registerNativeSearchTriggerCss($searchTrigger),
            ))));
        } else {
            $attrs['buttonPosition'] = 'no-button';
        }

        return $this->createBlock('core/search', $attrs, array(), $form);
    }

    private function hasAdjacentSearchTrigger(DOMElement $form): bool
    {
        return null !== $this->adjacentSearchTrigger($form);
    }

    private function adjacentSearchTrigger(DOMElement $form): ?DOMElement
    {
        $containers = array( $form );
        if ( $form->parentNode instanceof DOMElement ) {
            $containers[] = $form->parentNode;
        }

        foreach ( $containers as $container ) {
            $sibling = $this->nextElementSibling($container);
            if ( $sibling instanceof DOMElement && $this->isAdjacentSearchTriggerControl($sibling) ) {
                return $sibling;
            }
        }

        return null;
    }

    private function registerNativeSearchTriggerCss(DOMElement $trigger): string
    {
        $svg = $trigger->getElementsByTagName('svg')->item(0);
        if ( ! $svg instanceof DOMElement ) {
            return '';
        }

        $svgDeclarations = $this->presentationDeclarations($svg);
        $width = $this->cssPixelLength((string) ($svgDeclarations['width'] ?? '')) ?? $this->cssPixelLength($this->attr($svg, 'width'));
        $height = $this->cssPixelLength((string) ($svgDeclarations['height'] ?? '')) ?? $this->cssPixelLength($this->attr($svg, 'height'));
        if ( null === $width || null === $height ) {
            $viewBox = preg_split('/[\s,]+/', trim($this->attr($svg, 'viewbox'))) ?: array();
            if ( 4 === count($viewBox) && is_numeric($viewBox[2]) && is_numeric($viewBox[3]) ) {
                $width ??= (float) $viewBox[2];
                $height ??= (float) $viewBox[3];
            }
        }
        if ( null === $width || null === $height || 0 >= $width || 0 >= $height ) {
            return '';
        }

        $svgMarkup = $this->restoreSvgCasing($this->outerHtml($svg));
        if ( ! preg_match('/<svg\b[^>]*\bxmlns=/i', $svgMarkup) ) {
            $svgMarkup = preg_replace('/<svg\b/i', '<svg xmlns="http://www.w3.org/2000/svg"', $svgMarkup, 1) ?? $svgMarkup;
        }
        $className = 'blocks-engine-source-search-icon-' . substr(hash('sha256', $svgMarkup), 0, 12);
        if ( isset($this->nativeSearchTriggerCssRules[$className]) ) {
            return $className;
        }

        $declarations = $this->presentationDeclarations($trigger);
        $triggerHeight = isset($declarations['height']) && '' !== trim($declarations['height'])
            ? 'height:' . trim($declarations['height']) . '!important;'
            : '';
        $triggerWidth = $this->cssPixelLength((string) ($declarations['width'] ?? ''));
        $iconWidth = $this->cssNumber($width);
        $iconHeight = $this->cssNumber($height);
        $buttonWidth = $this->cssNumber($triggerWidth ?? ($width + 12));
        $dataUri = 'data:image/svg+xml,' . rawurlencode($svgMarkup);
        $selector = '.wp-block-search.' . $className;
        $this->nativeSearchTriggerCssRules[$className] = $selector . '{display:block!important;box-sizing:border-box!important;flex:0 0 ' . $buttonWidth . 'px!important;width:' . $buttonWidth . 'px!important;' . $triggerHeight . '}'
            . $selector . ' .wp-block-search__inside-wrapper{' . $triggerHeight . 'box-sizing:border-box!important;width:100%!important}'
            . $selector . ' .wp-block-search__button{display:block!important;box-sizing:border-box!important;width:100%!important;height:100%!important;min-width:0!important;margin:0!important;padding:1px 6px!important;font:400 13.3333px Arial!important;line-height:normal!important;text-align:center!important;color:#000!important;background:none!important;border:0!important;border-radius:0!important}'
            . $selector . '.wp-block-search__icon-button .wp-block-search__button.has-icon>svg.search-icon{display:none!important}'
            . $selector . ' .wp-block-search__button:before{content:"";display:inline-block;width:' . $iconWidth . 'px;height:' . $iconHeight . 'px;background:url("' . $dataUri . '") center/contain no-repeat}';

        return $className;
    }

    private function cssPixelLength(string $value): ?float
    {
        return preg_match('/^([0-9]+(?:\.[0-9]+)?)(?:px)?$/i', trim($value), $match)
            ? (float) $match[1]
            : null;
    }

    private function cssNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function searchBlockFromWrapper(DOMElement $element): ?array
    {
        if ( 1 !== $this->childElementCount($element) ) {
            return null;
        }

        $form = null;
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && 'form' === strtolower($child->tagName) ) {
                $form = $child;
                break;
            }
        }

        if ( ! $form instanceof DOMElement || ! $this->hasAdjacentSearchTrigger($form) ) {
            return null;
        }
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' !== trim($child->textContent ?? '') ) {
                return null;
            }
        }

        return $this->searchBlockFromForm($form);
    }

    private function isReplacedSearchClusterControl(DOMElement $control): bool
    {
        if ( $this->isAdjacentSearchTriggerControl($control) ) {
            $formContainer = $this->previousElementSibling($control);
            return $formContainer instanceof DOMElement && $this->containsNativeSearchForm($formContainer);
        }

        if ( ! $this->isSearchCloseControl($control) ) {
            return false;
        }

        $trigger = $this->previousElementSibling($control);
        $formContainer = $trigger instanceof DOMElement ? $this->previousElementSibling($trigger) : null;
        return $trigger instanceof DOMElement
            && $this->isAdjacentSearchTriggerControl($trigger)
            && $formContainer instanceof DOMElement
            && $this->containsNativeSearchForm($formContainer);
    }

    private function containsNativeSearchForm(DOMElement $element): bool
    {
        $forms = 'form' === strtolower($element->tagName)
            ? array( $element )
            : iterator_to_array($element->getElementsByTagName('form'));
        return 1 === count($forms) && $forms[0] instanceof DOMElement && $this->isNativeSearchForm($forms[0]);
    }

    private function nextElementSibling(DOMElement $element): ?DOMElement
    {
        for ( $sibling = $element->nextSibling; null !== $sibling; $sibling = $sibling->nextSibling ) {
            if ( $sibling instanceof DOMElement ) {
                return $sibling;
            }
        }

        return null;
    }

    private function previousElementSibling(DOMElement $element): ?DOMElement
    {
        for ( $sibling = $element->previousSibling; null !== $sibling; $sibling = $sibling->previousSibling ) {
            if ( $sibling instanceof DOMElement ) {
                return $sibling;
            }
        }

        return null;
    }

    private function isSearchCloseControl(DOMElement $control): bool
    {
        $haystack = strtolower(implode(' ', array(
            $this->attr($control, 'class'),
            $this->attr($control, 'id'),
            $this->attr($control, 'aria-label'),
            $this->attr($control, 'title'),
        )));
        return str_contains($haystack, 'search') && str_contains($haystack, 'close');
    }

    private function isNativeSearchForm(DOMElement $form): bool
    {
        $method = strtolower(trim($this->attr($form, 'method')));
        if ( '' !== $method && 'get' !== $method ) {
            return false;
        }
        if ( 0 < $form->getElementsByTagName('script')->length || array() !== $this->eventMetadata($form) ) {
            return false;
        }

        $textInput = null;
        $submitControl = null;
        foreach ( $this->formControlElements($form) as $control ) {
            if ( array() !== $this->eventMetadata($control) ) {
                return false;
            }
            $tagName = strtolower($control->tagName);
            $type = $this->formControlType($control);
            if ( 'input' === $tagName && in_array($type, array( 'text', 'search' ), true) ) {
                if ( null !== $textInput ) {
                    return false;
                }
                $textInput = $control;
                continue;
            }
            if ( ( 'button' === $tagName || 'input' === $tagName ) && 'submit' === $type ) {
                if ( null !== $submitControl ) {
                    return false;
                }
                $submitControl = $control;
                continue;
            }
            return false;
        }

        return $textInput instanceof DOMElement && $this->hasSearchFormSignal($form, $textInput);
    }

    private function isIconOnlySearchControl(DOMElement $control): bool
    {
        $haystack = strtolower(implode(' ', array(
            $this->attr($control, 'class'),
            $this->attr($control, 'id'),
            $this->attr($control, 'aria-label'),
            $this->attr($control, 'title'),
        )));
        if ( ! str_contains($haystack, 'search') || str_contains($haystack, 'close') ) {
            return false;
        }

        $text = trim(preg_replace('/\s+/', ' ', $control->textContent ?? '') ?? '');
        return '' === $text || 0 < $control->getElementsByTagName('svg')->length;
    }

    private function isAdjacentSearchTriggerControl(DOMElement $control): bool
    {
        if ( ! $this->isIconOnlySearchControl($control) ) {
            return false;
        }

        $identity = strtolower(trim($this->attr($control, 'class') . ' ' . $this->attr($control, 'id')));
        foreach ( preg_split('/\s+/', $identity) ?: array() as $token ) {
            if ( in_array($token, array( 'search-icon', 'search-toggle', 'search-trigger', 'open-search' ), true) ) {
                return true;
            }
        }

        $accessibleName = strtolower(trim($this->attr($control, 'aria-label') . ' ' . $this->attr($control, 'title')));
        return in_array($accessibleName, array( 'search', 'open search', 'expand search', 'toggle search' ), true);
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
     * Preserve one unambiguous controls-only subtree as the provider binding
     * slot while converting the form's surrounding visual content normally.
     *
     * @param array<int,array<string,mixed>> $fallbacks
     * @return array{block:array<string,mixed>,slot:array<string,mixed>}|null
     */
    private function compositionalFormBlock(DOMElement $form, array &$fallbacks): ?array
    {
        $slot = $this->formControlSlotElement($form);
        if ( null === $slot ) return null;

        $path = $slot->getNodePath();
        $token = 'form-control-slot-' . $this->nextSourceProvenanceId;
        $this->formControlSlotPaths[$path] = $token;
        try {
            $children = $this->convertChildren($form, $fallbacks, true);
        } finally {
            unset($this->formControlSlotPaths[$path]);
        }
        $slotBlock = $this->blockForBindingToken($children, $token);
        if ( array() === $children || null === $slotBlock ) return null;

        return array(
            'block' => $this->createBlock('core/group', $this->presentationAttributes($form), $children, $form),
            'slot'  => $slotBlock,
        );
    }

    /** @param array<int,array<string,mixed>> $blocks @return array<string,mixed>|null */
    private function blockForBindingToken(array $blocks, string $token): ?array
    {
        foreach ($blocks as $block) {
            if (!is_array($block)) continue;
            if ($token === ($block['_binding_token'] ?? null)) return $block;
            $nested = $this->blockForBindingToken(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array(), $token);
            if (null !== $nested) return $nested;
        }
        return null;
    }

    private function formControlSlotElement(DOMElement $form): ?DOMElement
    {
        $controls = $this->formControlElements($form);
        if ( array() === $controls ) return null;

        $formPath = $form->getNodePath();
        for ( $candidate = $controls[0]->parentNode; $candidate instanceof DOMElement && $candidate->getNodePath() !== $formPath; $candidate = $candidate->parentNode ) {
            if ( array_filter($controls, fn(DOMElement $control): bool => !$this->elementContains($candidate, $control)) ) continue;
            foreach ( $candidate->childNodes as $child ) {
                if ( XML_TEXT_NODE === $child->nodeType && '' !== trim($child->textContent ?? '') ) continue 2;
                if ( !$child instanceof DOMElement ) continue;
                if ( !array_filter($controls, fn(DOMElement $control): bool => $this->elementContains($child, $control)) ) continue 2;
            }
            return $candidate;
        }
        return null;
    }

    private function elementContains(DOMElement $ancestor, DOMElement $element): bool
    {
        $ancestorPath = $ancestor->getNodePath();
        for ( $node = $element; $node instanceof DOMElement; $node = $node->parentNode ) if ( $node->getNodePath() === $ancestorPath ) return true;
        return false;
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

        if ( 'input' === $tagName ) {
            $inputBlock = $this->readableInputBlockFromElement($element);
            if ( null !== $inputBlock ) {
                return $inputBlock;
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
        return $this->createBlock('core/html', array( 'content' => $this->safeFallbackHtml($element) ), array(), $element);
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
        $options = $this->selectOptions($select);
        if ( array() === $options ) {
            return null;
        }
        // Form controls are below the general high-value style boundary, so use
        // the selector-resolved author cascade directly as the representation
        // gate. Class/id presence alone is never sufficient.
        if ( array() === $this->structuralPresentationDeclarations($select) ) {
            $optionBlocks = array();
            foreach ( $options as $option ) {
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

            return $this->createBlock('core/group', $this->presentationAttributes($select), array(
                $this->createBlock('core/paragraph', array( 'content' => $this->runtime->escapeHtml($label) ), array(), $select),
                $this->createBlock('core/list', array(), $optionBlocks, $select),
            ), $select);
        }
        if ( ! $this->formSelectBlockGenerated ) {
            $this->generatedBlocks[] = ( new AuthoredSelectBlockGenerator() )->definition();
            $this->formSelectBlockGenerated = true;
        }
        $attrs = array_filter(array(
            'id' => $this->attr($select, 'id'),
            'name' => $this->attr($select, 'name'),
            'ariaLabel' => $this->attr($select, 'aria-label'),
            'placeholder' => $this->attr($select, 'placeholder'),
            'className' => $this->attr($select, 'class'),
            'style' => $this->attr($select, 'style'),
            'options' => $options,
            'selectedSummary' => $this->selectedOptionSummary($options),
        ), static fn (mixed $value): bool => is_array($value) ? array() !== $value : '' !== $value);
        $markup = ( new AuthoredSelectBlockGenerator() )->markup($attrs);
        $controlBlock = array(
            'blockName' => AuthoredSelectBlockGenerator::NAME,
            'attrs' => $attrs,
            'innerBlocks' => array(),
            'innerHTML' => $markup,
            'innerContent' => array( $markup ),
        );

        // Keep the long-standing group/anchor contract for callers that address
        // the converted field structurally. Source identity lives on the native
        // control, so authored select selectors never style this transparent shell.
        return $this->createBlock('core/group', array_filter(array(
            'anchor' => $this->safeAnchor($this->attr($select, 'id')),
            'className' => 'blocks-engine-authored-select-wrapper',
        )), array( $controlBlock ));
    }

    /**
     * Return a compact native input only when authored presentation is proven by
     * the resolved CSS cascade. The direct save shape preserves flex-child and
     * selector semantics that a readable paragraph cannot represent.
     *
     * @return array<string, mixed>|null
     */
    private function readableInputBlockFromElement(DOMElement $input): ?array
    {
        if ( array() === $this->structuralPresentationDeclarations($input) ) {
            return null;
        }
        if ( ! $this->formInputBlockGenerated ) {
            $this->generatedBlocks[] = ( new AuthoredInputBlockGenerator() )->definition();
            $this->formInputBlockGenerated = true;
        }
        $attrs = array_filter(array(
            'type' => $this->formControlType($input),
            'id' => $this->attr($input, 'id'),
            'name' => $this->attr($input, 'name'),
            'value' => $this->attr($input, 'value'),
            'placeholder' => $this->attr($input, 'placeholder'),
            'ariaLabel' => $this->attr($input, 'aria-label'),
            'className' => $this->attr($input, 'class'),
            'style' => $this->attr($input, 'style'),
            'min' => $this->attr($input, 'min'),
            'max' => $this->attr($input, 'max'),
            'step' => $this->attr($input, 'step'),
            'required' => $input->hasAttribute('required'),
            'disabled' => $input->hasAttribute('disabled'),
            'readOnly' => $input->hasAttribute('readonly'),
            'checked' => $input->hasAttribute('checked'),
        ), static fn (mixed $value): bool => is_bool($value) ? $value : '' !== $value);
        $markup = ( new AuthoredInputBlockGenerator() )->markup($attrs);

        return array(
            'blockName' => AuthoredInputBlockGenerator::NAME,
            'attrs' => $attrs,
            'innerBlocks' => array(),
            'innerHTML' => $markup,
            'innerContent' => array( $markup ),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $options
     */
    private function selectedOptionSummary(array $options): string
    {
        $selected = array();
        foreach ( $options as $option ) {
            if ( ! empty($option['selected']) && '' !== trim((string) ($option['label'] ?? '')) ) {
                $selected[] = (string) $option['label'];
            }
        }

        return array() === $selected ? '' : implode(', ', $selected) . ' (selected)';
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
    private function formFallbackFinding(DOMElement $element, ?array $readableFormBlock, ?array $bindingBlock = null): array
    {
        $controls = $this->formControls($element);
        $controlTopology = $this->formControlTopology($element);
        $layoutGraph = (new FormLayoutGraphBuilder())->build($element, $this->authorStylesheetAssets, $this->formLayoutCss);
        $boundedHtml = $this->boundedFallbackHtml($this->safeFallbackHtml($element));
        $replacesRuntimeIsland = null !== $bindingBlock;
        $bindingBlock ??= $readableFormBlock;
        $supersededRuntimeSelectors = $this->runtimeDomSelectorsForElement($element);
        if ( $replacesRuntimeIsland ) $supersededRuntimeSelectors[] = $this->runtimeIslandSelector($element);

        $finding = array(
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
            'binding'         => null !== $bindingBlock ? $this->blockBinding($bindingBlock, 'form', $supersededRuntimeSelectors) : array(),
            'controls'        => $controls,
            'control_topology' => $controlTopology,
            'layout_graph'     => $layoutGraph,
            'control_count'   => count($controls),
            'text_length'     => strlen(trim($element->textContent ?? '')),
            'child_count'     => $this->childElementCount($element),
            'html'            => $boundedHtml['html'],
            'html_bytes'      => $boundedHtml['bytes'],
            'html_truncated'  => $boundedHtml['truncated'],
        );
        if ( 'form' !== strtolower($element->tagName) ) {
            $finding['form_boundary'] = $this->pseudoFormBoundaryMetadata($element);
        }

        return FallbackDiagnostic::build($finding, $this->fallbackProvenance);
    }

    /**
     * @return array<string, mixed>
     */
    private function formSuccessPanelMetadata(DOMElement $form): array
    {
        if ( 'form' !== strtolower($form->tagName) ) {
            foreach ( $this->descendantElements($form) as $descendant ) {
                if ( $this->hasSuccessPanelSignal($descendant) ) {
                    return $this->successPanelMetadata($descendant);
                }
            }

            return array();
        }

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

            return $this->successPanelMetadata($sibling);
        }

        return array();
    }

    /** @return array<string, mixed> */
    private function successPanelMetadata(DOMElement $element): array
    {
        $boundedHtml = $this->boundedFallbackHtml($this->safeFallbackHtml($element));
        return array_filter(array(
            'selector'       => $this->elementSelector($element),
            'id'             => $this->attr($element, 'id'),
            'class'          => $this->attr($element, 'class'),
            'role'           => $this->attr($element, 'role'),
            'aria_live'      => $this->attr($element, 'aria-live'),
            'text'           => $this->normalizedSuccessPanelText($element),
            'html'           => $boundedHtml['html'],
            'html_bytes'     => $boundedHtml['bytes'],
            'html_truncated' => $boundedHtml['truncated'],
        ), static fn (mixed $value): bool => is_bool($value) || is_int($value) || '' !== trim((string) $value));
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

        // A pseudo-form must be a local interaction region, never the page shell
        // that happens to contain navigation or editorial content plus controls.
        if ( $this->pseudoFormContainsUnrelatedLandmark($element) ) {
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
     * Whether a container holds a local, labeled data-entry control and a submit
     * action. Unlike a real form, a div gives a plain button no submit ownership,
     * so its action must be explicit in type or semantics.
     */
    private function containerPairsDataEntryWithSubmit(DOMElement $element): bool
    {
        $hasDataEntry = false;
        $hasFieldLabel = false;
        $hasSubmit = false;
        $hasActionControl = false;
        $hasContainerAction = '' !== trim($this->attr($element, 'action')) || '' !== trim($this->attr($element, 'method')) || '' !== trim($this->attr($element, 'data-action'));

        foreach ( $this->formControlElements($element) as $control ) {
            if ( $this->isPseudoFormDataEntryControl($control) && ! $this->hasStandaloneSearchSignal($element, $control) ) {
                $hasDataEntry = true;
                $hasFieldLabel = $hasFieldLabel || '' !== trim($this->formControlLabel($control)) || '' !== trim($this->attr($control, 'aria-label')) || '' !== trim($this->attr($control, 'name'));
            } elseif ( 'button' === strtolower($control->tagName) || ( 'input' === strtolower($control->tagName) && ! in_array($this->formControlType($control), array( 'reset', 'button' ), true) ) ) {
                $hasActionControl = true;
                $hasSubmit = $hasSubmit || $this->isPseudoFormSubmitControl($control);
            }

            if ( $hasDataEntry && $hasFieldLabel && ( $hasSubmit || ( $hasContainerAction && $hasActionControl ) ) ) {
                return true;
            }
        }

        return false;
    }

    private function isPseudoFormSubmitControl(DOMElement $control): bool
    {
        $type = $this->formControlType($control);
        if ( in_array($type, array( 'submit', 'image' ), true) ) {
            return true;
        }

        return $this->hasSubmitSemantics($control);
    }

    private function pseudoFormContainsUnrelatedLandmark(DOMElement $element): bool
    {
        foreach ( $this->descendantElements($element) as $descendant ) {
            $tagName = strtolower($descendant->tagName);
            $role = strtolower($this->attr($descendant, 'role'));
            if ( in_array($tagName, array( 'article', 'nav', 'header', 'footer', 'main' ), true)
                || in_array($role, array( 'article', 'navigation', 'banner', 'contentinfo', 'main' ), true) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function pseudoFormBoundaryMetadata(DOMElement $element): array
    {
        $rejectedAncestors = array();
        for ( $ancestor = $element->parentNode; $ancestor instanceof DOMElement && count($rejectedAncestors) < 4; $ancestor = $ancestor->parentNode ) {
            if ( ! $this->pseudoFormContainsUnrelatedLandmark($ancestor) && ! $this->containerPairsDataEntryWithSubmit($ancestor) ) {
                continue;
            }
            $rejectedAncestors[] = array(
                'selector' => $this->elementSelector($ancestor),
                'reason'   => $this->pseudoFormContainsUnrelatedLandmark($ancestor) ? 'contains_unrelated_landmark' : 'contains_nested_coherent_form',
            );
        }

        return array(
            'schema' => 'generic/form-boundary/v1',
            'selector' => $this->elementSelector($element),
            'selection_basis' => array( 'local_controls', 'associated_label', 'submit_semantics' ),
            'rejected_ancestors' => $rejectedAncestors,
        );
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

        if ( $control->hasAttribute('required') || 'true' === strtolower(trim($this->attr($control, 'aria-required'))) ) {
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
                // An explicit empty value is a select placeholder semantic, not
                // a missing value to replace with the visible option label.
                'value' => $option->hasAttribute('value') ? $value : trim($option->textContent ?? ''),
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
        $declarations = $this->presentationDeclarations($element);
        $url = $this->backgroundImageExtractor->urlFromStyle($this->mergedPresentationStyle($element));
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
        $layout = $this->presentationAttributes($element)['layout'] ?? null;
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

            $columns[] = $this->createBlock('core/column', $this->presentationAttributes($child), $converted, $child);
        }
        array_push($fallbacks, ...$rowFallbacks);

        return $this->createBlock('core/columns', $this->presentationAttributes($element), $columns, $element);
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
        $container = $this->structuralPresentationDeclarations($element);
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
            $childFlex = $this->equalWidthFlexSignal($this->structuralPresentationDeclarations($child));
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
            $attrs = array_merge($this->presentationAttributes($picture), $attrs);
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
        $inline = trim($this->cssValueWithoutImportant((string) ($this->cssDeclarations($this->attr($image, 'style'))[ $property ] ?? '')));
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
        $structural = $this->structuralPresentationDeclarations($element);
        $position = strtolower(trim((string) ($structural['position'] ?? '')));
        if ( $this->hasPositionedVisualMediaChild($element) && ! in_array($position, array( 'absolute', 'fixed', 'sticky' ), true) ) {
            $geometry['position'] = 'relative';
        }
        $presentation = $this->presentationDeclarations($element);
        $inline = $this->cssDeclarations($this->attr($element, 'style'));
        foreach ( array( 'height', 'min-height' ) as $property ) {
            $family = $this->responsivePropertyFamily($property);
            if ( array() !== $this->conditionalStyleRules
                && $this->hasConditionalStyleFamily($element, $family)
                && ! $this->inlineOwnsResponsiveProperty($property, $family, $inline)
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

        $carrier = $this->inlineGeometryClassName(
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

    private function hasPositionedVisualMediaChild(DOMElement $element): bool
    {
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement || 0 === $child->getElementsByTagName('img')->length ) {
                continue;
            }
            $position = strtolower(trim((string) ($this->structuralPresentationDeclarations($child)['position'] ?? '')));
            if ( in_array($position, array( 'absolute', 'fixed' ), true) ) {
                return true;
            }
        }
        return false;
    }

    private function intrinsicVisualMediaHeight(DOMElement $element): string
    {
        $parent = $element->parentNode;
        if ( $parent instanceof DOMElement ) {
            $parentPosition = strtolower(trim((string) ($this->structuralPresentationDeclarations($parent)['position'] ?? '')));
            if ( in_array($parentPosition, array( 'absolute', 'fixed' ), true) ) {
                return '';
            }
        }
        $own = $this->presentationDeclarations($element);
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
            $position = strtolower(trim((string) ($this->structuralPresentationDeclarations($child)['position'] ?? '')));
            if ( ! in_array($position, array( 'absolute', 'fixed' ), true) ) {
                continue;
            }
            foreach ( $child->getElementsByTagName('img') as $image ) {
                if ( ! $image instanceof DOMElement ) {
                    continue;
                }
                for ( $carrier = $image->parentNode; $carrier instanceof DOMElement && $carrier !== $child; $carrier = $carrier->parentNode ) {
                    $carrierPosition = strtolower(trim((string) ($this->structuralPresentationDeclarations($carrier)['position'] ?? '')));
                    if ( 'sticky' === $carrierPosition ) {
                        continue 2;
                    }
                }
                $height = trim($this->cssValueWithoutImportant((string) ($this->cssDeclarations($this->attr($image, 'style'))['height'] ?? '')));
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
        $position = strtolower(trim((string) ($this->structuralPresentationDeclarations($element)['position'] ?? '')));
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

    private function videoOnlyCustomElement(DOMElement $element): ?DOMElement
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

        if ( ! $this->responsiveMediaBlockGenerated ) {
            $this->generatedBlocks[] = ( new ResponsiveMediaBlockGenerator() )->definition($this->generatedBlockNamespace);
            $this->responsiveMediaBlockGenerated = true;
        }

        return $this->createBlock(
            $this->generatedBlockNamespace . '/' . ResponsiveMediaBlockGenerator::LOCAL_NAME,
            array( 'content' => $this->safeFallbackHtml($element), 'kind' => 'media' ),
            array(),
            $element
        );
    }

    /** @return array<string, mixed>|null */
    private function capturedMediaLayoutBoundaryBlock(DOMElement $element): ?array
    {
        if ( 'main' !== strtolower($element->tagName)
            || $this->isRuntimeDomTarget($element)
            || $this->hasRuntimeTargetInSubtree($element)
            || $this->hasLayoutGeometryProofInSubtree($element)
            || $this->sourceElementNestingDepth($element) <= self::MAX_CAPTURED_LAYOUT_SOURCE_NESTING
            || ! $this->hasCapturedMediaContent($element)
        ) {
            return null;
        }

        if ( ! $this->responsiveMediaBlockGenerated ) {
            $this->generatedBlocks[] = ( new ResponsiveMediaBlockGenerator() )->definition($this->generatedBlockNamespace);
            $this->responsiveMediaBlockGenerated = true;
        }

        return $this->createBlock(
            $this->generatedBlockNamespace . '/' . ResponsiveMediaBlockGenerator::LOCAL_NAME,
            array( 'content' => $this->safeFallbackHtml($element), 'kind' => 'layout' ),
            array(),
            $element
        );
    }

    private function hasLayoutGeometryProofInSubtree(DOMElement $element): bool
    {
        $prefix = $this->elementSelector($element) . ' > ';
        foreach ( $this->layoutGeometryProofReductions as $proof ) {
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
            if ( $descendant instanceof DOMElement && $this->isRuntimeDomTarget($descendant) ) {
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

        if ( ! $this->authoredMarqueeBlockGenerated ) {
            $this->generatedBlocks[] = ( new AuthoredMarqueeBlockGenerator() )->definition($this->generatedBlockNamespace);
            $this->authoredMarqueeBlockGenerated = true;
        }

        $duration = 40.0;
        $durationCandidates = array( $this->cssDeclarations($this->attr($track, 'style'))['--marquee-duration'] ?? '' );
        for ( $carrier = $element; $carrier instanceof DOMElement && 'body' !== strtolower($carrier->tagName); $carrier = $carrier->parentNode instanceof DOMElement ? $carrier->parentNode : null ) {
            $durationCandidates[] = $this->cssDeclarations($this->attr($carrier, 'style'))['--marquee-duration'] ?? '';
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
            'blockName' => $this->generatedBlockNamespace . '/' . AuthoredMarqueeBlockGenerator::LOCAL_NAME,
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
            $generated = $this->fallbackEmitter->maybeGenerateCustomBlock(
                $element,
                $this->generatedBlocks,
                $this->generatedBlockNamespace,
                true
            );
            if ( null !== $generated ) {
                return $this->createBlock($generated['blockName'], $generated['attrs'], array(), $element);
            }
        }

        $boundedHtml = $this->boundedFallbackHtml($this->safeFallbackHtml($element));
        $selector = $this->elementSelector($element);
        if ( ! isset($this->responsiveImageFallbackSelectors[$selector]) ) {
            $this->responsiveImageFallbackSelectors[$selector] = true;
            $this->responsiveImageFallbacks[] = FallbackDiagnostic::build(array(
                'type'            => 'html',
                'reason'          => 'responsive_image_fallback',
                'diagnostic_code' => 'html_responsive_image_fallback',
                'message'         => 'Responsive image sources were preserved as sanitized core/html because core/image cannot serialize srcset, sizes, or picture source selection.',
                'source_format'   => 'html',
                'tag'             => strtolower($element->tagName),
                'selector'        => $selector,
                'attributes'      => $this->htmlAttributes($element),
                'context'         => $this->sourceContext($element),
                'classification'  => $this->fallbackEmitter->classifyFallbackSubtree($element),
                'html'            => $boundedHtml['html'],
                'html_bytes'      => $boundedHtml['bytes'],
                'html_truncated'  => $boundedHtml['truncated'],
            ), $this->fallbackProvenance);
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
            || $this->hasConditionalStyleFamily($element, 'layout')
            || $this->hasConditionalStyleFamily($element, 'visibility')
            || $this->hasConditionalStyleFamily($element, 'opacity')
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
            || $this->hasConditionalStyleFamily($element, 'layout')
            || $this->hasConditionalStyleFamily($element, 'visibility')
            || $this->hasConditionalStyleFamily($element, 'opacity')
            || $this->isRuntimeDomTarget($element)
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
        // Root-relative URLs cannot traverse out of their website root. Keep
        // their original spelling so they cannot match a relative asset key.
        if (str_starts_with(trim($url), '/') && preg_match('~(?:^|/)\.\.(?:/|$)~', parse_url($url, PHP_URL_PATH) ?: '')) return array(trim($url));
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
            $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), $this->nonCoreImageFigureClassName($figure), $this->nonCoreImageClassName($image), ...$this->authorSemanticMarkersForElement($image));
        } else {
            $attrs['className'] = $this->mergePresentationClassNames((string) ($attrs['className'] ?? ''), $this->injectedFigureHeightClassName($image));
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
            $inlineScale = strtolower($this->cssValueWithoutImportant((string) ($this->cssDeclarations($this->attr($image, 'style'))['object-fit'] ?? '')));
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
        foreach ($this->imageShapeStyleRules as $rule) {
            if ($property !== $rule['property'] || ! $this->matchesCssSelector($element, $rule['selector'])) {
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
                'specificity' => $this->mediaTextSelectorSpecificity($rule['selector']),
                'order' => $rule['order'],
                'inline' => false,
            );
            if ($this->imageShapeDeclarationWins($candidate, $winner)) {
                $winner = $candidate;
            }
        }
        $inlineEntries = $this->imageShapeDeclarationEntries($this->attr($element, 'style'));
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
        $specificity = $this->compareMediaTextSpecificity($candidate['specificity'], $current['specificity']);
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

<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Automattic\BlocksEngine\PhpTransformer\Css\CssAnalysisLimits;
use Automattic\BlocksEngine\PhpTransformer\Css\CssLength;
use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatchCache;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlLabel;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;
use Closure;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;

/** Emits bounded authored presentation facts for provider-materialized form controls. */
final class FormPresentationGraphBuilder
{
    private const MAX_CONTROLS = 128;
    private const MAX_RULES_PER_ROLE = 96;
    private const MAX_RULES = 8192;
    private const MAX_SELECTORS = 16384;
    private const MAX_CONDITION_DEPTH = 8;
    private const MAX_VARIANTS = 256;
    private const MAX_VISUAL_PARTS = 32;
    private const MAX_VISUAL_BYTES = 12288;
    private const MAX_VISUAL_DIMENSION = 4096;
    private const MAX_PROVENANCE = 32;
    /** Authored values such as system font stacks exceed a few hundred bytes; bound like selectors and conditions. */
    private const MAX_STYLE_VALUE_BYTES = 1024;
    private const MAX_DIAGNOSTICS = 32;
    /**
     * The inherited text properties a label or button caption reads from its
     * text carrier when it declares none itself, including the caption's case.
     */
    private const TYPOGRAPHY_PROPERTIES = array(
        'color', 'font', 'font-family', 'font-size', 'font-weight', 'letter-spacing', 'line-height', 'text-transform',
    );
    /** The paint properties one control renders itself: its text colour and background fill. */
    private const PAINT_PROPERTIES = array(
        'color', 'background', 'background-color', 'background-image', 'background-position', 'background-repeat', 'background-size',
    );
    /** The longhands each winning shorthand resets on the same element. */
    private const SHORTHAND_LONGHANDS = array(
        'background' => array( 'background-color', 'background-image', 'background-position', 'background-repeat', 'background-size' ),
        'font' => array( 'font-family', 'font-size', 'font-style', 'font-variant', 'font-weight', 'line-height' ),
        'margin' => array( 'margin-top', 'margin-right', 'margin-bottom', 'margin-left' ),
        'padding' => array( 'padding-top', 'padding-right', 'padding-bottom', 'padding-left' ),
    );
    /**
     * The box a button's caption carrier paints behind the caption: a sliding-door
     * `<a><span>Caption</span></a>` draws its body on the span and its cap on the link.
     */
    private const CAPTION_LAYER_PROPERTIES = array(
        'background', 'background-color', 'background-image', 'background-position', 'background-repeat', 'background-size',
        'border-radius', 'height',
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
    );
    /** A block-level caption carrier fills its button, so its padding sizes the button box. */
    private const CAPTION_BOX_PROPERTIES = array(
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'padding-block', 'padding-inline', 'padding-block-start', 'padding-block-end', 'padding-inline-start', 'padding-inline-end',
    );
    /** The text properties a visible group caption reports; a legend needs typography, not the wrapper's box. */
    private const GROUP_CAPTION_PROPERTIES = array(
        'color', 'font', 'font-family', 'font-size', 'font-style', 'font-variant', 'font-weight',
        'letter-spacing', 'line-height', 'text-align', 'text-transform',
    );
    /** ARIA roles that make a non-fieldset wrapper a named group of controls, as in FormControlTopologyBuilder. */
    private const GROUP_ROLES = array( 'radiogroup', 'group' );
    /** Bounded caption resolution: a caption search walks at most 8 previous siblings per scope. */
    private const MAX_CAPTION_SIBLINGS = 8;
    /** Caption search may check the group and ancestors up to 3 wrapper levels above it before it gives up. */
    private const MAX_CAPTION_ANCESTORS = 3;
    /** How far a text-carrier search may walk before the search, not the cascade, gives up. */
    private const MAX_CARRIER_CANDIDATES = 256;
    private const MAX_ROW_ANCESTORS = 32;
    /** Row-owned layout and frame paint; never widen the shared element vocabulary here. */
    private const ROW_PROPERTIES = array(
        'display', 'flex-direction', 'flex-wrap', 'column-gap', 'gap', 'grid-template-columns',
        'width', 'box-sizing', 'border', 'border-width', 'border-style', 'border-color',
        'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width',
        'border-top-style', 'border-right-style', 'border-bottom-style', 'border-left-style',
        'border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color',
        'border-radius', 'background-color',
    );
    private const ROW_ITEM_PROPERTIES = array('flex-grow', 'flex-shrink', 'flex-basis', 'width', 'min-width', 'max-width');
    /** Elements whose default display opens a block box; a member of this kind breaks the shared line. */
    private const BLOCK_FLOW_TAGS = array(
        'address', 'article', 'aside', 'blockquote', 'details', 'dialog', 'dd', 'div', 'dl', 'dt', 'fieldset', 'figcaption', 'figure',
        'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hgroup', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'table', 'ul',
    );
    private const CONTROL_CONTAINER_PROPERTIES = array(
        'background', 'background-color', 'border', 'border-color', 'border-style', 'border-width',
        'border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color',
        'border-top-style', 'border-right-style', 'border-bottom-style', 'border-left-style',
        'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width',
        'border-radius', 'border-top-left-radius', 'border-top-right-radius', 'border-bottom-right-radius', 'border-bottom-left-radius',
    );
    private const PROPERTIES = array(
        'appearance', 'background', 'background-color', 'background-image', 'background-position', 'background-repeat', 'background-size',
        'border', 'border-color', 'border-style', 'border-width',
        'border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color',
        'border-top-style', 'border-right-style', 'border-bottom-style', 'border-left-style',
        'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width',
        'border-radius', 'border-top-left-radius', 'border-top-right-radius', 'border-bottom-right-radius', 'border-bottom-left-radius',
        'box-sizing', 'color', 'display', 'font', 'font-family', 'font-size', 'font-style', 'font-variant', 'font-weight',
        'height', 'letter-spacing', 'line-height', 'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        // Margin carries the same logical longhands padding already reports, so a
        // source that spaces an element with `margin-inline-start` keeps that box.
        'margin-block-start', 'margin-block-end', 'margin-inline-start', 'margin-inline-end',
        'max-width', 'min-height', 'min-width', 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'padding-block-start', 'padding-block-end', 'padding-inline-start', 'padding-inline-end',
        // Padding carries the same two-value logical shorthand margin already
        // reports, so a source that spaces an element with `padding-inline` or
        // `padding-block` keeps that box instead of losing it to the allowlist.
        'padding-block', 'padding-inline',
        'text-align', 'text-decoration', 'text-indent', 'text-transform', 'vertical-align', 'width',
        'align-self', 'bottom', 'flex', 'flex-basis', 'flex-grow', 'flex-shrink', 'inset', 'justify-self',
        'left', 'margin-block', 'margin-inline', 'order', 'position', 'right', 'top', 'transform', 'z-index',
        'flex-direction', 'flex-wrap', 'flex-flow', 'align-items', 'justify-content', 'gap'
    );
    /** Row distribution facts only; element presentation keeps the shared vocabulary. */
    private const ROW_ONLY_PROPERTIES = array('column-gap', 'grid-template-columns');

    private array $diagnostics = array();
    /** @var array<string,true> Stylesheet facts, separate from the current element's limits. */
    private array $stylesheetDiagnostics = array();

    /** Stylesheet diagnostics accumulated by this builder, once per selector. */
    public function stylesheetDiagnostics(): array
    {
        return array_keys($this->stylesheetDiagnostics);
    }
    /** @var array<string,true> Source declarations whose value cannot be represented, keyed by source and property. */
    private array $unrepresentable = array();
    private bool $truncated = false;
    /** Memoized rule sets for {@see typographyStyles()}, analyzed once per transform. */
    private ?array $typographyAnalysis = null;
    private ?array $visibilityAnalysis = null;
    /** Selector match memo for one source document; a cache-owning caller shares it across the passes of one compile. */
    private readonly CssSelectorMatchCache $matchCache;
    /** Custom-property resolution over {@see $matchCache}'s source document. */
    private readonly FormCustomPropertyResolver $customProperties;

    /** @param (Closure(DOMElement, string): string)|null $resolveValue @param (Closure(DOMElement): string)|null $sanitizeInlineSvgMarkup @param (Closure(DOMElement): ?DOMElement)|null $requiredMarker @param list<DOMElement> $scopeElements @param CssSelectorMatchCache|null $selectorMatchCache A cache the caller owns and shares across one compile's passes; by default this builder owns one. */
    public function __construct(private readonly ?Closure $resolveValue = null, private readonly ?Closure $sanitizeInlineSvgMarkup = null, private readonly ?Closure $requiredMarker = null, private readonly array $scopeElements = array(), ?CssSelectorMatchCache $selectorMatchCache = null, private readonly array $additionalControls = array(), private readonly ?array $controlElements = null)
    {
        $this->matchCache = $selectorMatchCache ?? new CssSelectorMatchCache();
        $this->customProperties = new FormCustomPropertyResolver($this->matchCache);
    }

    /** Source form box presentation is distinct from its control layout graph. */
    public function buildContainer(DOMElement $form, array $stylesheets, string $inlineCss = ''): array
    {
        $this->truncated = false;
        $this->unrepresentable = array();
        $analysis = (new CssRuleAnalyzer())->analyze($stylesheets, $inlineCss, self::PROPERTIES, CssAnalysisLimits::MAX_STYLESHEET_BYTES, self::MAX_RULES, self::MAX_SELECTORS, self::MAX_CONDITION_DEPTH, $this->cascadeFilter(array($form)), CssAnalysisLimits::MAX_SCANNED_SELECTORS);
        if ($analysis['truncated']) return array();
        $matched = $this->matched($form, $analysis['rules']);
        $styles = $this->styles($matched['base'], $form, null, array());
        $variants = array();
        foreach ($this->effectiveConditional($matched['conditional'], $matched['base']) as $encoded => $facts) {
            if (count($variants) >= self::MAX_RULES_PER_ROLE) return array();
            $condition = json_decode($encoded, true);
            $patch = $this->styles($facts, $form, $condition, array());
            if ($patch) $variants[] = array('condition' => $condition, 'styles' => $patch, 'provenance' => $this->provenance($facts, $condition));
        }
        return $this->truncated || (!$styles && !$variants) ? array() : array('schema' => 'generic/form-container-presentation/v1', 'styles' => $styles, 'provenance' => $this->provenance($matched['base'], null), 'variants' => $variants);
    }

    /** Element-owned box facts and inherited text use the existing cascade. */
    public function buildElement(DOMElement $element, array $stylesheets, string $inlineCss = ''): array
    {
        $this->diagnostics = array();
        $this->truncated = false;
        $this->unrepresentable = array();
        $this->typographyAnalysis ??= $this->typographyAnalysis($stylesheets, $inlineCss);
        $analysis = $this->typographyAnalysis;
        $this->truncated = $analysis['truncated'];
        if ($this->truncated) $this->diagnostics[] = 'css_analysis_limit';
        $matched = $this->matched($element, $analysis['rules']);
        $base = $matched['base'];
        $conditional = $this->effectiveConditional($matched['conditional'], $base);
        // Inherited text properties reach this element from the nearest declaring
        // ancestor. `text-align` is inherited too: it places inline-level content
        // (an inline-block submit) inside a block wrapper a provider flattens.
        $inherited = array_flip(array_merge(self::TYPOGRAPHY_PROPERTIES, array( 'text-align' )));
        // Inheritance is per property: a local font-size does not prevent the
        // family and color from coming from the nearest declaring ancestor.
        for ($ancestor = $element->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode) {
            $parent = $this->matched($ancestor, $analysis['rules']);
            foreach (array_intersect_key($parent['base'], $inherited) as $property => $fact) {
                if (!isset($base[$property])) $base[$property] = $fact + array('element' => $ancestor);
            }
            foreach ($this->effectiveConditional($parent['conditional'], $parent['base']) as $encoded => $facts) {
                foreach (array_intersect_key($facts, $inherited) as $property => $fact) {
                    if (isset($matched['base'][$property]) || isset($conditional[$encoded][$property])) continue;
                    if (isset($base[$property]['element']) && !$base[$property]['element']->isSameNode($ancestor)) continue;
                    $conditional[$encoded][$property] = $fact + array('element' => $ancestor);
                }
            }
            // Once a property has a nearest declaring ancestor, further
            // ancestors cannot override it, even with higher specificity.
            foreach (array_keys($parent['base']) as $property) unset($inherited[$property]);
        }
        foreach ($base as $property => $fact) {
            $owner = $fact['element'] ?? $element;
            foreach ($this->customProperties->conditionsChanging($fact['value'], $owner, $analysis['customProperties'], true) as $condition) {
                $encoded = json_encode($condition);
                $conditional[$encoded][$property] ??= $fact;
            }
        }
        $styles = $this->styles($base, $element, null, $analysis['customProperties'], true);
        $provenance = $this->provenance($base, null);
        $variants = array();
        foreach ($conditional as $encoded => $facts) {
            if (count($variants) >= self::MAX_RULES_PER_ROLE) {
                $this->truncated = true;
                $this->diagnostics[] = 'variant_limit';
                break;
            }
            $condition = json_decode($encoded, true);
            $patch = $this->styles($facts, $element, $condition, $analysis['customProperties']);
            foreach ($patch as $key => $value) if (($styles[$key] ?? null) === $value) unset($patch[$key]);
            $facts = array_filter($facts, static fn(array $fact, string $property): bool => isset($patch[self::key($property)]), ARRAY_FILTER_USE_BOTH);
            if ($patch) $variants[] = array('condition' => $condition, 'styles' => $patch, 'precedence' => $this->precedence($facts), 'provenance' => $this->provenance($facts, $condition));
        }
        $result = array('schema' => 'generic/form-element-presentation/v1', 'styles' => $styles, 'provenance' => $provenance, 'variants' => $variants, 'truncated' => $this->truncated, 'diagnostics' => array_slice(array_values(array_unique($this->diagnostics)), 0, self::MAX_DIAGNOSTICS));
        self::assertElement($result);
        return $result;
    }

    public static function assertElement(array $presentation): void
    {
        if (array_diff(array_keys($presentation), array('schema', 'styles', 'provenance', 'variants', 'truncated', 'diagnostics')) || !array_is_list($presentation['variants'] ?? null)) throw new InvalidArgumentException('Form element presentation envelope has unknown keys.');
        foreach ($presentation['variants'] as $variant) {
            if (!is_array($variant) || array_diff(array_keys($variant), array('condition', 'styles', 'precedence', 'provenance'))) throw new InvalidArgumentException('Form element presentation variant has unknown keys.');
            foreach (ArrayField::array($variant, 'precedence') as $rank) if (!is_array($rank) || array_diff(array_keys($rank), array('source_order', 'specificity', 'important'))) throw new InvalidArgumentException('Form element presentation precedence has unknown keys.');
        }
        if ('generic/form-element-presentation/v1' !== ($presentation['schema'] ?? null) || !is_array($presentation['styles'] ?? null) || !is_array($presentation['provenance'] ?? null) || !is_array($presentation['variants'] ?? null) || count($presentation['variants']) > self::MAX_RULES_PER_ROLE || !is_bool($presentation['truncated'] ?? null) || !is_array($presentation['diagnostics'] ?? null) || count($presentation['diagnostics']) > self::MAX_DIAGNOSTICS) throw new InvalidArgumentException('Form element presentation envelope is invalid.');
        if (!array_is_list($presentation['diagnostics']) || array_filter($presentation['diagnostics'], static fn (mixed $diagnostic): bool => !is_string($diagnostic) || '' === trim($diagnostic) || str_starts_with($diagnostic, 'unsupported_selector:'))) throw new InvalidArgumentException('Form element diagnostics must describe the element, not the stylesheet.');
        self::assertStyles($presentation['styles']);
        self::assertProvenance($presentation['provenance'], $presentation['styles'], null);
        foreach ($presentation['variants'] as $variant) {
            if (!is_array($variant) || !is_array($variant['condition'] ?? null) || !self::validCondition($variant['condition']) || !is_array($variant['styles'] ?? null) || !is_array($variant['provenance'] ?? null) || !is_array($variant['precedence'] ?? null)) throw new InvalidArgumentException('Form element presentation variant is invalid.');
            self::assertStyles($variant['styles']);
            self::assertProvenance($variant['provenance'], $variant['styles'], $variant['condition']);
            foreach ($variant['precedence'] as $property => $rank) if (!in_array($property, self::PROPERTIES, true) || !isset($variant['styles'][self::key($property)]) || !is_array($rank) || !is_int($rank['source_order'] ?? null) || !is_int($rank['specificity'] ?? null) || !is_bool($rank['important'] ?? null)) throw new InvalidArgumentException('Form element presentation precedence is invalid.');
        }
    }

    /**
     * Resolved typography facts for one element, through the same cascade and
     * custom-property resolution the presentation graph roles use — the one
     * cascade, so copy reported outside the graph (an in-form context item)
     * reads the same values a role would.
     *
     * @param list<array<string, mixed>> $stylesheets @return array<string, string>
     */
    public function typographyStyles(DOMElement $element, array $stylesheets, string $inlineCss = ''): array
    {
        $this->typographyAnalysis ??= $this->typographyAnalysis($stylesheets, $inlineCss);
        if ( $this->typographyAnalysis['truncated'] ) {
            return array();
        }
        $matched = $this->matched($element, $this->typographyAnalysis['rules']);
        return $this->styles(
            array_intersect_key($matched['base'], array_flip(self::TYPOGRAPHY_PROPERTIES)),
            $element,
            null,
            $this->typographyAnalysis['customProperties']
        );
    }

    /**
     * The paint one element renders — the text colour and background fill its
     * own matched rules declare (shorthand or longhand), through the same
     * cascade the presentation graph reports with. A form provider materializes
     * a control into a block that carries its paint as style attributes, so the
     * declaration must state it even where block presentation leaves the
     * class-owned paint to the projected stylesheet (buttons do: their fill is
     * read from the same rules the graph proves). Buttons do not inherit
     * colour, so only the element's own declarations count. Memoized analysis;
     * empty when the bounded cascade was truncated.
     *
     * @param list<array<string, mixed>> $stylesheets @return array<string, string>
     */
    public function paintStyles(DOMElement $element, array $stylesheets, string $inlineCss = ''): array
    {
        $this->typographyAnalysis ??= $this->typographyAnalysis($stylesheets, $inlineCss);
        if ( $this->typographyAnalysis['truncated'] ) {
            return array();
        }
        $matched = $this->matched($element, $this->typographyAnalysis['rules']);
        return $this->styles(
            array_intersect_key($matched['base'], array_flip(self::PAINT_PROPERTIES)),
            $element,
            null,
            $this->typographyAnalysis['customProperties']
        );
    }

    /**
     * Whether the source hides an element unconditionally: `display: none` on it
     * or on an ancestor below `$boundary` (including the `hidden` attribute), or a resolved `visibility` of
     * `hidden`/`collapse` (the nearest declaration wins, since a descendant may
     * re-show itself). Form builders keep status copy such as a success message
     * inside the form this way until a submission succeeds. Returns the
     * deciding declaration, or null when the element is shown, when it is shown
     * under any condition a rule on the chain declares (a responsive variant is
     * not status copy), or when the bounded cascade could not be analyzed.
     *
     * @return array{property: string, value: string, selector: string, source_path: string}|null
     */
    public function hiddenState(DOMElement $element, DOMElement $boundary, array $stylesheets, string $inlineCss = ''): ?array
    {
        if ( null === $this->visibilityAnalysis ) {
            $filter = array() === $this->scopeElements ? null : $this->cascadeFilter($this->scopeElements);
            $this->visibilityAnalysis = (new CssRuleAnalyzer())->analyze($stylesheets, $inlineCss, array( 'display', 'visibility' ), CssAnalysisLimits::MAX_STYLESHEET_BYTES, self::MAX_RULES, self::MAX_SELECTORS, self::MAX_CONDITION_DEPTH, $filter, CssAnalysisLimits::MAX_SCANNED_SELECTORS);
        }
        if ( $this->visibilityAnalysis['truncated'] ) {
            return null;
        }
        return $this->hiddenBelow($element, $boundary, $this->visibilityAnalysis['rules']);
    }

    /**
     * The declaration that hides an element (or an ancestor below `$boundary`)
     * under every condition the chain's rules declare, judged from `$rules`.
     *
     * @param list<array<string, mixed>> $rules
     * @return array{property: string, value: string, selector: string, source_path: string}|null
     */
    private function hiddenBelow(DOMElement $element, DOMElement $boundary, array $rules): ?array
    {
        $chain = array();
        $conditions = array();
        for ( $node = $element; $node instanceof DOMElement && ! $node->isSameNode($boundary); $node = $node->parentNode instanceof DOMElement ? $node->parentNode : null ) {
            $facts = $this->visibilityFacts($node, $rules);
            $chain[] = $facts;
            $conditions += array_fill_keys(array_keys($facts['conditional']), true);
        }
        $hidden = self::hiddenDecision($chain, null);
        if ( null === $hidden ) {
            return null;
        }
        // A responsive variant that shows the element makes it layout, not status copy.
        foreach ( array_keys($conditions) as $condition ) {
            if ( null === self::hiddenDecision($chain, (string) $condition) ) {
                return null;
            }
        }
        return $hidden;
    }

    /**
     * @param list<array{base: array<string, array<string, mixed>>, conditional: array<string, array<string, array<string, mixed>>>}> $chain
     * @return array{property: string, value: string, selector: string, source_path: string}|null
     */
    private static function hiddenDecision(array $chain, ?string $condition): ?array
    {
        $visibility = null;
        foreach ( $chain as $facts ) {
            $resolved = $facts['base'];
            if ( null !== $condition ) {
                foreach ( $facts['conditional'][$condition] ?? array() as $property => $fact ) {
                    CssCascade::apply($resolved, $property, $fact);
                }
            }
            $display = $resolved['display'] ?? null;
            if ( is_array($display) && 'none' === strtolower(trim((string) $display['value'])) ) {
                return self::hiddenFact('display', 'none', $display);
            }
            if ( null === $visibility && is_array($resolved['visibility'] ?? null) ) {
                $visibility = $resolved['visibility'];
            }
        }
        $value = null === $visibility ? '' : strtolower(trim((string) $visibility['value']));
        return in_array($value, array( 'hidden', 'collapse' ), true) ? self::hiddenFact('visibility', $value, $visibility) : null;
    }

    /**
     * Winning `display`/`visibility` declarations on one element, unconditional
     * and per condition.
     *
     * @return array{base: array<string, array<string, mixed>>, conditional: array<string, array<string, array<string, mixed>>>}
     */
    private function visibilityFacts(DOMElement $element, array $rules): array
    {
        $properties = array( 'display', 'visibility' );
        // The user-agent stylesheet's `[hidden] { display: none }`: it loses to
        // every author rule, so a stylesheet that re-shows the element wins.
        if ( $element->hasAttribute('hidden') && 'until-found' !== strtolower(trim($element->getAttribute('hidden'))) ) {
            $rules[] = array( 'inline' => true, 'selector' => '[hidden]', 'declarations' => array( array( 'name' => 'display', 'value' => 'none' ) ), 'condition' => null, 'path' => 'user-agent', 'hash' => 'user-agent-hidden', 'order' => PHP_INT_MIN, 'specificity' => -1 );
        }
        if ( $element->hasAttribute('style') ) {
            $inline = $element->getAttribute('style');
            $rules[] = array( 'inline' => true, 'selector' => '[style]', 'declarations' => CssRuleAnalyzer::declarations($inline, $properties), 'condition' => null, 'path' => 'inline-style', 'hash' => hash('sha256', $inline), 'order' => PHP_INT_MAX, 'specificity' => 10000 );
        }
        $base = array();
        $conditional = array();
        foreach ( $rules as $rule ) {
            $match = ! empty($rule['inline']) ? array( 'supported' => true, 'matches' => true ) : $this->matchCache->matches($element, $rule['selector'], $rule['parsed_selector']);
            if ( ! $match['supported'] || ! $match['matches'] ) {
                continue;
            }
            $encoded = null === ( $rule['condition'] ?? null ) ? null : (string) json_encode($rule['condition']);
            foreach ( $rule['declarations'] as $declarationOrder => $declaration ) {
                if ( ! in_array($declaration['name'], $properties, true) ) {
                    continue;
                }
                $important = 1 === preg_match('/\s*!important\s*$/i', $declaration['value']);
                $value = preg_replace('/\s*!important\s*$/i', '', $declaration['value']) ?? $declaration['value'];
                $fact = array( 'value' => $value, 'path' => $rule['path'], 'selector' => $rule['selector'], 'order' => $rule['order'], 'declaration_order' => $declarationOrder, 'specificity' => $rule['specificity'], 'important' => $important, 'layer' => $rule['layer'] ?? null );
                if ( null === $encoded ) {
                    CssCascade::apply($base, $declaration['name'], $fact);
                } else {
                    $conditional[$encoded] ??= array();
                    CssCascade::apply($conditional[$encoded], $declaration['name'], $fact);
                }
            }
        }
        return array( 'base' => $base, 'conditional' => $conditional );
    }

    /** @return array{property: string, value: string, selector: string, source_path: string} */
    private static function hiddenFact(string $property, string $value, array $fact): array
    {
        return array( 'property' => $property, 'value' => $value, 'selector' => (string) $fact['selector'], 'source_path' => (string) $fact['path'] );
    }

    /**
     * The single deepest descendant whose collapsed text is the scope's own
     * visible text, ignoring the excluded subtrees — the element a source
     * styles instead of the scope itself (a `<p>` inside a `<label>`, a
     * `<span>` inside a `<p>`). Null when no descendant carries the text alone:
     * none, or more than one at the deepest level.
     *
     * @param list<DOMElement> $excluded
     */
    public static function soleTextCarrier(DOMElement $scope, array $excluded = array()): ?DOMElement
    {
        $text = self::collapsedCarrierText($scope, $excluded);
        if ( '' === $text ) {
            return null;
        }
        $carrier = null;
        $carrierDepth = 0;
        $scanned = 0;
        foreach ( $scope->getElementsByTagName('*') as $candidate ) {
            if ( ! $candidate instanceof DOMElement ) {
                continue;
            }
            if ( ++$scanned > self::MAX_CARRIER_CANDIDATES ) {
                return null;
            }
            $depth = 0;
            $withinExcluded = false;
            for ( $ancestor = $candidate->parentNode; $ancestor instanceof DOMElement && ! $ancestor->isSameNode($scope); $ancestor = $ancestor->parentNode instanceof DOMElement ? $ancestor->parentNode : null ) {
                if ( self::isExcludedCarrier($ancestor, $excluded) ) {
                    $withinExcluded = true;
                    break;
                }
                ++$depth;
            }
            if ( $withinExcluded || self::isExcludedCarrier($candidate, $excluded)
                || self::collapsedCarrierText($candidate, $excluded) !== $text ) {
                continue;
            }
            if ( null === $carrier || $depth > $carrierDepth ) {
                $carrier = $candidate;
                $carrierDepth = $depth;
                continue;
            }
            if ( $depth === $carrierDepth ) {
                return null;
            }
        }
        return $carrier;
    }

    /** @param list<DOMElement> $excluded */
    private static function isExcludedCarrier(DOMElement $element, array $excluded): bool
    {
        foreach ( $excluded as $excludedElement ) {
            if ( $element->isSameNode($excludedElement) ) {
                return true;
            }
        }
        return false;
    }

    /** @param list<DOMElement> $excluded */
    private static function collapsedCarrierText(DOMElement $element, array $excluded): string
    {
        $text = '';
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                if ( ! self::isExcludedCarrier($child, $excluded) ) {
                    $text .= self::collapsedCarrierText($child, $excluded);
                }
                continue;
            }
            $text .= $child->textContent ?? '';
        }
        return trim((string) preg_replace('/\s+/', ' ', $text) ?? '');
    }

    /** @param list<array<string, mixed>> $stylesheets @return array{truncated: bool, rules: list<array<string, mixed>>, customProperties: list<array<string, mixed>>} */
    private function typographyAnalysis(array $stylesheets, string $inlineCss): array
    {
        $filter = array() === $this->scopeElements ? null : $this->cascadeFilter($this->scopeElements);
        $properties = (new CssRuleAnalyzer())->analyze($stylesheets, $inlineCss, self::PROPERTIES, CssAnalysisLimits::MAX_STYLESHEET_BYTES, self::MAX_RULES, self::MAX_SELECTORS, self::MAX_CONDITION_DEPTH, $filter, CssAnalysisLimits::MAX_SCANNED_SELECTORS);
        $customProperties = (new CssRuleAnalyzer())->analyze($stylesheets, $inlineCss, array('--*'), CssAnalysisLimits::MAX_STYLESHEET_BYTES, self::MAX_RULES, self::MAX_SELECTORS, self::MAX_CONDITION_DEPTH, $filter, CssAnalysisLimits::MAX_SCANNED_SELECTORS);
        return array(
            'truncated' => $properties['truncated'] || $customProperties['truncated'],
            'rules' => $properties['rules'],
            'customProperties' => $customProperties['rules'],
        );
    }

    /**
     * The retention filter shared by every pass: a selector is kept when it
     * matches a scope element or one of its ancestors — or when the matcher
     * cannot read it, so the rule survives to report its diagnostic exactly as
     * before. Custom-property passes keep only matchable selectors, matching
     * their previous inline filters. The visited chain is resolved once and its
     * wrappers retained, so every pass answers the same (element, selector)
     * questions from {@see $matchCache} instead of re-matching them.
     *
     * @param list<DOMElement> $elements
     */
    private function cascadeFilter(array $elements, bool $retainUnsupported = true): Closure
    {
        $cache = $this->matchCache;
        // Controls share most of their ancestors; each element is visited once.
        $visits = array();
        foreach ($elements as $element) {
            for ($ancestor = $element; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode instanceof DOMElement ? $ancestor->parentNode : null) {
                $path = $ancestor->getNodePath();
                if ( isset($visits[$path]) ) {
                    break;
                }
                $visits[$path] = $ancestor;
            }
        }
        $visits = array_values($visits);
        $subjects = $cache->subjectTokens($visits);
        return static function (array $selector, string $selectorText) use ($visits, $subjects, $cache, $retainUnsupported): bool {
            if ( ! $cache->subjectMayMatch($selector, $subjects) ) {
                return false;
            }
            foreach ($visits as $ancestor) {
                $match = $cache->matches($ancestor, $selectorText, $selector);
                if ($match['matches'] || ($retainUnsupported && ! $match['supported'])) return true;
            }
            return false;
        };
    }

    /** @param list<array<string, mixed>> $stylesheets @return array<string, mixed> */
    public function build(DOMElement $form, array $stylesheets, string $inlineCss = ''): array
    {
        $this->stylesheetDiagnostics = array();
        $this->diagnostics = array();
        $this->truncated = false;
        $this->unrepresentable = array();
        $groupCaptions = $this->captionElements($form);
        $controlsForCustomProperties = array_merge($this->presentationElements($form), $groupCaptions['elements']);
        $analysis = (new CssRuleAnalyzer())->analyze($stylesheets, $inlineCss, array_merge(self::PROPERTIES, self::ROW_ONLY_PROPERTIES), CssAnalysisLimits::MAX_STYLESHEET_BYTES, self::MAX_RULES, self::MAX_SELECTORS, self::MAX_CONDITION_DEPTH, $this->cascadeFilter($controlsForCustomProperties), CssAnalysisLimits::MAX_SCANNED_SELECTORS);
        $customPropertyAnalysis = (new CssRuleAnalyzer())->analyze(
            $stylesheets,
            $inlineCss,
            array('--*'),
            CssAnalysisLimits::MAX_STYLESHEET_BYTES,
            self::MAX_RULES,
            self::MAX_SELECTORS,
            self::MAX_CONDITION_DEPTH,
            $this->cascadeFilter($controlsForCustomProperties, retainUnsupported: false)
        );
        $this->diagnostics = $analysis['diagnostics'];
        $this->truncated = $analysis['truncated'];
        $controls = array();
        $variants = array();
        $visualParts = array();
        $visualGroups = array();
        $hasRequiredMarker = false;
        $hasCaptionLayer = false;
        $controlContainers = array();
        $exclusiveAncestors = (new FormControlTopologyBuilder())->exclusiveWrapperAncestors($form, $this->controlElements);

        foreach ( $this->controls($form) as $index => $control ) {
            if ( $index >= self::MAX_CONTROLS ) {
                $this->truncated = true;
                $this->diagnostics[] = 'control_limit';
                break;
            }
            $row = array( 'index' => $index );
            $roles = array( 'control' => $control, 'label' => FormControlLabel::element($control) );
            if ( null !== $this->requiredMarker && ($marker = ($this->requiredMarker)($control)) instanceof DOMElement ) {
                $roles['required_marker'] = $marker;
                $hasRequiredMarker = true;
            }
            foreach ( $roles as $role => $element ) {
                if ( ! $element instanceof DOMElement ) {
                    continue;
                }
                $matched = $this->matched($element, $analysis['rules']);
                $styles = $this->styles($matched['base'], $element, null, $customPropertyAnalysis['rules'], true);
                $provenance = $this->provenance($matched['base'], null);
                $conditional = $this->effectiveConditional($matched['conditional'], $matched['base']);
                foreach ($matched['base'] as $property => $fact) {
                    foreach ($this->customProperties->conditionsChanging($fact['value'], $element, $customPropertyAnalysis['rules'], true) as $condition) $conditional[json_encode($condition)][$property] ??= $fact;
                }
                $carried = array();
                // A button, or the element painting a hidden native submit, carries its caption.
                $buttonCaption = 'control' === $role && ( 'button' === strtolower($control->tagName) || ! FormControlClassifier::isControlElement($control) );
                if ( 'label' === $role || $buttonCaption ) {
                    // The label's text is painted by its sole text carrier (a `<p>`
                    // or `<span>` inside it). Typography that carrier declares wins
                    // over the label's own for that text, property by property, so a
                    // provider label that renders the text directly keeps it.
                    $carried = $this->carrierTypography($buttonCaption ? array() : $roles, $element, $analysis['rules'], $customPropertyAnalysis['rules'], $conditional, $buttonCaption && ! $this->hasPadding($styles));
                    if ( array() !== $carried ) {
                        [$styles, $provenance] = $this->withCarriedTypography($styles, $provenance, $carried);
                    }
                    foreach ( $carried['conditional'] ?? array() as $encoded => $patch ) {
                        $conditional[$encoded] ??= array();
                    }
                }
                if ( in_array($role, array( 'label', 'required_marker' ), true) ) {
                    $layoutElement = 'label' === $role ? $element : $element->parentNode;
                    if ( $layoutElement instanceof DOMElement ) {
                        $layout = $this->matched($layoutElement, $analysis['rules'], true);
                        foreach ( $this->effectiveConditional($layout['conditional'], $layout['base']) as $encoded => $facts ) {
                            $conditional[$encoded] ??= array();
                        }
                    }
                }
                if ( 'label' === $role ) {
                    $this->carryLabelColumnGap($styles, $provenance, $element, $analysis['rules'], $customPropertyAnalysis['rules']);
                }
                if ( 'required_marker' === $role ) {
                    $this->carryMarkerGap($styles, $provenance, $element, $analysis['rules'], $customPropertyAnalysis['rules']);
                }
                if ( array() !== $styles || 'required_marker' === $role ) {
                    $row[$role] = array( 'styles' => $styles, 'provenance' => $provenance );
                }
                if ( $buttonCaption && null !== ($layer = $this->captionLayer($element, $analysis['rules'], $customPropertyAnalysis['rules'])) ) {
                    foreach ( $layer['variants'] as $variant ) {
                        if ( count($variants) >= self::MAX_VARIANTS ) {
                            $this->truncated = true;
                            $this->diagnostics[] = 'variant_limit';
                            break;
                        }
                        $variants[] = array( 'index' => $index, 'role' => 'caption' ) + $variant;
                    }
                    unset($layer['variants']);
                    $row['caption'] = $layer;
                    $hasCaptionLayer = true;
                }
                foreach ( $conditional as $encoded => $facts ) {
                    if ( count($variants) >= self::MAX_VARIANTS ) {
                        $this->truncated = true;
                        $this->diagnostics[] = 'variant_limit';
                        break 2;
                    }
                    $condition = json_decode($encoded, true);
                    $carrierPatch = $carried['conditional'][$encoded] ?? array();
                    $facts = array_diff_key($facts, array_flip(array_map(static fn (string $key): string => str_replace('_', '-', $key), array_keys($carrierPatch['styles'] ?? array()))));
                    $patch = $this->styles($facts, $element, $condition, $customPropertyAnalysis['rules']);
                    $patch = array_merge($patch, $carrierPatch['styles'] ?? array());
                    $variantProvenance = array_merge($this->provenance($facts, $condition), $carrierPatch['provenance'] ?? array());
                    if ( 'label' === $role ) {
                        $this->carryLabelColumnGap($patch, $variantProvenance, $element, $analysis['rules'], $customPropertyAnalysis['rules'], $condition);
                    }
                    if ( 'required_marker' === $role ) {
                        $this->carryMarkerGap($patch, $variantProvenance, $element, $analysis['rules'], $customPropertyAnalysis['rules'], $condition);
                    }
                    ksort($patch);
                    if ( array() !== $patch ) {
                        if ( count($variantProvenance) > self::MAX_PROVENANCE ) {
                            $variantProvenance = array_slice($variantProvenance, 0, self::MAX_PROVENANCE);
                            $this->truncated = true;
                            $this->diagnostics[] = 'provenance_limit';
                        }
                        $variants[] = array(
                            'index' => $index,
                            'role' => $role,
                            'condition' => $condition,
                            'style_patch' => $patch,
                            'precedence' => array_merge($this->precedence($facts), $carrierPatch['precedence'] ?? array()),
                            'provenance' => $variantProvenance,
                        );
                    }
                }
            }
            $this->carryFieldGroupGapOntoLabel($row, $control, $exclusiveAncestors[$index] ?? array(), $analysis['rules'], $customPropertyAnalysis['rules']);
            $container = $this->controlContainer($index, $exclusiveAncestors[$index] ?? array(), $analysis['rules'], $customPropertyAnalysis['rules']);
            if ( null !== $container ) {
                $controlContainers[] = $container['container'];
                foreach ( $container['variants'] as $variant ) {
                    if ( count($variants) >= self::MAX_VARIANTS ) { $this->truncated = true; $this->diagnostics[] = 'variant_limit'; break 2; }
                    $variants[] = $variant;
                }
            }
            $capturedParts = $this->visualParts($control, $index, $analysis['rules'], $customPropertyAnalysis['rules']);
            foreach ( $capturedParts as $part ) {
                if ( count($visualParts) >= self::MAX_VISUAL_PARTS ) {
                    $this->truncated = true;
                    $this->diagnostics[] = 'visual_part_limit';
                    break;
                }
                $visualParts[] = $part['part'];
                foreach ( $part['variants'] as $variant ) {
                    if ( count($variants) >= self::MAX_VARIANTS ) {
                        $this->truncated = true;
                        $this->diagnostics[] = 'variant_limit';
                        break 2;
                    }
                    $variants[] = $variant;
                }
            }
            $visualGroup = $capturedParts === array() ? null : $capturedParts[array_key_last($capturedParts)];
            if ( isset($visualGroup['group']) && array() === array_diff($visualGroup['group']['part_ids'], array_column($visualParts, 'id')) ) {
                $visualGroups[] = $visualGroup['group'];
                foreach ( $visualGroup['group_variants'] as $variant ) {
                    if ( count($variants) >= self::MAX_VARIANTS ) {
                        $this->truncated = true;
                        $this->diagnostics[] = 'variant_limit';
                        break 2;
                    }
                    $variants[] = $variant;
                }
            }
            if ( count($row) > 1 ) {
                $controls[] = $row;
            }
        }

        $captures = array();
        $seenCaptionIndexes = array();
        foreach ( $groupCaptions['captions'] as $captionCandidates ) {
            if ( count($captures) >= self::MAX_CONTROLS ) {
                $this->truncated = true;
                $this->diagnostics[] = 'group_caption_limit';
                break;
            }
            $captionRow = $this->captionRow($captionCandidates, $groupCaptions['index_by_path'], $analysis['rules'], $customPropertyAnalysis['rules']);
            if ( null !== $captionRow && ! isset($seenCaptionIndexes[$captionRow['index']]) ) {
                $seenCaptionIndexes[$captionRow['index']] = true;
                $captures[] = $captionRow;
            }
        }

        $controlRows = $this->controlRows($form, $analysis['rules'], $customPropertyAnalysis['rules'], $variants);
        $diagnostics = array_values(array_unique(array_merge($this->diagnostics, $this->stylesheetDiagnostics())));
        // Unsupported selectors can fill the diagnostic budget before the
        // actual bound that made this graph unsafe to publish. Keep that cause
        // visible so a dropped provider presentation graph is actionable.
        $bounds = array_values(array_filter($diagnostics, static fn (string $diagnostic): bool => str_ends_with($diagnostic, '_limit') || str_contains($diagnostic, '_truncated:')));
        $graph = array(
            'schema' => array() === $visualParts && ! $hasRequiredMarker && ! $hasCaptionLayer && array() === $controlContainers && array() === $captures ? 'generic/computed-form-presentation/v1' : 'generic/computed-form-presentation/v2',
            'basis' => 'source_css_cascade',
            'truncated' => $this->truncated,
            'limits' => array( 'controls' => self::MAX_CONTROLS, 'rules_per_role' => self::MAX_RULES_PER_ROLE ),
            'controls' => $controls,
            'variants' => $variants,
            'diagnostics' => array_slice(array_values(array_unique(array_merge($bounds, $diagnostics))), 0, self::MAX_DIAGNOSTICS),
        );
        if ( 'generic/computed-form-presentation/v2' === $graph['schema'] ) {
            $graph['visual_parts'] = $visualParts;
            $graph['visual_groups'] = $visualGroups;
            $graph['control_containers'] = $controlContainers;
            $graph['group_captions'] = $captures;
        }
        if ($controlRows) {
            $graph['schema'] = 'generic/computed-form-presentation/v3';
            $graph += array('visual_parts' => $visualParts, 'visual_groups' => $visualGroups, 'control_containers' => $controlContainers, 'group_captions' => $captures);
            $graph['control_rows'] = $controlRows;
        }
        self::assertValid($graph);
        return $graph;
    }

    /** @param array<string, mixed> $graph */
    public static function assertValid(array $graph): void
    {
        $version = $graph['schema'] ?? null;
        $v1 = 'generic/computed-form-presentation/v1' === $version;
        $v3 = 'generic/computed-form-presentation/v3' === $version;
        $v2 = $v3 || 'generic/computed-form-presentation/v2' === $version;
        $expectedKeys = $v2 ? array( 'schema', 'basis', 'truncated', 'limits', 'controls', 'visual_parts', 'visual_groups', 'control_containers', 'group_captions', 'variants', 'diagnostics' ) : array( 'schema', 'basis', 'truncated', 'limits', 'controls', 'variants', 'diagnostics' );
        if ($v3) $expectedKeys[] = 'control_rows';
        if ( (! $v1 && ! $v2) || 'source_css_cascade' !== ($graph['basis'] ?? null) || ! is_bool($graph['truncated'] ?? null) || ! is_array($graph['limits'] ?? null) || array_diff(array_keys($graph['limits']), array( 'controls', 'rules_per_role' )) || self::MAX_CONTROLS !== ($graph['limits']['controls'] ?? null) || self::MAX_RULES_PER_ROLE !== ($graph['limits']['rules_per_role'] ?? null) || ! is_array($graph['controls'] ?? null) || ! array_is_list($graph['controls']) || count($graph['controls']) > self::MAX_CONTROLS || ($v2 && (! is_array($graph['visual_parts'] ?? null) || ! array_is_list($graph['visual_parts']) || count($graph['visual_parts']) > self::MAX_VISUAL_PARTS || ! is_array($graph['visual_groups'] ?? null) || ! array_is_list($graph['visual_groups']) || count($graph['visual_groups']) > self::MAX_VISUAL_PARTS || ! is_array($graph['control_containers'] ?? null) || ! array_is_list($graph['control_containers']) || count($graph['control_containers']) > self::MAX_CONTROLS || ! is_array($graph['group_captions'] ?? null) || ! array_is_list($graph['group_captions']) || count($graph['group_captions']) > self::MAX_CONTROLS)) || ! is_array($graph['variants'] ?? null) || ! array_is_list($graph['variants']) || count($graph['variants']) > self::MAX_VARIANTS || ! is_array($graph['diagnostics'] ?? null) || ! array_is_list($graph['diagnostics']) || count(array_unique($graph['diagnostics'], SORT_REGULAR)) !== count($graph['diagnostics']) || count($graph['diagnostics']) > self::MAX_DIAGNOSTICS || array_filter($graph['diagnostics'], static fn (mixed $diagnostic): bool => ! is_string($diagnostic) || '' === trim($diagnostic) || strlen($diagnostic) > 1100) || array_diff(array_keys($graph), $expectedKeys) ) {
            throw new InvalidArgumentException('Form presentation graph envelope is invalid.');
        }
        $rowOwners = $v3 ? self::assertControlRows($graph['control_rows'] ?? null) : array();
        $containerIndexes = array();
        foreach ( $v2 ? $graph['control_containers'] : array() as $container ) {
            if ( ! is_array($container) || array_diff(array_keys($container), array( 'index', 'source_selector', 'styles', 'provenance' )) || ! is_int($container['index'] ?? null) || $container['index'] < 0 || $container['index'] >= self::MAX_CONTROLS || isset($containerIndexes[$container['index']]) || ! is_string($container['source_selector'] ?? null) || '' === trim($container['source_selector']) || strlen($container['source_selector']) > 2048 || ! is_array($container['styles'] ?? null) || array_diff(array_keys($container['styles']), array_map(self::key(...), self::CONTROL_CONTAINER_PROPERTIES)) || ! is_array($container['provenance'] ?? null) ) throw new InvalidArgumentException('Form presentation control container is invalid.');
            self::assertStyles($container['styles']); self::assertProvenance($container['provenance'], $container['styles'], null);
            $containerIndexes[$container['index']] = array() !== $container['styles'];
        }
        $captionIndexes = array();
        $captionLayers = array();
        foreach ( $v2 ? $graph['group_captions'] : array() as $caption ) {
            if ( ! is_array($caption) || array_diff(array_keys($caption), array( 'index', 'styles', 'provenance' )) || ! is_int($caption['index'] ?? null) || $caption['index'] < 0 || $caption['index'] >= self::MAX_CONTROLS || isset($captionIndexes[$caption['index']]) || ! is_array($caption['styles'] ?? null) || array() === $caption['styles'] || array_diff(array_keys($caption['styles']), array_map(self::key(...), self::GROUP_CAPTION_PROPERTIES)) || ! is_array($caption['provenance'] ?? null) ) throw new InvalidArgumentException('Form presentation group caption is invalid.');
            self::assertStyles($caption['styles']); self::assertProvenance($caption['provenance'], $caption['styles'], null);
            $captionIndexes[$caption['index']] = true;
        }
        $seen = array();
        foreach ( $graph['controls'] as $row ) {
            if ( ! is_array($row) || array_diff(array_keys($row), $v2 ? array( 'index', 'control', 'label', 'required_marker', 'caption' ) : array( 'index', 'control', 'label' )) || ! is_int($row['index'] ?? null) || $row['index'] < 0 || $row['index'] >= self::MAX_CONTROLS || isset($seen[$row['index']]) || (! isset($row['control']) && ! isset($row['label']) && ! isset($row['required_marker']) && ! isset($row['caption'])) ) {
                throw new InvalidArgumentException('Form presentation control is invalid.');
            }
            $seen[$row['index']] = true;
            foreach ( $v2 ? array( 'control', 'label', 'required_marker' ) : array( 'control', 'label' ) as $role ) {
                if ( isset($row[$role]) ) self::assertRole($row[$role], null, 'required_marker' === $role);
            }
            if ( isset($row['caption']) ) {
                $captionLayers[$row['index']] = true;
                self::assertRole($row['caption'], null);
                self::assertStyles($row['caption']['styles'], self::CAPTION_LAYER_PROPERTIES);
            }
        }
        $partIds = array();
        foreach ( $v2 ? $graph['visual_parts'] : array() as $part ) {
            self::assertVisualPart($part);
            if ( isset($partIds[$part['id']]) ) throw new InvalidArgumentException('Form presentation visual part identity is duplicated.');
            $partIds[$part['id']] = $part['index'];
        }
        $groupIds = array();
        foreach ( $v2 ? $graph['visual_groups'] : array() as $group ) {
            self::assertVisualGroup($group, $partIds);
            if ( isset($groupIds[$group['id']]) ) throw new InvalidArgumentException('Form presentation visual group identity is duplicated.');
            $groupIds[$group['id']] = true;
        }
        $rowLayouts = array();
        foreach ($v3 ? $graph['control_rows'] : array() as $row) {
            if ('stacked' === ($row['flow'] ?? null) || in_array($row['styles']['display'] ?? '', array('flex', 'inline-flex', 'grid', 'inline-grid'), true)) $rowLayouts[$row['index']] = true;
        }
        $containerVariants = array();
        foreach ( $graph['variants'] as $variant ) {
            if (is_array($variant) && in_array($variant['role'] ?? null, array('control_row', 'control_row_item'), true)) {
                self::assertRowVariant($variant, $rowOwners);
                if ('control_row' === $variant['role'] && in_array($variant['style_patch']['display'] ?? '', array('flex', 'inline-flex', 'grid', 'inline-grid'), true)) $rowLayouts[$variant['index']] = true;
                continue;
            }
            $isVisualPart = 'visual_part' === ($variant['role'] ?? null); $isVisualGroup = 'visual_group' === ($variant['role'] ?? null);
            $keys = $isVisualPart ? array( 'index', 'role', 'part_id', 'condition', 'style_patch', 'precedence', 'provenance' ) : ($isVisualGroup ? array( 'role', 'group_id', 'condition', 'style_patch', 'precedence', 'provenance' ) : array( 'index', 'role', 'condition', 'style_patch', 'precedence', 'provenance' ));
            if ( ! is_array($variant) || array_diff(array_keys($variant), $keys) || (! $isVisualGroup && (! is_int($variant['index'] ?? null) || $variant['index'] < 0 || $variant['index'] >= self::MAX_CONTROLS)) || ! in_array($variant['role'] ?? null, $v2 ? array( 'control', 'label', 'required_marker', 'caption', 'control_container', 'visual_part', 'visual_group' ) : array( 'control', 'label' ), true) || ($isVisualPart ? (! is_string($variant['part_id'] ?? null) || ! isset($partIds[$variant['part_id']]) || $variant['index'] !== $partIds[$variant['part_id']]) : ($isVisualGroup ? (! is_string($variant['group_id'] ?? null) || ! isset($groupIds[$variant['group_id']])) : (isset($variant['part_id']) || isset($variant['group_id'])))) || ! is_array($variant['condition'] ?? null) || ! self::validCondition($variant['condition']) || ! is_array($variant['style_patch'] ?? null) || array() === $variant['style_patch'] || ! is_array($variant['precedence'] ?? null) || ! is_array($variant['provenance'] ?? null) ) {
                throw new InvalidArgumentException('Form presentation variant is invalid.');
            }
            self::assertStyles($variant['style_patch'], 'caption' === $variant['role'] ? self::CAPTION_LAYER_PROPERTIES : self::PROPERTIES);
            if ( 'caption' === $variant['role'] && ! isset($captionLayers[$variant['index']]) ) throw new InvalidArgumentException('Form presentation caption variant has no caption layer.');
            if ( 'control_container' === $variant['role'] ) $containerVariants[$variant['index']] = true;
            foreach ( $variant['precedence'] as $property => $precedence ) {
                if ( ! in_array($property, self::PROPERTIES, true) || ! isset($variant['style_patch'][self::key($property)]) || ! is_array($precedence) || ! is_int($precedence['source_order'] ?? null) || ! is_int($precedence['specificity'] ?? null) || ! is_bool($precedence['important'] ?? null) ) throw new InvalidArgumentException('Form presentation precedence is invalid.');
            }
            self::assertProvenance($variant['provenance'], $variant['style_patch'], $variant['condition']);
        }
        if (array_diff_key($rowOwners, $rowLayouts)) throw new InvalidArgumentException('Form control row has no layout evidence.');
        foreach ( $containerIndexes as $index => $hasBaseStyles ) if (! $hasBaseStyles && ! isset($containerVariants[$index])) throw new InvalidArgumentException('Form presentation conditional control container has no variants.');
    }

    private static function assertRole(mixed $role, ?array $condition, bool $allowEmpty = false): void
    {
        if ( ! is_array($role) || count($role) !== 2 || array_diff(array_keys($role), array( 'styles', 'provenance' )) || ! is_array($role['styles'] ?? null) || (! $allowEmpty && array() === $role['styles']) || ! is_array($role['provenance'] ?? null) ) throw new InvalidArgumentException('Form presentation role is invalid.');
        self::assertStyles($role['styles']);
        self::assertProvenance($role['provenance'], $role['styles'], $condition);
    }

    /**
     * The paint a button's sole caption carrier draws behind the caption, when
     * it paints one. The control keeps its own paint; this layer sits inside the
     * control's padding box, as the carrier does in the source. Conditions that
     * repaint the carrier, such as a breakpoint clearing its background, are
     * caption variants of the same layer.
     *
     * @param list<array<string, mixed>> $rules
     * @param list<array<string, mixed>> $customPropertyRules
     * @return array{styles: array<string, string>, provenance: list<array<string, mixed>>, variants: list<array<string, mixed>>}|null
     */
    private function captionLayer(DOMElement $control, array $rules, array $customPropertyRules): ?array
    {
        $carrier = self::soleTextCarrier($control);
        if ( null === $carrier || $carrier->isSameNode($control) ) {
            return null;
        }
        $matched = $this->matched($carrier, $rules);
        $facts = array_intersect_key($matched['base'], array_flip(self::CAPTION_LAYER_PROPERTIES));
        if ( array() === array_intersect_key($facts, array_flip(array( 'background', 'background-color', 'background-image' ))) ) {
            return null;
        }
        $styles = $this->styles($facts, $carrier, null, $customPropertyRules, true);
        if ( array() === $styles ) {
            return null;
        }
        $variants = array();
        foreach ( $this->effectiveConditional($matched['conditional'], $matched['base']) as $encoded => $conditionalFacts ) {
            $condition = json_decode($encoded, true);
            $conditionalFacts = array_intersect_key($conditionalFacts, array_flip(self::CAPTION_LAYER_PROPERTIES));
            $patch = $this->styles($conditionalFacts, $carrier, $condition, $customPropertyRules, true);
            if ( array() !== $patch ) {
                $variants[] = array( 'condition' => $condition, 'style_patch' => $patch, 'precedence' => $this->precedence($conditionalFacts), 'provenance' => $this->provenance($conditionalFacts, $condition) );
            }
        }
        return array( 'styles' => $styles, 'provenance' => $this->provenance($facts, null), 'variants' => $variants );
    }

    /** Carrier typography wins per property: overridden provenance is pruned, then its styles and provenance merge in. */
    private function withCarriedTypography(array $styles, array $provenance, array $carried): array
    {
        $overridden = array_map(static fn (string $key): string => str_replace('_', '-', $key), array_keys(array_intersect_key($styles, $carried['styles'])));
        foreach ( $provenance as $factIndex => $fact ) {
            $fact['properties'] = array_values(array_diff($fact['properties'], $overridden));
            if ( array() === $fact['properties'] ) {
                unset($provenance[$factIndex]);
            } else {
                $provenance[$factIndex] = $fact;
            }
        }
        $provenance = array_values($provenance);
        $styles = array_merge($styles, $carried['styles']);
        ksort($styles);
        $provenance = array_merge($provenance, $carried['provenance']);
        if ( count($provenance) > self::MAX_PROVENANCE ) {
            $provenance = array_slice($provenance, 0, self::MAX_PROVENANCE);
            $this->truncated = true;
            $this->diagnostics[] = 'provenance_limit';
        }
        return array($styles, $provenance);
    }

    private function carrierTypography(array $roles, DOMElement $label, array $rules, array $customPropertyRules, array $labelConditional = array(), bool $captionBox = false): array
    {
        $properties = array_flip(self::TYPOGRAPHY_PROPERTIES);
        $excluded = array_values(array_filter(
            array( $roles['control'] ?? null, $roles['required_marker'] ?? null ),
            static fn (mixed $element): bool => $element instanceof DOMElement
        ));
        $carrier = self::soleTextCarrier($label, $excluded);
        $chain = array();
        $conditions = array_fill_keys(array_keys($labelConditional), true);
        for ( $element = $carrier; $element instanceof DOMElement && ! $element->isSameNode($label); $element = $element->parentNode instanceof DOMElement ? $element->parentNode : null ) {
            $matched = $this->matched($element, $rules);
            $carried = $properties;
            // The caption carrier's own padding sizes an unpadded button only
            // when the carrier is block-level; inline padding leaves the line box.
            if ( $captionBox && $element->isSameNode($carrier) && self::isBlockLevelDisplay((string) ($matched['base']['display']['value'] ?? '')) ) {
                $carried += array_flip(self::CAPTION_BOX_PROPERTIES);
            }
            $base = array_intersect_key($matched['base'], $carried);
            $conditional = $this->effectiveConditional($matched['conditional'], $matched['base']);
            foreach ($base as $property => $fact) {
                foreach ($this->customProperties->conditionsChanging($fact['value'], $element, $customPropertyRules, true) as $condition) $conditional[json_encode($condition)][$property] ??= $fact;
            }
            foreach ( $conditional as $encoded => &$facts ) {
                $facts = array_intersect_key($facts, $carried);
                if ( array() !== $facts ) $conditions[$encoded] = true;
            }
            unset($facts);
            $chain[] = array( 'element' => $element, 'base' => $base, 'conditional' => $conditional );
        }
        $resolve = function (?array $condition) use ($chain, $customPropertyRules): array {
            $styles = array(); $provenance = array(); $precedence = array();
            $encoded = null === $condition ? '' : json_encode($condition);
            foreach ( $chain as $row ) {
                $facts = array_diff_key(
                    array_replace($row['base'], $row['conditional'][$encoded] ?? array()),
                    array_flip(array_map(static fn (string $key): string => str_replace('_', '-', $key), array_keys($styles)))
                );
                $styles += $this->styles($facts, $row['element'], $condition, $customPropertyRules, null === $condition);
                $provenance = array_merge($provenance, $this->provenance($facts, $condition));
                $precedence += $this->precedence($facts);
            }
            ksort($styles); ksort($precedence);
            return array( 'styles' => $styles, 'provenance' => $provenance, 'precedence' => $precedence );
        };
        $result = $resolve(null);
        $result['conditional'] = array();
        if ( count($conditions) > self::MAX_VARIANTS ) {
            $this->truncated = true;
            $this->diagnostics[] = 'variant_limit';
        }
        foreach ( array_slice(array_keys($conditions), 0, self::MAX_VARIANTS) as $encoded ) {
            $patch = $resolve(json_decode($encoded, true));
            if ( array() !== $patch['styles'] ) $result['conditional'][$encoded] = $patch;
        }
        return array() === $result['styles'] && array() === $result['conditional'] ? array() : $result;
    }

    /**
     * A provider field flattens the exclusive label/control wrapper. Carry that
     * wrapper's authored gap onto the label as margin-block-end so the Jetpack
     * field shell keeps the intra-field spacing.
     *
     * @param array<string, mixed> $row
     * @param list<DOMElement> $ancestors
     * @param list<array<string, mixed>> $rules
     * @param list<array<string, mixed>> $customPropertyRules
     */
    private static function isBlockLevelDisplay(string $display): bool
    {
        return in_array(CssValueInspector::comparable($display), array( 'block', 'inline-block', 'flex', 'inline-flex', 'grid', 'inline-grid', 'flow-root' ), true);
    }

    /** @param array<string, string> $styles */
    private function hasPadding(array $styles): bool
    {
        foreach ( $styles as $key => $value ) {
            if ( str_starts_with($key, 'padding') && 1 !== preg_match('/^(?:0(?:\.0+)?(?:px|em|rem|%)?\s*)+$/', trim($value)) ) return true;
        }
        return false;
    }

    private function carryFieldGroupGapOntoLabel(array &$row, DOMElement $control, array $ancestors, array $rules, array $customPropertyRules): void
    {
        if ( isset($row['label']['styles']['margin_block_end']) || isset($row['label']['styles']['margin_bottom']) ) {
            return;
        }
        if ( ! FormControlClassifier::isDataEntryControl($control) || ! FormControlLabel::element($control) instanceof DOMElement ) {
            return;
        }
        $parent = $control->parentNode;
        if ( ! $parent instanceof DOMElement || $parent->isSameNode(FormControlLabel::element($control)) ) {
            return;
        }
        $wrapper = null;
        foreach ( $ancestors as $ancestor ) {
            if ( $ancestor->isSameNode($parent) ) {
                $wrapper = $ancestor;
                break;
            }
        }
        if ( ! $wrapper instanceof DOMElement ) {
            return;
        }
        $matched = $this->matched($wrapper, $rules);
        $layoutFacts = array_intersect_key($matched['base'], array_flip(array( 'display', 'flex-direction' )));
        $layoutStyles = $this->styles($layoutFacts, $wrapper, null, $customPropertyRules);
        $display = CssValueInspector::comparable($layoutStyles['display'] ?? '');
        $direction = CssValueInspector::comparable($layoutStyles['flex_direction'] ?? 'row');
        // Only a grid row gap or a column flex gap spaces the label above its
        // control; a row flex gap is inline spacing and block flow has no gap.
        $stacksBlockAxis = in_array($display, array( 'grid', 'inline-grid' ), true)
            || ( in_array($display, array( 'flex', 'inline-flex' ), true) && in_array($direction, array( 'column', 'column-reverse' ), true) );
        if ( ! $stacksBlockAxis ) {
            return;
        }
        $gapFacts = array_intersect_key($matched['base'], array_flip(array( 'gap' )));
        $gapStyles = $this->styles($gapFacts, $wrapper, null, $customPropertyRules);
        $gap = $gapStyles['gap'] ?? null;
        if ( ! is_string($gap) || '' === trim($gap) ) {
            return;
        }
        // The first gap component is the row gap; preserve whitespace in calc().
        $gap = CssValueSplitter::splitTopLevelWhitespace($gap)[0] ?? '';
        $gap = preg_replace('/(?<![0-9])\.(\d+)/', '0.$1', $gap) ?? $gap;
        $facts = array();
        foreach ( $gapFacts as $fact ) {
            $facts['margin-block-end'] = $fact;
        }
        if ( array() === $facts ) {
            return;
        }
        $styles = is_array($row['label']['styles'] ?? null) ? $row['label']['styles'] : array();
        $styles['margin_block_end'] = $gap;
        ksort($styles);
        $provenance = is_array($row['label']['provenance'] ?? null) ? $row['label']['provenance'] : array();
        $row['label'] = array(
            'styles' => $styles,
            'provenance' => array_merge($provenance, $this->provenance($facts, null)),
        );
        if ( count($row['label']['provenance']) > self::MAX_PROVENANCE ) {
            $row['label']['provenance'] = array_slice($row['label']['provenance'], 0, self::MAX_PROVENANCE);
            $this->truncated = true;
            $this->diagnostics[] = 'provenance_limit';
        }
    }

    /** Nearest shared ancestor per control pair, never a site wrapper outside the form. */
    private function controlRows(DOMElement $form, array $rules, array $customRules, array &$variants): array
    {
        $candidates = array();
        foreach (array_slice($this->controls($form), 0, self::MAX_CONTROLS) as $index => $control) {
            if (!str_starts_with($control->getNodePath(), $form->getNodePath() . '/')) continue;
            $item = $control;
            for ($depth = 0, $parent = $item->parentNode; $parent instanceof DOMElement && $depth < self::MAX_ROW_ANCESTORS; ++$depth, $item = $parent, $parent = $parent->parentNode) {
                $path = $parent->getNodePath();
                $candidates[$path]['element'] = $parent;
                $candidates[$path]['members'][$index] = $item;
                if ($parent->isSameNode($form)) break;
            }
            if ($parent instanceof DOMElement && !$parent->isSameNode($form) && $depth >= self::MAX_ROW_ANCESTORS) {
                $this->truncated = true;
                $this->diagnostics[] = 'control_row_ancestor_limit';
            }
        }
        $rows = array();
        $nearest = array();
        foreach ($candidates as $candidate) {
            $members = $candidate['members'];
            if (count($members) < 2) continue;
            // A shared ancestor is nearest only when controls occupy distinct children.
            $paths = array_map(static fn (DOMElement $item): string => $item->getNodePath(), $members);
            if (count(array_unique($paths)) < 2) continue;
            // Candidates arrive innermost first; only the nearest ancestor of a control set can stack it.
            $set = implode(',', array_keys($members));
            $isNearest = !isset($nearest[$set]);
            $nearest[$set] = true;
            $element = $candidate['element'];
            foreach (array_merge(array($element), array_values($members)) as $source) {
                if (strlen(SourceDom::elementSelector($source)) > 2048) { $this->truncated = true; $this->diagnostics[] = 'control_row_selector_limit'; continue 2; }
            }
            $matched = $this->matched($element, $rules, true);
            $display = $this->styles(array_intersect_key($matched['base'], array('display' => true)), $element, null, $customRules);
            $layout = in_array($display['display'] ?? '', array('flex', 'inline-flex', 'grid', 'inline-grid'), true);
            foreach ($this->effectiveConditional($matched['conditional'], $matched['base']) as $encoded => $facts) {
                $patch = $this->styles(array_intersect_key($facts, array('display' => true)), $element, json_decode($encoded, true), $customRules);
                $layout = $layout || in_array($patch['display'] ?? '', array('flex', 'inline-flex', 'grid', 'inline-grid'), true);
            }
            // A <form>'s own flow is the default field stacking every consumer renders; wrappers make it a fact.
            $stacked = !$layout && $isNearest && 'form' !== strtolower($element->tagName) && $this->stacksInBlockFlow($element, $members, $rules, $customRules);
            if (!$layout && !$stacked) continue;
            if (count($rows) >= self::MAX_CONTROLS) { $this->truncated = true; $this->diagnostics[] = 'control_row_limit'; break; }
            $rowIndex = count($rows);
            $row = array('index' => $rowIndex) + $this->rowRole($element, self::ROW_PROPERTIES, 'control_row', $rowIndex, null, $rules, $customRules, $variants);
            if ($stacked) $row['flow'] = 'stacked';
            $row['members'] = array();
            foreach ($members as $index => $item) {
                $row['members'][] = array('index' => $index) + $this->rowRole($item, self::ROW_ITEM_PROPERTIES, 'control_row_item', $rowIndex, $index, $rules, $customRules, $variants);
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * A block container lays its children out line by line: one block-level
     * member breaks the shared line, so the controls are stacked.
     *
     * @param array<int, DOMElement> $members
     */
    private function stacksInBlockFlow(DOMElement $element, array $members, array $rules, array $customRules): bool
    {
        $own = $this->styles(array_intersect_key($this->matched($element, $rules, true)['base'], array('display' => true)), $element, null, $customRules)['display'] ?? null;
        if (null !== $own && !in_array($own, array('block', 'flow-root', 'list-item'), true)) return false;
        foreach ($members as $item) {
            $display = $this->styles(array_intersect_key($this->matched($item, $rules, true)['base'], array('display' => true)), $item, null, $customRules)['display'] ?? null;
            if (null === $display ? in_array(strtolower($item->tagName), self::BLOCK_FLOW_TAGS, true) : in_array($display, array('block', 'flex', 'grid', 'flow-root', 'list-item', 'table'), true)) return true;
        }
        return false;
    }

    private function rowRole(DOMElement $element, array $properties, string $role, int $rowIndex, ?int $memberIndex, array $rules, array $customRules, array &$variants): array
    {
        $matched = $this->matched($element, $rules, true);
        $base = array_intersect_key($matched['base'], array_flip($properties));
        $conditional = $this->effectiveConditional($matched['conditional'], $matched['base']);
        foreach ($base as $property => $fact) {
            foreach ($this->customProperties->conditionsChanging($fact['value'], $element, $customRules, true) as $condition) $conditional[json_encode($condition)][$property] ??= $fact;
        }
        foreach ($conditional as $encoded => $facts) {
            $condition = json_decode($encoded, true);
            $facts = array_intersect_key($facts, array_flip($properties));
            $patch = $this->styles($facts, $element, $condition, $customRules);
            if (!$patch) continue;
            if (count($variants) >= self::MAX_VARIANTS) { $this->truncated = true; $this->diagnostics[] = 'variant_limit'; break; }
            $variant = array('index' => $rowIndex, 'role' => $role, 'condition' => $condition, 'style_patch' => $patch, 'precedence' => $this->precedence($facts), 'provenance' => $this->provenance($facts, $condition));
            if (null !== $memberIndex) $variant['member_index'] = $memberIndex;
            $variants[] = $variant;
        }
        return array('source_selector' => SourceDom::elementSelector($element), 'styles' => $this->styles($base, $element, null, $customRules), 'provenance' => $this->provenance($base, null));
    }

    private static function assertRowStyles(array $styles, array $properties): void
    {
        if (array_diff(array_keys($styles), array_map(self::key(...), $properties))) throw new InvalidArgumentException('Form control row property is invalid.');
        self::assertStyles($styles, array_merge(self::PROPERTIES, self::ROW_ONLY_PROPERTIES));
    }

    private static function assertRowFact(mixed $fact, array $keys, array $properties): void
    {
        if (!is_array($fact) || array_diff(array_keys($fact), $keys) || array_diff($keys, array_keys($fact)) || !is_int($fact['index']) || $fact['index'] < 0 || $fact['index'] >= self::MAX_CONTROLS || !is_string($fact['source_selector']) || '' === trim($fact['source_selector']) || strlen($fact['source_selector']) > 2048 || !is_array($fact['styles']) || !is_array($fact['provenance'])) throw new InvalidArgumentException('Form control row fact is invalid.');
        self::assertRowStyles($fact['styles'], $properties);
        self::assertProvenance($fact['provenance'], $fact['styles'], null, array_merge(self::PROPERTIES, self::ROW_ONLY_PROPERTIES));
    }

    private static function assertControlRows(mixed $rows): array
    {
        if (!is_array($rows) || !array_is_list($rows) || !$rows || count($rows) > self::MAX_CONTROLS) throw new InvalidArgumentException('Form control rows are invalid.');
        $owners = array();
        foreach ($rows as $row) {
            $stacked = is_array($row) && array_key_exists('flow', $row);
            if ($stacked && 'stacked' !== $row['flow']) throw new InvalidArgumentException('Form control row flow is invalid.');
            self::assertRowFact($row, array_merge(array('index', 'source_selector', 'styles', 'provenance', 'members'), $stacked ? array('flow') : array()), self::ROW_PROPERTIES);
            if (isset($owners[$row['index']]) || !is_array($row['members']) || !array_is_list($row['members']) || count($row['members']) < 2 || count($row['members']) > self::MAX_CONTROLS) throw new InvalidArgumentException('Form control row members are invalid.');
            $owners[$row['index']] = array();
            foreach ($row['members'] as $member) {
                self::assertRowFact($member, array('index', 'source_selector', 'styles', 'provenance'), self::ROW_ITEM_PROPERTIES);
                if (isset($owners[$row['index']][$member['index']])) throw new InvalidArgumentException('Form control row member is duplicated.');
                $owners[$row['index']][$member['index']] = true;
            }
        }
        return $owners;
    }

    private static function assertRowVariant(array $variant, array $owners): void
    {
        $item = 'control_row_item' === $variant['role'];
        $keys = array('index', 'role', 'condition', 'style_patch', 'precedence', 'provenance');
        if ($item) $keys[] = 'member_index';
        if (array_diff(array_keys($variant), $keys) || array_diff($keys, array_keys($variant)) || !is_int($variant['index']) || !isset($owners[$variant['index']]) || ($item && (!is_int($variant['member_index']) || !isset($owners[$variant['index']][$variant['member_index']]))) || !is_array($variant['condition']) || !self::validCondition($variant['condition']) || !is_array($variant['style_patch']) || !$variant['style_patch'] || !is_array($variant['precedence']) || !is_array($variant['provenance'])) throw new InvalidArgumentException('Form control row variant is invalid.');
        self::assertRowStyles($variant['style_patch'], $item ? self::ROW_ITEM_PROPERTIES : self::ROW_PROPERTIES);
        foreach ($variant['precedence'] as $property => $rank) {
            if (!in_array($property, $item ? self::ROW_ITEM_PROPERTIES : self::ROW_PROPERTIES, true) || !isset($variant['style_patch'][self::key($property)]) || !is_array($rank) || array_diff(array_keys($rank), array('source_order', 'specificity', 'important')) || !is_int($rank['source_order'] ?? null) || !is_int($rank['specificity'] ?? null) || !is_bool($rank['important'] ?? null)) throw new InvalidArgumentException('Form control row precedence is invalid.');
        }
        self::assertProvenance($variant['provenance'], $variant['style_patch'], $variant['condition'], array_merge(self::PROPERTIES, self::ROW_ONLY_PROPERTIES));
    }

    /** @param list<DOMElement> $ancestors @return array{container:array<string,mixed>,variants:list<array<string,mixed>}|null */
    private function controlContainer(int $index, array $ancestors, array $rules, array $customPropertyRules): ?array
    {
        $painted = array();
        $properties = array_flip(self::CONTROL_CONTAINER_PROPERTIES);
        foreach ( $ancestors as $ancestor ) {
            $matched = $this->matched($ancestor, $rules);
            $facts = array_intersect_key($matched['base'], $properties);
            $styles = $this->styles($facts, $ancestor, null, $customPropertyRules);
            $variants = array();
            foreach ( $this->effectiveConditional($matched['conditional'], $matched['base']) as $encoded => $conditionalFacts ) {
                $condition = json_decode($encoded, true); $conditionalFacts = array_intersect_key($conditionalFacts, $properties);
                $patch = $this->styles($conditionalFacts, $ancestor, $condition, $customPropertyRules);
                if ($this->hasContainerPaint($patch)) $variants[] = array( 'index' => $index, 'role' => 'control_container', 'condition' => $condition, 'style_patch' => $patch, 'precedence' => $this->precedence($conditionalFacts), 'provenance' => $this->provenance($conditionalFacts, $condition) );
            }
            if ($this->hasContainerPaint($styles) || array() !== $variants) $painted[] = array( 'element' => $ancestor, 'facts' => $this->hasContainerPaint($styles) ? $facts : array(), 'matched' => $matched, 'styles' => $this->hasContainerPaint($styles) ? $styles : array(), 'variants' => $variants );
        }
        if ( count($painted) > 1 ) { $this->diagnostics[] = 'control_container_multiple_painted_wrappers'; return null; }
        if ( array() === $painted ) return null;
        $paint = $painted[0];
        $container = array( 'index' => $index, 'source_selector' => SourceDom::elementSelector($paint['element']), 'styles' => $paint['styles'], 'provenance' => $this->provenance($paint['facts'], null) );
        return array( 'container' => $container, 'variants' => $paint['variants'] );
    }

    /**
     * Every ARIA choice group wrapper in the form that has a visible caption
     * element, with the flat controls each group needs for its caption row.
     *
     * Caption discovery is structural: an aria-labelledby target inside the
     * form that is static copy, else the nearest preceding sibling of the group
     * or of up to three of its ancestors whose text matches the group's
     * aria-label and which holds no form controls. No caption means no fact.
     *
     * @param DOMElement $form @return array{captions: list<array{group: DOMElement, caption: DOMElement}>, elements: list<DOMElement>, index_by_path: array<string, int>}
     */
    private function captionElements(DOMElement $form): array
    {
        $controlIndexes = array();
        foreach ( $this->controls($form) as $index => $control ) {
            $controlIndexes[$control->getNodePath()] = $index;
        }
        $captions = array();
        $elements = array();
        $claimed = array();
        foreach ( $form->getElementsByTagName('*') as $element ) {
            if ( ! $element instanceof DOMElement ) continue;
            if ( ! in_array(SourceDom::role($element), self::GROUP_ROLES, true) ) continue;
            if ( FormControlClassifier::isControlElement($element) || $this->inertAncestor($element, $form) ) continue;
            $caption = $this->captionElement($element, $form);
            if ( null === $caption || isset($claimed[$caption->getNodePath()]) ) continue;
            $claimed[$caption->getNodePath()] = true;
            $captions[] = array( 'group' => $element, 'caption' => $caption );
            $elements[] = $caption;
        }
        return array( 'captions' => $captions, 'elements' => $elements, 'index_by_path' => $controlIndexes );
    }

    /**
     * One caption row: the flat index of the group's first native radio or
     * checkbox input member, with the caption's own resolved typography plus
     * the same text-carrier typography the label role reads, so the element a
     * source paints the caption text on wins property by property.
     *
     * Base-only: conditional caption facts are not reported.
     *
     * @param list<array<string, mixed>> $rules @param list<array<string, mixed>> $customPropertyRules
     * @param array<string, int> $controlIndexes
     * @return array{index:int,styles:array<string,string>,provenance:list<array<string,mixed>>}|null
     */
    private function captionRow(array $candidates, array $controlIndexes, array $rules, array $customPropertyRules): ?array
    {
        $properties = array_flip(self::GROUP_CAPTION_PROPERTIES);
        $caption = $candidates['caption'];
        $firstIndex = $this->firstChoiceIndex($candidates['group'], $controlIndexes);
        if ( ! is_int($firstIndex) || $firstIndex < 0 || $firstIndex >= self::MAX_CONTROLS ) return null;
        $matched = $this->matched($caption, $rules);
        $base = array_intersect_key($matched['base'], $properties);
        $styles = $this->styles($base, $caption, null, $customPropertyRules, true);
        $provenance = $this->provenance($base, null);
        $carried = $this->carrierTypography(array(), $caption, $rules, $customPropertyRules);
        if ( array() !== $carried ) {
            [$styles, $provenance] = $this->withCarriedTypography($styles, $provenance, $carried);
        }
        if ( array() === $styles ) return null;

        return array( 'index' => $firstIndex, 'styles' => $styles, 'provenance' => $provenance );
    }

    /**
     * A wrapper with an ARIA group role is a named group of controls in a
     * fieldset-like sense. Its caption travels with the group in the control
     * topology, but nobody reported the visible caption's own typography, so a
     * rendered legend keeps provider defaults. The caption element is the
     * visible element the source paints that text on: the first aria-labelledby
     * target inside the form, else the nearest preceding sibling of the group
     * or of an ancestor up to three wrapper levels whose normalized text is the
     * group's aria-label. Only static copy qualifies: a candidate holding form
     * controls is a field host, not a caption.
     */
    private function captionElement(DOMElement $group, DOMElement $form): ?DOMElement
    {
        $document = $group->ownerDocument;
        foreach ( SourceDom::tokens($group->getAttribute('aria-labelledby')) as $id ) {
            $target = '' !== $id && $document instanceof DOMDocument ? $document->getElementById($id) : null;
            if ( ! $target instanceof DOMElement || $target->isSameNode($group) ) continue;
            if ( FormControlClassifier::isControlElement($target) || array() !== FormControlClassifier::controlElements($target) ) continue;
            if ( ! $this->withinForm($form, $target) || '' === self::normalizedText($target) ) continue;
            return $target;
        }
        $name = preg_replace('/\s+/', ' ', trim($group->getAttribute('aria-label')));
        if ( ! is_string($name) || '' === $name ) return null;
        for ( $scope = $group, $level = 0; $scope instanceof DOMElement && $level <= self::MAX_CAPTION_ANCESTORS && ! $scope->isSameNode($form); ++$level, $scope = $scope->parentNode instanceof DOMElement ? $scope->parentNode : null ) {
            for ( $sibling = $scope->previousElementSibling, $visited = 0; $sibling instanceof DOMElement && $visited < self::MAX_CAPTION_SIBLINGS; ++$visited, $sibling = $sibling->previousElementSibling ) {
                if ( FormControlClassifier::isControlElement($sibling) || array() !== FormControlClassifier::controlElements($sibling) ) continue;
                if ( self::normalizedText($sibling) === $name ) return $sibling;
            }
        }
        return null;
    }

    /** @param array<string, int> $controlIndexes */
    private function firstChoiceIndex(DOMElement $group, array $controlIndexes): ?int
    {
        foreach ( $group->getElementsByTagName('input') as $input ) {
            if ( ! $input instanceof DOMElement || 'input' !== strtolower($input->tagName) ) continue;
            $type = FormControlClassifier::controlType($input);
            if ( ! in_array($type, array( 'radio', 'checkbox' ), true) ) continue;
            $index = $controlIndexes[$input->getNodePath()] ?? null;
            if ( null !== $index ) return $index;
        }
        return null;
    }

    private static function normalizedText(DOMElement $element): string
    {
        $text = preg_replace('/\s+/', ' ', $element->textContent ?? '');

        return is_string($text) ? trim($text) : '';
    }

    /** @return bool Whether $element is a straggler of the form subtree, or genuinely outside it. */
    private function withinForm(DOMElement $form, DOMElement $element): bool
    {
        for ( $parent = $element->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode instanceof DOMElement ? $parent->parentNode : null ) {
            if ( $parent->isSameNode($form) ) return true;
        }

        return false;
    }

    private function inertAncestor(DOMElement $element, DOMElement $form): bool
    {
        for ( $parent = $element->parentNode; $parent instanceof DOMElement && ! $parent->isSameNode($form); $parent = $parent->parentNode instanceof DOMElement ? $parent->parentNode : null ) {
            if ( in_array(strtolower($parent->tagName), array( 'template', 'script', 'style', 'noscript' ), true) ) return true;
        }

        return false;
    }

    /** A container paint requires a visible fill or border, not radius or neutral resets alone. */
    private function hasContainerPaint(array $styles): bool
    {
        $classifier = new SourceElementClassifier();
        foreach ( array('background', 'background_color') as $property ) if (isset($styles[$property]) && $classifier->isVisibleEmptyVisualPaint($styles[$property])) return true;
        if (isset($styles['border']) && $classifier->isVisibleEmptyVisualBorder($styles['border'])) return true;
        // A zero-width border shorthand is a reset, not paint, even when a
        // separate border-color also matched. Layered author resets often
        // declare both `border: 0 solid` and `border-color` on `*`.
        if (isset($styles['border']) && 1 === preg_match('/(?:^|\s)0(?:\.0+)?(?:px|em|rem|ex|ch|cm|mm|in|pt|pc|vw|vh|vmin|vmax)?(?:\s|$)/i', trim($styles['border']))) return false;
        foreach ( array('border_width', 'border_top_width', 'border_right_width', 'border_bottom_width', 'border_left_width') as $property ) if (isset($styles[$property]) && ! $classifier->isPositiveCssLength($styles[$property])) return false;
        foreach ( array('border_color', 'border_top_color', 'border_right_color', 'border_bottom_color', 'border_left_color') as $property ) if (isset($styles[$property]) && $classifier->isVisibleEmptyVisualPaint($styles[$property])) return true;
        return false;
    }

    /** A visual part describes source identity and facts, never an inferred semantic role. */
    private static function assertVisualPart(mixed $part): void
    {
        if ( ! is_array($part) || array_diff(array_keys($part), array( 'id', 'index', 'kind', 'source_selector', 'markup', 'intrinsic_size', 'source_css', 'label_side' )) || ! is_string($part['id'] ?? null) || ! preg_match('/^control-[0-9]+-svg-[0-9]+$/', $part['id']) || ! is_int($part['index'] ?? null) || $part['index'] < 0 || $part['index'] >= self::MAX_CONTROLS || ! is_string($part['source_selector'] ?? null) || '' === trim($part['source_selector']) || strlen($part['source_selector']) > 2048 || ! in_array($part['label_side'] ?? null, array( 'before', 'after' ), true) || 'inline_svg' !== ($part['kind'] ?? null) || ! is_string($part['markup'] ?? null) || strlen($part['markup']) > self::MAX_VISUAL_BYTES || ! SourceDom::isSafeInlineSvgMarkup($part['markup']) || ! is_array($part['source_css'] ?? null) || ! in_array($part['source_css']['state'] ?? null, array( 'known', 'unknown' ), true) ) throw new InvalidArgumentException('Form presentation visual part is invalid.');
        if ( isset($part['intrinsic_size']) && (! is_array($part['intrinsic_size']) || array_diff(array_keys($part['intrinsic_size']), array( 'width', 'height' )) || ! self::validDimension($part['intrinsic_size']['width'] ?? null) || ! self::validDimension($part['intrinsic_size']['height'] ?? null)) ) throw new InvalidArgumentException('Form presentation visual part dimensions are invalid.');
        $sourceCss = $part['source_css'];
        if ( 'known' === $sourceCss['state'] ) {
            if ( array_diff(array_keys($sourceCss), array( 'state', 'styles', 'provenance' )) || ! is_array($sourceCss['styles'] ?? null) || array() === $sourceCss['styles'] || ! is_array($sourceCss['provenance'] ?? null) ) throw new InvalidArgumentException('Form presentation visual part source CSS is invalid.');
            self::assertStyles($sourceCss['styles']); self::assertProvenance($sourceCss['provenance'], $sourceCss['styles'], null);
        } elseif ( array_diff(array_keys($sourceCss), array( 'state' )) ) throw new InvalidArgumentException('Form presentation visual part unknown CSS state is invalid.');
    }

    /** A visual group retains the authored common container without assigning semantic meaning. */
    private static function assertVisualGroup(mixed $group, array $partIds): void
    {
        if ( ! is_array($group) || array_diff(array_keys($group), array( 'id', 'source_selector', 'part_ids', 'source_css' )) || ! is_string($group['id'] ?? null) || ! preg_match('/^visual-group-[a-f0-9]{16}$/', $group['id']) || ! is_string($group['source_selector'] ?? null) || '' === trim($group['source_selector']) || strlen($group['source_selector']) > 2048 || ! is_array($group['part_ids'] ?? null) || ! array_is_list($group['part_ids']) || count($group['part_ids']) < 2 || count($group['part_ids']) > self::MAX_VISUAL_PARTS || count(array_unique($group['part_ids'])) !== count($group['part_ids']) || array_filter($group['part_ids'], static fn (mixed $id): bool => ! is_string($id) || ! isset($partIds[$id])) || ! is_array($group['source_css'] ?? null) || ! in_array($group['source_css']['state'] ?? null, array( 'known', 'unknown' ), true) ) throw new InvalidArgumentException('Form presentation visual group is invalid.');
        if ( 'known' === $group['source_css']['state'] ) {
            if ( array_diff(array_keys($group['source_css']), array( 'state', 'styles', 'provenance' )) || ! is_array($group['source_css']['styles'] ?? null) || array() === $group['source_css']['styles'] || ! is_array($group['source_css']['provenance'] ?? null) ) throw new InvalidArgumentException('Form presentation visual group source CSS is invalid.');
            self::assertStyles($group['source_css']['styles']); self::assertProvenance($group['source_css']['provenance'], $group['source_css']['styles'], null);
        } elseif ( array_diff(array_keys($group['source_css']), array( 'state' )) ) throw new InvalidArgumentException('Form presentation visual group unknown CSS state is invalid.');
    }

    private static function assertStyles(array $styles, array $properties = self::PROPERTIES): void
    {
        foreach ( $styles as $key => $value ) if ( ! is_string($key) || ! in_array($key, array_map(self::key(...), $properties), true) || ! is_string($value) || '' === trim($value) || strlen($value) > self::MAX_STYLE_VALUE_BYTES ) throw new InvalidArgumentException('Form presentation style is invalid.');
    }

    private static function assertProvenance(array $provenance, array $styles, ?array $condition, array $properties = self::PROPERTIES): void
    {
        if ( count($provenance) > self::MAX_PROVENANCE ) throw new InvalidArgumentException('Form presentation provenance exceeds its limit.');
        foreach ( $provenance as $fact ) {
            if ( is_array($fact) && array_diff(array_keys($fact), array( 'source_path', 'source_sha256', 'selector', 'condition', 'properties' )) ) throw new InvalidArgumentException('Form presentation provenance has unknown keys.');
            if ( ! is_array($fact) || ! is_string($fact['source_path'] ?? null) || '' === ArtifactPath::safeRelativePath($fact['source_path']) || ArtifactPath::safeRelativePath($fact['source_path']) !== $fact['source_path'] || ! preg_match('/^[a-f0-9]{64}$/', $fact['source_sha256'] ?? '') || ! is_string($fact['selector'] ?? null) || '' === trim($fact['selector']) || strlen($fact['selector']) > 1024 || ! is_array($fact['properties'] ?? null) || array() === $fact['properties'] || array_filter($fact['properties'], static fn (mixed $property): bool => ! is_string($property) || ! in_array($property, $properties, true) || ! isset($styles[self::key($property)])) || ($condition !== null && ($fact['condition'] ?? null) !== $condition) || ($condition === null && ($fact['condition'] ?? null) !== null) ) throw new InvalidArgumentException('Form presentation provenance is invalid.');
        }
    }

    /** @return list<DOMElement> */
    private function controls(DOMElement $form): array
    {
        // One shared enumeration keeps presentation-graph indexes aligned with
        // reported controls. A raw tag walk counted aria-hidden honeypots the
        // control manifest omits, so layered author CSS on the submit button
        // landed on an unmapped index and providers declined the form. Each
        // index describes the element that paints its control.
        return null !== $this->controlElements
            ? array_map(FormControlClassifier::presentingElement(...), $this->controlElements)
            : FormControlClassifier::presentedControlElements($form, $this->additionalControls);
    }

    /** @return list<DOMElement> */
    private function presentationElements(DOMElement $form): array
    {
        $elements = $this->controls($form);
        foreach ( $this->controls($form) as $control ) {
            if (($carrier = self::soleTextCarrier($control)) instanceof DOMElement) $elements[] = $carrier;
            $label = FormControlLabel::element($control);
            if ( $label instanceof DOMElement ) $elements[] = $label;
            $excluded = array( $control );
            if ( null !== $this->requiredMarker && ($marker = ($this->requiredMarker)($control)) instanceof DOMElement ) {
                $elements[] = $marker;
                $excluded[] = $marker;
            }
            if ( $label instanceof DOMElement && ($carrier = self::soleTextCarrier($label, $excluded)) instanceof DOMElement ) $elements[] = $carrier;
            if (null !== $this->sanitizeInlineSvgMarkup) {
                $visualCount = 0;
                foreach ($control->getElementsByTagName('svg') as $svg) {
                    if (!$svg instanceof DOMElement || !SourceDom::svgHasDrawableContent($svg)) continue;
                    $elements[] = $svg;
                    if (++$visualCount >= self::MAX_VISUAL_PARTS) break;
                }
            }
        }
        return $elements;
    }

    private function labelSide(DOMElement $control, DOMElement $svg): string
    {
        // Walk descendants in document order, including text in nested wrappers.
        $nodes = (new \DOMXPath($control->ownerDocument))->query('.//node()', $control);
        foreach ( $nodes as $node ) {
            if ( $node->isSameNode($svg) ) return 'before';
            if ( $node instanceof \DOMText && '' !== trim($node->textContent) ) return 'after';
        }
        return 'before';
    }

    /** @return list<array{part: array<string,mixed>, variants: list<array<string,mixed>>, group?: array<string,mixed>, group_variants?: list<array<string,mixed>>}> */
    private function visualParts(DOMElement $control, int $index, array $rules, array $customPropertyRules): array
    {
        if ( null === $this->sanitizeInlineSvgMarkup ) return array();
        $parts = array();
        $ordinal = 0;
        foreach ( $control->getElementsByTagName('svg') as $svg ) {
            if ( ! $svg instanceof DOMElement || ! SourceDom::svgHasDrawableContent($svg) ) continue;
            // An icon the source keeps hidden inside the control (a loading
            // spinner shown only while the form submits) is not drawn there.
            if ( null !== $this->hiddenBelow($svg, $control, $rules) ) continue;
            if ( count($parts) >= self::MAX_VISUAL_PARTS ) { $this->truncated = true; $this->diagnostics[] = 'visual_part_limit'; break; }
            $selector = SourceDom::elementSelector($svg);
            if ( strlen($selector) > 2048 ) { $this->diagnostics[] = 'visual_selector_limit'; continue; }
            $markup = trim(($this->sanitizeInlineSvgMarkup)($svg));
            if ( strlen($markup) > self::MAX_VISUAL_BYTES || ! SourceDom::isSafeInlineSvgMarkup($markup) ) { $this->diagnostics[] = 'unsafe_visual_part'; continue; }
            $matched = $this->matched($svg, $rules);
            $styles = $this->styles($matched['base'], $svg, null, $customPropertyRules);
            $part = array( 'id' => 'control-' . $index . '-svg-' . $ordinal++, 'index' => $index, 'kind' => 'inline_svg', 'label_side' => $this->labelSide($control, $svg), 'source_selector' => $selector, 'markup' => $markup, 'source_css' => array( 'state' => 'unknown' ) );
            $size = $this->intrinsicSize($svg);
            if ( array() !== $size ) $part['intrinsic_size'] = $size;
            if ( array() !== $styles ) $part['source_css'] = array( 'state' => 'known', 'styles' => $styles, 'provenance' => $this->provenance($matched['base'], null) );
            $variants = array();
            foreach ( $this->effectiveConditional($matched['conditional'], $matched['base']) as $encoded => $facts ) {
                $condition = json_decode($encoded, true);
                $patch = $this->styles($facts, $svg, $condition, $customPropertyRules);
                if ( array() !== $patch ) $variants[] = array( 'index' => $index, 'role' => 'visual_part', 'part_id' => $part['id'], 'condition' => $condition, 'style_patch' => $patch, 'precedence' => $this->precedence($facts), 'provenance' => $this->provenance($facts, $condition) );
            }
            $parts[] = array( 'part' => $part, 'variants' => $variants, 'element' => $svg );
        }
        $svgs = array_column($parts, 'element');
        if ( count($svgs) >= 2 && ($groupElement = $this->lowestCommonAncestor($svgs)) instanceof DOMElement ) {
            $selector = SourceDom::elementSelector($groupElement);
            if ( strlen($selector) <= 2048 ) {
                $matched = $this->matched($groupElement, $rules);
                $styles = $this->styles($matched['base'], $groupElement, null, $customPropertyRules);
                $group = array( 'id' => 'visual-group-' . substr(hash('sha256', $selector), 0, 16), 'source_selector' => $selector, 'part_ids' => array_column(array_column($parts, 'part'), 'id'), 'source_css' => array( 'state' => 'unknown' ) );
                if ( array() !== $styles ) $group['source_css'] = array( 'state' => 'known', 'styles' => $styles, 'provenance' => $this->provenance($matched['base'], null) );
                $groupVariants = array();
                foreach ( $this->effectiveConditional($matched['conditional'], $matched['base']) as $encoded => $facts ) { $condition = json_decode($encoded, true); $patch = $this->styles($facts, $groupElement, $condition, $customPropertyRules); if ( array() !== $patch ) $groupVariants[] = array( 'role' => 'visual_group', 'group_id' => $group['id'], 'condition' => $condition, 'style_patch' => $patch, 'precedence' => $this->precedence($facts), 'provenance' => $this->provenance($facts, $condition) ); }
                $parts[array_key_last($parts)]['group'] = $group;
                $parts[array_key_last($parts)]['group_variants'] = $groupVariants;
            }
        }
        foreach ( $parts as &$part ) unset($part['element']); unset($part);
        return $parts;
    }

    /** @param list<DOMElement> $elements */
    private function lowestCommonAncestor(array $elements): ?DOMElement
    {
        for ( $candidate = $elements[0]->parentNode; $candidate instanceof DOMElement; $candidate = $candidate->parentNode ) {
            foreach ( $elements as $element ) { for ( $current = $element; $current instanceof DOMElement && ! $current->isSameNode($candidate); $current = $current->parentNode instanceof DOMElement ? $current->parentNode : null ) {} if (! $current instanceof DOMElement) continue 2; }
            return $candidate;
        }
        return null;
    }

    /** @param list<array<string, mixed>> $rules */
    private function matched(DOMElement $element, array $rules, bool $expandFlex = false): array
    {
        $allowed = $expandFlex ? array_merge(self::PROPERTIES, self::ROW_ONLY_PROPERTIES) : self::PROPERTIES;
        if ( $element->hasAttribute('style') ) {
            $inline = $element->getAttribute('style');
            $rules[] = array( 'inline' => true, 'selector' => '[style]', 'declarations' => CssRuleAnalyzer::declarations($inline, $allowed), 'condition' => null, 'path' => 'inline-style', 'hash' => hash('sha256', $inline), 'order' => PHP_INT_MAX, 'specificity' => 10000 );
        }
        $base = array(); $conditional = array(); $matched = 0;
        foreach ( $rules as $rule ) {
            $match = ! empty($rule['inline']) ? array( 'supported' => true, 'matches' => true ) : $this->matchCache->matches($element, $rule['selector'], $rule['parsed_selector']);
            if ( ! $match['supported'] ) { $this->stylesheetDiagnostics['unsupported_selector:' . $rule['selector']] = true; continue; }
            if ( ! $match['matches'] ) continue;
            if ( $matched++ >= self::MAX_RULES_PER_ROLE ) { $this->truncated = true; $this->diagnostics[] = 'rules_per_role_limit'; break; }
            foreach ( $rule['declarations'] as $declarationOrder => $declaration ) {
                $declarations = $expandFlex && 'flex' === $declaration['name'] ? $this->flexLonghands($declaration['value']) : $this->flexFlowLonghands($declaration);
                foreach ( $declarations as $name => $value ) {
                    if ( ! in_array($name, $allowed, true) ) continue;
                    $important = 1 === preg_match('/\s*!important\s*$/i', $value);
                    $resolved = preg_replace('/\s*!important\s*$/i', '', $value) ?? $value;
                    $fact = array( 'value' => $resolved, 'path' => $rule['path'], 'hash' => $rule['hash'], 'selector' => $rule['selector'], 'order' => $rule['order'], 'declaration_order' => $declarationOrder, 'specificity' => $rule['specificity'], 'important' => $important, 'layer' => $rule['layer'] ?? null );
                    $encoded = null === $rule['condition'] ? null : json_encode($rule['condition']);
                    if ( null === $encoded ) $target =& $base; else { $conditional[$encoded] ??= array(); $target =& $conditional[$encoded]; }
                    CssCascade::apply($target, $name, $fact); unset($target);
                }
            }
        }
        $base = $this->shorthandCascade($base);
        foreach ($conditional as &$facts) $facts = $this->shorthandCascade($facts, $base);
        unset($facts);
        return array( 'base' => $base, 'conditional' => $conditional );
    }

    /** A winning shorthand resets weaker longhands on the same source element. */
    private function shorthandCascade(array $facts, array $base = array()): array
    {
        foreach (self::SHORTHAND_LONGHANDS as $shorthand => $longhands) {
            $winner = $facts[$shorthand] ?? $base[$shorthand] ?? null;
            if (!is_array($winner)) continue;
            if (isset($base[$shorthand]) && !CssCascade::wins($winner, $base[$shorthand])) $winner = $base[$shorthand];
            foreach ($longhands as $property) {
                if (!isset($facts[$property]) || !CssCascade::wins($winner, $facts[$property])) continue;
                // A same-rule longhand authored after the shorthand overrides it.
                if (CssCascade::wins($facts[$property], $winner) && ($winner['declaration_order'] ?? 0) < ($facts[$property]['declaration_order'] ?? 0)) continue;
                unset($facts[$property]);
            }
        }
        return $facts;
    }

    /**
     * One source declaration as the longhand facts the shared cascade consumes.
     * The `flex-flow` shorthand is expanded into its `flex-direction` and
     * `flex-wrap` longhands — an omitted token is its initial value, a same-rule
     * longhand authored after it still wins, and an unparseable flow (a custom
     * property, say) names its gap without discarding the rest of the graph.
     *
     * @return array<string, string>
     */
    private function flexFlowLonghands(array $declaration): array
    {
        if ( 'flex-flow' !== $declaration['name'] ) {
            return array( $declaration['name'] => $declaration['value'] );
        }
        $flow = FlexFlowParser::parse(CssValueSplitter::splitTopLevelWhitespace($declaration['value']));
        if ( null === $flow ) {
            $this->diagnostics[] = 'unsupported_flex_flow';
            return array();
        }
        return array( 'flex-direction' => $flow[0], 'flex-wrap' => $flow[1] );
    }

    /** Expand a flex shorthand before cascading, so later longhands and !important retain their rank. */
    private function flexLonghands(string $value): array
    {
        $important = 1 === preg_match('/\s*!important\s*$/i', $value);
        $value = trim(preg_replace('/\s*!important\s*$/i', '', $value) ?? $value);
        $keywords = array('none' => array('0', '0', 'auto'), 'auto' => array('1', '1', 'auto'), 'initial' => array('0', '1', 'auto'));
        $parts = $keywords[strtolower($value)] ?? null;
        if (in_array(strtolower($value), array('inherit', 'unset', 'revert', 'revert-layer'), true)) $parts = array($value, $value, $value);
        if (null === $parts) {
            $tokens = CssValueSplitter::splitTopLevelWhitespace($value);
            $numbers = array(); $basis = null;
            foreach ($tokens as $token) {
                if (preg_match('/^(?:\d*\.)?\d+$/D', $token) && count($numbers) < 2 && null === $basis) $numbers[] = $token;
                elseif (null === $basis && (in_array(strtolower($token), array('auto', 'content', 'min-content', 'max-content', 'fit-content'), true) || preg_match('/^(?:0|(?:\d*\.)?\d+(?:%|[a-z]+)|(?:calc|min|max|clamp)\(.+\))$/iD', $token))) $basis = $token;
                else { $this->diagnostics[] = 'unsupported_flex'; return array(); }
            }
            if (!$tokens) return array();
            $parts = array($numbers[0] ?? '1', $numbers[1] ?? '1', $basis ?? '0%');
        }
        if ($important) $parts = array_map(static fn (string $part): string => $part . ' !important', $parts);
        return array_combine(array('flex-grow', 'flex-shrink', 'flex-basis'), $parts);
    }

    private function effectiveConditional(array $conditional, array $base): array
    {
        foreach ( $conditional as $condition => &$facts ) foreach ( $facts as $property => $fact ) if ( isset($base[$property]) && ! CssCascade::wins($fact, $base[$property]) ) unset($facts[$property]);
        unset($facts);
        return array_filter($conditional);
    }

    /** @param list<array<string, mixed>> $rules */
    private function styles(array $facts, DOMElement $element, ?array $condition, array $rules, bool $unconditionalBase = false): array
    {
        $result = array();
        foreach ( $facts as $property => $fact ) {
            $owner = $fact['element'] ?? $element;
            $value = $this->customProperties->resolve($fact['value'], $owner, $condition, $rules, $unconditionalBase);
            if ( null !== $this->resolveValue && str_contains($value, 'var(') ) $value = ($this->resolveValue)($element, $value);
            // A value that is empty or over the style budget cannot be carried.
            // Drop that one property and report it; the rest of the form keeps its presentation.
            if ( '' === trim($value) || strlen($value) > self::MAX_STYLE_VALUE_BYTES ) {
                $this->unrepresentable[self::declarationKey($fact, $property)] = true;
                $this->diagnostics[] = 'style_value_unrepresentable:' . $property;
                continue;
            }
            $result[self::key($property)] = $value;
        }
        ksort($result);
        return $result;
    }

    /**
     * A flex label's own `column-gap` spaces its box from its caption. The
     * shared vocabulary keeps `column-gap` row-only, so the label states it
     * through the equivalent `gap: <row-gap> <column-gap>` shorthand.
     */
    private function carryLabelColumnGap(array &$styles, array &$provenance, DOMElement $label, array $rules, array $customRules, ?array $condition = null): void
    {
        $base = $this->layoutFacts($label, $rules, $condition);
        $resolved = $this->styles($base, $label, $condition, $customRules, null === $condition);
        $column = $base['column-gap'] ?? null;
        if ( ! is_array($column) || ! in_array($resolved['display'] ?? '', array( 'flex', 'inline-flex' ), true)
            || ( isset($base['gap']) && CssCascade::wins($base['gap'], $column) ) ) {
            return;
        }
        $row = isset($base['gap']) ? (CssValueSplitter::splitTopLevelWhitespace($resolved['gap'])[0] ?? 'normal') : 'normal';
        $styles['gap'] = $row . ' ' . $resolved['column_gap'];
        $provenance = array_merge($provenance, $this->provenance(array( 'gap' => $column ), $condition));
    }

    /** Carry a horizontal flex gap across the flattened caption/marker boundary. */
    private function carryMarkerGap(array &$styles, array &$provenance, DOMElement $marker, array $rules, array $customRules, ?array $condition = null): void
    {
        $container = $marker->parentNode;
        if ( ! $container instanceof DOMElement ) {
            return;
        }
        $base = $this->layoutFacts($container, $rules, $condition);
        $containerStyles = $this->styles($base, $container, $condition, $customRules, null === $condition);
        if ( in_array($containerStyles['display'] ?? '', array( 'flex', 'inline-flex', 'grid', 'inline-grid' ), true) ) {
            // The source parent blockifies this item; that outer display does
            // not describe the inline marker after the caption is flattened.
            unset($styles['display']);
            foreach ( $provenance as &$source ) {
                $source['properties'] = array_values(array_diff($source['properties'], array( 'display' )));
            }
            unset($source);
            $provenance = array_values(array_filter($provenance, static fn (array $source): bool => array() !== $source['properties']));
            if ( null !== $condition ) {
                // A variant restates the inline display its conditional facts named.
                $styles['display'] = 'inline';
            }
        }
        if ( ! $this->followsCaption($marker) || ! in_array($containerStyles['display'] ?? '', array( 'flex', 'inline-flex' ), true)
            || 'row' !== ($containerStyles['flex_direction'] ?? 'row')
        ) {
            return;
        }
        $property = isset($base['column-gap']) && (! isset($base['gap']) || CssCascade::wins($base['column-gap'], $base['gap'])) ? 'column-gap' : 'gap';
        $gap = $containerStyles[self::key($property)] ?? '';
        $parts = CssValueSplitter::splitTopLevelWhitespace($gap);
        $gap = $parts[1] ?? $parts[0] ?? '';
        if ( '' === $gap || 'normal' === $gap ) {
            return;
        }
        // Font-relative lengths must use the gap container's font, not the
        // smaller indicator's. Scale ex/ch without guessing font metrics.
        if ( 1 === preg_match('/^([0-9.]+)(em|ex|ch)$/D', $gap, $length) ) {
            $containerFont = $this->fontPixels($container, $rules, $customRules, $condition);
            $markerFont = $this->fontPixels($marker, $rules, $customRules, $condition);
            if ( 'em' === $length[2] ) {
                $gap = CssLength::format((float) $length[1] * $containerFont);
            } elseif ( $markerFont > 0 && $containerFont !== $markerFont ) {
                $ratio = rtrim(rtrim(number_format($containerFont / $markerFont, 6, '.', ''), '0'), '.');
                $gap = 'calc(' . $gap . ' * ' . $ratio . ')';
            }
        }
        $markerStyles = $this->styles($this->layoutFacts($marker, $rules, $condition), $marker, $condition, $customRules, null === $condition);
        $margin = $markerStyles['margin_left'] ?? null;
        $styles['margin_left'] = null !== $margin ? 'calc(' . $margin . ' + ' . $gap . ')' : $gap;
        $provenance = array_merge($provenance, $this->provenance(array( 'margin-left' => $base[$property] ), $condition));
    }

    /** A bare caption text node is an anonymous flex item, so the gap still separates it from the marker. */
    private function followsCaption(DOMElement $marker): bool
    {
        for ( $node = $marker->previousSibling; null !== $node; $node = $node->previousSibling ) {
            if ( $node instanceof DOMElement || '' !== trim($node->textContent) ) {
                return true;
            }
        }
        return false;
    }

    /** Effective layout facts in the same condition as the carried gap. */
    private function layoutFacts(DOMElement $element, array $rules, ?array $condition): array
    {
        $matched = $this->matched($element, $rules, true);
        $conditional = $this->effectiveConditional($matched['conditional'], $matched['base']);
        return array_merge($matched['base'], null === $condition ? array() : ($conditional[json_encode($condition)] ?? array()));
    }

    /** Resolve inherited font sizes against their own parent, not the marker. */
    private function fontPixels(DOMElement $element, array $rules, array $customRules, ?array $condition = null): float
    {
        $chain = array();
        for ( $node = $element; $node instanceof DOMElement; $node = $node->parentNode ) {
            $chain[] = $node;
        }
        $font = 16.0;
        $root = 16.0;
        foreach ( array_reverse($chain) as $index => $node ) {
            $facts = $this->layoutFacts($node, $rules, $condition);
            $styles = $this->styles(array_intersect_key($facts, array( 'font-size' => true )), $node, $condition, $customRules, null === $condition);
            $value = $styles['font_size'] ?? '';
            $font = str_ends_with($value, '%') ? $font * (float) $value / 100 : (CssLength::FONT_SIZE_KEYWORDS[$value] ?? CssLength::toPx($value, $root, $font) ?? $font);
            if ( 0 === $index ) {
                $root = $font;
            }
        }
        return $font;
    }

    private static function declarationKey(array $fact, string $property): string { return $fact['path'] . "\n" . $fact['selector'] . "\n" . $property; }
    private static function key(string $property): string { return str_replace('-', '_', $property); }
    /** @return array<string, int> */
    private function intrinsicSize(DOMElement $svg): array { $width = $this->dimension(SourceDom::attr($svg, 'width')); $height = $this->dimension(SourceDom::attr($svg, 'height')); return null !== $width && null !== $height ? array( 'width' => $width, 'height' => $height ) : array(); }
    private function dimension(string $value): ?int { return 1 === preg_match('/^[1-9][0-9]{0,3}$/D', trim($value)) && (int) $value <= self::MAX_VISUAL_DIMENSION ? (int) $value : null; }
    private static function validDimension(mixed $value): bool { return is_int($value) && $value > 0 && $value <= self::MAX_VISUAL_DIMENSION; }
    private function precedence(array $facts): array { $result = array(); foreach ( $facts as $property => $fact ) if ( ! isset($this->unrepresentable[self::declarationKey($fact, $property)]) ) $result[$property] = array( 'source_order' => $fact['order'], 'specificity' => $fact['specificity'], 'important' => $fact['important'] ); ksort($result); return $result; }
    private function provenance(array $facts, ?array $condition): array { $grouped = array(); foreach ( $facts as $property => $fact ) { if ( isset($this->unrepresentable[self::declarationKey($fact, $property)]) ) continue; $key = $fact['path'] . "\n" . $fact['selector']; $grouped[$key] ??= array( 'source_path' => $fact['path'], 'source_sha256' => $fact['hash'], 'selector' => $fact['selector'], 'condition' => $condition, 'properties' => array() ); $grouped[$key]['properties'][] = $property; } foreach ( $grouped as &$item ) sort($item['properties'], SORT_STRING); unset($item); if ( count($grouped) > self::MAX_PROVENANCE ) { $this->truncated = true; $this->diagnostics[] = 'provenance_limit'; } return array_slice(array_values($grouped), 0, self::MAX_PROVENANCE); }

    private static function validCondition(array $condition, int $depth = 0): bool
    {
        if ( $depth > self::MAX_CONDITION_DEPTH ) return false;
        if ( 'all' === ($condition['kind'] ?? null) ) return is_array($condition['conditions'] ?? null) && array() !== $condition['conditions'] && count($condition['conditions']) <= self::MAX_CONDITION_DEPTH && array_reduce($condition['conditions'], static fn (bool $ok, mixed $item): bool => $ok && is_array($item) && self::validCondition($item, $depth + 1), true);
        return in_array($condition['kind'] ?? null, array( 'media', 'container', 'supports' ), true) && is_string($condition['query'] ?? null) && '' !== trim($condition['query']) && strlen($condition['query']) <= 1024;
    }
}

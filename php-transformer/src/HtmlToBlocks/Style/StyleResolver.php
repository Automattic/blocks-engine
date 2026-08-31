<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\CssUrlRewriter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformerAnalysisCache;
use Automattic\BlocksEngine\PhpTransformer\WordPress\GeneratedGutenbergClassPolicy;
use DOMElement;

/**
 * Resolves source CSS into native block presentation attributes.
 *
 * Previously `StyleResolutionTrait` — at 2,822 lines the largest
 * single-consumer trait mixed into `HtmlTransformer`, and therefore the largest
 * single contributor to that class's object scope. As a trait every one of its
 * 99 methods resolved against the transformer's `$this`.
 *
 * Despite its size this was the most self-contained of the remaining traits:
 * it needs 15 transformer operations and 3 collaborators, which is fewer
 * external dependencies than traits a third its size.
 */
final class StyleResolver
{
    public function __construct(
        private readonly StyleResolutionContext $context,
        private readonly HtmlTransformerAnalysisCache $analysisCache
    ) {
    }

    private ?StyleAttributeMapper $styleAttributeMapper = null;

    private ?HighValueStyleBoundaryPolicy $highValueStyleBoundaryPolicy = null;

    private ?ClosedStateNormalizer $closedStateNormalizer = null;

    /**
     * Resolved presentation attributes for the active transform, keyed by the
     * DOMElement wrapper object id plus node path. PHP may reuse wrapper object
     * ids within one traversal as transient DOMElement wrappers are released.
     *
     * @var array<string, array<string, mixed>>
     */

    /**
     * @var array<string, array<string, string>>
     */

    /**
     * @var array<string, string>
     */

    /**
     * @var array<string, string>
     */

    /**
     * Inline presentation declarations which core block supports cannot serialize
     * are carried by deterministic classes in a generated stylesheet.
     *
     * @var array<string, string>
     */


    /** Source-selector cache remains valid only between source-DOM mutations. */

    /** @var array<string, array<string, mixed>> */

    /**
     * Author-declared values for the properties an element's inline style could
     * be overriding, keyed by element plus the queried property set. Resolving
     * this walks every matched rule, so it is memoized per element.
     *
     * @var array<string, array<string, array<int, string>>>
     */

    /**
     * @return list<string>
     */
    private function inlineLayoutCarrierProperties(): array
    {
        return array(
            'display',
            'flex-direction',
            'flex-wrap',
            'align-items',
            'justify-content',
            'gap',
        );
    }

    /**
     * The alignment half of the layout carrier list. `display` is excluded on
     * purpose: in the author-resolved branch the author stylesheet owns the
     * formatting context, and carrying `display` is exactly what that branch
     * exists to guard against.
     *
     * @return list<string>
     */
    private function inlineFlexAlignmentCarrierProperties(): array
    {
        return array_values(array_diff($this->inlineLayoutCarrierProperties(), array( 'display' )));
    }

    /**
     * Properties carried when NO author rule declares them at all.
     *
     * Deliberately NOT general. Decorative paint is safe to preserve without
     * layout or animation side effects. Color declarations also land here when
     * their custom properties cannot be proven compatible with Gutenberg color
     * support; carrying the authored CSS avoids activating destructive support
     * classes without discarding the source declaration. A general "carry every
     * leftover inline declaration" rule would also carry `animation`, `filter`
     * and `counter-reset`, which have side effects. Conflicting declarations do
     * not need to be on this list — a conflict is self-evidence that the author
     * rule would otherwise reassert the opposite value.
     *
     * @return list<string>
     */
    private function inlineUnmatchedCarrierProperties(): array
    {
        return array(
            'box-shadow',
            'color',
            'background-color',
            'border-color',
            'border',
            'border-top',
            'border-right',
            'border-bottom',
            'border-left',
            'border-top-color',
            'border-right-color',
            'border-bottom-color',
            'border-left-color',
        );
    }

    /**
     * @return list<string>
     */
    private function inlineListMarkerCarrierProperties(): array
    {
        return array(
            'list-style',
            'list-style-type',
            'list-style-position',
            'list-style-image',
        );
    }

    /**
     * @return list<string>
     */
    public function inlineGeometryProperties(): array
    {
        return array_merge($this->inlineLayoutCarrierProperties(), $this->inlineListMarkerCarrierProperties(), array(
            'width',
            'height',
            'min-width',
            'min-height',
            'max-width',
            'max-height',
            'aspect-ratio',
            'box-sizing',
            'flex',
            'flex-basis',
            'flex-grow',
            'flex-shrink',
            'object-fit',
            'object-position',
        ));
    }

    /**
     * Positioning that makes an empty named anchor a scroll target rather than
     * a zero-size in-flow box.
     *
     * @return list<string>
     */
    private function namedFragmentTargetProperties(): array
    {
        return array(
            'position',
            'top',
            'right',
            'bottom',
            'left',
            'inset',
            'overflow',
            'pointer-events',
        );
    }

    /**
     * An empty element whose inline style already places it for hash navigation.
     */
    private function isNamedFragmentTarget(DOMElement $element): bool
    {
        if ( '' === trim($this->context->attr($element, 'id')) ) {
            return false;
        }
        if ( 0 < $this->context->directElementChildCount($element) || '' !== trim((string) $element->textContent) ) {
            return false;
        }

        $position = strtolower(trim((string) ($this->cssDeclarations($this->context->attr($element, 'style'))['position'] ?? '')));

        return in_array($position, array( 'absolute', 'fixed' ), true);
    }

    /**
     * @return list<string>
     */
    private function inlineBackgroundCarrierProperties(): array
    {
        return array(
            'background',
            'background-image',
            'background-position',
            'background-size',
            'background-repeat',
            'background-attachment',
            'background-origin',
            'background-clip',
            'background-blend-mode',
        );
    }

    public function styleAttributeMapper(): StyleAttributeMapper
    {
        return $this->styleAttributeMapper ??= new StyleAttributeMapper();
    }

    private function highValueStyleBoundaryPolicy(): HighValueStyleBoundaryPolicy
    {
        return $this->highValueStyleBoundaryPolicy ??= new HighValueStyleBoundaryPolicy();
    }

    /**
     * Resolve an element's presentation into canonical block attributes.
     *
     * The merged CSS is translated into the canonical block `style` OBJECT
     * (typography/color/spacing/border) plus the `layout` attribute. Class-owned
     * vertical flex CSS stays owned by the preserved `className` to avoid
     * WordPress `is-vertical` layout classes overriding source CSS. A raw inline
     * `style` STRING is never emitted on a block: declarations that do not map to
     * a block support are dropped and ride on `className` instead (#261). Frozen
     * responsive/JS hidden base states are normalized away (#259).
     *
     * @return array<string, mixed>
     */
    public function presentationAttributes(DOMElement $element, array $excludedGeometryProperties = array(), array $forcedGeometryProperties = array()): array
    {
        return $this->resolvedPresentationAttributes($element, $excludedGeometryProperties, $forcedGeometryProperties, false);
    }

    /**
     * Preserve inline-only geometry entirely in the generated carrier because
     * core/media-text cannot serialize arbitrary wrapper geometry inline.
     *
     * @return array<string, mixed>
     */
    public function mediaTextPresentationAttributes(DOMElement $element, array $excludedGeometryProperties = array()): array
    {
        return $this->resolvedPresentationAttributes($element, $excludedGeometryProperties, array(), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvedPresentationAttributes(
        DOMElement $element,
        array $excludedGeometryProperties,
        array $forcedGeometryProperties,
        bool $carrierOwnsInlineGeometry
    ): array
    {
        $cache = $this->context->presentationResolutionCache();
        $cacheKey = $cache->elementKey($element)
            . ':' . implode(',', $excludedGeometryProperties)
            . ':' . implode(',', $forcedGeometryProperties)
            . ':' . ($carrierOwnsInlineGeometry ? 'carrier' : 'inline');
        if ( isset($cache->attributes[$cacheKey]) ) {
            return $cache->attributes[$cacheKey];
        }

        $declarations = $this->classOwnedResponsiveDeclarations(
            $element,
            $this->presentationDeclarations($element)
        );
        $declarations = $this->classOwnedBackgroundPaintDeclarations($element, $declarations);
        $mapped       = $this->styleAttributeMapper()->map(
            $declarations,
            fn (string $value): string => $this->resolveCssVariablesInValue($value, $element)
        );
        $forcedGeometryDeclarations = array() === $forcedGeometryProperties
            ? array()
            : $this->cssDeclarations((string) ($this->styleAttributeMapper()->serialize($mapped['style'] ?? array())['style'] ?? ''));

        $attrs = array_filter(array_merge($mapped['attrs'] ?? array(), array(
            'anchor'    => $this->context->safeAnchor($this->context->attr($element, 'id')),
            'className' => $this->mergePresentationClassNames(
                $this->inlineStyleDeclaresAllReset($element) ? '' : $this->context->promotedClassName($this->context->attr($element, 'class')),
                $this->editorAnchorClassName($element),
                $this->inlineGeometryClassName(
                    $element,
                    $excludedGeometryProperties,
                    $forcedGeometryProperties,
                    $forcedGeometryDeclarations,
                    $carrierOwnsInlineGeometry
                )
            ),
            'inlineGeometryStyle' => $this->inlineGeometryStyle($element, $excludedGeometryProperties, $forcedGeometryProperties),
            'style'     => $mapped['style'],
            'layout'    => $this->layoutAttribute($element, $this->cssDeclarationString($declarations)),
        )), static fn ($value): bool => is_array($value) ? array() !== $value : '' !== trim((string) $value));

        $cache->attributes[$cacheKey] = $attrs;

        return $attrs;
    }

    /**
     * Keep declarations with conditional variants under author stylesheet
     * ownership. Promoting their base values to block supports would serialize
     * them inline and prevent media/container queries from winning the cascade.
     * Explicit source inline declarations retain their normal priority.
     *
     * @param array<string, string> $declarations
     * @return array<string, string>
     */
    private function classOwnedResponsiveDeclarations(DOMElement $element, array $declarations): array
    {
        if (array() === $declarations || array() === $this->context->sourceStyles()->conditionalRules()) {
            return $declarations;
        }

        $conditionalFamilies = array();
        foreach ($this->styleRuleCandidates($element, 'conditional') as $rule) {
            if (! $this->matchesCssSelector($element, $rule['selector'])) {
                continue;
            }
            foreach (array_keys($rule['declarations']) as $property) {
                $conditionalFamilies[$this->responsivePropertyFamily($property)] = true;
            }
        }

        if (array() === $conditionalFamilies) {
            return $declarations;
        }

        $inline = $this->cssDeclarations($this->context->attr($element, 'style'));
        foreach (array_keys($declarations) as $property) {
            $family = $this->responsivePropertyFamily($property);
            if (! isset($conditionalFamilies[$family]) || $this->inlineOwnsResponsiveProperty($property, $family, $inline)) {
                continue;
            }
            unset($declarations[$property]);
        }

        return $declarations;
    }

    /**
     * Background support emits inline declarations and `has-background`, which
     * changes the cascade for matched stylesheet rules. Keep author-owned paint
     * in the projected stylesheet; source inline declarations retain support
     * mapping because their cascade ownership is already inline.
     *
     * @param array<string, string> $declarations
     * @return array<string, string>
     */
    private function classOwnedBackgroundPaintDeclarations(DOMElement $element, array $declarations): array
    {
        $inline = $this->cssDeclarations($this->context->attr($element, 'style'));
        foreach ( array(
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
        ) as $property ) {
            if ( ! isset($inline[ $property ]) ) {
                unset($declarations[ $property ]);
            }
        }

        return $declarations;
    }

    public function responsivePropertyFamily(string $property): string
    {
        $property = strtolower(trim($property));
        if (
            in_array($property, array('display', 'gap', 'row-gap', 'column-gap', 'justify-content', 'align-content', 'align-items', 'align-self'), true)
            || str_starts_with($property, 'flex-')
            || str_starts_with($property, 'grid-')
        ) {
            return 'layout';
        }
        foreach (array('padding', 'margin', 'border', 'background') as $family) {
            if ($property === $family || str_starts_with($property, $family . '-')) {
                return $family;
            }
        }

        return $property;
    }

    /**
     * @param array<string, string> $inline
     */
    public function inlineOwnsResponsiveProperty(string $property, string $family, array $inline): bool
    {
        if (isset($inline[$property])) {
            return true;
        }

        return $property !== $family && isset($inline[$family]);
    }

    public function hasConditionalStyleFamily(DOMElement $element, string $family): bool
    {
        foreach ($this->styleRuleCandidates($element, 'conditional') as $rule) {
            if (! $this->matchesCssSelector($element, $rule['selector'])) {
                continue;
            }
            foreach (array_keys($rule['declarations']) as $property) {
                if ($family === $this->responsivePropertyFamily($property)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Core supports cannot serialize arbitrary box dimensions. Keep only source
     * inline geometry in a generated stylesheet; class-owned declarations are
     * already retained by author stylesheet materialization.
     */
    public function inlineGeometryClassName(
        DOMElement $element,
        array $excludedProperties = array(),
        array $forcedProperties = array(),
        array $forcedDeclarations = array(),
        bool $carrierOwnsInlineGeometry = false
    ): string
    {
        $declarations = $carrierOwnsInlineGeometry
            ? $this->mediaTextInlineCascadeDeclarations($this->context->attr($element, 'style'))
            : $this->cssDeclarations($this->context->attr($element, 'style'));
        $declarations = $this->stripFrozenHiddenState($element, $declarations);
        $geometry = array();
        $properties = $this->inlineGeometryProperties();
        if ( $this->isNamedFragmentTarget($element) ) {
            $properties = array_merge($properties, $this->namedFragmentTargetProperties());
        }
        if ( $this->inlineDisplayOverridesAuthorLayout($element, $declarations) ) {
            $inlineDisplay = strtolower(trim((string) preg_replace('/\s*!\s*important\s*$/i', '', (string) ($declarations['display'] ?? ''))));
            if ( ! in_array($inlineDisplay, array( 'flex', 'inline-flex' ), true) ) {
                $properties = array_values(array_diff(
                    $properties,
                    array( 'flex-direction', 'flex-wrap', 'align-items', 'justify-content', 'gap' )
                ));
            }
        } else {
            $properties = array_values(array_diff($properties, $this->inlineLayoutCarrierProperties()));
            // The author stylesheet, not the inline style, establishes this
            // element's flex/grid formatting context, so its inline alignment
            // declarations are still the source's own and still need carrying.
            // A <div> reaches the same rescue through cssOwnedFlexAttributes();
            // a <p> or <ul> never can, because that path is gated on
            // ShellLandmarkPolicy::isFlowContainerTag(). Gate on the inline
            // intersection so no `gap` or `align-items` the author's media
            // queries own can be synthesized here.
            if ( $this->authorResolvedDisplayEstablishesFlexOrGrid($element) ) {
                $properties = array_merge($properties, array_values(array_intersect(
                    $this->inlineFlexAlignmentCarrierProperties(),
                    array_keys($declarations)
                )));
            }
        }
        $inlineBackground = (string) ($declarations['background'] ?? $declarations['background-image'] ?? '');
        if ( preg_match('/\burl\s*\(/i', $inlineBackground)
            && ( 0 < $this->context->directElementChildCount($element) || '' !== trim((string) $element->textContent) )
        ) {
            $properties = array_merge($properties, $this->inlineBackgroundCarrierProperties());
        }
        foreach (array_values(array_unique(array_merge($properties, $forcedProperties))) as $property) {
            if (in_array($property, $excludedProperties, true)) {
                continue;
            }
            $rawValue = trim((string) ($declarations[$property] ?? ($forcedDeclarations[$property] ?? '')));
            $value = trim(preg_replace('/\s*!\s*important\s*$/i', '', $rawValue) ?? $rawValue);
            if (in_array($property, array( 'width', 'height', 'min-width', 'min-height', 'max-width', 'max-height' ), true)
                && preg_match('/^(?:\d+|\d*\.\d+)$/', $value)
            ) {
                $value .= 'px';
            }
            if ( in_array($property, array( 'background', 'background-image', 'list-style', 'list-style-image' ), true) ) {
                $value = CssUrlRewriter::rewrite($value, fn (string $url): string => $this->context->resolvedAssetImageUrl($url));
            }
            if ('' !== $value && ! preg_match('~[{}<>;]|/\*~', $value)) {
                $geometry[$property] = $value;
            }
        }

        // Inline declarations that exist in order to OVERRIDE author CSS. The
        // "drop it and rely on the preserved className plus the carried author
        // CSS" premise inverts for these: dropping them does not fall back to
        // the same styling, it falls back to the OPPOSITE styling.
        $overrideDeclarations = $this->inlineAuthorOverrideDeclarations(
            $element,
            $declarations,
            $geometry,
            array_merge($excludedProperties, $forcedProperties)
        );
        foreach ($overrideDeclarations as $property => $value) {
            $geometry[$property] = $value;
        }

        // `text-align` rides an EXISTING container carrier and never mints one on
        // its own. A carrier class is what promotes an otherwise attribute-less
        // wrapper into a core/group, so minting one here would add block-tree
        // structure to every wrapper whose only inline declaration is an
        // alignment — a topology change, not a styling fix.
        if ( array() !== $geometry ) {
            foreach ($this->inlineInheritedTextAlignDeclaration($element, $declarations, $excludedProperties) as $property => $value) {
                $geometry[$property] = $value;
                $overrideDeclarations[$property] = $value;
            }
        }

        // Core block supports drop arbitrary custom properties when parsing a
        // saved style attribute. Carry them in the generated stylesheet instead.
        foreach ($this->inlineCustomPropertyDeclarations($element, $declarations, array_values($geometry)) as $property => $value) {
            $geometry[$property] = $value;
        }

        if (array() === $geometry) {
            return '';
        }

        // Emit carried declarations in source order. For declarations sharing
        // a priority tier, last-write-wins is decided by rule order, and an
        // alphabetical sort silently flips shorthand/longhand winners (grid vs
        // grid-template-columns, gap vs column-gap). Values not present inline
        // (forced/custom-property fallbacks) sort last.
        $sourceOrder = array_flip(array_keys($declarations));
        uksort($geometry, static fn (string $a, string $b): int => (($sourceOrder[$a] ?? PHP_INT_MAX) <=> ($sourceOrder[$b] ?? PHP_INT_MAX)) ?: strcmp($a, $b));
        $normalPriorityDeclarations = array();
        $importantDeclarations = array();
        $forcedPropertyLookup = array_fill_keys($forcedProperties, true);
        $inlineLayoutPropertyLookup = array_fill_keys($this->inlineLayoutCarrierProperties(), true);
        $inlineListMarkerPropertyLookup = array_fill_keys($this->inlineListMarkerCarrierProperties(), true);
        // Author-override carriers stay in the non-important tier. At (0,2,0)
        // the `:root .x` selector already outranks the plain single-class rule
        // being overridden, at every viewport, because a media query adds no
        // specificity. The !important tier would additionally beat authored
        // non-important `:hover`/`:focus` rules and delete the interactive
        // states the source still wants.
        // Background-image heroes pin a definite box through :root .carrier
        // (0,2,0). Other height carriers still need !important to beat IDs.
        if ( isset($geometry['height']) && preg_match('/\burl\s*\(/i', $inlineBackground) ) {
            $overrideDeclarations['height'] = $geometry['height'];
        }
        $overridePropertyLookup = array_fill_keys(array_keys($overrideDeclarations), true);
        foreach ($geometry as $property => $value) {
            if ( isset($inlineListMarkerPropertyLookup[$property])
                || isset($overridePropertyLookup[$property])
                || ( isset($inlineLayoutPropertyLookup[$property]) && ! isset($forcedPropertyLookup[$property]) )
            ) {
                // Preserve source inline layout and list markers over a later
                // plain author class without introducing !important.
                $normalPriorityDeclarations[] = $property . ':' . $value;
                continue;
            }

            // A converted inline declaration must continue to outrank authored
            // normal selectors, including ID selectors. Authored !important
            // rules retain their normal cascade priority through specificity.
            $importantDeclarations[] = $property . ':' . $value . ' !important';
        }
        $signature = implode(';', array_merge($normalPriorityDeclarations, $importantDeclarations));
        $className = $this->context->layoutGeometry()->allocateCarrier($this->geometryStructuralPath($element) . "\n" . $signature);
        $rules = array();
        if ( array() !== $normalPriorityDeclarations ) {
            $rules[] = ':root .' . $className . '{' . implode(';', $normalPriorityDeclarations) . '}';
        }
        if ( array() !== $importantDeclarations ) {
            $rules[] = '.' . $className . '{' . implode(';', $importantDeclarations) . '}';
        }
        $this->context->layoutGeometry()->registerRule($className, implode("\n", $rules));

        return $className;
    }

    /**
     * An inline display needs a carrier when materialized author CSS would
     * otherwise reassert a different layout mode on the transformed element, OR
     * when no author rule supplies `display` at all and the inline value differs
     * from the transformed tag's own default. Conditional variants count because
     * the inline declaration owns every viewport in the source document.
     *
     * The second case is not optional. A `.badge` reused for its paint declares
     * no `display`, so restoring its inline `position:static` without its inline
     * `display:inline-block` turns the pill into a flow-level block box at the
     * container's full content width, with a solid background and a 999px
     * radius — a worse regression than the overlap being fixed.
     *
     * This predicate governs the CARRIER only. It must not be used to choose a
     * priority tier: `cssOwnedFlexAttributes()` keys its forced-property branch
     * off the narrower `inlineDisplayConflictsWithAuthorLayout()`, because the
     * non-important tier is only sound for the CONFLICT case. Widening the tier
     * to the differs-from-tag-default population demotes a carrier from
     * `!important` to `:root .x` at (0,2,0), where any author selector with three
     * or more weighted tokens on the same element wins and the source's own
     * inline value stops rendering.
     *
     * @param array<string, string> $inlineDeclarations
     */
    private function inlineDisplayOverridesAuthorLayout(DOMElement $element, array $inlineDeclarations): bool
    {
        $inlineDisplay = $this->inlineDisplayValue($inlineDeclarations);
        if ( '' === $inlineDisplay ) {
            return false;
        }

        if ( $this->inlineDisplayConflictsWithAuthorLayout($element, $inlineDeclarations) ) {
            return true;
        }

        foreach ( $this->styleRuleCandidates($element, 'static-conditional') as $rule ) {
            if ( ! $this->matchesCssSelector($element, $rule['selector']) ) {
                continue;
            }
            if ( '' !== $this->authorDisplayValue($rule) ) {
                // An author rule supplies `display` and agrees with the inline
                // value, so the materialized stylesheet already carries it.
                return false;
            }
        }

        return $inlineDisplay !== $this->defaultTagDisplay($element);
    }

    /**
     * Whether materialized author CSS would reassert a DIFFERENT layout mode on
     * the transformed element. This is the original, narrower question, and the
     * only one that may drive a priority-tier choice.
     *
     * @param array<string, string> $inlineDeclarations
     */
    public function inlineDisplayConflictsWithAuthorLayout(DOMElement $element, array $inlineDeclarations): bool
    {
        $inlineDisplay = $this->inlineDisplayValue($inlineDeclarations);
        if ( '' === $inlineDisplay ) {
            return false;
        }

        foreach ( $this->styleRuleCandidates($element, 'static-conditional') as $rule ) {
            if ( ! $this->matchesCssSelector($element, $rule['selector']) ) {
                continue;
            }
            $authorDisplay = $this->authorDisplayValue($rule);
            if ( '' !== $authorDisplay && $inlineDisplay !== $authorDisplay ) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, string> $inlineDeclarations */
    private function inlineDisplayValue(array $inlineDeclarations): string
    {
        return strtolower(trim((string) preg_replace(
            '/\s*!\s*important\s*$/i',
            '',
            (string) ($inlineDeclarations['display'] ?? '')
        )));
    }

    /** @param array<string, mixed> $rule */
    private function authorDisplayValue(array $rule): string
    {
        return strtolower(trim((string) preg_replace(
            '/\s*!\s*important\s*$/i',
            '',
            (string) ($rule['declarations']['display'] ?? '')
        )));
    }

    /**
     * The transformed tag's own default display. An inline `display` differing
     * from it is overriding the ELEMENT'S default, with no author rule involved.
     *
     * Unlisted tags fall back to `block` rather than to CSS's true `inline`
     * default: the population here is HTML5 sectioning and content elements, and
     * a `block` fallback keeps an unrecognized tag a no-op instead of minting a
     * carrier from a guess.
     */
    private function defaultTagDisplay(DOMElement $element): string
    {
        $defaults = array(
            'a' => 'inline', 'abbr' => 'inline', 'b' => 'inline', 'bdi' => 'inline', 'bdo' => 'inline',
            'br' => 'inline', 'cite' => 'inline', 'code' => 'inline', 'data' => 'inline', 'dfn' => 'inline',
            'em' => 'inline', 'i' => 'inline', 'img' => 'inline', 'kbd' => 'inline', 'label' => 'inline',
            'mark' => 'inline', 'picture' => 'inline', 'q' => 'inline', 's' => 'inline', 'samp' => 'inline',
            'small' => 'inline', 'span' => 'inline', 'strong' => 'inline', 'sub' => 'inline',
            'sup' => 'inline', 'svg' => 'inline', 'time' => 'inline', 'u' => 'inline', 'var' => 'inline',
            'wbr' => 'inline',
            'button' => 'inline-block', 'input' => 'inline-block', 'select' => 'inline-block',
            'textarea' => 'inline-block',
            'li' => 'list-item',
            'table' => 'table', 'caption' => 'table-caption', 'colgroup' => 'table-column-group',
            'col' => 'table-column', 'thead' => 'table-header-group', 'tbody' => 'table-row-group',
            'tfoot' => 'table-footer-group', 'tr' => 'table-row', 'td' => 'table-cell', 'th' => 'table-cell',
        );

        return $defaults[ strtolower($element->tagName) ] ?? 'block';
    }

    private function authorResolvedDisplayEstablishesFlexOrGrid(DOMElement $element): bool
    {
        $display = strtolower(trim((string) preg_replace(
            '/\s*!\s*important\s*$/i',
            '',
            (string) ($this->structuralPresentationDeclarations($element)['display'] ?? '')
        )));

        return in_array($display, array( 'flex', 'inline-flex', 'grid', 'inline-grid' ), true);
    }

    /**
     * Inline declarations which map to no block support and would otherwise be
     * dropped, in the two cases where dropping them changes the rendering:
     * a matching author rule declares the same property with a DIFFERENT value,
     * or no author rule declares it at all and it is on the narrow unmatched
     * allowlist.
     *
     * @param array<string, string> $inlineDeclarations
     * @param array<string, string> $carried already-selected geometry declarations
     * @param array<int, string> $excludedProperties
     * @return array<string, string>
     */
    private function inlineAuthorOverrideDeclarations(
        DOMElement $element,
        array $inlineDeclarations,
        array $carried,
        array $excludedProperties
    ): array {
        $candidates = $this->styleAttributeMapper()->map(
            $inlineDeclarations,
            fn (string $value): string => $this->resolveCssVariablesInValue($value, $element)
        )['leftover'] ?? array();
        if ( isset($inlineDeclarations['box-shadow']) ) {
            $candidates['box-shadow'] = $inlineDeclarations['box-shadow'];
        }
        foreach ( array_keys($candidates) as $property ) {
            if ( isset($carried[ $property ])
                || in_array($property, $excludedProperties, true)
                || str_starts_with($property, '--')
                // `text-align` has its own inherited-value gate below; carrying
                // it here would bypass that gate.
                || 'text-align' === $property
            ) {
                unset($candidates[ $property ]);
            }
        }
        if ( array() === $candidates ) {
            return array();
        }

        $authorDeclared = $this->authorDeclaredPropertyValues($element, array_keys($candidates));
        $unmatchedCarrier = $this->inlineUnmatchedCarrierProperties();
        $overrides = array();
        foreach ( $candidates as $property => $rawValue ) {
            $value = $this->carriedDeclarationValue($rawValue);
            if ( '' === $value ) {
                continue;
            }
            if ( ! isset($authorDeclared[ $property ]) ) {
                if ( in_array($property, $unmatchedCarrier, true) ) {
                    $overrides[ $property ] = $value;
                }
                continue;
            }
            if ( ! in_array($this->context->cssComparableValue($value), $authorDeclared[ $property ], true) ) {
                $overrides[ $property ] = $value;
            }
        }

        return $overrides;
    }

    /**
     * Author-declared values for the given properties, from the matching rules
     * THE COLLECTED RULE SET RETAINS.
     *
     * KNOWN LIMITATION, load-bearing: `staticStyleRules` and
     * `conditionalStyleRules` are filtered through `safeVisualDeclarations()`
     * before they are stored, so only properties on that 86-entry allowlist are
     * visible here. `position`, `z-index` and `direction` are on it; `overflow`,
     * `overflow-x/y`, `top`, `right`, `bottom`, `left`, `transform`,
     * `transition`, `animation`, `opacity`, `visibility`, `float`, `clear`,
     * `align-self`, `justify-self`, `white-space` and `cursor`
     * are NOT. For those, an author declaration cannot register, the inline
     * override falls into the "no author rule declares it" branch, and it is
     * dropped unless it is on the narrow unmatched allowlist — while the
     * materialized author stylesheet still asserts the opposite value verbatim.
     * The conflict rescue is therefore property-dependent by construction, and
     * closing it means collecting an unfiltered rule set, which is a change to
     * every rule-collection path rather than to this one.
     *
     * Pseudo-state selectors are unsupported by the matcher and so never register
     * here: a `:hover` box-shadow does not make a resting-state inline
     * box-shadow redundant.
     *
     * @param array<int, string> $properties
     * @return array<string, array<int, string>>
     */
    public function authorDeclaredPropertyValues(DOMElement $element, array $properties): array
    {
        $cache = $this->context->sourceStyles();
        sort($properties, SORT_STRING);
        $cacheKey = $this->context->presentationResolutionCache()->elementKey($element) . ':' . implode(',', $properties);
        if ( isset($cache->authorDeclaredPropertyValues[ $cacheKey ]) ) {
            return $cache->authorDeclaredPropertyValues[ $cacheKey ];
        }

        $wanted = array_fill_keys($properties, true);
        $declared = array();
        foreach ( $this->styleRuleCandidates($element, 'static-conditional') as $rule ) {
            if ( ! $this->matchesCssSelector($element, $rule['selector']) ) {
                continue;
            }
            foreach ( $rule['declarations'] as $property => $value ) {
                if ( isset($wanted[ strtolower((string) $property) ]) ) {
                    $declared[ strtolower((string) $property) ][] = $this->context->cssComparableValue((string) $value);
                }
            }
        }

        $cache->authorDeclaredPropertyValues[ $cacheKey ] = $declared;

        return $declared;
    }

    /**
     * One `text-align` declaration on a container carrier restores its whole
     * subtree, which is the source's own inheritance semantics: it covers block
     * types with no `align` support at all (core/list, core/group) and emits one
     * declaration instead of N attributes. Leaves keep using createBlock()'s
     * element-scoped `align` attribute so the editor's alignment control still
     * reflects reality — this is the INHERITED case only.
     *
     * The caller only consults this once the element already has a carrier, so a
     * container whose ONLY inline declaration is an alignment is deliberately not
     * covered: minting a carrier for it would promote a bare wrapper into a
     * core/group and change the block tree.
     *
     * @param array<string, string> $declarations
     * @param array<int, string> $excludedProperties
     * @return array<string, string>
     */
    private function inlineInheritedTextAlignDeclaration(DOMElement $element, array $declarations, array $excludedProperties): array
    {
        if ( in_array('text-align', $excludedProperties, true) || 0 === $this->context->directElementChildCount($element) ) {
            return array();
        }

        $value = $this->carriedDeclarationValue((string) ($declarations['text-align'] ?? ''));
        if ( '' === $value ) {
            return array();
        }

        $rightToLeft = $this->isRightToLeftElement($element);
        if ( $this->comparableTextAlignment($value, $rightToLeft) === $this->effectiveTextAlignmentWithoutInline($element, $rightToLeft) ) {
            return array();
        }

        return array( 'text-align' => $value );
    }

    /**
     * What this element's alignment would resolve to if the inline declaration
     * were removed: its OWN author-declared `text-align` when it has one, and
     * only otherwise the value inherited from its ancestors.
     *
     * Consulting the element's own author rule is the whole point. An element
     * whose class sets `text-align:center` and whose inline style sets `left` has
     * no ancestor alignment to compare against, so an ancestor-only walk resolves
     * to the document default, matches `left`, and skips the carrier — leaving the
     * class rule to win and render centred where the source rendered left. That is
     * the same inverted premise the conflict rescue exists to close.
     *
     * `structuralPresentationDeclarations()` is deliberately NOT used here: it
     * merges the inline style in, so comparing against it would always be equal
     * and would skip every carrier.
     */
    private function effectiveTextAlignmentWithoutInline(DOMElement $element, bool $rightToLeft): string
    {
        $authorDeclared = $this->authorDeclaredPropertyValues($element, array( 'text-align' ))['text-align'] ?? array();
        // Later declarations win at equal specificity, so the last match is the
        // closest available stand-in for the author cascade's own winner.
        for ( $index = count($authorDeclared) - 1; $index >= 0; $index-- ) {
            $own = $this->comparableTextAlignment((string) $authorDeclared[ $index ], $rightToLeft);
            if ( '' !== $own ) {
                return $own;
            }
        }

        return $this->inheritedTextAlignment($element, $rightToLeft);
    }

    /**
     * The alignment this element inherits, resolved from its ancestors and
     * falling back to the tag's own UA default. `text-align` is inherited, so a
     * carrier is warranted only when the inline value differs from what the
     * element would have resolved to anyway.
     */
    private function inheritedTextAlignment(DOMElement $element, bool $rightToLeft): string
    {
        for ( $ancestor = $element->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode ) {
            $inherited = $this->comparableTextAlignment(
                (string) ($this->structuralPresentationDeclarations($ancestor)['text-align'] ?? ''),
                $rightToLeft
            );
            if ( '' !== $inherited ) {
                return $inherited;
            }
        }

        // The UA stylesheet centers table captions and header cells; every other
        // element starts at the writing-mode start edge.
        return in_array(strtolower($element->tagName), array( 'caption', 'th' ), true)
            ? 'center'
            : 'start';
    }

    /** `left` and `start` are one alignment in LTR, as are `right` and `start` in RTL. */
    private function comparableTextAlignment(string $value, bool $rightToLeft): string
    {
        $value = strtolower(trim(preg_replace('/\s*!\s*important\s*$/i', '', trim($value)) ?? $value));

        return $value === ( $rightToLeft ? 'right' : 'left' ) ? 'start' : $value;
    }

    private function isRightToLeftElement(DOMElement $element): bool
    {
        for ( $node = $element; $node instanceof DOMElement; $node = $node->parentNode ) {
            $direction = strtolower(trim($this->context->attr($node, 'dir')));
            if ( '' !== $direction ) {
                return 'rtl' === $direction;
            }
        }

        return false;
    }

    /**
     * Strip `!important` and reject any value that could break out of the
     * generated rule, matching the geometry loop's own guard.
     *
     * Anything that can leave the emitted rule's own closing brace unreachable is
     * rejected, because this path carries values such as `box-shadow` whose
     * grammar is full of parentheses, quotes and escapes. Three ways to do it,
     * all verified to swallow the NEXT carrier rule in a browser:
     *   - an unclosed `rgba(`, which makes the parser consume the brace hunting
     *     for the `)`;
     *   - an odd number of `'` or `"`, which puts the brace inside a string;
     *   - a trailing backslash, which escapes the brace itself.
     * In each case the corruption lands on an unrelated element's styling, so the
     * malformed value is dropped rather than carried.
     */
    private function carriedDeclarationValue(string $rawValue): string
    {
        $value = trim(preg_replace('/\s*!\s*important\s*$/i', '', trim($rawValue)) ?? $rawValue);
        if ( '' === $value || preg_match('~[{}<>;]|/\*~', $value) ) {
            return '';
        }
        if ( substr_count($value, '(') !== substr_count($value, ')') ) {
            return '';
        }
        if ( 0 !== substr_count($value, '"') % 2 || 0 !== substr_count($value, "'") % 2 ) {
            return '';
        }
        // An odd trailing run of backslashes escapes whatever follows the value,
        // which in the emitted rule is the closing brace.
        if ( 1 === preg_match('/(\\\\+)$/', $value, $trailing) && 0 !== strlen($trailing[1]) % 2 ) {
            return '';
        }

        return $value;
    }

    /**
     * A bare source <img> serializes inside a generated
     * <figure class="wp-block-image">. An authored percentage height on the
     * image then resolves against that auto-height figure instead of the
     * source container and collapses the image to its intrinsic ratio. Carry
     * height:100% on the injected figure so authored percentage sizing keeps
     * resolving against the original container box. When the container height
     * is auto the figure percentage computes back to auto, so the carry stays
     * faithful even when the driving rule lives behind a media query.
     */
    public function injectedFigureHeightClassName(DOMElement $image): string
    {
        if ( ! $this->authorStylesDriveImageHeight($image) ) {
            return '';
        }

        $rule = 'height:100% !important';
        $className = $this->context->layoutGeometry()->allocateCarrier('figure-height' . "\n" . $this->geometryStructuralPath($image) . "\n" . $rule);
        $this->context->layoutGeometry()->registerRule($className, '.' . $className . '{' . $rule . '}');

        return $className;
    }

    private function authorStylesDriveImageHeight(DOMElement $image): bool
    {
        $declarations = $this->structuralPresentationDeclarations($image);
        foreach ( array( 'height', 'min-height' ) as $property ) {
            if ( $this->isCssPercentageValue((string) ($declarations[$property] ?? '')) ) {
                return true;
            }
        }

        foreach ( $this->styleRuleCandidates($image, 'conditional') as $rule ) {
            if ( ! $this->matchesCssSelector($image, $rule['selector']) ) {
                continue;
            }
            foreach ( array( 'height', 'min-height' ) as $property ) {
                if ( $this->isCssPercentageValue((string) ($rule['declarations'][$property] ?? '')) ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isCssPercentageValue(string $value): bool
    {
        $value = trim(preg_replace('/\s*!\s*important\s*$/i', '', $value) ?? $value);

        return 1 === preg_match('/^\d+(?:\.\d+)?%$/', $value);
    }

    public function geometryStructuralPath(DOMElement $element): string
    {
        $segments = array();
        for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode) {
            $index = 1;
            for ($sibling = $node->previousSibling; null !== $sibling; $sibling = $sibling->previousSibling) {
                if ($sibling instanceof DOMElement && strtolower($sibling->tagName) === strtolower($node->tagName)) {
                    ++$index;
                }
            }
            $segments[] = strtolower($node->tagName) . ':' . $index;
        }

        return implode('/', array_reverse($segments));
    }

    private function inlineGeometryStyle(DOMElement $element, array $excludedProperties = array(), array $forcedProperties = array()): string
    {
        $declarations = $this->cssDeclarations($this->context->attr($element, 'style'));
        $style = array();
        $geometryValues = array();
        $properties = $this->inlineGeometryProperties();
        if ( $this->isNamedFragmentTarget($element) ) {
            $properties = array_merge($properties, $this->namedFragmentTargetProperties());
        }
        foreach (array_values(array_unique(array_merge($properties, $forcedProperties))) as $property) {
            if (in_array($property, $excludedProperties, true)) {
                continue;
            }
            $value = trim((string) ($declarations[$property] ?? ''));
            $geometryValues[] = $value;
            if (1 === preg_match('/\s*!important\s*$/i', $value)) {
                $style[] = $property . ':' . $value;
            }
        }

        return implode(';', $style);
    }

    /**
     * @param array<string, string> $declarations
     * @param array<int, string> $geometryValues
     * @return array<string, string>
     */
    private function inlineCustomPropertyDeclarations(DOMElement $element, array $declarations, array $geometryValues): array
    {
        $required = $this->inlineCustomPropertiesRequired(
            $declarations,
            $this->inlineCustomPropertiesConsumedByAuthorStyles($element, $declarations) + $this->customPropertiesReferencedByValues($geometryValues)
        );
        $customProperties = array();
        foreach ($declarations as $property => $value) {
            if (str_starts_with($property, '--') && isset($required[$property])) {
                $customProperties[$property] = $value;
            }
        }
        ksort($customProperties, SORT_STRING);

        return $customProperties;
    }

    /**
     * @param array<string, string> $declarations
     * @return array<string, true>
     */
    private function inlineCustomPropertiesConsumedByAuthorStyles(DOMElement $element, array $declarations): array
    {
        $declared = array_fill_keys(array_filter(array_keys($declarations), static fn (string $property): bool => str_starts_with($property, '--')), true);
        if (array() === $declared) {
            return array();
        }

        $consumed = array();
        $inspect = function (DOMElement $target) use (&$consumed, $declared): void {
            foreach ($this->styleRuleCandidates($target, 'static-conditional-pseudo') as $rule) {
                if (! $this->matchesCssSelector($target, $rule['selector'])) {
                    continue;
                }
                $consumed += array_intersect_key($this->customPropertiesReferencedByValues($rule['declarations']), $declared);
            }
        };
        $inspect($element);
        foreach ($element->getElementsByTagName('*') as $descendant) {
            if ($descendant instanceof DOMElement) {
                $inspect($descendant);
            }
        }

        return $consumed;
    }

    /**
     * @param array<string|int, string> $values
     * @return array<string, true>
     */
    private function customPropertiesReferencedByValues(array $values): array
    {
        $properties = array();
        foreach ($values as $value) {
            if (preg_match_all('/\bvar\(\s*(--[-_a-zA-Z0-9]+)/', $value, $matches)) {
                foreach ($matches[1] as $property) {
                    $properties[strtolower($property)] = true;
                }
            }
        }

        return $properties;
    }

    /**
     * @param array<string, string> $declarations
     * @param array<string, true> $required
     * @return array<string, true>
     */
    private function inlineCustomPropertiesRequired(array $declarations, array $required): array
    {
        $pending = array_keys($required);
        while (array() !== $pending) {
            $property = array_pop($pending);
            if (! isset($declarations[$property])) {
                continue;
            }
            foreach (array_keys($this->customPropertiesReferencedByValues(array($declarations[$property]))) as $dependency) {
                if (! isset($required[$dependency])) {
                    $required[$dependency] = true;
                    $pending[] = $dependency;
                }
            }
        }

        return $required;
    }

    private function isCssAllResetValue(string $value): bool
    {
        $value = strtolower(trim(preg_replace('/\s*!\s*important\s*$/i', '', $value) ?? $value));

        return in_array($value, array( 'unset', 'initial', 'revert', 'revert-layer' ), true);
    }

    /**
     * An inline `all` reset is the author's explicit opt-out of every
     * class-owned recipe on this element. The reset itself cannot ride to the
     * block, so the source classes must not either: the materialized author
     * stylesheet would reassert the very declarations the reset removed.
     */
    private function inlineStyleDeclaresAllReset(DOMElement $element): bool
    {
        return $this->isCssAllResetValue((string) ($this->cssDeclarations($this->context->attr($element, 'style'))['all'] ?? ''));
    }

    public function mergePresentationClassNames(string ...$classNames): string
    {
        $classes = array();
        foreach ($classNames as $className) {
            foreach (preg_split('/\s+/', trim($className)) ?: array() as $class) {
                if ('' !== $class && ! in_array($class, $classes, true)) {
                    $classes[] = $class;
                }
            }
        }

        return implode(' ', $classes);
    }

    public function generatedGeometryCss(string $serializedBlocks): string
    {
        return $this->context->layoutGeometry()->cssForSerializedBlocks($serializedBlocks);
    }

    /**
     * @return array<string, string>
     */
    public function presentationDeclarations(DOMElement $element): array
    {
        $cache = $this->context->presentationResolutionCache();
        $cacheKey = $cache->elementKey($element);
        if ( isset($cache->declarations[$cacheKey]) ) {
            return $cache->declarations[$cacheKey];
        }

        $style = $this->mergedPresentationStyle($element);
        $declarations = $this->stripFrozenHiddenState($element, $this->cssDeclarations($style));
        // Elements below the high-value boundary skip declaration merging, so
        // an inline `all` reset can still reach here verbatim. It maps to no
        // block support and must not leak into layout/style resolution.
        if ( $this->isCssAllResetValue((string) ($declarations['all'] ?? '')) ) {
            unset($declarations['all']);
        }
        $cache->declarations[$cacheKey] = $declarations;

        return $cache->declarations[$cacheKey];
    }

    /**
     * Resolve structural context even when the element is not itself a style
     * boundary. Child classification still needs parent flex/grid semantics.
     *
     * @return array<string, string>
     */
    public function structuralPresentationDeclarations(DOMElement $element): array
    {
        $cache = $this->context->sourceStyles();
        $cacheKey = $this->context->presentationResolutionCache()->elementKey($element);
        if ( isset($cache->structuralDeclarations[$cacheKey]) ) {
            ++$this->analysisCache->sourceStructuralDeclarationHits;
            return $cache->structuralDeclarations[$cacheKey];
        }
        ++$this->analysisCache->sourceStructuralDeclarationBuilds;

        $declarations = array();
        foreach ( $this->styleRuleCandidates($element, 'static') as $rule ) {
            if ( $this->matchesCssSelector($element, $rule['selector']) ) {
                $declarations = $this->mergeCssDeclarationMaps($declarations, $rule['declarations']);
            }
        }

        return $cache->structuralDeclarations[$cacheKey] = $this->mergeCssDeclarationMaps($declarations, $this->cssDeclarations($this->context->attr($element, 'style')));
    }

    /**
     * Resolve media-text gate declarations without flattening CSS importance or
     * shorthand/longhand order. Inline declarations outrank matched stylesheet
     * declarations at equal importance.
     *
     * @return array<string, string>
     */
    private function mediaTextPresentationDeclarations(DOMElement $element): array
    {
        $cascade = array();
        $sequence = 0;
        foreach ($this->styleRuleCandidates($element, 'static') as $rule) {
            if (! $this->matchesCssSelector($element, $rule['selector'])) {
                continue;
            }
            foreach ($rule['mediaTextDeclarations'] ?? array() as $entry) {
                $this->applyMediaTextCascadeDeclaration(
                    $cascade,
                    $entry['property'],
                    $entry['value'] . ($entry['important'] ? ' !important' : ''),
                    false,
                    $rule['mediaTextSpecificity'] ?? array( 0, 0, 0 ),
                    ++$sequence
                );
            }
        }

        foreach ($this->mediaTextInlineDeclarationEntries($this->context->attr($element, 'style')) as $entry) {
            $this->applyMediaTextCascadeDeclaration(
                $cascade,
                $entry['property'],
                $entry['value'] . ($entry['important'] ? ' !important' : ''),
                true,
                array( PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX ),
                ++$sequence
            );
        }

        $declarations = array();
        foreach ($cascade as $property => $entry) {
            $declarations[$property] = $entry['value'] . ($entry['important'] ? ' !important' : '');
        }

        return $declarations;
    }

    /**
     * @param array<string, array{value: string, important: bool, inline: bool, specificity: array{int, int, int}, sequence: int}> $cascade
     * @param array{int, int, int} $specificity
     */
    private function applyMediaTextCascadeDeclaration(
        array &$cascade,
        string $property,
        string $rawValue,
        bool $inline,
        array $specificity,
        int $sequence
    ): void {
        $property = str_starts_with($property, '--') ? $property : strtolower($property);
        $important = 1 === preg_match('/\s*!\s*important\s*$/i', $rawValue);
        $value = trim(preg_replace('/\s*!\s*important\s*$/i', '', $rawValue) ?? $rawValue);
        if ('' === $property || '' === $value) {
            return;
        }

        if ('flex-flow' === $property) {
            $property = 'flex-direction';
            // A var() flow is statically unresolvable — keep it verbatim so the
            // strict gate declines on it instead of defaulting to row.
            if (1 !== preg_match('/var\s*\(/i', $value)) {
                $flowDirection = null;
                foreach (CssValueSplitter::splitTopLevelWhitespace(strtolower($value)) as $component) {
                    if (in_array($component, array('row', 'row-reverse', 'column', 'column-reverse'), true)) {
                        $flowDirection = $component;
                        break;
                    }
                }
                $value = $flowDirection ?? (in_array(strtolower($value), array('inherit', 'unset', 'revert', 'revert-layer'), true) ? strtolower($value) : 'row');
            }
        }

        $current = $cascade[$property] ?? null;
        if (is_array($current)) {
            if ($current['important'] && ! $important) {
                return;
            }
            if ($current['important'] === $important) {
                $specificityComparison = $this->compareMediaTextSpecificity($current['specificity'], $specificity);
                if (0 < $specificityComparison) {
                    return;
                }
                if (0 === $specificityComparison && $current['sequence'] > $sequence) {
                    return;
                }
                if (0 === $specificityComparison && $current['sequence'] === $sequence && $current['inline'] && ! $inline) {
                    return;
                }
            }
        }

        $cascade[$property] = array(
            'value' => $value,
            'important' => $important,
            'inline' => $inline,
            'specificity' => $specificity,
            'sequence' => $sequence,
        );
    }

    /**
     * @return list<array{property: string, value: string, important: bool}>
     */
    private function mediaTextInlineDeclarationEntries(string $style): array
    {
        $entries = array();
        foreach (CssValueSplitter::splitTopLevel($style, array(';')) as $declaration) {
            $separator = strpos($declaration, ':');
            if (false === $separator) {
                continue;
            }

            $rawProperty = trim(substr($declaration, 0, $separator));
            $property = str_starts_with($rawProperty, '--') ? $rawProperty : strtolower($rawProperty);
            $rawValue = trim(substr($declaration, $separator + 1));
            $important = 1 === preg_match('/\s*!\s*important\s*$/i', $rawValue);
            $value = trim(preg_replace('/\s*!\s*important\s*$/i', '', $rawValue) ?? $rawValue);
            $value = preg_replace('/\s+/', ' ', $value) ?? $value;
            if ('' === $property
                || '' === $value
                || array() === $this->cssDeclarations($property . ':' . $value)
                || ! $this->isValidMediaTextDeclarationValue($property, $value)
            ) {
                continue;
            }

            $entries[] = array(
                'property' => $property,
                'value' => $value,
                'important' => $important,
            );
        }

        return $entries;
    }

    private function isValidMediaTextDeclarationValue(string $property, string $rawValue): bool
    {
        if (str_starts_with($property, '--')) {
            return true;
        }

        $value = strtolower(trim($rawValue));
        if (in_array($value, array('inherit', 'initial', 'revert', 'revert-layer', 'unset'), true)) {
            return true;
        }

        // var() values are valid CSS everywhere but statically unresolvable.
        // They must SURVIVE into the cascade so the strict gates can fail
        // closed on them — dropping them here makes the gate read "absent"
        // and convert with the default layout.
        if (1 === preg_match('/var\s*\(/i', $value)) {
            return true;
        }

        if ('display' === $property) {
            return in_array($value, array(
                'block', 'contents', 'flow-root', 'flex', 'grid', 'inline', 'inline-block',
                'inline-flex', 'inline-grid', 'inline-table', 'list-item', 'none', 'ruby',
                'ruby-base', 'ruby-base-container', 'ruby-text', 'ruby-text-container',
                'table', 'table-caption', 'table-cell', 'table-column', 'table-column-group',
                'table-footer-group', 'table-header-group', 'table-row', 'table-row-group',
            ), true) || 1 === preg_match('/^(?:block|inline)\s+(?:flow|flow-root|flex|grid|ruby)(?:\s+list-item)?$/', $value);
        }

        if ('flex-direction' === $property) {
            return in_array($value, array('column', 'column-reverse', 'row', 'row-reverse'), true);
        }

        if ('flex-flow' === $property) {
            $directions = array('column', 'column-reverse', 'row', 'row-reverse');
            $wraps = array('nowrap', 'wrap', 'wrap-reverse');
            $seenDirection = false;
            $seenWrap = false;
            $components = CssValueSplitter::splitTopLevelWhitespace($value);
            if (array() === $components || 2 < count($components)) {
                return false;
            }
            foreach ($components as $component) {
                if (in_array($component, $directions, true) && ! $seenDirection) {
                    $seenDirection = true;
                    continue;
                }
                if (in_array($component, $wraps, true) && ! $seenWrap) {
                    $seenWrap = true;
                    continue;
                }
                return false;
            }
            return true;
        }

        if ('order' === $property) {
            return is_numeric($value);
        }

        if ('align-items' === $property) {
            return in_array($value, array(
                'anchor-center', 'baseline', 'center', 'dialog', 'end', 'first baseline',
                'flex-end', 'flex-start', 'last baseline', 'normal', 'self-end', 'self-start',
                'start', 'stretch',
            ), true) || 1 === preg_match('/^(?:safe|unsafe)\s+(?:center|end|flex-end|flex-start|self-end|self-start|start)$/', $value);
        }

        if ('direction' === $property) {
            return in_array($value, array('ltr', 'rtl'), true);
        }

        if (in_array($property, array('flex-basis', 'width'), true)) {
            return in_array($value, array('auto', 'contain', 'content', 'fit-content', 'max-content', 'min-content', 'stretch'), true)
                || 1 === preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:%|[a-z]+)?$/i', $value)
                || 1 === preg_match('/^(?:calc|clamp|fit-content|max|min|var)\(.+\)$/i', $value);
        }

        if ('grid-template-columns' === $property) {
            return $this->isValidMediaTextGridTemplateColumns($value);
        }

        return true;
    }

    private function isValidMediaTextGridTemplateColumns(string $value): bool
    {
        if (in_array($value, array('masonry', 'none', 'subgrid'), true)) {
            return true;
        }

        $tracks = CssValueSplitter::splitTopLevelWhitespace($value);
        if (array() === $tracks) {
            return false;
        }
        foreach ($tracks as $track) {
            if (in_array($track, array('auto', 'max-content', 'min-content'), true)
                || 1 === preg_match('/^\[[^\]]+\]$/', $track)
                || 1 === preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:%|fr|[a-z]+)$/i', $track)
                || 1 === preg_match('/^(?:calc|clamp|fit-content|max|min|minmax|repeat|var)\(.+\)$/i', $track)
            ) {
                continue;
            }
            return false;
        }

        return true;
    }

    /**
     * Preserve case-sensitive custom-property names while resolving duplicate
     * inline declarations by CSS importance and source order.
     *
     * @return array<string, string>
     */
    private function mediaTextInlineCascadeDeclarations(string $style): array
    {
        $cascade = array();
        foreach ($this->mediaTextInlineDeclarationEntries($style) as $entry) {
            $current = $cascade[$entry['property']] ?? null;
            if (is_array($current) && $current['important'] && ! $entry['important']) {
                continue;
            }
            $cascade[$entry['property']] = array(
                'value' => $entry['value'],
                'important' => $entry['important'],
            );
        }

        $declarations = array();
        foreach ($cascade as $property => $entry) {
            $declarations[$property] = $entry['value'] . ($entry['important'] ? ' !important' : '');
        }

        return $declarations;
    }

    /**
     * @return array{int, int, int}
     */
    public function mediaTextSelectorSpecificity(string $selector): array
    {
        $parsed = $this->context->parsedCssSelector($selector);
        if (! ($parsed['supported'] ?? false)) {
            return array( 0, 0, 0 );
        }

        $ids = 0;
        $classes = 0;
        $elements = 0;
        foreach ($parsed['compounds'] as $compound) {
            $zeroSpecificity = $compound['zero_specificity'] ?? array();
            $ids += count($compound['ids'] ?? array()) - (int) ($zeroSpecificity['ids'] ?? 0);
            $classes += count($compound['classes'] ?? array()) + count($compound['attributes'] ?? array())
                - (int) ($zeroSpecificity['classes'] ?? 0) - (int) ($zeroSpecificity['attributes'] ?? 0);
            if (null !== ($compound['nth_child'] ?? null) || ($compound['first_child'] ?? false) || ($compound['last_child'] ?? false)) {
                ++$classes;
            }
            if (null !== ($compound['type'] ?? null) && 0 === (int) ($zeroSpecificity['types'] ?? 0)) {
                ++$elements;
            }
        }

        return array( $ids, $classes, $elements );
    }

    /**
     * @param array{int, int, int} $left
     * @param array{int, int, int} $right
     */
    public function compareMediaTextSpecificity(array $left, array $right): int
    {
        foreach ( array( 0, 1, 2 ) as $index ) {
            if ( $left[ $index ] !== $right[ $index ] ) {
                return $left[ $index ] <=> $right[ $index ];
            }
        }

        return 0;
    }

    /**
     * Resolve full authored layout style for media-text strict gates, including
     * low-value direct children that general presentation resolution skips.
     */
    public function mediaTextPresentationStyle(DOMElement $element): string
    {
        $cache = $this->context->presentationResolutionCache();
        $cacheKey = $cache->elementKey($element);
        if ( isset($cache->mediaTextStyles[$cacheKey]) ) {
            return $cache->mediaTextStyles[$cacheKey];
        }

        $cache->mediaTextStyles[$cacheKey] = $this->cssDeclarationString($this->mediaTextPresentationDeclarations($element));

        return $cache->mediaTextStyles[$cacheKey];
    }

    /**
     * Remove JS-gated closed states from content-bearing or interactive
     * elements so they are not frozen permanently invisible (#259, #1353, #1354).
     * Decorative nodes keep their hidden declarations.
     *
     * @param array<string, string> $declarations
     * @return array<string, string>
     */
    private function stripFrozenHiddenState(DOMElement $element, array $declarations): array
    {
        if (
            array() === $declarations
            || $this->isDecorativeHiddenElement($element)
            || $this->isExplicitlyInactiveState($element)
            || $this->context->hasRetainedPresentationRuntime($element)
        ) {
            return $declarations;
        }

        $responsiveDisplay = null;
        if (
            'none' === CssValueInspector::comparable((string) ($declarations['display'] ?? ''))
            && $this->hasConditionalVisibleDisplay($element)
        ) {
            $responsiveDisplay = $declarations['display'];
            unset($declarations['display']);
        }

        $normalized = $this->closedStateNormalizer()->strip($declarations);
        if ( null !== $responsiveDisplay ) {
            $normalized['declarations']['display'] = $responsiveDisplay;
        }
        if ( array() !== $normalized['stripped'] ) {
            $this->context->transformationEvidence()->recordFrozenHiddenState(array(
                'tag'          => strtolower($element->tagName),
                'selector'     => $this->context->elementSelector($element),
                'editor_selector' => $this->editorStaticStateSelector($element),
                'declarations' => $normalized['stripped'],
            ));
        }

        return $normalized['declarations'];
    }

    private function hasConditionalVisibleDisplay(DOMElement $element): bool
    {
        foreach ($this->styleRuleCandidates($element, 'conditional') as $rule) {
            if (! $this->matchesCssSelector($element, $rule['selector'])) {
                continue;
            }
            $display = CssValueInspector::comparable((string) ($rule['declarations']['display'] ?? ''));
            if ( '' !== $display && 'none' !== $display ) {
                return true;
            }
        }

        return false;
    }

    private function isExplicitlyInactiveState(DOMElement $element): bool
    {
        return 'false' === strtolower(trim($this->context->attr($element, 'data-visible')));
    }

    /** @return list<string> */
    public function closedStateRepairCssRules(): array
    {
        return $this->closedStateNormalizer()->repairRules(
            $this->context->transformationEvidence()->frozenHiddenStateFindings()
        );
    }

    public function collectEditorHiddenStateFindings(DOMElement $body): void
    {
        foreach ( $body->getElementsByTagName('*') as $element ) {
            if ( ! $element instanceof DOMElement ) {
                continue;
            }
            $declarations = array();
            foreach ( $this->styleRuleCandidates($element, 'hidden-state') as $rule ) {
                if ( $this->matchesCssSelector($element, $rule['selector']) ) {
                    $declarations = $this->mergeCssDeclarationMaps($declarations, $rule['declarations']);
                }
            }
            $declarations = $this->mergeCssDeclarationMaps($declarations, $this->cssDeclarations($this->context->attr($element, 'style')));
            $this->stripFrozenHiddenState($element, $declarations);
        }
    }

    /** @return array<int, array{selector:string,declarations:array<string,string>}> */
    private function hiddenStateStyleRules(): array
    {
        $rules = array();
        (new CssStylesheetTransformer())->visitStyleRules(
            $this->context->authorStyles()->combinedCss(),
            function (string $prelude, string $body, array $conditions) use (&$rules): void {
                if (array() !== $conditions) {
                    return;
                }
                $declarations = array_intersect_key($this->cssDeclarations($body), $this->closedStateNormalizer()->hiddenStateProperties());
                foreach (explode(',', $prelude) as $selector) {
                    $selector = trim($selector);
                    if (array() !== $declarations && '' !== $selector && ! $this->selectorCarriesPseudoState($selector) && $this->isSupportedCssSelector($selector)) {
                        $rules[] = array('selector' => $selector, 'declarations' => $declarations);
                    }
                }
            }
        );

        return $rules;
    }

    private function editorStaticStateSelector(DOMElement $element): string
    {
        $id = trim($this->context->attr($element, 'id'));
        if ( preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $id) ) {
            return '#' . $id;
        }

        $classes = array_values(array_filter(
            preg_split('/\s+/', trim($this->context->attr($element, 'class'))) ?: array(),
            static fn (string $class): bool => 1 === preg_match('/^[A-Za-z_-][A-Za-z0-9_-]*$/', $class)
        ));

        return array() === $classes ? '' : '.' . implode('.', $classes);
    }

    private function editorAnchorClassName(DOMElement $element): string
    {
        if ( ! in_array(strtolower($element->tagName), array('article', 'aside', 'div', 'footer', 'header', 'main', 'section'), true) ) {
            return '';
        }
        $anchor = $this->context->safeAnchor($this->context->attr($element, 'id'));
        return '' === $anchor ? '' : 'blocks-engine-editor-anchor-' . $anchor;
    }

    /**
     * An element is treated as genuinely (decoratively) hidden when it carries
     * no real content or interactivity, or it is presentational. Collapsible
     * regions that still hold content stay content-bearing even when the source
     * marked them `aria-hidden` in the closed capture.
     */
    private function isDecorativeHiddenElement(DOMElement $element): bool
    {
        return $this->closedStateNormalizer()->isDecorativeHiddenElement(
            $element,
            fn (DOMElement $source, string $name): string => $this->context->attr($source, $name)
        );
    }

    private function closedStateNormalizer(): ClosedStateNormalizer
    {
        return $this->closedStateNormalizer ??= new ClosedStateNormalizer();
    }

    public function mergedPresentationStyle(DOMElement $element): string
    {
        $cache = $this->context->presentationResolutionCache();
        $cacheKey = $cache->elementKey($element);
        if ( isset($cache->mergedStyles[$cacheKey]) ) {
            return $cache->mergedStyles[$cacheKey];
        }

        $inlineStyle = $this->context->attr($element, 'style');
        if ( array() === $this->context->sourceStyles()->staticRules() || (! $this->isHighValueStyledElement($element) && ! $this->hasGenericRecognitionDemand($element)) ) {
            $cache->mergedStyles[$cacheKey] = $inlineStyle;
            return $inlineStyle;
        }

        $declarations = array();
        foreach ( $this->styleRuleCandidates($element, 'static') as $rule ) {
            if ( $this->matchesCssSelector($element, $rule['selector']) ) {
                $declarations = $this->mergeCssDeclarationMaps($declarations, $rule['declarations']);
            }
        }

        if ( array() === $declarations ) {
            $cache->mergedStyles[$cacheKey] = $inlineStyle;
            return $inlineStyle;
        }

        $declarations = $this->mergeCssDeclarationMaps($declarations, $this->cssDeclarations($inlineStyle));
        $cache->mergedStyles[$cacheKey] = $this->cssDeclarationString($declarations);

        return $cache->mergedStyles[$cacheKey];
    }

    /**
     * Resolve the authored resting cascade for navigation recognition.
     *
     * General presentation merging intentionally follows source order only,
     * but navigation link colour becomes a rendered carrier and therefore must
     * use the browser winner. A later low-specificity item class cannot replace
     * an earlier, stronger menu-anchor rule.
     */
    public function specificityResolvedPresentationStyle(DOMElement $element): string
    {
        $cascade = array();
        $sequence = 0;
        foreach ( $this->styleRuleCandidates($element, 'static') as $rule ) {
            if ( ! $this->matchesCssSelector($element, $rule['selector']) ) {
                continue;
            }

            $specificity = $this->mediaTextSelectorSpecificity($rule['selector']);
            foreach ( $rule['declarations'] as $property => $value ) {
                $this->applyMediaTextCascadeDeclaration(
                    $cascade,
                    (string) $property,
                    (string) $value,
                    false,
                    $specificity,
                    ++$sequence
                );
            }
        }

        foreach ( $this->cssDeclarations($this->context->attr($element, 'style')) as $property => $value ) {
            $this->applyMediaTextCascadeDeclaration(
                $cascade,
                (string) $property,
                (string) $value,
                true,
                array( PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX ),
                ++$sequence
            );
        }

        $declarations = array();
        foreach ( $cascade as $property => $entry ) {
            $declarations[$property] = $entry['value'] . ($entry['important'] ? ' !important' : '');
        }

        return $this->cssDeclarationString($declarations);
    }

    /**
     * Return the authored cascade winner for an inherited property. Theme and
     * user-agent defaults are deliberately absent: callers use this only when
     * preserving a value the source CSS actually states.
     */
    public function authoredInheritedPropertyWinner(DOMElement $element, string $property): string
    {
        $property = strtolower(trim($property));
        if ( ! in_array($property, array(
            'color',
            'font-family',
            'font-size',
            'font-style',
            'letter-spacing',
            'line-height',
            'text-transform',
            'white-space',
        ), true) ) {
            return '';
        }

        for ( $current = $element; $current instanceof DOMElement; $current = $current->parentNode instanceof DOMElement ? $current->parentNode : null ) {
            $declarations = $this->cssDeclarations($this->specificityResolvedPresentationStyle($current));
            if ( ! array_key_exists($property, $declarations) ) {
                continue;
            }

            $rawValue = (string) $declarations[$property];
            if ( 1 === preg_match('/\s*!\s*important\s*$/i', $rawValue) ) {
                return '';
            }
            $value = trim($rawValue);
            $keyword = strtolower($value);
            if ( in_array($keyword, array( 'inherit', 'unset' ), true) ) {
                continue;
            }
            if ( in_array($keyword, array( 'initial', 'revert', 'revert-layer' ), true) ) {
                return '';
            }

            return $this->resolveCssVariablesInValue($value);
        }

        return '';
    }

    /**
     * Resolve gap shorthand and longhands as one cascade family.
     *
     * @return array{row-gap?: string, column-gap?: string}
     */
    public function specificityResolvedGapDeclarations(DOMElement $element): array
    {
        $cascade = array();
        $sequence = 0;
        foreach ( $this->styleRuleCandidates($element, 'static') as $rule ) {
            if ( ! $this->matchesCssSelector($element, $rule['selector']) ) {
                continue;
            }

            $specificity = $this->mediaTextSelectorSpecificity($rule['selector']);
            $entries = $rule['mediaTextDeclarations'] ?? array();
            foreach ( $rule['declarations'] ?? array() as $property => $value ) {
                if ( ! in_array(strtolower((string) $property), array( 'gap', 'row-gap', 'column-gap' ), true) ) {
                    continue;
                }
                $entries[] = array(
                    'property' => (string) $property,
                    'value' => (string) $value,
                    'important' => str_contains(strtolower((string) $value), '!important'),
                );
            }
            foreach ( $entries as $entry ) {
                $this->applyGapCascadeDeclaration(
                    $cascade,
                    (string) ($entry['property'] ?? ''),
                    (string) ($entry['value'] ?? '') . (! empty($entry['important']) ? ' !important' : ''),
                    false,
                    $specificity,
                    ++$sequence
                );
            }
        }

        foreach ( $this->mediaTextInlineDeclarationEntries($this->context->attr($element, 'style')) as $entry ) {
            $this->applyGapCascadeDeclaration(
                $cascade,
                (string) ($entry['property'] ?? ''),
                (string) ($entry['value'] ?? '') . (! empty($entry['important']) ? ' !important' : ''),
                true,
                array( PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX ),
                ++$sequence
            );
        }

        $resolved = array();
        foreach ( array( 'row-gap', 'column-gap' ) as $property ) {
            if ( isset($cascade[$property]) ) {
                $resolved[$property] = $cascade[$property]['value'] . ($cascade[$property]['important'] ? ' !important' : '');
            }
        }
        return $resolved;
    }

    /**
     * @param array<string, array{value: string, important: bool, inline: bool, specificity: array{int, int, int}, sequence: int}> $cascade
     * @param array{int, int, int} $specificity
     */
    private function applyGapCascadeDeclaration(array &$cascade, string $property, string $value, bool $inline, array $specificity, int $sequence): void
    {
        $property = strtolower(trim($property));
        if ( 'gap' === $property ) {
            $important = 1 === preg_match('/\s*!\s*important\s*$/i', $value);
            $plain = trim(preg_replace('/\s*!\s*important\s*$/i', '', $value) ?? $value);
            $parts = CssValueSplitter::splitTopLevelWhitespace($plain);
            if ( 1 > count($parts) || 2 < count($parts) ) {
                return;
            }
            $suffix = $important ? ' !important' : '';
            $this->applyMediaTextCascadeDeclaration($cascade, 'row-gap', $parts[0] . $suffix, $inline, $specificity, $sequence);
            $this->applyMediaTextCascadeDeclaration($cascade, 'column-gap', ($parts[1] ?? $parts[0]) . $suffix, $inline, $specificity, $sequence);
            return;
        }

        if ( in_array($property, array( 'row-gap', 'column-gap' ), true) ) {
            $this->applyMediaTextCascadeDeclaration($cascade, $property, $value, $inline, $specificity, $sequence);
        }
    }

    /**
     * Preserve declaration order while applying shorthand reset semantics.
     *
     * @param array<string, string> $base
     * @param array<string, string> $incoming
     * @return array<string, string>
     */
    public function mergeCssDeclarationMaps(array $base, array $incoming): array
    {
        foreach ( $incoming as $property => $value ) {
            if ( 'all' === $property && $this->isCssAllResetValue($value) ) {
                // `all:unset|initial|revert` resets every longhand except
                // custom properties, direction, and unicode-bidi; earlier
                // declarations cannot survive the reset regardless of origin.
                // The keyword itself never rides forward: honoring it means
                // dropping what it reset, not serializing `all`.
                foreach ( array_keys($base) as $existing ) {
                    if ( ! str_starts_with($existing, '--') && ! in_array($existing, array( 'direction', 'unicode-bidi' ), true) ) {
                        unset($base[$existing]);
                    }
                }
                continue;
            }
            if ( 'background' === $property ) {
                foreach ( array_keys($base) as $existing ) {
                    if ( 'background' === $existing || str_starts_with($existing, 'background-') ) {
                        unset($base[$existing]);
                    }
                }
            }
            unset($base[$property]);
            $base[$property] = $value;
        }

        return $base;
    }

    private function isHighValueStyledElement(DOMElement $element): bool
    {
        return $this->highValueStyleBoundaryPolicy()->matches($element);
    }

    /** Image crop recognition is structural and selector-driven, not name-driven. */
    private function hasGenericRecognitionDemand(DOMElement $element): bool
    {
        if ('img' !== strtolower($element->tagName)) {
            return false;
        }

        foreach ($this->styleRuleCandidates($element, 'static-conditional') as $rule) {
            if (! $this->matchesCssSelector($element, $rule['selector'])) {
                continue;
            }
            if (array_intersect(array('aspect-ratio', 'object-fit', 'object-position'), array_keys($rule['declarations']))) {
                return true;
            }
        }

        return false;
    }

    /** Build every immutable source-style rule stream in one stylesheet traversal. */
    public function stylesheetAnalysis(string $css): array
    {
        $analysis = array(
            'static' => array(),
            'conditional' => array(),
            'navigation_state' => array(),
            'image_shape' => array(),
            'pseudo' => array(),
        );
        $imageOrder = 0;
        (new CssStylesheetTransformer())->visitStyleRules(
            $css,
            function (string $prelude, string $body, array $conditions) use (&$analysis, &$imageOrder): void {
                $declarations = $this->safeVisualDeclarations($this->cssDeclarations($body));
                $mediaTextDeclarations = array() === $conditions
                    ? array_values(array_filter(
                        $this->mediaTextInlineDeclarationEntries($body),
                        static fn (array $entry): bool => in_array($entry['property'], array(
                            'align-items',
                            'direction',
                            'display',
                            'flex-basis',
                            'flex-direction',
                            'flex-flow',
                            'float',
                            'grid-template-columns',
                            'order',
                            'width',
                        ), true)
                    ))
                    : array();
                $imageEntries = $this->imageShapeDeclarationEntries($body);

                foreach (explode(',', $prelude) as $selector) {
                    $selector = trim($selector);
                    if ('' === $selector || str_starts_with($selector, '@')) {
                        continue;
                    }
                    $supportedRestingSelector = ! $this->selectorCarriesPseudoState($selector) && $this->isSupportedCssSelector($selector);
                    if ($supportedRestingSelector && array() === $conditions && (array() !== $declarations || array() !== $mediaTextDeclarations)) {
                        $analysis['static'][] = array(
                            'selector' => $selector,
                            'declarations' => $declarations,
                            'mediaTextDeclarations' => $mediaTextDeclarations,
                            'mediaTextSpecificity' => $this->mediaTextSelectorSpecificity($selector),
                        );
                    }
                    if ($supportedRestingSelector && array() !== $conditions && array() !== $declarations) {
                        $analysis['conditional'][] = array('selector' => $selector, 'declarations' => $declarations, 'conditions' => $conditions);
                    }
                    if ($supportedRestingSelector) {
                        foreach ($imageEntries as $entry) {
                            $analysis['image_shape'][] = array(
                                'selector' => $selector,
                                'property' => $entry['property'],
                                'value' => $entry['value'],
                                'conditions' => $conditions,
                                'order' => $imageOrder++,
                            );
                        }
                    }
                    if (array() !== $conditions || array() === $declarations) {
                        continue;
                    }
                    if (1 === preg_match_all('/:(hover|focus-visible|focus|active)\b/i', $selector, $stateMatches, PREG_OFFSET_CAPTURE)) {
                        $state = strtolower((string) $stateMatches[1][0][0]);
                        $offset = (int) $stateMatches[0][0][1];
                        $baseSelector = trim(substr_replace($selector, '', $offset, strlen((string) $stateMatches[0][0][0])));
                        if ('' !== $baseSelector && ! $this->selectorCarriesPseudoState($baseSelector) && $this->isSupportedCssSelector($baseSelector)) {
                            $analysis['navigation_state'][] = array('selector' => $selector, 'base_selector' => $baseSelector, 'state' => $state, 'declarations' => $declarations);
                        }
                    }
                    if (preg_match('/::?(before|after)\b/i', $selector, $pseudoMatch)) {
                        $baseSelector = trim((string) preg_replace('/::?(?:before|after)\b/i', '', $selector));
                        if ('' !== $baseSelector && ! $this->selectorCarriesPseudoState($baseSelector) && $this->isSupportedCssSelector($baseSelector)) {
                            $analysis['pseudo'][] = array('selector' => $baseSelector, 'pseudo' => strtolower($pseudoMatch[1]), 'declarations' => $declarations);
                        }
                    }
                }
            }
        );

        return $analysis;
    }

    /** @return list<array{property: string, value: string}> */
    public function imageShapeDeclarationEntries(string $style): array
    {
        $entries = array();
        foreach (CssValueSplitter::splitTopLevel($style, array(';')) as $declaration) {
            if (! str_contains($declaration, ':')) {
                continue;
            }
            [$property, $value] = array_map('trim', explode(':', $declaration, 2));
            $property = strtolower($property);
            $value = preg_replace('/\s+/', ' ', $value) ?? $value;
            if (in_array($property, array('aspect-ratio', 'object-fit'), true) && '' !== $value) {
                $entries[] = array('property' => $property, 'value' => $value);
            }
        }

        return $entries;
    }

    /**
     * @param array<string, string> $declarations
     * @return array<string, string>
     */
    public function safeVisualDeclarations(array $declarations): array
    {
        $safe = array_flip(array(
            '-webkit-background-clip',
            '-webkit-text-fill-color',
            'background',
            'background-attachment',
            'background-clip',
            'background-color',
            'background-image',
            'background-origin',
            'background-position',
            'background-repeat',
            'background-size',
            'aspect-ratio',
            'border',
            'border-bottom',
            'border-bottom-color',
            'border-bottom-left-radius',
            'border-bottom-right-radius',
            'border-bottom-style',
            'border-color',
            'border-left',
            'border-left-color',
            'border-left-style',
            'border-radius',
            'border-right',
            'border-right-color',
            'border-right-style',
            'border-style',
            'border-bottom-width',
            'border-collapse',
            'border-left-width',
            'border-right-width',
            'border-spacing',
            'border-top',
            'border-top-color',
            'border-top-left-radius',
            'border-top-right-radius',
            'border-top-style',
            'border-top-width',
            'border-width',
            'box-shadow',
            'color',
            'align-items',
            'column-gap',
            'direction',
            'display',
            'flex-direction',
            'flex-flow',
            'flex',
            'flex-basis',
            'flex-grow',
            'flex-wrap',
            'font-family',
            'font-size',
            'font-style',
            'font-weight',
            'letter-spacing',
            'gap',
            'grid-template-columns',
            'grid-template-rows',
            'height',
            'inset',
            'justify-content',
            'line-height',
            'margin',
            'margin-bottom',
            'margin-left',
            'margin-right',
            'margin-top',
            'max-height',
            'max-width',
            'min-height',
            'min-width',
            'object-fit',
            'order',
            'padding',
            'padding-bottom',
            'padding-left',
            'padding-right',
            'padding-top',
            'place-items',
            'pointer-events',
            'position',
            'row-gap',
            'text-align',
            'text-decoration',
            'text-decoration-line',
            'text-transform',
            'table-layout',
            'width',
            'z-index',
        ));

        return array_intersect_key($declarations, $safe);
    }

    /**
     * @return array<string, string>
     */
    public function cssDeclarations(string $style): array
    {
        $declarations = array();
        foreach ( CssValueSplitter::splitTopLevel($style, array( ';' )) as $declaration ) {
            if ( ! str_contains($declaration, ':') ) {
                continue;
            }
            [$name, $value] = array_map('trim', explode(':', $declaration, 2));
            $name = strtolower($name);
            $value = preg_replace('/\s+/', ' ', $value) ?? $value;
            $allowsImageUrl = in_array($name, array( 'background', 'background-image', 'list-style', 'list-style-image' ), true) && ! preg_match('/(?:expression\s*\(|javascript\s*:)/i', $value);
            if ( '' !== $name && '' !== $value && ( $allowsImageUrl || ! preg_match('/(?:expression\s*\(|javascript\s*:|url\s*\()/i', $value) ) ) {
                // Keep the surviving declaration at its final authored position.
                // Border shorthands and longhands reset one another in source
                // order, so overwriting a prior key in place is not sufficient.
                unset($declarations[$name]);
                $declarations[$name] = $value;
            }
        }

        return $declarations;
    }

    /**
     * @param array<string, string> $declarations
     */
    public function cssDeclarationString(array $declarations): string
    {
        $parts = array();
        foreach ( $declarations as $name => $value ) {
            $parts[] = $name . ':' . $value;
        }

        return implode(';', $parts);
    }

    private function isSupportedCssSelector(string $selector): bool
    {
        return (bool) ($this->context->parsedCssSelector($selector)['supported'] ?? false);
    }

    public function matchesCssSelector(DOMElement $element, string $selector): bool
    {
        $cache = $this->context->sourceStyles();
        $match = $cache->selectorMatchCache->matches($element, $selector, $this->context->parsedCssSelector($selector));
        return $match['supported'] && $match['matches'];
    }

    public function recordSourceSelectorMatchWork(): void
    {
        $selectorCache = $this->context->sourceStyles()->selectorMatchCache;
        $this->analysisCache->sourceSelectorMatchExecutions += $selectorCache->matchExecutions;
        $this->analysisCache->sourceSelectorMatchHits += $selectorCache->matchHits;
        $this->analysisCache->sourceSelectorMatchMisses += $selectorCache->matchMisses;
        $this->analysisCache->sourceSelectorMatchEvictions += $selectorCache->matchEvictions;
        $this->analysisCache->sourceSelectorMatchPeakEntries = max($this->analysisCache->sourceSelectorMatchPeakEntries, $selectorCache->matchPeakEntries);
        $this->analysisCache->sourceSelectorClassTokenBuilds += $selectorCache->classTokenBuilds;
        $this->analysisCache->sourceSelectorClassTokenHits += $selectorCache->classTokenHits;
        $this->analysisCache->sourceSelectorAttributeReads += $selectorCache->attributeReads;
        $this->analysisCache->sourceStyleCandidateRuleChecks += $selectorCache->candidateRuleChecks;
        $this->analysisCache->sourceStyleCandidateRulesSkipped += $selectorCache->candidateRulesSkipped;
        $this->analysisCache->sourceStyleCandidateRuleHits += $selectorCache->candidateRuleHits;
        $this->analysisCache->sourceStyleCandidateRuleMisses += $selectorCache->candidateRuleMisses;
        $this->analysisCache->sourceStyleCandidateRuleEvictions += $selectorCache->candidateRuleEvictions;
        $this->analysisCache->sourceStyleCandidateRulePeakEntries = max($this->analysisCache->sourceStyleCandidateRulePeakEntries, $selectorCache->candidateRulePeakEntries);
        $this->analysisCache->sourceStyleCandidateRulePeakRetained = max($this->analysisCache->sourceStyleCandidateRulePeakRetained, $selectorCache->candidateRulePeakRetained);
    }

    /** @return list<array<string, mixed>> */
    public function styleRuleCandidates(DOMElement $element, string $collection): array
    {
        $cache = $this->context->sourceStyles();
        $index = $cache->ruleCandidateIndexes[$collection] ??= $this->styleRuleCandidateIndex($collection);
        return $cache->selectorMatchCache->styleRuleCandidates($element, $collection, $index);
    }

    /** @return array{universal: list<array{order: int, rule: array<string, mixed>}>, ids: array<string, list<array{order: int, rule: array<string, mixed>}>>, classes: array<string, list<array{order: int, rule: array<string, mixed>}>>, tags: array<string, list<array{order: int, rule: array<string, mixed>}>>, attributes: array<string, list<array{order: int, rule: array<string, mixed>}>>, total: int} */
    private function styleRuleCandidateIndex(string $collection): array
    {
        $rules = match ($collection) {
            'static' => $this->context->sourceStyles()->staticRules(),
            'conditional' => $this->context->sourceStyles()->conditionalRules(),
            'hidden-state' => $this->hiddenStateStyleRules(),
            'static-conditional' => array_merge($this->context->sourceStyles()->staticRules(), $this->context->sourceStyles()->conditionalRules()),
            'static-conditional-pseudo' => array_merge($this->context->sourceStyles()->staticRules(), $this->context->sourceStyles()->conditionalRules(), $this->context->sourceStyles()->pseudoElementRules()),
        };
        $index = array('universal' => array(), 'ids' => array(), 'classes' => array(), 'tags' => array(), 'attributes' => array(), 'total' => count($rules));
        foreach ( $rules as $order => $rule ) {
            $parsed = $this->context->parsedCssSelector((string) ($rule['selector'] ?? ''));
            $compounds = $parsed['compounds'] ?? array();
            $rightmost = array() === $compounds ? null : $compounds[array_key_last($compounds)];
            $target = 'universal';
            $key = '';
            if ( $parsed['supported'] && null === ($parsed['pseudo_state_suffix_span'] ?? null) && is_array($rightmost) ) {
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
            $entry = array('order' => (int) $order, 'rule' => $rule);
            if ( 'universal' === $target ) {
                $index['universal'][] = $entry;
            } else {
                $index[$target][$key][] = $entry;
            }
        }
        return $index;
    }

    /**
     * Whether a selector targets a pseudo-state or pseudo-element rather than the
     * element's resting state. Such rules (`:hover`, `:focus`, `:active`,
     * `:visited`, `:focus-visible`, `:focus-within`, `::before`/`::after`, and the
     * single-colon legacy `:before`/`:after`) describe transient or generated
     * presentation. They must never be folded into an element's RESTING inline
     * style — they belong in the verbatim materialized stylesheet, where they fire
     * on real interaction. Selectors carrying one of these are excluded from the
     * inline-style resolution rule set entirely (not stripped-and-kept), so a
     * `.btn-primary:hover{background:#f0ac22}` rule no longer overrides the correct
     * resting `.btn-primary` declarations on the element.
     */
    private function selectorCarriesPseudoState(string $selector): bool
    {
        return 1 === preg_match('/:{1,2}(?:hover|focus-visible|focus-within|focus|active|visited|before|after)\b/i', $selector);
    }

    public function presentationClassName(string $className): string
    {
        $classes = preg_split('/\s+/', trim($className)) ?: array();
        $classes = array_filter($classes, static fn (string $class): bool => '' !== $class && ! self::isBehaviorHookClassName($class) && ! self::isGeneratedCoreClassName($class) && ! self::isTransformerMarkerClassName($class));

        return implode(' ', array_values(array_unique($classes)));
    }

    /**
     * Transformer-generated marker and carrier classes found in SOURCE markup
     * (re-ingested transformer output) must be re-derived, not preserved as
     * author classes: a preserved css-owned-grid marker would trip the
     * grid-class heuristics and the carried margin reset. Emitted classNames
     * are unaffected — this filters ingestion only.
     */
    private static function isTransformerMarkerClassName(string $className): bool
    {
        return str_starts_with($className, 'blocks-engine-')
            || str_starts_with($className, 'be-inline-geometry-');
    }

    private static function isBehaviorHookClassName(string $className): bool
    {
        return 1 === preg_match('/^js(?:$|[-_:]|[A-Z])/', $className);
    }

    private static function isGeneratedCoreClassName(string $className): bool
    {
        return GeneratedGutenbergClassPolicy::isGeneratedClassName($className);
    }

    /**
     * @return array<string, string>
     */
    private function layoutAttribute(DOMElement $element, string $mergedStyle = ''): array
    {
        $declared = trim($this->context->attr($element, 'data-layout'));
        if ( '' === $declared ) {
            $declared = trim($this->context->attr($element, 'data-wp-layout'));
        }

        if ( '' !== $declared ) {
            $decoded = json_decode($declared, true);
            $type = is_array($decoded) ? (string) ($decoded['type'] ?? '') : $declared;
            if ( in_array($type, array( 'constrained', 'flex', 'flow', 'grid' ), true) ) {
                return array( 'type' => $type );
            }
        }

        $inlineStyle = strtolower($this->context->attr($element, 'style'));
        $mergedDeclarations = $this->cssDeclarations($mergedStyle);
        $inlineDeclarations = $this->cssDeclarations($inlineStyle);
        if ( preg_match('/(?:^|;)\s*display\s*:\s*(inline-)?flex\b/', $inlineStyle) ) {
            $layout = array( 'type' => 'flex' );
            // flex-direction: column / column-reverse is a vertical main axis. A
            // core/group flex layout defaults to a horizontal Row, so the
            // orientation must be made explicit or the children render
            // side-by-side instead of stacked. Row / row-reverse / default flex
            // keeps the implicit horizontal orientation.
            if ( preg_match('/(?:^|;)\s*flex-direction\s*:\s*column(?:-reverse)?\b/', $inlineStyle) ) {
                $layout['orientation'] = 'vertical';
            }
            $justifyContent = $this->layoutJustifyContent((string) ($inlineDeclarations['justify-content'] ?? $mergedDeclarations['justify-content'] ?? ''));
            if ( '' !== $justifyContent ) {
                $layout['justifyContent'] = $justifyContent;
            }
            $flexWrap = $this->layoutFlexWrap((string) ($inlineDeclarations['flex-wrap'] ?? $mergedDeclarations['flex-wrap'] ?? ''));
            if ( '' !== $flexWrap ) {
                $layout['flexWrap'] = $flexWrap;
            }

            return $layout;
        }
        $style = strtolower('' !== trim($mergedStyle) ? $mergedStyle : $this->context->attr($element, 'style'));
        if ( preg_match('/(?:^|;)\s*display\s*:\s*(inline-)?flex\b/', $style)
            && ! preg_match('/(?:^|;)\s*flex-direction\s*:\s*column(?:-reverse)?\b/', $style)
        ) {
            if ( ! preg_match('/(?:^|;)\s*display\s*:\s*(inline-)?flex\b/', $inlineStyle) && $this->hasOwnStyleHook($element) ) {
                return array();
            }

            return array( 'type' => 'flex' );
        }
        if ( preg_match('/(?:^|;)\s*display\s*:\s*(inline-)?grid\b/', $style) ) {
            $minimumColumnWidth = $this->autoRepeatMinimumColumnWidth(
                (string) ($mergedDeclarations['grid-template-columns'] ?? $inlineDeclarations['grid-template-columns'] ?? '')
            );
            if ( '' !== $minimumColumnWidth ) {
                return array( 'type' => 'grid', 'minimumColumnWidth' => $minimumColumnWidth );
            }
            if ( ! preg_match('/(?:^|;)\s*display\s*:\s*(inline-)?grid\b/', $inlineStyle) && $this->hasOwnStyleHook($element) ) {
                return array();
            }

            return array( 'type' => 'grid' );
        }

        $inlineOwnsLayout = false;
        foreach (array_keys($inlineDeclarations) as $property) {
            if ('layout' === $this->responsivePropertyFamily($property)) {
                $inlineOwnsLayout = true;
                break;
            }
        }
        if (! $inlineOwnsLayout && $this->hasConditionalStyleFamily($element, 'layout')) {
            return array();
        }

        // An explicit grid class token (`grid`, `grid-3`, `footer-grid`,
        // `card-grid`, …) is a deterministic CSS-grid signal on its own. When the
        // container holds more than one element child, emit grid layout so the
        // multi-column arrangement survives even when the children are plain
        // wrappers rather than recognized card markup. Without this the grid
        // collapses to a vertical stack and loses visual parity.
        if ( $this->hasExplicitGridClass($element) && 1 < $this->context->directElementChildCount($element) ) {
            return array( 'type' => 'grid' );
        }

        if ( $this->hasGridLikeClass($element) && 1 < $this->context->cardLikeChildCount($element) ) {
            return array( 'type' => 'grid' );
        }

        return array();
    }

    private function hasOwnStyleHook(DOMElement $element): bool
    {
        return '' !== trim($this->context->attr($element, 'class')) || '' !== trim($this->context->attr($element, 'id'));
    }

    private function layoutJustifyContent(string $value): string
    {
        $value = strtolower(trim($value));
        $map = array(
            'flex-start'    => 'left',
            'start'         => 'left',
            'left'          => 'left',
            'center'        => 'center',
            'flex-end'      => 'right',
            'end'           => 'right',
            'right'         => 'right',
            'space-between' => 'space-between',
        );

        return $map[ $value ] ?? '';
    }

    private function layoutFlexWrap(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, array( 'wrap', 'nowrap' ), true) ? $value : '';
    }

    /**
     * A track list of exactly repeat(auto-fill, minmax(<width>, 1fr)) is
     * natively expressible as WordPress grid layout: core renders
     * minimumColumnWidth as repeat(auto-fill, minmax(min(<width>, 100%), 1fr)).
     *
     * auto-fit is deliberately excluded. wp-includes/block-supports/layout.php
     * hardcodes auto-fill in every branch that renders minimumColumnWidth, so
     * the attribute cannot express auto-fit at all. The two keywords differ in
     * rendered geometry — auto-fit collapses tracks left empty, auto-fill
     * retains them — so converting auto-fit would keep the empty tracks and
     * squeeze the real content into part of the measure. Like every other track
     * list WordPress cannot express (fixed counts, asymmetric tracks, nested
     * functions), auto-fit returns '' and stays under author CSS ownership.
     */
    private function autoRepeatMinimumColumnWidth(string $tracks): string
    {
        if ( 1 === preg_match('/^repeat\(\s*auto-fill\s*,\s*minmax\(\s*([0-9]*\.?[0-9]+(?:px|rem|em|ch|ex|vw|vh|vmin|vmax|%))\s*,\s*1fr\s*\)\s*\)$/i', trim($tracks), $matches)
            && 0.0 < (float) $matches[1]
        ) {
            return strtolower($matches[1]);
        }

        return '';
    }

    /**
     * Unambiguous grid class tokens: a bare `grid`, a numbered `grid-N`, or any
     * `*-grid` / `*_grid` suffix (footer-grid, card-grid, mission-grid, …) plus
     * the common `grid-cols` / `grid-columns` utility names. These map directly to
     * `display:grid` containers, so they are safe to treat as grids regardless of
     * child semantics. Ambiguous semantic names (cards, features, …) stay gated on
     * card-like children via hasGridLikeClass().
     */
    private function hasExplicitGridClass(DOMElement $element): bool
    {
        $className = $this->authorClassTokens($element);
        return (bool) preg_match('/(?:^|[\s_-])(?:grid|grid-[0-9]+|grid-cols(?:-[0-9]+)?|grid-columns|[a-z0-9]+[-_]grid)(?:$|[\s_-])/', $className);
    }

    private function hasGridLikeClass(DOMElement $element): bool
    {
        $className = $this->authorClassTokens($element);
        return (bool) preg_match('/(?:^|[\s_-])(?:cards|features|services|providers|testimonials|resources|posts|projects|stats|badges|grid|grid-[0-9]+|tiles|collection|gallery)(?:$|[\s_-])/', $className);
    }

    /**
     * Class tokens with generated markers filtered out, so transformer-emitted
     * classes (blocks-engine-css-owned-grid, …) re-ingested from prior output
     * never trip the author grid-class heuristics.
     */
    private function authorClassTokens(DOMElement $element): string
    {
        $tokens = preg_split('/\s+/', strtolower(trim($this->context->attr($element, 'class')))) ?: array();

        return implode(' ', array_filter($tokens, static fn (string $token): bool => '' !== $token && ! GeneratedGutenbergClassPolicy::isGeneratedClassName($token) && ! self::isTransformerMarkerClassName($token)));
    }

    /**
     * Expand `var(--token)` references against source custom properties, with
     * ancestor-declared properties layered over them when an element is given.
     *
     * Lives here rather than beside SVG materialization because it is CSS
     * custom-property resolution and already depends on this resolver's own
     * structural declarations.
     */
    public function resolveCssVariablesInValue(string $value, ?DOMElement $element = null): string
    {
        if ( false === strpos($value, 'var(') ) {
            return $value;
        }

        $customProperties = $this->context->sourceStyles()->customProperties();
        if ( $element instanceof DOMElement ) {
            $ancestors = array();
            for ( $current = $element; $current instanceof DOMElement; $current = $current->parentNode instanceof DOMElement ? $current->parentNode : null ) {
                $ancestors[] = $current;
            }
            foreach ( array_reverse($ancestors) as $ancestor ) {
                foreach ( $this->structuralPresentationDeclarations($ancestor) as $name => $propertyValue ) {
                    if ( str_starts_with($name, '--') ) {
                        $customProperties[$name] = $propertyValue;
                    }
                }
            }
        }

        for ( $pass = 0; $pass < 5; ++$pass ) {
            $expanded = preg_replace_callback('/var\(\s*(--[A-Za-z0-9_-]+)\s*(?:,\s*([^()]*))?\)/', static function (array $matches) use ($customProperties): string {
                $name = (string) $matches[1];
                if ( isset($customProperties[$name]) && '' !== $customProperties[$name] ) {
                    return $customProperties[$name];
                }

                return isset($matches[2]) && '' !== trim((string) $matches[2]) ? trim((string) $matches[2]) : (string) $matches[0];
            }, $value);

            if ( ! is_string($expanded) || $expanded === $value ) {
                break;
            }
            $value = $expanded;
        }

        return trim($value);
    }
}

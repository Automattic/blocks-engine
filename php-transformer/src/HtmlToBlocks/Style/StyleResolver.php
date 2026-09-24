<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssIdent;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\CssUrlRewriter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformerAnalysisCache;
use Automattic\BlocksEngine\PhpTransformer\Support\ShellLandmarkPolicy;
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
final class StyleResolver implements ElementPresentationResolver
{
    public function __construct(
        private readonly StyleResolutionContext $context,
        private readonly HtmlTransformerAnalysisCache $analysisCache
    ) {
    }

    private ?StyleAttributeMapper $styleAttributeMapper = null;

    private ?HighValueStyleBoundaryPolicy $highValueStyleBoundaryPolicy = null;

    private ?ClosedStateNormalizer $closedStateNormalizer = null;

    private ?InlineGeometry $inlineGeometry = null;

    /**
     * Whether an element's block keeps a native core grid layout attribute,
     * keyed by the presentation cache's element key. Parents are queried once
     * per placed child, so the layout resolution is memoized per transform.
     *
     * @var array<string, bool>
     */
    private array $coreGridParentCache = array();

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
    public function inlineGeometryProperties(): array
    {
        return $this->inlineGeometry()->geometryProperties();
    }

    public function hasChildOwnedPositionedOffsets(DOMElement $element): bool
    {
        return $this->inlineGeometry()->hasChildOwnedPositionedOffsets($element);
    }

    public function styleAttributeMapper(): StyleAttributeMapper
    {
        return $this->styleAttributeMapper ??= new StyleAttributeMapper();
    }

    private function highValueStyleBoundaryPolicy(): HighValueStyleBoundaryPolicy
    {
        return $this->highValueStyleBoundaryPolicy ??= new HighValueStyleBoundaryPolicy();
    }

    private function inlineGeometry(): InlineGeometry
    {
        return $this->inlineGeometry ??= new InlineGeometry(
            $this->context,
            $this->cssDeclarations(...),
            $this->stripFrozenHiddenState(...),
            $this->mediaTextInlineDeclarationEntries(...),
            $this->inlineDisplayOverridesAuthorLayout(...),
            $this->authorResolvedDisplayEstablishesFlexOrGrid(...),
            $this->inlineAuthorOverrideDeclarations(...),
            $this->inlineInheritedTextAlignDeclaration(...),
            $this->inlineCustomPropertyDeclarations(...),
            $this->geometryStructuralPath(...),
            $this->structuralPresentationDeclarations(...),
            $this->hasConditionalStyleFamily(...),
            $this->responsivePropertyFamily(...),
            $this->hasConditionalGridTemplateColumns(...)
        );
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

        $nativeGridPlacement = $this->nativeGridChildPlacement($element);
        if ( array() !== $nativeGridPlacement['excluded'] ) {
            $excludedGeometryProperties = array_values(array_unique(array_merge(
                $excludedGeometryProperties,
                $nativeGridPlacement['excluded']
            )));
        }

        $attrs = array_filter(array_merge($mapped['attrs'] ?? array(), array(
            'anchor'    => SourceDom::anchorAttributeValue(SourceDom::attr($element, 'id')),
            'className' => $this->mergePresentationClassNames(
                $this->inlineStyleDeclaresAllReset($element) ? '' : $this->context->promotedClassName(SourceDom::attr($element, 'class')),
                $this->editorAnchorClassName($element),
                $this->viewportRootHeightClassName($element),
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
            'layout'    => $this->inlineGeometry()->layoutAttribute($element, $this->cssDeclarationString($declarations)),
        )), static fn ($value): bool => is_array($value) ? array() !== $value : '' !== trim((string) $value));

        if ( array() !== $nativeGridPlacement['placement'] ) {
            $style = is_array($attrs['style'] ?? null) ? $attrs['style'] : array();
            // The keys are core's child layout values
            // (wp_get_layout_child_values()), rendered by
            // wp_get_child_layout_style_rules() for children of a
            // layout.type=grid container; they do not serialize into the
            // wrapper's inline style. Merged rather than assigned: other
            // resolution on this element may already have populated
            // style.layout (e.g. selfStretch/flexSize for a flex-item child),
            // and grid placement must not clobber it.
            $existingChildLayout = is_array($style['layout'] ?? null) ? $style['layout'] : array();
            $style['layout'] = array_merge($existingChildLayout, $nativeGridPlacement['placement']);
            $attrs['style'] = $style;
        }

        $cache->attributes[$cacheKey] = $attrs;

        return $attrs;
    }

    /**
     * Native core 7.1 grid child placement for this element, when the parent
     * element is emitted as a core grid layout container (issue #2139 step
     * 1).
     *
     * Placement resolves from the resting cascaded-value stream (matched
     * non-conditional author rules plus the inline style), so class-authored
     * and inline placement convert alike. Author rules are never rewritten:
     * converted properties leave only the per-element inline geometry
     * carrier, and a class-owned rule is restated natively from the same
     * resolved value. Placement with a media-query variant stays entirely
     * author/carrier owned.
     *
     * The parent check runs first because it is memoized and most elements
     * are not grid items. Under a parent that is not a core grid, only inline
     * placement is diagnosed; the parent keeps the subtree CSS-owned, so
     * class-authored placement there loses nothing.
     *
     * @return array{placement: array<string, int>, excluded: list<string>}
     */
    private function nativeGridChildPlacement(DOMElement $element): array
    {
        $parent = $element->parentNode instanceof DOMElement ? $element->parentNode : null;
        if ( null === $parent || ! $this->parentEmitsCoreGridLayout($parent) ) {
            $inline = $this->cssDeclarations(SourceDom::attr($element, 'style'));
            if ( $this->inlineGeometry()->declaresGridPlacement($inline) ) {
                $this->recordGridPlacementCarrierFinding($element, 'grid_placement_parent_not_core_grid');
            }

            return array( 'placement' => array(), 'excluded' => array() );
        }

        $geometry = $this->inlineGeometry();
        // Grid placement is outside the classification allow-list that feeds
        // structuralPresentationDeclarations(), so it resolves from the
        // generic cascaded-value stream: matched resting author rules plus
        // the inline style, without media-conditional rules.
        $placementDeclarations = array_intersect_key(
            $this->matchedCascadedDeclarations($element),
            array_fill_keys($geometry->gridItemPlacementProperties(), true)
        );
        if ( ! $geometry->declaresGridPlacement($placementDeclarations) ) {
            return array( 'placement' => array(), 'excluded' => array() );
        }
        $resolved = $this->structuralPresentationDeclarations($element);

        // Absolutely positioned grid children place their containing block
        // through grid-area; that projection (issue #2139 step 2) is not the
        // native child layout core renders here.
        $position = CssValueInspector::comparable((string) ( $resolved['position'] ?? '' ));
        if ( in_array($position, array( 'absolute', 'fixed' ), true) ) {
            return array( 'placement' => array(), 'excluded' => array() );
        }

        $resolution = $geometry->resolveGridChildPlacement($placementDeclarations);
        if ( null !== $resolution['reason'] ) {
            $this->recordGridPlacementCarrierFinding($element, $resolution['reason']);
        }
        if ( array() === $resolution['placement'] ) {
            return array( 'placement' => array(), 'excluded' => array() );
        }

        if ( $this->hasConditionalGridPlacement($element) ) {
            // The placement varies under a media query and the transformer
            // has no destination viewport breakpoint mapping. Core's
            // unconditional child-layout rule and the author's media-query
            // rule tie on specificity, so emitting the base placement natively
            // could override the responsive variant depending on stylesheet
            // order. The whole placement stays author/carrier owned.
            $this->recordGridPlacementCarrierFinding($element, 'grid_placement_responsive_unmapped');

            return array( 'placement' => array(), 'excluded' => array() );
        }

        return array( 'placement' => $resolution['placement'], 'excluded' => $resolution['converted'] );
    }

    /**
     * Whether the block hosting this element's parent is emitted with a
     * native `layout.type: grid` attribute, mirroring the layout attribute
     * and CSS-ownership demotion the emitters apply.
     */
    private function parentEmitsCoreGridLayout(DOMElement $parent): bool
    {
        $cache = $this->context->presentationResolutionCache();
        $key = $cache->elementKey($parent) . ':core-grid-parent';
        if ( isset($this->coreGridParentCache[$key]) ) {
            return $this->coreGridParentCache[$key];
        }

        $declarations = $this->classOwnedResponsiveDeclarations(
            $parent,
            $this->presentationDeclarations($parent)
        );

        return $this->coreGridParentCache[$key] = $this->inlineGeometry()->isCoreGridContainerParent(
            $parent,
            $this->cssDeclarationString($declarations)
        );
    }

    /**
     * Whether a media-conditional author rule restates this element's grid
     * placement, so the placement is viewport-dependent in the source.
     */
    private function hasConditionalGridPlacement(DOMElement $element): bool
    {
        return $this->hasConditionalDeclarationForProperties($element, $this->inlineGeometry()->gridItemPlacementProperties());
    }

    /**
     * Whether a media-conditional author rule restates this element's
     * `grid-template-columns`, so a track list otherwise exactly expressible
     * as native `columnCount` grid layout (issue #2139 step 1) has a
     * viewport-dependent variant the transformer has no breakpoint mapping
     * for. The element keeps its base track list under CSS ownership instead
     * of losing the responsive variant to a native attribute the transformer
     * cannot make responsive.
     */
    private function hasConditionalGridTemplateColumns(DOMElement $element): bool
    {
        return $this->hasConditionalDeclarationForProperties($element, array( 'grid-template-columns' ));
    }

    /**
     * Whether a media-conditional author rule matching this element declares
     * any of the given properties.
     *
     * @param list<string> $properties
     */
    private function hasConditionalDeclarationForProperties(DOMElement $element, array $properties): bool
    {
        $wanted = array_fill_keys(array_map('strtolower', $properties), true);
        foreach ( $this->styleRuleCandidates($element, 'conditional') as $rule ) {
            if ( ! $this->matchesCssSelector($element, (string) ( $rule['selector'] ?? '' )) ) {
                continue;
            }
            // Classification properties live in `declarations`; properties
            // outside that allow-list (grid-item placement) ride the
            // conditional rule's `cascadedDeclarations` stream.
            foreach ( array( $rule['declarations'] ?? array(), $rule['cascadedDeclarations'] ?? array() ) as $declarations ) {
                foreach ( array_keys($declarations) as $property ) {
                    if ( isset($wanted[ strtolower(trim((string) $property)) ]) ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function recordGridPlacementCarrierFinding(DOMElement $element, string $reason): void
    {
        $this->context->transformationEvidence()->recordGridPlacementCarrierFinding(
            SourceDom::elementSelector($element),
            $reason
        );
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

        $conditionalFamilies = $this->conditionalFamiliesInPlay($element);

        if (array() === $conditionalFamilies) {
            return $declarations;
        }

        $inline = $this->cssDeclarations(SourceDom::attr($element, 'style'));
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
     * The inline projection a class-retaining rich-text carrier may keep.
     *
     * A rich-text carrier keeps the author's classes as selector hooks, so a
     * media-conditional rule continues to address it after conversion — but an
     * inline declaration out-ranks every stylesheet rule. Projecting the static
     * cascade winner inline would freeze the base breakpoint's value onto the
     * carrier and silence that responsive rule at every other width. The same
     * demotion `classOwnedResponsiveDeclarations()` applies to block wrappers,
     * scoped to conditional rules the carrier will still answer after the
     * transform: ones naming a class token the carrier retains.
     *
     * @param array<string, string> $declarations
     * @return array<string, string>
     */
    public function stripResponsiveClassOwnedDeclarations(DOMElement $element, array $declarations): array
    {
        if (array() === $declarations || array() === $this->context->sourceStyles()->conditionalRules()) {
            return $declarations;
        }

        $classes = SourceDom::boundedClassTokens(SourceDom::attr($element, 'class'));
        if (array() === $classes) {
            return $declarations;
        }

        $responsiveFamilies = $this->responsiveClassFamiliesInPlay($element, $classes);
        if (array() === $responsiveFamilies) {
            return $declarations;
        }

        $inline = $this->cssDeclarations(SourceDom::attr($element, 'style'));
        foreach (array_keys($declarations) as $property) {
            $family = $this->responsivePropertyFamily($property);
            if (! isset($responsiveFamilies[$family]) || $this->inlineOwnsResponsiveProperty($property, $family, $inline)) {
                continue;
            }
            unset($declarations[$property]);
        }

        return $declarations;
    }

    /**
     * Property families the media-conditional rules put in play for this
     * element through a selector naming one of its class tokens — the rules a
     * class-retaining carrier keeps answering after conversion.
     *
     * @param list<string> $classes
     * @return array<string, true>
     */
    private function responsiveClassFamiliesInPlay(DOMElement $element, array $classes): array
    {
        $families = array();
        foreach ($this->styleRuleCandidates($element, 'conditional') as $rule) {
            $selector = (string) ($rule['selector'] ?? '');
            if (! $this->matchesCssSelector($element, $selector) || ! $this->selectorNamesAnyRetainedClass($selector, $classes)) {
                continue;
            }
            foreach (array_keys($rule['declarations']) as $property) {
                $families[$this->responsivePropertyFamily($property)] = true;
            }
        }

        return $families;
    }

    /** @param list<string> $classes */
    private function selectorNamesAnyRetainedClass(string $selector, array $classes): bool
    {
        foreach ($classes as $class) {
            if (1 === preg_match('/' . CssIdent::classSelectorRegex($class) . '(?![a-zA-Z0-9_-])/', $selector)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Property families a conditional (media-scoped) rule puts in play for
     * this element: rules the matcher evaluates as matching, plus rules whose
     * selectors the matcher cannot evaluate but which name the element by id
     * or class.
     *
     * @return array<string, true>
     */
    private function conditionalFamiliesInPlay(DOMElement $element): array
    {
        $conditionalFamilies = array();
        foreach ($this->styleRuleCandidates($element, 'conditional') as $rule) {
            $selector = (string) ($rule['selector'] ?? '');
            if (! $this->matchesCssSelector($element, $selector)
                && ! $this->unsupportedSelectorReferencesElement($selector, $element)
            ) {
                continue;
            }
            foreach (array_keys($rule['declarations']) as $property) {
                $conditionalFamilies[$this->responsivePropertyFamily($property)] = true;
            }
        }
        foreach ($this->context->sourceStyles()->conditionalRules() as $rule) {
            $selector = (string) ($rule['selector'] ?? '');
            if ( 1 !== preg_match('/:(?:is|where|not|has)\s*\(/i', $selector)
                || ! $this->unsupportedSelectorReferencesElement($selector, $element)
            ) {
                continue;
            }
            foreach (array_keys($rule['declarations']) as $property) {
                $conditionalFamilies[$this->responsivePropertyFamily($property)] = true;
            }
        }

        return $conditionalFamilies;
    }

    /**
     * Value stated for this element by media-conditional rules.
     *
     * `specificityResolvedPresentationStyle()` reads the static collection
     * only. A capture serialises its desktop stylesheet behind a width query,
     * so properties a builder states there are absent from that view even
     * though they are what the document renders with. Later rules win, matching
     * declaration order within the collection.
     */
    public function conditionalDeclaration(DOMElement $element, string $property): string
    {
        $value = '';
        foreach ( $this->styleRuleCandidates($element, 'static-conditional') as $rule ) {
            $declared = trim((string) ( $rule['declarations'][$property] ?? '' ));
            if ( '' === $declared || ! $this->matchesCssSelector($element, (string) ( $rule['selector'] ?? '' )) ) {
                continue;
            }
            // A condition that never holds at the reference viewport (`@media
            // print` hiding nav chrome) is not the value the document renders
            // with; letting it through turns an anchored conditional winner
            // into a resting one.
            if ( ! $this->conditionsApplyAtReferenceViewport(is_array($rule['conditions'] ?? null) ? $rule['conditions'] : array()) ) {
                continue;
            }
            $value = $declared;
        }

        return $value;
    }

    /**
     * Viewport-scoped `display` values the source states for an element.
     *
     * A control that a responsive utility hides (`md:hidden`) states `display`
     * only inside a media condition. Resolving that element's box at one
     * reference viewport flattens the set to whichever value happened to apply
     * there, so the conditions have to travel with the values.
     *
     * Keyed by condition text, so a condition restated later keeps the value
     * that wins in source order.
     *
     * @return array<string, string>
     */
    public function conditionalDisplayRules(DOMElement $element): array
    {
        return $this->declaredPresentation($element, 'display')->conditional();
    }

    /**
     * Everything the source stylesheet declares for one property on one element.
     *
     * Carriers used to walk the candidate rules themselves and resolve straight
     * to a scalar, each with its own copy of the selector match, the condition
     * filter and the layer check. That is why the same flattening defect kept
     * reappearing in unrelated features: a responsive `font-size` frozen at the
     * desktop breakpoint, a `md:hidden` control frozen visible. Collecting the
     * whole declared set once means a carrier that wants one value has to say
     * which one it means.
     */
    public function declaredPresentation(DOMElement $element, string $property): DeclaredPresentation
    {
        $entries = array();
        foreach ( $this->styleRuleCandidates($element, 'static-conditional') as $rule ) {
            $declared = trim((string) ( $rule['declarations'][ $property ] ?? '' ));
            if ( '' === $declared || ! $this->matchesCssSelector($element, (string) ( $rule['selector'] ?? '' )) ) {
                continue;
            }

            $conditions = array_map('trim', $rule['conditions'] ?? array());
            // `@layer` scopes a declaration without conditioning it on the
            // viewport, so it is carried as the entry's layer rather than as a
            // condition the author could restate.
            $queries = array_values(array_filter(
                $conditions,
                static fn (string $condition): bool => 1 !== preg_match('/^@layer\b/i', $condition)
            ));

            $entries[] = array(
                'value' => $declared,
                'conditions' => $conditions,
                'queries' => $queries,
                'layer' => $rule['layer'] ?? null,
                'applies' => array() === $conditions || $this->conditionsApplyAtReferenceViewport($conditions),
            );
        }

        return DeclaredPresentation::fromEntries($entries);
    }

    /**
     * The authored `font-size` that must be serialized inline on a text block
     * for the authored size to win at the WordPress runtime, or '' when nothing
     * needs baking.
     *
     * `classOwnedResponsiveDeclarations()` strips a static `font-size` from
     * presentation attributes whenever any conditional (media-scoped) rule
     * touches the `font-size` family, so media queries keep winning the
     * cascade through author-stylesheet ownership. On heading/paragraph text
     * blocks that ownership can hand rendering to the UNLAYERED WordPress
     * rules a projected theme ships — `h1{font-size:inherit}` and the
     * `.wp-block-heading` defaults. The inline declaration a typography
     * support serializes is the only authored value that beats them.
     *
     * Whether ownership actually fails is a cascade question, evaluated at the
     * desktop reference viewport:
     *  - An UNLAYERED class rule beats the element-scoped defaults on
     *    specificity, so ownership works and nothing is baked — unless a
     *    media-conditional rule the desktop capture RENDERS (a mobile-first
     *    `min-width` breakpoint) restates the property for the element. That
     *    conditional value is the desktop truth the static base was stripped
     *    in favour of, and it is what gets baked (`@media (max-width:…)`
     *    overrides do not apply at the capture width, so a desktop-first
     *    cascade keeps stylesheet ownership unchanged).
     *  - Declarations inside a cascade `@layer` (Tailwind v4 emits every
     *    utility inside `@layer utilities`) lose to the unlayered WordPress
     *    rules regardless of class specificity. When every matching
     *    declaration is layered, the desktop-evaluated winner is baked — an
     *    unlayered rule declaring the property keeps cascade ownership and is
     *    never overridden.
     *
     * Callers merge this into block attributes only when
     * `presentationAttributes()` did not already serialize a `fontSize`, and
     * an element whose own inline style declares `font-size` keeps that
     * normal priority.
     */
    public function bakedTypographyFontSize(DOMElement $element): string
    {
        if ( isset($this->cssDeclarations(SourceDom::attr($element, 'style'))['font-size']) ) {
            return '';
        }

        // Both rescue paths only exist for stylesheets that carry conditional
        // rules or cascade layers; stylesheets without either already resolve
        // text-block font sizes through working author-stylesheet ownership.
        if ( array() === $this->context->sourceStyles()->conditionalRules() && ! $this->context->sourceStyles()->hasLayeredRules() ) {
            return '';
        }

        return $this->carriedDeclarationValue($this->cascadeFontSizeWinner($element));
    }

    public function responsiveTypographyClassName(DOMElement $element): string
    {
        if (isset($this->cssDeclarations(SourceDom::attr($element, 'style'))['font-size'])) {
            return '';
        }

        $hasConditionalFontSize = false;
        foreach ($this->context->sourceStyles()->conditionalRules() as $rule) {
            if (isset($rule['declarations']['font-size'])) {
                $hasConditionalFontSize = true;
                break;
            }
        }
        if (!$hasConditionalFontSize) {
            return '';
        }

        $declared = $this->declaredPresentation($element, 'font-size');
        if (!$declared->isConditional()) {
            return '';
        }

        $base = $this->carriedDeclarationValue($declared->base());
        $conditional = array();
        foreach ($declared->conditional() as $condition => $value) {
            $value = $this->carriedDeclarationValue($value);
            if ('' !== $value) {
                $conditional[$condition] = $value;
            }
        }
        if ('' === $base && array() === $conditional) {
            return '';
        }

        $className = 'blocks-engine-responsive-typography-' . substr(hash(
            'sha256',
            $this->geometryStructuralPath($element) . "\n" . $base . "\n" . serialize($conditional)
        ), 0, 12);
        $this->context->generatedSupportStyles()->registerResponsiveTypography($className, $base, $conditional);

        return $className;
    }

    /**
     * The cascade-winning authored `font-size` for an element, evaluated at
     * the desktop reference viewport and restricted to declarations that
     * cannot win the WordPress runtime cascade through stylesheet ownership.
     *
     * @return string
     */
    private function cascadeFontSizeWinner(DOMElement $element): string
    {
        $declared  = $this->declaredPresentation($element, 'font-size');
        $unlayered = $declared->unlayered();
        $layered   = $declared->layered();

        // An unlayered conditional rule the desktop capture renders is the
        // desktop truth the static base was stripped in favour of. It has to be
        // a conditioned declaration that wins, not merely a winner alongside
        // some unrelated breakpoint.
        $applyingConditional = $unlayered->conditionalOnly()->resolvedValue();
        if ( '' !== $applyingConditional ) {
            return $applyingConditional;
        }

        // An unlayered declaration keeps cascade ownership and is never
        // overridden, so nothing needs baking; neither does an element the
        // stylesheet never gives a layered size.
        if ( ! $unlayered->isEmpty() || $layered->isEmpty() ) {
            return '';
        }

        // A responsive authored `font-size` is a set of viewport-specific values,
        // not one value. Baking freezes the reference viewport's winner into an
        // unconditional inline style that then wins at every width, so a phone
        // renders the desktop size. The author's own breakpoints stay in the
        // projected stylesheet and keep resolving per viewport, so responsive
        // typography is left to them.
        if ( $layered->isConditional() ) {
            return '';
        }

        return $layered->resolvedValue();
    }

    /**
     * Resolve a source display declaration with the conditional custom-property
     * scope that a responsive capture applies to the rendered element.
     */
    public function resolvedConditionalDisplay(DOMElement $element): string
    {
        $display = $this->conditionalDeclaration($element, 'display');
        if ( '' === $display ) {
            $display = (string) ($this->cssDeclarations($this->specificityResolvedPresentationStyle($element))['display'] ?? '');
        }

        $display = trim(preg_replace('/\s*!important\s*$/i', '', $display) ?? $display);
        return $this->expandCssVariableReferences($display, $this->conditionalCascadedCustomProperties($element));
    }

    /**
     * Value a rule states for this element that the matcher cannot evaluate.
     *
     * Selectors using `:is`, `:where`, `:not` or `:has` are not matched
     * structurally, so declarations they carry never reach the cascade. When
     * such a rule names this element by id or class, the value is still the
     * source's stated intent for it, and losing it silently drops the element
     * to the destination default.
     *
     * Later rules win, matching declaration order, since specificity cannot be
     * compared for a selector that was never parsed.
     */
    public function unsupportedSelectorDeclaration(DOMElement $element, string $property): string
    {
        $value = '';
        foreach ( array( $this->context->sourceStyles()->staticRules(), $this->context->sourceStyles()->conditionalRules() ) as $rules ) {
            foreach ( $rules as $rule ) {
                $selector = (string) ( $rule['selector'] ?? '' );
                if ( 1 !== preg_match('/:(?:is|where|not|has)\s*\(/i', $selector)
                    || ! $this->unsupportedSelectorReferencesElement($selector, $element)
                ) {
                    continue;
                }
                $declared = trim((string) ( $rule['declarations'][$property] ?? '' ));
                if ( '' !== $declared ) {
                    $value = $declared;
                }
            }
        }

        return $value;
    }

    private function unsupportedSelectorReferencesElement(string $selector, DOMElement $element): bool
    {
        $id = trim(SourceDom::attr($element, 'id'));
        if ( '' !== $id && 1 === preg_match('/#' . preg_quote($id, '/') . '(?![\w-])/', $selector) ) {
            return true;
        }
        foreach ( preg_split('/\s+/', trim(SourceDom::attr($element, 'class'))) ?: array() as $className ) {
            if ( '' !== $className && 1 === preg_match('/' . CssIdent::classSelectorRegex($className) . '(?![\w-])/', $selector) ) {
                return true;
            }
        }
        return false;
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
        if ( $this->isSolitaryBackgroundColorDeclaration($declarations) ) {
            // A single, plain `background-color` with no accompanying image,
            // gradient, or positioning is exactly the shape `color` already
            // promotes to native text-color support: nothing about it depends
            // on the author's own selector to keep painting correctly. Baking
            // it inline the same way wins the cascade unconditionally instead
            // of depending on the projected author stylesheet out-ranking an
            // unlayered theme default (#1894, #1896) -- the defect behind a
            // solid authored button fill losing to `wp-element-button`.
            return $declarations;
        }

        $inline = $this->cssDeclarations(SourceDom::attr($element, 'style'));
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

    /**
     * Whether the captured declarations describe nothing more than a flat,
     * single-layer background color -- no shorthand, image, gradient, or
     * positioning that would need the author's own class to keep owning the
     * paint. Layered/positioned paint (the case {@see classOwnedBackgroundPaintDeclarations()}
     * exists for) still stays class-owned.
     *
     * @param array<string, string> $declarations
     */
    private function isSolitaryBackgroundColorDeclaration(array $declarations): bool
    {
        if ( ! isset($declarations['background-color']) || '' === trim((string) $declarations['background-color']) ) {
            return false;
        }
        foreach ( array(
            'background',
            'background-image',
            'background-position',
            'background-size',
            'background-repeat',
            'background-attachment',
            'background-origin',
            'background-clip',
            'background-blend-mode',
        ) as $property ) {
            if ( isset($declarations[ $property ]) && '' !== trim((string) $declarations[ $property ]) ) {
                return false;
            }
        }

        return true;
    }

    public function responsivePropertyFamily(string $property): string
    {
        $property = strtolower(trim($property));
        // `gap`/`row-gap`/`column-gap` are a genuine shorthand/longhand family
        // (setting one can affect the others) but they do not cascade-interact
        // with `display`, `justify-content`, `align-*`, or `flex-*`/`grid-*`:
        // a responsive breakpoint that only toggles `display` cannot conflict
        // with an unconditional `gap` value baked in from the base state.
        // Folding gap into the broader `layout` family below caused an
        // unrelated conditional `display` rule (e.g. `.md\:flex{display:flex}`)
        // to also strip an unconditional `gap` declaration (e.g.
        // `.gap-8{gap:...}`), silently dropping the authored gap for any
        // responsive-hidden flex/grid container.
        if (in_array($property, array('gap', 'row-gap', 'column-gap'), true)) {
            return 'gap';
        }
        if (
            in_array($property, array('display', 'justify-content', 'align-content', 'align-items', 'align-self'), true)
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
        foreach ( $this->matchingStyleRules($element, 'conditional') as $rule ) {
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
    ): string {
        return $this->inlineGeometry()->className(
            $element,
            $excludedProperties,
            $forcedProperties,
            $forcedDeclarations,
            $carrierOwnsInlineGeometry
        );
    }

    /**
     * A generated carrier class restating only the element's inline background
     * paint, for empty source containers kept as visual boundaries. '' when the
     * inline style paints no image.
     */
    public function emptyElementBackgroundCarrierClassName(DOMElement $element): string
    {
        return $this->inlineGeometry()->emptyElementBackgroundCarrierClassName($element);
    }

    /**
     * `core/embed`'s save() is a rigid, two-level `<figure><div
     * class="wp-block-embed__wrapper">` shape with no attribute path onto
     * that inner wrapper div at all — `customClassName` only ever reaches
     * the outer `<figure>`. An authored ABSOLUTE height therefore can't be
     * carried the way {@see inlineGeometryClassName()} carries other exact
     * dimensions (which puts the generated class directly on the box that
     * needs the declaration): the box that needs `height` here is the
     * wrapper, not the figure the transform is allowed to add a class to.
     *
     * This registers a descendant-selector rule instead — `.<carrier>
     * .wp-block-embed__wrapper{height:<value>}` — in the same generated
     * stylesheet {@see inlineGeometryClassName()} feeds, keyed off a carrier
     * class landing on the figure. Paired with core's own
     * `wp-has-aspect-ratio` (`.wp-has-aspect-ratio iframe{position:absolute;
     * inset:0;width:100%;height:100%}`), the wrapper's own fixed height —
     * not a proportional `wp-embed-aspect-*` padding-top — becomes the box
     * the eventual oEmbed-injected iframe stretches to fill.
     */
    public function embedWrapperHeightClassName(DOMElement $iframe, string $heightValue): string
    {
        $signature = $this->geometryStructuralPath($iframe) . "\nembed-wrapper-height\n" . $heightValue;
        $className = $this->context->layoutGeometry()->allocateCarrier($signature);
        $this->context->layoutGeometry()->registerRule(
            $className,
            '.' . $className . ' .wp-block-embed__wrapper{height:' . $heightValue . '!important}'
        );

        return $className;
    }

    /**
     * A source `<img>` box constrained by author CSS `min-width`/`max-width`/
     * `min-height`/`max-height` — properties core/image cannot express as a
     * native block attribute at all — or by a `width`/`height` the caller's
     * own native width/height resolution above could not carry as one.
     *
     * core/image's save() puts `className` on the generated `<figure>` — or,
     * when the source `<img>` is itself the anchor of a link, on the same
     * figure one level further out — never on the descendant `<img>` that
     * actually paints. An author class establishing this box therefore sizes
     * a box nothing renders, and the image falls back to its intrinsic
     * width/height attributes. This carries the resolved box through the
     * same be-inline-geometry primitive {@see
     * SvgMaterializer::inlineSvgImageAttributesFromMarkup()} already uses for
     * a materialized inline SVG's box (see also #1624 for the analogous
     * button-icon failure this mirrors), targeting a descendant `img`
     * selector so it reaches the `<img>` whether or not a source `<a>`
     * still sits between the figure and it.
     *
     * A `width`/`height` this resolves is a safety net only: it fires
     * exclusively when the caller's own native attribute for that axis is
     * still empty (and, for height, no native aspectRatio already implies
     * it), so an already-successful native width/height/aspectRatio/scale
     * carry is left untouched.
     *
     * `$widthResolvesToAuto`/`$heightResolvesToAuto` cover the remaining gap
     * on the free axis of a class-sized image (e.g. Tailwind's `h-10 w-auto
     * max-w-[200px]`): when {@see
     * ImageDimensionResolver::authorResolvesDimensionToAuto()} already
     * suppressed the caller's native attribute for that axis because the
     * author explicitly resolved it to `auto`, this box is the only place
     * left to say so, or the axis is left unstated and falls to whichever
     * `width`/`height` WordPress core's OWN block-library stylesheet happens
     * to declare for `.wp-block-image img` in that rendering context —
     * `width:auto` on the frontend (matching the author by accident) but
     * `width:100%` in the editor canvas (stretching the image to fill the
     * figure, then clamped by max-width instead of derived from height and
     * the intrinsic aspect ratio). Restating the author's own `auto` here
     * makes the box identical in both contexts instead of depending on which
     * context-specific core default happens to agree with it. This reuses
     * the same author-stated-auto detection the caller already ran to
     * suppress the native attribute, rather than re-deriving it from the
     * declarations a second time.
     */
    public function imageBoxConstraintClassName(
        DOMElement $image,
        string $nativeWidth,
        string $nativeHeight,
        bool $hasNativeAspectRatio,
        bool $widthResolvesToAuto = false,
        bool $heightResolvesToAuto = false
    ): string {
        $declarations = $this->imageShapeDeclarations($image);
        $box = array();
        foreach (array('width', 'min-width', 'max-width') as $property) {
            if ('width' === $property) {
                if ('' !== $nativeWidth) {
                    continue;
                }
                if ($widthResolvesToAuto) {
                    $box['width'] = 'auto';
                    continue;
                }
            }
            $value = $this->comparableImageShapeConstraintValue($declarations, $property);
            if ('' !== $value) {
                $box[$property] = $value;
            }
        }
        foreach (array('height', 'min-height', 'max-height') as $property) {
            if ('height' === $property) {
                if ('' !== $nativeHeight || $hasNativeAspectRatio) {
                    continue;
                }
                if ($heightResolvesToAuto) {
                    $box['height'] = 'auto';
                    continue;
                }
            }
            $value = $this->comparableImageShapeConstraintValue($declarations, $property);
            if ('' !== $value) {
                $box[$property] = $value;
            }
        }
        if (array() === $box) {
            return '';
        }

        ksort($box);
        $declarationList = array();
        foreach ($box as $property => $value) {
            $declarationList[] = $property . ':' . $value . '!important';
        }
        $signature = $this->geometryStructuralPath($image) . "\nimage-box-constraint\n" . implode(';', $declarationList);
        $className = $this->context->layoutGeometry()->allocateCarrier($signature);
        $this->context->layoutGeometry()->registerRule(
            $className,
            '.' . $className . ' img{' . implode(';', $declarationList) . '}'
        );

        return $className;
    }

    /**
     * `auto` (and the keywords that compute to it) stays excluded here even
     * though {@see imageBoxConstraintClassName()} now carries an author-
     * stated `auto` explicitly on the `width`/`height` axis itself: for
     * `min-width`/`max-width`/`min-height`/`max-height` an `auto` is simply
     * the property's own initial value, i.e. "no constraint on this axis",
     * not a carryable instruction the way a plain axis's `auto` is. Kept
     * excluded for `width`/`height` too, since that case is handled by the
     * caller passing `$widthResolvesToAuto`/`$heightResolvesToAuto` instead
     * of this generic value comparison — {@see
     * ImageDimensionResolver::authorResolvesDimensionToAuto()} is the single
     * place that decides an axis is author-stated `auto`.
     *
     * @param array<string, array<string, mixed>> $declarations
     */
    private function comparableImageShapeConstraintValue(array $declarations, string $property): string
    {
        $value = trim(CssValueInspector::withoutImportant((string) ($declarations[$property]['value'] ?? '')));
        if (
            '' === $value
            || in_array(strtolower($value), array('auto', 'inherit', 'initial', 'unset', 'revert', 'revert-layer', 'none'), true)
            || preg_match('~[{}<>;]|/\*~', $value)
        ) {
            return '';
        }

        return $value;
    }

    /**
     * A source document root commonly inherits `height:100%` through html and
     * body. Block content gains WordPress-owned ancestors, which makes that
     * percentage indefinite and can collapse absolute page layers to a header.
     */
    private function viewportRootHeightClassName(DOMElement $element): string
    {
        $parent = $element->parentNode;
        if ( ! $parent instanceof DOMElement
            || ( 'body' !== strtolower($parent->tagName) && ! $this->isDocumentVariantRoot($parent) )
            || '' === SourceDom::safeAnchor(SourceDom::attr($element, 'id'))
        ) {
            return '';
        }

        $height = CssValueInspector::comparable((string) ($this->structuralPresentationDeclarations($element)['height'] ?? ''));
        if ( '100%' !== $height ) {
            return '';
        }

        $rule = 'height:100vh!important';
        $className = $this->context->layoutGeometry()->allocateCarrier(
            'viewport-root-height' . "\n" . $this->geometryStructuralPath($element) . "\n" . $rule
        );
        $this->context->layoutGeometry()->registerRule($className, '.' . $className . '{' . $rule . '}');
        return $className;
    }

    private function isDocumentVariantRoot(DOMElement $element): bool
    {
        foreach (preg_split('/\s+/', trim(SourceDom::attr($element, 'class'))) ?: array() as $className) {
            if (str_starts_with($className, 'site-document-variant-')
                || in_array($className, array('data-liberation-desktop-document', 'data-liberation-mobile-document'), true)
            ) {
                return true;
            }
        }
        return false;
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

        foreach ( $this->matchingStyleRules($element, 'static-conditional') as $rule ) {
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

        foreach ( $this->matchingStyleRules($element, 'static-conditional') as $rule ) {
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
     * before they are stored, so only properties on that allowlist are
     * visible here. `position`, `inset`, `top`, `right`, `bottom`, `left`,
     * `z-index` and `direction` are on it; `overflow`, `overflow-x/y`,
     * `transform`, `transition`, `animation`, `opacity`, `visibility`,
     * `float`, `clear`, `align-self`, `justify-self`, `white-space` and `cursor`
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
        foreach ( $this->matchingStyleRules($element, 'static-conditional') as $rule ) {
            foreach ( $rule['declarations'] as $property => $value ) {
                $property = strtolower((string) $property);
                if ( isset($wanted[ $property ]) ) {
                    $declared[ $property ][] = $this->context->cssComparableValue((string) $value);
                }
                // The `background` shorthand resets every longhand it does not
                // set, `background-image` included, so a rule that declares it
                // is also the rule's stated `background-image` value even
                // though the parser never sees that literal property name.
                // Without this, an author rule painting an image through the
                // shorthand is invisible here, an inline `background-image`
                // override can never find the conflicting value it exists to
                // beat, and the override is wrongly dropped.
                if ( 'background' === $property && isset($wanted['background-image']) ) {
                    $declared['background-image'][] = $this->context->cssComparableValue(
                        $this->backgroundShorthandImageValue((string) $value)
                    );
                }
            }
        }

        $cache->authorDeclaredPropertyValues[ $cacheKey ] = $declared;

        return $declared;
    }

    /**
     * The `background-image` longhand a `background` shorthand implies.
     * Mirrors `StyleAttributeMapper::backgroundColor()`'s treatment of the
     * shorthand's color token: a shorthand without an image function paints
     * the same "no image" state as an explicit `background-image: none`.
     */
    private function backgroundShorthandImageValue(string $shorthand): string
    {
        return preg_match('/\b(?:url\s*\(|[a-z-]*gradient\s*\()/i', $shorthand)
            ? trim($shorthand)
            : 'none';
    }

    /**
     * Author-declared values from conditional rules, such as media queries.
     *
     * @param array<int, string> $properties
     * @return array<string, array<int, string>>
     */
    public function conditionalAuthorDeclaredPropertyValues(DOMElement $element, array $properties): array
    {
        sort($properties, SORT_STRING);
        $wanted = array_fill_keys($properties, true);
        $declared = array();
        foreach ( $this->matchingStyleRules($element, 'conditional') as $rule ) {
            foreach ( $rule['declarations'] as $property => $value ) {
                if ( isset($wanted[ strtolower((string) $property) ]) ) {
                    $declared[ strtolower((string) $property) ][] = $this->context->cssComparableValue((string) $value);
                }
            }
        }
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
        if ( in_array('text-align', $excludedProperties, true) || 0 === SourceDom::directElementChildCount($element) ) {
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
     * The alignment a text wrapper's GENERATED inner RichText blocks must carry
     * so the source's own alignment survives, or '' when nothing is at risk.
     *
     * A wrapper that stays a core/group or core/quote because it owns box chrome
     * has no native alignment attribute, and its inline `text-align` only reaches
     * the generated stylesheet when the wrapper already mints a geometry carrier
     * for some other property. Padding, borders and radii are consumed into block
     * supports rather than a carrier, so the common "boxed, centred intro copy"
     * wrapper mints none and the alignment is dropped outright. The inner
     * paragraph the engine generates for that wrapper's own text is the native
     * carrier for it.
     *
     * The gate is the same one `inlineInheritedTextAlignDeclaration()` applies:
     * an alignment already reproduced by the wrapper's OWN preserved author rule,
     * or inherited from a preserved ancestor, is not at risk and is not restated.
     * Only `left`/`center`/`right` are returned, because `align` is what the
     * generated block carries and that attribute has no `start`/`end` spelling.
     */
    public function generatedRichTextAlignment(DOMElement $element): string
    {
        $value = strtolower($this->carriedDeclarationValue((string) ($this->presentationDeclarations($element)['text-align'] ?? '')));
        if ( ! in_array($value, array( 'left', 'center', 'right' ), true) ) {
            return '';
        }

        $rightToLeft = $this->isRightToLeftElement($element);
        if ( $this->comparableTextAlignment($value, $rightToLeft) === $this->effectiveTextAlignmentWithoutInline($element, $rightToLeft) ) {
            return '';
        }

        return $value;
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
            $direction = strtolower(trim(SourceDom::attr($node, 'dir')));
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
     *
     * An image the source links adds a second injected box to that same chain:
     * core/image serializes it as <figure><a><img></a></figure>, and that <a>
     * is an inline-level, auto-height element the source never had. Carrying
     * the height only as far as the figure leaves the authored percentage
     * resolving against the link instead, so the image still collapses to its
     * intrinsic ratio while the identical unlinked image renders correctly.
     * Restate the fill on the injected link so the chain reaches the image
     * unbroken. The link box is generated, not authored, so sizing it overrides
     * nothing the source said.
     */
    public function injectedFigureHeightClassName(DOMElement $image): string
    {
        if ( ! $this->authorStylesDriveImageHeight($image) ) {
            return '';
        }

        $rule = 'height:100% !important';
        $linkRule = $this->hasAncestorLink($image) ? '>a{display:block !important;width:100% !important;' . $rule . '}' : '';
        $className = $this->context->layoutGeometry()->allocateCarrier('figure-height' . "\n" . $this->geometryStructuralPath($image) . "\n" . $rule . $linkRule);
        $this->context->layoutGeometry()->registerRule(
            $className,
            '.' . $className . '{' . $rule . '}' . ('' === $linkRule ? '' : '.' . $className . $linkRule)
        );

        return $className;
    }

    /**
     * Whether a source ancestor link can reach this image, and therefore
     * whether the generated block can carry one. core/image only ever emits
     * its link anchor around the <img>, so no other element can appear in
     * between.
     */
    private function hasAncestorLink(DOMElement $element): bool
    {
        for ( $ancestor = $element->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode ) {
            if ( 'a' === strtolower($ancestor->tagName) ) {
                return true;
            }
        }

        return false;
    }

    private function authorStylesDriveImageHeight(DOMElement $image): bool
    {
        $declarations = $this->structuralPresentationDeclarations($image);
        foreach ( array( 'height', 'min-height' ) as $property ) {
            if ( $this->isCssPercentageValue((string) ($declarations[$property] ?? '')) ) {
                return true;
            }
        }

        foreach ( $this->matchingStyleRules($image, 'conditional') as $rule ) {
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
        $declarations = $this->cssDeclarations(SourceDom::attr($element, 'style'));
        $style = array();
        $geometryValues = array();
        $geometry = $this->inlineGeometry();
        $properties = $geometry->geometryProperties();
        if ( $geometry->isNamedFragmentTarget($element) ) {
            $properties = array_merge($properties, $geometry->namedFragmentTargetProperties());
        }
        $properties = array_merge($properties, $geometry->positioningPropertiesFor($element, $declarations));
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
        // Most callers case-fold declaration keys for matching, but custom
        // property names are case-sensitive: `--headerBg` and `--headerbg` are
        // different properties, so the carrier must declare the name the
        // author wrote or every `var(--headerBg)` reader falls back to its
        // :root default. A key that already is an authored name stays as is.
        $authoredNames = array();
        foreach (CssValueSplitter::splitTopLevel(SourceDom::attr($element, 'style'), array(';')) as $declaration) {
            $name = trim(explode(':', $declaration, 2)[0]);
            if (str_starts_with($name, '--')) {
                $authoredNames[$name] = $name;
                $authoredNames[strtolower($name)] ??= $name;
            }
        }
        $customProperties = array();
        foreach ($declarations as $property => $value) {
            if (str_starts_with($property, '--') && isset($required[$property])) {
                $customProperties[$authoredNames[$property] ?? $property] = CssUrlRewriter::rewrite($value, fn (string $url): string => $this->context->resolvedAssetImageUrl($url));
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
            foreach ( $this->matchingStyleRules($target, 'static-conditional-pseudo') as $rule ) {
                // Read the rule's UNFILTERED `var()` references. `declarations`
                // is the `safeVisualDeclarations()` classification allow-list,
                // which omits `opacity`, `transform`, `filter` and friends — a
                // reference from one of those was invisible here, so the inline
                // definition an ancestor declared was judged unused and dropped,
                // leaving the reader invalid at computed-value time.
                $consumed += array_intersect_key(array_fill_keys($rule['customPropertyReferences'] ?? array(), true), $declared);
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
                    $properties[$property] = true;
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
        return $this->isCssAllResetValue((string) ($this->cssDeclarations(SourceDom::attr($element, 'style'))['all'] ?? ''));
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

    /** A fixed inline height only clips converted descendants when overflow clips. */
    public function hasTopologyUnsafeFixedHeight(DOMElement $element): bool
    {
        $height = CssValueInspector::comparable((string) ($this->cssDeclarations(SourceDom::attr($element, 'style'))['height'] ?? ''));
        $overflow = CssValueInspector::comparable((string) ($this->structuralPresentationDeclarations($element)['overflow'] ?? ''));

        return 1 === preg_match('/^[1-9][0-9]*(?:\.\d+)?px$/', $height)
            && in_array($overflow, array('hidden', 'clip'), true);
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
        foreach ( $this->matchingStyleRules($element, 'static') as $rule ) {
            $declarations = $this->mergeCssDeclarationMaps($declarations, $rule['declarations']);
        }

        return $cache->structuralDeclarations[$cacheKey] = $this->mergeCssDeclarationMaps($declarations, $this->cssDeclarations(SourceDom::attr($element, 'style')));
    }

    /**
     * Resolves matching author rules even when the element is below the native
     * presentation boundary. Media materializers use this only to decide whether
     * source CSS, rather than an intrinsic asset attribute, owns its media box.
     *
     * @return array<string, string>
     */
    public function authorStructuralDeclarations(DOMElement $element): array
    {
        $authorStyles = $this->context->authorStyles();
        $selectorCache = $authorStyles->selectorMatchCache();
        $declarations = array();
        foreach ( $selectorCache->styleRuleCandidates($element, 'author-structural', $authorStyles->styleRuleCandidateIndex()) as $rule ) {
            if ( ! $selectorCache->matches($element, (string) ($rule['selector'] ?? ''), $rule['parsed'] ?? array(), true)['matches'] ) {
                continue;
            }
            $declarations = $this->mergeCssDeclarationMaps($declarations, $rule['declarations'] ?? array());
        }

        return $this->mergeCssDeclarationMaps($declarations, $this->cssDeclarations(SourceDom::attr($element, 'style')));
    }

    /**
     * Every value the author stylesheet states for these properties on this
     * element in its resting state, at any viewport, in source order with the
     * inline style last.
     *
     * The source-style collections keep only the classification allow-list, so
     * a property such as `text-indent` or `clip` never reaches them; the author
     * analysis keeps every declaration together with its condition stack. This
     * answers "does the source ever state X here" for recognition, not which
     * value wins, so callers must not project it.
     *
     * @param array<int, string> $properties
     * @return array<string, list<string>>
     */
    public function authorDeclaredValuesAtAnyViewport(DOMElement $element, array $properties): array
    {
        $wanted = array_fill_keys(array_map('strtolower', $properties), true);
        $authorStyles = $this->context->authorStyles();
        $selectorCache = $authorStyles->selectorMatchCache();
        $rules = $selectorCache->styleRuleCandidates($element, 'author-structural', $authorStyles->styleRuleCandidateIndex());
        $declarationSets = array();
        foreach ( $rules as $rule ) {
            if ( $selectorCache->matches($element, (string) ($rule['selector'] ?? ''), $rule['parsed'] ?? array())['matches'] ) {
                $declarationSets[] = $rule['declarations'] ?? array();
            }
        }
        $declarationSets[] = $this->cssDeclarations(SourceDom::attr($element, 'style'));

        $declared = array();
        foreach ( $declarationSets as $declarations ) {
            foreach ( $declarations as $property => $value ) {
                $property = strtolower((string) $property);
                if ( isset($wanted[ $property ]) ) {
                    $declared[ $property ][] = (string) $value;
                }
            }
        }

        return $declared;
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
        foreach ( $this->matchingStyleRules($element, 'static') as $rule ) {
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

        foreach ($this->mediaTextInlineDeclarationEntries(SourceDom::attr($element, 'style')) as $entry) {
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
            $listSpecificity = CssSelectorMatcher::selectorListArgumentSpecificity($compound);
            $ids += $listSpecificity['ids'];
            $classes += $listSpecificity['classes'];
            $elements += $listSpecificity['types'];
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
            || $this->isHiddenPositionedLayer($element, $declarations)
            || $this->hasKeyboardFocusReveal($element, $declarations)
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

        $normalized = $this->closedStateNormalizer()->strip($declarations, $this->sourceRevealProperties($element, $declarations));
        if ( null !== $responsiveDisplay ) {
            $normalized['declarations']['display'] = $responsiveDisplay;
        }
        if ( array() !== $normalized['stripped'] ) {
            $this->context->transformationEvidence()->recordFrozenHiddenState(array(
                'tag'          => strtolower($element->tagName),
                'selector'     => SourceDom::elementSelector($element),
                'editor_selector' => $this->editorStaticStateSelector($element),
                'declarations' => $normalized['stripped'],
            ));
        }

        return $normalized['declarations'];
    }

    /** @param array<string, string> $declarations */
    private function hasKeyboardFocusReveal(DOMElement $element, array $declarations): bool
    {
        foreach ($this->context->sourceStyles()->revealStateRules() as $rule) {
            if (
                ! in_array((string) ($rule['state'] ?? ''), array('focus', 'focus-visible', 'focus-within'), true)
                || ! $this->matchesCssSelector($element, (string) ($rule['base_selector'] ?? ''))
                || ! $this->matchesCssSelector($element, (string) ($rule['state_subject_selector'] ?? ''))
            ) {
                continue;
            }

            $revealed = (array) ($rule['declarations'] ?? array());
            $opacity = CssValueInspector::comparable((string) ($declarations['opacity'] ?? ''));
            $revealedOpacity = CssValueInspector::comparable((string) ($revealed['opacity'] ?? ''));
            if (is_numeric($opacity) && 0.0 === (float) $opacity && is_numeric($revealedOpacity) && 0.0 < (float) $revealedOpacity) {
                return true;
            }
            if ('none' === CssValueInspector::comparable((string) ($declarations['display'] ?? '')) && $this->isVisibleDisplay((string) ($revealed['display'] ?? ''))) {
                return true;
            }
            if ('hidden' === CssValueInspector::comparable((string) ($declarations['visibility'] ?? '')) && 'visible' === CssValueInspector::comparable((string) ($revealed['visibility'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    private function hasConditionalVisibleDisplay(DOMElement $element): bool
    {
        foreach ( $this->matchingStyleRules($element, 'conditional') as $rule ) {
            $display = CssValueInspector::comparable((string) ($rule['declarations']['display'] ?? ''));
            if ( '' !== $display && 'none' !== $display ) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, string> $declarations @return array<string, true> */
    private function sourceRevealProperties(DOMElement $element, array $declarations): array
    {
        if ($this->context->sourceStyles()->isAriaControlledTarget($element)) {
            return array(
                'display' => true,
                'visibility' => true,
                'height' => true,
                'max-height' => true,
                'overflow' => true,
            );
        }

        $revealed = array();
        foreach ($this->context->sourceStyles()->revealStateRules() as $rule) {
            if (! $this->matchesCssSelector($element, (string) $rule['base_selector'])) {
                continue;
            }
            $state = (array) ($rule['declarations'] ?? array());
            if ('none' === CssValueInspector::comparable((string) ($declarations['display'] ?? '')) && $this->isVisibleDisplay((string) ($state['display'] ?? ''))) {
                $revealed['display'] = true;
            }
            if ('hidden' === CssValueInspector::comparable((string) ($declarations['visibility'] ?? '')) && 'visible' === CssValueInspector::comparable((string) ($state['visibility'] ?? ''))) {
                $revealed['visibility'] = true;
            }
            $revealsGeometry = false;
            foreach (array('height', 'max-height') as $property) {
                if ($this->isZeroLength((string) ($declarations[$property] ?? '')) && $this->isExpandedLength($property, (string) ($state[$property] ?? ''))) {
                    $revealed[$property] = true;
                    $revealsGeometry = true;
                }
            }
            if (
                $revealsGeometry
                && 'visible' === CssValueInspector::comparable((string) ($state['overflow'] ?? ''))
            ) {
                $revealed['overflow'] = true;
            }
        }

        return $revealed;
    }

    private function isVisibleDisplay(string $value): bool
    {
        return in_array(CssValueInspector::comparable($value), array('block', 'contents', 'flex', 'grid', 'inline', 'inline-block', 'inline-flex', 'inline-grid', 'list-item', 'table'), true);
    }

    private function isZeroLength(string $value): bool
    {
        return 1 === preg_match('/^0(?:px|em|rem|%|vh|vw)?$/', CssValueInspector::comparable($value));
    }

    private function isExpandedLength(string $property, string $value): bool
    {
        $value = CssValueInspector::comparable($value);
        if ('' === $value || $this->isZeroLength($value)) {
            return false;
        }

        return ('height' === $property && in_array($value, array('auto', 'fit-content', 'max-content', 'min-content'), true))
            || ('max-height' === $property && 'none' === $value)
            || 1 === preg_match('/^(?:\d*\.\d+|\d+)(?:px|em|rem|%|vh|vw)$/', $value);
    }

    private function isExplicitlyInactiveState(DOMElement $element): bool
    {
        if (
            'false' === strtolower(trim(SourceDom::attr($element, 'data-visible')))
            || 'dialog' === strtolower(trim(SourceDom::attr($element, 'role')))
            || 'true' === strtolower(trim(SourceDom::attr($element, 'aria-modal')))
        ) {
            return true;
        }

        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if (
                $descendant instanceof DOMElement
                && (
                    'dialog' === strtolower(trim(SourceDom::attr($descendant, 'role')))
                    || 'true' === strtolower(trim(SourceDom::attr($descendant, 'aria-modal')))
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, string> $declarations */
    private function isHiddenPositionedLayer(DOMElement $element, array $declarations): bool
    {
        $opacity = CssValueInspector::comparable((string) ($declarations['opacity'] ?? '1'));
        $hidden = 'none' === CssValueInspector::comparable((string) ($declarations['display'] ?? ''))
            || 'hidden' === CssValueInspector::comparable((string) ($declarations['visibility'] ?? ''))
            || (is_numeric($opacity) && 0.0 === (float) $opacity);
        if ( ! $hidden ) {
            return false;
        }

        $resolved = array();
        foreach ( $this->matchingStyleRules($element, 'static') as $rule ) {
            $resolved = $this->mergeCssDeclarationMaps($resolved, $rule['declarations']);
        }
        $resolved = $this->mergeCssDeclarationMaps($resolved, $this->cssDeclarations(SourceDom::attr($element, 'style')));
        $resolved = $this->mergeCssDeclarationMaps($resolved, $declarations);
        $position = CssValueInspector::comparable((string) ($resolved['position'] ?? ''));
        return in_array($position, array( 'absolute', 'fixed' ), true);
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
            foreach ( $this->matchingStyleRules($element, 'hidden-state') as $rule ) {
                $declarations = $this->mergeCssDeclarationMaps($declarations, $rule['declarations']);
            }
            $declarations = $this->mergeCssDeclarationMaps($declarations, $this->cssDeclarations(SourceDom::attr($element, 'style')));
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
                // Projected stylesheets may not be in the static rule analysis.
                // Keep positioning alongside visibility for the overlay guard.
                $declarations = array_intersect_key($this->cssDeclarations($body), $this->closedStateNormalizer()->hiddenStateProperties() + array('position' => true));
                foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
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
        $id = trim(SourceDom::attr($element, 'id'));
        if ( preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $id) ) {
            return '#' . $id;
        }

        $classes = SourceDom::boundedClassTokens(SourceDom::attr($element, 'class'));

        return array() === $classes ? '' : CssIdent::compoundClassSelector($classes);
    }

    private function editorAnchorClassName(DOMElement $element): string
    {
        if ( ! in_array(strtolower($element->tagName), array('article', 'aside', 'div', 'footer', 'header', 'main', 'section'), true) ) {
            return '';
        }
        $anchor = SourceDom::safeAnchor(SourceDom::attr($element, 'id'));
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
            fn (DOMElement $source, string $name): string => SourceDom::attr($source, $name)
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

        $inlineStyle = SourceDom::attr($element, 'style');
        if ( array() === $this->context->sourceStyles()->staticRules() || (! $this->isHighValueStyledElement($element) && ! $this->hasGenericRecognitionDemand($element)) ) {
            $cache->mergedStyles[$cacheKey] = $inlineStyle;
            return $inlineStyle;
        }

        $declarations = array();
        foreach ( $this->matchingStyleRules($element, 'static') as $rule ) {
            $declarations = $this->mergeCssDeclarationMaps($declarations, $rule['declarations']);
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
        return $this->resolvedCascadeStyle($element, 'static');
    }
    /**
     * Resolve the authored resting cascade across static AND media-conditional
     * rules that apply at the desktop reference viewport.
     *
     * `specificityResolvedPresentationStyle()` reads the static collection only,
     * which is right for carrying presentation: class-owned conditional values
     * must stay under author-stylesheet ownership so media queries keep winning
     * the cascade. Recognition, however, needs to SEE those values — a capture
     * serialises a builder's desktop styles behind a width query, so an explicit
     * control surface can be invisible to the static view even though it is what
     * the document renders with. Consumers must use this for classification
     * signals only, never for presentation projection.
     */
    public function controlSurfaceResolvedStyle(DOMElement $element): string
    {
        return $this->resolvedCascadeStyle($element, 'static-conditional');
    }

    /**
     * Resolve an element's authored resting cascade over one rule collection.
     *
     * The two callers above were the same thirty-five lines twice over, differing
     * only in which collection they read. What separates them is that choice, not
     * the resolution, so the resolution is written once and each caller is the
     * sentence that names its collection.
     *
     * Rules a media query conditions are skipped unless they apply at the
     * reference viewport. That test is a no-op for the static collection, whose
     * rules carry no conditions by construction, so it does not need to be a
     * parameter.
     */
    private function resolvedCascadeStyle(DOMElement $element, string $collection): string
    {
        $cascade = array();
        $sequence = 0;
        foreach ( $this->matchingStyleRules($element, $collection) as $rule ) {
            if ( ! empty($rule['conditions']) && ! $this->conditionsApplyAtReferenceViewport($rule['conditions']) ) {
                continue;
            }

            $specificity = $this->mediaTextSelectorSpecificity($rule['selector']);
            foreach ( $rule['declarations'] as $property => $value ) {
                $this->applyMediaTextCascadeDeclaration($cascade, (string) $property, (string) $value, false, $specificity, ++$sequence);
            }
        }

        foreach ( $this->cssDeclarations(SourceDom::attr($element, 'style')) as $property => $value ) {
            $this->applyMediaTextCascadeDeclaration($cascade, (string) $property, (string) $value, true, array( PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX ), ++$sequence);
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
        foreach ( $this->matchingStyleRules($element, 'static') as $rule ) {
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

        foreach ( $this->mediaTextInlineDeclarationEntries(SourceDom::attr($element, 'style')) as $entry ) {
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

        foreach ( $this->matchingStyleRules($element, 'static-conditional') as $rule ) {
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
            'reveal_state' => array(),
            'image_shape' => array(),
            'pseudo' => array(),
            'cascaded_values' => array(),
        );
        $imageOrder = 0;
        $layers = array();
        if (preg_match_all('/@layer\s+([a-z0-9_-]+(?:\.[a-z0-9_-]+)?(?:\s*,\s*[a-z0-9_-]+(?:\.[a-z0-9_-]+)?)*)\s*;/i', $css, $layerStatements)) {
            foreach ($layerStatements[1] as $statement) foreach (explode(',', $statement) as $name) $layers[strtolower(trim($name))] ??= count($layers);
        }
        (new CssStylesheetTransformer())->visitStyleRules(
            $css,
            function (string $prelude, string $body, array $conditions) use (&$analysis, &$imageOrder, &$layers): void {
                $rawDeclarations = $this->cssDeclarations($body);
                $declarations = $this->safeVisualDeclarations($rawDeclarations);
                // A materialized SVG asset is an isolated document: it cannot
                // inherit `fill`/`stroke`/`color` (or the custom properties they
                // reference) from the host stylesheet the way the inline source
                // could. This unfiltered stream — kept separate from the finite
                // `safeVisualDeclarations()` allow-list used for classification —
                // lets paint materialization resolve the same cascade a browser
                // would, including id/class-scoped custom-property indirection.
                $cascadedValueDeclarations = $this->cascadeRelevantDeclarations($rawDeclarations);
                // Which custom properties this rule READS, taken from the same
                // unfiltered stream. Consumption is not confined to the
                // classification allow-list — `opacity`, `transform`, `filter`
                // and `transition` all read `var()` — and an inline definition
                // an ancestor declares is only carried when the engine can see
                // a reader for it. Names only: this rides every rule record.
                $customPropertyReferences = array_keys($this->customPropertiesReferencedByValues($rawDeclarations));
                $readingCustomProperties = static fn (array $rule): array => array() === $customPropertyReferences
                    ? $rule
                    : $rule + array( 'customPropertyReferences' => $customPropertyReferences );
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
                $layer = null;
                foreach ($conditions as $condition) if (preg_match('/^@layer\s+([a-z0-9_-]+(?:\.[a-z0-9_-]+)*)\b/i', trim($condition), $match)) {
                    $name = strtolower($match[1]);
                    $layers[$name] ??= count($layers);
                    $layer = $name;
                }
                // A rule is static when every condition wrapping it resolves the
                // same way for every reader. `@layer` always does. So does an
                // `@supports` condition the engine knows to be true: the browser
                // rendering the output will take that branch unconditionally, so
                // the resting cascade has to see it too.
                //
                // Tailwind v4 writes each opacity-modified colour as an opaque
                // fallback plus the real translucent value behind
                // `@supports (color: color-mix(...))`. Leaving that branch out of
                // the resting rules resolved every such colour to the fallback
                // the framework only emits for browsers without the feature.
                //
                // `@media` stays conditional: it depends on the viewport, which
                // is exactly what the conditional stream exists to model.
                foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? explode(',', $prelude) as $selector) {
                    $selector = trim($selector);
                    if ('' === $selector || str_starts_with($selector, '@')) {
                        continue;
                    }
                    $lifted = ColorSchemeVariant::liftSelector($selector);
                    $selector = trim($lifted['prelude']);
                    $selectorConditions = $conditions;
                    if (null !== $lifted['scheme']) {
                        $selectorConditions[] = '@media (prefers-color-scheme: ' . $lifted['scheme'] . ')';
                    }
                    $selectorIsStaticLayerRule = array() !== $selectorConditions
                        && array_reduce($selectorConditions, fn (bool $static, string $condition): bool => $static && $this->conditionResolvesStatically($condition), true);
                    $supportedRestingSelector = ! $this->selectorCarriesPseudoState($selector) && $this->isSupportedCssSelector($selector);
                    if ($supportedRestingSelector && (array() === $selectorConditions || $selectorIsStaticLayerRule) && (array() !== $declarations || array() !== $mediaTextDeclarations || array() !== $customPropertyReferences)) {
                        $analysis['static'][] = $readingCustomProperties(array(
                            'selector' => $selector,
                            'declarations' => $declarations,
                            'mediaTextDeclarations' => $mediaTextDeclarations,
                            'mediaTextSpecificity' => $this->mediaTextSelectorSpecificity($selector),
                            'layer' => $layer,
                        ));
                    }
                    if (! $this->selectorCarriesPseudoState($selector) && array() !== $selectorConditions && ! $selectorIsStaticLayerRule && (array() !== $declarations || array() !== $cascadedValueDeclarations || array() !== $customPropertyReferences)) {
                        $analysis['conditional'][] = $readingCustomProperties(array(
                            'selector' => $selector,
                            'declarations' => $declarations,
                            'cascadedDeclarations' => $cascadedValueDeclarations,
                            'conditions' => $selectorConditions,
                            'layer' => $layer,
                        ));
                    }
                    if ($supportedRestingSelector) {
                        foreach ($imageEntries as $entry) {
                            $analysis['image_shape'][] = array(
                                'selector' => $selector,
                                'property' => $entry['property'],
                                'value' => $entry['value'],
                                'conditions' => $selectorConditions,
                                'order' => $imageOrder++,
                                'layer' => $layer,
                            );
                        }
                    }
                    if ($supportedRestingSelector && (array() === $selectorConditions || $selectorIsStaticLayerRule) && array() !== $cascadedValueDeclarations) {
                        $analysis['cascaded_values'][] = array('selector' => $selector, 'declarations' => $cascadedValueDeclarations);
                    }
                    // A `content`-only pseudo-element rule draws generated
                    // content while declaring no classified property, so it is
                    // collected before the empty-declaration guard below.
                    if (preg_match('/::?(before|after)\b/i', $selector, $pseudoMatch)) {
                        $baseSelector = trim((string) preg_replace('/::?(?:before|after)\b/i', '', $selector));
                        if ('' !== $baseSelector && ! $this->selectorCarriesPseudoState($baseSelector) && (array() !== $declarations || isset($rawDeclarations['content']))) {
                            $pseudoDeclarations = $declarations;
                            if (isset($rawDeclarations['content'])) {
                                $pseudoDeclarations['content'] = $rawDeclarations['content'];
                            }
                            $analysis['pseudo'][] = $readingCustomProperties(array('selector' => $baseSelector, 'pseudo' => strtolower($pseudoMatch[1]), 'declarations' => $pseudoDeclarations, 'conditions' => $selectorConditions));
                        }
                    }
                    if (array() === $declarations) {
                        continue;
                    }
                    if (array() === $selectorConditions && 1 === preg_match_all('/:(hover|focus-within|focus-visible|focus|active)\b/i', $selector, $stateMatches, PREG_OFFSET_CAPTURE)) {
                        $state = strtolower((string) $stateMatches[1][0][0]);
                        $offset = (int) $stateMatches[0][0][1];
                        $baseSelector = trim(substr_replace($selector, '', $offset, strlen((string) $stateMatches[0][0][0])));
                        if ('' !== $baseSelector && ! $this->selectorCarriesPseudoState($baseSelector) && $this->isSupportedCssSelector($baseSelector)) {
                            $analysis['navigation_state'][] = array('selector' => $selector, 'base_selector' => $baseSelector, 'state' => $state, 'declarations' => $declarations);
                            $analysis['reveal_state'][] = array('base_selector' => $baseSelector, 'state' => $state, 'state_subject_selector' => trim(substr($selector, 0, $offset)), 'declarations' => $rawDeclarations);
                        }
                    }
                }
            }
        );

        $analysis['layer_names'] = array_keys($layers);
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
            if (in_array($property, array('width', 'height', 'min-width', 'max-width', 'min-height', 'max-height', 'aspect-ratio', 'object-fit', 'object-position'), true) && '' !== $value) {
                $entries[] = array('property' => $property, 'value' => $value);
            }
        }

        return $entries;
    }

    /** Resolve image box and crop declarations at the desktop reference viewport. */
    public function imageShapeDeclarations(DOMElement $element): array
    {
        $facts = array();
        foreach ($this->context->sourceStyles()->imageShapeRules() as $rule) {
            if (!$this->matchesCssSelector($element, $rule['selector']) || !$this->conditionsApplyAtReferenceViewport($rule['conditions'])) continue;
            CssCascade::apply($facts, $rule['property'], array(
                'value' => $rule['value'],
                'important' => CssValueInspector::isImportant($rule['value']),
                'specificity' => $this->mediaTextSelectorSpecificity($rule['selector']), 'order' => $rule['order'], 'inline' => false, 'layer' => $rule['layer'] ?? null, 'conditions' => $rule['conditions'],
            ));
        }
        foreach ($this->imageShapeDeclarationEntries(SourceDom::attr($element, 'style')) as $order => $entry) {
            CssCascade::apply($facts, $entry['property'], array(
                'value' => $entry['value'], 'important' => CssValueInspector::isImportant($entry['value']), 'conditions' => array(),
                'specificity' => array(PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX), 'order' => PHP_INT_MAX - 1000 + $order, 'inline' => true, 'layer' => null,
            ));
        }
        return $facts;
    }

    /**
     * Whether one at-rule condition holds identically for every reader.
     *
     * `@layer` only orders the cascade, so a layered rule is always resting.
     * An `@supports` condition the allowlist knows to be true is resting too —
     * the browser takes that branch unconditionally. An unknown `@supports`
     * term, or any `@media` query, stays conditional.
     */
    private function conditionResolvesStatically(string $condition): bool
    {
        $condition = trim($condition);
        if (1 === preg_match('/^@layer\b/i', $condition)) {
            return true;
        }

        return 1 === preg_match('/^@supports\b/i', $condition)
            && CssCascade::supportsConditionApplies((string) preg_replace('/^@supports\s*/i', '', $condition));
    }

    /** @param list<string> $conditions */
    private function conditionsApplyAtReferenceViewport(array $conditions): bool
    {
        foreach ($conditions as $condition) {
            $condition = trim($condition);
            if (preg_match('/^@layer\b/i', $condition)) continue;
            if (preg_match('/^@supports\b/i', $condition)) {
                if (!CssCascade::supportsConditionApplies((string) preg_replace('/^@supports\s*/i', '', $condition))) return false;
            } elseif (preg_match('/^@media\b/i', $condition)) {
                if (!CssCascade::mediaConditionApplies((string) preg_replace('/^@media\s*/i', '', $condition), 1440.0)) return false;
            } else return false;
        }
        return true;
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
            'alignment-baseline',
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
            'baseline-shift',
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
            'border-start-start-radius',
            'border-start-end-radius',
            'border-end-start-radius',
            'border-end-end-radius',
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
            'align-self',
            'column-gap',
            'direction',
            'display',
            'dominant-baseline',
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
            'object-position',
            'order',
            'padding',
            'padding-bottom',
            'padding-left',
            'padding-right',
            'padding-top',
            'place-items',
            'pointer-events',
            'position',
            'top',
            'right',
            'bottom',
            'left',
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
     * The subset of a declaration map needed to resolve values outside the
     * classification allow-list: SVG paint/layout, animation identity, and
     * custom properties. Keeping this stream separate lets SVG materialization
     * and structural `var()` resolution share the real matched cascade without
     * changing general classification.
     *
     * @param array<string, string> $declarations
     * @return array<string, string>
     */
    private function cascadeRelevantDeclarations(array $declarations): array
    {
        static $cascadeProperties = array(
            'color' => true,
            'fill' => true,
            'fill-opacity' => true,
            'stroke' => true,
            'stroke-opacity' => true,
            'stroke-width' => true,
            'animation' => true,
            'animation-name' => true,
            // Grid-item placement: resolved for native core grid child
            // layout (Automattic/blocks-engine#2139).
            'grid-area' => true,
            'grid-column' => true,
            'grid-column-start' => true,
            'grid-column-end' => true,
            'grid-row' => true,
            'grid-row-start' => true,
            'grid-row-end' => true,
        );

        $filtered = array();
        foreach ( $declarations as $name => $value ) {
            if ( str_starts_with($name, '--') || isset($cascadeProperties[$name]) ) {
                $filtered[$name] = $value;
            }
        }

        return $filtered;
    }

    /**
     * @return array<string, string>
     */
    public function cssDeclarations(string $style): array
    {
        $declarations = array();
        foreach ( $this->verbatimCssDeclarations($style) as $name => $value ) {
            foreach ( $this->physicalBoxDeclarations($name, $value) as $boxName => $boxValue ) {
                // Importance precedes source order even within one declaration
                // list. Reducing to a property map must retain that winner.
                if (isset($declarations[$boxName]) && CssValueInspector::isImportant($declarations[$boxName]) && ! CssValueInspector::isImportant($boxValue)) {
                    continue;
                }
                // Keep the surviving declaration at its final authored position.
                // Border shorthands and longhands reset one another in source
                // order, so overwriting a prior key in place is not sufficient.
                unset($declarations[$boxName]);
                $declarations[$boxName] = $boxValue;
            }
        }

        return $declarations;
    }

    /**
     * Parse a declaration list without logical-to-physical box expansion.
     *
     * Author-stylesheet projection re-emits authored declaration text, so it
     * must keep the authored property names (`margin-inline`, …) byte-for-byte;
     * only cascade resolution and classification read the expanded physical
     * sides.
     *
     * @return array<string, string>
     */
    public function verbatimCssDeclarations(string $style): array
    {
        $declarations = array();
        foreach ( CssValueSplitter::splitTopLevel($style, array( ';' )) as $declaration ) {
            if ( ! str_contains($declaration, ':') ) {
                continue;
            }
            [$name, $value] = array_map('trim', explode(':', $declaration, 2));
            // Custom property names are case-sensitive: `--btnBg` and
            // `--btnbg` are distinct properties, and `var(--btnBg)` only
            // reads the first.
            $name = str_starts_with($name, '--') ? $name : strtolower($name);
            $value = preg_replace('/\s+/', ' ', $value) ?? $value;
            // A consumed custom property can supply the URL to an authored
            // background rule. Keep it for the same sanitized carrier path.
            $allowsImageUrl = (str_starts_with($name, '--') || in_array($name, array( 'background', 'background-image', 'list-style', 'list-style-image' ), true)) && ! preg_match('/(?:expression\s*\(|javascript\s*:)/i', $value);
            if ( '' !== $name && '' !== $value && ( $allowsImageUrl || ! preg_match('/(?:expression\s*\(|javascript\s*:|url\s*\()/i', $value) ) ) {
                // Importance precedes source order even within one declaration
                // list. Reducing to a property map must retain that winner.
                if (isset($declarations[$name]) && CssValueInspector::isImportant($declarations[$name]) && ! CssValueInspector::isImportant($value)) {
                    continue;
                }
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
     * Expand a logical box property into its physical side longhands.
     *
     * A builder's box utilities are increasingly authored as logical properties
     * (`padding-block`, `padding-inline`), while the cascade and every
     * downstream box consumer (control-surface classification, spacing
     * projection) read physical sides. Expansion assumes the desktop reference
     * viewport's horizontal-tb, left-to-right writing mode — the same fixed
     * assumption the rest of style resolution makes.
     *
     * @return array<string, string> Physical declarations keyed by property.
     */
    private function physicalBoxDeclarations(string $property, string $value): array
    {
        $axes = array(
            'padding-block' => array( 'padding-top', 'padding-bottom' ),
            'padding-inline' => array( 'padding-left', 'padding-right' ),
            'margin-block' => array( 'margin-top', 'margin-bottom' ),
            'margin-inline' => array( 'margin-left', 'margin-right' ),
        );
        if ( isset($axes[$property]) ) {
            $important = CssValueInspector::isImportant($value) ? ' !important' : '';
            $plain = trim(preg_replace('/\s*!\s*important\s*$/i', '', $value) ?? $value);
            $parts = CssValueSplitter::splitTopLevelWhitespace($plain);
            if ( count($parts) < 1 || count($parts) > 2 ) {
                return array();
            }

            return array(
                $axes[$property][0] => $parts[0] . $important,
                $axes[$property][1] => ( $parts[1] ?? $parts[0] ) . $important,
            );
        }

        $sides = array(
            'padding-block-start' => 'padding-top',
            'padding-block-end' => 'padding-bottom',
            'padding-inline-start' => 'padding-left',
            'padding-inline-end' => 'padding-right',
            'margin-block-start' => 'margin-top',
            'margin-block-end' => 'margin-bottom',
            'margin-inline-start' => 'margin-left',
            'margin-inline-end' => 'margin-right',
        );
        if ( isset($sides[$property]) ) {
            return array( $sides[$property] => $value );
        }

        return array( $property => $value );
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
    /**
     * Rules from a collection that actually match an element.
     *
     * styleRuleCandidates() returns an indexed *superset* — everything sharing a
     * tag, class, id or attribute with the element — which still has to be
     * confirmed against the real selector. Every caller remembered to do that,
     * each with its own copy of the guard, so the distinction between "might
     * match" and "does match" lived in eighteen places instead of a name.
     *
     * Generating rather than collecting keeps the superset from being
     * materialised twice on a hot path.
     *
     * @return iterable<array<string, mixed>>
     */
    private function matchingStyleRules(DOMElement $element, string $collection): iterable
    {
        foreach ( $this->styleRuleCandidates($element, $collection) as $rule ) {
            if ( $this->matchesCssSelector($element, (string) ( $rule['selector'] ?? '' )) ) {
                yield $rule;
            }
        }
    }

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
            'cascaded-values' => $this->context->sourceStyles()->cascadedValueRules(),
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
        $classes = array_filter($classes, fn (string $class): bool => '' !== $class
            && (! self::isBehaviorHookClassName($class) || $this->hasAuthorClassSelector($class))
            && ! self::isGeneratedCoreClassName($class)
            && ! self::isTransformerMarkerClassName($class));

        return implode(' ', array_values(array_unique($classes)));
    }

    private function hasAuthorClassSelector(string $className): bool
    {
        foreach ( $this->context->authorStyles()->styleRules() as $rule ) {
            foreach ( $rule['selectors'] as $selector ) {
                foreach ( $selector['parsed']['compounds'] ?? array() as $compound ) {
                    $parts = array_merge(array($compound));
                    foreach ( $compound['not'] ?? array() as $negated ) {
                        array_push($parts, ...($negated['compounds'] ?? array()));
                    }
                    foreach ( $parts as $part ) {
                        if ( in_array($className, $part['classes'] ?? array(), true) ) {
                            return true;
                        }
                    }
                }
                // Retain references in selectors outside the matcher's subset,
                // including generated content and functional pseudo-classes.
                if ( ! ($selector['parsed']['supported'] ?? false)
                    && preg_match('/' . CssIdent::classSelectorRegex($className) . '(?![a-zA-Z0-9_-])/', $selector['selector']) ) {
                    return true;
                }
            }
        }
        return false;
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
     * Expand `var(--token)` references against source custom properties, with
     * ancestor-declared properties layered over them when an element is given.
     *
     * Lives here rather than beside SVG materialization because it is CSS
     * custom-property resolution and already depends on this resolver's own
     * structural declarations.
     */
    /**
     * The element's resolved presentation declarations, as a property map.
     *
     * Reading presentation took a three-call incantation —
     * `cssDeclarations(resolveCssVariablesInValue(specificityResolvedPresentationStyle($el), $el))`
     * — written out at nine call sites across the converters, the pattern
     * contexts and the projectors.
     *
     * They did not agree: some passed the element when expanding `var()` and
     * some did not, which is the difference between resolving a custom property
     * in the element's own cascade and resolving it against the document's
     * global scope. Two readings of the same question, chosen per call site by
     * whoever wrote it. This is the element-scoped one, which is the reading
     * that can see a property an ancestor rebound.
     *
     * @return array<string, string>
     */
    public function resolvedPresentationDeclarations(DOMElement $element): array
    {
        return $this->cssDeclarations(
            $this->resolveCssVariablesInValue($this->specificityResolvedPresentationStyle($element), $element)
        );
    }

    public function resolveCssVariablesInValue(string $value, ?DOMElement $element = null): string
    {
        if ( false === strpos($value, 'var(') ) {
            return $value;
        }

        $customProperties = $element instanceof DOMElement
            ? $this->cascadedCustomProperties($element)
            : $this->context->sourceStyles()->customProperties();

        return $this->expandCssVariableReferences($value, $customProperties);
    }

    /**
     * @param array<string, string> $customProperties
     */
    private function expandCssVariableReferences(string $value, array $customProperties): string
    {
        for ( $pass = 0; $pass < 5; ++$pass ) {
            $expanded = preg_replace_callback('/var\(\s*(--[A-Za-z0-9_-]+)\s*(?:,\s*([^()]*))?\)/', static function (array $matches) use ($customProperties): string {
                $name = (string) $matches[1];
                $propertyValue = (string) ($customProperties[$name] ?? '');
                // A custom property authored as a bare CSS-wide keyword
                // (`--token:unset`) is a common "no override" sentinel: a
                // design-system token deliberately left unset so a consuming
                // `var(--token, <default>)` falls through to its own default,
                // exactly as if `--token` were never declared. Per spec these
                // keywords have no special meaning once substituted into
                // another property's value (the declaration would simply be
                // invalid), so honoring the sentinel intent here -- rather
                // than substituting the literal word "unset" -- is a closer
                // approximation of the cascade's real outcome than treating
                // it as a normal value.
                $isCssWideKeywordSentinel = in_array(strtolower(trim($propertyValue)), array( 'unset', 'initial', 'inherit', 'revert', 'revert-layer' ), true);
                if ( isset($customProperties[$name]) && '' !== $propertyValue && ! $isCssWideKeywordSentinel ) {
                    return $propertyValue;
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

    /**
     * Matched CSS and inline declarations from the generic cascaded-value
     * stream. Unlike {@see presentationDeclarations()}, this is not limited to
     * classification properties, so callers can resolve custom properties at
     * an element's actual cascade scope.
     *
     * @return array<string, string>
     */
    public function matchedCascadedDeclarations(DOMElement $element): array
    {
        $declarations = array();
        foreach ( $this->matchingStyleRules($element, 'cascaded-values') as $rule ) {
            $declarations = $this->mergeCssDeclarationMaps($declarations, $rule['declarations']);
        }

        return $this->mergeCssDeclarationMaps(
            $declarations,
            $this->cascadeRelevantDeclarations($this->cssDeclarations(SourceDom::attr($element, 'style')))
        );
    }

    /**
     * Custom properties visible to `$element` through the unfiltered cascade,
     * scoped by ancestry rather than limited to `:root`/`html`. An id- or
     * class-scoped `--token` an ancestor declares is a legitimate source.
     * This supports any property that needs an element-scoped `var()` value,
     * including SVG paint and structural layout declarations.
     *
     * @return array<string, string>
     */
    public function cascadedCustomProperties(DOMElement $element): array
    {
        $customProperties = $this->context->sourceStyles()->customProperties();
        $ancestors = array();
        for ( $current = $element; $current instanceof DOMElement; $current = $current->parentNode instanceof DOMElement ? $current->parentNode : null ) {
            $ancestors[] = $current;
        }
        foreach ( array_reverse($ancestors) as $ancestor ) {
            foreach ( $this->matchedCascadedDeclarations($ancestor) as $name => $propertyValue ) {
                if ( str_starts_with($name, '--') ) {
                    $customProperties[$name] = $propertyValue;
                }
            }
        }

        return $customProperties;
    }

    /**
     * The custom properties a declaration reads, resolved from the source
     * cascade of the element that declared it.
     *
     * A materializer that rebuilds a source subtree into a single element
     * carries the author declarations that matched it, but not the custom
     * properties those declarations read: the elements that declared them are
     * exactly the ones the rebuild collapses. Re-rooting the definitions on the
     * surviving element keeps a carried `var()` resolvable instead of leaving
     * it invalid at computed-value time. Nested references are left alone so a
     * definition that survives in the output still resolves at runtime.
     *
     * @return array<string, string>
     */
    public function carriedCustomProperties(string $value, DOMElement $element): array
    {
        if ( ! str_contains($value, 'var(') || ! preg_match_all('/var\(\s*(--[A-Za-z0-9_-]+)/', $value, $matches) ) {
            return array();
        }

        $customProperties = $this->cascadedCustomProperties($element);
        $carried = array();
        foreach ( array_unique($matches[1]) as $name ) {
            $declared = trim((string) ( $customProperties[ $name ] ?? '' ));
            if ( '' === $declared ) {
                continue;
            }
            $carried[ $name ] = $declared;
        }

        return $carried;
    }

    /** @return array<string, string> */
    private function conditionalCascadedCustomProperties(DOMElement $element): array
    {
        $customProperties = $this->cascadedCustomProperties($element);
        $ancestors = array();
        for ( $current = $element; $current instanceof DOMElement; $current = $current->parentNode instanceof DOMElement ? $current->parentNode : null ) {
            $ancestors[] = $current;
        }
        foreach ( array_reverse($ancestors) as $ancestor ) {
            foreach ( $this->matchingStyleRules($ancestor, 'conditional') as $rule ) {
                foreach ( $rule['cascadedDeclarations'] ?? array() as $name => $value ) {
                    if ( str_starts_with((string) $name, '--') ) {
                        $customProperties[(string) $name] = (string) $value;
                    }
                }
            }
        }

        return $customProperties;
    }

    /**
     * The element's own resolved value for one paint property — from matched
     * CSS or inline style directly on `$element`, with any `var()` reference
     * expanded against its ancestor-scoped custom properties. Returns null when
     * `$element` declares nothing for `$property` (the caller decides whether
     * to keep walking its ancestors, since that is an SVG-inheritance decision,
     * not a CSS-cascade-resolution one).
     */
    public function resolvedSvgCascadeValue(DOMElement $element, string $property): ?string
    {
        $declared = trim((string) ($this->matchedCascadedDeclarations($element)[$property] ?? ''));
        if ( '' === $declared ) {
            return null;
        }

        return false === strpos($declared, 'var(')
            ? $declared
            : $this->expandCssVariableReferences($declared, $this->cascadedCustomProperties($element));
    }

    public function specificityResolvedSvgCascadeValue(DOMElement $element, string $property): ?string
    {
        if ( ! in_array($property, array('alignment-baseline', 'baseline-shift', 'dominant-baseline'), true) ) {
            return null;
        }

        $declared = trim((string) ($this->cssDeclarations($this->specificityResolvedPresentationStyle($element))[$property] ?? ''));
        $declared = trim(preg_replace('/\s*!\s*important\s*$/i', '', $declared) ?? $declared);
        if ( '' === $declared ) {
            return null;
        }

        return $declared;
    }

    /**
     * Expand `var()` in an arbitrary structural declaration value (e.g.
     * `display`, `align-self`) against the unfiltered custom-property cascade
     * {@see cascadedCustomProperties()} tracks. This avoids duplicating the
     * element-scoped custom-property cascade for structural declarations.
     */
    public function resolveStructuralCssVariablesInValue(string $value, DOMElement $element): string
    {
        if ( false === strpos($value, 'var(') ) {
            return $value;
        }

        return $this->expandCssVariableReferences($value, $this->cascadedCustomProperties($element));
    }
}

<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\CssUrlRewriter;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\WordPress\GeneratedGutenbergClassPolicy;
use Closure;
use DOMElement;

/**
 * Inline geometry carrier classes and block layout attributes.
 *
 * Extracted from {@see StyleResolver} so this class has no StyleResolver `$this`.
 * CssCascade and CssValueInspector are used directly. Remaining StyleResolver
 * operations are explicit constructor closures — not a Context bag.
 */
final class InlineGeometry
{
    /**
     * @param Closure(string): array<string, string> $cssDeclarations
     * @param Closure(DOMElement, array<string, string>): array<string, string> $stripFrozenHiddenState
     * @param Closure(string): list<array{property: string, value: string, important: bool}> $mediaTextInlineDeclarationEntries
     * @param Closure(DOMElement, array<string, string>): bool $inlineDisplayOverridesAuthorLayout
     * @param Closure(DOMElement): bool $authorResolvedDisplayEstablishesFlexOrGrid
     * @param Closure(DOMElement, array<string, string>, array<string, string>, array<int, string>): array<string, string> $inlineAuthorOverrideDeclarations
     * @param Closure(DOMElement, array<string, string>, array<int, string>): array<string, string> $inlineInheritedTextAlignDeclaration
     * @param Closure(DOMElement, array<string, string>, array<int, string>): array<string, string> $inlineCustomPropertyDeclarations
     * @param Closure(DOMElement): string $geometryStructuralPath
     * @param Closure(DOMElement): array<string, string> $structuralPresentationDeclarations
     * @param Closure(DOMElement, string): bool $hasConditionalStyleFamily
     * @param Closure(string): string $responsivePropertyFamily
     */
    public function __construct(
        private readonly StyleResolutionContext $context,
        private readonly Closure $cssDeclarations,
        private readonly Closure $stripFrozenHiddenState,
        private readonly Closure $mediaTextInlineDeclarationEntries,
        private readonly Closure $inlineDisplayOverridesAuthorLayout,
        private readonly Closure $authorResolvedDisplayEstablishesFlexOrGrid,
        private readonly Closure $inlineAuthorOverrideDeclarations,
        private readonly Closure $inlineInheritedTextAlignDeclaration,
        private readonly Closure $inlineCustomPropertyDeclarations,
        private readonly Closure $geometryStructuralPath,
        private readonly Closure $structuralPresentationDeclarations,
        private readonly Closure $hasConditionalStyleFamily,
        private readonly Closure $responsivePropertyFamily
    ) {
    }

    /**
     * @return list<string>
     */
    public function layoutCarrierProperties(): array
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
     * @return list<string>
     */
    public function flexAlignmentCarrierProperties(): array
    {
        return array_values(array_diff($this->layoutCarrierProperties(), array( 'display' )));
    }

    /**
     * @return list<string>
     */
    public function listMarkerCarrierProperties(): array
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
    public function geometryProperties(): array
    {
        return array_merge($this->layoutCarrierProperties(), $this->listMarkerCarrierProperties(), array(
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
     * @return list<string>
     */
    public function positioningCarrierProperties(): array
    {
        return array(
            'float',
            'clear',
            'position',
            'top',
            'right',
            'bottom',
            'left',
            'inset',
            'overflow',
            'overflow-x',
            'overflow-y',
            'z-index',
        );
    }

    /**
     * Insets that only place a box when its used `position` is not `static`.
     *
     * @return list<string>
     */
    public function offsetCarrierProperties(): array
    {
        return array(
            'top',
            'right',
            'bottom',
            'left',
            'inset',
            'z-index',
        );
    }

    /**
     * @param array<string, string> $declarations
     * @return list<string>
     */
    public function positioningPropertiesFor(DOMElement $element, array $declarations): array
    {
        if ( $this->inlineDeclaresPositioning($element, $declarations) ) {
            return $this->positioningCarrierProperties();
        }

        if ( ! $this->inlineDeclaresOffsets($declarations) ) {
            return array();
        }

        return $this->authorResolvedPositionCarriesOffsets($element, $declarations) ? $this->offsetCarrierProperties() : array();
    }

    /**
     * Inline insets on an out-of-flow control land on the child block. A
     * synthesized core/buttons wrapper must not become their containing block.
     */
    public function hasChildOwnedPositionedOffsets(DOMElement $element): bool
    {
        $declarations = ($this->cssDeclarations)(SourceDom::attr($element, 'style'));
        $hasInset = false;
        foreach ( array( 'top', 'right', 'bottom', 'left', 'inset' ) as $property ) {
            if ( '' !== trim((string) ($declarations[ $property ] ?? '')) ) {
                $hasInset = true;
                break;
            }
        }
        if ( ! $hasInset ) {
            return false;
        }

        $position = CssValueInspector::comparable(
            (string) (($this->structuralPresentationDeclarations)($element)['position'] ?? '')
        );

        return in_array($position, array( 'absolute', 'fixed', 'sticky' ), true);
    }

    /**
     * @param array<string, string> $declarations
     */
    private function inlineDeclaresOffsets(array $declarations): bool
    {
        foreach ( $this->offsetCarrierProperties() as $property ) {
            if ( '' !== trim((string) ($declarations[ $property ] ?? '')) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $declarations
     */
    public function inlineDeclaresPositioning(DOMElement $element, array $declarations): bool
    {
        $float = CssValueInspector::comparable((string) ($declarations['float'] ?? ''));
        if ( '' !== $float && 'none' !== $float ) {
            return true;
        }

        $position = CssValueInspector::comparable((string) ($declarations['position'] ?? ''));
        if ( in_array($position, array( 'relative', 'sticky' ), true) ) {
            return true;
        }

        return 'absolute' === $position && $this->hasInlinePositionedAncestor($element);
    }

    /**
     * Class-owned `relative`/`absolute`/`sticky` keeps per-element inline
     * insets. Inline `position` stays on the existing inlineDeclaresPositioning
     * path so unanchored absolute and viewport-fixed layers are not pinned
     * through the carrier.
     *
     * @param array<string, string> $declarations
     */
    private function authorResolvedPositionCarriesOffsets(DOMElement $element, array $declarations): bool
    {
        $inlinePosition = CssValueInspector::comparable((string) ($declarations['position'] ?? ''));
        if ( in_array($inlinePosition, array( 'relative', 'absolute', 'fixed', 'sticky' ), true) ) {
            return false;
        }

        $position = CssValueInspector::comparable(
            (string) (($this->structuralPresentationDeclarations)($element)['position'] ?? '')
        );

        return in_array($position, array( 'relative', 'absolute', 'sticky' ), true);
    }

    /**
     * @return list<string>
     */
    public function namedFragmentTargetProperties(): array
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

    public function isNamedFragmentTarget(DOMElement $element): bool
    {
        if ( '' === trim(SourceDom::attr($element, 'id')) ) {
            return false;
        }
        if ( 0 < SourceDom::directElementChildCount($element) || '' !== trim((string) $element->textContent) ) {
            return false;
        }

        $position = strtolower(trim((string) (($this->cssDeclarations)(SourceDom::attr($element, 'style'))['position'] ?? '')));

        return in_array($position, array( 'absolute', 'fixed' ), true);
    }

    /**
     * @return list<string>
     */
    private function backgroundCarrierProperties(): array
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

    /**
     * Core supports cannot serialize arbitrary box dimensions. Keep only source
     * inline geometry in a generated stylesheet; class-owned declarations are
     * already retained by author stylesheet materialization.
     */
    public function className(
        DOMElement $element,
        array $excludedProperties = array(),
        array $forcedProperties = array(),
        array $forcedDeclarations = array(),
        bool $carrierOwnsInlineGeometry = false
    ): string {
        $declarations = $carrierOwnsInlineGeometry
            ? $this->mediaTextInlineCascadeDeclarations(SourceDom::attr($element, 'style'))
            : ($this->cssDeclarations)(SourceDom::attr($element, 'style'));
        $declarations = ($this->stripFrozenHiddenState)($element, $declarations);
        $geometry = array();
        $properties = $this->geometryProperties();
        if ( $this->isNamedFragmentTarget($element) ) {
            $properties = array_merge($properties, $this->namedFragmentTargetProperties());
        }
        $properties = array_merge($properties, $this->positioningPropertiesFor($element, $declarations));
        if ( ($this->inlineDisplayOverridesAuthorLayout)($element, $declarations) ) {
            $inlineDisplay = strtolower(trim((string) preg_replace('/\s*!\s*important\s*$/i', '', (string) ($declarations['display'] ?? ''))));
            if ( ! in_array($inlineDisplay, array( 'flex', 'inline-flex' ), true) ) {
                $properties = array_values(array_diff(
                    $properties,
                    array( 'flex-direction', 'flex-wrap', 'align-items', 'justify-content', 'gap' )
                ));
            }
        } else {
            $properties = array_values(array_diff($properties, $this->layoutCarrierProperties()));
            // The author stylesheet, not the inline style, establishes this
            // element's flex/grid formatting context, so its inline alignment
            // declarations are still the source's own and still need carrying.
            // A <div> reaches the same rescue through cssOwnedFlexAttributes();
            // a <p> or <ul> never can, because that path is gated on
            // ShellLandmarkPolicy::isFlowContainerTag(). Gate on the inline
            // intersection so no `gap` or `align-items` the author's media
            // queries own can be synthesized here.
            if ( ($this->authorResolvedDisplayEstablishesFlexOrGrid)($element) ) {
                $properties = array_merge($properties, array_values(array_intersect(
                    $this->flexAlignmentCarrierProperties(),
                    array_keys($declarations)
                )));
            }
        }
        if ('hidden' === CssValueInspector::comparable((string) ($declarations['visibility'] ?? ''))) {
            $properties[] = 'visibility';
        }
        $collapsedHeight = CssValueInspector::comparable((string) ($declarations['height'] ?? $declarations['max-height'] ?? ''));
        if (
            1 === preg_match('/^0(?:px|em|rem|%|vh|vw)?$/', $collapsedHeight)
            && in_array(CssValueInspector::comparable((string) ($declarations['overflow'] ?? '')), array('hidden', 'clip'), true)
        ) {
            $properties[] = 'overflow';
        }
        $inlineBackground = (string) ($declarations['background'] ?? $declarations['background-image'] ?? '');
        if ( preg_match('/\burl\s*\(/i', $inlineBackground)
            && ( 0 < SourceDom::directElementChildCount($element) || '' !== trim((string) $element->textContent) )
        ) {
            $properties = array_merge($properties, $this->backgroundCarrierProperties());
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
            if ('grid-template-columns' === $property) {
                $value = $this->containerSafeGridTemplateColumns($value);
            }
            if ('' !== $value && ! preg_match('~[{}<>;]|/\*~', $value)) {
                $geometry[$property] = $value;
            }
        }

        // Inline declarations that exist in order to OVERRIDE author CSS. The
        // "drop it and rely on the preserved className plus the carried author
        // CSS" premise inverts for these: dropping them does not fall back to
        // the same styling, it falls back to the OPPOSITE styling.
        $overrideDeclarations = ($this->inlineAuthorOverrideDeclarations)(
            $element,
            $declarations,
            $geometry,
            array_merge($excludedProperties, $forcedProperties)
        );
        foreach ($overrideDeclarations as $property => $value) {
            $geometry[$property] = $value;
        }

        if ( $this->isNormalFlowViewportWidthGeometry($element, $geometry)
            || $this->isCapturedViewportWidthBreakout($element, $geometry, $declarations)
        ) {
            // A WordPress flow container is commonly inset from the viewport.
            // Re-anchor a carried 100vw box to that viewport and clip oversized
            // cover descendants within the source's full-bleed carrier.
            $geometry['position'] = 'relative';
            $geometry['left'] = '50%';
            $geometry['margin-left'] = '-50vw';
            $geometry['margin-right'] = '-50vw';
            $geometry['overflow-x'] = 'clip';
        }

        // `text-align` rides an EXISTING container carrier and never mints one on
        // its own. A carrier class is what promotes an otherwise attribute-less
        // wrapper into a core/group, so minting one here would add block-tree
        // structure to every wrapper whose only inline declaration is an
        // alignment — a topology change, not a styling fix.
        if ( array() !== $geometry ) {
            foreach (($this->inlineInheritedTextAlignDeclaration)($element, $declarations, $excludedProperties) as $property => $value) {
                $geometry[$property] = $value;
                $overrideDeclarations[$property] = $value;
            }
        }

        // Core block supports drop arbitrary custom properties when parsing a
        // saved style attribute. Carry them in the generated stylesheet instead.
        foreach (($this->inlineCustomPropertyDeclarations)($element, $declarations, array_values($geometry)) as $property => $value) {
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
        $inlineLayoutPropertyLookup = array_fill_keys($this->layoutCarrierProperties(), true);
        $inlineListMarkerPropertyLookup = array_fill_keys($this->listMarkerCarrierProperties(), true);
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
        $className = $this->context->layoutGeometry()->allocateCarrier(($this->geometryStructuralPath)($element) . "\n" . $signature);
        $rules = array();
        if ( array() !== $normalPriorityDeclarations ) {
            $rules[] = ':root .' . $className . '{' . implode(';', $normalPriorityDeclarations) . '}';
        }
        if ( array() !== $importantDeclarations ) {
            $rules[] = '.' . $className . '{' . implode(';', $importantDeclarations) . '}';
        }
        $float = strtolower(CssValueInspector::comparable((string) ($geometry['float'] ?? '')));
        if ( in_array($float, array( 'left', 'right' ), true) ) {
            // WordPress flow groups are flex containers. Float is ignored on a
            // flex item, so the parent that owns the floated box has to be a
            // block formatting context for the source wrapping to survive.
            $rules[] = '.wp-block-group:has(> .' . $className . '){display:block !important}';
        }
        $this->context->layoutGeometry()->registerRule($className, implode("\n", $rules));

        return $className;
    }

    /**
     * @return array<string, string>
     */
    public function layoutAttribute(DOMElement $element, string $mergedStyle = ''): array
    {
        $declared = trim(SourceDom::attr($element, 'data-layout'));
        if ( '' === $declared ) {
            $declared = trim(SourceDom::attr($element, 'data-wp-layout'));
        }

        if ( '' !== $declared ) {
            $decoded = json_decode($declared, true);
            $type = is_array($decoded) ? (string) ($decoded['type'] ?? '') : $declared;
            if ( in_array($type, array( 'constrained', 'flex', 'flow', 'grid' ), true) ) {
                return array( 'type' => $type );
            }
        }

        $inlineStyle = strtolower(SourceDom::attr($element, 'style'));
        $mergedDeclarations = ($this->cssDeclarations)($mergedStyle);
        $inlineDeclarations = ($this->cssDeclarations)($inlineStyle);
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
        $style = strtolower('' !== trim($mergedStyle) ? $mergedStyle : SourceDom::attr($element, 'style'));
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
            if ('layout' === ($this->responsivePropertyFamily)($property)) {
                $inlineOwnsLayout = true;
                break;
            }
        }
        if (! $inlineOwnsLayout && ($this->hasConditionalStyleFamily)($element, 'layout')) {
            return array();
        }

        // An explicit grid class token (`grid`, `grid-3`, `footer-grid`,
        // `card-grid`, …) is a deterministic CSS-grid signal on its own. When the
        // container holds more than one element child, emit grid layout so the
        // multi-column arrangement survives even when the children are plain
        // wrappers rather than recognized card markup. Without this the grid
        // collapses to a vertical stack and loses visual parity.
        if ( $this->hasExplicitGridClass($element) && 1 < SourceDom::directElementChildCount($element) ) {
            return array( 'type' => 'grid' );
        }

        if ( $this->hasGridLikeClass($element) && 1 < $this->context->cardLikeChildCount($element) ) {
            return array( 'type' => 'grid' );
        }

        return array();
    }

    /**
     * @return array<string, string>
     */
    private function mediaTextInlineCascadeDeclarations(string $style): array
    {
        $cascade = array();
        $order = 0;
        foreach (($this->mediaTextInlineDeclarationEntries)($style) as $entry) {
            CssCascade::apply($cascade, $entry['property'], array(
                'value' => $entry['value'],
                'important' => $entry['important'],
                'inline' => true,
                'specificity' => array( 0, 0, 0 ),
                'order' => $order,
            ));
            ++$order;
        }

        $declarations = array();
        foreach ($cascade as $property => $entry) {
            $declarations[$property] = $entry['value'] . ($entry['important'] ? ' !important' : '');
        }

        return $declarations;
    }

    private function hasInlinePositionedAncestor(DOMElement $element): bool
    {
        for ( $parent = $element->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
            if ( in_array(strtolower($parent->tagName), array( 'body', 'html' ), true) ) {
                return false;
            }
            $position = CssValueInspector::comparable(
                (string) (($this->cssDeclarations)(SourceDom::attr($parent, 'style'))['position'] ?? '')
            );
            if ( in_array($position, array( 'relative', 'absolute', 'fixed', 'sticky' ), true) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * A viewport-wide box the source pulled back to the viewport edge itself.
     *
     * Sites that break a section out of an inset container commonly measure the
     * offset while rendering and write it onto the element, so the captured
     * document carries a pixel offset that is only true at the width it was
     * captured at. The same breakout expressed against the viewport resolves at
     * every width, so the measured offset is replaced rather than carried.
     *
     * @param array<string, string> $geometry
     * @param array<string, string> $declarations
     */
    private function isCapturedViewportWidthBreakout(DOMElement $element, array $geometry, array $declarations): bool
    {
        if ( '100vw' !== strtolower(trim((string) preg_replace('/\s+/', '', (string) ($geometry['width'] ?? '')))) ) {
            return false;
        }
        $offset = strtolower(trim((string) preg_replace(
            '/\s*!\s*important\s*$/i',
            '',
            (string) ($declarations['left'] ?? '')
        )));
        if ( 1 !== preg_match('/^-\s*(?:\d+|\d*\.\d+)(?:px|rem|em|%)$/', $offset) ) {
            return false;
        }
        $position = strtolower(trim((string) preg_replace(
            '/\s*!\s*important\s*$/i',
            '',
            (string) (($this->structuralPresentationDeclarations)($element)['position'] ?? 'static')
        )));

        return in_array($position, array( '', 'static', 'relative' ), true);
    }

    private function isNormalFlowViewportWidthGeometry(DOMElement $element, array $geometry): bool
    {
        $width = strtolower(trim((string) preg_replace('/\s+/', '', (string) ($geometry['width'] ?? ''))));
        if ( '100vw' !== $width ) {
            return false;
        }

        $position = strtolower(trim((string) preg_replace(
            '/\s*!\s*important\s*$/i',
            '',
            (string) (($this->structuralPresentationDeclarations)($element)['position'] ?? 'static')
        )));

        return '' === $position || 'static' === $position;
    }

    private function hasOwnStyleHook(DOMElement $element): bool
    {
        return '' !== trim(SourceDom::attr($element, 'class')) || '' !== trim(SourceDom::attr($element, 'id'));
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
     * A carried `grid-template-columns` value is a measurement, not authored
     * responsive CSS: it rides in on an element's inline `style` attribute
     * (see CSS_OWNED_GRID_CARRIER_PROPERTIES in HtmlCompilation), most often a
     * single viewport's resolved pixel width. Carried unconditionally, a fixed
     * track never shrinks with its container and a grid child can end up wider
     * than the container that holds it (Automattic/blocks-engine#1895,
     * Automattic/blocks-engine#1898).
     *
     * Each bare absolute-length track (`px`, `rem`, `em`, `ch`, `ex`, `cm`,
     * `mm`, `in`, `pt`, `pc`, `q`, or a viewport unit) is wrapped in
     * `min(<track>, 100%)` — the same idiom core already uses to keep a fixed
     * `minimumColumnWidth` container-safe (see autoRepeatMinimumColumnWidth()
     * below: `repeat(auto-fill, minmax(min(<width>, 100%), 1fr))`). The
     * desktop measurement is preserved up to the container's available width,
     * so nothing changes until the container is actually narrower than the
     * carried track, at which point the track — and so the grid child —
     * clamps to the container instead of overflowing it.
     *
     * Tracks that are already container- or content-relative (`fr`, `%`,
     * `auto`, `min-content`, `max-content`, `minmax(...)`, `repeat(...)`,
     * `fit-content(...)`, `calc(...)`, `var(...)`, named line groups) are left
     * untouched: they already adapt, and wrapping a `%` track in `min(x, 100%)`
     * would be a no-op at best and change intent at worst.
     */
    private function containerSafeGridTemplateColumns(string $value): string
    {
        $trimmed = trim($value);
        if (in_array(strtolower($trimmed), array( '', 'none', 'masonry', 'subgrid' ), true)) {
            return $value;
        }

        $tracks = CssValueSplitter::splitTopLevelWhitespace($trimmed);
        if (array() === $tracks) {
            return $value;
        }

        $safeTracks = array_map(static function (string $track): string {
            if (1 === preg_match(
                '/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:px|rem|em|ch|ex|cm|mm|in|pt|pc|q|vw|vh|vmin|vmax)$/i',
                $track
            )) {
                return 'min(' . $track . ', 100%)';
            }

            return $track;
        }, $tracks);

        return implode(' ', $safeTracks);
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
     * token ending `*-grid` / `*_grid` (footer-grid, card-grid, mission-grid, …)
     * plus the common `grid-cols` / `grid-columns` utility names. Matched as whole
     * class tokens, so mid-token fragments (`border-grid-line`, `grid-pattern`,
     * `text-grid-500`) are not signals. These map directly to `display:grid`
     * containers, so they are safe to treat as grids regardless of child
     * semantics. Ambiguous semantic names (cards, features, …) stay gated on
     * card-like children via hasGridLikeClass().
     */
    private function hasExplicitGridClass(DOMElement $element): bool
    {
        foreach ( $this->authorClassTokens($element) as $token ) {
            if ( $this->isExplicitGridClassToken($token) ) {
                return true;
            }
        }

        return false;
    }

    private function hasGridLikeClass(DOMElement $element): bool
    {
        foreach ( $this->authorClassTokens($element) as $token ) {
            if ( $this->isGridLikeClassToken($token) ) {
                return true;
            }
        }

        return false;
    }

    private function isExplicitGridClassToken(string $token): bool
    {
        $token = $this->classTokenBase($token);
        if ( 'grid' === $token || 'grid-cols' === $token || 'grid-columns' === $token ) {
            return true;
        }
        if ( 1 === preg_match('/^grid-(?:[0-9]+|cols-[0-9]+)$/', $token) ) {
            return true;
        }

        return str_ends_with($token, '-grid') || str_ends_with($token, '_grid');
    }

    private function isGridLikeClassToken(string $token): bool
    {
        $token = $this->classTokenBase($token);

        return in_array($token, array( 'cards', 'features', 'services', 'providers', 'testimonials', 'resources', 'posts', 'projects', 'stats', 'badges', 'grid', 'tiles', 'collection', 'gallery' ), true)
            || 1 === preg_match('/^grid-[0-9]+$/', $token);
    }

    private function classTokenBase(string $token): string
    {
        $slash = strpos($token, '/');

        return false === $slash ? $token : substr($token, 0, $slash);
    }

    /**
     * Class tokens with generated markers filtered out, so transformer-emitted
     * classes (blocks-engine-css-owned-grid, …) re-ingested from prior output
     * never trip the author grid-class heuristics.
     *
     * @return array<int, string>
     */
    private function authorClassTokens(DOMElement $element): array
    {
        $tokens = preg_split('/\s+/', strtolower(trim(SourceDom::attr($element, 'class')))) ?: array();

        return array_values(array_filter($tokens, static fn (string $token): bool => '' !== $token && ! GeneratedGutenbergClassPolicy::isGeneratedClassName($token) && ! str_starts_with($token, 'blocks-engine-') && ! str_starts_with($token, 'be-inline-geometry-')));
    }
}

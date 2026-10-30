<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredButtonBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\NavigationOpenerPresentation;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\NavigationToggleSuppressor;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Support\RuntimeSelectorVocabulary;
use Closure;
use DOMElement;

/** Promotes a menu toggle onto its projected navigation, or suppresses the control. */
final class ProjectedNavigationConverter implements ElementConverter
{
    /**
     * @param Closure(DOMElement, array<int, array<string, mixed>>&, array<int, class-string>): ?array<string, mixed> $recognizePatterns
     */
    public function __construct(
        private readonly NavigationToggleSuppressor $navigationToggleSuppressor,
        private readonly StyleResolver $styleResolver,
        private readonly HtmlTransformerSession $session,
        private readonly Closure $recognizePatterns,
        private readonly SourceBlockCreator $createBlock,
        private readonly ?Closure $isRuntimeDomTarget = null,
        private readonly ?Closure $svgMarkup = null
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
    {
        $runtimeButton = ('button' === $tagName || AuthoredButtonBlockGenerator::isRoleButton($element))
            && $this->isRuntimeDomTarget instanceof Closure
            && ($this->isRuntimeDomTarget)($element)
            && $this->retainsRuntimeButtonBinding($element);
        $projectedNavigation = $runtimeButton ? $this->navigationToggleSuppressor->projectedNavigationTargetForControl($element) : null;
        if ( $runtimeButton && ! $projectedNavigation instanceof DOMElement ) {
            // The runtime binding keeps ordinary wired controls interactive.
            // A hamburger whose menu the emitted core/navigation already owns
            // is not one: Core renders its own open button in the control's
            // slot and the hidden panel the control opened is superseded, so
            // the kept button would be a second, inert menu toggle beside
            // the navigation.
            if ( ! $this->navigationToggleSuppressor->isMenuToggleSupersededByNativeNavigationOpener($element) ) {
                return ConversionOutcome::unhandled();
            }
            $this->supersedeRuntimeSelectorsForControl($element);
            return ConversionOutcome::handled(null);
        }

        if ($runtimeButton && $projectedNavigation instanceof DOMElement) {
            $this->supersedeRuntimeSelectorsForControl($element);
        }
        $projectedNavigation ??= $this->navigationToggleSuppressor->projectedNavigationTargetForControl($element);
        if ( $projectedNavigation instanceof DOMElement ) {
            $block = ($this->recognizePatterns)($projectedNavigation, $fallbacks, array(NavigationPattern::class));
            if ( null !== $block ) {
                $nativeClassNames = 'blocks-engine-list-navigation blocks-engine-native-responsive-navigation';
                if ( $this->navigationToggleSuppressor->isImplicitDialogNavigationControl($element) ) {
                    $nativeClassNames .= ' blocks-engine-projected-dialog-navigation';
                }
                // The control's own presentation class (its visibility utility,
                // box sizing, hover states, …) describes the *toggle*, not the
                // navigation it opens — Core's overlayMenu is the responsive
                // affordance now, so that presentation is carried onto it
                // through the generated toggle marker below and the generic
                // author-selector projection, never by unioning the control's
                // literal class onto the nav host. Doing that here previously
                // put a hamburger's own visibility/size utilities (and any
                // author rule keyed to its class) directly on the block that
                // replaces it, which is self-contradictory whenever the
                // control and the nav it projects state opposite responsive
                // visibility (e.g. a `md:hidden` toggle projecting a
                // `hidden md:flex` nav).
                $block['attrs']['className'] = SourceDom::mergeClassNames(
                    $nativeClassNames,
                    (string) ($block['attrs']['className'] ?? ''),
                    $this->responsiveNavigationToggleMarker($projectedNavigation),
                    $this->responsiveNavigationOverlayMarker($projectedNavigation)
                );
                $block['attrs']['overlayMenu'] = $this->navigationToggleSuppressor->projectedOverlayMenu($element);
                $block['attrs'] = array_replace_recursive($block['attrs'], $this->responsiveNavigationOverlayAttributes($projectedNavigation));
                $block['attrs'] = $this->withSourceOpener($block['attrs'], $projectedNavigation);
                $this->session->authorSelectorProjectionState()->projectNavigationListIntoOverlay($projectedNavigation);
                // The emitted navigation occupies the opener's layout slot.
                // Its hidden-state provenance must belong to that control,
                // rather than to the source panel it now opens natively.
                // An inferred, unbound glyph retains the existing duplicate
                // reconciliation against its visible in-flow menu instead.
                if ( ! $element->hasAttribute('aria-controls') ) {
                    return ConversionOutcome::handled($block);
                }
                $replacement = $this->createBlock->createBlock(
                    'core/navigation',
                    $block['attrs'],
                    $block['innerBlocks'] ?? array(),
                    $element,
                    $projectedNavigation
                );
                // Keep the control's hidden-state provenance, but not its CSS
                // identity. Attribute selectors and transforms describe the
                // superseded button, never an ancestor of Core's fixed sheet.
                $replacement['attrs']['className'] = $block['attrs']['className'];
                return ConversionOutcome::handled($replacement);
            }
        }

        if ( $this->navigationToggleSuppressor->isProjectedNavigationSuppressed($element) || $this->navigationToggleSuppressor->isRedundantMenuToggleControl($element) ) {
            return ConversionOutcome::handled(null);
        }

        return ConversionOutcome::unhandled();
    }

    private function retainsRuntimeButtonBinding(DOMElement $element): bool
    {
        $attributes = AuthoredButtonBlockGenerator::sourceSafeAttributes($element);
        if ( array() === $attributes ) {
            return false;
        }
        if ( ! $this->navigationToggleSuppressor->isRedundantMenuToggleControl($element) ) {
            return true;
        }

        foreach ( array_keys($attributes) as $name ) {
            if ( str_starts_with($name, 'data-') ) {
                return true;
            }
        }

        return false;
    }

    private function supersedeRuntimeSelectorsForControl(DOMElement $control): void
    {
        $selectors = $this->session->runtimeSelectorState();
        $classes = SourceDom::classNames($control);
        foreach (array_keys($selectors->domSelectors()) as $selector) {
            $matches = str_starts_with($selector, '#')
                ? substr($selector, 1) === SourceDom::attr($control, 'id')
                : (str_starts_with($selector, '.')
                    ? in_array(substr($selector, 1), $classes, true)
                    : RuntimeSelectorVocabulary::matchesElement($control, $selector, array('button')));
            if ($matches) $selectors->supersede($selector);
        }
    }

    public function responsiveNavigationToggleMarker(DOMElement $navigation): string
    {
        $toggle = $this->navigationToggleSuppressor->navigationToggleControl($navigation);
        if ( ! $toggle instanceof DOMElement ) {
            return '';
        }

        // A select supplies the opener's box, but not its form-field paint.
        $isJumpMenu = 'select' === strtolower($toggle->tagName);
        $sourceDeclarations = $this->styleResolver->resolvedPresentationDeclarations($toggle);
        $presentation = new NavigationOpenerPresentation($this->styleResolver, $this->svgMarkup);
        $source = $isJumpMenu ? null : $presentation->source($toggle);
        $declarations = array();
        $hasUsableWidth = false;
        $hasUsableHeight = false;
        $geometryProperties = array(
            'box-sizing',
            'width',
            'height',
            'min-width',
            'min-height',
            'padding',
            'padding-top',
            'padding-right',
            'padding-bottom',
            'padding-left',
        );
        // Button presentation already owns its margins; a select only supplies geometry.
        if ( $isJumpMenu ) {
            $geometryProperties = array_merge($geometryProperties, array('margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left'));
            $sourceDeclarations = array_intersect_key(
                array_replace($sourceDeclarations, $this->styleResolver->collapsedViewportAuthorDeclarations($toggle, $geometryProperties)),
                array_flip($geometryProperties)
            );
        }
        foreach ( array_merge($geometryProperties, array(
            'border-top-left-radius',
            'border-top-right-radius',
            'border-bottom-right-radius',
            'border-bottom-left-radius',
            'background-color',
            'color',
        )) as $property ) {
            $value = trim((string) ($sourceDeclarations[$property] ?? ''));
            if ( '' !== $value && ! preg_match('/[{}<>;]/', $value) ) {
                $comparable = CssValueInspector::withoutImportant($value);
                if ( in_array($property, array( 'width', 'min-width', 'height', 'min-height' ), true) && in_array($comparable, array( 'auto', 'fit-content', 'max-content', 'min-content' ), true) ) {
                    continue;
                }
                $hasUsableWidth = $hasUsableWidth || ( in_array($property, array( 'width', 'min-width' ), true) && $this->nativeNavigationToggleDimensionIsUsable($comparable) );
                $hasUsableHeight = $hasUsableHeight || ( in_array($property, array( 'height', 'min-height' ), true) && $this->nativeNavigationToggleDimensionIsUsable($comparable) );
                $declarations[] = $property . ':' . $comparable . '!important';
            }
        }
        if ( ! $hasUsableWidth && null === $source ) {
            $declarations[] = 'min-width:44px!important';
        }
        if ( ! $hasUsableHeight && null === $source ) {
            $declarations[] = 'min-height:44px!important';
        }
        if ( array() === $declarations && null === $source ) {
            return '';
        }

        $always = 'always' === $this->navigationToggleSuppressor->projectedOverlayMenu($toggle);
        $display = strtolower(CssValueInspector::withoutImportant(trim((string) ($sourceDeclarations['display'] ?? ''))));
        $extra = '';
        $openDeclarations = $declarations;
        // A toggle the source places absolutely at the collapsed viewport
        // hands that placement to Core's open button, which renders in its
        // stead; the host then has to stop being the button's containing
        // block (see nativeNavigationTogglePlacement()).
        $placement = $always ? array() : $this->nativeNavigationTogglePlacement($toggle, $navigation);
        // Follow the source collapse boundary rather than Core's 600px switch;
        // these boundaries also participate in the generated marker hash.
        $collapseBoundary = $always ? '' : $this->sourceCollapseBoundary($navigation, $toggle);
        $absentAbove = $always ? '' : $this->sourceAbsentAboveBoundary($navigation, $toggle);
        $bindsPanel = ! $this->navigationToggleSuppressor->isHashAnchorMenuProjection($toggle)
            && ( $always || ( '' !== $absentAbove && ! $this->navigationToggleSuppressor->projectedNavigationTargetForControl($toggle) instanceof DOMElement ) )
            && $this->hasNavigationHeader($navigation);
        // Making the host static also cancels its own relative offsets. Carry
        // those on the leaf, in the same rule as any source-toggle placement.
        $openPlacement = $placement;
        if ( $bindsPanel || array() !== $placement ) {
            $hostPlacement = $this->portableCollapsedPlacement($navigation, array_merge(array('position'), self::TOGGLE_OFFSET_PROPERTIES));
            if ( 'relative' === strtolower($hostPlacement['position'] ?? '') && $this->hasPlacementOffset($hostPlacement) ) {
                $openPlacement = array_replace($hostPlacement, $placement);
            }
        }
        foreach ( $openPlacement as $property => $value ) {
            $openDeclarations[] = $property . ':' . $value . '!important';
        }
        if ( $always ) {
            if ( 'table-cell' === $display && ! $hasUsableHeight ) {
                $openDeclarations[] = 'min-height:60px!important';
            }
            foreach ( array( 'border', 'border-right', 'font-family', 'font-size', 'font-weight', 'letter-spacing', 'text-align' ) as $property ) {
                $value = trim((string) ($sourceDeclarations[$property] ?? ''));
                if ( '' !== $value && ! preg_match('/[{}<>;]/', $value) ) {
                    $openDeclarations[] = $property . ':' . CssValueInspector::withoutImportant($value) . '!important';
                }
            }
            $openDeclarations[] = 'display:flex!important';
            $openDeclarations[] = 'align-items:center!important';
            $openDeclarations[] = 'justify-content:center!important';
            $pseudo = $this->nativeNavigationToggleGeneratedContent($toggle);
            if ( array() !== $pseudo ) {
                $afterParts = array();
                foreach ( $pseudo as $property => $value ) {
                    $afterParts[] = $property . ':' . $value . '!important';
                }
                $extra .= 'SVG_HIDE';
                $extra .= 'AFTER:' . implode(';', $afterParts);
            }
        }

        // A projected menu replaces the toggle as a grid/flex item, not only
        // as a DOM occurrence. Carry its authored row slot on the host; putting
        // this on Core's leaf opener would place it inside the nav instead.
        $slotDeclarations = array();
        if ( $this->navigationToggleSuppressor->projectedNavigationTargetForControl($toggle) instanceof DOMElement ) {
            foreach ( $this->styleResolver->collapsedViewportAuthorDeclarations($toggle, array('grid-area', 'grid-column', 'grid-row', 'grid-column-start', 'grid-column-end', 'grid-row-start', 'grid-row-end', 'order', 'align-self', 'justify-self')) as $property => $value ) {
                $value = $this->styleResolver->resolveCssVariablesInValue(CssValueInspector::withoutImportant(trim($value)), $toggle);
                if ( '' !== $value && ! preg_match('/[{}<>;]|var\(/i', $value) ) {
                    $slotDeclarations[] = $property . ':' . $value . '!important';
                }
            }
        }
        $marker = 'blocks-engine-native-navigation-toggle-' . substr(hash('sha256', implode(';', $openDeclarations) . implode(';', $slotDeclarations) . $extra . (null === $source ? '' : serialize($source)) . ( '' === $collapseBoundary ? '' : ';collapse:' . $collapseBoundary ) . ( '' === $absentAbove ? '' : ';absent:' . $absentAbove )), 0, 12);
        $host = '.wp-block-navigation.blocks-engine-list-navigation.blocks-engine-native-responsive-navigation.' . $marker;
        $hostRule = $host . '{' . implode(';', array_merge($this->nativeNavigationToggleHostDeclarations($always, $display, $sourceDeclarations, array() !== $placement), $slotDeclarations)) . '}';
        $openRule = $host . '>.wp-block-navigation__responsive-container-open{' . implode(';', $openDeclarations) . '}';
        if ( array() !== $placement ) {
            $openRule .= $this->nativeNavigationTogglePlacementEditorReset($host, $placement);
        }
        $extraRules = '';
        if (null !== $source) {
            $open = $host . '>.wp-block-navigation__responsive-container-open';
            // Native defaults supply a different box; intrinsic source SVG
            // sizing remains on the child, with margin/transform on the leaf
            // button so Core's overlay does not gain a transformed ancestor.
            $extraRules .= $open . '{min-width:0!important;min-height:0!important;padding:0!important;border:0!important;width:auto!important;height:auto!important}';
            $extraRules .= $presentation->css($open, $source['button'], $toggle);
            $extraRules .= $presentation->css($open . '>svg', $source['icon'], $toggle->getElementsByTagName('svg')->item(0));
        }
        if ( str_contains($extra, 'SVG_HIDE') ) {
            $extraRules .= $host . '>.wp-block-navigation__responsive-container-open svg{display:none!important}';
        }
        if ( str_contains($extra, 'AFTER:') ) {
            $afterBody = substr($extra, strpos($extra, 'AFTER:') + 6);
            $extraRules .= $host . '>.wp-block-navigation__responsive-container-open::after{' . $afterBody . '}';
        }
        if ( $always ) {
            // The generic list repair exposes closed containers above Core's
            // 600px switch. This occurrence owns an always-overlay control, so
            // its closed panel stays out of layout at every branch width.
            $extraRules .= $host . ' .wp-block-navigation__responsive-container:not(.is-menu-open){display:none!important}';
            if ( $this->navigationToggleSuppressor->isHashAnchorMenuProjection($toggle) ) {
                $extraRules .= $this->nativeNavigationToggleDropdownCss($host, $navigation);
                $extraRules .= $this->nativeNavigationToggleOpenControlCss($host);
            } elseif ( $bindsPanel ) {
                $extraRules .= $this->nativeNavigationBoundPanelCss($host, $navigation);
            }
        }
        // A superseded burger uses Core's viewport overlay, not the source
        // dropdown's header-bound geometry. Other mobile-only drawers retain
        // their existing panel projection within the collapsed range.
        $drawerRules = ! $always && $bindsPanel ? $this->nativeNavigationBoundPanelCss($host, $navigation) : '';
        if ( $always ) {
            $rule = $hostRule . $openRule . $extraRules;
        } elseif ( '' === $collapseBoundary ) {
            $rule = '@media(max-width:' . ( self::CORE_OVERLAY_BREAKPOINT - 1 ) . 'px){' . $hostRule . $openRule . $drawerRules . '}';
        } else {
            $rule = '@media(max-width:' . $collapseBoundary . '){' . $hostRule . $openRule . $drawerRules . '}'
                . $this->sourceBoundaryOverlayCss($host, $collapseBoundary);
        }
        if ( '' !== $absentAbove ) {
            $rule .= '@media(min-width:' . $absentAbove . '){' . $host . '{display:none!important}}';
        }
        $this->session->generatedSupportStylesheetState()->registerNativeNavigationToggle($marker, $rule);
        return $marker;
    }

    /** @param array<string,mixed> $attrs @return array<string,mixed> */
    public function withSourceOpener(array $attrs, DOMElement $navigation): array
    {
        $toggle = $this->navigationToggleSuppressor->navigationToggleControl($navigation);
        if (!$toggle instanceof DOMElement) return $attrs;
        $source = (new NavigationOpenerPresentation($this->styleResolver, $this->svgMarkup))->source($toggle);
        if (null !== $source) $attrs['metadata'][NavigationOpenerPresentation::METADATA_KEY] = array('svg' => $source['artwork']);
        return $attrs;
    }

    /**
     * Core supports for the source's mobile panel. Only portable paint and
     * horizontal packing belong here; Core owns the overlay's vertical stack.
     *
     * @return array<string, mixed>
     */
    public function responsiveNavigationOverlayAttributes(DOMElement $navigation): array
    {
        $attrs = array();
        foreach ( $this->collapsedPanelChain($navigation) as $panel ) {
            $values = $this->styleResolver->collapsedViewportDeclarations($panel, array('background', 'background-color', 'color', 'text-align', 'align-items', 'flex-direction'));
            $background = '';
            foreach ( $values as $property => $value ) {
                if ( in_array($property, array('background', 'background-color'), true) ) {
                    if ( '' === $background || CssValueInspector::isImportant($value) || ! CssValueInspector::isImportant($background) ) $background = $value;
                } elseif ( 'color' === $property ) {
                    $paint = $this->portableCollapsedPaint($panel, $value);
                    if ( '' !== $paint ) $attrs['customOverlayTextColor'] = $paint;
                } else {
                    if ( 'flex-direction' === $property || ('align-items' === $property && ! str_starts_with(CssValueInspector::withoutImportant($values['flex-direction'] ?? ''), 'column')) ) continue;
                    $value = CssValueInspector::withoutImportant($value);
                    $justify = match (trim($value)) {
                        'end', 'right', 'flex-end' => 'right',
                        'center' => 'center',
                        'start', 'left', 'flex-start' => 'left',
                        default => '',
                    };
                    if ( '' !== $justify ) $attrs['layout'] = array('type' => 'flex', 'justifyContent' => $justify);
                }
            }
            $paint = $this->portableCollapsedPaint($panel, $background);
            if ( '' !== $paint ) $attrs['customOverlayBackgroundColor'] = $paint;
        }

        return $attrs;
    }

    /** Carry panel paint and content-box repairs onto Core's default open overlay. */
    public function responsiveNavigationOverlayMarker(DOMElement $navigation): string
    {
        $attrs = $this->responsiveNavigationOverlayAttributes($navigation);
        $background = $attrs['customOverlayBackgroundColor'] ?? '';
        $color = $attrs['customOverlayTextColor'] ?? '';
        $listPadding = array();
        $chain = $this->collapsedPanelChain($navigation);
        foreach ($this->styleResolver->collapsedViewportDeclarations($chain[0], array('padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left')) as $property => $value) {
            $value = $this->styleResolver->resolveCssVariablesInValue(CssValueInspector::withoutImportant($value), $chain[0]);
            if ('' !== trim($value) && !preg_match('/[{}<>;]|var\(|url\(/i', $value)) {
                $listPadding[] = $property . ':' . $value . '!important';
            }
        }
        $alignmentMarker = $this->responsiveNavigationAlignmentMarker($navigation);
        if ( '' === $background && '' === $color && array() === $listPadding ) {
            return $alignmentMarker;
        }

        $declarations = array();
        if ( '' !== $background ) {
            $declarations[] = 'background:' . $background . '!important';
        }
        if ( '' !== $color ) {
            $declarations[] = 'color:' . $color . '!important';
        }
        $marker = 'blocks-engine-navigation-overlay-' . substr(hash('sha256', implode(';', $declarations) . "\0" . implode(';', $listPadding)), 0, 12);
        // A custom overlay template part styles itself; Core marks that
        // container `disable-default-overlay`, and this must not reach it.
        $open = '.wp-block-navigation.blocks-engine-native-responsive-navigation.' . $marker
            . ' .wp-block-navigation__responsive-container.is-menu-open:not(.disable-default-overlay)';
        $rule = array() === $declarations ? '' : $open . '{' . implode(';', $declarations) . '}';
        if (array() !== $listPadding) {
            // The generic list reset removes its padding; in the open panel,
            // this list owns the source content box again, including room for
            // scaled icons and nested item wrappers.
            $rule .= $open . ' .wp-block-navigation__container{' . implode(';', $listPadding) . '}';
            if ( ! $chain[0]->isSameNode($navigation) ) {
                // The discarded panel gutter replaces Core's inline sheet
                // gutter, rather than adding a second inset around the list.
                $rule .= $open . '{padding-inline:0!important}';
            }
        }
        $this->session->generatedSupportStylesheetState()->registerNativeNavigationOverlay($marker, $rule);

        return trim($marker . ' ' . $alignmentMarker);
    }

    private function responsiveNavigationAlignmentMarker(DOMElement $navigation): string
    {
        $alignment = $this->responsiveNavigationOverlayAttributes($navigation)['layout']['justifyContent'] ?? '';
        if ( '' === $alignment ) return '';
        $marker = 'blocks-engine-navigation-alignment-' . $alignment;
        $open = '.wp-block-navigation.blocks-engine-native-responsive-navigation.' . $marker
            . ' .wp-block-navigation__responsive-container.is-menu-open:not(.disable-default-overlay)';
        // Core owns the vertical stack. Only restate its cross-axis and
        // remove desktop submenu indentation inside the default overlay.
        $align = match ($alignment) { 'right' => 'flex-end', 'center' => 'center', default => 'flex-start' };
        $rule = $open . ' .wp-block-navigation__container,' . $open . ' .wp-block-navigation__submenu-container{align-items:' . $align . '!important;text-align:' . $alignment . '!important}'
            . $open . ' .wp-block-navigation__submenu-container{padding-inline:0!important;margin-inline:0!important}'
            . $open . ' .wp-block-navigation-item,' . $open . ' .wp-block-navigation-item__content{text-align:' . $alignment . '!important}'
            // Retained desktop item/link classes and generated presentation
            // carriers can set align-self on the LI or center a nested flex
            // wrapper. Let the overlay's cross-axis alignment flow through the
            // entire item chain, including childless top-level links.
            . $open . ' .wp-block-navigation-item,' . $open . ' .wp-block-navigation-item *{align-self:auto!important;align-items:inherit!important}';
        $this->session->generatedSupportStylesheetState()->registerNativeNavigationOverlay($marker, $rule);

        return $marker;
    }

    /**
     * The collapsed out-of-flow parent (if any), navigation itself and the
     * single-wrapper chain beneath it down to its list, outer first.
     * Siblings such as the toggle control or a brand anchor do not break the
     * chain; two candidate wrappers at one level make the panel ambiguous.
     *
     * @return list<DOMElement>
     */
    private function collapsedPanelChain(DOMElement $navigation): array
    {
        $chain = array( $navigation );
        // A collapsed out-of-flow wrapper is discarded when navigation moves
        // into the burger slot. Its gutter and alignment belong to the overlay.
        $parent = $navigation->parentNode;
        if ( $parent instanceof DOMElement ) {
            $values = $this->styleResolver->collapsedViewportDeclarations($parent, array('position', 'visibility', 'max-height'));
            if ( in_array(CssValueInspector::withoutImportant($values['position'] ?? ''), array('absolute', 'fixed'), true)
                && ('hidden' === CssValueInspector::withoutImportant($values['visibility'] ?? '') || in_array(CssValueInspector::withoutImportant($values['max-height'] ?? ''), array('0', '0px'), true)) ) {
                array_unshift($chain, $parent);
            }
        }
        $node = $navigation;
        for ( $depth = 0; $depth < 4 && ! in_array(strtolower($node->tagName), array( 'ul', 'ol' ), true); ++$depth ) {
            $wrapper = null;
            foreach ( $node->childNodes as $child ) {
                if ( ! $child instanceof DOMElement || ! in_array(strtolower($child->tagName), array( 'div', 'ul', 'ol' ), true) ) {
                    continue;
                }
                if ( null !== $wrapper ) {
                    return $chain;
                }
                $wrapper = $child;
            }
            if ( ! $wrapper instanceof DOMElement ) {
                break;
            }
            $chain[] = $wrapper;
            $node = $wrapper;
        }

        return $chain;
    }

    /** A collapsed-state colour value that can be restated away from the source stylesheet, or ''. */
    private function portableCollapsedPaint(DOMElement $panel, string $value): string
    {
        $value = $this->styleResolver->resolveCssVariablesInValue(CssValueInspector::withoutImportant($value), $panel);
        if ( '' === $value
            || preg_match('/[{}<>;]|var\(|url\(/i', $value)
            || in_array(strtolower($value), array( 'none', 'inherit', 'initial', 'unset', 'revert', 'revert-layer', 'currentcolor' ), true)
            || CssValueInspector::isTransparentColor($value)
        ) {
            return '';
        }

        return $value;
    }

    /** The viewport width (px) at and below which core/navigation shows its native overlay control. */
    private const CORE_OVERLAY_BREAKPOINT = 600;

    /**
     * The source's collapse boundary as a CSS length (`1300px`), or '' when
     * it is unknown or not wider than core's own 600px switch.
     *
     * At or below core's breakpoint the native behavior already matches the
     * source, so nothing is restated and the output is unchanged.
     */
    private function sourceCollapseBoundary(DOMElement $navigation, DOMElement $toggle): string
    {
        $boundary = $this->navigationToggleSuppressor->sourceCollapseBreakpoint($navigation, $toggle);
        if ( null === $boundary || $boundary <= self::CORE_OVERLAY_BREAKPOINT ) {
            return '';
        }

        return rtrim(rtrim(number_format($boundary, 3, '.', ''), '0'), '.') . 'px';
    }

    /**
     * The smallest width (`768px`) from which the source shows neither this
     * menu nor its toggle, or '' when it shows one of them at the reference
     * viewport or states no boundary.
     *
     * A mobile-only drawer (`md:hidden` toggle opening a panel that is closed
     * at rest) has no presence on wide screens, where a separate desktop menu
     * takes over. Core would lay the drawer's links out inline there, so the
     * host is hidden above the source boundary instead.
     */
    private function sourceAbsentAboveBoundary(DOMElement $navigation, DOMElement $toggle): string
    {
        if ( ! $this->navigationToggleSuppressor->isHiddenAtDefaultViewport($toggle)
            || ! $this->navigationToggleSuppressor->isHiddenAtDefaultViewport($navigation) ) {
            return '';
        }
        $boundary = $this->navigationToggleSuppressor->sourceCollapseBreakpoint($navigation, $toggle);
        if ( null === $boundary ) {
            return '';
        }

        return rtrim(rtrim(number_format($boundary + 1.0, 3, '.', ''), '0'), '.') . 'px';
    }

    /**
     * Between core's 600px switch and the source boundary, restate the state
     * core renders below 600px on this host: keep the host visible (the
     * author's `display:none` for the collapsed menu now lands on the block
     * that holds core's open button), show the open button core hides from
     * 600px up, and hide the closed menu container that core and the generic
     * list-navigation repair would otherwise lay out inline. The open overlay
     * (`is-menu-open`) is left to core, which styles it the same at every
     * width, and above the source boundary the generic rules apply as before.
     *
     * The closed-container rule carries one class more than the generic
     * `display:contents` repair it overrides, so it wins on specificity
     * whatever order the layers are emitted in.
     */
    private function sourceBoundaryOverlayCss(string $host, string $collapseBoundary): string
    {
        return '@media(min-width:' . self::CORE_OVERLAY_BREAKPOINT . 'px) and (max-width:' . $collapseBoundary . '){'
            . $host . '{display:flex!important}'
            . $host . '>.wp-block-navigation__responsive-container-open{display:flex!important}'
            . $host . ' .wp-block-navigation__responsive-container:not(.is-menu-open){display:none!important}'
            . '}';
    }

    private function hasNavigationHeader(DOMElement $navigation): bool
    {
        for ( $node = $navigation->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( 'header' === strtolower($node->tagName) ) {
                return true;
            }
        }
        return false;
    }

    private function nativeNavigationBoundPanelCss(string $host, DOMElement $navigation): string
    {
        if ( ! $this->hasNavigationHeader($navigation) ) {
            return '';
        }
        $open = $host . ' .wp-block-navigation__responsive-container.is-menu-open';
        // The source header contains the disclosure panel. Core's modal adds
        // a viewport-sized sheet and a second padding box around that panel;
        // retain the header edge and let the projected list own its geometry.
        return $host . '{position:static!important}'
            . $open . '{position:absolute!important;inset:100% 0 auto!important;width:100%!important;height:auto!important;min-height:0!important;padding:0!important}'
            . $open . ' .wp-block-navigation__responsive-container-content{padding:0!important;align-items:stretch!important}'
            . $open . ' .wp-block-navigation__container{width:100%!important}'
            . $open . ' .wp-block-navigation__responsive-container-close{top:0!important}'
            . 'html.has-modal-open:has(' . $open . '){overflow:visible!important}';
    }

    private function nativeNavigationToggleDropdownCss(string $host, DOMElement $navigation): string
    {
        $panel = $navigation->parentNode instanceof DOMElement ? $navigation->parentNode : $navigation;
        $declarations = $this->styleResolver->resolvedPresentationDeclarations($panel);
        $background = '#fff';
        $maxHeight = CssValueInspector::withoutImportant(trim((string) ($declarations['max-height'] ?? '')));
        if ( '' === $maxHeight || 'none' === strtolower($maxHeight) || '0' === $maxHeight || '0px' === $maxHeight ) {
            $maxHeight = '200px';
        }
        $topOffset = $this->nativeNavigationToggleHeaderOffset($navigation);
        $open = $host . ' .wp-block-navigation__responsive-container.is-menu-open';
        return $open . '{box-sizing:border-box!important;position:fixed!important;inset:auto!important;top:' . $topOffset . '!important;left:0!important;right:0!important;width:100%!important;height:auto!important;min-height:60px!important;max-height:' . $maxHeight . '!important;background:' . $background . '!important;display:flex!important;justify-content:flex-start!important;align-items:center!important;overflow:visible!important;z-index:6!important;padding:0 20px!important;box-shadow:0 5px 10px 0 rgba(0,0,0,0.2)!important}'
            . 'body.admin-bar ' . $open . '{top:calc(' . $topOffset . ' + var(--wp-admin--admin-bar--height,32px))!important}'
            . $open . ' .wp-block-navigation__responsive-container-content{flex-direction:row!important;align-items:center!important;justify-content:flex-start!important;width:100%!important;height:60px!important;margin:0!important;padding:0!important}'
            // The source menu bar lays its items out inline, so the gap between
            // two labels is the collapsed whitespace text node the source had
            // between list items, advanced in the list's own font. Core's
            // navigation save() emits no whitespace between items, so a flex
            // bar loses that gap entirely. Keep the bar an inline formatting
            // context and restore the separator as generated content, which
            // lets the browser measure it in the same inherited font instead
            // of pinning a guessed pixel gap.
            // The bar's own line box has to span the bar, or vertical-align
            // centers each item against a short strut parked at the top.
            . $open . ' .wp-block-navigation__container{display:block!important;white-space:nowrap!important;width:auto!important;height:60px!important;line-height:60px!important;margin:0!important;padding:0!important;list-style:none!important}'
            . $this->nativeNavigationToggleItemCss($open, $navigation)
            . $open . ' .wp-block-navigation-item span::after{content:none!important}'
            . $open . ' .wp-block-navigation__responsive-container-close{display:flex!important;position:fixed!important;top:calc(0px - ' . $topOffset . ')!important;left:0!important;width:100px!important;height:60px!important;opacity:0!important;z-index:9!important;padding:0!important;margin:0!important;border:0!important;background:transparent!important;cursor:pointer!important}'
            . $open . ' .wp-block-navigation__responsive-container-close svg{display:none!important}'
            . 'html.has-modal-open:has(' . $open . '){overflow:visible!important}'
            . 'body:has(' . $open . '){overflow:visible!important}';
    }

    /**
     * Properties that place a positioned toggle: its positioning scheme, the
     * offsets it is placed by (physical and logical, shorthand and longhand),
     * the transform that typically centres it, its margins and its stacking.
     * Listed longhand after shorthand so a caller emitting them in the order
     * the cascade last declared them reproduces the author's result.
     */
    private const TOGGLE_PLACEMENT_PROPERTIES = array(
        'position',
        'inset',
        'inset-block',
        'inset-inline',
        'top',
        'right',
        'bottom',
        'left',
        'inset-block-start',
        'inset-block-end',
        'inset-inline-start',
        'inset-inline-end',
        'transform',
        'translate',
        'rotate',
        'scale',
        'transform-origin',
        'margin',
        'margin-block',
        'margin-inline',
        'margin-top',
        'margin-right',
        'margin-bottom',
        'margin-left',
        'margin-block-start',
        'margin-block-end',
        'margin-inline-start',
        'margin-inline-end',
        'z-index',
    );

    /** The offset properties among {@see self::TOGGLE_PLACEMENT_PROPERTIES}; an absolute toggle with none set keeps its static position, which the host cannot reproduce. */
    private const TOGGLE_OFFSET_PROPERTIES = array(
        'inset',
        'inset-block',
        'inset-inline',
        'top',
        'right',
        'bottom',
        'left',
        'inset-block-start',
        'inset-block-end',
        'inset-inline-start',
        'inset-inline-end',
    );

    /**
     * Properties that make an element the containing block of a positioned
     * (or fixed) descendant: `position` other than static, and the
     * transform-like properties that establish one for fixed boxes too.
     */
    private const CONTAINING_BLOCK_PROPERTIES = array(
        'position',
        'transform',
        'translate',
        'rotate',
        'scale',
        'perspective',
        'filter',
        'backdrop-filter',
        'will-change',
        'contain',
    );

    /**
     * The source toggle's placement at the collapsed viewport, to restate on
     * Core's open button, or an empty array when nothing should be carried.
     *
     * Core's open button renders inside the navigation host, which the host
     * rule pins `position:relative` and sizes to the button, so it sits in
     * the header's flow at the navigation's slot. A source toggle the author
     * placed absolutely — `.nav button{position:absolute;right:0;top:50%;
     * transform:translateY(-50%)}` under the breakpoint that shows it — is
     * pinned to the header's edge instead; its author rule no longer reaches
     * anything once the toggle is dropped, so the button lost the placement.
     *
     * The placement is read from the author cascade at the mobile reference
     * viewport, the state in which the toggle shows, and moved onto the open
     * button itself: a transform on the host would make it the containing
     * block of Core's fixed overlay, while on the button, a leaf, it moves
     * nothing else. The host turns `position:static` (see
     * {@see nativeNavigationToggleHostDeclarations()}) so the button's
     * offsets resolve against the same ancestor the source toggle's did.
     * That only holds when the toggle and the navigation share their nearest
     * containing-block ancestor; a toggle placed inside its own positioned
     * wrapper, or inside a positioned navigation, is left alone, as is a
     * toggle in normal flow or one hidden at that viewport. The values are
     * resolved at one viewport, so a toggle the source moves between its
     * breakpoints is placed where the narrowest one puts it.
     *
     * @return array<string, string>
     */
    private function nativeNavigationTogglePlacement(DOMElement $toggle, DOMElement $navigation): array
    {
        // The author analysis, not the resting cascade: transforms are kept
        // out of the source-style collections, and the toggle's transform is
        // the part that centres it.
        $portable = $this->portableCollapsedPlacement($toggle, array_merge(self::TOGGLE_PLACEMENT_PROPERTIES, array( 'display' )));
        if ( 'none' === strtolower((string) ( $portable['display'] ?? '' )) ) {
            return array();
        }
        unset($portable['display']);
        if ( ! in_array(strtolower((string) ( $portable['position'] ?? '' )), array( 'absolute', 'fixed' ), true) ) {
            return array();
        }
        if ( ! $this->hasPlacementOffset($portable) ) {
            return array();
        }
        $toggleContainingBlock = $this->collapsedContainingBlock($toggle);
        $navigationContainingBlock = $this->collapsedContainingBlock($navigation);
        if ( $toggleContainingBlock !== $navigationContainingBlock
            && ! ( $toggleContainingBlock instanceof DOMElement && $navigationContainingBlock instanceof DOMElement && $toggleContainingBlock->isSameNode($navigationContainingBlock) ) ) {
            return array();
        }

        return $portable;
    }

    /** @param list<string> $properties @return array<string, string> */
    private function portableCollapsedPlacement(DOMElement $element, array $properties): array
    {
        $portable = array();
        foreach ( $this->styleResolver->collapsedViewportAuthorDeclarations($element, $properties) as $property => $value ) {
            $value = $this->styleResolver->resolveCssVariablesInValue(CssValueInspector::withoutImportant(trim($value)), $element);
            if ( '' !== $value && ! preg_match('/[{}<>;]|var\(/i', $value) ) {
                $portable[$property] = $value;
            }
        }
        return $portable;
    }

    /** @param array<string, string> $placement */
    private function hasPlacementOffset(array $placement): bool
    {
        foreach ( self::TOGGLE_OFFSET_PROPERTIES as $property ) {
            $value = strtolower($placement[$property] ?? '');
            if ( '' !== $value && 'auto' !== $value ) {
                return true;
            }
        }
        return false;
    }

    /**
     * The nearest proper ancestor that, at the collapsed viewport, is the
     * containing block of a positioned descendant — positioned itself or
     * transformed — or null when only the initial containing block is.
     */
    private function collapsedContainingBlock(DOMElement $element): ?DOMElement
    {
        for ( $node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( in_array(strtolower($node->tagName), array( 'body', 'html' ), true) ) {
                break;
            }
            $declared = $this->styleResolver->collapsedViewportAuthorDeclarations($node, self::CONTAINING_BLOCK_PROPERTIES);
            foreach ( $declared as $property => $value ) {
                if ( $this->establishesContainingBlock($property, strtolower(CssValueInspector::withoutImportant(trim($value)))) ) {
                    return $node;
                }
            }
        }

        return null;
    }

    /**
     * Undo the carried placement inside the block editor canvas.
     *
     * The editor gives every block wrapper `position:relative` for its own
     * chrome, so the group wrappers between the host and the source's
     * containing block become the open button's containing block there and
     * the placement lands against a zero-size box. The canvas keeps the
     * in-flow placement it had before the placement was carried; the front
     * end, where the wrappers are static as in the source, keeps the fix.
     *
     * @param array<string, string> $placement
     */
    private function nativeNavigationTogglePlacementEditorReset(string $host, array $placement): string
    {
        $initial = array(
            'position' => 'static',
            'transform' => 'none',
            'translate' => 'none',
            'rotate' => 'none',
            'scale' => 'none',
            'transform-origin' => 'initial',
            'z-index' => 'auto',
        );
        $reset = array();
        foreach ( array_keys($placement) as $property ) {
            if ( in_array($property, self::TOGGLE_OFFSET_PROPERTIES, true) ) {
                $reset['inset'] = 'inset:auto!important';
            } elseif ( str_starts_with($property, 'margin' ) ) {
                $reset['margin'] = 'margin:0!important';
            } else {
                $reset[$property] = $property . ':' . ( $initial[$property] ?? 'initial' ) . '!important';
            }
        }

        return ':root .editor-styles-wrapper ' . $host . '>.wp-block-navigation__responsive-container-open{' . implode(';', $reset) . '}';
    }

    /** Whether one declared value of a {@see self::CONTAINING_BLOCK_PROPERTIES} property makes its element a containing block. */
    private function establishesContainingBlock(string $property, string $value): bool
    {
        if ( '' === $value || in_array($value, array( 'initial', 'unset', 'revert', 'revert-layer' ), true) ) {
            return false;
        }
        switch ( $property ) {
            case 'position':
                return 'static' !== $value;
            case 'will-change':
                return 1 === preg_match('/\b(transform|perspective|filter|translate|rotate|scale)\b/', $value);
            case 'contain':
                return 1 === preg_match('/\b(layout|paint|strict|content)\b/', $value);
            default:
                return 'none' !== $value;
        }
    }

    /**
     * @param array<string, string> $sourceDeclarations
     * @return array<int, string>
     */
    private function nativeNavigationToggleHostDeclarations(bool $always, string $display, array $sourceDeclarations, bool $placesOpenButton = false): array
    {
        $host = array(
            'box-sizing:border-box!important',
            'padding:0!important',
            // When the open button carries the source toggle's absolute
            // placement, the host must not be its containing block: the
            // offsets have to resolve against the ancestor the toggle's did.
            $placesOpenButton ? 'position:static!important' : 'position:relative!important',
            'overflow:visible!important',
        );
        if ( $always && 'table-cell' === $display ) {
            $host[] = 'display:table-cell!important';
            $host[] = 'vertical-align:middle!important';
            $width = CssValueInspector::withoutImportant(trim((string) ($sourceDeclarations['width'] ?? '')));
            if ( $this->nativeNavigationToggleDimensionIsUsable($width) ) {
                $host[] = 'width:' . $width . '!important';
            }

            return $host;
        }

        // The leaf opener owns the burger's visual box, not this host.
        $host[] = 'min-width:0!important';
        $host[] = 'min-height:0!important';
        if ( ! $always ) {
            // The source menu's collapsed-panel rules land on this host, which
            // at these widths is the toggle-sized box around Core's open
            // button. Its padding is already stripped above; its border would
            // frame the button (or, once the button is placed out of flow,
            // draw an empty bordered dot), and the source toggle had neither.
            $host[] = 'border:0!important';
        }

        return $host;
    }

    private function nativeNavigationToggleOpenControlCss(string $host): string
    {
        $open = $host . '>.wp-block-navigation__responsive-container-open';
        $whenOpen = $host . ':has(.wp-block-navigation__responsive-container.is-menu-open)>.wp-block-navigation__responsive-container-open';
        return $whenOpen . '{background:#fff!important}'
            . $whenOpen . '::after{color:#444!important}'
            . $open . ':hover{background:#fff!important}'
            . $open . ':hover::after{color:#444!important}';
    }

    private function nativeNavigationToggleItemCss(string $open, DOMElement $navigation): string
    {
        $anchor = null;
        foreach ( $navigation->getElementsByTagName('a') as $candidate ) {
            if ( $candidate instanceof DOMElement && '' !== trim($candidate->textContent ?? '') ) {
                $href = trim(SourceDom::attr($candidate, 'href'));
                if ( '' !== $href && ! str_starts_with($href, '#') ) {
                    $anchor = $candidate;
                    break;
                }
            }
        }
        $item = array(
            'font-size' => '14px',
            'font-weight' => '700',
            'text-transform' => 'uppercase',
            'letter-spacing' => '0.04em',
            'color' => '#444',
            'padding' => '0 10px',
            'line-height' => 'normal',
        );
        if ( $anchor instanceof DOMElement ) {
            $declarations = $this->styleResolver->resolvedPresentationDeclarations($anchor);
            foreach ( array( 'font-size', 'font-weight', 'text-transform', 'letter-spacing', 'color', 'line-height' ) as $property ) {
                $value = CssValueInspector::withoutImportant(trim((string) ($declarations[$property] ?? '')));
                if ( '' !== $value && ! preg_match('/[{}<>]/', $value) ) {
                    $item[$property] = $value;
                }
            }
            $parent = $anchor->parentNode instanceof DOMElement ? $anchor->parentNode : null;
            if ( $parent instanceof DOMElement ) {
                $parentResolved = $this->styleResolver->resolveCssVariablesInValue(
                    $this->styleResolver->specificityResolvedPresentationStyle($parent)
                );
                $padding = CssValueInspector::withoutImportant(trim((string) ($this->styleResolver->cssDeclarations($parentResolved)['padding'] ?? '')));
                if ( $this->nativeNavigationTogglePaddingIsUsable($padding) ) {
                    $item['padding'] = $padding;
                }
            }
        }
        $content = array();
        $padding = $item['padding'];
        unset($item['padding']);
        foreach ( $item as $property => $value ) {
            $content[] = $property . ':' . $value . '!important';
        }

        $color = $item['color'] ?? '#444';
        $withoutColor = array();
        foreach ( $content as $declaration ) {
            if ( ! str_starts_with($declaration, 'color:') ) {
                $withoutColor[] = $declaration;
            }
        }

        return $open . ' .wp-block-navigation-item,'
            . $open . ' .wp-block-navigation-item.wp-block-navigation-link{display:inline-block!important;vertical-align:middle!important;height:auto!important;line-height:normal!important;padding:' . $padding . '!important;margin:0!important;list-style:none!important;box-sizing:border-box!important}'
            . $open . ' .wp-block-navigation-item::after{content:" "!important;white-space:pre!important;display:inline!important}'
            . $open . ' .wp-block-navigation-item:last-child::after{content:none!important}'
            . $open . ' .wp-block-navigation-item__content{display:inline!important;white-space:nowrap!important;padding:0!important;' . implode(';', $withoutColor) . '}'
            . $open . ' .wp-block-navigation-item:not(.blocks-engine-current-navigation-item):not(.current-menu-item) .wp-block-navigation-item__content{color:' . $color . '!important}';
    }

    private function nativeNavigationToggleHeaderOffset(DOMElement $navigation): string
    {
        $toggle = $this->navigationToggleSuppressor->navigationToggleControl($navigation);
        if ( ! $toggle instanceof DOMElement ) {
            return '60px';
        }
        $bar = $this->absolutelyPinnedHeaderBar($toggle);
        $target = $bar instanceof DOMElement ? $bar : $toggle;
        $resolved = $this->styleResolver->resolveCssVariablesInValue(
            $this->styleResolver->specificityResolvedPresentationStyle($target)
        );
        $height = CssValueInspector::withoutImportant(trim((string) ($this->styleResolver->cssDeclarations($resolved)['height'] ?? '')));
        if ( 1 === preg_match('/^\d+(?:\.\d+)?px$/', $height) ) {
            return $height;
        }
        if ( $bar instanceof DOMElement && $bar->parentNode instanceof DOMElement ) {
            $parentResolved = $this->styleResolver->resolveCssVariablesInValue(
                $this->styleResolver->specificityResolvedPresentationStyle($bar->parentNode)
            );
            $parentHeight = CssValueInspector::withoutImportant(trim((string) ($this->styleResolver->cssDeclarations($parentResolved)['height'] ?? '')));
            if ( 1 === preg_match('/^\d+(?:\.\d+)?px$/', $parentHeight) ) {
                return $parentHeight;
            }
        }

        return '60px';
    }

    private function absolutelyPinnedHeaderBar(DOMElement $toggle): ?DOMElement
    {
        $node = $toggle->parentNode;
        while ( $node instanceof DOMElement ) {
            $declarations = $this->styleResolver->resolvedPresentationDeclarations($node);
            if ( array() === $declarations ) {
                $declarations = $this->styleResolver->structuralPresentationDeclarations($node);
            }
            $position = strtolower(CssValueInspector::withoutImportant(trim((string) ($declarations['position'] ?? ''))));
            $top = strtolower(CssValueInspector::withoutImportant(trim((string) ($declarations['top'] ?? ''))));
            if ( in_array($position, array( 'absolute', 'fixed' ), true) && in_array($top, array( '', 'auto', '0', '0px' ), true) ) {
                return $node;
            }
            $node = $node->parentNode;
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function nativeNavigationToggleGeneratedContent(DOMElement $toggle): array
    {
        $targets = array($toggle);
        foreach ( $toggle->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                $targets[] = $child;
            }
        }
        foreach ( $this->session->sourceStyleResolutionState()->pseudoElementRules() as $rule ) {
            if ( 'after' !== ($rule['pseudo'] ?? '') && ':after' !== ($rule['pseudo'] ?? '') ) {
                continue;
            }
            $selector = (string) ($rule['selector'] ?? '');
            $matched = false;
            foreach ( $targets as $target ) {
                if ( $this->styleResolver->matchesCssSelector($target, $selector) ) {
                    $matched = true;
                    break;
                }
            }
            if ( ! $matched ) {
                foreach ( preg_split('/\s+/', trim(SourceDom::attr($toggle, 'class'))) ?: array() as $className ) {
                    if ( '' !== $className && 1 === preg_match('/\.' . preg_quote($className, '/') . '(?:$|[.\s\[:#>+~])/', $selector) ) {
                        $matched = true;
                        break;
                    }
                }
            }
            if ( ! $matched ) {
                continue;
            }
            $picked = array();
            foreach ( array( 'content', 'color', 'font-family', 'font-size', 'font-weight', 'letter-spacing', 'line-height', 'display', 'text-align' ) as $property ) {
                $value = trim((string) (($rule['declarations'][$property] ?? '')));
                if ( '' === $value || preg_match('/[{}<>]/', $value) ) {
                    continue;
                }
                $picked[$property] = CssValueInspector::withoutImportant($value);
            }
            if ( isset($picked['content']) && ! in_array(strtolower($picked['content']), array( 'none', 'normal', '""', "''" ), true) ) {
                $picked['content'] = $this->nativeNavigationToggleContentValue($picked['content']);
                return $picked;
            }
        }

        return array();
    }

    private function nativeNavigationToggleContentValue(string $value): string
    {
        $trimmed = trim($value);
        $unquoted = ltrim(trim($trimmed, "\"'"), '\\');
        if ( 1 === preg_match('/^[A-Za-z][A-Za-z0-9 -]*$/', $unquoted) ) {
            return '"' . $unquoted . '"';
        }

        return $trimmed;
    }

    private function nativeNavigationTogglePaddingIsUsable(string $value): bool
    {
        if ( '' === $value || preg_match('/[{}<>]/', $value) ) {
            return false;
        }

        return 1 !== preg_match('/^0(?:px|em|rem)?(?:\s+0(?:px|em|rem)?){0,3}$/', strtolower(trim($value)));
    }

    private function nativeNavigationToggleDimensionIsUsable(string $value): bool
    {
        if ( 1 !== preg_match('/^(\d+(?:\.\d+)?)px$/', $value, $matches) ) {
            return false;
        }

        return (float) $matches[1] >= 24;
    }
}

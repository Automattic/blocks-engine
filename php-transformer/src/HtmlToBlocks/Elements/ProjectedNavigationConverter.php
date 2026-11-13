<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\Css\CssColor;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
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
    public function __construct(
        private readonly NavigationToggleSuppressor $navigationToggleSuppressor,
        private readonly StyleResolver $styleResolver,
        private readonly HtmlTransformerSession $session,
        private readonly PatternTreeConverter $tree,
        private readonly SourceBlockCreator $createBlock,
        private readonly RuntimeDomTargets $runtimeDomTargets,
        private readonly Closure $svgMarkup
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        $runtimeButton = ('button' === $tagName || AuthoredButtonBlockGenerator::isRoleButton($element))
            && $this->runtimeDomTargets->isRuntimeDomTarget($element)
            && $this->retainsRuntimeButtonBinding($element);
        $projectedNavigation = $runtimeButton ? $this->navigationToggleSuppressor->projectedNavigationTargetForControl($element) : null;
        if ( $runtimeButton && ! $projectedNavigation instanceof DOMElement ) {
            // A capture close helper for a panel Core's overlay replaced
            // closes nothing; the overlay brings its own close button.
            if ( $this->navigationToggleSuppressor->isProjectedNavigationSuppressed($element) ) {
                $this->supersedeRuntimeSelectorsForControl($element);
                return ConversionOutcome::handled(null);
            }
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
            if ( $this->navigationToggleSuppressor->isMirrorControl($element) ) {
                return $this->convertMirrorControl($element, $projectedNavigation, $fallbacks);
            }
            $block = $this->tree->patterns($projectedNavigation, $fallbacks, array(NavigationPattern::class));
            // A landmark carrier is the panel's own box around its menu. The
            // control's slot takes only that menu; Core's overlay is the panel.
            if ( null !== $block && $this->navigationToggleSuppressor->isNavigationCarrier($projectedNavigation)
                && 'core/group' === ($block['blockName'] ?? '')
                && 1 === count($block['innerBlocks'] ?? array())
                && 'core/navigation' === ($block['innerBlocks'][0]['blockName'] ?? '') ) {
                $block = $block['innerBlocks'][0];
            }
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

    /**
     * Put a navigation in the slot of a toggle whose row swaps with the row
     * that holds the links. Core's overlay is always enabled on this occurrence;
     * source visibility and the collapse boundary decide when its slot shows.
     * The inline occurrence keeps the links in their original row.
     *
     * @param array<int, mixed> $fallbacks
     */
    private function convertMirrorControl(DOMElement $control, DOMElement $navigation, array &$fallbacks): ConversionOutcome
    {
        $panel = $this->navigationToggleSuppressor->mirrorPanel($control);
        $this->navigationToggleSuppressor->setBuildingMirror(true);
        try {
            $block = $this->tree->patterns($navigation, $fallbacks, array(NavigationPattern::class));
            if ( null === $block || 'core/navigation' !== ($block['blockName'] ?? '') ) {
                return ConversionOutcome::unhandled();
            }
            $block['attrs']['className'] = SourceDom::mergeClassNames(
                'blocks-engine-list-navigation blocks-engine-native-responsive-navigation',
                (string) ($block['attrs']['className'] ?? ''),
                $this->responsiveNavigationToggleMarker($navigation),
                null === $panel ? $this->mirrorRowPaintMarker($control, $navigation) : $this->responsiveNavigationOverlayMarker($panel),
                null === $panel ? '' : $this->mirrorPanelLinkMarker($panel)
            );
            $block['attrs']['overlayMenu'] = 'always';
            $block['attrs'] = array_replace(
                $block['attrs'],
                null === $panel ? array() : array_intersect_key($this->responsiveNavigationOverlayAttributes($panel), array_flip(array('customOverlayBackgroundColor', 'customOverlayTextColor')))
            );
            $block['attrs'] = $this->withSourceOpener($block['attrs'], $navigation);
            $boundary = $this->navigationToggleSuppressor->sourceCollapseBreakpoint($navigation, $control);
            if (null !== $boundary) {
                $marker = 'blocks-engine-navigation-toggle-slot-' . substr(hash('sha256', (string) $boundary), 0, 12);
                // The source nav's identity belongs only to the inline occurrence.
                $classes = array_filter(SourceDom::tokens($block['attrs']['className']), static fn (string $class): bool => str_starts_with($class, 'blocks-engine-'));
                $block['attrs']['className'] = SourceDom::mergeClassNames(implode(' ', $classes), $marker);
                unset($block['attrs']['anchor']);
                $host = '.wp-block-navigation.blocks-engine-native-responsive-navigation.' . $marker . ':not(.wp-block-navigation__container)';
                $this->session->generatedSupportStylesheetState()->registerNativeNavigationToggle($marker,
                    '@media(max-width:' . $boundary . 'px){' . $host . '{display:flex!important}}'
                    . '@media(min-width:' . ($boundary + 1) . 'px){' . $host . '{display:none!important}}');
            }
            $block['attrs']['metadata'][NavigationOpenerPresentation::METADATA_KEY]['className'] = SourceDom::attr($control, 'class');
        } finally {
            $this->navigationToggleSuppressor->setBuildingMirror(false);
        }
        $this->supersedeRuntimeSelectorsForControl($control);

        return ConversionOutcome::handled($block);
    }

    /**
     * With no captured panel to copy, the overlay takes the paint around the
     * toggle, so links drawn for the header stay readable once it opens.
     */
    private function mirrorRowPaintMarker(DOMElement $control, DOMElement $navigation): string
    {
        $background = '';
        for ( $node = $control->parentNode; $node instanceof DOMElement && '' === $background; $node = $node->parentNode ) {
            $values = $this->styleResolver->collapsedViewportDeclarations($node, array('background', 'background-color'));
            $background = $this->portableCollapsedPaint($node, $this->backgroundColorLayer($node, (string) ($values['background-color'] ?? $values['background'] ?? '')));
            // A translucent header relies on what is behind it; the overlay covers the page.
            $parsed = '' === $background ? null : CssColor::parse($background);
            if ( null !== $parsed && 1.0 > $parsed[3] ) {
                $background = '';
            }
            if ( 'html' === strtolower($node->tagName) ) {
                break;
            }
        }
        $anchor = $navigation->getElementsByTagName('a')->item(0);
        $color = $anchor instanceof DOMElement
            ? $this->portableCollapsedPaint($anchor, (string) ($this->styleResolver->collapsedViewportDeclarations($anchor, array('color'))['color'] ?? ''))
            : '';
        $marker = 'blocks-engine-navigation-row-paint-' . substr(hash('sha256', $background . '|' . $color), 0, 12);
        $open = '.wp-block-navigation.blocks-engine-native-responsive-navigation.' . $marker
            . ' .wp-block-navigation__responsive-container.is-menu-open:not(.disable-default-overlay)';
        $this->session->generatedSupportStylesheetState()->registerNativeNavigationOverlay(
            $marker,
            ( '' === $background ? '' : $open . '{background:' . $background . '!important}' )
                . $open . ' .wp-block-navigation__container{padding-inline-start:2rem!important}'
                . $open . ' .wp-block-navigation-item{margin:0!important;padding:0!important}'
                . $open . ' .wp-block-navigation-item__content{margin:0!important}'
                . ( '' === $color ? '' : $open . ' .wp-block-navigation__responsive-container-close{color:' . $color . '!important}' )
        );

        return $marker;
    }

    /**
     * The panel's links replace the row's in the open overlay. The links in
     * this navigation come from the row, so their inline spacing and type
     * would otherwise carry over into the panel.
     */
    private function mirrorPanelLinkMarker(DOMElement $panel): string
    {
        $anchor = $panel->getElementsByTagName('a')->item(0);
        if ( ! $anchor instanceof DOMElement ) {
            return '';
        }
        $properties = array('color', 'font-family', 'font-size', 'font-weight', 'letter-spacing', 'line-height', 'text-transform', 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left');
        $declarations = array('text-transform' => 'none!important');
        foreach ( $this->styleResolver->collapsedViewportDeclarations($anchor, $properties) as $property => $value ) {
            $value = $this->styleResolver->resolveCssVariablesInValue(CssValueInspector::withoutImportant($value), $anchor);
            if ( '' !== trim($value) && ! preg_match('/[{}<>;]|var\(|url\(/i', $value) ) {
                $declarations[$property] = trim($value) . '!important';
            }
        }
        $body = implode(';', array_map(static fn (string $property, string $value): string => $property . ':' . $value, array_keys($declarations), $declarations));
        // The row's links sat in its own box; the panel's inset came from the
        // wrappers around its links.
        $inset = 0.0;
        $item = null;
        for ( $node = $anchor->parentNode; $node instanceof DOMElement && ! $node->isSameNode($panel->parentNode); $node = $node->parentNode ) {
            $item ??= 'li' === strtolower($node->tagName) ? $node : null;
            $boxes = $this->styleResolver->collapsedViewportDeclarations($node, array('padding-left', 'margin-left'));
            foreach ( $boxes as $value ) {
                if ( 1 === preg_match('/^(-?\d*\.?\d+)px$/', CssValueInspector::withoutImportant(trim($value)), $match) ) {
                    $inset += (float) $match[1];
                }
            }
        }
        $rowGap = '';
        if ( $item instanceof DOMElement ) {
            $boxes = $this->styleResolver->collapsedViewportDeclarations($item, array('margin-bottom', 'padding-bottom', 'padding-top', 'margin-top'));
            $sum = 0.0;
            foreach ( $boxes as $value ) {
                if ( 1 === preg_match('/^(\d*\.?\d+)px$/', CssValueInspector::withoutImportant(trim($value)), $match) ) {
                    $sum += (float) $match[1];
                }
            }
            $rowGap = 0.0 < $sum ? $sum . 'px' : '';
        }
        $marker = 'blocks-engine-navigation-panel-links-' . substr(hash('sha256', $body . '|' . $inset . '|' . $rowGap), 0, 12);
        $open = '.wp-block-navigation.blocks-engine-native-responsive-navigation.' . $marker
            . ' .wp-block-navigation__responsive-container.is-menu-open:not(.disable-default-overlay)';
        $this->session->generatedSupportStylesheetState()->registerNativeNavigationOverlay(
            $marker,
            $open . ' .wp-block-navigation__container{padding-inline-start:' . $inset . 'px!important;' . ( '' === $rowGap ? '' : 'row-gap:' . $rowGap . '!important' ) . '}'
                . $open . ' .wp-block-navigation-item{margin:0!important;padding:0!important}'
                . $open . ' .wp-block-navigation-item__content{margin:0!important;' . $body . '}'
                . ( isset($declarations['color']) ? $open . ' .wp-block-navigation__responsive-container-close{color:' . $declarations['color'] . '}' : '' )
        );

        return $marker;
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
        // Core's own icon paints with currentColor. When the source artwork
        // is not carried, its resting glyph colour still belongs to the icon.
        $glyphColor = $isJumpMenu || null !== $source ? '' : $this->sourceGlyphColor($toggle);
        if ( '' !== $glyphColor ) {
            $declarations = array_values(array_filter($declarations, static fn (string $declaration): bool => ! str_starts_with($declaration, 'color:')));
            $declarations[] = 'color:' . $glyphColor . '!important';
        }
        // A toggle carried by its own padding, border and an icon child keeps
        // that intrinsic box; the touch-target floor is for boxes with none.
        $hasIntrinsicBox = ! $isJumpMenu && $this->nativeNavigationToggleHasIntrinsicBox($toggle);
        if ($hasIntrinsicBox && null === $source) {
            foreach ($toggle->getElementsByTagName('*') as $child) {
                $box = $this->styleResolver->collapsedViewportDeclarations($child, array('width', 'height'));
                if ($this->nativeNavigationToggleDimensionIsUsable($box['width'] ?? '') && $this->nativeNavigationToggleDimensionIsUsable($box['height'] ?? '')) {
                    if (!$hasUsableWidth) $declarations[] = 'width:' . $box['width'] . '!important';
                    if (!$hasUsableHeight) $declarations[] = 'height:' . $box['height'] . '!important';
                    break;
                }
            }
        }
        if ( ! $hasUsableWidth && null === $source && ! $hasIntrinsicBox ) {
            $declarations[] = 'min-width:44px!important';
        }
        if ( ! $hasUsableHeight && null === $source && ! $hasIntrinsicBox ) {
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
        $bindsPanel = ! $this->navigationToggleSuppressor->isMirrorControl($toggle)
            && ! $this->navigationToggleSuppressor->isHashAnchorMenuProjection($toggle)
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
        // Its collapsed margins also belong to that outer item box.
        $slotDeclarations = array();
        foreach ( $this->styleResolver->collapsedViewportAuthorDeclarations($toggle, array('grid-area', 'grid-column', 'grid-row', 'grid-column-start', 'grid-column-end', 'grid-row-start', 'grid-row-end', 'order', 'align-self', 'justify-self', 'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left')) as $property => $value ) {
            $value = $this->styleResolver->resolveCssVariablesInValue(CssValueInspector::withoutImportant(trim($value)), $toggle);
            if ( '' !== $value && ! preg_match('/[{}<>;]|var\(/i', $value) ) {
                $slotDeclarations[] = $property . ':' . $value . '!important';
            }
        }
        $wrapperSlot = $always ? null : $this->nativeNavigationToggleWrapperSlot($toggle, $navigation);
        if ( null !== $wrapperSlot ) {
            $slotDeclarations = array_merge($slotDeclarations, $wrapperSlot['host']);
        }
        $buttonPresentation = 'button' === strtolower($toggle->tagName)
            ? $this->styleResolver->authoredControlPresentation($toggle, array('padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'border', 'border-radius', 'border-top-left-radius', 'border-top-right-radius', 'border-bottom-left-radius', 'border-bottom-right-radius', 'background', 'background-color'))
            : array();
        $marker = 'blocks-engine-native-navigation-toggle-' . substr(hash('sha256', (array() === $buttonPresentation ? '' : serialize($buttonPresentation)) . implode(';', $openDeclarations) . implode(';', $slotDeclarations) . ( null === $wrapperSlot ? '' : ';wrapper' ) . $extra . (null === $source ? '' : serialize($source)) . ( '' === $collapseBoundary ? '' : ';collapse:' . $collapseBoundary ) . ( '' === $absentAbove ? '' : ';absent:' . $absentAbove )), 0, 12);
        $host = '.wp-block-navigation.blocks-engine-list-navigation.blocks-engine-native-responsive-navigation.' . $marker;
        $hostDeclarations = $this->nativeNavigationToggleHostDeclarations($always, $display, $sourceDeclarations, array() !== $placement);
        $drawer = $this->collapsedContainingBlock($toggle);
        $drawerPlacement = $drawer instanceof DOMElement && SourceDom::elementContains($drawer, $navigation)
            ? $this->portableCollapsedPlacement($drawer, array('position')) : array();
        $outOfFlowRule = '';
        if ( 'fixed' === ($placement['position'] ?? '') && 'fixed' === ($drawerPlacement['position'] ?? '') ) {
            // The removed drawer occupied no layout slot. Keep its empty
            // host out of flow too, without retaining panel dimensions.
            // Core repeats the host classes on its inner list, which must
            // keep its own box inside the open overlay.
            $outOfFlowRule = $host . ':not(.wp-block-navigation__container){position:absolute!important;inset:auto!important;width:0!important;height:0!important;margin:0!important;flex-basis:0!important}';
            $slotDeclarations = array();
            $wrapperSlot = null;
        }
        $hostRule = $host . '{' . implode(';', array_merge($hostDeclarations, $slotDeclarations)) . '}' . $outOfFlowRule;
        if ( null !== $wrapperSlot ) {
            // The wrapper is the row's last item, after the host. Addressing it
            // from the host keeps the rule independent of where the header
            // sits in each page, so shared chrome stays identical.
            $hostRule .= $host . '~:last-child{margin-inline-start:auto!important}';
        }
        // Core repeats source list classes on its inner container. Neither
        // that list nor the replacement toggle host is the source drawer:
        // retaining its translation (or a transform hint) moves the closed
        // menu out of the viewport and makes the host a containing block for
        // Core's fixed overlay and a viewport-fixed opener.
        $hostRule .= $host . ',' . $host . ' .wp-block-navigation__container{transform:none!important;translate:none!important;will-change:auto!important}';
        $openRule = $host . '>.wp-block-navigation__responsive-container-open{' . implode(';', $openDeclarations) . '}';
        if ( array() !== $placement ) {
            $openRule .= $this->nativeNavigationTogglePlacementEditorReset($host, $placement);
        }
        $extraRules = '';
        if ('button' === strtolower($toggle->tagName)) {
            // Core resets native button paint and padding. Restore the native
            // defaults first, then replay author rules (including shorthands
            // and conditional rules) on the actual replacement control.
            $open = $host . '>.wp-block-navigation__responsive-container-open';
            $extraRules .= $open . '{background-color:ButtonFace!important;border-radius:0!important;padding:1px 6px!important}';
            $extraRules .= $presentation->css($open, $buttonPresentation, $toggle);
        }
        if (null !== $source) {
            $open = $host . '>.wp-block-navigation__responsive-container-open';
            // Native defaults supply a different box; intrinsic source SVG
            // sizing remains on the child, with margin/transform on the leaf
            // button so Core's overlay does not gain a transformed ancestor.
            $extraRules .= $open . '{min-width:0!important;min-height:0!important;' . ('button' === strtolower($toggle->tagName) ? '' : 'padding:0!important;') . 'border:0!important;width:auto!important;height:auto!important}';
            $extraRules .= $presentation->css($open, $source['button'], $toggle);
            $extraRules .= $presentation->css($open . '>svg', $source['icon'], $presentation->icon($toggle) ?? $toggle);
        }
        if (null !== $source && null === $presentation->icon($toggle) && $this->hasNavigationHeader($navigation)) {
            $paint = $this->responsiveNavigationOverlayAttributes($navigation)['customOverlayBackgroundColor'] ?? '';
            if ('' !== $paint) {
                $openPanel = $host . ' .wp-block-navigation__responsive-container.is-menu-open:not(.disable-default-overlay)';
                // The passive panel is below the retained header row. Keep Core's
                // close icon in the replacement host, the source burger's slot.
                // Core's open animation transform and flex-item z-index would make
                // the container the fixed panel's containing block and lift it over
                // the header row, so the container stays a plain, static box.
                $extraRules .= $host . '{position:relative!important}'
                    . $host . ':has(.is-menu-open) .wp-block-navigation__responsive-container-open{visibility:hidden!important}'
                    . $openPanel . '{position:static!important;background:transparent!important;padding:0!important;transform:none!important;animation:none!important;z-index:auto!important}'
                    . $openPanel . ' .wp-block-navigation__responsive-dialog{position:static!important}'
                    . $openPanel . ' .wp-block-navigation__responsive-container-content{position:fixed!important;inset:0!important;box-sizing:border-box!important;background:' . $paint . '!important;z-index:-1!important}'
                    . $openPanel . ' .wp-block-navigation__responsive-container-close{position:absolute!important;inset:0!important;display:flex!important;align-items:center!important;justify-content:center!important;' . implode(';', $declarations) . '}'
                    . $openPanel . ' .wp-block-navigation__responsive-container-close{box-sizing:content-box!important;width:' . $source['icon']['']['width'] . '!important;height:' . $source['icon']['']['height'] . '!important;padding:1px 6px!important;border:0!important;background:transparent!important}'
                    . $openPanel . ' .wp-block-navigation__responsive-container-close svg{display:block!important;width:100%!important;height:100%!important}';
            }
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
        $drawerRules .= $this->nativeNavigationDrawerCss($host, $navigation, $toggle);
        $panelRule = $always ? '' : $this->collapsePanelVisibilityRule($host, $navigation);
        if ( $always ) {
            $rule = $hostRule . $openRule . $extraRules;
        } elseif ( '' === $collapseBoundary ) {
            $rule = '@media(max-width:' . ( self::CORE_OVERLAY_BREAKPOINT - 1 ) . 'px){' . $hostRule . $openRule . $extraRules . $drawerRules . $panelRule . '}';
        } else {
            $rule = '@media(max-width:' . $collapseBoundary . '){' . $hostRule . $openRule . $extraRules . $drawerRules . $panelRule . '}'
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
        // A text-only opener is Core's text opener ("Menu"/"Close"), not its icon.
        elseif (NavigationOpenerPresentation::isTextOnly($toggle)) $attrs['hasIcon'] = false;
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
        $panels = $this->overlayPanelBoxes($navigation);
        foreach ( $panels as $panel ) {
            $values = $this->styleResolver->collapsedViewportAuthorDeclarations($panel, array('background', 'background-color', 'color', 'text-align', 'align-items', 'flex-direction'));
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
            $paint = $this->portableCollapsedPaint($panel, $this->backgroundColorLayer($panel, $background));
            if ( '' !== $paint ) $attrs['customOverlayBackgroundColor'] = $paint;
        }
        if ( isset($attrs['customOverlayTextColor']) && ! isset($attrs['customOverlayBackgroundColor']) ) {
            // The source text was painted for the surface behind the menu,
            // not for Core's white overlay: open the panel on that surface.
            $surface = $this->opaqueSurfaceBehind($navigation);
            if ( '' !== $surface ) $attrs['customOverlayBackgroundColor'] = $surface;
        }

        return $attrs;
    }

    /** The nearest opaque background colour painted by an ancestor of the menu. */
    private function opaqueSurfaceBehind(DOMElement $navigation): string
    {
        for ( $node = $navigation->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
            $values = $this->styleResolver->collapsedViewportAuthorDeclarations($node, array('background', 'background-color'));
            $paint = $this->portableCollapsedPaint($node, $this->backgroundColorLayer($node, (string) ($values['background-color'] ?? $values['background'] ?? '')));
            if ( '' !== $paint && CssColor::opaque($paint) ) return $paint;
        }

        return '';
    }

    /** Include only full-bleed, passive backdrop children of the controlled panel chain. */
    private function overlayPanelBoxes(DOMElement $navigation): array
    {
        $boxes = array_merge($this->navigationToggleSuppressor->controlledPanelBoxes($navigation), $this->collapsedPanelChain($navigation));
        for ($node = $navigation; $node instanceof DOMElement && !in_array(strtolower($node->tagName), array('header', 'body'), true); $node = $node->parentNode) {
            $values = $this->styleResolver->collapsedViewportAuthorDeclarations($node, array('position', 'visibility', 'opacity'));
            if (in_array($values['position'] ?? '', array('fixed', 'absolute'), true) && ('hidden' === ($values['visibility'] ?? '') || '0' === ($values['opacity'] ?? ''))) {
                for ($inner = $navigation; $inner instanceof DOMElement; $inner = $inner->parentNode) {
                    array_unshift($boxes, $inner);
                    if ($inner->isSameNode($node)) break;
                }
                break;
            }
        }
        $result = array();
        foreach ($boxes as $box) {
            $result[] = $box;
            foreach ($box->childNodes as $child) {
                if (!$child instanceof DOMElement || $child->childElementCount > 0 || '' !== trim($child->textContent ?? '')) continue;
                $values = $this->styleResolver->collapsedViewportDeclarations($child, array('position', 'inset', 'top', 'right', 'bottom', 'left'));
                if ('absolute' !== ($values['position'] ?? '')) continue;
                $full = in_array($values['inset'] ?? '', array('0', '0px'), true);
                if (!$full) {
                    $full = true;
                    foreach (array('top', 'right', 'bottom', 'left') as $side) $full = $full && in_array($values[$side] ?? '', array('0', '0px'), true);
                }
                if ($full) $result[] = $child;
            }
        }
        return $result;
    }

    /** Carry panel paint and content-box repairs onto Core's default open overlay. */
    public function responsiveNavigationOverlayMarker(DOMElement $navigation): string
    {
        $attrs = $this->responsiveNavigationOverlayAttributes($navigation);
        $background = $attrs['customOverlayBackgroundColor'] ?? '';
        $color = $attrs['customOverlayTextColor'] ?? '';
        $listPadding = array();
        $chain = $this->collapsedPanelChain($navigation);
        foreach ($chain[0]->hasAttribute(CaptureEvidence::fluidIgnorePaddingAttribute()) ? array() : $this->styleResolver->collapsedViewportDeclarations($chain[0], array('padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left')) as $property => $value) {
            $value = $this->styleResolver->resolveCssVariablesInValue(CssValueInspector::withoutImportant($value), $chain[0]);
            if ('' !== trim($value) && !preg_match('/[{}<>;]|var\(|url\(/i', $value)) {
                $listPadding[] = $property . ':' . $value . '!important';
            }
        }
        $alignmentMarker = trim($this->responsiveNavigationAlignmentMarker($navigation) . ' ' . $this->responsiveNavigationHeaderOffsetMarker($navigation));
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

    /** Script-owned panel padding follows the live header, never its capture-time pixels. */
    private function responsiveNavigationHeaderOffsetMarker(DOMElement $navigation): string
    {
        $header = $navigation->parentNode;
        while ($header instanceof DOMElement && 'header' !== strtolower($header->tagName)) $header = $header->parentNode;
        if (!$header instanceof DOMElement) return '';
        foreach ($this->overlayPanelBoxes($navigation) as $box) {
            if (!$box->hasAttribute(CaptureEvidence::fluidIgnorePaddingAttribute())) continue;
            $position = $this->styleResolver->collapsedViewportAuthorDeclarations($box, array('position'));
            if ('fixed' !== ($position['position'] ?? '')) continue;
            $marker = 'blocks-engine-navigation-header-offset';
            $open = '.wp-block-navigation.blocks-engine-native-responsive-navigation.' . $marker . ' .wp-block-navigation__responsive-container.is-menu-open:not(.disable-default-overlay)';
            $this->session->generatedSupportStylesheetState()->registerNativeNavigationOverlay($marker,
                $open . ' .wp-block-navigation__responsive-dialog .wp-block-navigation__responsive-container-content{box-sizing:border-box!important;min-height:100dvh!important;padding-top:var(--blocks-engine-navigation-header-height,0px)!important}'
            );
            return $marker;
        }
        return '';
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
        foreach ($this->overlayPanelBoxes($navigation) as $box) {
            $values = $this->styleResolver->collapsedViewportAuthorDeclarations($box, array('flex-direction', 'justify-content'));
            if (str_starts_with($values['flex-direction'] ?? '', 'column') && 'center' === ($values['justify-content'] ?? '')) {
                $rule .= $open . ' .wp-block-navigation__responsive-container-content{min-height:100dvh!important;justify-content:center!important;align-items:' . $align . '!important;padding-block:0!important}'
                    . $open . ' .wp-block-navigation__container{flex-direction:column!important;justify-content:center!important}';
                break;
            }
        }
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

    /**
     * The resting fill of a toggle's single drawn glyph at the collapsed
     * viewport, as a portable colour, or ''. A glyph that paints with
     * currentColor takes its own resolved text colour.
     */
    private function sourceGlyphColor(DOMElement $toggle): string
    {
        $glyph = null;
        foreach ( $toggle->getElementsByTagName('svg') as $svg ) {
            if ( ! $svg instanceof DOMElement || ! SourceDom::svgHasDrawableContent($svg) ) {
                continue;
            }
            if ( null !== $glyph ) {
                return '';
            }
            $glyph = $svg;
        }
        if ( ! $glyph instanceof DOMElement ) {
            return '';
        }
        $fill = CssValueInspector::withoutImportant(trim((string) ($this->styleResolver->collapsedViewportAuthorDeclarations($glyph, array( 'fill' ))['fill'] ?? '')));
        $fill = '' === $fill ? '' : $this->styleResolver->resolveCssVariablesAtViewport($fill, $glyph, StyleResolver::MOBILE_REFERENCE_WIDTH);
        if ( 'currentcolor' === strtolower($fill) ) {
            $fill = '';
            for ( $node = $glyph; $node instanceof DOMElement; $node = $node->parentNode ) {
                $color = CssValueInspector::withoutImportant(trim((string) ($this->styleResolver->collapsedViewportAuthorDeclarations($node, array( 'color' ))['color'] ?? '')));
                if ( '' !== $color && ! in_array(strtolower($color), array( 'inherit', 'currentcolor' ), true) ) {
                    $fill = $this->styleResolver->resolveCssVariablesAtViewport($color, $node, StyleResolver::MOBILE_REFERENCE_WIDTH);
                    break;
                }
            }
        }
        $fill = trim($fill);

        return null !== CssColor::parse($fill) && ! CssValueInspector::isTransparentColor($fill) ? $fill : '';
    }

    /**
     * The colour layer of a background declaration, or ''. A shorthand such
     * as a reset's `background:0 0` names a position, not a paint.
     */
    private function backgroundColorLayer(DOMElement $panel, string $value): string
    {
        $resolved = $this->styleResolver->resolveCssVariablesInValue(CssValueInspector::withoutImportant($value), $panel);
        // A gradient is the paint itself; Core's overlay background takes it whole.
        if ( preg_match('/^(?:repeating-)?(?:linear|radial|conic)-gradient\(/i', trim($resolved)) ) {
            return $value;
        }
        $colors = array_values(array_filter(
            CssValueSplitter::splitTopLevelWhitespace(trim($resolved)),
            static fn (string $token): bool => null !== CssColor::parse($token)
        ));

        return 1 === count($colors) ? $colors[0] : '';
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

    /**
     * Put the native navigation into the header row across the collapsed
     * range when its source collapse panel also holds content beside it, or
     * '' when nothing must be restated.
     *
     * A source disclosure panel that also holds content beside the menu (a
     * CTA, a search box) keeps its own wrapper in the projection, and with it
     * the author closed state (`display:none` until the source boundary) that
     * once hid the whole panel. A panel holding only the menu is absorbed into
     * the navigation block, whose toggle rules already neutralize that state
     * on the host itself; the wrapper case reaches neither of them, and the
     * closed state then hides Core's open button along with the menu, leaving
     * collapsed-viewport visitors without a way to open it.
     *
     * The native overlay replaces the panel's collapse behaviour, so across
     * the range where this occurrence's toggle shows, the wrapper's box is
     * dissolved: the host — carrying the dropped toggle's authored slot —
     * joins the header row in that toggle's place, the same slot replacement
     * a dropped burger gets, while the wrapper's other content keeps the
     * source closed state, hidden as it was while the source hid the whole
     * panel. Revealing the panel's own box instead would also reveal that
     * content in the header and stack the toggle on a second row behind it.
     */
    private function collapsePanelVisibilityRule(string $host, DOMElement $navigation): string
    {
        $chain = $this->collapsedPanelChain($navigation);
        // The innermost wrapper that keeps rendered content beside the chain
        // is the one the projection keeps around the navigation block; outer
        // wrappers never parent it directly.
        $panel = null;
        for ( $i = count($chain) - 1; $i >= 0; --$i ) {
            // A landmark whose box stays apart from its list is kept as the
            // carrier group around the navigation block even when the list is
            // its only content, so its closed state hides Core's open button.
            if ( ( isset($chain[$i + 1]) && $this->collapsePanelKeepsHost($chain[$i], $chain[$i + 1]) )
                || $this->navigationToggleSuppressor->isNavigationCarrier($chain[$i]) ) {
                $panel = $chain[$i];
                break;
            }
        }
        // The occurrence's own parent is not on the chain unless the collapsed
        // cascade hid it out of flow; when the chain kept no wrapper above the
        // list, that parent is the panel projected around the navigation.
        if ( null === $panel ) {
            $candidate = $navigation->parentNode;
            if ( $candidate instanceof DOMElement && $this->collapsePanelKeepsHost($candidate, $navigation) ) {
                $panel = $candidate;
            }
        }
        if ( null === $panel || ! $this->hiddenAtCollapsedViewport($panel) ) {
            return '';
        }
        // The override is `!important`, so it must also outrank the panel's
        // own important author `display` rules (an ID-scoped document switch,
        // for one); an `:is()` arm of IDs that never matches adds the weight.
        [$ids] = $this->styleResolver->authorImportantDeclarationSpecificity($panel, array( 'display' ));
        $weight = 0 < $ids ? ':is(.wp-block-group,' . str_repeat('#blocks-engine-panel', $ids + 1) . ')' : '';
        $wrapper = '.wp-block-group' . $weight . ':has(> ' . $host . ')';

        return $wrapper . '{display:contents!important}'
            . $wrapper . '>:not(' . $host . '){display:none!important}';
    }

    /** Whether the collapsed cascade hides the panel with `display:none` at the reference viewport. */
    private function hiddenAtCollapsedViewport(DOMElement $panel): bool
    {
        $display = CssValueInspector::withoutImportant(trim((string) ( $this->styleResolver->collapsedViewportAuthorDeclarations($panel, array( 'display' ))['display'] ?? '' )));
        $display = strtolower(trim($this->styleResolver->resolveCssVariablesAtViewport($display, $panel, StyleResolver::MOBILE_REFERENCE_WIDTH)));

        return 'none' === $display;
    }

    /** Whether the panel keeps rendered content beside the navigation, so the projection keeps it as a wrapper instead of absorbing it into the navigation block. */
    private function collapsePanelKeepsHost(DOMElement $panel, DOMElement $navigation): bool
    {
        foreach ( $panel->childNodes as $child ) {
            if ( $child instanceof DOMElement && ! $child->isSameNode($navigation) ) {
                return true;
            }
        }

        return false;
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

    /** Restore a proven viewport side drawer, never an ordinary header dropdown. */
    private function nativeNavigationDrawerCss(string $host, DOMElement $navigation, DOMElement $toggle): string
    {
        $fixed = null;
        $panel = null;
        $width = '';
        foreach ($this->collapsedPanelChain($navigation) as $candidatePanel) {
            $values = $this->portableCollapsedPlacement($candidatePanel, array('width'));
            if (preg_match('/^(?:[1-9][0-9]*(?:\.[0-9]+)?)(?:px|rem|em|vw)$/D', $values['width'] ?? '')) {
                $panel = $candidatePanel;
                $width = $values['width'];
                break;
            }
        }
        for ($node = $navigation, $depth = 0; $node instanceof DOMElement && $depth < 5; $node = $node->parentNode, ++$depth) {
            $values = $this->portableCollapsedPlacement($node, array('position', 'width', 'left', 'right'));
            $candidate = $values['width'] ?? '';
            if (null === $panel && preg_match('/^(?:[1-9][0-9]*(?:\.[0-9]+)?)(?:px|rem|em|vw)$/D', $candidate)) {
                $panel = $node;
                $width = $candidate;
            }
            if ('fixed' === ($values['position'] ?? '') && (isset($values['right']) || isset($values['left']))) {
                $fixed = $values;
                break;
            }
        }
        if (null === $fixed || null === $panel) return '';
        $edge = 'auto' !== ($fixed['right'] ?? 'auto') ? 'right' : 'left';
        if (!in_array($fixed[$edge] ?? '', array('0', '0px'), true)) return '';
        $background = '';
        foreach (array($panel, $node) as $paintSource) {
            if (!$paintSource instanceof DOMElement) continue;
            $paint = $this->styleResolver->collapsedViewportAuthorDeclarations($paintSource, array('background', 'background-color'));
            $background = $this->portableCollapsedPaint($paintSource, $paint['background-color'] ?? $paint['background'] ?? '');
            if ('' !== $background) break;
        }
        if ('' === $background) return '';
        // Checkbox drawers commonly pair their visible label with a second,
        // full-page label. Carry only its paint, not its hidden resting box.
        $backdrop = 'transparent';
        if ('label' === strtolower($toggle->tagName) && $toggle->parentNode instanceof DOMElement) {
            foreach ($toggle->parentNode->childNodes as $sibling) {
                if (!$sibling instanceof DOMElement || $sibling->isSameNode($toggle)
                    || 'label' !== strtolower($sibling->tagName) || $sibling->getAttribute('for') !== $toggle->getAttribute('for')) continue;
                $paint = $this->styleResolver->collapsedViewportAuthorDeclarations($sibling, array('background', 'background-color'));
                $value = $this->portableCollapsedPaint($sibling, $paint['background-color'] ?? $paint['background'] ?? '');
                if ('' !== $value) $backdrop = $value;
            }
        }
        $controlSize = $this->portableCollapsedPlacement($toggle, array('height'))['height'] ?? '0px';
        if (!preg_match('/^\d+(?:\.\d+)?(?:px|rem|em)$/D', $controlSize)) $controlSize = '0px';
        $open = $host . ' .wp-block-navigation__responsive-container.is-menu-open:not(.disable-default-overlay)';
        return $open . '{background:' . $backdrop . '!important;padding:0!important}'
            . $open . ' .wp-block-navigation__responsive-dialog{box-sizing:border-box!important;position:fixed!important;inset:0 auto 0 auto!important;' . $edge . ':0!important;width:' . $width . '!important;max-width:100vw!important;height:100dvh!important;margin:0!important;overflow:auto!important;background:' . $background . '!important}'
            . $open . ' .wp-block-navigation__responsive-container-content{padding-top:' . $controlSize . '!important;flex-direction:column!important;align-items:stretch!important}'
            . $open . ' .wp-block-navigation__container{display:flex!important;flex-direction:column!important;align-items:stretch!important;width:100%!important}'
            . $open . ' .wp-block-navigation__responsive-container-close{top:0!important;right:0!important}';
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
     * wrapper is left alone. An absolute toggle in a fixed drawer containing
     * the navigation (including the navigation itself) is promoted to fixed
     * viewport placement instead. The drawer need not be the converted list.
     * Otherwise, nothing is carried for a
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
        if ( $toggleContainingBlock instanceof DOMElement && SourceDom::elementContains($toggleContainingBlock, $navigation) ) {
            $drawer = $this->portableCollapsedPlacement($toggleContainingBlock, self::CONTAINING_BLOCK_PROPERTIES);
            // An absolute control inside a viewport-fixed drawer is itself
            // viewport anchored. Promote only simple physical edge offsets:
            // percentages, logical insets and transformed drawers require a
            // different containing-block calculation and must not be guessed.
            if ( 'absolute' === ($portable['position'] ?? '') && 'fixed' === ($drawer['position'] ?? '') ) {
                unset($drawer['position']);
                foreach ( $drawer as $property => $value ) {
                    if ( $this->establishesContainingBlock($property, strtolower($value)) ) {
                        return array();
                    }
                }
                $edges = $this->portableCollapsedPlacement($toggleContainingBlock, self::TOGGLE_OFFSET_PROPERTIES);
                foreach ( array('top', 'right', 'bottom', 'left') as $edge ) {
                    $offset = $portable[$edge] ?? 'auto';
                    if ( 'auto' === $offset ) {
                        continue;
                    }
                    $anchor = $edges[$edge] ?? 'auto';
                    if ( ! preg_match('/^-?(?:\d+(?:\.\d+)?|\.\d+)(?:px)?$/', $offset)
                        || ! preg_match('/^-?(?:\d+(?:\.\d+)?|\.\d+)(?:px)?$/', $anchor) ) {
                        return array();
                    }
                    $portable[$edge] = ( (float) $offset + (float) $anchor ) . 'px';
                }
                if ( array_diff(array_keys(array_intersect_key($portable, array_flip(self::TOGGLE_OFFSET_PROPERTIES))), array('top', 'right', 'bottom', 'left')) ) {
                    return array();
                }
                $portable['position'] = 'fixed';
                $stacking = $this->portableCollapsedPlacement($toggleContainingBlock, array('z-index'));
                // The promoted control leaves the drawer's stacking context;
                // use that context's level, not the toggle's level within it.
                if ( isset($stacking['z-index']) ) {
                    $portable['z-index'] = $stacking['z-index'];
                }
                return $portable;
            }
        }
        if ( $toggleContainingBlock !== $navigationContainingBlock
            && ! ( $toggleContainingBlock instanceof DOMElement && $navigationContainingBlock instanceof DOMElement && $toggleContainingBlock->isSameNode($navigationContainingBlock) ) ) {
            return array();
        }

        return $portable;
    }

    /**
     * The trailing slot of a toggle that sits in a sibling wrapper of the
     * navigation, or null when the toggle shares the navigation's row.
     *
     * Core's open button renders inside the navigation host, so at collapsed
     * widths it takes the navigation's slot in the row. A source header laid
     * out as `brand | nav | actions{cart, toggle}`, whose nav leaves the flow
     * when collapsed, shows the toggle at the trailing edge, after the cart.
     * When the toggle closes that actions wrapper and the wrapper closes a
     * flex row that already keeps it at the trailing edge, the host is
     * ordered after the wrapper, spaced by the wrapper's gap, and the wrapper
     * keeps the trailing edge with an auto inline-start margin. Rows with
     * other shapes keep the navigation's slot.
     *
     * @return array{host: list<string>}|null
     */
    private function nativeNavigationToggleWrapperSlot(DOMElement $toggle, DOMElement $navigation): ?array
    {
        $wrapper = $toggle->parentNode;
        $row = $navigation->parentNode;
        if ( ! $wrapper instanceof DOMElement || ! $row instanceof DOMElement
            || $wrapper->isSameNode($row) || ! $wrapper->parentNode instanceof DOMElement || ! $wrapper->parentNode->isSameNode($row)
            || ! $this->isLastElementChild($toggle) || ! $this->isLastElementChild($wrapper) ) {
            return null;
        }
        $layout = array_map('strtolower', $this->portableCollapsedPlacement($row, array('display', 'flex-direction', 'justify-content')));
        if ( ! in_array($layout['display'] ?? '', array('flex', 'inline-flex'), true)
            || ! in_array($layout['flex-direction'] ?? 'row', array('row', ''), true) ) {
            return null;
        }
        $wrapperBox = $this->portableCollapsedPlacement($wrapper, array('order', 'margin-left', 'margin-inline-start', 'gap', 'column-gap'));
        $trailing = in_array($layout['justify-content'] ?? '', array('space-between', 'flex-end', 'end', 'right'), true)
            || 'auto' === strtolower($wrapperBox['margin-left'] ?? $wrapperBox['margin-inline-start'] ?? '');
        $order = $wrapperBox['order'] ?? '0';
        if ( ! $trailing || ! preg_match('/^-?\d+$/', $order) ) {
            return null;
        }
        $host = array('order:' . ( (int) $order + 1 ) . '!important');
        $gap = $wrapperBox['column-gap'] ?? ( isset($wrapperBox['gap']) ? (string) array_slice(preg_split('/\s+/', $wrapperBox['gap']) ?: array(), -1)[0] : '' );
        if ( preg_match('/^(?:\d+(?:\.\d+)?|\.\d+)(?:px|r?em)$/', $gap) ) {
            $host[] = 'margin-inline-start:' . $gap . '!important';
        }

        return array( 'host' => $host );
    }

    private function isLastElementChild(DOMElement $element): bool
    {
        for ( $next = $element->nextSibling; null !== $next; $next = $next->nextSibling ) {
            if ( $next instanceof DOMElement ) {
                return false;
            }
        }
        return true;
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
            // The source panel's closed state (faded, offset, inert) lands on
            // this host too. The host is the toggle's slot and Core's overlay
            // renders inside it, so that state would hide both the opener and
            // the opened menu.
            'inset:auto!important',
            'opacity:1!important',
            'visibility:visible!important',
            'pointer-events:auto!important',
            'clip-path:none!important',
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
                foreach ( SourceDom::tokens(SourceDom::attr($toggle, 'class')) as $className ) {
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

    private function nativeNavigationToggleHasIntrinsicBox(DOMElement $toggle): bool
    {
        foreach ( $toggle->getElementsByTagName('*') as $child ) {
            $declarations = $this->styleResolver->collapsedViewportDeclarations($child, array('width', 'height'));
            $fontSize = null;
            $lengths = array();
            foreach ( array( 'width', 'height' ) as $property ) {
                $value = CssValueInspector::withoutImportant(trim((string) ($declarations[$property] ?? '')));
                if ( 1 === preg_match('/^(\d+(?:\.\d+)?|\.\d+)(em|rem)$/i', $value, $matches) ) {
                    $fontSize ??= $this->nativeNavigationToggleFontSize($child);
                    $value = rtrim(rtrim(sprintf('%.3F', (float) $matches[1] * ( 'rem' === strtolower($matches[2]) ? 16.0 : $fontSize )), '0'), '.') . 'px';
                }
                $lengths[] = $value;
            }
            if ( $this->nativeNavigationToggleDimensionIsUsable($lengths[0]) && $this->nativeNavigationToggleDimensionIsUsable($lengths[1]) ) {
                return true;
            }
        }

        return false;
    }

    /** Computed font size in px: the nearest declared size, em against its parent, rem against 16px. */
    private function nativeNavigationToggleFontSize(DOMElement $element, int $depth = 0): float
    {
        if ( $depth > 32 ) {
            return 16.0;
        }
        $value = strtolower(CssValueInspector::withoutImportant(trim((string) ($this->styleResolver->resolvedPresentationDeclarations($element)['font-size'] ?? ''))));
        $parent = $element->parentNode instanceof DOMElement ? $element->parentNode : null;
        if ( 1 === preg_match('/^(\d+(?:\.\d+)?|\.\d+)(px|rem|em|%)$/', $value, $matches) ) {
            $number = (float) $matches[1];
            switch ( $matches[2] ) {
                case 'px':
                    return $number;
                case 'rem':
                    return $number * 16.0;
                case 'em':
                    return $number * ( $parent ? $this->nativeNavigationToggleFontSize($parent, $depth + 1) : 16.0 );
                default:
                    return $number / 100 * ( $parent ? $this->nativeNavigationToggleFontSize($parent, $depth + 1) : 16.0 );
            }
        }

        return $parent ? $this->nativeNavigationToggleFontSize($parent, $depth + 1) : 16.0;
    }

    private function nativeNavigationToggleDimensionIsUsable(string $value): bool
    {
        if ( 1 !== preg_match('/^(\d+(?:\.\d+)?)px$/', $value, $matches) ) {
            return false;
        }

        return (float) $matches[1] >= 24;
    }
}

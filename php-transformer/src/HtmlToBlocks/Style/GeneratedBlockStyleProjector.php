<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Automattic\BlocksEngine\PhpTransformer\Css\CssLength;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ButtonStyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMElement;

/** Projects unsupported block styles into deterministic generated CSS. */
final class GeneratedBlockStyleProjector
{
    public function __construct(
        private readonly Runtime $runtime,
        private readonly StyleResolver $styleResolver,
        private readonly HtmlTransformerSession $session
    ) {}

    /**
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    public function applyDeclaredBlockSupport(
        string $name,
        array $attrs,
        DOMElement $sourceElement,
        GeneratedSupportStylesheetState $generatedStyles,
        bool $preserveGeneratedStyle
    ): array {
        // Adding has-background opts these blocks into core's padded box. Source
        // CSS has no such opt-in: carry every side, including initial zeroes.
        // Resolve before mapping so shorthand/longhand and selector precedence
        // use the same primitives as other source presentation.
        if ( in_array($name, array( 'core/paragraph', 'core/heading', 'core/list', 'core/columns' ), true)
            && (isset($attrs['style']['color']['background']) || isset($attrs['style']['color']['gradient'])) ) {
            $declarations = SourceDeclarations::parse($this->styleResolver->specificityResolvedPresentationStyle($sourceElement));
            $mapped = $this->styleResolver->styleAttributeMapper()->map(
                $declarations,
                fn (string $value): string => $this->styleResolver->customProperties->resolve($value, $sourceElement)
            );
            $padding = $mapped['style']['spacing']['padding'] ?? array();
            foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
                // Lists have a UA indentation even without authored padding.
                $initial = 'left' === $side && in_array(strtolower($sourceElement->tagName), array( 'ul', 'ol' ), true) ? '40px' : '0';
                $property = 'padding-' . $side;
                $candidate = $this->styleResolver->classOwnedResponsiveDeclarations($sourceElement, array($property => $padding[$side] ?? $initial));
                if ( isset($candidate[$property]) ) {
                    $attrs['style']['spacing']['padding'][$side] = $candidate[$property];
                }
            }
        }
        $normalized = $this->runtime->normalizeBlockSupportAttributes($name, $attrs);
        $fallback = $normalized['fallbackStyle'];
        $attrs = $normalized['attrs'];
        $submenuBackground = 'core/navigation-submenu' === $name ? trim((string) ($fallback['color']['background'] ?? '')) : '';
        if ( '' !== $submenuBackground && array() !== SourceDeclarations::parse('background-color:' . $submenuBackground) ) {
            $className = 'blocks-engine-navigation-submenu-background-' . hash('sha256', $submenuBackground);
            $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $className);
            $generatedStyles->registerNavigationSubmenuBackground($className, $submenuBackground);
        }
        $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: array();
        if ( 'core/navigation' === $name && is_array($fallback['spacing']['padding'] ?? null) ) {
            foreach ( $classes as $class ) {
                if ( 'blocks-engine-list-navigation' !== $class && ! str_starts_with($class, 'blocks-engine-') ) {
                    $generatedStyles->registerListNavigationPadding($class, $fallback['spacing']['padding']);
                }
            }
        }
        if ( 'core/navigation' === $name
            && ! in_array('blocks-engine-list-navigation', $classes, true)
            && is_array($fallback['spacing'] ?? null) ) {
            $declarations = $this->styleResolver->styleAttributeMapper()->serialize(array( 'spacing' => $fallback['spacing'] ))['style'];
            if ( '' !== $declarations ) {
                // A utility such as flex may also belong to a different menu.
                // Keep this source box on an instance, never on an author class.
                $scope = '';
                foreach ( $classes as $class ) {
                    if ( str_starts_with($class, 'blocks-engine-navigation-instance-') ) {
                        $scope = $class;
                        break;
                    }
                }
                if ( '' === $scope ) {
                    $scope = $this->session->authorStyleAnalysis()->allocateStableMarker(
                        'navigation-instance',
                        ($sourceElement->getNodePath() ?? '') . $sourceElement->ownerDocument->saveHTML($sourceElement) . $declarations
                    );
                    $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $scope);
                }
                $generatedStyles->registerNavigationSpacing($scope, $declarations);
            }
        }
        if ( 'core/buttons' === $name && is_array($fallback['spacing'] ?? null) ) {
            $declarations = $this->styleResolver->styleAttributeMapper()->serialize(array( 'spacing' => $fallback['spacing'] ))['style'];
            foreach ( $classes as $class ) {
                if ( '' !== $declarations && str_starts_with($class, 'blocks-engine-control-') ) {
                    $generatedStyles->registerButtonWrapperSpacing($class, $declarations);
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
                    $generatedStyles->registerNavigationLinkColor($class, (string) $fallback['color']['text']);
                }
            }
        }
        if ( array() === $fallback ) {
            return $attrs;
        }

        $fallbackStyle = $this->styleResolver->styleAttributeMapper()->serialize($fallback)['style'];
        $fallbackDeclarations = SourceDeclarations::parse($fallbackStyle);
        $inlineDeclarations = SourceDeclarations::parse($sourceElement->getAttribute('style'));
        $inlineMapped = $this->styleResolver->styleAttributeMapper()->map($inlineDeclarations);
        $inlineFallbackDeclarations = SourceDeclarations::parse($this->styleResolver->styleAttributeMapper()->serialize($inlineMapped['style'] ?? array())['style']);
        foreach ( array_keys($fallbackDeclarations) as $property ) {
            if ( 'core/button' === $name
                && 'border-radius' === $property
                && '0' === (string) ($fallback['border']['radius'] ?? '')
            ) {
                continue;
            }
            if ( ! $preserveGeneratedStyle && ! isset($inlineDeclarations[$property]) && ! isset($inlineFallbackDeclarations[$property]) ) {
                unset($fallbackDeclarations[$property]);
            }
        }
        if ( preg_match('/(?:^|\s)be-inline-geometry-[^\s]+/', (string) ($attrs['className'] ?? '')) ) {
            // The source geometry carrier preserves declaration priority and
            // custom-property case. Do not add a lossy mapped duplicate.
            foreach ( $this->styleResolver->inlineGeometryProperties() as $property ) {
                unset($fallbackDeclarations[$property]);
            }
            // `box-shadow` stays: the geometry carrier deliberately excludes it
            // whenever a resolved shadow claimed the block's shadow support
            // (core/button skips shadow serialization, which is exactly when
            // this fallback holds the value), so the fallback declaration is
            // the only candidate left carrying it — never a duplicate.
        }
        if ( array() === $fallbackDeclarations ) {
            return $attrs;
        }
        $carrier = $this->styleResolver->inlineGeometryClassName(
            $sourceElement,
            array_diff($this->styleResolver->inlineGeometryProperties(), array_keys($fallbackDeclarations)),
            array_keys($fallbackDeclarations),
            $fallbackDeclarations,
            false,
            'core/button' === $name
        );
        if ( '' !== $carrier ) {
            $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $carrier);
        }
        return $attrs;
    }

    /**
     * @param array<string, mixed> $attrs
     * @return array{attrs: array<string, mixed>, text_alignment: string, color_changed: bool}
     */
    public function projectNativeButtonInheritedStyle(DOMElement $anchor, array $attrs, bool $useInitialTextAlignment): array
    {
        $existingTextColor = (string) ($attrs['style']['color']['text'] ?? '');
        $anchorDeclarations = $this->styleResolver->presentationDeclarations($anchor);
        $anchorColorInherits = ! isset($anchorDeclarations['color']) || self::isInheritedCssWideValue((string) $anchorDeclarations['color']);
        $anchorTextAlignmentInherits = ! isset($anchorDeclarations['text-align']) || self::isInheritedCssWideValue((string) $anchorDeclarations['text-align']);
        $inheritedColor = '';
        $inheritedTextAlignment = '';

        for ( $ancestor = $anchor->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode ) {
            // `presentationDeclarations()` skips matched stylesheet rules for
            // ancestors the high-value style boundary does not recognize (a
            // bare `<body>`/`<html>` with no class tokens, most of all), so an
            // inherited foreground authored there — `body{color:...}` is the
            // idiomatic place a design system states it — was invisible here
            // even though it is exactly the value the source renders with.
            // `structuralPresentationDeclarations()` resolves every matching
            // rule unconditionally (the same primitive `inheritedTextAlignment()`
            // already walks ancestors with), so the walk sees it too.
            $declarations = $this->styleResolver->structuralPresentationDeclarations($ancestor);
            if ( '' === $inheritedColor && $anchorColorInherits && isset($declarations['color']) ) {
                $inheritedColor = $this->styleResolver->customProperties->resolve((string) $declarations['color'], $ancestor);
            }
            if ( '' === $inheritedTextAlignment && $anchorTextAlignmentInherits && isset($declarations['text-align']) ) {
                $inheritedTextAlignment = strtolower(trim((string) $declarations['text-align']));
            }
            if ( '' !== $inheritedColor && '' !== $inheritedTextAlignment ) {
                break;
            }
        }

        if ( '' !== $inheritedColor && ( '' === trim((string) ($attrs['style']['color']['text'] ?? '')) || self::isInheritedCssWideValue((string) $attrs['style']['color']['text']) ) ) {
            $mappedColor = $this->styleResolver->styleAttributeMapper()->map(array( 'color' => $inheritedColor ))['style']['color']['text'] ?? '';
            if ( '' !== trim((string) $mappedColor) ) {
                $attrs['style']['color']['text'] = $mappedColor;
            }
        }

        $textAlignment = $anchorTextAlignmentInherits
            ? $inheritedTextAlignment
            : strtolower(trim((string) $anchorDeclarations['text-align']));
        if ( '' === $textAlignment || 'initial' === $textAlignment ) {
            $textAlignment = $useInitialTextAlignment ? 'start' : '';
        } elseif ( ! in_array($textAlignment, array( 'start', 'end', 'left', 'center', 'right' ), true) ) {
            $textAlignment = '';
        }

        return array(
            'attrs' => $attrs,
            'text_alignment' => $textAlignment,
            'color_changed' => $existingTextColor !== (string) ($attrs['style']['color']['text'] ?? ''),
        );
    }

    /** @param array<string, mixed> $attrs */
    public function registerNativeButtonStyleRule(
        string $marker,
        array $attrs,
        GeneratedSupportStylesheetState $generatedStyles,
        string $inheritedTextAlignment = '',
        ?DOMElement $sourceControl = null
    ): void {
        $style = ArrayField::array($attrs, 'style');
        $declarations = array();
        $guardedDeclarations = array();
        $wrapperDeclarations = array();
        $outerWrapperDeclarations = array();
        $intrinsicWrapperDeclarations = array();
        $logicalCorners = array( 'border-start-start-radius', 'border-start-end-radius', 'border-end-start-radius', 'border-end-end-radius' );
        $hasLogicalCorners = $sourceControl instanceof DOMElement && (
            array() !== $this->styleResolver->authorDeclaredPropertyValues($sourceControl, $logicalCorners)
            || array() !== array_intersect_key(SourceDeclarations::parse($sourceControl->getAttribute('style')), array_flip($logicalCorners))
        );
        // Core serializes the button's own color, border, typography and spacing
        // attributes inline on the link, where they already outrank every
        // non-important rule. They are not restated here: an !important class
        // copy froze the imported paint and silently defeated the owner's own
        // button controls in the editor and on the frontend.
        if ( $sourceControl instanceof DOMElement ) {
            $sourceDeclarations = SourceDeclarations::parse($this->styleResolver->specificityResolvedPresentationStyle($sourceControl));
            $sourceStructuralDeclarations = $this->styleResolver->classOwnedResponsiveDeclarations(
                $sourceControl,
                $this->styleResolver->structuralPresentationDeclarations($sourceControl)
            );
            // The square-corner fallback is also a radius declaration. It must
            // not recreate a base value when authored conditions own that family.
            $ownsRadius = isset($this->styleResolver->classOwnedResponsiveDeclarations($sourceControl, array('border-radius' => '0'))['border-radius']);
            // Native button attributes preserve only uniform border values. Keep
            // authored side shorthands on the generated link rule so `var()`
            // values retain their width, style, and color after conversion.
            foreach ( array( 'border-top', 'border-right', 'border-bottom', 'border-left' ) as $property ) {
                $value = trim((string) ($sourceDeclarations[$property] ?? ''));
                if ( '' !== $value && ! preg_match('/[{}<>;]/', $value) ) {
                    $declarations[] = $property . ':' . $value . '!important';
                }
            }
            $inlineDeclarations = SourceDeclarations::parse($sourceControl->getAttribute('style'));
            $hasAuthoredWidth = isset($inlineDeclarations['width'])
                || array() !== $this->styleResolver->authorDeclaredPropertyValues($sourceControl, array( 'width' ));
            $definiteAncestorWidth = $hasAuthoredWidth ? '' : $this->definiteAncestorWidth($sourceControl, $sourceDeclarations);
            $stretchingFlex = $this->sourceControlStretchesFlex($sourceDeclarations);
            $height = CssValueInspector::declared($sourceDeclarations, 'height');
            $resolvedBox = $this->styleResolver->resolvedSourceStateDeclarations($sourceControl);
            // Width-fill rules size the source control, not a synthetic carrier.
            // Keep its box model, including the content-box default for links.
            $sourceBoxSizing = $resolvedBox['box-sizing'] ?? ('a' === strtolower($sourceControl->tagName) ? 'content-box' : 'border-box');
            if ( '' !== $definiteAncestorWidth ) {
                $outerWrapperDeclarations[] = 'width:' . $definiteAncestorWidth;
                $outerWrapperDeclarations[] = 'max-width:100%';
                $declarations[] = 'box-sizing:' . $sourceBoxSizing;
                $declarations[] = 'width:100%';
            } elseif ( $stretchingFlex ) {
                // With no source width, a flex share is an outer box size.
                // Only an authored width sizes the source content box.
                $declarations[] = 'box-sizing:' . ($hasAuthoredWidth ? $sourceBoxSizing : 'border-box');
                $declarations[] = 'width:100%';
                $declarations[] = 'max-width:100%';
                $absoluteFill = 'absolute' === CssValueInspector::declared($sourceDeclarations, 'position')
                    && '100%' === CssValueInspector::declared($sourceDeclarations, 'min-width');
                if ( '100%' === $height && ! $absoluteFill ) {
                    $wrapperDeclarations[] = 'height:100%';
                    $declarations[] = 'height:100%!important';
                }
            // An inline-level control sizes to its content, so the wrapper
            // standing in its place has to do the same. A block-level wrapper
            // stretches to its container instead, which both moves the control
            // and steals the width its siblings were sharing. When the control
            // is centered only through inherited text-align, the synthesized
            // core/buttons wrapper restates that centering as flex
            // justification — which needs a wrapper that fills its parent to
            // have room to act, mirroring the full-width block the source
            // centered the control inside of.
            } elseif ( ! $hasAuthoredWidth && ! in_array(CssValueInspector::declared($sourceDeclarations, 'float'), array( '', 'none' ), true) ) {
                // The outer carrier participates in the source float context.
                // Size that float to the decorated link rather than the space
                // left alongside earlier floats (possibly a zero-width slot).
                $outerWrapperDeclarations[] = 'width:fit-content';
                $outerWrapperDeclarations[] = 'max-width:100%';
            } elseif ( ! $hasAuthoredWidth && $this->sourceControlFillsFlowLine($sourceControl, $sourceDeclarations) ) {
                // A block-level control in normal flow spans its container's
                // line. core/buttons is a flex row, which would shrink it to its
                // label instead, so every wrapper layer fills the line.
                $outerWrapperDeclarations[] = 'width:100%';
                $outerWrapperDeclarations[] = 'max-width:100%';
                $wrapperDeclarations[] = 'width:100%';
                $declarations[] = 'box-sizing:' . $sourceBoxSizing;
                $declarations[] = 'width:100%';
            } elseif ( ! $hasAuthoredWidth && $this->sourceControlFillsGridCell($sourceControl, $sourceDeclarations) ) {
                // A block-level control in a grid cell stretches to the cell by
                // default. The wrapper is already a block there, so it needs no
                // width; an intrinsic one would shrink it to its label.
            } elseif ( ! $hasAuthoredWidth && in_array(CssValueInspector::declared($sourceDeclarations, 'display'), array( 'flex', 'inline-flex', 'inline-block', 'inline-grid', 'inline-table' ), true) ) {
                // fit-content already wraps a label within its container; the
                // cap only binds a label that cannot wrap, which the uncapped
                // source control lets overflow.
                $unwrappable = in_array(CssValueInspector::declared($sourceDeclarations, 'white-space'), array( 'nowrap', 'pre' ), true)
                    && '' === CssValueInspector::declared($sourceDeclarations, 'max-width')
                    && array() === $this->styleResolver->authorDeclaredPropertyValues($sourceControl, array( 'max-width' ));
                $fitCap = $unwrappable ? array() : array( 'max-width:100%' );
                if ( 'center' === $inheritedTextAlignment ) {
                    // A flex or grid item shrinks to its content unless told to
                    // fill. In block flow the wrapper already spans its line,
                    // inside the margins the control's rules may give it; a
                    // 100% width would push it past them and off center.
                    if ( ! $this->sourceParentIsBlockContainer($sourceControl) ) {
                        $outerWrapperDeclarations[] = 'width:100%';
                        $outerWrapperDeclarations[] = 'max-width:100%';
                    }
                } else {
                    // Shrink to fit without making the whole label the minimum
                    // width of a content-sized parent (for example a grid track).
                    $outerWrapperDeclarations[] = 'width:fit-content';
                    $outerWrapperDeclarations = array_merge($outerWrapperDeclarations, $fitCap);
                }
                $intrinsicWrapperDeclarations[] = 'width:fit-content';
                $intrinsicWrapperDeclarations = array_merge($intrinsicWrapperDeclarations, $fitCap);
                $declarations[] = 'box-sizing:' . $sourceBoxSizing;
                $declarations[] = 'width:fit-content';
                $declarations = array_merge($declarations, $fitCap);
            }
            if ( ! $this->sourceControlHasVisibleBorder($sourceControl, $sourceDeclarations) ) {
                if ( '' === trim((string) ($style['border']['style'] ?? '')) ) {
                    $guardedDeclarations[':not([style*="border-style"]):not([style*="border-top-style"])'][] = 'border-style:none!important';
                }
                if ( '' === trim((string) ($style['border']['width'] ?? '')) ) {
                    $guardedDeclarations[':not([style*="border-width"]):not([style*="border-top-width"])'][] = 'border-width:0!important';
                }
            }
            $rootFontSize = $this->styleResolver->documentRootFontSize($sourceControl) ?? 16.0;
            $controlOwnsHeight = (new ButtonStyleResolver())->heightNeedsControlBox($resolvedBox, CssLength::toPx($height, $rootFontSize) ?? INF, $rootFontSize);
            if (ButtonStyleResolver::sharesAnchorContainer($sourceControl, $this->styleResolver)) {
                // The shared bar cannot provide an individual percentage height.
                $declarations[] = 'box-sizing:' . $sourceBoxSizing;
            } elseif ( $controlOwnsHeight ) {
                // The positioned or padded control owns this size, not its carriers.
                $declarations[] = 'box-sizing:' . $sourceBoxSizing;
            } elseif ( LayoutParticipation::resolve($sourceControl, $this->styleResolver)->neutralizeButtonWrapper() ) {
                // Both save wrappers are flattened, so no carrier box exists to
                // hold the size: the link is the box and keeps the authored height.
            } elseif ( preg_match('/^(?:\d+(?:\.\d+)?|\.\d+)(?:px|em|rem|vh|vw)$/', $height) ) {
                $wrapperDeclarations[] = 'height:100%';
                $declarations[] = 'height:100%!important';
            } elseif (
                '100%' === $height
                && 'absolute' === CssValueInspector::declared($sourceDeclarations, 'position')
                && '100%' === CssValueInspector::declared($sourceDeclarations, 'min-width')
            ) {
                $outerWrapperDeclarations[] = 'height:100%';
                $wrapperDeclarations[] = 'height:100%';
                $declarations[] = 'height:100%!important';
            } elseif ( self::sourceControlPinsToItsWrapper($sourceDeclarations) ) {
                // The source control takes its box from a sized wrapper by pinning
                // to every edge. Core's link sits in flow instead, so it shrinks to
                // its label unless it is told to fill that same box.
                $outerWrapperDeclarations[] = 'height:100%';
                $wrapperDeclarations[] = 'height:100%';
                $declarations[] = 'width:100%!important';
                $declarations[] = 'height:100%!important';
            }
            foreach ( array( 'border-top-left-radius', 'border-top-right-radius', 'border-bottom-right-radius', 'border-bottom-left-radius' ) as $property ) {
                $value = CssValueInspector::declared($sourceStructuralDeclarations, $property);
                if ( '' !== $value && ! preg_match('/[{}<>;]/', $value) ) {
                    $declarations[] = $property . ':' . $value . '!important';
                }
            }
            if ( $ownsRadius && ( 'a' === strtolower($sourceControl->tagName) || '0' === CssValueInspector::declared($sourceDeclarations, 'border-width') ) && '' === trim((string) ($style['border']['radius'] ?? '')) ) {
                $hasCornerRadius = $hasLogicalCorners;
                foreach ( array( 'border-top-left-radius', 'border-top-right-radius', 'border-bottom-right-radius', 'border-bottom-left-radius', 'border-start-start-radius', 'border-start-end-radius', 'border-end-start-radius', 'border-end-end-radius' ) as $property ) {
                    if ( '' !== trim((string) ($sourceStructuralDeclarations[$property] ?? '')) ) {
                        $hasCornerRadius = true;
                        break;
                    }
                }
                $sourceRadius = trim((string) ($sourceStructuralDeclarations['border-radius'] ?? ''));
                if ( $hasCornerRadius ) {
                    $sourceRadius = '';
                }
                if ( '' !== $sourceRadius && ! preg_match('/[{}<>;]/', $sourceRadius) ) {
                    // A source shorthand radius is the control's own corner geometry,
                    // so it replaces the theme default rather than being squared off.
                    $declarations[] = 'border-radius:' . $sourceRadius . '!important';
                } elseif ( ! $hasCornerRadius ) {
                    // Anchors have square corners by default; core/button applies
                    // the active theme's radius unless it is neutralized.
                    $declarations[] = 'border-radius:0!important';
                }
            }
        }
        if ( $sourceControl instanceof DOMElement ) {
            // Native supports consume paint, and author CSS projects class-owned
            // layout. Inline control layout has no stylesheet rule to project;
            // keep it on the saved link, not its synthetic formatting wrappers.
            $inlineLayout = array_intersect_key(
                SourceDeclarations::parse($sourceControl->getAttribute('style')),
                array_flip(array( 'display', 'flex-direction', 'flex-wrap', 'align-items', 'justify-content', 'gap', 'column-gap', 'row-gap', 'width', 'min-width', 'max-width' ))
            );
            if ( array() !== $inlineLayout ) {
                $inlineLayout = array_intersect_key(
                    $this->styleResolver->authoredControlPresentation($sourceControl, array_keys($inlineLayout))[''] ?? array(),
                    $inlineLayout
                );
                $inlineLayout = array_map(fn (string $value): string => $this->styleResolver->customProperties->resolve($value, $sourceControl), $inlineLayout);
                $inlineLayout = $this->styleResolver->safeVisualDeclarations($inlineLayout);
            }
            foreach ( $inlineLayout as $property => $value ) {
                $declarations[] = $property . ':' . CssValueInspector::withoutImportant($value) . '!important';
            }
            if ( isset($inlineLayout['width']) ) {
                // A source anchor is content-box unless authored otherwise.
                // The width-fill primitive supplies border-box for synthetic
                // carriers; it must not change the padded source control's box.
                $declarations[] = 'box-sizing:' . $sourceBoxSizing . '!important';
            }
        }
        if ( '' !== $inheritedTextAlignment ) {
            $declarations[] = 'text-align:' . $inheritedTextAlignment . '!important';
        }
        $wrapperDisplayRules = '';
        if ( $sourceControl instanceof DOMElement ) {
            $displayRules = $this->styleResolver->authoredControlPresentation($sourceControl, array('display'));
            $hasHiddenState = false;
            foreach ( $displayRules as $properties ) {
                if ( 'none' === CssValueInspector::comparable($properties['display'] ?? '') ) $hasHiddenState = true;
            }
            if ( $hasHiddenState ) {
                // Only the generated wrapper standing in for this single control
                // leaves the parent's layout. A shared buttons row stays visible.
                $selector = '.' . $marker . '.' . $marker . '.wp-block-buttons:has(>.' . $marker . '.wp-block-button:only-child)';
                foreach ( $displayRules as $conditions => $properties ) {
                    if ( ! isset($properties['display']) ) continue;
                    $hidden = 'none' === CssValueInspector::comparable($properties['display']);
                    $rule = $selector . '{display:' . ($hidden ? 'none' : 'block') . '!important}';
                    if ( '' !== $conditions ) $rule = $conditions . '{' . $rule . str_repeat('}', substr_count($conditions, '{') + 1);
                    $wrapperDisplayRules .= $rule;
                }
            }
        }
        if ( array() === $declarations && array() === $guardedDeclarations && '' === $wrapperDisplayRules ) {
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
        $linkSelector = '.' . $marker . '.' . $marker . '>.wp-block-button__link';
        $linkRules = array() === $declarations ? '' : $linkSelector . '{' . implode(';', $declarations) . '}';
        foreach ( $guardedDeclarations as $guard => $guarded ) {
            $linkRules .= $linkSelector . $guard . '{' . implode(';', $guarded) . '}';
        }
        $generatedStyles->registerNativeButton($marker, $outerWrapperRule . $wrapperRule . $intrinsicWrapperRule . $linkRules . $wrapperDisplayRules);
    }

    /**
     * A label chain flattened into the button's plain RichText leaves no
     * element for its author rules to reach. Its white-space family is
     * inherited text layout, so it moves to the link that now holds the text.
     */
    public function registerFlattenedButtonLabelTextLayout(string $marker, DOMElement $sourceControl, GeneratedSupportStylesheetState $generatedStyles): void
    {
        $label = trim($sourceControl->textContent ?? '');
        if ( '' === $label ) return;
        $family = array( 'white-space', 'white-space-collapse', 'text-wrap', 'text-wrap-mode', 'text-wrap-style' );
        $resets = array( 'white-space' => array( 'white-space-collapse', 'text-wrap', 'text-wrap-mode', 'text-wrap-style' ), 'text-wrap' => array( 'text-wrap-mode', 'text-wrap-style' ) );
        $resolved = array();
        foreach ( $sourceControl->getElementsByTagName('*') as $element ) {
            if ( ! $element instanceof DOMElement || trim($element->textContent ?? '') !== $label ) continue;
            $declarations = SourceDeclarations::parse($this->styleResolver->specificityResolvedPresentationStyle($element));
            foreach ( $declarations as $property => $value ) {
                if ( ! in_array($property, $family, true) ) continue;
                $value = trim(preg_replace('/\s*!important\s*$/i', '', (string) $value) ?? '');
                // Custom properties resolve on the source element; one that does not
                // resolve there is invalid at computed-value time and would only reset
                // the family on the link, so it is not presentation.
                if ( str_contains($value, 'var(') ) $value = trim($this->styleResolver->customProperties->resolve($value, $element));
                if ( str_contains($value, 'var(') ) continue;
                if ( '' === $value || preg_match('/[{}<>;]/', $value) || in_array(strtolower($value), array( 'inherit', 'unset', 'revert', 'revert-layer' ), true) ) continue;
                foreach ( $resets[$property] ?? array() as $longhand ) unset($resolved[$longhand]);
                unset($resolved[$property]);
                $resolved[$property] = $value;
            }
        }
        if ( array() === $resolved ) return;
        $rule = array();
        foreach ( $resolved as $property => $value ) $rule[] = $property . ':' . $value . '!important';
        $generatedStyles->appendNativeButton($marker, '.' . $marker . '.' . $marker . '>.wp-block-button__link{' . implode(';', $rule) . '}');
    }

    public function registerNativeButtonDefaultBackgroundGuard(string $marker, DOMElement $sourceControl, GeneratedSupportStylesheetState $generatedStyles, bool $blockHasInlineBackground = false): bool
    {
        if ( $blockHasInlineBackground ) return false;
        $sourceDeclarations = SourceDeclarations::parse(
            $this->styleResolver->specificityResolvedPresentationStyle($sourceControl)
        );
        if ( ! $this->sourceControlNeedsDefaultButtonBackgroundGuard($sourceControl, $sourceDeclarations) ) {
            return false;
        }
        $media = $this->sourceBackgroundGuardMedia($sourceControl);
        if ( null === $media ) return false;
        // A preset background (`has-background`) is the block's own fill too.
        $selector = '.' . $marker . '.' . $marker . '>.wp-block-button__link:not([style*="background"]):not(.has-background)';
        foreach ( $this->styleResolver->sourceBackgroundInteractionStates($sourceControl) as $state ) {
            $selector .= ':not(:' . $state . ')';
        }
        $rule = $selector . '{background-color:transparent!important}';
        if ( '' !== $media ) $rule = '@media ' . $media . '{' . $rule . '}';
        $generatedStyles->appendNativeButton($marker, $rule);
        return true;
    }

    /** @param array<string, string> $sourceDeclarations */
    private function sourceControlNeedsDefaultButtonBackgroundGuard(DOMElement $sourceControl, array $sourceDeclarations): bool
    {
        $background = CssValueInspector::declared($sourceDeclarations, 'background');
        $backgroundColor = CssValueInspector::declared($sourceDeclarations, 'background-color');
        $sourceHasNoFill = '' === $background && '' === $backgroundColor;
        $sourceHasTransparentFill = self::isTransparentBackgroundValue($background)
            || self::isTransparentBackgroundValue($backgroundColor);
        return ( $sourceHasNoFill || $sourceHasTransparentFill ) && ! $this->sourceControlSurfaceIsFilled($sourceControl);
    }

    private function sourceBackgroundGuardMedia(DOMElement $sourceControl): ?string
    {
        $minWidth = null;
        $maxWidth = null;
        foreach ( $this->session->sourceStyleResolutionState()->matchingRules($sourceControl, 'conditional') as $rule ) {
            $hasPaint = false;
            foreach ( array('background', 'background-color') as $property ) {
                $value = CssValueInspector::comparable((string) ($rule['declarations'][$property] ?? ''));
                if ( '' !== $value && ! self::isTransparentBackgroundValue($value) ) {
                    $hasPaint = true;
                    break;
                }
            }
            if ( ! $hasPaint ) continue;
            foreach ( $rule['conditions'] ?? array() as $condition ) {
                if ( ! is_string($condition) || ! preg_match('/^@media\s*\(\s*(max|min)-width\s*:\s*(\d+(?:\.\d+)?)px\s*\)$/i', trim($condition), $matches) ) return null;
                $value = (float) $matches[2];
                if ( 'max' === strtolower($matches[1]) ) $minWidth = max($minWidth ?? 0, (int) floor($value) + 1);
                else $maxWidth = min($maxWidth ?? PHP_INT_MAX, (int) ceil($value) - 1);
            }
        }
        if ( null === $minWidth && null === $maxWidth ) return '';
        if ( null !== $minWidth && null !== $maxWidth && $minWidth > $maxWidth ) return null;
        return \Automattic\BlocksEngine\PhpTransformer\Css\MediaCondition::combine(
            null !== $minWidth ? '(min-width:' . $minWidth . 'px)' : '',
            null !== $maxWidth ? '(max-width:' . $maxWidth . 'px)' : ''
        );
    }


    /**
     * Whether the control renders a fill at the desktop reference viewport.
     *
     * A builder can serialise a control's fill behind a width query, which the
     * static view does not see. The generated link paints over the wrapper that
     * carries that fill, so neutralizing it would hide what the document renders
     * with. This is a classification signal, never a projected value.
     */
    private function sourceControlSurfaceIsFilled(DOMElement $sourceControl): bool
    {
        foreach ( $this->session->sourceStyleResolutionState()->matchingRules($sourceControl, 'static') as $rule ) {
            foreach ( array('background', 'background-color') as $property ) {
                $value = CssValueInspector::comparable((string) ($rule['declarations'][$property] ?? ''));
                if ( '' !== $value && ! self::isTransparentBackgroundValue($value) ) return true;
            }
        }
        $declarations = SourceDeclarations::parse(
            $this->styleResolver->controlSurfaceResolvedStyle($sourceControl)
        );
        foreach ( array( 'background', 'background-color' ) as $property ) {
            $value = CssValueInspector::declared($declarations, $property);
            if ( '' === $value || preg_match('/^(?:0(?:px)?(?:\s+0(?:px)?)*|none|transparent|initial|inherit|unset|revert)(?:\s+none)?$/', $value)
                || self::isTransparentBackgroundValue($value)
            ) {
                continue;
            }

            return true;
        }

        return false;
    }

    private static function isTransparentBackgroundValue(string $value): bool
    {
        return (bool) preg_match('/^(?:0(?:px)?(?:\s+0(?:px)?)*|none|transparent)(?:\s+none)?$/', $value)
            || (bool) preg_match('/^(?:rgba|hsla)\(\s*[^,]+,\s*[^,]+,\s*[^,]+,\s*0(?:\.0+)?%?\s*\)$/i', $value)
            || (bool) preg_match('/^(?:rgb|hsl)\(\s*[^\/]+\/\s*0(?:\.0+)?%?\s*\)$/i', $value);
    }

    /**
     * Whether the control is pinned to every edge of its containing block.
     *
     * @param array<string, string> $sourceDeclarations
     */
    private static function sourceControlPinsToItsWrapper(array $sourceDeclarations): bool
    {
        if ( 'absolute' !== CssValueInspector::declared($sourceDeclarations, 'position') ) {
            return false;
        }
        $inset = CssValueInspector::declared($sourceDeclarations, 'inset');
        if ( 1 === preg_match('/^0(?:px)?(?:\s+0(?:px)?){0,3}$/', $inset) ) {
            return true;
        }
        foreach ( array( 'top', 'right', 'bottom', 'left' ) as $edge ) {
            if ( 1 !== preg_match('/^0(?:px)?$/', CssValueInspector::declared($sourceDeclarations, $edge)) ) {
                return false;
            }
        }

        return true;
    }

    public function registerDirectFlexButton(string $marker, DOMElement $control, GeneratedSupportStylesheetState $generatedStyles): void
    {
        $parent = $control->parentNode;
        // Which axis the parent lays out on is a classification question, and it
        // has to be answered at the reference viewport the rest of the geometry
        // is resolved for. The resting view sees only the mobile-first value, so
        // a `flex-col md:flex-row` container reads as a column, its children read
        // as cross-axis stretched, and a content-sized control gets pinned to
        // `width:100%` for a column it is not in at the width being rendered.
        $parentStyle = $parent instanceof DOMElement
            ? SourceDeclarations::parse($this->styleResolver->controlSurfaceResolvedStyle($parent))
            : array();
        $isColumn = str_starts_with(strtolower(trim((string) ($parentStyle['flex-direction'] ?? 'row'))), 'column');
        $stretchesCrossAxis = $isColumn && $this->columnFlexChildStretches($parentStyle, $control);
        $wrapper = ':where(.' . $marker . '.wp-block-buttons)';
        $button = ':where(.' . $marker . '.wp-block-buttons)>:where(.' . $marker . '.wp-block-button)';
        $link = $button . '>:where(.wp-block-button__link)';
        if ( LayoutParticipation::resolve($control, $this->styleResolver)->intrinsicRatioItem ) {
            // The link must remain the column flex item: its multiline content
            // height feeds the preferred aspect ratio before the percentage
            // minimum is applied. A block carrier breaks that sizing cycle.
            // Core's fixed minimum height also disables the flex item's
            // content-based automatic minimum; restore it along with auto height.
            $intrinsicWidth = CssValueInspector::comparable((string) ($this->styleResolver->resolvedSourceStateDeclarations($control)['width'] ?? 'min-content'));
            $generatedStyles->registerDirectFlexButton(
                $marker,
                $wrapper . '{display:contents!important}'
                    . $button . '{display:contents!important}'
                    . $link . '{box-sizing:border-box;width:' . $intrinsicWidth . '!important;height:auto!important;min-height:auto!important;word-break:normal!important}'
            );
            return;
        }
        // The outer box participates in the authored parent, including its live
        // responsive cross-axis stretching. Intrinsic widths resolved at the
        // reference viewport must not pin it when that parent becomes a column.
        $hasWidth = array() !== ($this->styleResolver->authorDeclaredPropertyValues($control, array('width'))['width'] ?? array());
        $parentDisplays = array((string) ($parentStyle['display'] ?? ''));
        if ( $parent instanceof DOMElement ) {
            $parentDisplays = array_merge($parentDisplays, array_values($this->styleResolver->conditionalDisplayRules($parent)));
        }
        $isFlex = (bool) array_intersect(array_map(CssValueInspector::comparable(...), $parentDisplays), array('flex', 'inline-flex'));
        $isGrid = in_array(CssValueInspector::declared($parentStyle, 'display'), array('grid', 'inline-grid'), true);
        $controlStyle = SourceDeclarations::parse($this->styleResolver->controlSurfaceResolvedStyle($control));
        $alignment = CssValueInspector::comparable((string) ($controlStyle['align-self'] ?? 'auto'));
        if ( 'auto' === $alignment ) $alignment = CssValueInspector::comparable((string) ($parentStyle['align-items'] ?? 'normal'));
        $stretchesHeight = $isGrid
            && in_array($alignment, array('normal', 'stretch'), true)
            && in_array(CssValueInspector::comparable((string) ($controlStyle['height'] ?? 'auto')), array('', 'auto'), true);
        // A grid item's stretched track height reaches the link through flex
        // carriers, since a percentage height cannot resolve against the
        // auto-height block wrapper. Flex rows keep the height:100% path.
        $stretchRule = $stretchesHeight
            ? $wrapper . '{display:flex!important;align-items:stretch!important}'
                . $button . '{display:flex!important;align-items:stretch!important;width:100%;flex:1}'
                . $link . '{height:auto!important}'
            : '';
        // The wrapper stands in for the source control as its parent's flex or
        // grid item. When the source kept CSS's automatic minimum size, the
        // wrapper must keep it too: zeroing it lets the row shrink below its
        // nowrap labels, which lowers an ancestor grid item's min-content
        // contribution and shifts `fr` tracks away from the source resolution.
        $wrapperFloor = self::sourceControlKeepsAutomaticMinimum(
            $controlStyle,
            $this->styleResolver->authorStructuralDeclarations($control)
        ) ? '' : ';min-width:0';
        // Core's link `word-break:break-word` turns every character into a soft
        // wrap opportunity, so the wrapper's min-content contribution collapses
        // to one glyph and a squeezed row stacks the label letter by letter.
        // Restore the initial value at Core's weight; this sheet follows Core's,
        // and an authored word-break projected onto the link still outranks it.
        $generatedStyles->registerDirectFlexButton(
            $marker,
            $wrapper . '{display:block!important;gap:0!important' . $wrapperFloor . '}'
                . $button . '{display:block!important;margin:0!important;min-width:0' . ($stretchesCrossAxis ? ';width:100%!important' : '') . '}'
                . $link . '{box-sizing:border-box' . ($stretchesCrossAxis ? ';width:100%!important' : '') . '}'
                . $button . '>.wp-block-button__link{word-break:normal}'
                . ($hasWidth || ! $isFlex ? '' : $wrapper . '{width:auto!important}')
                . ($isGrid ? $button . '{width:100%}' : '')
                . ($isFlex ? $button . '{width:100%!important;height:100%}' . $link . '{' . ($hasWidth ? '' : 'width:100%!important;') . 'height:100%}' : '')
                . $stretchRule
        );
    }

    /**
     * Whether a flex column's cross axis (its children's inline size) resolves
     * to `stretch` -- the CSS default, since `align-items: normal` computes to
     * `stretch` for flex items -- so a content-sized native button should be
     * widened to fill the row the way its source anchor was. The parent's
     * `align-items` governs this unless the control itself opts out with a
     * non-stretch `align-self`; either one naming a keyword alignment
     * (`center`, `flex-start`, `flex-end`, `baseline`, ...) means the source
     * anchor sized to its own content instead of the row, and the button must
     * keep hugging its label rather than stretching to the container width.
     *
     * @param array<string, string> $parentStyle
     */
    private function columnFlexChildStretches(array $parentStyle, DOMElement $control): bool
    {
        $alignSelf = CssValueInspector::comparable(
            (string) ($this->styleResolver->structuralPresentationDeclarations($control)['align-self'] ?? '')
        );
        if ( '' !== $alignSelf && ! in_array($alignSelf, array( 'stretch', 'auto', 'normal' ), true) ) {
            return false;
        }

        $alignItems = CssValueInspector::declared($parentStyle, 'align-items');

        return '' === $alignItems || in_array($alignItems, array( 'stretch', 'normal' ), true);
    }

    /**
     * Whether the source control keeps CSS's automatic minimum size in its
     * parent's flex/grid track sizing: a `min-width` of `auto` (or none at
     * all) with visible overflow resolves the item's minimum to its own
     * min-content size. The participating wrapper inherits that floor by
     * simply staying at `min-width:auto` — the same default the source anchor
     * had — so an ancestor track keeps resolving to the source minimum.
     *
     * When the source itself suppressed the automatic minimum — an explicit
     * zero `min-width`, or an inline-axis `overflow` other than `visible` —
     * the wrapper must restate `min-width:0`, because the authored zero rides
     * the projected link rule and a fresh wrapper box would otherwise floor at
     * its content. A nonzero explicit `min-width` also rides the link, where
     * it raises the chain floor above min-content, so the wrapper needs no
     * declaration of its own. A `var()` value cannot be proven zero
     * statically and keeps the source default, mirroring
     * {@see self::sourceControlHasVisibleBorder()}.
     *
     * `min-width` is read from the resting reference-viewport cascade.
     * `overflow` never reaches that cascade (it is outside the
     * classification allow-list), so it is read from the raw author
     * declaration stream, where any viewport stating a clipping inline-axis
     * overflow suppresses the automatic minimum.
     *
     * @param array<string, string> $restingDeclarations
     * @param array<string, string> $authorDeclarations
     */
    private static function sourceControlKeepsAutomaticMinimum(array $restingDeclarations, array $authorDeclarations): bool
    {
        $minWidth = CssValueInspector::declared($restingDeclarations, 'min-width');
        if ( '' !== $minWidth && 'auto' !== $minWidth && ! str_contains($minWidth, 'var(') ) {
            return CssValueInspector::isNonZero($minWidth);
        }
        foreach ( array( 'overflow', 'overflow-x' ) as $property ) {
            $overflow = strtolower(trim((string) ($restingDeclarations[$property] ?? $authorDeclarations[$property] ?? '')));
            if ( '' === $overflow || str_contains($overflow, 'var(') ) {
                continue;
            }
            // The shorthand's first token is the inline axis that governs
            // min-width; any other value there than `visible` (or a keyword
            // that computes to it) zeroes the automatic minimum.
            $inlineAxis = preg_split('/\s+/', $overflow) ?: array( '' );
            if ( '' !== $inlineAxis[0] && ! in_array($inlineAxis[0], array( 'visible', 'unset', 'initial', 'inherit', 'revert' ), true) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Neutralized wrappers are `display:contents`, so the link is the box that
     * participates in the source layout and carries the width itself. Restating
     * `display:block` on those wrappers would reinstate boxes the source never
     * had and stop the link from stretching across its grid or flex line.
     */
    public function registerButtonWidth(string $marker, int $width, GeneratedSupportStylesheetState $generatedStyles, bool $wrappersNeutralized = false): void
    {
        $wrapper = ':where(.' . $marker . '.wp-block-buttons)';
        $button = ':where(.' . $marker . '.wp-block-buttons)>:where(.' . $marker . '.wp-block-button)';
        $link = $button . '>:where(.wp-block-button__link)';
        $rule = $wrappersNeutralized
            ? $link . '{box-sizing:border-box;width:' . (string) $width . '%!important}'
            : ( 100 !== $width
            ? $button . '{width:' . (string) $width . '%!important}' . $link . '{box-sizing:border-box;width:100%!important}'
            : $wrapper . '{display:block!important;gap:0!important;width:100%!important}'
                . $button . '{display:block!important;margin:0!important;width:100%!important}'
                . $link . '{box-sizing:border-box;width:100%!important}' );
        $generatedStyles->registerButtonWidth($marker, $rule);
    }

    /**
     * Decide whether the source contributes any visible border, including
     * conditional author rules and physical or logical border longhands. The
     * neutralizer must only fire when none of them do — a stale `border: 0`
     * reset must not force-kill a real border declared later in the cascade
     * through longhands, which is exactly what happens with `var()`-driven
     * custom-property borders. A longhand whose value references `var(...)`
     * cannot be proven zero statically, so it is treated as visible.
     *
     * @param array<string, string> $sourceDeclarations
     */
    private function sourceControlHasVisibleBorder(DOMElement $sourceControl, array $sourceDeclarations): bool
    {
        $sides = array( 'border', 'border-top', 'border-right', 'border-bottom', 'border-left', 'border-inline', 'border-inline-start', 'border-inline-end', 'border-block', 'border-block-start', 'border-block-end' );
        $properties = $sides;
        foreach ( $sides as $side ) {
            $properties[] = $side . '-width';
            $properties[] = $side . '-style';
        }
        // This is only a reset guard, not presentation projection: a border at
        // any condition must stay under author ownership, even off-reference.
        $conditional = $this->styleResolver->conditionalAuthorDeclaredPropertyValues($sourceControl, $properties);
        // Logical properties are not all in the classification cascade's allow-list.
        $logicalProperties = array_values(array_filter($properties, static fn (string $property): bool => str_starts_with($property, 'border-inline') || str_starts_with($property, 'border-block')));
        $declared = array_merge($conditional, $this->styleResolver->authorDeclaredValuesAtAnyViewport($sourceControl, $logicalProperties));
        foreach ( $declared as $property => $values ) {
            foreach ( $values as $value ) {
                if ( self::borderDeclarationsHaveVisibleBorder(array($property => $value)) ) {
                    return true;
                }
            }
        }

        return self::borderDeclarationsHaveVisibleBorder($sourceDeclarations);
    }

    /** @param array<string, mixed> $sourceDeclarations */
    private static function borderDeclarationsHaveVisibleBorder(array $sourceDeclarations): bool
    {
        $sides = array( 'border-top', 'border-right', 'border-bottom', 'border-left', 'border-inline', 'border-inline-start', 'border-inline-end', 'border-block', 'border-block-start', 'border-block-end' );
        $widths = array_merge(array('border-width'), array_map(static fn (string $side): string => $side . '-width', $sides));
        $styles = array_merge(array('border-style'), array_map(static fn (string $side): string => $side . '-style', $sides));
        // A reset can pair border-style:solid with zero width. Style alone
        // cannot paint a border; only later nonzero side widths can override it.
        $width = CssValueInspector::declared($sourceDeclarations, 'border-width');
        if ( '' !== $width && 1 === preg_match('/^0(?:px)?(?:\s+0(?:px)?){0,3}$/', $width) ) {
            $nonzeroSide = false;
            foreach ( array_diff($widths, array('border-width')) as $property ) {
                $value = CssValueInspector::declared($sourceDeclarations, $property);
                $nonzeroSide = $nonzeroSide || ('' !== $value && CssValueInspector::isNonZero($value));
            }
            foreach ( array_merge(array('border'), $sides) as $property ) {
                $value = CssValueInspector::declared($sourceDeclarations, $property);
                $nonzeroSide = $nonzeroSide || ('' !== $value && self::borderSideShorthandIsVisible($value));
            }
            if ( ! $nonzeroSide ) {
                return false;
            }
        }
        $shorthand = CssValueInspector::declared($sourceDeclarations, 'border');
        if ( '' !== $shorthand && ! preg_match('/^(?:0(?:px)?|none)$/', $shorthand) ) {
            return true;
        }

        foreach ( $sides as $property ) {
            $value = CssValueInspector::declared($sourceDeclarations, $property);
            if ( '' !== $value && self::borderSideShorthandIsVisible($value) ) {
                return true;
            }
        }

        foreach ( $widths as $property ) {
            $value = CssValueInspector::declared($sourceDeclarations, $property);
            if ( '' !== $value && ( str_contains($value, 'var(') || str_contains($value, 'calc(') || CssValueInspector::isNonZero($value) ) ) {
                return true;
            }
        }

        foreach ( $styles as $property ) {
            $value = CssValueInspector::declared($sourceDeclarations, $property);
            if ( '' === $value ) {
                continue;
            }
            if ( str_contains($value, 'var(') ) {
                return true;
            }
            foreach ( preg_split('/\s+/', $value) ?: array() as $token ) {
                if ( '' !== $token && ! in_array($token, array( 'none', 'hidden' ), true) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /** A `border-top|right|bottom|left` shorthand value is visible unless it resolves to no width or a `none`/`hidden` style. */
    private static function borderSideShorthandIsVisible(string $value): bool
    {
        if ( str_contains($value, 'var(') || str_contains($value, 'calc(') ) {
            return true;
        }
        if ( preg_match('/^(?:0(?:px)?|none)$/', $value) ) {
            return false;
        }
        $styleKeywords = array( 'none', 'hidden', 'dotted', 'dashed', 'solid', 'double', 'groove', 'ridge', 'inset', 'outset' );
        $styleToken = null;
        foreach ( CssValueSplitter::splitTopLevelWhitespace($value) as $token ) {
            $token = strtolower($token);
            if ( in_array($token, $styleKeywords, true) ) {
                $styleToken = $token;
                break;
            }
        }
        // No explicit style component means the shorthand resets border-*-style to its
        // initial value of `none`, so the side has no visible border.
        return null !== $styleToken && ! in_array($styleToken, array( 'none', 'hidden' ), true);
    }

    /**
     * A display:block control whose parent lays it out in normal block flow.
     *
     * @param array<string, string> $sourceDeclarations
     */
    private function sourceControlFillsFlowLine(DOMElement $sourceControl, array $sourceDeclarations): bool
    {
        // `display:flex` and friends are block-level too: they span the line
        // exactly like a block. Only the `inline-*` forms size to content.
        // A `<button>` keeps its fit-content width as a flex or grid
        // container, so only other controls span the line that way.
        $display = CssValueInspector::declared($sourceDeclarations, 'display');
        $blockLevel = 'button' === strtolower($sourceControl->tagName) ? array( 'block' ) : array( 'block', 'flex', 'grid', 'table' );
        if ( ! in_array($display, $blockLevel, true) ) {
            return false;
        }
        $float = CssValueInspector::declared($sourceDeclarations, 'float');
        $position = CssValueInspector::declared($sourceDeclarations, 'position');
        if ( in_array($float, array( 'left', 'right' ), true) || in_array($position, array( 'absolute', 'fixed' ), true) ) {
            return false;
        }
        $parent = $sourceControl->parentNode;
        if ( ! $parent instanceof DOMElement ) {
            return false;
        }
        $parentDisplay = CssValueInspector::comparable((string) (SourceDeclarations::parse($this->styleResolver->specificityResolvedPresentationStyle($parent))['display'] ?? 'block'));
        return in_array($parentDisplay, array( '', 'block', 'flow-root', 'list-item' ), true);
    }

    private function sourceParentIsBlockContainer(DOMElement $sourceControl): bool
    {
        $parent = $sourceControl->parentNode;
        if ( ! $parent instanceof DOMElement ) {
            return false;
        }
        $display = CssValueInspector::comparable((string) (SourceDeclarations::parse($this->styleResolver->specificityResolvedPresentationStyle($parent))['display'] ?? ''));

        return in_array($display, array( '', 'block', 'flow-root', 'list-item', 'inline-block', 'table-cell' ), true);
    }

    private function sourceControlFillsGridCell(DOMElement $sourceControl, array $sourceDeclarations): bool
    {
        if ( ! in_array(CssValueInspector::declared($sourceDeclarations, 'display'), array( 'flex', 'grid', 'table' ), true) ) {
            return false;
        }
        if ( in_array(CssValueInspector::declared($sourceDeclarations, 'position'), array( 'absolute', 'fixed' ), true) ) {
            return false;
        }
        if ( ! in_array(CssValueInspector::declared($sourceDeclarations, 'justify-self'), array( '', 'auto', 'normal', 'stretch' ), true) ) {
            return false;
        }
        $parent = $sourceControl->parentNode;
        if ( ! $parent instanceof DOMElement ) {
            return false;
        }
        $parentDisplay = CssValueInspector::comparable((string) (SourceDeclarations::parse($this->styleResolver->specificityResolvedPresentationStyle($parent))['display'] ?? ''));

        return 'grid' === $parentDisplay;
    }

    private function sourceControlStretchesFlex(array $sourceDeclarations): bool
    {
        $display = CssValueInspector::declared($sourceDeclarations, 'display');
        if ( ! in_array($display, array( 'flex', 'inline-flex' ), true) ) {
            return false;
        }
        $height = CssValueInspector::declared($sourceDeclarations, 'height');
        $width = CssValueInspector::declared($sourceDeclarations, 'width');
        $flexGrow = CssValueInspector::declared($sourceDeclarations, 'flex-grow');
        $flex = CssValueInspector::declared($sourceDeclarations, 'flex');

        return '100%' === $height
            || '100%' === $width
            || 1 === preg_match('/^[1-9]/', $flexGrow)
            || 1 === preg_match('/^[1-9]/', $flex);
    }

    /**
     * The px width of the nearest sized ancestor, when the control actually
     * fills it. Only a block-level control spans its container, and only
     * through ancestors in block flow: an inline-level control, or any
     * shrink-to-fit, flex, floated or out-of-flow box on the way up, sizes the
     * control to its content instead of the sized ancestor.
     *
     * @param array<string, string> $sourceDeclarations
     */
    private function definiteAncestorWidth(DOMElement $sourceControl, array $sourceDeclarations): string
    {
        if ( ! $this->sourceControlFillsFlowLine($sourceControl, $sourceDeclarations) ) {
            return '';
        }
        for ( $ancestor = $sourceControl->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode ) {
            $declarations = $this->styleResolver->presentationDeclarations($ancestor);
            $width = CssValueInspector::declared($declarations, 'width');
            if ( preg_match('/^(?:\d+(?:\.\d+)?)px$/', $width) ) {
                return $width;
            }
            if ( ! in_array(CssValueInspector::declared($declarations, 'display'), array( '', 'block', 'flow-root', 'list-item' ), true)
                || in_array(CssValueInspector::declared($declarations, 'float'), array( 'left', 'right' ), true)
                || in_array(CssValueInspector::declared($declarations, 'position'), array( 'absolute', 'fixed' ), true) ) {
                return '';
            }
        }

        return '';
    }

    private static function isInheritedCssWideValue(string $value): bool
    {
        return in_array(strtolower(trim($value)), array( 'inherit', 'unset' ), true);
    }
}

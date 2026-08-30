<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMElement;

/** Projects unsupported block styles into deterministic generated CSS. */
final class GeneratedBlockStyleProjector
{
    public function __construct(
        private readonly Runtime $runtime,
        private readonly StyleResolver $styleResolver
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
        $normalized = $this->runtime->normalizeBlockSupportAttributes($name, $attrs);
        $fallback = $normalized['fallbackStyle'];
        $attrs = $normalized['attrs'];
        $submenuBackground = 'core/navigation-submenu' === $name ? trim((string) ($fallback['color']['background'] ?? '')) : '';
        if ( '' !== $submenuBackground && array() !== $this->styleResolver->cssDeclarations('background-color:' . $submenuBackground) ) {
            $className = 'blocks-engine-navigation-submenu-background-' . hash('sha256', $submenuBackground);
            $attrs['className'] = self::mergeClassNames((string) ($attrs['className'] ?? ''), $className);
            $generatedStyles->registerNavigationSubmenuBackground($className, $submenuBackground);
        }
        $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array();
        if ( 'core/navigation' === $name && is_array($fallback['spacing']['padding'] ?? null) ) {
            foreach ( $classes as $class ) {
                if ( 'blocks-engine-list-navigation' !== $class && ! str_starts_with($class, 'blocks-engine-') ) {
                    $generatedStyles->registerListNavigationPadding($class, $fallback['spacing']['padding']);
                }
            }
        }
        if ( 'core/navigation' === $name && is_array($fallback['spacing'] ?? null) ) {
            $declarations = $this->styleResolver->styleAttributeMapper()->serialize(array( 'spacing' => $fallback['spacing'] ))['style'];
            foreach ( $classes as $class ) {
                if ( '' !== $declarations && 'blocks-engine-list-navigation' !== $class && ! str_starts_with($class, 'blocks-engine-') ) {
                    $generatedStyles->registerNavigationSpacing($class, $declarations);
                    break;
                }
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
        $fallbackDeclarations = $this->styleResolver->cssDeclarations($fallbackStyle);
        $inlineDeclarations = $this->styleResolver->cssDeclarations($sourceElement->getAttribute('style'));
        $inlineMapped = $this->styleResolver->styleAttributeMapper()->map($inlineDeclarations);
        $inlineFallbackDeclarations = $this->styleResolver->cssDeclarations($this->styleResolver->styleAttributeMapper()->serialize($inlineMapped['style'] ?? array())['style']);
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
            foreach ( $this->styleResolver->inlineGeometryProperties() as $property ) {
                unset($fallbackDeclarations[$property]);
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
            $attrs['className'] = self::mergeClassNames((string) ($attrs['className'] ?? ''), $carrier);
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
        $style = is_array($attrs['style'] ?? null) ? $attrs['style'] : array();
        $declarations = array();
        $wrapperDeclarations = array();
        $outerWrapperDeclarations = array();
        $intrinsicWrapperDeclarations = array();
        foreach ( array(
            'background-color' => $style['color']['background'] ?? '',
            'color' => $style['color']['text'] ?? '',
            'border-color' => $style['border']['color'] ?? '',
            'border-style' => $style['border']['style'] ?? '',
            'border-width' => $style['border']['width'] ?? '',
            'border-radius' => $style['border']['radius'] ?? '',
            'font-size' => $style['typography']['fontSize'] ?? '',
            'font-weight' => $style['typography']['fontWeight'] ?? '',
            'letter-spacing' => $style['typography']['letterSpacing'] ?? '',
            'line-height' => $style['typography']['lineHeight'] ?? '',
            'text-transform' => $style['typography']['textTransform'] ?? '',
            'padding-top' => $style['spacing']['padding']['top'] ?? '',
            'padding-right' => $style['spacing']['padding']['right'] ?? '',
            'padding-bottom' => $style['spacing']['padding']['bottom'] ?? '',
            'padding-left' => $style['spacing']['padding']['left'] ?? '',
        ) as $property => $value ) {
            $value = trim((string) $value);
            if ( '' !== $value && ! preg_match('/[{}<>;]/', $value) ) {
                $declarations[] = $property . ':' . $value . '!important';
            }
        }
        if ( $sourceControl instanceof DOMElement ) {
            $sourceDeclarations = $this->styleResolver->cssDeclarations($this->styleResolver->specificityResolvedPresentationStyle($sourceControl));
            $sourceStructuralDeclarations = $this->styleResolver->structuralPresentationDeclarations($sourceControl);
            $inlineDeclarations = $this->styleResolver->cssDeclarations($sourceControl->getAttribute('style'));
            $hasAuthoredWidth = isset($inlineDeclarations['width'])
                || array() !== $this->styleResolver->authorDeclaredPropertyValues($sourceControl, array( 'width' ));
            if ( ! $hasAuthoredWidth && in_array(CssValueInspector::comparable((string) ($sourceDeclarations['display'] ?? '')), array( 'flex', 'inline-flex' ), true) ) {
                $outerWrapperDeclarations[] = 'width:max-content';
                $outerWrapperDeclarations[] = 'max-width:100%';
                $intrinsicWrapperDeclarations[] = 'width:max-content';
                $intrinsicWrapperDeclarations[] = 'max-width:100%';
                $declarations[] = 'box-sizing:border-box';
                $declarations[] = 'width:max-content';
                $declarations[] = 'max-width:100%';
            }
            $background = CssValueInspector::comparable((string) ($sourceDeclarations['background'] ?? ''));
            if ( '' === trim((string) ($style['color']['background'] ?? '')) && preg_match('/^(?:0(?:px)?(?:\s+0(?:px)?)*|none|transparent)(?:\s+none)?$/', $background) ) {
                $declarations[] = 'background-color:transparent!important';
            }
            $border = CssValueInspector::comparable((string) ($sourceDeclarations['border'] ?? ''));
            if ( preg_match('/^(?:0(?:px)?|none)$/', $border) ) {
                if ( '' === trim((string) ($style['border']['style'] ?? '')) ) {
                    $declarations[] = 'border-style:none!important';
                }
                if ( '' === trim((string) ($style['border']['width'] ?? '')) ) {
                    $declarations[] = 'border-width:0!important';
                }
            }
            $height = CssValueInspector::comparable((string) ($sourceDeclarations['height'] ?? ''));
            if ( preg_match('/^(?:\d+(?:\.\d+)?|\.\d+)(?:px|em|rem|vh|vw)$/', $height) ) {
                $wrapperDeclarations[] = 'height:100%';
                $declarations[] = 'height:100%!important';
            }
            foreach ( array( 'border-top-left-radius', 'border-top-right-radius', 'border-bottom-right-radius', 'border-bottom-left-radius' ) as $property ) {
                $value = CssValueInspector::comparable((string) ($sourceStructuralDeclarations[$property] ?? ''));
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
        $generatedStyles->registerNativeButton($marker, $outerWrapperRule . $wrapperRule . $intrinsicWrapperRule . '.' . $marker . '.' . $marker . '>.wp-block-button__link{' . implode(';', $declarations) . '}');
    }

    public function registerDirectFlexButton(string $marker, DOMElement $control, GeneratedSupportStylesheetState $generatedStyles): void
    {
        $parent = $control->parentNode;
        $parentStyle = $parent instanceof DOMElement ? $this->styleResolver->structuralPresentationDeclarations($parent) : array();
        $isColumn = str_starts_with(strtolower(trim((string) ($parentStyle['flex-direction'] ?? 'row'))), 'column');
        $wrapper = ':where(.' . $marker . '.wp-block-buttons)';
        $button = ':where(.' . $marker . '.wp-block-buttons)>:where(.' . $marker . '.wp-block-button)';
        $link = $button . '>:where(.wp-block-button__link)';
        $columnGeometry = $isColumn ? ';width:100%!important' : '';
        $generatedStyles->registerDirectFlexButton(
            $marker,
            $wrapper . '{display:block!important;gap:0!important;min-width:0' . $columnGeometry . '}'
                . $button . '{display:block!important;margin:0!important;min-width:0' . $columnGeometry . '}'
                . $link . '{box-sizing:border-box' . ($isColumn ? ';width:100%!important' : '') . '}'
        );
    }

    public function registerButtonWidth(string $marker, int $width, GeneratedSupportStylesheetState $generatedStyles): void
    {
        $wrapper = ':where(.' . $marker . '.wp-block-buttons)';
        $button = ':where(.' . $marker . '.wp-block-buttons)>:where(.' . $marker . '.wp-block-button)';
        $link = $button . '>:where(.wp-block-button__link)';
        $rule = 100 !== $width
            ? $button . '{width:' . (string) $width . '%!important}' . $link . '{box-sizing:border-box;width:100%!important}'
            : $wrapper . '{display:block!important;gap:0!important;width:100%!important}'
                . $button . '{display:block!important;margin:0!important;width:100%!important}'
                . $link . '{box-sizing:border-box;width:100%!important}';
        $generatedStyles->registerButtonWidth($marker, $rule);
    }

    private static function isInheritedCssWideValue(string $value): bool
    {
        return in_array(strtolower(trim($value)), array( 'inherit', 'unset' ), true);
    }

    private static function mergeClassNames(string ...$classNames): string
    {
        $classes = array();
        foreach ( $classNames as $className ) {
            foreach ( preg_split('/\s+/', trim($className)) ?: array() as $class ) {
                if ( '' !== $class && ! in_array($class, $classes, true) ) {
                    $classes[] = $class;
                }
            }
        }
        return implode(' ', $classes);
    }
}

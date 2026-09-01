<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\ShellLandmarkPolicy;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/** Projects source identities and structural carriers onto canonical block attributes. */
final class SourceBlockAttributeProjector
{
    public const SYNTHETIC_PARAGRAPH_CLASS = 'blocks-engine-synthetic-paragraph';
    public const HIDDEN_RICH_TEXT_MARKER_CLASS = 'blocks-engine-hidden-richtext-marker';
    public const SYNTHETIC_ANCHOR_UNDECORATED_CLASS = 'blocks-engine-synthetic-anchor-undecorated';
    public const SYNTHETIC_IMAGE_FIGURE_CLASS = 'blocks-engine-synthetic-image-figure';
    public const CSS_OWNED_INLINE_FLOW_CLASS = 'blocks-engine-css-owned-inline-flow';
    public const CSS_OWNED_LAYOUT_ITEM_CLASS = 'blocks-engine-css-owned-layout-item';

    private const SYNTHETIC_HEADER_ANCHOR_CLASS_PREFIX = 'blocks-engine-synthetic-header-anchor-';

    public function __construct(
        private readonly StyleResolver $styleResolver,
        private readonly GeneratedBlockStyleProjector $generatedStyleProjector
    ) {}

    /**
     * @param array<string, mixed>              $attrs
     * @param array<int, array<string, mixed>> $innerBlocks
     * @return array<string, mixed>
     */
    public function project(
        string $name,
        array $attrs,
        array $innerBlocks,
        DOMElement $sourceElement,
        DOMElement $logicalSourceElement,
        SourceBlockAttributeProjectionFacts $facts,
        SourceBlockAttributeProjectionContext $context
    ): array {
        $sourceTagName = strtolower($sourceElement->tagName);
        if ( 'core/image' === $name && 'figure' !== $sourceTagName ) {
            $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), self::SYNTHETIC_IMAGE_FIGURE_CLASS);
        }
        if ( 'core/paragraph' === $name && $facts->isInlineSourceElement ) {
            $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), self::SYNTHETIC_PARAGRAPH_CLASS);
            if ( 'a' === $sourceTagName && $this->sourceAnchorHasNoTextDecoration($sourceElement) ) {
                $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), self::SYNTHETIC_ANCHOR_UNDECORATED_CLASS);
            }
            if ( 'a' === $sourceTagName ) {
                $attrs = $this->withSyntheticHeaderAnchorCarrier($attrs, $sourceElement, $context->generatedStyles);
            }
        }
        $projectionClassName = $this->sourceProjectionClassName($sourceElement, $context, (string) ($attrs['className'] ?? ''));
        if ( '' !== $projectionClassName ) {
            $attrs['className'] = $projectionClassName;
        }
        if ( 'core/group' === $name && $facts->isAuthorLayoutItem ) {
            $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), self::CSS_OWNED_LAYOUT_ITEM_CLASS);
        }
        if ( 'core/group' === $name && $this->isAtomicInlineChildFlow($sourceElement, $innerBlocks) ) {
            $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), self::CSS_OWNED_INLINE_FLOW_CLASS);
        }
        if ( 'core/group' === $name && 'grid' === (string) ($attrs['layout']['type'] ?? '') ) {
            $gapCarrier = $this->styleResolver->inlineGeometryClassName($sourceElement, array(), array( 'gap' ));
            if ( '' !== $gapCarrier ) {
                $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $gapCarrier);
            }
        }
        $tableMarker = $context->selectorProjections->tableMarker($sourceElement->getNodePath() ?? '');
        if ( 'core/table' === $name && '' !== $tableMarker ) {
            $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $tableMarker);
        }

        $attrs = $this->projectButtonAttributes($name, $attrs, $sourceElement, $logicalSourceElement, $facts, $context);
        $attrs = $this->generatedStyleProjector->applyDeclaredBlockSupport(
            $name,
            $attrs,
            $sourceElement,
            $context->generatedStyles,
            $facts->preserveGeneratedStyle
        );

        if ( 'core/group' === $name && ! isset($attrs['tagName']) ) {
            $semanticTag = self::semanticGroupTagName($sourceElement);
            if ( null !== $semanticTag ) {
                $attrs['tagName'] = $semanticTag;
            }
        }
        return $attrs;
    }

    public function sourceProjectionClassName(DOMElement $element, SourceBlockAttributeProjectionContext $context, string $className = ''): string
    {
        $sourceTagMarker = $context->selectorProjections->tagMarker(strtolower($element->tagName));
        if ( '' !== $sourceTagMarker ) {
            $className = SourceDom::mergeClassNames($className, $sourceTagMarker);
        }
        if ( $element->parentNode instanceof DOMElement
            && 'body' === strtolower($element->parentNode->tagName)
            && array() !== $context->authorStyles->sourceBodyProjectionClasses()
        ) {
            $className = SourceDom::mergeClassNames($className, ...$context->authorStyles->sourceBodyProjectionClasses());
        }
        $semanticMarkers = $context->selectorProjections->semanticMarkersForPath($element->getNodePath() ?? '');
        if ( array() !== $semanticMarkers ) {
            $className = SourceDom::mergeClassNames($className, ...$semanticMarkers);
        }
        return $className;
    }

    /** @param array<string, mixed> $attrs @return array<string, mixed> */
    private function projectButtonAttributes(
        string $name,
        array $attrs,
        DOMElement $sourceElement,
        DOMElement $logicalControl,
        SourceBlockAttributeProjectionFacts $facts,
        SourceBlockAttributeProjectionContext $context
    ): array {
        $logicalControlPath = $logicalControl->getNodePath() ?? '';
        $presentationPath = $sourceElement->getNodePath() ?? '';
        $nativeButtonTextAlignment = '';
        $hasNativeButtonColor = false;
        $hasNativeButtonStyle = false;
        if ( 'core/button' === $name && in_array(strtolower($logicalControl->tagName), array( 'a', 'button' ), true) ) {
            $nativeButtonProjection = $this->generatedStyleProjector->projectNativeButtonInheritedStyle(
                $logicalControl,
                $attrs,
                'a' === strtolower($logicalControl->tagName) && ($sourceElement === $logicalControl || $sourceElement->parentNode === $logicalControl)
            );
            $attrs = $nativeButtonProjection['attrs'];
            if ( $this->buttonLabelHasAuthoredColor($sourceElement, $logicalControl, (string) ($attrs['text'] ?? ''), $presentationPath, $logicalControlPath) ) {
                unset($attrs['style']['color']['text']);
                if ( array() === ($attrs['style']['color'] ?? null) ) {
                    unset($attrs['style']['color']);
                }
                if ( array() === ($attrs['style'] ?? null) ) {
                    unset($attrs['style']);
                }
            }
            $nativeButtonTextAlignment = $nativeButtonProjection['text_alignment'];
            $hasNativeButtonColor = $nativeButtonProjection['color_changed'];
            $hasNativeButtonStyle = '' !== $nativeButtonTextAlignment || $hasNativeButtonColor;
        }
        if ( $facts->hasAuthorControlProjection ) {
            $controlMarker = '' !== $logicalControlPath ? $context->selectorProjections->ensureControlMarker($logicalControlPath) : '';
            if ( '' !== $controlMarker ) {
                $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $controlMarker);
                if ( 'core/button' === $name ) {
                    $this->generatedStyleProjector->registerNativeButtonStyleRule($controlMarker, $attrs, $context->generatedStyles, $nativeButtonTextAlignment, $logicalControl);
                    if ( $facts->isDirectChildOfAuthorFlexLayout ) {
                        $this->generatedStyleProjector->registerDirectFlexButton($controlMarker, $logicalControl, $context->generatedStyles);
                    }
                    self::registerButtonWidth($attrs, $controlMarker, $context, $this->generatedStyleProjector);
                }
            }
            if ( '' !== $controlMarker && '' !== $presentationPath && $presentationPath !== $logicalControlPath ) {
                $context->selectorProjections->installButtonPresentationMarker($presentationPath, $controlMarker);
            }
            // The element that renders the label is styled separately from the
            // control that boxes it, and core/button renders the label inside
            // the link. Register it too, so rules written for the label reach
            // the link instead of leaving the box's own colour to paint text.
            if ( '' !== $controlMarker ) {
                foreach ( self::buttonLabelPaths($sourceElement, $logicalControl, (string) ($attrs['text'] ?? '')) as $labelPath ) {
                    if ( $labelPath !== $logicalControlPath && $labelPath !== $presentationPath ) {
                        $context->selectorProjections->installButtonPresentationMarker($labelPath, $controlMarker);
                    }
                }
            }
        }
        if ( 'core/button' === $name && $hasNativeButtonStyle && '' === $context->selectorProjections->controlMarker($logicalControlPath) ) {
            $nativeButtonMarker = $hasNativeButtonColor
                ? $context->authorStyles->allocateMarker('native-button')
                : 'blocks-engine-native-button-alignment-' . $nativeButtonTextAlignment;
            $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $nativeButtonMarker);
            $this->generatedStyleProjector->registerNativeButtonStyleRule($nativeButtonMarker, $hasNativeButtonColor ? $attrs : array(), $context->generatedStyles, $nativeButtonTextAlignment);
            self::registerButtonWidth($attrs, $nativeButtonMarker, $context, $this->generatedStyleProjector);
        }
        return $attrs;
    }

    /**
     * Paths of the elements that render a button's visible label.
     *
     * @return array<int, string>
     */
    private static function buttonLabelPaths(DOMElement $sourceElement, ?DOMElement $logicalControl, string $label): array
    {
        $label = trim(html_entity_decode(strip_tags($label), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        if ( '' === $label ) {
            return array();
        }

        $paths = array();
        foreach ( array( $sourceElement, $logicalControl ) as $host ) {
            if ( ! $host instanceof DOMElement ) {
                continue;
            }
            foreach ( $host->getElementsByTagName('*') as $candidate ) {
                if ( ! $candidate instanceof DOMElement ) {
                    continue;
                }
                if ( trim($candidate->textContent ?? '') !== $label ) {
                    continue;
                }
                $path = $candidate->getNodePath() ?? '';
                if ( '' !== $path ) {
                    $paths[$path] = true;
                }
            }
        }

        return array_keys($paths);
    }

    private function buttonLabelHasAuthoredColor(
        DOMElement $sourceElement,
        ?DOMElement $logicalControl,
        string $label,
        string $presentationPath,
        string $logicalControlPath
    ): bool
    {
        $paths = array_fill_keys(self::buttonLabelPaths($sourceElement, $logicalControl, $label), true);
        if ( array() === $paths ) {
            return false;
        }
        foreach ( array( $sourceElement, $logicalControl ) as $host ) {
            if ( ! $host instanceof DOMElement ) {
                continue;
            }
            foreach ( $host->getElementsByTagName('*') as $candidate ) {
                $candidatePath = $candidate instanceof DOMElement ? ($candidate->getNodePath() ?? '') : '';
                if ( $candidate instanceof DOMElement
                    && $candidatePath !== $presentationPath
                    && $candidatePath !== $logicalControlPath
                    && isset($paths[$candidatePath])
                    && array() !== $this->styleResolver->authorDeclaredPropertyValues($candidate, array( 'color' ))
                ) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @param array<string, mixed> $attrs */
    private static function registerButtonWidth(array $attrs, string $marker, SourceBlockAttributeProjectionContext $context, GeneratedBlockStyleProjector $generatedStyleProjector): void
    {
        $buttonWidth = (int) ($attrs['width'] ?? 0);
        if ( in_array($buttonWidth, array( 25, 50, 75, 100 ), true) ) {
            $generatedStyleProjector->registerButtonWidth($marker, $buttonWidth, $context->generatedStyles);
        }
    }

    /** @param array<int, array<string, mixed>> $innerBlocks */
    private function isAtomicInlineChildFlow(DOMElement $element, array $innerBlocks): bool
    {
        $children = array();
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                $children[] = $child;
            } elseif ( '' !== trim((string) ($child->textContent ?? '')) ) {
                return false;
            }
        }
        if ( count($children) < 2 || count($children) !== count($innerBlocks) ) {
            return false;
        }
        foreach ( $children as $child ) {
            $display = strtolower(trim((string) preg_replace('/\s*!important\s*$/i', '', (string) ($this->styleResolver->cssDeclarations($child->getAttribute('style'))['display'] ?? ''))));
            if ( ! in_array($display, array( 'inline-block', 'inline-flex', 'inline-grid', 'inline-table' ), true) ) {
                return false;
            }
        }
        return true;
    }

    private function sourceAnchorHasNoTextDecoration(DOMElement $anchor): bool
    {
        $decorationLine = null;
        foreach ( $this->styleResolver->cssDeclarations($this->styleResolver->mergedPresentationStyle($anchor)) as $property => $value ) {
            $value = CssValueInspector::comparable($this->styleResolver->resolveCssVariablesInValue($value));
            if ( 'text-decoration' === $property ) {
                $decorationLine = preg_match('/\b(?:underline|overline|line-through)\b/', $value)
                    ? 'line'
                    : (! in_array($value, array( 'inherit', 'revert', 'revert-layer' ), true) ? 'none' : $decorationLine);
            } elseif ( 'text-decoration-line' === $property ) {
                $decorationLine = preg_match('/\b(?:underline|overline|line-through)\b/', $value)
                    ? 'line'
                    : (in_array($value, array( 'none', 'initial', 'unset' ), true) ? 'none' : $decorationLine);
            }
        }
        return 'none' === $decorationLine;
    }

    /** @param array<string, mixed> $attrs @return array<string, mixed> */
    private function withSyntheticHeaderAnchorCarrier(array $attrs, DOMElement $anchor, GeneratedSupportStylesheetState $generatedStyles): array
    {
        if ( ! self::hasAncestorTag($anchor, 'header') ) {
            return $attrs;
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
                $declarations[$property] = $this->styleResolver->resolveCssVariablesInValue($value);
            }
        }
        foreach ( $this->styleResolver->specificityResolvedGapDeclarations($anchor) as $property => $value ) {
            if ( ! str_contains(strtolower($value), '!important') ) {
                $declarations[$property] = $this->styleResolver->resolveCssVariablesInValue($value);
            }
        }
        if ( array() === $declarations ) {
            return $attrs;
        }
        $css = $this->styleResolver->cssDeclarationString($declarations);
        $className = self::SYNTHETIC_HEADER_ANCHOR_CLASS_PREFIX . substr(hash('sha256', $css), 0, 16);
        $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $className);
        $generatedStyles->registerSyntheticHeaderAnchor($className, 'p.' . $className . '>a{' . $css . '}');
        return $attrs;
    }

    private static function semanticGroupTagName(DOMElement $element): ?string
    {
        $tag = strtolower($element->tagName);
        if ( ShellLandmarkPolicy::isSemanticGroupTag($tag) ) {
            return $tag;
        }
        $landmark = ShellLandmarkPolicy::landmarkKind($tag, $element->getAttribute('role'));
        return in_array($landmark, array( 'header', 'footer' ), true) ? $landmark : null;
    }

    private static function hasAncestorTag(DOMElement $element, string $tagName): bool
    {
        for ( $parent = $element->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
            if ( $tagName === strtolower($parent->tagName) ) {
                return true;
            }
        }
        return false;
    }
}

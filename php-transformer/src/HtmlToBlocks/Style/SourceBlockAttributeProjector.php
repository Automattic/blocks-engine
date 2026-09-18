<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Support\ShellLandmarkPolicy;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/** Projects source identities and structural carriers onto canonical block attributes. */
final class SourceBlockAttributeProjector
{
    public const SYNTHETIC_PARAGRAPH_CLASS = 'blocks-engine-synthetic-paragraph';
    public const SYNTHETIC_SVG_PARAGRAPH_CLASS = 'blocks-engine-synthetic-svg-paragraph';
    public const HIDDEN_RICH_TEXT_MARKER_CLASS = 'blocks-engine-hidden-richtext-marker';
    public const SYNTHETIC_ANCHOR_UNDECORATED_CLASS = 'blocks-engine-synthetic-anchor-undecorated';
    public const SYNTHETIC_ANCHOR_BLOCK_DISPLAY_CLASS = 'blocks-engine-synthetic-anchor-block-display';
    public const SYNTHETIC_IMAGE_FIGURE_CLASS = 'blocks-engine-synthetic-image-figure';
    public const SYNTHETIC_INLINE_IMAGE_FIGURE_CLASS = 'blocks-engine-synthetic-image-figure-inline';
    public const SYNTHETIC_EMBED_FIGURE_CLASS = 'blocks-engine-synthetic-embed-figure';
    public const CSS_OWNED_INLINE_FLOW_CLASS = 'blocks-engine-css-owned-inline-flow';
    public const CSS_OWNED_LAYOUT_ITEM_CLASS = 'blocks-engine-css-owned-layout-item';
    public const LAYOUT_NEUTRAL_BUTTONS_CLASS = 'blocks-engine-layout-neutral-buttons';
    public const LAYOUT_NEUTRAL_BUTTON_CLASS = 'blocks-engine-layout-child-button';

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
            // The source image was inline content that its parent aligned. A
            // synthesized figure is a block box that fills the line instead,
            // so the alignment has nothing left to move.
            if ( $facts->syntheticImageFigureFollowsInlineFlow ) {
                $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), self::SYNTHETIC_INLINE_IMAGE_FIGURE_CLASS);
            }
        }
        if ( 'core/paragraph' === $name && $facts->isInlineSourceElement ) {
            $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), self::SYNTHETIC_PARAGRAPH_CLASS);
            if ( 'a' === $sourceTagName && $this->sourceAnchorHasNoTextDecoration($sourceElement) ) {
                $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), self::SYNTHETIC_ANCHOR_UNDECORATED_CLASS);
            }
            if ( 'a' === $sourceTagName && $this->sourceAnchorResolvesToBlockDisplay($sourceElement) ) {
                $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), self::SYNTHETIC_ANCHOR_BLOCK_DISPLAY_CLASS);
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
        if ( in_array($name, array( 'core/group', 'core/paragraph' ), true) && self::isHiddenAccessibilitySupportElement($sourceElement) ) {
            $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), self::HIDDEN_RICH_TEXT_MARKER_CLASS);
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
        if ( 'core/button' === $name
            && $this->buttonLabelHasAuthoredColor($sourceElement, $logicalSourceElement, (string) ($attrs['text'] ?? ''), $sourceElement->getNodePath() ?? '', $logicalSourceElement->getNodePath() ?? '')
        ) {
            unset($attrs['style']['color']['text']);
            if ( array() === ($attrs['style']['color'] ?? null) ) {
                unset($attrs['style']['color']);
            }
            if ( array() === ($attrs['style'] ?? null) ) {
                unset($attrs['style']);
            }
        }

        if ( 'core/group' === $name && ! isset($attrs['tagName']) ) {
            $semanticTag = self::semanticGroupTagName($sourceElement);
            if ( null !== $semanticTag ) {
                $attrs['tagName'] = $semanticTag;
            }
        }
        return $attrs;
    }

    private static function isHiddenAccessibilitySupportElement(DOMElement $element): bool
    {
        $identity = strtolower(SourceDom::attr($element, 'id') . ' ' . SourceDom::attr($element, 'class'));
        if ( 1 !== preg_match('/(?:a11y|accessib|screen[-_]?reader|sr[-_]?only|visually[-_]?hidden)/', $identity) ) {
            return false;
        }

        $style = strtolower(SourceDom::attr($element, 'style'));
        return 1 === preg_match('/(?:^|;)\s*(?:display\s*:\s*none|visibility\s*:\s*hidden)\s*(?:!important)?\s*(?:;|$)/', $style);
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
        $isStandaloneLayoutButton = 'button' === strtolower($logicalControl->tagName)
            && $this->isDirectChildOfAuthorOwnedLayout($logicalControl)
            && ! $this->styleResolver->hasChildOwnedPositionedOffsets($logicalControl);
        if ( $isStandaloneLayoutButton ) {
            if ( 'core/buttons' === $name ) {
                $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), self::LAYOUT_NEUTRAL_BUTTONS_CLASS);
            }
            if ( 'core/button' === $name ) {
                $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), self::LAYOUT_NEUTRAL_BUTTON_CLASS);
            }
        }
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
        $labelPaths = self::buttonLabelPaths($sourceElement, $logicalControl, (string) ($attrs['text'] ?? ''));
        $labelProjectionPaths = self::hasStandaloneButtonLabel($logicalControl) ? $labelPaths : array();
        if ( 'core/button' === $name && $sourceElement !== $logicalControl && in_array($presentationPath, $labelProjectionPaths, true) ) {
            $logicalStyle = $this->styleResolver->styleAttributeMapper()->map(
                $this->styleResolver->cssDeclarations($this->styleResolver->mergedPresentationStyle($logicalControl))
            )['style'] ?? array();
            if ( '' !== trim((string) ($logicalStyle['color']['background'] ?? '')) ) {
                $attrs['style']['color']['background'] = $logicalStyle['color']['background'];
            }
        }
        if ( $facts->hasAuthorControlProjection ) {
            $controlMarker = '' !== $logicalControlPath ? $context->selectorProjections->ensureControlMarker($logicalControlPath) : '';
            if ( '' !== $controlMarker ) {
                $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $controlMarker);
                if ( 'core/button' === $name ) {
                    $this->generatedStyleProjector->registerNativeButtonStyleRule($controlMarker, $attrs, $context->generatedStyles, $nativeButtonTextAlignment, $logicalControl);
                    $childOwnedOffsets = $this->styleResolver->hasChildOwnedPositionedOffsets($logicalControl);
                    if ( $facts->isDirectChildOfAuthorFlexLayout && ! $childOwnedOffsets && ! $isStandaloneLayoutButton ) {
                        $this->generatedStyleProjector->registerDirectFlexButton($controlMarker, $logicalControl, $context->generatedStyles);
                    }
                    if ( ! $childOwnedOffsets ) {
                        $this->registerButtonWidth($attrs, $controlMarker, $logicalControl, $context);
                    }
                }
                if ( 'core/buttons' === $name && $this->styleResolver->hasChildOwnedPositionedOffsets($logicalControl) ) {
                    $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), self::LAYOUT_NEUTRAL_BUTTONS_CLASS);
                }
            }
            if ( '' !== $controlMarker && '' !== $presentationPath && $presentationPath !== $logicalControlPath ) {
                $context->selectorProjections->installButtonPresentationMarker($presentationPath, $controlMarker);
            }
            // core/button unwraps the label into RichText. Keep its selector
            // surface distinct from the link that owns the control chrome.
            if ( '' !== $controlMarker ) {
                foreach ( $labelProjectionPaths as $labelPath ) {
                    if ( $labelPath !== $logicalControlPath && $labelPath !== $presentationPath ) {
                        $context->selectorProjections->installButtonLabelPath($labelPath);
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
            $this->registerButtonWidth($attrs, $nativeButtonMarker, $logicalControl, $context);
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

    private static function hasStandaloneButtonLabel(DOMElement $control): bool
    {
        $label = trim($control->textContent ?? '');
        if ( '' === $label ) {
            return false;
        }
        $spans = $control->getElementsByTagName('span');
        if ( 1 !== $spans->length || ! $spans->item(0) instanceof DOMElement ) {
            return false;
        }
        return $label === trim($spans->item(0)->textContent ?? '');
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
    private function registerButtonWidth(array $attrs, string $marker, DOMElement $sourceControl, SourceBlockAttributeProjectionContext $context): void
    {
        $buttonWidth = (int) ($attrs['width'] ?? 0);
        if ( ! in_array($buttonWidth, array( 25, 50, 75, 100 ), true) ) {
            return;
        }
        if ( 100 === $buttonWidth ) {
            foreach ( $this->styleResolver->authorDeclaredPropertyValues($sourceControl, array( 'width' ))['width'] ?? array() as $value ) {
                if ( '100%' !== CssValueInspector::comparable($value) ) {
                    return;
                }
            }
        }
        $this->generatedStyleProjector->registerButtonWidth($marker, $buttonWidth, $context->generatedStyles);
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
        return 'none' === $this->resolvedTextDecorationLine($anchor, true);
    }

    /**
     * Resolves the computed `text-decoration-line` an element's authored
     * cascade produces, following an explicit `inherit` keyword up the
     * ancestor chain the way a browser would.
     *
     * `text-decoration-line` is NOT an inherited property: an element with no
     * authored declaration for it computes to the initial value `none`
     * regardless of its ancestors' values. The one exception is the anchor
     * LEAF itself — a plain `<a>` with no authored declaration at all still
     * renders underlined because of the user-agent stylesheet's `a { text-
     * decoration: underline }` rule, which this resolver has no visibility
     * into, so an undeclared leaf is left unresolved (conservative "has
     * decoration").
     *
     * This is what makes Tailwind Preflight's `a { text-decoration: inherit }`
     * reset actually resolve to `none`: the keyword sends resolution to the
     * anchor's parent, which is ordinarily a plain, undeclared element whose
     * computed value is the non-anchor initial `none` — not an unresolved
     * inheritance in need of a grandparent's value.
     *
     * @return string 'line', 'none', or '' when the authored cascade never
     *                resolves (an undeclared leaf, or an unresolvable
     *                revert/revert-layer keyword) — callers treat '' as "has
     *                decoration".
     */
    private function resolvedTextDecorationLine(DOMElement $element, bool $isLeaf): string
    {
        $declared = null;
        foreach ( $this->styleResolver->cssDeclarations($this->styleResolver->mergedPresentationStyle($element)) as $property => $value ) {
            if ( 'text-decoration' === $property || 'text-decoration-line' === $property ) {
                $declared = CssValueInspector::comparable($this->styleResolver->resolveCssVariablesInValue($value));
            }
        }

        if ( null === $declared ) {
            return $isLeaf ? '' : 'none';
        }
        if ( preg_match('/\b(?:underline|overline|line-through)\b/', $declared) ) {
            return 'line';
        }
        if ( 'inherit' === $declared ) {
            return $element->parentNode instanceof DOMElement
                ? $this->resolvedTextDecorationLine($element->parentNode, false)
                : 'none';
        }
        if ( in_array($declared, array( 'none', 'initial', 'unset' ), true) ) {
            return 'none';
        }

        // `revert`/`revert-layer` resolve against the UA/previous-layer
        // cascade, which this resolver cannot evaluate generically.
        return '';
    }

    /**
     * Whether a plain anchor's outer display resolves to a block-level box
     * even though nothing authored ever says so on the anchor itself.
     *
     * A source anchor with its own authored `display` (however it resolves)
     * reaches the materialized markup through the ordinary author-stylesheet
     * projection, which still matches the anchor's retained class/tag
     * selector once it is promoted into a synthetic paragraph carrier -- so
     * there is nothing invisible to reproduce there. An anchor with NO
     * authored `display` of its own, however, computes to the UA
     * stylesheet's `inline` default only when it stays an inline-level box;
     * CSS blockifies it to a block-level box when its parent establishes
     * flex or grid layout, independent of any single selector. That
     * blockification is real geometry (measured on harrykahanhai's "Listen"
     * section: 16px vs 14px line height) with no authored rule of its own
     * to survive the carrier, so it must be reproduced here the same way
     * #1899 reproduces a resolved-`none` text-decoration.
     */
    private function sourceAnchorResolvesToBlockDisplay(DOMElement $anchor): bool
    {
        $ownDisplay = CssValueInspector::comparable((string) ($this->styleResolver->structuralPresentationDeclarations($anchor)['display'] ?? ''));

        return '' === $ownDisplay && $this->isDirectChildOfAuthorOwnedLayout($anchor);
    }

    private function isDirectChildOfAuthorOwnedLayout(DOMElement $element): bool
    {
        $parent = $element->parentNode;
        if ( ! $parent instanceof DOMElement ) {
            return false;
        }

        $parentDisplay = CssValueInspector::comparable((string) ($this->styleResolver->structuralPresentationDeclarations($parent)['display'] ?? ''));

        return in_array($parentDisplay, array( 'flex', 'inline-flex', 'grid', 'inline-grid' ), true);
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

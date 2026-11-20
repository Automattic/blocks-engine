<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Support\ShellLandmarkPolicy;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormDispatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextLanguageDirection;
use DOMElement;

/** Projects source identities and structural carriers onto canonical block attributes. */
final class SourceBlockAttributeProjector
{
    public const SYNTHETIC_PARAGRAPH_CLASS = 'blocks-engine-synthetic-paragraph';
    /** A synthetic paragraph that is itself a lowered block-level source box, not a transparent carrier. */
    public const SOURCE_BOX_PARAGRAPH_CLASS = 'blocks-engine-source-box-paragraph';
    /** A synthetic link paragraph that keeps an inline box, so the editor can focus its RichText root. */
    public const INLINE_ANCHOR_CARRIER_CLASS = 'blocks-engine-inline-anchor-carrier';
    public const SYNTHETIC_SVG_PARAGRAPH_CLASS = 'blocks-engine-synthetic-svg-paragraph';
    /**
     * A synthetic paragraph whose RichText keeps its source item (a classed
     * link) with the same classes. The paragraph shares those classes only for
     * sibling matching; the retained element is the item's one box.
     */
    public const RETAINED_ITEM_CARRIER_CLASS = 'blocks-engine-retained-item';
    public const HIDDEN_RICH_TEXT_MARKER_CLASS = 'blocks-engine-hidden-richtext-marker';
    public const SYNTHETIC_ANCHOR_UNDECORATED_CLASS = 'blocks-engine-synthetic-anchor-undecorated';
    public const SYNTHETIC_ANCHOR_BLOCK_DISPLAY_CLASS = 'blocks-engine-synthetic-anchor-block-display';
    /** RichText content wrapped in a link propagated from an enclosing card anchor. */
    public const PROPAGATED_LINK_CLASS = 'blocks-engine-propagated-link';
    public const PROPAGATED_LINK_COLOR_CLASS = 'blocks-engine-propagated-link-color';
    public const SYNTHETIC_IMAGE_FIGURE_CLASS = 'blocks-engine-synthetic-image-figure';
    public const SYNTHETIC_FLEX_IMAGE_FIGURE_CLASS = 'blocks-engine-synthetic-flex-image-figure';
    public const AUTHOR_MAX_WIDTH_IMAGE_CLASS = 'blocks-engine-author-max-width-image';
    public const SYNTHETIC_INLINE_IMAGE_FIGURE_CLASS = 'blocks-engine-synthetic-image-figure-inline';
    /**
     * A transparent figure that only keeps an ordinary inline image's line-box
     * strut. Unlike the inline carrier it owns no source positioning.
     */
    public const SYNTHETIC_STRUT_IMAGE_FIGURE_CLASS = 'blocks-engine-synthetic-image-figure-strut';
    public const INLINE_IMAGE_LEAF_CLASS = 'blocks-engine-inline-image-leaf';
    public const BASELINE_IMAGE_LEAF_CLASS = 'blocks-engine-baseline-image-leaf';
    public const INLINE_IMAGE_LINK_CLASS = 'blocks-engine-inline-image-link';
    public const SYNTHETIC_IMAGE_GAP_CLASS = 'blocks-engine-synthetic-image-gap';
    /** An image unwrapped from a paragraph whose line box it sized in the source. */
    public const PARAGRAPH_LINE_BOX_IMAGE_FIGURE_CLASS = 'blocks-engine-paragraph-line-box-image';
    public const SYNTHETIC_FILL_IMAGE_FIGURE_CLASS = 'blocks-engine-synthetic-image-figure-fill';
    public const LAYER_FILL_IMAGE_FIGURE_CLASS = 'blocks-engine-layer-fill-image';
    /** A fill image a later absolutely positioned sibling layer paints over. */
    public const COVERED_FILL_IMAGE_FIGURE_CLASS = 'blocks-engine-covered-fill-image';
    /** A positioned sibling layer whose box covers an earlier fill image's whole box. */
    public const IMAGE_COVERING_LAYER_CLASS = 'blocks-engine-image-covering-layer';
    public const SYNTHETIC_FILL_VIDEO_FIGURE_CLASS = 'blocks-engine-synthetic-video-figure-fill';
    public const SYNTHETIC_EMBED_FIGURE_CLASS = 'blocks-engine-synthetic-embed-figure';
    /** A core/embed under inherited whitespace preservation its wrapper must not apply. */
    public const EMBED_NORMAL_WHITESPACE_CLASS = 'blocks-engine-embed-normal-whitespace';
    public const CSS_OWNED_INLINE_FLOW_CLASS = 'blocks-engine-css-owned-inline-flow';
    public const CSS_OWNED_LAYOUT_ITEM_CLASS = 'blocks-engine-css-owned-layout-item';
    public const LAYOUT_NEUTRAL_BUTTONS_CLASS = 'blocks-engine-layout-neutral-buttons';
    /** A group standing in for an inline wrapper around flow content; it generates no box. */
    public const INLINE_FLOW_WRAPPER_CLASS = 'blocks-engine-inline-flow-wrapper';
    public const LAYOUT_NEUTRAL_BUTTON_CLASS = 'blocks-engine-layout-child-button';

    private const SYNTHETIC_HEADER_ANCHOR_CLASS_PREFIX = 'blocks-engine-synthetic-header-anchor-';

    /**
     * Markers that record a fact about the ORIGINAL source which the block's
     * saved tag cannot express: a `<p>` that was synthesized around inline
     * content, or a `<figure>` synthesized around a bare image. Re-ingested
     * transformer output has only the saved tag, so the marker is the one
     * piece of evidence left; re-deriving it for the same block keeps a
     * transform of the engine's own saved markup a fixed point. Every other
     * engine class is re-derived from structure, never carried.
     */
    private const SELF_DESCRIBING_MARKERS = array(
        'core/group'     => array('blocks-engine-stretched-link'),
        'core/paragraph' => array(
            self::SYNTHETIC_PARAGRAPH_CLASS,
            self::SOURCE_BOX_PARAGRAPH_CLASS,
            self::SYNTHETIC_SVG_PARAGRAPH_CLASS,
            self::RETAINED_ITEM_CARRIER_CLASS,
            'blocks-engine-stretched-link-carrier',
            self::SYNTHETIC_ANCHOR_UNDECORATED_CLASS,
            self::SYNTHETIC_ANCHOR_BLOCK_DISPLAY_CLASS,
            self::PROPAGATED_LINK_CLASS,
            self::PROPAGATED_LINK_COLOR_CLASS,
        ),
        'core/heading'   => array(
            'blocks-engine-stretched-link-carrier',
            self::SYNTHETIC_ANCHOR_UNDECORATED_CLASS,
            self::PROPAGATED_LINK_CLASS,
            self::PROPAGATED_LINK_COLOR_CLASS,
        ),
        'core/video'     => array(self::SYNTHETIC_FILL_VIDEO_FIGURE_CLASS),
        'core/image'     => array(
            self::SYNTHETIC_IMAGE_FIGURE_CLASS,
            self::SYNTHETIC_FLEX_IMAGE_FIGURE_CLASS,
            self::AUTHOR_MAX_WIDTH_IMAGE_CLASS,
            self::SYNTHETIC_INLINE_IMAGE_FIGURE_CLASS,
            self::SYNTHETIC_STRUT_IMAGE_FIGURE_CLASS,
            self::SYNTHETIC_FILL_IMAGE_FIGURE_CLASS,
            self::LAYER_FILL_IMAGE_FIGURE_CLASS,
            self::COVERED_FILL_IMAGE_FIGURE_CLASS,
            self::SYNTHETIC_IMAGE_GAP_CLASS,
            self::PARAGRAPH_LINE_BOX_IMAGE_FIGURE_CLASS,
        ),
    );

    /**
     * Content-addressed markers whose payload (generated CSS for presentation
     * the saved markup no longer carries) cannot be recomputed from the saved
     * block. The disclosure toggle's source presentation is projected into
     * the stylesheet under this marker and removed from the toggle itself.
     */
    private const SELF_DESCRIBING_MARKER_PREFIXES = array(
        'core/accordion-heading' => array( 'blocks-engine-accordion-toggle-' ),
    );

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
        $attrs = $this->withHostRenderedFormIdentity($name, $attrs, $sourceElement, $context);
        $attrs = $this->withSyntheticImageFigureClasses($name, $attrs, $sourceElement, $sourceTagName, $facts);
        $attrs = $this->withImageCoveringLayerClass($attrs, $sourceElement);
        $attrs = $this->withInlineImageAlignment($name, $attrs, $innerBlocks, $sourceElement);
        $attrs = $this->withVideoSizeHints($name, $attrs, $sourceElement, $sourceTagName, $context);
        $attrs = $this->withInlineParagraphCarrierClasses($name, $attrs, $sourceElement, $sourceTagName, $facts, $context);
        $attrs = $this->withSelfDescribingMarkers($name, $attrs, $sourceElement);
        if ('core/group' === $name && $facts->isInlineSourceElement
            && 'inherit' === ($innerBlocks[0]['attrs']['style']['typography']['fontSize'] ?? null)
            && str_contains((string) ($innerBlocks[0]['attrs']['className'] ?? ''), self::SYNTHETIC_PARAGRAPH_CLASS)) {
            // The group owns the source wrapper's selector and typography;
            // it is no longer an inline RichText/layout carrier.
            $context->selectorProjections->adoptAsSemanticBlock($sourceElement->getNodePath() ?? '');
        }
        $attrs = $this->withSourceProjectionClassName($name, $attrs, $sourceElement, $sourceTagName, $facts, $context);
        $attrs = $this->withLayoutCarrierClasses($name, $attrs, $innerBlocks, $sourceElement, $facts, $context);

        $attrs = $this->projectButtonAttributes($name, $attrs, $sourceElement, $logicalSourceElement, $facts, $context);
        $attrs = $this->generatedStyleProjector->applyDeclaredBlockSupport(
            $name,
            $attrs,
            $sourceElement,
            $context->generatedStyles,
            $facts->preserveGeneratedStyle
        );
        // This is behavior identity, not an authored styling class. It must
        // survive the styling resolver's pruning of unused source classes.
        $collectionMarker = SourceDom::attr($sourceElement, 'data-blocks-engine-collection-item-marker');
        if (1 === preg_match('/^blocks-engine-collection-item-[a-f0-9]{16}-[0-9]{1,3}$/', $collectionMarker)) {
            $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $collectionMarker);
        }
        $attrs = $this->withoutDuplicatedButtonLabelColor($name, $attrs, $sourceElement, $logicalSourceElement);

        $attrs = $this->withGroupSourceSemantics($name, $attrs, $sourceElement, $context);
        $attrs = $this->withSocialAnchorIdentity($name, $attrs, $sourceElement, $sourceTagName, $context);
        $attrs = $this->withGlobalZeroBoxes($name, $attrs, $sourceElement, $context);
        // An inline source element's own lang/dir already rides in the
        // RichText it is part of; only the text element's own box adds it.
        if ( ! $facts->isInlineSourceElement ) {
            $attrs = RichTextLanguageDirection::apply($name, $attrs, $sourceElement);
        }
        return ( new EditorListViewContainerNamer() )->apply($name, $attrs, $sourceElement);
    }

    /**
     * A preserved form has no block wrapper: its identity classes go into its own markup.
     *
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function withHostRenderedFormIdentity(string $name, array $attrs, DOMElement $sourceElement, SourceBlockAttributeProjectionContext $context): array
    {
        if ( 'core/html' === $name && array() !== FormDispatcher::hostRenderedClasses($sourceElement) ) {
            // Preserved forms have no block wrapper: identity must live in
            // their actual markup, not the ignored core/html className.
            $attrs['content'] = SourceDom::safeFallbackHtml(
                $sourceElement,
                $context->selectorProjections->tagMarkers(),
                fn (DOMElement $element): array => SourceDom::tokens($this->sourceProjectionClassName($element, $context, SourceDom::attr($element, 'class')))
            );
        }
        return $attrs;
    }

    /**
     * Box classes for the figure core/image synthesizes around a bare source image.
     *
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function withSyntheticImageFigureClasses(string $name, array $attrs, DOMElement $sourceElement, string $sourceTagName, SourceBlockAttributeProjectionFacts $facts): array
    {
        if ( 'core/image' === $name && 'figure' !== $sourceTagName ) {
            $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), self::SYNTHETIC_IMAGE_FIGURE_CLASS);
            if ($this->sourceImageIsFlexOrGridItem($sourceElement)) {
                $attrs['className'] = SourceDom::mergeClassNames(
                    (string) ($attrs['className'] ?? ''),
                    self::SYNTHETIC_FLEX_IMAGE_FIGURE_CLASS,
                    $this->styleResolver->syntheticFlexImageBoxClassName($sourceElement)
                );
            } elseif ('' !== (string) ($attrs['href'] ?? '') && $this->sourceImageLinkIsFlexOrGridItem($sourceElement)) {
                // The link core/image re-emits around the image was the item.
                // A figure box would put it on a line instead, adding the strut
                // below an inline-level link that a blockified item never had.
                $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), self::SYNTHETIC_FLEX_IMAGE_FIGURE_CLASS);
            }
            if ( str_contains((string) $attrs['className'], self::SYNTHETIC_FLEX_IMAGE_FIGURE_CLASS)
                && (isset($this->styleResolver->imageShapeDeclarations($sourceElement)['max-width'])
                    || $this->styleResolver->imageAxisVariesByViewport($sourceElement, 'max-width')) ) {
                $attrs['className'] = SourceDom::mergeClassNames((string) $attrs['className'], self::AUTHOR_MAX_WIDTH_IMAGE_CLASS);
            }
            // The source image was inline content that its parent aligned. A
            // synthesized figure is a block box that fills the line instead,
            // so the alignment has nothing left to move.
            if ( $facts->syntheticImageFigureFollowsInlineFlow || $facts->syntheticImageFigureKeepsLineStrut ) {
                $attrs['className'] = SourceDom::mergeClassNames(
                    (string) ($attrs['className'] ?? ''),
                    $facts->syntheticImageFigureFollowsInlineFlow ? self::SYNTHETIC_INLINE_IMAGE_FIGURE_CLASS : self::SYNTHETIC_STRUT_IMAGE_FIGURE_CLASS
                );
                if ( $this->hasCollapsedImageGap($sourceElement) ) {
                    $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), self::SYNTHETIC_IMAGE_GAP_CLASS);
                }
            } elseif ( $this->sourceImageFillsParent($sourceElement) ) {
                $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), self::SYNTHETIC_FILL_IMAGE_FIGURE_CLASS);
                $parent = $sourceElement->parentNode;
                if ( $parent instanceof DOMElement ) {
                    $position = CssValueInspector::comparable((string) ($this->styleResolver->structuralPresentationDeclarations($parent)['position'] ?? ''));
                    if ( in_array($position, array( 'absolute', 'fixed' ), true) ) {
                        $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), self::LAYER_FILL_IMAGE_FIGURE_CLASS);
                    } elseif ( $this->laterSiblingIsPositionedLayer($sourceElement) ) {
                        $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), self::COVERED_FILL_IMAGE_FIGURE_CLASS);
                    }
                }
            }
        }
        return $attrs;
    }

    /**
     * A group keeps the text alignment its inline image figure's source line had.
     *
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function withInlineImageAlignment(string $name, array $attrs, array $innerBlocks, DOMElement $sourceElement): array
    {
        // A transparent image figure still participates in the source parent's
        // line. Unlike RichText leaves it cannot own text alignment, so keep
        // the parent's inline declaration on the group that already exists.
        if ( 'core/group' === $name ) {
            foreach ( $innerBlocks as $child ) {
                if ( 'core/image' === ($child['blockName'] ?? '')
                    && in_array(self::SYNTHETIC_INLINE_IMAGE_FIGURE_CLASS, SourceDom::tokens((string) ($child['attrs']['className'] ?? '')), true) ) {
                    $attrs['className'] = SourceDom::mergeClassNames(
                        (string) ($attrs['className'] ?? ''),
                        $this->styleResolver->inlineGeometryClassName($sourceElement, array(), array( 'text-align' ))
                    );
                    break;
                }
            }
        }
        return $attrs;
    }

    /**
     * Zero-specificity size hints for a video's HTML dimensions, and its fill class.
     *
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function withVideoSizeHints(string $name, array $attrs, DOMElement $sourceElement, string $sourceTagName, SourceBlockAttributeProjectionContext $context): array
    {
        if ( 'core/video' === $name && 'video' === $sourceTagName ) {
            // HTML dimensions are presentational hints, not core/video attrs.
            // Zero-specificity rules preserve their fallback role: authored
            // source CSS (including responsive sizing) must still win.
            $hints = '';
            foreach ( array( 'width', 'height' ) as $dimension ) {
                $value = trim($sourceElement->getAttribute($dimension));
                if ( preg_match('/^\d+$/', $value) ) {
                    $hints .= $dimension . ':' . $value . 'px;';
                }
            }
            if ( '' !== $hints ) {
                $marker = 'blocks-engine-video-size-' . substr(md5($hints), 0, 12);
                $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $marker);
                $rule = ':where(.' . $marker . '>video){' . $hints . '}';
                $width = trim($sourceElement->getAttribute('width'));
                $height = trim($sourceElement->getAttribute('height'));
                // Core styles video to width:100%;height:auto. Give its new
                // figure the intrinsic width and reserve the intrinsic ratio
                // before metadata loads, without overriding authored CSS.
                if ( preg_match('/^\d+$/', $width) ) {
                    $rule .= ':where(.' . $marker . '){width:' . $width . 'px;max-width:100%}';
                }
                if ( preg_match('/^[1-9]\d*$/', $width) && preg_match('/^[1-9]\d*$/', $height) ) {
                    $rule .= ':where(.' . $marker . '>video){aspect-ratio:auto ' . $width . '/' . $height . '}';
                }
                $context->generatedStyles->registerVideoSizeHint($marker, $rule);
            }
            $declarations = $this->styleResolver->resolvedPresentationDeclarations($sourceElement);
            if ( '100%' === CssValueInspector::declared($declarations, 'width')
                && '100%' === CssValueInspector::declared($declarations, 'height') ) {
                $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), self::SYNTHETIC_FILL_VIDEO_FIGURE_CLASS);
            }
        }
        return $attrs;
    }

    /**
     * Carrier classes for a paragraph made from an inline source element.
     *
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function withInlineParagraphCarrierClasses(string $name, array $attrs, DOMElement $sourceElement, string $sourceTagName, SourceBlockAttributeProjectionFacts $facts, SourceBlockAttributeProjectionContext $context): array
    {
        if ( 'core/paragraph' === $name && $facts->isInlineSourceElement ) {
            $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), self::SYNTHETIC_PARAGRAPH_CLASS);
            // Entrance links retain their own painted box and live classes.
            // A second paragraph box would add border/spacing and resize the
            // grid item even though the authored rules now reach the leaf.
            if ( ! ('a' === $sourceTagName && $sourceElement->hasAttribute(\Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence::VIEWPORT_ENTRANCE))
                && $this->styleResolver->sourceChildHasParentBoxStyling($sourceElement) ) {
                // The projected parent > child rule now addresses this carrier.
                // display:contents would discard its margin/paint/geometry.
                $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), self::SOURCE_BOX_PARAGRAPH_CLASS);
            }
            // The carrier holds the wrapper's lifted alignment for a line it
            // fills alone. Boxless, that alignment would apply to no line box.
            $alignedLineBox = in_array($attrs['style']['typography']['textAlign'] ?? '', array( 'left', 'center', 'right' ), true)
                && $this->styleResolver->inheritedTextAlignmentNeedsLineBox($sourceElement);
            if ( $alignedLineBox ) {
                $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), self::SOURCE_BOX_PARAGRAPH_CLASS);
            }
            if ( 'a' === $sourceTagName && $this->sourceAnchorHasNoTextDecoration($sourceElement) ) {
                $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), self::SYNTHETIC_ANCHOR_UNDECORATED_CLASS);
            }
            if ( 'a' === $sourceTagName && $this->sourceAnchorResolvesToBlockDisplay($sourceElement) ) {
                $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), self::SYNTHETIC_ANCHOR_BLOCK_DISPLAY_CLASS);
            }
            if ( 'a' === $sourceTagName ) {
                $presentation = $this->styleResolver->structuralPresentationDeclarations($sourceElement);
                $display = CssValueInspector::comparable((string) ($presentation['display'] ?? ''));
                $float = CssValueInspector::comparable((string) ($presentation['float'] ?? ''));
                if ( ! $alignedLineBox
                    && in_array($display, array('', 'inline'), true)
                    && in_array($float, array('', 'none'), true)
                    && ! $this->isDirectChildOfAuthorOwnedLayout($sourceElement)
                    && $this->anchorPrecedesBlock($sourceElement) ) {
                    // A boxless RichText root cannot receive focus. In inline
                    // flow before a block, its fallback hitbox can overlap the
                    // following Group. An inline carrier preserves the source
                    // line without changing flex/grid or floating link layout.
                    $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), self::INLINE_ANCHOR_CARRIER_CLASS);
                }
                $attrs = $this->withSyntheticHeaderAnchorCarrier($attrs, $sourceElement, $context->generatedStyles);
            }
        }
        return $attrs;
    }

    /**
     * Source classes that describe the block itself survive onto it.
     *
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function withSelfDescribingMarkers(string $name, array $attrs, DOMElement $sourceElement): array
    {
        foreach ( self::SELF_DESCRIBING_MARKERS[ $name ] ?? array() as $marker ) {
            if ( SourceDom::hasClass($sourceElement, $marker) ) {
                $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $marker);
            }
        }
        foreach ( self::SELF_DESCRIBING_MARKER_PREFIXES[ $name ] ?? array() as $prefix ) {
            foreach ( SourceDom::classNames($sourceElement) as $class ) {
                if ( str_starts_with($class, $prefix) ) {
                    $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $class);
                }
            }
        }
        return $attrs;
    }

    /**
     * The source element's selector projection classes, on whichever box owns them.
     *
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function withSourceProjectionClassName(string $name, array $attrs, DOMElement $sourceElement, string $sourceTagName, SourceBlockAttributeProjectionFacts $facts, SourceBlockAttributeProjectionContext $context): array
    {
        $baseClassName = (string) ($attrs['className'] ?? '');
        // A RichText marker is already the selector projection of this source
        // element. Its paragraph is only a validity carrier, not a second box.
        $richTextMarker = $context->selectorProjections->richTextMarker($sourceElement->getNodePath() ?? '');
        $contentOwnsProjection = 'core/paragraph' === $name && $facts->isInlineSourceElement
            && '' !== $richTextMarker
            && str_contains((string) ($attrs['content'] ?? ''), '--blocks-engine-richtext-marker:' . $richTextMarker);
        // NavigationPattern has split the source anchor from its LI already.
        // Later source projection must follow that ownership, not put the
        // anchor's attribute selectors back on the block's rendered LI.
        $navigationAnchorOwnsProjection = in_array($name, array('core/navigation-link', 'core/navigation-submenu'), true)
            && 'a' === $sourceTagName
            && isset($attrs['metadata']['blocksEngineNavigationAnchor']);
        if ($navigationAnchorOwnsProjection) {
            $attrs['metadata']['blocksEngineNavigationAnchor']['className'] = $this->sourceProjectionClassName(
                $sourceElement,
                $context,
                (string) ($attrs['metadata']['blocksEngineNavigationAnchor']['className'] ?? '')
            );
        }
        // A navigation built from its collapsed panel renders that panel as
        // Core's responsive container; the panel's projection belongs there.
        $navigationPanelOwnsProjection = 'core/navigation' === $name
            && true === ($attrs['metadata']['blocksEngineNavigationContainer']['ownsSource'] ?? false);
        if ($navigationPanelOwnsProjection) {
            $attrs['metadata']['blocksEngineNavigationContainer']['className'] = $this->sourceProjectionClassName(
                $sourceElement,
                $context,
                (string) ($attrs['metadata']['blocksEngineNavigationContainer']['className'] ?? '')
            );
        }
        $projectionClassName = $contentOwnsProjection || $navigationAnchorOwnsProjection || $navigationPanelOwnsProjection
            ? $baseClassName
            : $this->sourceProjectionClassName($sourceElement, $context, $baseClassName);
        if ( '' !== $projectionClassName ) {
            $attrs['className'] = 'core/paragraph' === $name && 'a' === $sourceTagName && $facts->isInlineSourceElement
                ? self::withoutContentAnchorClasses($projectionClassName, $baseClassName, (string) ($attrs['content'] ?? ''))
                : $projectionClassName;
        }
        return $attrs;
    }

    /**
     * Layout and structural marker classes: CSS-owned items and flow, hidden support text, grid gaps, table markers.
     *
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function withLayoutCarrierClasses(string $name, array $attrs, array $innerBlocks, DOMElement $sourceElement, SourceBlockAttributeProjectionFacts $facts, SourceBlockAttributeProjectionContext $context): array
    {
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
        return $attrs;
    }

    /**
     * A button whose label carries its own authored colour drops the duplicated text colour.
     *
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function withoutDuplicatedButtonLabelColor(string $name, array $attrs, DOMElement $sourceElement, DOMElement $logicalSourceElement): array
    {
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
        return $attrs;
    }

    /**
     * A group's responsive top margin carrier and its semantic source tag.
     *
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function withGroupSourceSemantics(string $name, array $attrs, DOMElement $sourceElement, SourceBlockAttributeProjectionContext $context): array
    {
        if ( 'core/group' === $name ) {
            $responsiveMarginTop = $this->styleResolver->responsiveBlockMarginTopClassName($sourceElement);
            if ( '' !== $responsiveMarginTop ) {
                $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $responsiveMarginTop);
            }
        }

        if ( 'core/group' === $name && ! isset($attrs['tagName']) ) {
            $semanticTag = self::semanticGroupTagName($sourceElement);
            // A content item's own footer is no landmark (HTML maps it
            // to a generic role). Saved as `<footer>` it reads as a
            // copy of the page shell inside page content, so it keeps the
            // tag only while author CSS still selects it by that tag.
            if ( null !== $semanticTag && ShellLandmarkPolicy::isContentItemLandmarkTag($sourceElement)
                && ! self::authorCssSelectsTag($context->authorStyles, $semanticTag)
            ) {
                $semanticTag = null;
            }
            if ( null !== $semanticTag ) {
                $attrs['tagName'] = $semanticTag;
            }
        }
        return $attrs;
    }

    /**
     * A social link keeps its source anchor's identity attributes and classes.
     *
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function withSocialAnchorIdentity(string $name, array $attrs, DOMElement $sourceElement, string $sourceTagName, SourceBlockAttributeProjectionContext $context): array
    {
        if ('core/social-link' === $name) {
            $anchor = 'a' === $sourceTagName ? $sourceElement : $sourceElement->getElementsByTagName('a')->item(0);
            if ($anchor instanceof DOMElement) {
                $identity = array();
                if ($anchor->hasAttribute('class')) $identity['data-blocks-engine-social-source-class'] = $anchor->getAttribute('class');
                foreach (\Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree::authoredAttributes($anchor) as $attribute) {
                    if (in_array($attribute->name, array('id', 'class', 'style', 'role', 'title', 'tabindex', 'target', 'rel'), true) || str_starts_with($attribute->name, 'data-') || str_starts_with($attribute->name, 'aria-')) $identity[$attribute->name] = $attribute->value;
                }
                $classes = $this->sourceProjectionClassName($anchor, $context, (string) ($identity['class'] ?? ''));
                if ('a' === $sourceTagName) {
                    $itemClasses = array();
                    $sourceClasses = SourceDom::boundedClassTokens($anchor->getAttribute('class'));
                    foreach (SourceDom::tokens((string) ($attrs['className'] ?? '')) as $class) {
                        if (str_starts_with($class, 'blocks-engine-social-source-') && !in_array($class, $sourceClasses, true)) $itemClasses[] = $class;
                        else $classes = SourceDom::mergeClassNames($classes, $class);
                    }
                    $attrs['className'] = implode(' ', $itemClasses);
                    unset($attrs['anchor'], $attrs['style']);
                }
                $identity['class'] = $classes;
                $attrs['metadata']['blocksEngineSocialAnchor'] = $identity;
            }
        }
        return $attrs;
    }

    /**
     * Zero padding or margin a global rule already sets moves to a shared marker class.
     *
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function withGlobalZeroBoxes(string $name, array $attrs, DOMElement $sourceElement, SourceBlockAttributeProjectionContext $context): array
    {
        foreach (array('padding', 'margin') as $box) {
            $sides = $attrs['style']['spacing'][$box] ?? array();
            $zeroSides = is_array($sides) ? array_values(array_intersect(array('top', 'right', 'bottom', 'left'), array_keys(array_filter($sides, static fn($value): bool => is_string($value) && CssValueInspector::isZeroLength($value))))) : array();
            $zeroSides = array_values(array_filter($zeroSides, fn(string $side): bool => $this->styleResolver->hasGlobalZeroBox($sourceElement, $box, $side)));
            if (array() === $zeroSides) continue;
            // Only the sides the instance zeroes join the shared rule; a partial box
            // gets a side-specific marker so unlisted sides keep their own cascade.
            $all = 4 === count($zeroSides);
            $marker = 'blocks-engine-zero-' . str_replace('/', '-', $name) . '-' . $box . ($all ? '' : '-' . implode('', array_map(static fn(string $side): string => $side[0], $zeroSides)));
            $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $marker);
            $context->generatedStyles->registerGlobalZeroBox($marker, $all ? $box . ':0' : implode(';', array_map(static fn(string $side): string => $box . '-' . $side . ':0', $zeroSides)));
            foreach ($zeroSides as $side) unset($attrs['style']['spacing'][$box][$side]);
            if (array() === ($attrs['style']['spacing'][$box] ?? null)) unset($attrs['style']['spacing'][$box]);
        }
        if (array() === ($attrs['style']['spacing'] ?? null)) unset($attrs['style']['spacing']);
        if (array() === ($attrs['style'] ?? null)) unset($attrs['style']);
        return $attrs;
    }

    /** Whitespace-only nodes are discarded by convertChildren(), not by the browser. */
    private function hasCollapsedImageGap(DOMElement $image): bool
    {
        $item = $image;
        if ( $image->parentNode instanceof DOMElement && 'a' === strtolower($image->parentNode->tagName) ) {
            $item = $image->parentNode;
        }
        $sibling = $item->previousSibling;
        $space = false;
        while ( $sibling instanceof \DOMText || $sibling instanceof \DOMComment ) {
            if ( $sibling instanceof \DOMText ) {
                if ( ! preg_match('/^[\x20\t\r\n\f]*$/D', $sibling->textContent) ) return false;
                $space = $space || '' !== $sibling->textContent;
            }
            $sibling = $sibling->previousSibling;
        }
        if ( ! $space || ! $sibling instanceof DOMElement ) return false;
        foreach ( array($item, $sibling) as $element ) {
            $tag = strtolower($element->tagName);
            if ( 'img' !== $tag && ('a' !== $tag || 1 !== $element->getElementsByTagName('img')->length || '' !== trim($element->textContent)) ) return false;
            $declarations = $this->styleResolver->resolvedPresentationDeclarations($element);
            $display = CssValueInspector::declared($declarations, 'display');
            if ( ! in_array($display, array('', 'inline', 'inline-block'), true) ) return false;
            if ( ! in_array(CssValueInspector::declared($declarations, 'float'), array('', 'none'), true) ) return false;
        }
        // A real space in flex/grid does not create a layout item. Keep it that way.
        $parent = $item->parentNode;
        if ( $parent instanceof DOMElement ) {
            $declarations = $this->styleResolver->resolvedPresentationDeclarations($parent);
            if ( in_array(CssValueInspector::declared($declarations, 'display'), array('flex', 'inline-flex', 'grid', 'inline-grid'), true) ) return false;
        }
        return true;
    }

    private function sourceImageFillsParent(DOMElement $image): bool
    {
        if ( 'img' !== strtolower($image->tagName) ) {
            return false;
        }
        $shape = $this->styleResolver->imageShapeDeclarations($image);
        foreach ( array( 'width', 'height' ) as $axis ) {
            $value = $this->styleResolver->customProperties->resolve((string) ($shape[$axis]['value'] ?? ''), $image);
            if ( '100%' !== CssValueInspector::comparable($value) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * A later absolutely positioned sibling paints over the whole of an image
     * that fills their shared parent (a scrim with a caption, a tint over a
     * poster). It is the front end's design, but in the editor that layer is
     * a block whose box covers the image block, so every click on the image
     * selects the layer and the image can never be selected or replaced. A
     * layer that covers only part of the image (a centered device frame, a
     * corner badge) leaves the rest of the image clickable.
     */
    private function laterSiblingIsPositionedLayer(DOMElement $image): bool
    {
        for ( $sibling = $image->nextSibling; null !== $sibling; $sibling = $sibling->nextSibling ) {
            if ( $sibling instanceof DOMElement && $this->positionedLayerCoversContainingBlock($sibling) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Mark the covering layer itself, so only it moves into editor flow after
     * a covered image. Every other positioned sibling keeps its geometry.
     *
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function withImageCoveringLayerClass(array $attrs, DOMElement $sourceElement): array
    {
        for ( $sibling = $sourceElement->previousSibling; null !== $sibling; $sibling = $sibling->previousSibling ) {
            // Re-ingested output has the covered figure, not the bare image.
            if ( $sibling instanceof DOMElement && ( $this->sourceImageFillsParent($sibling) || SourceDom::hasClass($sibling, self::COVERED_FILL_IMAGE_FIGURE_CLASS) ) ) {
                if ( $this->positionedLayerCoversContainingBlock($sourceElement) ) {
                    $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), self::IMAGE_COVERING_LAYER_CLASS);
                }
                break;
            }
        }
        return $attrs;
    }

    /**
     * An absolutely positioned box whose extent is its containing block's:
     * all four insets pinned to zero, or a full width and height from a
     * pinned top-left corner.
     */
    private function positionedLayerCoversContainingBlock(DOMElement $element): bool
    {
        $declarations = $this->styleResolver->structuralPresentationDeclarations($element);
        if ( ! in_array(CssValueInspector::declared($declarations, 'position'), array( 'absolute', 'fixed' ), true) ) {
            return false;
        }
        $sides = CssValueInspector::expandBoxShorthand(CssValueInspector::declared($declarations, 'inset'));
        foreach ( array( 'top', 'right', 'bottom', 'left' ) as $index => $side ) {
            $value = CssValueInspector::declared($declarations, $side);
            $sides[ $index ] = '' === $value ? $sides[ $index ] : $value;
        }
        $zero = static fn (string $value): bool => '' !== $value && CssValueInspector::isZeroLength($value);
        if ( 4 === count(array_filter($sides, $zero)) ) {
            return true;
        }
        return $zero($sides[0]) && $zero($sides[3])
            && '100%' === CssValueInspector::declared($declarations, 'width')
            && '100%' === CssValueInspector::declared($declarations, 'height');
    }

    private function sourceImageIsFlexOrGridItem(DOMElement $image): bool
    {
        return $this->styleResolver->imageIsFlexOrGridItem($image);
    }

    private function sourceImageLinkIsFlexOrGridItem(DOMElement $image): bool
    {
        $link = $image->parentNode;
        if ( ! $link instanceof DOMElement || 'a' !== strtolower($link->tagName) ) {
            return false;
        }
        return $this->styleResolver->imageIsFlexOrGridItem($link);
    }

    private static function isHiddenAccessibilitySupportElement(DOMElement $element): bool
    {
        $identity = strtolower(SourceDom::attr($element, 'id') . ' ' . SourceDom::attr($element, 'class'));
        if ( 1 !== preg_match('/(?:a11y|accessib|screen[-_]?reader|sr[-_]?only|visually[-_]?hidden)/', $identity) ) {
            return false;
        }

        return CssValueInspector::hidesBox(SourceDeclarations::inline($element));
    }

    /**
     * A synthetic paragraph only makes an inline source anchor valid block
     * markup: the anchor itself stays in the paragraph's content with its
     * own projection classes. Repeating those classes on the paragraph would
     * paint the anchor's author box (border, background, flex display) a
     * second time around it and take the carrier out of `display:contents`.
     */
    private static function withoutContentAnchorClasses(string $projectionClassName, string $baseClassName, string $content): string
    {
        if ( 1 !== preg_match('/^\s*<a\b[^>]*?\sclass="([^"]*)"/i', $content, $match) ) {
            return $projectionClassName;
        }
        $carried = array_flip(SourceDom::tokens($match[1]));
        $base = array_flip(SourceDom::tokens($baseClassName));
        $kept = array_filter(
            SourceDom::tokens($projectionClassName),
            static fn (string $class): bool => isset($base[$class]) || ! isset($carried[$class])
        );
        return implode(' ', $kept);
    }

    public function sourceProjectionClassName(DOMElement $element, SourceBlockAttributeProjectionContext $context, string $className = ''): string
    {
        $hostClassIdentities = array();
        if ( '' !== $context->authorStyles->combinedCss() ) {
            // The stylesheet projector rewrites every authored class selector
            // to its marker, whatever the token's length or position, so every
            // class on the element must carry its marker too.
            foreach ( preg_split('/\s+/', trim(SourceDom::attr($element, 'class')), -1, PREG_SPLIT_NO_EMPTY) ?: array() as $class ) {
                if ( $context->authorStyles->isHostRenderedClass($class)
                    && (in_array($class, SourceDom::tokens($className), true)
                        || in_array($class, FormDispatcher::hostRenderedClasses($element), true)) ) {
                    // CSS-owned form lowering may consume the readable class.
                    // The source identity still belongs on its emitted carrier.
                    $hostClassIdentities[] = EngineMarker::subjectClassClass($class);
                    $context->selectorProjections->recordHostClassCarrier($element->getNodePath() ?? '');
                }
                $marker = $context->authorStyles->sourceClassMarker($class);
                if ( '' !== $marker ) {
                    $className = SourceDom::mergeClassNames($className, $marker);
                }
            }
        }
        $sourceTagMarker = $context->selectorProjections->tagMarker(strtolower($element->tagName));
        if ( '' !== $sourceTagMarker ) {
            $className = SourceDom::mergeClassNames($className, $sourceTagMarker);
        }
        $semanticMarkers = $context->selectorProjections->sourceProjectionMarkersForPath($element->getNodePath() ?? '');
        if ( array() !== $semanticMarkers ) {
            $className = SourceDom::mergeClassNames($className, ...$semanticMarkers);
        }
        return SourceDom::mergeClassNames($className, ...$hostClassIdentities);
    }

    /** Modifiers are shared by native lowering and safe core/html reduction. */
    public function layoutNeutralButtonSourceBoxClasses(DOMElement $element): string
    {
        $declarations = SourceDeclarations::parse($this->styleResolver->mergedPresentationStyle($element));
        $classes = array();
        $display = CssValueInspector::declared($declarations, 'display');
        if ( in_array($display, array( 'inline-block', 'block', 'flex', 'inline-flex', 'inline-grid', 'inline-table' ), true) ) {
            $classes[] = self::LAYOUT_NEUTRAL_BUTTON_CLASS . '-display-' . $display;
        }
        foreach ( array( 'width', 'height' ) as $property ) {
            if ( '' !== trim((string) ($declarations[$property] ?? '')) ) {
                $classes[] = self::LAYOUT_NEUTRAL_BUTTON_CLASS . '-author-' . $property;
            }
        }
        if ( '' === trim((string) ($declarations['width'] ?? '')) && (
            $this->stretchesAcrossGridTrack($element, $declarations)
            || $this->hasAbsoluteHorizontalInsets($declarations)
        ) ) {
            // A stretched grid item's track, or an absolutely positioned box
            // pinned to both horizontal edges, has a source-owned width that
            // must survive the shrink-to-fit reset.
            $classes[] = self::LAYOUT_NEUTRAL_BUTTON_CLASS . '-author-width';
        }
        // A size the author states only under a condition (a phone-width
        // `.card .btn{width:100%}`) is projected under that condition. The
        // control rests shrink-to-fit, but through a default that projected
        // rule can outrank (see EngineSupportCss).
        $conditional = $this->styleResolver->authorDeclaredValuesAtAnyViewport($element, array( 'width', 'height' ));
        foreach ( array( 'width', 'height' ) as $property ) {
            $modifier = self::LAYOUT_NEUTRAL_BUTTON_CLASS . '-author-' . $property;
            $stated = array_filter($conditional[$property] ?? array(), static fn (string $value): bool => ! in_array(CssValueInspector::comparable($value), array( '', 'auto' ), true));
            if ( array() !== $stated && ! in_array($modifier, $classes, true) ) {
                $classes[] = self::LAYOUT_NEUTRAL_BUTTON_CLASS . '-conditional-' . $property;
            }
        }
        return implode(' ', $classes);
    }

    /** @param array<string, string> $declarations */
    private function hasAbsoluteHorizontalInsets(array $declarations): bool
    {
        if ( ! in_array(CssValueInspector::declared($declarations, 'position'), array( 'absolute', 'fixed' ), true) ) {
            return false;
        }
        $left = CssValueInspector::comparable((string) ($declarations['left'] ?? $declarations['inset-inline-start'] ?? ''));
        $right = CssValueInspector::comparable((string) ($declarations['right'] ?? $declarations['inset-inline-end'] ?? ''));
        if ( '' === $left && '' === $right && isset($declarations['inset']) ) {
            $inset = preg_split('/\\s+/', CssValueInspector::comparable((string) $declarations['inset'])) ?: array();
            $left = $inset[3] ?? $inset[1] ?? $inset[0] ?? '';
            $right = $inset[1] ?? $inset[0] ?? '';
        }
        return '' !== $left && 'auto' !== $left && '' !== $right && 'auto' !== $right;
    }

    /** @param array<string, string> $declarations */
    private function stretchesAcrossGridTrack(DOMElement $element, array $declarations): bool
    {
        $parent = $element->parentNode;
        if ( ! $parent instanceof DOMElement || LayoutParticipation::GRID_ITEM !== LayoutParticipation::resolve($element, $this->styleResolver)->context ) {
            return false;
        }
        $justifySelf = CssValueInspector::declared($declarations, 'justify-self');
        if ( in_array($justifySelf, array( '', 'auto', 'normal' ), true) ) {
            $parentDeclarations = SourceDeclarations::parse($this->styleResolver->mergedPresentationStyle($parent));
            $justifySelf = CssValueInspector::declared($parentDeclarations, 'justify-items');
        }
        return in_array($justifySelf, array( '', 'normal', 'stretch', 'legacy' ), true);
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
        $participation = in_array($name, array( 'core/button', 'core/buttons' ), true)
            ? LayoutParticipation::resolve($logicalControl, $this->styleResolver)
            : null;
        if ( $participation instanceof LayoutParticipation && $participation->neutralizeButtonsWrapper() && 'core/buttons' === $name ) {
            $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), self::LAYOUT_NEUTRAL_BUTTONS_CLASS);
        }
        if ( $participation instanceof LayoutParticipation && $participation->neutralizeButtonWrapper() && 'core/button' === $name ) {
            $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), self::LAYOUT_NEUTRAL_BUTTON_CLASS);
            $attrs['className'] = SourceDom::mergeClassNames((string) $attrs['className'], $this->layoutNeutralButtonSourceBoxClasses($logicalControl), (string) ($context->reducedButtonSourceBoxes[SourceDom::attr($logicalControl, 'id')] ?? ''));
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
                SourceDeclarations::parse($this->styleResolver->mergedPresentationStyle($logicalControl))
            )['style'] ?? array();
            if ( '' !== trim((string) ($logicalStyle['color']['background'] ?? '')) ) {
                $attrs['style']['color']['background'] = $logicalStyle['color']['background'];
            }
        }
        if ( $facts->hasAuthorControlProjection ) {
            $controlMarker = '' !== $logicalControlPath ? $context->selectorProjections->ensureControlMarker($logicalControlPath) : '';
            if ( '' !== $controlMarker ) {
                $attrs['className'] = SourceDom::mergeClassNames(
                    (string) ($attrs['className'] ?? ''),
                    ...$context->selectorProjections->controlCarrierClasses($logicalControlPath)
                );
                if ( 'core/button' === $name ) {
                    $this->generatedStyleProjector->registerNativeButtonStyleRule($controlMarker, $attrs, $context->generatedStyles, $nativeButtonTextAlignment, $logicalControl);
                    $blockColor = is_array($attrs['style']['color'] ?? null) ? $attrs['style']['color'] : array();
                    $this->generatedStyleProjector->registerNativeButtonDefaultBackgroundGuard(
                        $controlMarker,
                        $logicalControl,
                        $context->generatedStyles,
                        '' !== trim((string) ($blockColor['background'] ?? ''))
                    );
                    // A collapsed surface can own dimensions even when the
                    // logical anchor is inline and unsized. Its geometry carrier
                    // lives on Core's display:contents wrapper, so size the painted
                    // link instead and opt out of the layout-neutral axis resets.
                    if ( $sourceElement !== $logicalControl && $participation instanceof LayoutParticipation && $participation->neutralizeButtonWrapper() ) {
                        $surface = SourceDeclarations::parse($this->styleResolver->mergedPresentationStyle($sourceElement));
                        // Offsets on a display:contents carrier have no effect.
                        // Use the absorbed wrapper, not its 100%-sized inner paint
                        // surface, as the positioning authority (even when unsized).
                        $position = CssValueInspector::declared($surface, 'position');
                        if ( in_array($position, array( 'absolute', 'fixed', 'relative', 'sticky' ), true) ) {
                            $positioning = array('position:' . $position);
                            foreach ( array( 'top', 'right', 'bottom', 'left', 'inset', 'inset-block', 'inset-inline', 'inset-block-start', 'inset-block-end', 'inset-inline-start', 'inset-inline-end', 'z-index' ) as $property ) {
                                if ( isset($surface[$property]) ) $positioning[] = $property . ':' . $surface[$property];
                            }
                            $context->generatedStyles->appendNativeButton($controlMarker, '.' . $controlMarker . '.' . $controlMarker . '>.wp-block-button__link{' . implode(';', $positioning) . '}');
                        }
                        $dimensions = array();
                        foreach ( array( 'width', 'height' ) as $axis ) {
                            $value = trim((string) ($surface[$axis] ?? ''));
                            if ( '' !== $value && ! in_array(CssValueInspector::comparable($value), array( 'auto', 'initial', 'inherit', 'unset', 'revert', 'revert-layer' ), true) ) {
                                $dimensions[] = $axis . ':' . $value;
                                $attrs['className'] = SourceDom::mergeClassNames((string) $attrs['className'], self::LAYOUT_NEUTRAL_BUTTON_CLASS . '-author-' . $axis);
                            }
                        }
                        if ( array() !== $dimensions ) {
                            // The sized wrapper can contain the actual painted flex
                            // surface. Its content-box sizing and default zero padding
                            // must not turn into Core's border-box/padded button box.
                            $paint = array_replace($this->styleResolver->cascade->declarations($sourceElement), $surface);
                            $candidate = $sourceElement;
                            while ( 1 === $candidate->childElementCount ) {
                                $child = $candidate->firstElementChild;
                                if ( ! $child instanceof DOMElement || trim($child->textContent) !== trim($sourceElement->textContent) ) break;
                                $childStyle = array_replace($this->styleResolver->cascade->declarations($child), SourceDeclarations::parse($this->styleResolver->mergedPresentationStyle($child)));
                                if ( in_array(CssValueInspector::declared($childStyle, 'display'), array( 'flex', 'inline-flex' ), true) ) {
                                    $paint = array_replace($childStyle, $this->styleResolver->authoredControlPresentation($child, array( 'box-sizing', 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left' ))[''] ?? array());
                                    $paint = SourceDeclarations::parse($this->styleResolver->cssDeclarationString($paint));
                                    break;
                                }
                                $candidate = $child;
                            }
                            $display = CssValueInspector::declared($paint, 'display');
                            $flex = in_array($display, array( 'flex', 'inline-flex' ), true);
                            $attrs['className'] = SourceDom::mergeClassNames((string) $attrs['className'], self::LAYOUT_NEUTRAL_BUTTON_CLASS . '-display-' . ($flex ? $display : 'inline-block'));
                            $declarations = array_merge(array('box-sizing:' . ($paint['box-sizing'] ?? 'content-box')), $dimensions);
                            $declarations[] = 'padding:' . ($paint['padding'] ?? '0');
                            foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
                                if ( isset($paint['padding-' . $side]) ) $declarations[] = 'padding-' . $side . ':' . $paint['padding-' . $side];
                            }
                            if ( $flex ) {
                                foreach ( array( 'flex-direction' => 'row', 'align-items' => 'stretch', 'justify-content' => 'normal' ) as $property => $initial ) {
                                    $declarations[] = $property . ':' . ($paint[$property] ?? $initial);
                                }
                            }
                            $context->generatedStyles->appendNativeButton($controlMarker, '.' . $controlMarker . '.' . $controlMarker . '>.wp-block-button__link{' . implode(';', $declarations) . '}');
                        }
                    }
                    $childOwnedOffsets = $participation instanceof LayoutParticipation && $participation->positioned;
                    if ( $participation instanceof LayoutParticipation && $participation->needsDirectFlexRepair() ) {
                        $this->generatedStyleProjector->registerDirectFlexButton($controlMarker, $logicalControl, $context->generatedStyles);
                    }
                    if ( ! $childOwnedOffsets ) {
                        $this->registerButtonWidth($attrs, $controlMarker, $logicalControl, $context, $participation instanceof LayoutParticipation && $participation->neutralizeButtonWrapper());
                    }
                }
            }
            if ( '' !== $controlMarker && '' !== $presentationPath && $presentationPath !== $logicalControlPath ) {
                // The collapsed surface has its own selector identity, even though
                // navigation and layout still belong to the logical control.
                $attrs['className'] = SourceDom::mergeClassNames(
                    (string) ($attrs['className'] ?? ''),
                    ...$context->selectorProjections->buttonPresentationCarrierClasses($presentationPath)
                );
                $context->selectorProjections->installButtonPresentationMarker($presentationPath, $controlMarker);
            }
            // core/button unwraps the label into RichText. Keep its selector
            // surface distinct from the link that owns the control chrome.
            if ( '' !== $controlMarker && 'core/button' === $name && array() === $labelProjectionPaths && array() !== $labelPaths ) {
                $this->generatedStyleProjector->registerFlattenedButtonLabelTextLayout($controlMarker, $logicalControl, $context->generatedStyles);
            }
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
            $this->registerButtonWidth($attrs, $nativeButtonMarker, $logicalControl, $context, $participation instanceof LayoutParticipation && $participation->neutralizeButtonWrapper());
        }
        if ( 'core/button' === $name
            && 1 === preg_match('/(?:^|\s)(blocks-engine-native-button-alignment-(?:start|end|left|center|right))(?:\s|$)/', (string) ($attrs['className'] ?? ''), $alignmentMarker)
        ) {
            $guardMarker = $context->authorStyles->allocateMarker('native-button');
            if ( $this->generatedStyleProjector->registerNativeButtonDefaultBackgroundGuard(
                $guardMarker,
                $logicalControl,
                $context->generatedStyles,
                '' !== trim((string) ($attrs['style']['color']['background'] ?? ''))
            ) ) {
                $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $guardMarker);
            }
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
        // A label flattened to plain text no longer owns a separate paint surface.
        if (1 !== preg_match('/<(?:span|mark|font|em|strong|b|i|small)\b[^>]*\s(?:class|style|color)=/i', $label)) {
            return false;
        }
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

    /**
     * Projects the `width` attribute {@see LayoutParticipation::widthPreset()}
     * already decided into generated CSS. core/button's `dimensions.width`
     * support is `__experimentalSkipSerialization`, so the canonical attribute
     * never produces CSS on its own — this is the explicit collaborator that
     * makes it render: the 25/50/75 branch fills the control as a flex item
     * inside `.wp-block-buttons`, and the 100 branch blockifies the wrapper
     * chain so the percentage resolves against a definite ancestor.
     *
     * @param array<string, mixed> $attrs
     */
    private function registerButtonWidth(array $attrs, string $marker, DOMElement $sourceControl, SourceBlockAttributeProjectionContext $context, bool $wrappersNeutralized): void
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
        $this->generatedStyleProjector->registerButtonWidth($marker, $buttonWidth, $context->generatedStyles, $wrappersNeutralized);
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
            $display = CssValueInspector::comparable((string) (SourceDeclarations::parse($child->getAttribute('style'))['display'] ?? ''));
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

    /** @return array<string,mixed> */
    public function syntheticInlineParagraphAttributes(DOMElement $container, bool $inheritsWrapperTypography = false): array
    {
        // This carrier adds neither a source box nor a typography boundary.
        // In particular, a theme's paragraph rules must not interrupt the
        // typography of an inline source wrapper lowered to a group. Keep
        // inheritance live so responsive wrapper declarations still apply.
        $attrs = array('className' => self::SYNTHETIC_PARAGRAPH_CLASS);
        if ($inheritsWrapperTypography) {
            $attrs['style'] = array(
                'typography' => array(
                    'fontSize' => 'inherit',
                    'fontWeight' => 'inherit',
                    'lineHeight' => 'inherit',
                    'fontFamily' => 'inherit',
                ),
                'color' => array('text' => 'inherit'),
            );
        }
        $anchors = $container->getElementsByTagName('a');
        if (0 === $anchors->length) return $attrs;
        foreach ($anchors as $anchor) if (!$anchor instanceof DOMElement || !$this->sourceAnchorHasNoTextDecoration($anchor)) return $attrs;
        $attrs['className'] .= ' ' . self::SYNTHETIC_ANCHOR_UNDECORATED_CLASS;
        return $attrs;
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
        // Decoration determines the generated anchor carrier, even for an
        // otherwise ordinary link that the presentation fast path skips.
        foreach ( $this->styleResolver->cascade->declarations($element) as $property => $value ) {
            if ( 'text-decoration' === $property || 'text-decoration-line' === $property ) {
                $declared = CssValueInspector::comparable($this->styleResolver->customProperties->resolve($value));
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

    private function anchorPrecedesBlock(DOMElement $anchor): bool
    {
        // Navigation lowering owns the layout of brand links beside a list.
        // The list becomes a Navigation block, not an ordinary following Group.
        $parent = $anchor->parentNode;
        if ( $parent instanceof DOMElement
            && ( 'nav' === strtolower($parent->tagName) || 'navigation' === strtolower(SourceDom::attr($parent, 'role')) ) ) {
            return false;
        }
        $next = $anchor->nextSibling;
        while ( $next instanceof \DOMText && '' === trim($next->textContent) ) {
            $next = $next->nextSibling;
        }
        // A following flow container or heading can overlap the boxless root.
        // Paragraphs, lists and shell landmarks have separate lowering owners;
        // changing their preceding links also changes shared-shell identity.
        if ( ! $next instanceof DOMElement
            || ! in_array(strtolower($next->tagName), array('div', 'section', 'article', 'aside', 'main', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'), true)
            || in_array(strtolower(SourceDom::attr($next, 'role')), array('banner', 'navigation', 'contentinfo'), true) ) {
            return false;
        }
        $display = CssValueInspector::comparable((string) ($this->styleResolver->structuralPresentationDeclarations($next)['display'] ?? ''));
        return in_array($display, array('', 'block', 'flex', 'grid', 'flow-root', 'table'), true);
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
        if ( null === SourceDom::ancestorElement($anchor, 'header') ) {
            return $attrs;
        }
        $direct = SourceDeclarations::parse($this->styleResolver->specificityResolvedPresentationStyle($anchor));
        $declarations = array();
        if ( 'inherit' === strtolower(trim((string) ($direct['color'] ?? ''))) ) {
            $inheritedColor = $this->styleResolver->authoredInheritedPropertyWinner($anchor, 'color');
            if ( '' !== $inheritedColor ) {
                $declarations['color'] = $inheritedColor;
            }
        }
        foreach ( array( 'display', 'align-items', 'justify-content' ) as $property ) {
            $value = trim((string) ($direct[$property] ?? ''));
            // A value the source switches under a media query stays with the
            // author rules: restating only its base value on the carrier
            // would outrank the source's own breakpoint switch.
            if ( array() !== $this->styleResolver->declaredPresentation($anchor, $property)->conditional() ) {
                continue;
            }
            if ( '' !== $value && ! str_contains(strtolower($value), '!important') ) {
                $declarations[$property] = $this->styleResolver->customProperties->resolve($value);
            }
        }
        foreach ( $this->styleResolver->specificityResolvedGapDeclarations($anchor) as $property => $value ) {
            if ( ! str_contains(strtolower($value), '!important') ) {
                $declarations[$property] = $this->styleResolver->customProperties->resolve($value);
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

    /**
     * Whether an author rule names the tag as a type with an effect a `div`
     * would not already have. `display:block` (the normalize.css reset for
     * sectioning tags) is a div's own display.
     */
    private static function authorCssSelectsTag(AuthorStyleAnalysis $authorStyles, string $tagName): bool
    {
        $pattern = '/(?<![\w.#-])' . preg_quote($tagName, '/') . '(?![\w-])/i';
        foreach ( $authorStyles->styleRules() as $rule ) {
            $declarations = (array) ( $rule['declarations'] ?? array() );
            if ( 1 === count($declarations) && 'block' === strtolower(trim((string) ( $declarations['display'] ?? '' ))) ) {
                continue;
            }
            foreach ( (array) ( $rule['selectors'] ?? array() ) as $selector ) {
                $text = is_array($selector) ? (string) ( $selector['selector'] ?? '' ) : (string) $selector;
                // Attribute values and strings can hold the word; strip them first.
                $text = preg_replace('/\[[^\]]*\]|"[^"]*"|\'[^\']*\'/', '', $text) ?? $text;
                if ( 1 === preg_match($pattern, $text) ) {
                    return true;
                }
            }
        }
        return false;
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
}

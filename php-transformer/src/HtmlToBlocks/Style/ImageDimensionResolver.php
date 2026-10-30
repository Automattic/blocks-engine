<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\HtmlDimensionAttribute;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/**
 * The width and height core/image can carry for a source image.
 *
 * core/image serializes a single `width` and `height`, so a source dimension has
 * to be reduced to one viewport-invariant CSS length: the inline style first,
 * then a stylesheet declaration that no media query conditions, then the HTML
 * attribute. A dimension a media query varies is left to the projected
 * stylesheet, which still resolves it per viewport.
 *
 * This is source-reading and cascade work that lived in HtmlCompilation because
 * that is where the image converter calls it from, not because it belongs to the
 * compilation. It needs a style resolver and nothing else.
 */
final class ImageDimensionResolver
{
    public function __construct(private readonly StyleResolver $styles)
    {
    }

    public function imageDisplayDimension(DOMElement $image, string $property): string
    {
        $declarations = $this->styles->cssDeclarations(SourceDom::attr($image, 'style'));
        $inline = trim(CssValueInspector::withoutImportant((string) ($declarations[ $property ] ?? '')));
        $other = 'width' === $property ? 'height' : 'width';
        $otherInline = trim(CssValueInspector::withoutImportant((string) ($declarations[ $other ] ?? '')));
        if ( $this->imageViewportPairFillsParent($inline, $otherInline) ) {
            return '100%';
        }
        if ( '' !== $inline && ! in_array(strtolower($inline), array( 'auto', 'inherit', 'initial', 'unset', 'revert', 'revert-layer' ), true) ) {
            return $this->imageDimensionValue($inline);
        }
        if ( $this->styles->imageAxisVariesByViewport($image, $property) ) {
            // A responsive rule resizes this axis: the projected stylesheet
            // resolves it per viewport, and neither the desktop value nor the
            // HTML attribute may be pinned inline over it.
            return '';
        }
        $stylesheet = $this->imageStylesheetDimension($image, $property);
        if ( '' !== $stylesheet ) {
            return $this->imageDimensionValue($stylesheet);
        }
        if ( $this->authorResolvesDimensionToAuto($image, $property) ) {
            // Author CSS is not silent on this axis -- it explicitly neutralises
            // it (Tailwind's `w-auto`/`h-auto`, or a plain `width:auto`). That is
            // a real instruction to derive this axis from the OTHER axis plus the
            // image's own aspect ratio, not an absence of one. Falling through to
            // the intrinsic width/height HTML attribute here would defeat it: a
            // core/image style carrying that attribute's raw pixel value (e.g.
            // `width:1536px` from a 1536x1024 source file) fixes this axis after
            // all, then the OTHER, author-constrained axis is the one left to be
            // clamped by whatever `max-width`/`max-height` box constraint the
            // figure carries -- which is exactly backwards from what `auto` asked
            // for. Emitting no dimension for this axis at all leaves the browser
            // to compute it from the constrained axis and the image's real
            // (loaded) aspect ratio, the same computation the source page itself
            // relied on `auto` to do.
            return '';
        }
        return HtmlDimensionAttribute::cssLength(SourceDom::attr($image, $property));
    }

    /**
     * The image's used size in CSS pixels on each axis that is fixed, or null
     * for an axis that is not.
     *
     * An axis is fixed when {@see imageDisplayDimension()} -- the same
     * inline/stylesheet/attribute resolution core/image emission uses --
     * yields an absolute length, or yields `100%` and the image fills a
     * sizing wrapper whose own size on that axis is a fixed length. That
     * covers an avatar sized by `.av{width:36px;height:36px} .av img{width:100%;
     * height:100%}` exactly like one sized by `width="36" height="36"`.
     *
     * @return array{width: ?float, height: ?float}
     */
    public function fixedPixelSize(DOMElement $image): array
    {
        return array(
            'width' => $this->fixedPixelDimension($image, 'width'),
            'height' => $this->fixedPixelDimension($image, 'height'),
        );
    }

    /** {@see fixedPixelSize()} for one axis. */
    public function fixedPixelDimension(DOMElement $image, string $property): ?float
    {
        $parentSizing = $this->parentSizedDimension($image, $property);
        if ( '' !== $parentSizing ) {
            return $this->fillingWrapperPixelDimension($image, $property, 'inherit' === $parentSizing);
        }

        return $this->absolutePixelLength($this->imageDisplayDimension($image, $property), $image);
    }

    /**
     * How this image axis takes its size from its parent box, if it does:
     * `100%` when it fills the parent, `inherit` when it inherits the parent's
     * specified length, or '' when the axis is sized some other way.
     *
     * This is the one rule for "this image dimension is the parent's size":
     * both {@see fixedPixelDimension()} and core/image emission ask it before
     * replacing a parent-relative dimension with the parent's fixed length.
     * `inherit` is only honoured when author CSS states it for the axis at
     * every viewport; a responsive rule leaves the axis to the projected
     * stylesheet.
     */
    public function parentSizedDimension(DOMElement $image, string $property): string
    {
        if ( '100%' === $this->imageDisplayDimension($image, $property) ) {
            return '100%';
        }
        if ( $this->styles->imageAxisVariesByViewport($image, $property) ) {
            return '';
        }
        $declaration = $this->styles->imageShapeDeclarations($image)[$property] ?? array();
        if ( ! is_array($declaration) || ! $this->imageStylesheetDimensionIsViewportInvariant($declaration['conditions'] ?? array()) ) {
            return '';
        }

        return 'inherit' === CssValueInspector::comparable(CssValueInspector::withoutImportant((string) ($declaration['value'] ?? '')))
            ? 'inherit'
            : '';
    }

    /**
     * The fixed length of the wrapper a `100%`-sized image fills on this axis.
     *
     * Walks up through wrappers the image (or the wrapper below) is the only
     * content of. An in-flow, block-level wrapper whose size on the axis is
     * unstated, `auto` or `100%` passes the box through; the first wrapper that
     * states an absolute length is the box the image fills. Anything else --
     * shared content, padding that insets the box, floats, out-of-flow or
     * flex/grid-item wrappers whose auto size depends on their content, a size
     * a media query varies -- is not a fixed box, and the axis stays unfixed.
     *
     * An `inherit`ed axis takes the parent's specified value instead of a
     * share of its box: the parent's absolute length is the image's size
     * (its padding does not inset an inherited length), a parent that is
     * itself `100%` continues as a fill, and a parent that is unstated or
     * `auto` leaves the image `auto`, so the axis stays unfixed.
     */
    private function fillingWrapperPixelDimension(DOMElement $image, string $property, bool $inherits = false): ?float
    {
        $paddingProperties = 'width' === $property
            ? array( 'padding', 'padding-left', 'padding-right', 'padding-inline', 'padding-inline-start', 'padding-inline-end' )
            : array( 'padding', 'padding-top', 'padding-bottom', 'padding-block', 'padding-block-start', 'padding-block-end' );
        $node = $image;
        for ( $depth = 0; $depth < 6; ++$depth ) {
            $wrapper = $node->parentNode;
            if ( ! $wrapper instanceof DOMElement
                || in_array(strtolower($wrapper->tagName), array( 'body', 'html' ), true)
                || ! $this->isSoleContent($wrapper, $node)
                || $this->styles->imageAxisVariesByViewport($wrapper, $property) ) {
                return null;
            }
            $declarations = $this->styles->referenceViewportAuthorDeclarations(
                $wrapper,
                array_merge(array( $property, 'display', 'position', 'float' ), $paddingProperties)
            );
            $value = CssValueInspector::comparable(CssValueInspector::withoutImportant((string) ($declarations[ $property ] ?? '')));
            if ( $inherits ) {
                if ( 'inherit' === $value ) {
                    $node = $wrapper;
                    continue;
                }
                if ( in_array($value, array( '', 'auto' ), true) ) {
                    return null;
                }
                $inherits = false;
                if ( '100%' !== $value ) {
                    return $this->absolutePixelLength($value, $wrapper);
                }
            }
            foreach ( $paddingProperties as $padding ) {
                if ( ! $this->isZeroLength(CssValueInspector::comparable((string) ($declarations[ $padding ] ?? ''))) ) {
                    return null;
                }
            }

            if ( ! in_array($value, array( '', 'auto', '100%' ), true) ) {
                return $this->absolutePixelLength($value, $wrapper);
            }
            if ( ! $this->passesFillThrough($wrapper, $declarations) ) {
                return null;
            }
            $node = $wrapper;
        }

        return null;
    }

    /** @param array<string, string> $declarations */
    private function passesFillThrough(DOMElement $wrapper, array $declarations): bool
    {
        $display = CssValueInspector::comparable((string) ($declarations['display'] ?? ''));
        if ( '' === $display ) {
            $display = $this->styles->defaultTagDisplay($wrapper);
        }
        $position = CssValueInspector::comparable((string) ($declarations['position'] ?? ''));
        $float = CssValueInspector::comparable((string) ($declarations['float'] ?? ''));
        if ( ! in_array($display, array( 'block', 'flow-root', 'flex', 'grid', 'list-item' ), true)
            || in_array($position, array( 'absolute', 'fixed' ), true)
            || ! in_array($float, array( '', 'none' ), true) ) {
            return false;
        }
        $container = $wrapper->parentNode;
        if ( ! $container instanceof DOMElement ) {
            return false;
        }
        $containerDisplay = CssValueInspector::comparable((string) ($this->styles->referenceViewportAuthorDeclarations($container, array( 'display' ))['display'] ?? ''));

        return ! in_array($containerDisplay, array( 'flex', 'inline-flex', 'grid', 'inline-grid' ), true);
    }

    private function isSoleContent(DOMElement $wrapper, DOMElement $child): bool
    {
        foreach ( $wrapper->childNodes as $node ) {
            if ( $node instanceof DOMElement ) {
                if ( ! $node->isSameNode($child) ) {
                    return false;
                }
            } elseif ( $node instanceof \DOMText && '' !== trim($node->textContent) ) {
                return false;
            }
        }

        return true;
    }

    private function isZeroLength(string $value): bool
    {
        foreach ( preg_split('/\s+/', trim($value)) ?: array() as $token ) {
            if ( '' !== $token && 1 !== preg_match('/^[+-]?0*(?:\.0+)?(?:[a-z]+|%)?$/', $token) ) {
                return false;
            }
        }

        return true;
    }

    private function absolutePixelLength(string $value, DOMElement $context): ?float
    {
        if ( 1 !== preg_match('/^((?:\d+|\d*\.\d+))(px|rem)$/i', trim($value), $matches) ) {
            return null;
        }
        $length = (float) $matches[1];
        if ( 'rem' === strtolower($matches[2]) ) {
            $root = $this->styles->documentRootFontSize($context);
            if ( null === $root ) {
                return null;
            }
            $length *= $root;
        }

        return $length;
    }

    /**
     * Whether author CSS, not the image's intrinsic size, sets its rendered
     * width wherever it is displayed.
     *
     * A viewport-invariant dimension ({@see imageDisplayDimension()}) answers
     * this directly. Otherwise the width is read from the author cascade at
     * the desktop and mobile reference viewports. A captured responsive
     * document variant is displayed only inside its own viewport branch, so a
     * reference viewport where that document is `display:none` places no
     * demand on the image: a width declared in that branch's
     * `@media`-conditioned stylesheet still covers every viewport the image
     * renders at.
     */
    public function hasAuthorDisplayWidth(DOMElement $image): bool
    {
        if ( '' !== $this->imageDisplayDimension($image, 'width') ) {
            return true;
        }
        $document = $image->parentNode;
        while ( $document instanceof DOMElement && ! SourceDom::isDocumentVariantRoot($document) ) {
            $document = $document->parentNode;
        }
        $displayed = false;
        foreach ( array( 'referenceViewportAuthorDeclarations', 'collapsedViewportAuthorDeclarations' ) as $atViewport ) {
            if ( $document instanceof DOMElement && 'none' === CssValueInspector::comparable(CssValueInspector::withoutImportant($this->styles->$atViewport($document, array( 'display' ))['display'] ?? '')) ) {
                continue;
            }
            $displayed = true;
            if ( '' === $this->imageDimensionValue(CssValueInspector::withoutImportant($this->styles->$atViewport($image, array( 'width' ))['width'] ?? '')) ) {
                return false;
            }
        }

        return $displayed;
    }

    /**
     * Whether author CSS -- inline or a matched stylesheet rule, cascade-
     * resolved at the desktop reference viewport the same way {@see
     * imageStylesheetDimension()} resolves a usable length -- states this axis
     * is `auto` (or a keyword computing to the same initial value) rather than
     * simply never declaring it. Kept distinct from `imageStylesheetDimension()`
     * returning '' for viewport-conditioned or absent declarations, because
     * only an author-STATED `auto` carries the "derive me from the other axis"
     * instruction; a property nobody declares carries no instruction and must
     * keep falling back to the HTML attribute below.
     *
     * Public so {@see StyleResolver::imageBoxConstraintClassName()} can reuse
     * this exact detection to decide whether the be-inline-geometry carrier
     * rule needs to restate the same axis as `auto` for the descendant
     * `<img>` -- rather than re-deriving "did the author say auto" a second
     * time from the same declarations.
     */
    public function authorResolvesDimensionToAuto(DOMElement $image, string $property): bool
    {
        $value = strtolower(trim(CssValueInspector::withoutImportant(
            (string) ($this->styles->imageShapeDeclarations($image)[$property]['value'] ?? '')
        )));

        return in_array($value, array( 'auto', 'initial', 'unset' ), true);
    }

    /** A viewport-invariant source dimension that native core/image can carry. */
    public function imageStylesheetDimension(DOMElement $image, string $property): string
    {
        $declaration = $this->styles->imageShapeDeclarations($image)[$property] ?? array();
        if (!is_array($declaration) || ! $this->imageStylesheetDimensionIsViewportInvariant($declaration['conditions'] ?? array())) {
            return '';
        }
        $value = trim(CssValueInspector::withoutImportant((string) ($declaration['value'] ?? '')));
        return in_array(strtolower($value), array( '', 'auto', 'inherit', 'initial', 'unset', 'revert', 'revert-layer' ), true) ? '' : $value;
    }

    /** @param mixed $conditions */
    private function imageStylesheetDimensionIsViewportInvariant(mixed $conditions): bool
    {
        if (!is_array($conditions)) {
            return false;
        }
        foreach ($conditions as $condition) {
            if (!is_string($condition) || !preg_match('/^@layer\b/i', trim($condition))) {
                return false;
            }
        }
        return true;
    }

    /**
     * Keep core/image dimensions to CSS lengths WordPress can serialize safely.
     *
     * core/image save() writes `width`/`height` into the <img> style verbatim,
     * so a bare number has to gain its `px` here: an attribute of `800` saves
     * as `width:800`, which no longer matches stored `width:800px` markup and
     * invalidates the block in the editor. Linked images are no exception.
     */
    private function imageDimensionValue(string $value): string
    {
        $value = trim($value);
        if (1 !== preg_match('/^(?:\d+|\d*\.\d+)(?:%|px|r?em|ex|ch|lh|rlh|vw|vh|vmin|vmax|vi|vb|cm|mm|q|in|pt|pc)?$/i', $value)) {
            return '';
        }
        return preg_match('/^(?:\d+|\d*\.\d+)$/', $value) ? $value . 'px' : $value;
    }

    /**
     * A capture often inlines the stylesheet's viewport pair onto the img while
     * the live document later pins integer px from the already-sized parent.
     * Filling that parent keeps the designed box instead of a truncated vw.
     */
    private function imageViewportPairFillsParent(string $first, string $second): bool
    {
        return 1 === preg_match('/^(?:\d+|\d*\.\d+)vw$/i', $first)
            && 1 === preg_match('/^(?:\d+|\d*\.\d+)v(?:w|h)$/i', $second);
    }

    public function fillParentImageViewportPair(DOMElement $image): void
    {
        $declarations = $this->styles->cssDeclarations(SourceDom::attr($image, 'style'));
        $width = trim(CssValueInspector::withoutImportant((string) ($declarations['width'] ?? '')));
        $height = trim(CssValueInspector::withoutImportant((string) ($declarations['height'] ?? '')));
        if ( ! $this->imageViewportPairFillsParent($width, $height) ) {
            return;
        }
        $declarations['width'] = '100%';
        $declarations['height'] = '100%';
        $style = array();
        foreach ( $declarations as $property => $value ) {
            $style[] = $property . ':' . $value;
        }
        $image->setAttribute('style', implode(';', $style));
    }
}

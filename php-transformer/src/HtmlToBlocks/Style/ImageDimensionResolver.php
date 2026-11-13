<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
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

    /**
     * Resolve the shape constraint the authored CSS applies to the <img> at the
     * desktop viewport, and carry it as native core/image attributes.
     *
     * This applies to ANY image whose resolved CSS carries the pair, however the
     * rule reaches it -- a rule on the image itself, a blanket `img` rule, or a
     * descendant rule. The flattened wrapper (`.hero-frame img`) is the
     * motivating case rather than the boundary: core/image has no slot for such
     * a wrapper, so once the carrier is dropped the constraint survives only as
     * block attributes. An image already matching the pair loses nothing by
     * having it stated natively.
     *
     * Conservative by design: emit aspectRatio/scale only when the resolved
     * style carries BOTH an explicit, well-formed aspect-ratio AND
     * object-fit:cover|contain. Plain images gain nothing.
     *
     * @return array<string, string>
     */
    public function shapeConstraintAttributes(DOMElement $image, string $width, string $height): array
    {
        $declarations = $this->styles->imageShapeDeclarations($image);
        $aspectRatio  = $this->normalizedAspectRatio(
            (string) ($declarations['aspect-ratio']['value'] ?? '')
        );
        // `object-fit:cover !important` is a common defence against core's
        // `.wp-block-image img` rules. Strip importance symmetrically with
        // normalizedAspectRatio, or the keyword never matches the allowlist below
        // and the whole promotion silently declines.
        $scale        = strtolower(CssValueInspector::withoutImportant(
            $this->styles->resolveCssVariablesInValue(
                (string) ($declarations['object-fit']['value'] ?? ''),
                $image
            )
        ));

        if ( ! in_array($scale, array( 'cover', 'contain' ), true) ) {
            return array();
        }

        // A zero authored box is serializable but core image crop attributes
        // would manufacture positive geometry. Negative HTML dimensions are not
        // serializable by core/image and are discarded before block creation.
        // In either case, fail closed rather than promoting the crop.
        if ($this->imageHasNonPositiveDimension($image, 'width') || $this->imageHasNonPositiveDimension($image, 'height')) {
            return array();
        }

        if ( '' === $aspectRatio
            || ($this->imageDimensionsDetermineDifferentAspectRatio($width, $height, $aspectRatio)
                && (($declarations['aspect-ratio']['inline'] ?? false) === true
                    || ! $this->imageDimensionsAreIntrinsicAttributes($image)))
        ) {
            if ( ($declarations['object-fit']['inline'] ?? false) === true || $this->imageShapeFillsBox($declarations) ) {
                return array( 'scale' => $scale );
            }

            return array();
        }

        return array(
            'aspectRatio' => $aspectRatio,
            'scale'       => $scale,
        );
    }

    /** @param array<string, array<string, mixed>> $declarations */
    private function imageShapeFillsBox(array $declarations): bool
    {
        foreach ( array( 'width', 'height' ) as $property ) {
            $value = strtolower(CssValueInspector::withoutImportant((string) ($declarations[$property]['value'] ?? '')));
            if ( '100%' === $value ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize a CSS aspect-ratio value to WordPress' attribute form (`4/3`,
     * `1`). Returns '' for auto/keyword/compound values the crop cannot rely on.
     *
     * A zero component is also refused. `aspect-ratio:0/3` is a degenerate ratio
     * that collapses the box to nothing; authored CSS gets away with it because
     * some other declaration constrains the element, but the promoted block
     * attribute carries no such company.
     */
    private function normalizedAspectRatio(string $value): string
    {
        $value = strtolower(CssValueInspector::withoutImportant($value));
        if ( '' === $value || in_array($value, array( 'auto', 'inherit', 'initial', 'unset', 'revert', 'revert-layer', 'none' ), true) ) {
            return '';
        }

        if ( preg_match('#^(\d*\.?\d+)\s*/\s*(\d*\.?\d+)$#', $value, $matches) ) {
            if ( 0.0 === (float) $matches[1] || 0.0 === (float) $matches[2] ) {
                return '';
            }

            return $matches[1] . '/' . $matches[2];
        }

        if ( preg_match('#^\d*\.?\d+$#', $value) ) {
            return 0.0 === (float) $value ? '' : $value;
        }

        return '';
    }

    private function imageDimensionsDetermineDifferentAspectRatio(string $width, string $height, string $aspectRatio): bool
    {
        if (! preg_match('/^(\d*\.?\d+)([a-z]+)$/i', $width, $widthMatch)
            || ! preg_match('/^(\d*\.?\d+)([a-z]+)$/i', $height, $heightMatch)
            || strtolower($widthMatch[2]) !== strtolower($heightMatch[2])
            || ! preg_match('#^(\d*\.?\d+)(?:\s*/\s*(\d*\.?\d+))?$#', $aspectRatio, $ratioMatch)
            || 0.0 >= (float) $widthMatch[1]
            || 0.0 >= (float) $heightMatch[1]
            || 0.0 >= (float) $ratioMatch[1]
            || 0.0 >= (float) ($ratioMatch[2] ?? '1')) {
            return false;
        }
        $ratio = (float) $ratioMatch[1] / (float) ($ratioMatch[2] ?? '1');
        return abs(((float) $widthMatch[1] / (float) $heightMatch[1]) - $ratio) > 0.000001;
    }

    /**
     * Whether a fixed width and height in one absolute unit already determine
     * exactly the given aspect ratio. Percentages, mixed units and missing
     * axes do not: they leave the box to the stylesheet-owned crop.
     */
    public function statesAspectRatio(string $width, string $height, string $aspectRatio): bool
    {
        if (! preg_match('/^(\d*\.?\d+)(px|r?em|cm|mm|in|pt|pc)$/i', $width, $widthMatch)
            || ! preg_match('/^(\d*\.?\d+)(px|r?em|cm|mm|in|pt|pc)$/i', $height, $heightMatch)
            || strtolower($widthMatch[2]) !== strtolower($heightMatch[2])
            || 0.0 >= (float) $widthMatch[1]
            || 0.0 >= (float) $heightMatch[1]
            || ! preg_match('#^(\d*\.?\d+)(?:\s*/\s*(\d*\.?\d+))?$#', $aspectRatio)) {
            return false;
        }

        return ! $this->imageDimensionsDetermineDifferentAspectRatio($width, $height, $aspectRatio);
    }

    /** Whether the emitted dimensions came only from the image file metadata. */
    private function imageDimensionsAreIntrinsicAttributes(DOMElement $image): bool
    {
        $declarations = SourceDeclarations::inline($image);
        foreach (array( 'width', 'height' ) as $property) {
            $value = trim(CssValueInspector::withoutImportant((string) ($declarations[$property] ?? '')));
            if ('' !== $value && !in_array(strtolower($value), array( 'auto', 'inherit', 'initial', 'unset', 'revert', 'revert-layer' ), true)) {
                return false;
            }
        }
        return '' !== trim(SourceDom::attr($image, 'width')) && '' !== trim(SourceDom::attr($image, 'height'));
    }

    public function imageDimensionIsIntrinsicAttribute(DOMElement $image, string $property): bool
    {
        $declarations = SourceDeclarations::inline($image);
        $inline = trim(CssValueInspector::withoutImportant((string) ($declarations[$property] ?? '')));
        return ('' === $inline || in_array(strtolower($inline), array( 'auto', 'inherit', 'initial', 'unset', 'revert', 'revert-layer' ), true))
            && '' === $this->imageStylesheetDimension($image, $property)
            && '' !== trim(SourceDom::attr($image, $property));
    }

    private function imageHasNonPositiveDimension(DOMElement $image, string $property): bool
    {
        $inline = trim(CssValueInspector::withoutImportant((string) (SourceDeclarations::inline($image)[ $property ] ?? '')));
        $value = $inline;
        if ('' === $value || in_array(strtolower($value), array( 'auto', 'inherit', 'initial', 'unset', 'revert', 'revert-layer' ), true)) {
            $value = trim(CssValueInspector::withoutImportant((string) ($this->styles->presentationDeclarations($image)[ $property ] ?? '')));
        }
        if ('' === $value || in_array(strtolower($value), array( 'auto', 'inherit', 'initial', 'unset', 'revert', 'revert-layer' ), true)) {
            $raw = SourceDom::attr($image, $property);
            $attribute = HtmlDimensionAttribute::parse($raw);
            // A signed value is not a valid dimension; it still fails closed.
            return null === $attribute
                ? 1 === preg_match('/^[\t\n\f\r ]*[+-]/', $raw)
                : 0.0 >= $attribute['value'];
        }
        return 1 === preg_match('/^[+-]?(?:\d+|\d*\.\d+)(?:%|px|r?em|ex|ch|lh|rlh|vw|vh|vmin|vmax|vi|vb|cm|mm|q|in|pt|pc)?$/i', $value)
            && 0.0 >= (float) $value;
    }

    public function imageDisplayDimension(DOMElement $image, string $property): string
    {
        $declarations = SourceDeclarations::inline($image);
        $inline = trim(CssValueInspector::withoutImportant((string) ($declarations[ $property ] ?? '')));
        $other = 'width' === $property ? 'height' : 'width';
        $otherInline = trim(CssValueInspector::withoutImportant((string) ($declarations[ $other ] ?? '')));
        if ( $this->imageViewportPairFillsParent($inline, $otherInline) ) {
            return '100%';
        }
        if ( '' !== $inline && ! in_array(strtolower($inline), array( 'auto', 'inherit', 'initial', 'unset', 'revert', 'revert-layer' ), true) ) {
            return $this->imageDimensionValue($inline);
        }
        if ( $this->styles->imageAxisVariesByViewport($image, $property)
            || $this->styles->imageAxisVariesByViewport($image, $other) ) {
            // Core saves a missing opposite axis as auto when one dimension
            // is present. Leave both axes to projection when either varies,
            // so that auto cannot override responsive stylesheet sizing.
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
     * The smallest used size in CSS pixels the image can have on each axis at
     * any viewport, or null for an axis with no guaranteed lower bound.
     *
     * A fixed axis ({@see fixedPixelSize()}) is its own bound. A fluid one is
     * bounded below by what the author CSS guarantees at every viewport: the
     * minimum of a `clamp()`, the largest absolute argument of a `max()`, an
     * absolute `min()`, or an absolute `min-width`/`min-height`. An absolute
     * `max-width`/`max-height` caps the dimension, but not the minimum.
     *
     * @return array{width: ?float, height: ?float}
     */
    public function minimumPixelSize(DOMElement $image): array
    {
        return array(
            'width' => $this->minimumPixelDimension($image, 'width'),
            'height' => $this->minimumPixelDimension($image, 'height'),
        );
    }

    /** {@see minimumPixelSize()} for one axis. */
    public function minimumPixelDimension(DOMElement $image, string $property): ?float
    {
        $dimension = $this->fixedPixelDimension($image, $property)
            ?? $this->viewportInvariantLowerBound($image, $property);
        $maximum = $this->viewportInvariantStylesheetValue($image, 'max-' . $property);
        $maximum = '' === $maximum ? null : $this->absolutePixelLength($maximum, $image);
        if ( null !== $dimension && null !== $maximum ) {
            $dimension = min($dimension, $maximum);
        }
        $minimum = $this->viewportInvariantLowerBound($image, 'min-' . $property);
        if ( null === $dimension || null === $minimum ) {
            return $dimension ?? $minimum;
        }

        return max($dimension, $minimum);
    }

    private function viewportInvariantLowerBound(DOMElement $image, string $property): ?float
    {
        $value = $this->viewportInvariantStylesheetValue($image, $property);

        return '' === $value ? null : $this->lowerBoundPixelLength($value, $image);
    }

    private function viewportInvariantStylesheetValue(DOMElement $image, string $property): string
    {
        return $this->styles->imageAxisVariesByViewport($image, $property) ? '' : $this->imageStylesheetDimension($image, $property);
    }

    /**
     * The smallest pixel value a length or `clamp()`/`max()`/`min()` of
     * lengths can resolve to, or null when it has no absolute lower bound.
     */
    private function lowerBoundPixelLength(string $value, DOMElement $context): ?float
    {
        $value = trim($value);
        if ( 1 !== preg_match('/^(clamp|max|min)\(\s*(.*)\s*\)$/is', $value, $matches) ) {
            return $this->absolutePixelLength($value, $context);
        }
        if ( ! CssValueSplitter::hasBalancedParens($matches[2]) ) {
            return null;
        }
        $arguments = CssValueSplitter::splitTopLevel($matches[2], array( ',' ));
        $function = strtolower($matches[1]);
        if ( 'clamp' === $function ) {
            // clamp(MIN, VAL, MAX) resolves to max(MIN, min(VAL, MAX)).
            return 3 === count($arguments) ? $this->lowerBoundPixelLength($arguments[0], $context) : null;
        }
        $bounds = array();
        foreach ( $arguments as $argument ) {
            $bound = $this->lowerBoundPixelLength($argument, $context);
            if ( null === $bound && 'min' === $function ) {
                return null;
            }
            if ( null !== $bound ) {
                $bounds[] = $bound;
            }
        }
        if ( array() === $bounds ) {
            return null;
        }

        return 'max' === $function ? max($bounds) : min($bounds);
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
        $display = CssValueInspector::declared($declarations, 'display');
        if ( '' === $display ) {
            $display = $this->styles->defaultTagDisplay($wrapper);
        }
        $position = CssValueInspector::declared($declarations, 'position');
        $float = CssValueInspector::declared($declarations, 'float');
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
        foreach ( SourceDom::tokens($value) as $token ) {
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
        $declarations = SourceDeclarations::inline($image);
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

<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

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
        $attribute = trim(SourceDom::attr($image, $property));
        return $this->imageDimensionValue($attribute);
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

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

    public function imageDisplayDimension(DOMElement $image, string $property, bool $linked): string
    {
        $declarations = $this->styles->cssDeclarations(SourceDom::attr($image, 'style'));
        $inline = trim(CssValueInspector::withoutImportant((string) ($declarations[ $property ] ?? '')));
        $other = 'width' === $property ? 'height' : 'width';
        $otherInline = trim(CssValueInspector::withoutImportant((string) ($declarations[ $other ] ?? '')));
        if ( $this->imageViewportPairFillsParent($inline, $otherInline) ) {
            return '100%';
        }
        if ( '' !== $inline && ! in_array(strtolower($inline), array( 'auto', 'inherit', 'initial', 'unset', 'revert', 'revert-layer' ), true) ) {
            return $this->imageDimensionValue($inline, $linked);
        }
        $stylesheet = $this->imageStylesheetDimension($image, $property);
        if ( '' !== $stylesheet ) {
            return $this->imageDimensionValue($stylesheet, $linked);
        }
        $attribute = trim(SourceDom::attr($image, $property));
        return $this->imageDimensionValue($attribute, $linked);
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

    /** Keep core/image dimensions to CSS lengths WordPress can serialize safely. */
    private function imageDimensionValue(string $value, bool $linked): string
    {
        $value = trim($value);
        if (1 !== preg_match('/^(?:\d+|\d*\.\d+)(?:%|px|r?em|ex|ch|lh|rlh|vw|vh|vmin|vmax|vi|vb|cm|mm|q|in|pt|pc)?$/i', $value)) {
            return '';
        }
        return ! $linked && preg_match('/^(?:\d+|\d*\.\d+)$/', $value) ? $value . 'px' : $value;
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

<?php
declare(strict_types=1);
namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

/** Native image leaves and the placement facts whose reference box stays there. */
final class NativeImageLeafPresentation
{
    public const LEAF_PATHS = array( ' > img', ' > a > img' );

    public static function selectors(string $wrapper, string $suffix = ''): string
    {
        return implode(',', array_map(static fn (string $path): string => $wrapper . $path . $suffix, self::LEAF_PATHS));
    }

    /** Properties ignored by a synthetic display:contents figure. */
    public static function isBoxProperty(string $property): bool
    {
        return in_array($property, array(
            'width', 'height', 'min-width', 'max-width', 'min-height', 'max-height',
            'aspect-ratio', 'box-sizing', 'flex', 'flex-grow', 'flex-shrink', 'flex-basis',
            'align-self', 'justify-self', 'place-self', 'order',
            'margin', 'padding', 'border',
        ), true)
            || str_starts_with($property, 'margin-')
            || str_starts_with($property, 'padding-')
            || str_starts_with($property, 'border-');
    }

    /** Properties that position a box out of, or offset from, its flow. */
    public static function isPositioningProperty(string $property): bool
    {
        return in_array($property, array( 'position', 'inset', 'inset-inline', 'inset-block', 'inset-inline-start', 'inset-inline-end', 'inset-block-start', 'inset-block-end', 'top', 'right', 'bottom', 'left', 'z-index' ), true);
    }

    public static function isPlacementProperty(string $property): bool
    {
        // Margins position the sized image, not its full-width synthetic figure.
        return in_array($property, array('margin', 'translate', 'rotate', 'scale', 'transform', '-webkit-transform', 'transform-origin', 'offset'), true)
            || str_starts_with($property, 'margin-')
            || str_starts_with($property, 'offset-');
    }
}

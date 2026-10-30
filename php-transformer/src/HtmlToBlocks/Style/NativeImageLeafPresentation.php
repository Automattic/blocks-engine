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

    public static function isPlacementProperty(string $property): bool
    {
        return in_array($property, array('translate', 'rotate', 'scale', 'transform', '-webkit-transform', 'transform-origin', 'offset'), true)
            || str_starts_with($property, 'offset-');
    }
}

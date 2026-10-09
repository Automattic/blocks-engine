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

    public static function isPlacementProperty(string $property): bool
    {
        return in_array($property, array('translate', 'rotate', 'scale', 'transform', '-webkit-transform', 'transform-origin', 'offset'), true)
            || str_starts_with($property, 'offset-');
    }
}

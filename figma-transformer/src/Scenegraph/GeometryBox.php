<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Scenegraph;

/**
 * Coordinate-space names for normalized scenegraph boxes.
 */
final class GeometryBox
{
    public const CLASSIFICATION_CANVAS_ABSOLUTE = 'canvas-absolute';
    public const CLASSIFICATION_PARENT_LOCAL = 'parent-local';
    public const CLASSIFICATION_PAGE_LOCAL = 'page-local';

    public const COORDINATE_SPACE_CANVAS_ABSOLUTE = 'absolute';
    public const COORDINATE_SPACE_PARENT_LOCAL = 'local';
    public const COORDINATE_SPACE_PAGE_LOCAL = self::COORDINATE_SPACE_PARENT_LOCAL;

    /**
     * @param array<string, mixed> $box
     */
    public static function coordinateSpace(array $box): string
    {
        return isset($box['coordinate_space']) && is_scalar($box['coordinate_space'])
            ? (string) $box['coordinate_space']
            : self::COORDINATE_SPACE_CANVAS_ABSOLUTE;
    }

    public static function coordinateSpaceForClassification(string $classification): string
    {
        return self::CLASSIFICATION_CANVAS_ABSOLUTE === $classification
            ? self::COORDINATE_SPACE_CANVAS_ABSOLUTE
            : self::COORDINATE_SPACE_PARENT_LOCAL;
    }

    /**
     * @param array<string, mixed> $box
     */
    public static function classifyNormalizedBox(array $box, bool $isPageLocal = false): string
    {
        if ( self::COORDINATE_SPACE_CANVAS_ABSOLUTE === self::coordinateSpace($box) ) {
            return self::CLASSIFICATION_CANVAS_ABSOLUTE;
        }

        return $isPageLocal ? self::CLASSIFICATION_PAGE_LOCAL : self::CLASSIFICATION_PARENT_LOCAL;
    }
}

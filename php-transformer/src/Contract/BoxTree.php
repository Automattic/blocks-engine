<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Contract;

use DOMElement;

/** Shared producer contract. Evidence is observational, never a conversion directive. */
final class BoxTree
{
    public const SCHEMA = 'blocks-engine/box-tree/v1';
    public const NODE_ATTRIBUTE = 'data-blocks-engine-node-id';
    public const MAX_NODES = 100000;
    public const MAX_VIEWPORTS = 8;
    public const MAX_BYTES = 33554432;
    public const MOTION_PROPERTIES = array('animation-name', 'transition-property', 'transition-duration');
    public const PROPERTIES = array(
        'display', 'position', 'box-sizing', 'overflow-x', 'overflow-y',
        'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'row-gap', 'column-gap',
        'flex-direction', 'flex-wrap', 'justify-content', 'align-items', 'align-content',
        'flex-grow', 'flex-shrink', 'flex-basis', 'align-self', 'order',
        'grid-template-columns', 'grid-template-rows', 'grid-auto-columns', 'grid-auto-rows', 'grid-auto-flow',
        'grid-column-start', 'grid-column-end', 'grid-row-start', 'grid-row-end', 'justify-items', 'justify-self',
        'background', 'border-top', 'border-right', 'border-bottom', 'border-left', 'border-radius',
        'outline', 'box-shadow', 'mask', 'filter', 'opacity', 'mix-blend-mode', 'transform', 'z-index',
        'width', 'height', 'min-width', 'max-width', 'min-height', 'max-height',
    );

    public static function validId(mixed $id): bool
    {
        return is_string($id) && 1 === preg_match('/^[A-Za-z0-9._:-]{1,80}$/D', $id);
    }

    /** Return all viewport observations for this exact source element. */
    public static function forElement(array $tree, DOMElement $element): array
    {
        return $tree['nodes'][$element->getAttribute(self::NODE_ATTRIBUTE)]['viewports'] ?? array();
    }
}

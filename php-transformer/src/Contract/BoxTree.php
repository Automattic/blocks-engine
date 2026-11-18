<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Contract;

use DOMElement;

/** Shared producer contract. Evidence is observational, never a conversion directive. */
final class BoxTree
{
    public const SCHEMA = 'blocks-engine/box-tree/v2';
    public const NODE_ATTRIBUTE = 'data-blocks-engine-node-id';
    public const MAX_NODES = 100000;
    public const MAX_VIEWPORTS = 8;
    public const MAX_BYTES = 33554432;
    public const MOTION_PROPERTIES = array('animation-name', 'transition-property', 'transition-duration');
    public const INHERITED_PROPERTIES = array(
        'color', 'font-family', 'font-size', 'font-weight', 'font-style', 'line-height',
        'letter-spacing', 'word-spacing', 'text-align', 'text-transform', 'text-indent',
        'text-shadow', 'white-space', 'word-break', 'overflow-wrap',
        'list-style-type', 'list-style-position', 'visibility', 'cursor',
    );
    public const PROPERTIES = array(
        'display', 'position', 'box-sizing', 'overflow-x', 'overflow-y',
        'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'row-gap', 'column-gap',
        'flex-direction', 'flex-wrap', 'justify-content', 'align-items', 'align-content',
        'flex-grow', 'flex-shrink', 'flex-basis', 'align-self', 'order',
        'grid-template-columns', 'grid-template-rows', 'grid-auto-columns', 'grid-auto-rows', 'grid-auto-flow',
        'grid-column-start', 'grid-column-end', 'grid-row-start', 'grid-row-end', 'justify-items', 'justify-self',
        'background-color', 'background-image', 'border-top', 'border-right', 'border-bottom', 'border-left', 'border-radius',
        'outline', 'box-shadow', 'mask-image', 'mask-mode', 'filter', 'opacity', 'mix-blend-mode', 'transform', 'z-index',
        'width', 'height', 'min-width', 'max-width', 'min-height', 'max-height',
        'color', 'font-family', 'font-size', 'font-weight', 'font-style', 'line-height',
        'letter-spacing', 'word-spacing', 'text-align', 'text-transform', 'text-indent',
        'text-decoration-line', 'text-decoration-color', 'text-decoration-style', 'text-shadow',
        'white-space', 'word-break', 'overflow-wrap', 'text-overflow', 'vertical-align',
        'list-style-type', 'list-style-position',
        'top', 'right', 'bottom', 'left', 'float', 'clear', 'visibility', 'aspect-ratio',
        'object-fit', 'object-position', 'transform-origin', 'clip-path', 'isolation',
        'backdrop-filter', 'pointer-events', 'cursor',
        'background-position-x', 'background-position-y', 'background-size', 'background-repeat',
        'background-clip', 'background-origin', 'background-attachment', 'background-blend-mode',
    );

    /** Capture identity is only meaningful to box-tree lookup, never authored markup. */
    public static function isEvidenceAttribute(string $name): bool
    {
        return self::NODE_ATTRIBUTE === strtolower($name);
    }

    /** @return list<\DOMAttr> Authored attributes, without capture-only identity. */
    public static function authoredAttributes(DOMElement $element): array
    {
        return array_values(array_filter(iterator_to_array($element->attributes), static fn (\DOMAttr $attribute): bool => ! self::isEvidenceAttribute($attribute->name)));
    }

    /** Sidecars are compiler evidence, regardless of their declared role. */
    public static function isSidecarPath(string $path): bool
    {
        return 1 === preg_match('~(?:^|/)box-tree/.+\.json$~D', $path);
    }

    public static function validId(mixed $id): bool
    {
        return is_string($id) && 1 === preg_match('/^[A-Za-z0-9._:-]{1,80}$/D', $id);
    }

    /** Return all viewport observations for this exact source element. */
    public static function forElement(array $tree, DOMElement $element): array
    {
        return $tree['nodes'][$element->getAttribute(self::NODE_ATTRIBUTE)]['viewports'] ?? array();
    }

    /**
     * Viewport observations of the captured parent of this source element,
     * read from the tree's own child lists. The transform DOM can re-home an
     * element (a page root under a fresh `<body>`) whose new parent carries no
     * capture identity, but the capture still knows the box it laid out in.
     *
     * @param array<string, string> $parents Child id => parent id, built by {@see self::parentIds()}.
     */
    public static function parentForElement(array $tree, array $parents, DOMElement $element): array
    {
        $parent = $parents[$element->getAttribute(self::NODE_ATTRIBUTE)] ?? null;
        return null === $parent ? array() : ($tree['nodes'][$parent]['viewports'] ?? array());
    }

    /** @return array<string, string> Child id => parent id, from the tree's child lists. */
    public static function parentIds(array $tree): array
    {
        $parents = array();
        foreach ($tree['nodes'] ?? array() as $id => $node) {
            foreach (is_array($node['children'] ?? null) ? $node['children'] : array() as $child) {
                if (is_string($child)) $parents[$child] = (string) $id;
            }
        }
        return $parents;
    }
}

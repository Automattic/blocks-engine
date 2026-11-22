<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Contract;

use DOMElement;

/** Shared producer contract. Evidence is observational, never a conversion directive. */
final class BoxTree
{
    public const SCHEMA = 'blocks-engine/box-tree/v2';
    /**
     * v2 with a top-level `styles` list of used-style objects; every node and
     * pseudo observation's `used_styles` is an integer index into it. Lossless,
     * and much smaller for long pages that repeat few distinct styles.
     */
    public const SCHEMA_COMPACT = 'blocks-engine/box-tree/v3';
    public const NODE_ATTRIBUTE = 'data-blocks-engine-node-id';
    public const MAX_NODES = 100000;
    public const MAX_VIEWPORTS = 8;
    /**
     * Box-tree intake is withheld unless this environment variable is "1".
     * Applying captured observations made every frozen corpus import worse
     * than the same capture without them (#7352); each decision is re-enabled
     * only once it is proven no worse than authored styling (#7345).
     */
    public const INTAKE_ENV = 'BLOCKS_ENGINE_BOX_TREE_INTAKE';
    public const MAX_BYTES = 33554432;
    public const MOTION_PROPERTIES = array('animation-name', 'transition-property', 'transition-duration');
    /**
     * SVG paint, inherited down to shapes and text. Optional: evidence
     * captured before producers recorded paint omits these keys, and a
     * consumer then has no paint to restate.
     */
    public const PAINT_PROPERTIES = array(
        'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-opacity',
        'stroke-linecap', 'stroke-linejoin', 'stroke-dasharray', 'stroke-dashoffset',
    );
    public const INHERITED_PROPERTIES = array(
        'color', 'font-family', 'font-size', 'font-weight', 'font-style', 'line-height',
        'letter-spacing', 'word-spacing', 'text-align', 'text-transform', 'text-indent',
        'text-shadow', 'white-space', 'word-break', 'overflow-wrap',
        'list-style-type', 'list-style-position', 'visibility', 'cursor',
        'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-opacity',
        'stroke-linecap', 'stroke-linejoin', 'stroke-dasharray', 'stroke-dashoffset',
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

    public static function intakeEnabled(): bool
    {
        return '1' === getenv(self::INTAKE_ENV);
    }

    /** Sidecars are compiler evidence, regardless of their declared role. */
    public static function isSidecarPath(string $path): bool
    {
        return 1 === preg_match('~(?:^|/)box-tree/.+\.json$~D', $path);
    }

    /**
     * Expand a v3 tree to the exact v2 shape, so v2 validation and every
     * consumer see one format. Other schemas are returned unchanged. Returns
     * null when the style table or an index into it is malformed; observation
     * shape and style content are left to v2 validation.
     */
    public static function expandCompact(mixed $tree): mixed
    {
        if (!is_array($tree) || self::SCHEMA_COMPACT !== ($tree['schema'] ?? null)) return $tree;
        $styles = $tree['styles'] ?? null;
        if (!is_array($styles) || !array_is_list($styles) || count($styles) > self::MAX_NODES * self::MAX_VIEWPORTS) return null;
        foreach ($styles as $style) {
            if (!is_array($style)) return null;
        }
        $resolve = static function (mixed &$observation) use ($styles): bool {
            if (!is_array($observation) || !array_key_exists('used_styles', $observation)) return true;
            $index = $observation['used_styles'];
            if (!is_int($index) || !isset($styles[$index])) return false;
            $observation['used_styles'] = $styles[$index];
            return true;
        };
        if (is_array($tree['nodes'] ?? null)) {
            foreach ($tree['nodes'] as &$node) {
                if (!is_array($node) || !is_array($node['viewports'] ?? null)) continue;
                foreach ($node['viewports'] as &$observation) {
                    if (!$resolve($observation)) return null;
                    if (!is_array($observation) || !is_array($observation['pseudo'] ?? null)) continue;
                    foreach ($observation['pseudo'] as &$pseudo) {
                        if (!$resolve($pseudo)) return null;
                    }
                    unset($pseudo);
                }
                unset($observation);
            }
            unset($node);
        }
        unset($tree['styles']);
        $tree['schema'] = self::SCHEMA;
        return $tree;
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
}

<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Contract;

use DOMElement;

/** Shared producer contract. Evidence is observational, never a conversion directive. */
final class BoxTree
{
    /** Static-site-importer still reads this schema in its sidecar fixtures. */
    public const SCHEMA = 'blocks-engine/box-tree/v2';

    /**
     * Compact sidecars: each viewport's
     * `used_styles`, and each `pseudo.*.used_styles`, is an integer index into
     * the top-level `styles` array.
     */
    public const SCHEMA_V3 = 'blocks-engine/box-tree/v3';

    /**
     * The normalized-file field that carries the SHA-256 of a document's
     * original artifact bytes, taken at intake before any normalizer or
     * projector rewrites the document. A sidecar's `source_hash` is verified
     * against this digest only; it is set only when the artifact carries
     * sidecars.
     */
    public const INTAKE_HASH_FIELD = 'box_tree_source_hash';

    /** Every schema {@see self::read()} accepts. */
    public const SCHEMAS = array(self::SCHEMA, self::SCHEMA_V3);

    /** Static-site-importer still reads this property list in its sidecar fixtures. */
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

    /** Static-site-importer still reads this sidecar size limit. */
    public const MAX_BYTES = 33554432;

    /** Static-site-importer still reads this capture-only identity contract. */
    public const NODE_ATTRIBUTE = 'data-blocks-engine-node-id';

    /** Static-site-importer still reads this contract to exclude capture-only identity. */
    public static function isEvidenceAttribute(string $name): bool
    {
        return self::NODE_ATTRIBUTE === strtolower($name);
    }

    /**
     * Static-site-importer still reads this contract to exclude capture-only identity.
     * @return list<\DOMAttr> Authored attributes, without capture-only identity.
     */
    public static function authoredAttributes(DOMElement $element): array
    {
        return array_values(array_filter(iterator_to_array($element->attributes), static fn (\DOMAttr $attribute): bool => ! self::isEvidenceAttribute($attribute->name)));
    }

    /** Static-site-importer still reads this to recognize sidecars, regardless of role. */
    public static function isSidecarPath(string $path): bool
    {
        return 1 === preg_match('~(?:^|/)box-tree/.+\.json$~D', $path);
    }

    public static function isSupportedSchema(mixed $schema): bool
    {
        return in_array($schema, self::SCHEMAS, true);
    }

    /**
     * The one contract reader for decoded sidecars. A v2 tree is returned as
     * is; a v3 tree is expanded into the v2 shape: every `used_styles` index
     * is replaced by the style object it names, the `styles` table is
     * dropped, and `schema` becomes {@see self::SCHEMA}. Any other schema, or
     * a v3 tree with a style index that names no style object, reads as null.
     *
     * @return array<string, mixed>|null
     */
    public static function read(mixed $tree): ?array
    {
        if (! is_array($tree) || ! self::isSupportedSchema($tree['schema'] ?? null)) return null;
        if (self::SCHEMA === $tree['schema']) return $tree;
        $styles = $tree['styles'] ?? null;
        if (! is_array($styles) || ! array_is_list($styles) || ! is_array($tree['nodes'] ?? null)) return null;
        foreach ($styles as $style) {
            if (! is_array($style)) return null;
        }
        $expand = static function (mixed $observation) use ($styles): mixed {
            if (! is_array($observation) || ! array_key_exists('used_styles', $observation)) return $observation;
            $index = $observation['used_styles'];
            if (! is_int($index) || ! isset($styles[$index])) return null;
            $observation['used_styles'] = $styles[$index];
            return $observation;
        };
        foreach ($tree['nodes'] as $id => $node) {
            if (! is_array($node) || ! is_array($node['viewports'] ?? null)) continue;
            foreach ($node['viewports'] as $viewport => $observation) {
                $observation = $expand($observation);
                if (null === $observation) return null;
                if (is_array($observation) && is_array($observation['pseudo'] ?? null)) {
                    foreach ($observation['pseudo'] as $pseudo => $pseudoObservation) {
                        $pseudoObservation = $expand($pseudoObservation);
                        if (null === $pseudoObservation) return null;
                        $observation['pseudo'][$pseudo] = $pseudoObservation;
                    }
                }
                $node['viewports'][$viewport] = $observation;
            }
            $tree['nodes'][$id] = $node;
        }
        unset($tree['styles']);
        $tree['schema'] = self::SCHEMA;
        return $tree;
    }

    public static function validId(mixed $id): bool
    {
        return is_string($id) && 1 === preg_match('/^[A-Za-z0-9._:-]{1,80}$/D', $id);
    }

}

<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Contract;

use DOMElement;

/** Shared producer contract. Evidence is observational, never a conversion directive. */
final class BoxTree
{
    public const NODE_ATTRIBUTE = 'data-blocks-engine-node-id';

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

}

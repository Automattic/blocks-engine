<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

/** Typed reads of optional fields from decoded, loosely shaped arrays. */
final class ArrayField
{
    /**
     * The field when it is an array, otherwise an empty array. The container
     * is read exactly like `$values[$key] ?? null`, so an absent container or
     * key yields the empty array.
     *
     * @return array<mixed>
     */
    public static function array(mixed $values, string $key): array
    {
        $value = $values[$key] ?? null;

        return is_array($value) ? $value : array();
    }
}

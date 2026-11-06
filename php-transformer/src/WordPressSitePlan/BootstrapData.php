<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/** Emits PHP literals without var_export's repeated keys and indentation. */
final class BootstrapData
{
    public static function literal(mixed $value): string
    {
        if (!is_array($value)) return var_export($value, true);
        $items = array();
        $list = array_is_list($value);
        foreach ($value as $key => $item) $items[] = ($list ? '' : self::literal($key) . '=>') . self::literal($item);
        return '[' . implode(',', $items) . ']';
    }

    /** Intern rows as immutable literals; a fixed loop restores keys and order. */
    public static function table(string $name, array $value): string
    {
        $rows = $indices = $references = array();
        foreach ($value as $field => $row) {
            $key = serialize($row);
            if (!isset($indices[$key])) {
                $indices[$key] = count($rows);
                $rows[] = $row;
            }
            $references[$field] = $indices[$key];
        }
        $variable = '$' . $name;
        return $variable . '_rows = ' . self::literal($rows) . ";\n"
            . $variable . '_references = ' . self::literal($references) . ";\n"
            . $variable . ' = [];' . "\n"
            . 'foreach ( ' . $variable . '_references as $key => $index ) ' . $variable . '[$key] = ' . $variable . '_rows[$index];' . "\n"
            . 'unset( ' . $variable . '_rows, ' . $variable . '_references );';
    }
}

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

    /** Intern nested rows bottom-up; references preserve keys, order and types. */
    public static function table(string $name, array $value): string
    {
        $rows = $indices = array();
        $intern = static function (array $row) use (&$intern, &$rows, &$indices): int {
            $key = serialize($row);
            if (isset($indices[$key])) return $indices[$key];
            $items = array();
            $list = array_is_list($row);
            foreach ($row as $field => $item) $items[] = ($list ? '' : self::literal($field) . ' => ') . (is_array($item) ? '$r[' . $intern($item) . ']' : self::literal($item));
            $index = count($rows);
            $rows[] = '$r[' . $index . ']=[' . implode(',', $items) . '];';
            $indices[$key] = $index;
            return $index;
        };
        $index = $intern($value);
        return '$' . $name . ' = (static function (): array { $r=[];' . "\n" . implode("\n", $rows) . "\n" . 'return $r[' . $index . ']; })();';
    }
}

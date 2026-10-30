<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

/** WordPress core's serialize_block_attributes() escaping. */
final class BlockAttributes
{
    public static function encode(array|object $attributes, int $flags = 0): string
    {
        $json = json_encode($attributes, $flags | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return self::escapeJson(false === $json ? '' : $json);
    }

    /** Also supports JSON produced by WordPress's wp_json_encode(). */
    public static function escapeJson(string $json): string
    {
        return strtr($json, array(
            '\\\\' => '\\u005c',
            '--' => '\\u002d\\u002d',
            '<' => '\\u003c',
            '>' => '\\u003e',
            '&' => '\\u0026',
            '\\"' => '\\u0022',
        ));
    }
}

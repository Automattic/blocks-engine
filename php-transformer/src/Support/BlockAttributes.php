<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

/** WordPress core's serialize_block_attributes() escaping, and reading those attributes back from a block comment. */
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

    /**
     * The attributes of a block comment's body (`wp:name {...}`), or null when
     * it carries none or they are not a JSON object.
     *
     * @return array<string,mixed>|null
     */
    public static function decodeComment(string $comment): ?array
    {
        if (!preg_match('~^\s*wp:[^\s{]+\s+(\{.*\})\s*/?\s*$~s', $comment, $payload)) return null;
        $attributes = json_decode($payload[1], true);
        return is_array($attributes) ? $attributes : null;
    }
}

<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Support\BlockAttributes;

/** Class lists in block JSON, including nested layout-shell wrapper attributes. */
final class BlockCommentClasses
{
    private const COMMENT = '/<!--\s+wp:[a-z0-9\/-]+\s+\{.*?\}\s+\/?-->/s';

    private const CLASS_VALUE = '/"(className|class)"\s*:\s*("(?:[^"\\\\]|\\\\.)*")/s';

    /** @return list<string> */
    public static function values(string $markup): array
    {
        $values = array();
        self::rewrite($markup, static function (string $value) use (&$values): string {
            $values[] = $value;
            return $value;
        });
        return $values;
    }

    /** @param callable(string):string $rewrite */
    public static function rewrite(string $markup, callable $rewrite): string
    {
        return preg_replace_callback(self::COMMENT, static function (array $comment) use ($rewrite): string {
            return preg_replace_callback(self::CLASS_VALUE, static function (array $match) use ($rewrite): string {
                $value = json_decode($match[2]);
                if (!is_string($value)) return $match[0];
                $changed = $rewrite($value);
                if ($changed === $value) return $match[0];
                if ('className' === $match[1] && '' === $changed && '' !== trim($value)) return '"className":null';
                $json = BlockAttributes::escapeJson((string) json_encode($changed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                return substr($match[0], 0, -strlen($match[2])) . $json;
            }, $comment[0]) ?? $comment[0];
        }, $markup) ?? $markup;
    }
}

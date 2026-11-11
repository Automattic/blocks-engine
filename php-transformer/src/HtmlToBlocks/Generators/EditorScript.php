<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

/** Fills a generated block's editor script template. */
final class EditorScript
{
    /**
     * Replaces `__BLOCK_NAME__`, then `__BLOCK_ATTRIBUTES__` with the
     * attribute schema as JSON, then each extra placeholder in order.
     *
     * @param array<string, mixed>  $attributes
     * @param array<string, string> $placeholders
     */
    public static function bind(string $script, string $blockName, array $attributes, array $placeholders = array()): string
    {
        return str_replace(
            array_merge(array('__BLOCK_NAME__', '__BLOCK_ATTRIBUTES__'), array_keys($placeholders)),
            array_merge(array($blockName, json_encode($attributes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), array_values($placeholders)),
            $script
        );
    }
}

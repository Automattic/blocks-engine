<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPress;

final class Runtime
{
    public function hasWordPress(): bool
    {
        return $this->canSerializeBlocks();
    }

    public function canParseBlocks(): bool
    {
        return function_exists('parse_blocks');
    }

    public function canSerializeBlocks(): bool
    {
        return function_exists('serialize_blocks');
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    public function serializeBlocks(array $blocks): string
    {
        if ( ! $this->canSerializeBlocks() ) {
            throw new \RuntimeException('WordPress serialize_blocks() is not available.');
        }

        return serialize_blocks($blocks);
    }
}

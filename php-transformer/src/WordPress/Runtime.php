<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPress;

final class Runtime
{
    public function hasWordPress(): bool
    {
        return function_exists('parse_blocks') && function_exists('serialize_blocks');
    }
}

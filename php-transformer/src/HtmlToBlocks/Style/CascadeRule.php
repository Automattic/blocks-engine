<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

/** One CSS contribution declaring the {@see CascadeLayer} it belongs to. */
final class CascadeRule
{
    public function __construct(
        public readonly CascadeLayer $layer,
        public readonly string $css
    ) {
    }
}

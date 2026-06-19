<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\FormatBridge;

final class FormatBridge
{
    /**
     * @return list<string>
     */
    public function supportedFormats(): array
    {
        return array( 'html', 'markdown', 'blocks' );
    }
}

<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use DOMElement;

final class ProbeBlockCreator implements SourceBlockCreator
{
    /**
     * @param array<string, mixed> $attrs
     * @param array<int, array<string, mixed>> $innerBlocks
     * @return array<string, mixed>
     */
    public function createBlock(string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null, ?DOMElement $logicalSourceElement = null): array
    {
        return array(
            'blockName'   => $name,
            'attrs'       => $attrs,
            'innerBlocks' => $innerBlocks,
        );
    }
}

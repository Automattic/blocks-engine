<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Tests\Support;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Closure;
use DOMElement;

final class SourceBlockCreatorFixture implements SourceBlockCreator
{
    /** @param Closure(string, array<string, mixed>, array<int, array<string, mixed>>, ?DOMElement, ?DOMElement): array<string, mixed> $createBlock */
    public function __construct(private readonly Closure $createBlock) {}

    /** @return array<string, mixed> */
    public function createBlock(string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null, ?DOMElement $logicalSourceElement = null): array
    {
        return ($this->createBlock)($name, $attrs, $innerBlocks, $sourceElement, $logicalSourceElement);
    }
}

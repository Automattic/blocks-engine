<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Tests\Support;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ButtonLinkLeftovers;
use Closure;
use DOMElement;

final class ButtonLinkLeftoversFixture implements ButtonLinkLeftovers
{
    /**
     * @param Closure(DOMElement): ?array<string, mixed>|null $imageBlockFromAnchor
     * @param Closure(DOMElement, array<int, array<string, mixed>>): ?array<string, mixed>|null $convertLinkWrapperGroup
     */
    public function __construct(
        private readonly ?Closure $imageBlockFromAnchor = null,
        private readonly ?Closure $convertLinkWrapperGroup = null
    ) {
    }

    public function imageBlockFromAnchor(DOMElement $anchor): ?array
    {
        return null === $this->imageBlockFromAnchor ? null : ($this->imageBlockFromAnchor)($anchor);
    }

    public function convertLinkWrapperGroup(DOMElement $anchor, array &$fallbacks): ?array
    {
        return null === $this->convertLinkWrapperGroup ? null : ($this->convertLinkWrapperGroup)($anchor, $fallbacks);
    }
}

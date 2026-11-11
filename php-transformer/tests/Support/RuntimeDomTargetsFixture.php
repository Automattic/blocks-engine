<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Tests\Support;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\RuntimeDomTargets;
use Closure;
use DOMElement;

final class RuntimeDomTargetsFixture implements RuntimeDomTargets
{
    /** @param Closure(DOMElement): bool $matches */
    public function __construct(private readonly Closure $matches) {}

    public function isRuntimeDomTarget(DOMElement $element): bool
    {
        return ($this->matches)($element);
    }
}

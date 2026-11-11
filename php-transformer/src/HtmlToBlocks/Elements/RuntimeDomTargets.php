<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use DOMElement;

/** Answers whether client runtime code addresses a source element. */
interface RuntimeDomTargets
{
    public function isRuntimeDomTarget(DOMElement $element): bool;
}

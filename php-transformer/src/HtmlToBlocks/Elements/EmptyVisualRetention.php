<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use DOMElement;

/** Decides whether an element with no rendered content still paints a box worth keeping. */
interface EmptyVisualRetention
{
    public function shouldPreserveEmptyVisualElement(DOMElement $element): bool;
}

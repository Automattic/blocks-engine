<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use DOMElement;

/** Lowers an author-owned layout container to native blocks around its converted children. */
interface AuthorLayoutLowering
{
    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>
     */
    public function authorLayoutBlockFromElement(DOMElement $element, array &$fallbacks): array;
}

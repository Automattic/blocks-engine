<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use DOMElement;

/** Non-button leftover conversions still owned by HtmlCompilation. */
interface ButtonLinkLeftovers
{
    /** @return array<string, mixed>|null */
    public function imageBlockFromAnchor(DOMElement $anchor): ?array;

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    public function convertLinkWrapperGroup(DOMElement $anchor, array &$fallbacks): ?array;
}

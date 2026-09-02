<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText;

use DOMElement;

interface RichTextInlinePolicy
{
    public function richTextMarker(DOMElement $element): string;

    public function retainsEmptyRichTextInline(DOMElement $element): bool;
}

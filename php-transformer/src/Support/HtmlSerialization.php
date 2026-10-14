<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

/** Removes the UTF-8 parsing marker serialized by DOMDocument::loadHTML(). */
final class HtmlSerialization
{
    public static function removeEncodingMarker(string $html): string
    {
        return preg_replace('/^(?:<\?xml encoding="UTF-8" ?\??>|<!--[?\/]xml encoding="UTF-8" ?-->)/i', '', $html) ?? $html;
    }

}

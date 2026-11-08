<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

final class Utf8Text
{
    /** Preserve source bytes up to the requested limit without splitting UTF-8 characters. */
    public static function prefix(string $value, int $maxBytes): string
    {
        $prefix = substr($value, 0, $maxBytes);
        while ('' !== $prefix && 1 !== preg_match('//u', $prefix)) {
            $prefix = substr($prefix, 0, -1);
        }
        return $prefix;
    }
}

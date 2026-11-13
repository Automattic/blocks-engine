<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Family names in a CSS `font-family` stack. */
final class CssFontFamily
{
    /**
     * Unquoted family names in source order. Commas split the stack only at
     * top level, so `var(--font, Arial)` and quoted names stay whole.
     *
     * @return list<string>
     */
    public static function names(string $stack): array
    {
        $names = array();
        foreach (CssValueSplitter::splitTopLevel($stack, array(',')) as $name) {
            $name = trim($name, " \t\n\r\0\x0B\"'");
            if ('' !== $name) $names[] = $name;
        }
        return $names;
    }
}

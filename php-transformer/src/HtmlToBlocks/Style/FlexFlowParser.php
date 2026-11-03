<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

/** Parses the direction and wrap components of the flex-flow shorthand. */
final class FlexFlowParser
{
    /** @param list<string> $parts @return array{0: string, 1: string}|null */
    public static function parse(array $parts): ?array
    {
        $direction = null;
        $wrap = null;
        foreach ($parts as $part) {
            $token = strtolower(trim($part));
            if (null === $direction && in_array($token, array('row', 'row-reverse', 'column', 'column-reverse'), true)) $direction = $token;
            elseif (null === $wrap && in_array($token, array('nowrap', 'wrap', 'wrap-reverse'), true)) $wrap = $token;
            else return null;
        }
        return count($parts) <= 2 ? array($direction ?? 'row', $wrap ?? 'nowrap') : null;
    }
}

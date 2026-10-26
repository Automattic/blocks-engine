<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

/** Expand generated :is() compounds for legacy selector-text contract assertions. */
function expandCompactedSelectors(string $css): string
{
    $expand = static function (string $arm) use (&$expand): array {
        if ( ! preg_match('/:is(?<args>\((?:[^()]++|(?&args))*\))/', $arm, $match, PREG_OFFSET_CAPTURE) ) {
            return array($arm);
        }
        $result = array();
        $prefix = substr($arm, 0, $match[0][1]);
        $suffix = substr($arm, $match[0][1] + strlen($match[0][0]));
        foreach ( CssStylesheetTransformer::splitSelectorList(substr($match['args'][0], 1, -1)) ?? array() as $alternative ) {
            array_push($result, ...$expand($prefix . $alternative . $suffix));
        }
        return $result;
    };
    return (new CssStylesheetTransformer())->transform($css, static function (string $prelude) use ($expand): string {
        $arms = array();
        foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $arm ) {
            array_push($arms, ...$expand($arm));
        }
        return implode(',', $arms);
    });
}

<?php
declare(strict_types=1);

/**
 * Authors declare page spacing inline on <body> — reserving room for a fixed
 * footer bar is the common case. An inline style never reaches the stylesheet
 * pipeline, so that spacing was dropped and every imported page came up short
 * by exactly that amount.
 *
 * The body's spacing is page content the reader sees. Its positioning, sizing
 * and overflow are document mechanics WordPress owns, and overriding those
 * breaks scrolling in the editor canvas, so only spacing crosses over.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$css = static function (string $html): string {
    $result = ( new HtmlTransformer() )->transform($html, array())->toArray();
    $css = '';
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( is_array($asset) && 'css' === ($asset['kind'] ?? null) ) {
            $css .= (string) ($asset['content'] ?? '');
        }
    }

    return $css;
};

$bodyRule = static fn (string $css): string => 1 === preg_match('/(?:^|[^-\w])body\s*\{([^}]*)\}/', $css, $m) ? $m[1] : '';

// The captured Weebly body: footer reserve alongside document mechanics.
$weebly = $css('<html><body style="min-height:100%;position:relative;height:auto !important;padding-bottom:62px !important"><main><p>Copy</p></main></body></html>');
$assert(
    str_contains($weebly, 'padding-bottom:62px'),
    'the inline footer reserve is projected onto the document body',
    $weebly
);
$assert(
    str_contains($bodyRule($weebly), '!important'),
    'the authored precedence of that spacing is preserved',
    $bodyRule($weebly)
);
$assert(
    ! str_contains($weebly, 'position:relative'),
    'document positioning is left to WordPress',
    $bodyRule($weebly)
);
$assert(
    1 !== preg_match('/(?:^|[^-\w])body\s*\{[^}]*height\s*:/', $weebly),
    'document sizing is left to WordPress, so the editor canvas still scrolls',
    $bodyRule($weebly)
);

// Every spacing side crosses over, shorthand included.
// Margin is re-targeted by the projector rather than left on the body, so the
// assertion is that the authored value survives, not where it lands.
$sides = $css('<html><body style="margin:4px;padding:10px 12px 14px 16px"><main><p>Copy</p></main></body></html>');
$assert(
    str_contains($sides, 'margin:4px') && str_contains($bodyRule($sides), 'padding:10px 12px 14px 16px'),
    'shorthand margin and padding both cross over',
    $bodyRule($sides)
);

// A body with no inline style produces no body rule at all.
$plain = $css('<html><body><main><p>Copy</p></main></body></html>');
$assert(
    1 !== preg_match('/(?:^|[^-\w])body\s*\{/', $plain),
    'a body without inline spacing introduces no rule',
    $plain
);

// A body whose inline style is only mechanics produces no body rule.
$mechanicsOnly = $css('<html><body style="position:relative;overflow:hidden;height:100%"><main><p>Copy</p></main></body></html>');
$assert(
    1 !== preg_match('/(?:^|[^-\w])body\s*\{/', $mechanicsOnly),
    'an inline style carrying only document mechanics introduces no rule',
    $mechanicsOnly
);

// Hostile input cannot break out of the declaration block.
$hostile = $css('<html><body style="padding-bottom:1px}body{color:red"><main><p>Copy</p></main></body></html>');
$assert(
    ! str_contains($hostile, 'color:red'),
    'an inline style cannot inject an extra rule',
    $hostile
);

if ( 0 < $failures ) {
    fwrite(STDERR, "inline body spacing projection FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "inline body spacing projection passed: {$passes} assertions\n";

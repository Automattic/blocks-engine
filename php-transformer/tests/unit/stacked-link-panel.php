<?php
declare(strict_types=1);

/**
 * A stacked link panel (a mobile menu: block links spaced by a parent
 * `> * + *` rule, ending in a full-width block button) keeps its spacing and
 * the button's full width.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$result = ( new HtmlTransformer() )->transform(
    '<style>.panel>*+*{margin-top:16px}.link{display:block}.cta{display:block;padding:10px 20px;background:#e8501c;color:#fff;text-align:center}</style>'
    . '<div class="panel"><a class="link" href="/one">One</a><a class="link" href="/two">Two</a><a class="cta" href="https://example.com/">Subscribe</a></div>',
    array()
)->toArray();
$css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
$markup = (string) ($result['serialized_blocks'] ?? '');

$assert(str_contains($markup, 'blocks-engine-synthetic-paragraph'), 'panel links lower to synthetic paragraph carriers', $markup);
$assert(str_contains($css, ':where(p.blocks-engine-synthetic-paragraph)>a{margin:inherit}'), 'the anchor takes the sibling spacing its display:contents carrier matched', $css);
$assert(1 === preg_match('/\.wp-block-buttons\{[^}]*width:100%/', $css) && 1 === preg_match('/>\.wp-block-button__link\{[^}]*width:100%/', $css), 'a block-level flow button fills the line instead of shrinking to its label', $css);

$inRow = ( new HtmlTransformer() )->transform(
    '<style>.row{display:flex}.cta{display:block;padding:10px 20px;background:#e8501c;color:#fff}</style><div class="row"><a class="cta" href="/go">Go</a></div>',
    array()
)->toArray();
$inRowCss = implode("\n", array_column($inRow['assets'] ?? array(), 'content'));
$assert(0 === preg_match('/>\.wp-block-button__link\{[^}]*width:100%/', $inRowCss), 'a block-level button that is a flex item keeps its intrinsic width', $inRowCss);

if ( $failures > 0 ) {
    fwrite(STDERR, "stacked link panel: {$failures} failed, {$passes} passed\n");
    exit(1);
}
echo "stacked link panel: {$passes} passed\n";

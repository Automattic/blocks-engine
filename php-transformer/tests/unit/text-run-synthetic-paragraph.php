<?php
declare(strict_types=1);

/**
 * A text-only layout item (`<div class="author">Anonyme</div>` in a flex
 * column) lowers to one core/paragraph that is the source element's own box:
 * it carries the source class instead of nesting an added paragraph inside a
 * group. The lowered-paragraph reset keeps the UA's `1em` paragraph margins
 * off it below every author rule, so its authored margin still applies, while
 * a real source paragraph beside it keeps its own margins.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlCompilation;

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

$lowered = HtmlCompilation::LOWERED_PARAGRAPH_CLASS;
$html = '<html><head><style>'
    . '.review{display:flex;flex-direction:column}'
    . '.author{font-weight:600;margin-top:16px}'
    . '</style></head><body><main><div class="review">'
    . '<p class="body">Quote text here.</p>'
    . '<div class="author">Anonyme</div>'
    . '</div></main></body></html>';

$result = ( new HtmlTransformer() )->transform($html, array())->toArray();
$blocks = (string) ($result['serialized_blocks'] ?? '');
$css = '';
foreach ( $result['assets'] ?? array() as $asset ) {
    if ( is_array($asset) && 'css' === ($asset['kind'] ?? null) ) {
        $css .= (string) ($asset['content'] ?? '');
    }
}

$assert(1 === preg_match('/<p class="([^"]*\\bauthor\\b[^"]*)">Anonyme<\\/p>/', $blocks, $run) && ! str_contains($blocks, 'wp-block-group author'), 'the text-only layout item lowers to one paragraph carrying its source class', $blocks);
$assert(in_array($lowered, preg_split('/\\s+/', trim($run[1] ?? '')) ?: array(), true), 'the paragraph is marked as lowered from a non-paragraph source', $run[1] ?? '');
$assert(1 === preg_match('/<p class="([^"]*)">Quote text here\\.<\\/p>/', $blocks, $source), 'the source paragraph lowers to core/paragraph', $blocks);
$assert(! in_array($lowered, preg_split('/\\s+/', trim($source[1] ?? '')) ?: array(), true), 'the source paragraph keeps its own box', $source[1] ?? '');
$assert(str_contains($css, ':root :where(p.' . $lowered . '){margin-block-start:0;margin-block-end:0}'), 'the zero-specificity lowered-paragraph margin reset ships with the page', $css);
$assert(1 === preg_match('/\\{font-weight:600\\}[^{]*\\{margin-top:16px\\}/', $css), 'the authored margin-top is still projected for the source class', $css);
if ( 0 < $failures ) {
    fwrite(STDERR, "text run synthetic paragraph FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "text run synthetic paragraph passed: {$passes} assertions\n";

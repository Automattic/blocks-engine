<?php
declare(strict_types=1);

/**
 * A source text run (`<div class="author">Anonyme</div>`) lowers to a
 * core/paragraph that is only a validity carrier: the source had no paragraph
 * box there. The carrier is marked as a synthetic paragraph, so the engine
 * support reset keeps the UA's `1em` paragraph margins off it, while a real
 * source paragraph beside it keeps its author margins.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;

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

$synthetic = SourceBlockAttributeProjector::SYNTHETIC_PARAGRAPH_CLASS;
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

$assert(1 === preg_match('/<div class="wp-block-group author[^"]*">\s*<!-- wp:paragraph[^>]*-->\s*<p class="([^"]*)">Anonyme<\/p>/', $blocks, $run), 'the text run lowers to a paragraph inside its source wrapper', $blocks);
$assert(in_array($synthetic, preg_split('/\s+/', trim($run[1] ?? '')) ?: array(), true), 'the text-run paragraph is marked as a synthetic paragraph', $run[1] ?? '');
$assert(1 === preg_match('/<p class="([^"]*)">Quote text here\.<\/p>/', $blocks, $source), 'the source paragraph lowers to core/paragraph', $blocks);
$assert(! in_array($synthetic, preg_split('/\s+/', trim($source[1] ?? '')) ?: array(), true), 'the source paragraph keeps its own box', $source[1] ?? '');
$assert(str_contains($css, ':root :where(.' . $synthetic . '){margin-top:0;margin-bottom:0}'), 'the synthetic paragraph margin reset ships with the page', $css);

if ( 0 < $failures ) {
    fwrite(STDERR, "text run synthetic paragraph FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "text run synthetic paragraph passed: {$passes} assertions\n";

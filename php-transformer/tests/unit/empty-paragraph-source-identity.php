<?php
declare(strict_types=1);

/**
 * An empty source paragraph that lowers to core/paragraph keeps the source
 * identity a non-empty one has: its classes and its source tag marker. The
 * author `p` rules that place it address that marker, so a bare paragraph
 * would fall back to the UA's `1em` block margins.
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

$html = '<html><head><style>'
    . 'p{margin-top:0;margin-bottom:1rem}'
    . '.card .desc p{margin-bottom:0}'
    . '.desc p:empty{min-height:1em}'
    . '</style></head><body><main>'
    . '<div class="card"><h3>Title</h3><div class="desc"><p class="lead"></p></div></div>'
    . '<div class="card"><h3>Other</h3><div class="desc"><p class="lead">Text</p></div></div>'
    . '</main></body></html>';

$result = ( new HtmlTransformer() )->transform($html, array())->toArray();
$blocks = (string) ($result['serialized_blocks'] ?? '');
$css = '';
foreach ( $result['assets'] ?? array() as $asset ) {
    if ( is_array($asset) && 'css' === ($asset['kind'] ?? null) ) {
        $css .= (string) ($asset['content'] ?? '');
    }
}

$assert(1 === preg_match('/<p class="([^"]*)">Text<\/p>/', $blocks, $filled), 'the non-empty paragraph lowers to core/paragraph', $blocks);
$assert(1 === preg_match('/<!-- wp:paragraph[^>]*-->\s*<p class="([^"]*)"><\/p>/', $blocks, $empty), 'the empty paragraph lowers to core/paragraph with a class attribute', $blocks);
$filledClasses = preg_split('/\s+/', trim($filled[1] ?? '')) ?: array();
$emptyClasses = preg_split('/\s+/', trim($empty[1] ?? '')) ?: array();
$marker = array_values(array_filter($filledClasses, static fn (string $class): bool => str_starts_with($class, 'blocks-engine-source-p-')))[0] ?? '';
$assert('' !== $marker, 'the non-empty paragraph carries the source p marker', $filled[1] ?? '');
$assert(in_array($marker, $emptyClasses, true), 'the empty paragraph carries the same source p marker', $empty[1] ?? '');
$assert(in_array('lead', $emptyClasses, true), 'the empty paragraph keeps its source class', $empty[1] ?? '');
$assert('' !== $marker && 1 === preg_match('/:where\(\.' . preg_quote($marker, '/') . '\)[^{]*\{[^}]*margin-top:0/', $css), 'the author p margin rule addresses the marker both paragraphs carry', $css);

if ( 0 < $failures ) {
    fwrite(STDERR, "empty paragraph source identity FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "empty paragraph source identity passed: {$passes} assertions\n";

<?php
declare(strict_types=1);

/**
 * Framework text-split comments (React's `"<!-- -->{text}<!-- -->"`) leave
 * RichText content, but the text-run boundaries they made stay. Browsers shape
 * each text node on its own, so joining the runs moves the glyphs at the seams.
 * Every other run is wrapped in the engine's neutral RichText carrier, which
 * author `span` rules do not reach and which re-converts to itself.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

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

$carrier = '<mark style="background-color:transparent;color:inherit" role="none">';
$transformer = new HtmlTransformer();
$blocks = static fn (string $html): string => (string) ($transformer->transform($html, array())->toArray()['serialized_blocks'] ?? '');

$html = '<html><head><style>.review span{color:red}</style></head><body><main>'
    . '<p class="review">"<!-- -->Je recommande<!-- -->"</p>'
    . '<h2>A<!-- -->B<!-- -->C</h2>'
    . '<p>Plain<!-- note --> text</p>'
    . '</main></body></html>';
$first = $blocks($html);

$assert(! str_contains(preg_replace('/<!-- \/?wp:[^>]*-->/', '', $first) ?? '', '<!--'), 'no source comment reaches RichText content', $first);
$assert(str_contains($first, '<p class="review">&quot;' . $carrier . 'Je recommande</mark>&quot;</p>'), 'the quoted run keeps its boundaries inside a neutral carrier', $first);
$assert(str_contains($first, '>A' . $carrier . 'B</mark>C</h2>'), 'every other heading run is carried, so each seam stays a boundary', $first);
$assert(str_contains($first, '<p>Plain' . $carrier . ' text</mark></p>'), 'a comment between two runs keeps one boundary', $first);
$assert(! str_contains($first, '<span>'), 'the boundary is not a span author span rules could reach', $first);

$saved = preg_replace('/<!-- \/?wp:[^>]*-->/', '', $first) ?? '';
$second = $blocks('<html><head><style>.review span{color:red}</style></head><body>' . $saved . '</body></html>');
preg_match_all('/<(p|h2)\b[^>]*>(.*?)<\/\1>/s', $first, $one);
preg_match_all('/<(p|h2)\b[^>]*>(.*?)<\/\1>/s', $second, $two);
$assert($one[2] === $two[2], 'converting the saved RichText content again is a fixed point', json_encode(array( $one[2], $two[2] )) ?: '');

// Author `mark` rules (Bootstrap's reboot pads every mark) address source
// marks only. Engine carriers were never marks in the source.
$marked = $transformer->transform('<html><head><style>.mark,mark{padding:.1875em;background-color:#fcf8e3}.hl{font-weight:700}</style></head><body><main>'
    . '<p>"<!-- -->Quote<!-- -->"</p><p>Real <mark>hit</mark> and <span class="hl">bold</span></p>'
    . '</main></body></html>', array())->toArray();
$markCss = '';
foreach ( $marked['assets'] ?? array() as $asset ) {
    if ( is_array($asset) && 'css' === ($asset['kind'] ?? null) ) {
        $markCss .= (string) ($asset['content'] ?? '');
    }
}
$markBlocks = (string) ($marked['serialized_blocks'] ?? '');
$assert(0 === preg_match('/(?:^|[},])\s*(?:\.mark\s*,\s*)?mark\s*[{,]/', $markCss), 'no author mark rule keeps a bare mark subject that reaches engine carriers', $markCss);
$assert(1 === preg_match('/:where\(\.blocks-engine-source-mark-[0-9a-f]+-\d+\)[^{]*\{padding:\.1875em/', $markCss), 'the author mark rule addresses the source mark marker', $markCss);
$assert(str_contains($markBlocks, '<mark style="padding:.1875em;background-color:#fcf8e3;color:inherit">hit</mark>'), 'a source mark keeps its author presentation', $markBlocks);

// Resolve display through author CSS, not just inline declarations.
foreach ( array(
    'inline-flex' => array( '<a class="b" href="/calc/">Use the <!-- -->Rate Calculator<!-- --> <svg viewBox="0 0 10 10"><path d="M0 0L10 10"/></svg></a>', 'Use the Rate Calculator', false ),
    'flex' => array( '<p class="b">Use the <!-- -->Rate Calculator<!-- --></p>', 'Use the Rate Calculator', false ),
    'grid' => array( '<p class="b">Use the <!-- -->Rate Calculator<!-- --></p>', 'Use the Rate Calculator', false ),
    'inline-grid' => array( '<p class="b">Use the <!-- -->Rate Calculator<!-- --></p>', 'Use the Rate Calculator', false ),
    'block' => array( '<p>Download the <!-- -->Four Seasons<!-- --> catalogue.</p>', 'Download the ' . $carrier . 'Four Seasons</mark> catalogue.', true ),
) as $display => [$markup, $expected, $hasCarrier] ) {
    $compiled = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array( 'index.html' => '<html><head><style>.b{display:' . $display . ';align-items:center;gap:.5rem}</style></head><body><main>' . $markup . '</main></body></html>' ),
    ))->toArray();
    $output = (string) ($compiled['serialized_blocks'] ?? '');
    $assert(str_contains($output, $expected) && $hasCarrier === str_contains($output, '<mark'), $display . ' preserves the direct parent text-item contract', $output);
}

if ( 0 < $failures ) {
    fwrite(STDERR, "comment text run boundaries FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "comment text run boundaries passed: {$passes} assertions\n";

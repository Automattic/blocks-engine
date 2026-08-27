<?php
declare(strict_types=1);

/**
 * Empty named fragment targets must keep their capture-time position so hash
 * navigation can scroll (issue #1290).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes   = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$transformer = new HtmlTransformer();
$html = '<main>'
    . '<span id="features" aria-hidden="true" style="position: absolute; top: 787px; left: 0px; width: 0px; height: 0px; overflow: hidden; pointer-events: none;"></span>'
    . '<h2>Built for the Conscious Traveler</h2>'
    . '<a href="#features">Features</a>'
    . '</main>';
$out = $transformer->transform($html)->toArray();
$serialized = (string) ( $out['serialized_blocks'] ?? '' );
$css = '';
foreach ( $out['assets'] ?? array() as $asset ) {
    if ( is_array($asset) && 'css' === ( $asset['kind'] ?? '' ) ) {
        $css .= (string) ( $asset['content'] ?? '' );
    }
}
if ( '' === $css ) {
    foreach ( $out['source_reports'] ?? array() as $report ) {
        if ( is_array($report) ) {
            $css .= json_encode($report);
        }
    }
}

$assert(str_contains($serialized, 'id="features"'), '1: fragment id is preserved', $serialized);
$assert(
    str_contains($css, '787px') && ( str_contains($css, 'position:absolute') || str_contains($css, 'position: absolute') ),
    '2: empty named target keeps absolute top',
    $css !== '' ? substr($css, 0, 1500) : $serialized
);

$filled = $transformer->transform(
    '<main><div id="card" style="position:absolute;top:40px;width:200px;height:80px"><p>Card</p></div></main>'
)->toArray();
$filledCss = '';
foreach ( $filled['assets'] ?? array() as $asset ) {
    if ( is_array($asset) && 'css' === ( $asset['kind'] ?? '' ) ) {
        $filledCss .= (string) ( $asset['content'] ?? '' );
    }
}
$assert(
    str_contains((string) ( $filled['serialized_blocks'] ?? '' ), 'Card'),
    '3: non-empty positioned content still converts',
    (string) ( $filled['serialized_blocks'] ?? '' )
);

if ( $failures > 0 ) {
    fwrite(STDERR, PHP_EOL . "named fragment target tests: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "named fragment target tests: {$passes} passed" . PHP_EOL);

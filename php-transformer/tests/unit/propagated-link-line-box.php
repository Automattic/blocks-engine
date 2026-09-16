<?php
declare(strict_types=1);

/**
 * Unit tests for the anchor a content-wrapping link is pushed into.
 *
 * Plain-PHP test script — no PHPUnit. When a source link wraps content that
 * has to become blocks, the link is pushed down onto inline carriers and the
 * resulting anchor exists only to hold the href. It has no presentation of its
 * own, but as a block box it establishes a line box from the inherited font, so
 * text the source sized smaller than its surroundings is measured against that
 * instead of its own line height. A stacked brand lockup grew by the difference
 * on every line.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes   = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) { ++$passes; return; }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$result = ( new HtmlTransformer() )->transform(
    '<style>.lockup{display:flex;flex-direction:column;line-height:1.25}'
    . '.brand{font-size:1.5rem}.sub{font-size:.65rem;text-transform:uppercase}</style>'
    . '<header><a href="#top" class="lockup"><span class="brand">Eloisa Calvinato</span><span class="sub">Psicóloga</span></a></header>'
    . '<main><p>Body</p></main>'
)->toArray();

$blocks = (string) ( $result['serialized_blocks'] ?? '' );
$css = '';
foreach ( $result['assets'] ?? array() as $asset ) {
    if ( 'css' === ( $asset['kind'] ?? '' ) ) $css .= "\n" . (string) ( $asset['content'] ?? '' );
}

$assert(
    str_contains($blocks, 'blocks-engine-inline-layout-carrier'),
    'the wrapping link is pushed onto inline carriers',
    $blocks
);
$assert(
    str_contains($css, ':where(p.blocks-engine-inline-layout-carrier>a){display:contents}'),
    'the introduced anchor contributes no box of its own',
    $css
);
$assert(
    str_contains($css, ':where(p.blocks-engine-inline-layout-carrier){display:contents'),
    'the carrier paragraph itself still contributes no box',
    $css
);
$assert(
    str_contains($blocks, 'Psicóloga') && str_contains($blocks, 'Eloisa Calvinato'),
    'both lockup lines survive the conversion',
    $blocks
);
$assert(
    str_contains($blocks, 'href="#top"'),
    'the source href is still reachable from the carried anchor',
    $blocks
);

if ( $failures > 0 ) {
    fwrite(STDERR, "Propagated link line box: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "Propagated link line box passed: {$passes} assertions\n");

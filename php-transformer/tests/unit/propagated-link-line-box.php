<?php
declare(strict_types=1);

/** CSS-stacked phrasing stays inside its source link, without extra line boxes. */

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
    str_contains($blocks, '<a href="#top" class="lockup">'),
    'the stacked phrasing retains its source anchor and class',
    $blocks
);
$assert(
    !str_contains($blocks, 'blocks-engine-propagated-link'),
    'no synthetic anchor is inserted inside the source layout',
    $css
);
$assert(
    str_contains($css, ':where(p.blocks-engine-synthetic-paragraph:not(.blocks-engine-source-box-paragraph):has(>a)){display:contents}'),
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

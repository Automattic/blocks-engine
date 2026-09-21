<?php
declare(strict_types=1);

/**
 * Inline grid-item placement must survive conversion of native block children.
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
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$result = ( new HtmlTransformer() )->transform(
    '<div style="display:grid;grid-template-columns:repeat(12,1fr)">'
    . '<h3 style="grid-area:1 / 1 / span 1 / span 8">Stay Connected</h3>'
    . '<p style="grid-column:2 / span 4;grid-row:2">Email</p>'
    . '</div>',
    array()
)->toArray();

$markup = (string) ( $result['serialized_blocks'] ?? '' );
$engineCss = '';
foreach ( $result['assets'] ?? array() as $asset ) {
    if ( is_array($asset) && 'engine-support' === ( $asset['source'] ?? '' ) ) {
        $engineCss .= (string) ( $asset['content'] ?? '' );
    }
}

$assert(
    str_contains($markup, 'wp-block-heading be-inline-geometry-'),
    'converted heading receives a geometry carrier for inline grid placement',
    $markup
);
$assert(
    str_contains($engineCss, 'grid-area:1 / 1 / span 1 / span 8'),
    'grid-area placement is emitted on the converted heading carrier',
    $engineCss
);
$assert(
    str_contains($markup, '<p class="be-inline-geometry-'),
    'converted paragraph receives a geometry carrier for grid longhands',
    $markup
);
$assert(
    str_contains($engineCss, 'grid-column:2 / span 4') && str_contains($engineCss, 'grid-row:2'),
    'grid-column and grid-row placement are emitted on the converted paragraph carrier',
    $engineCss
);

if ( 0 < $failures ) {
    fwrite(STDERR, sprintf('inline grid-item carrier contract FAILED: %d passed, %d failed%s', $passes, $failures, PHP_EOL));
    exit(1);
}

echo sprintf('Inline grid-item carrier contract passed: %d assertions%s', $passes, PHP_EOL);

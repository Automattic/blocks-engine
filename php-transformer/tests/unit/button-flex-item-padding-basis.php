<?php
declare(strict_types=1);

/**
 * A lowered button that is a `flex:1 0 0` item keeps its padding's share of
 * the row. Padding counts toward a flex item's hypothetical main size, so two
 * padded `flex:1 0 0` siblings split a row evenly. The core/buttons wrapper
 * takes the flex declaration while the padding moves to its link, so the
 * wrapper restates the basis as the source item's outer basis.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
    }
};
$cssOf = static function (string $html): string {
    $css = '';
    foreach ( ( new HtmlTransformer() )->transform($html)->toArray()['assets'] ?? array() as $asset ) {
        if ( is_array($asset) && 'css' === ( $asset['kind'] ?? '' ) ) {
            $css .= (string) ( $asset['content'] ?? '' );
        }
    }
    return $css;
};
$bar = static fn(string $row, string $item): string => '<style>*{box-sizing:border-box}.bar{display:flex;gap:8px;width:360px;padding:10px;' . $row . '}'
    . '.bar a{display:flex;align-items:center;justify-content:center;background:#f6d84a;color:#10253b;border-radius:4px;' . $item . '}</style>'
    . '<main><div class="bar"><a href="tel:+100">Call</a><a href="/quote">Get a quote</a></div></main>';

foreach ( array(
    'row, border-box' => array( 'flex-direction:row', 'flex:1 0 0;width:1px;padding:16px 24px', 'flex-basis:calc(24px + 24px)' ),
    'column, border-box' => array( 'flex-direction:column;height:200px', 'flex:1 0 0;padding:12px 24px 8px', 'flex-basis:calc(12px + 8px)' ),
    // A bar shown only on small screens is still the items' flex container.
    'row, hidden at desktop' => array( 'flex-direction:row}@media (min-width:1200px){.bar{display:none!important}', 'flex:1 0 0;width:1px;padding:16px 24px', 'flex-basis:calc(24px + 24px)' ),
    'row, content-box' => array( 'flex-direction:row', 'box-sizing:content-box;flex:1 0 10px;padding:0 20px;border:2px solid #000', 'flex-basis:calc(10px + 20px + 2px + 20px + 2px)' ),
) as $case => [ $row, $item, $basis ] ) {
    $css = $cssOf($bar($row, $item));
    $assert(str_contains($css, 'wp-block-button'), $case . ': the links lower to core/button', $css);
    $assert(str_contains($css, $basis), $case . ': the wrapper basis includes the link padding and border', $css);
}

// An auto basis already sizes from content, padding included.
$css = $cssOf($bar('flex-direction:row', 'flex:1 1 auto;padding:16px 24px'));
$assert(! str_contains($css, 'flex-basis:calc('), 'auto basis: no restated basis', $css);

if ( $failures > 0 ) {
    exit(1);
}
echo "OK\n";

<?php
declare(strict_types=1);

/**
 * When the toggle still shows above the menu's own `display` switch, the menu
 * collapses behind it up to the toggle's boundary. Google Sites hides its
 * sidebar off-canvas below 1280px while the toggle shows; a narrower
 * `display:none` (a phone-document swap at 767px) must not shrink that range.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$css = '.toggle{display:block}@media (min-width:1280px){.toggle{display:none}}'
    . '#panel{position:fixed;left:0;top:0;transform:translateX(-100%)}@media (min-width:1280px){#panel{transform:none}}'
    . '@media(max-width:767px){#panel{display:none!important}}';
$html = '<!doctype html><html><head><style>' . $css . '</style></head><body><header>'
    . '<div role="button" tabindex="0" class="toggle" aria-controls="panel" aria-expanded="false" title="Show sidebar"><svg viewBox="0 0 24 24"><path d="M3 6h18M3 12h18M3 18h18"></path></svg></div>'
    . '<nav id="panel"><ul><li><a href="/a">Alpha</a></li><li><a href="/b">Beta</a></li><li><a href="/c">Gamma</a></li></ul></nav>'
    . '</header><main><p>Body</p></main></body></html>';

$result = ( new HtmlTransformer() )->transform($html, array())->toArray();
$assetCss = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
preg_match_all('/@media\(min-width:600px\) and \(max-width:([0-9.]+)px\)/', $assetCss, $ranges);
$boundaries = array_values(array_unique($ranges[1]));

$failures = array();
if ( ! str_contains((string) $result['serialized_blocks'], '<!-- wp:navigation') ) {
    $failures[] = 'menu does not convert to core/navigation';
}
if ( array( '1279' ) !== $boundaries ) {
    $failures[] = 'collapsed range ends at ' . ( array() === $boundaries ? 'none' : implode(',', $boundaries) ) . 'px, expected 1279px';
}

foreach ( $failures as $failure ) {
    fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
}
echo 'Collapse boundary follows toggle: ' . ( 2 - count($failures) ) . ' passed, ' . count($failures) . ' failed' . PHP_EOL;
exit(array() === $failures ? 0 : 1);

<?php
declare(strict_types=1);

/**
 * A header anchor whose `display` the source switches at a breakpoint keeps
 * that switch: the synthetic paragraph carrier must not restate the base
 * `display:none`, which would outrank the source's `@media` rule and hide the
 * site title at every width (Google Sites shows it from 1280px).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$css = '.title{display:none;color:inherit}@media only screen and (min-width:1280px){.title{display:block}}';
$html = '<!doctype html><html><head><style>' . $css . '</style></head><body><header><div class="bar">'
    . '<a class="title" href="/">Site Title</a></div></header><main><p>Body</p></main></body></html>';

$result = ( new HtmlTransformer() )->transform($html, array())->toArray();
$assetCss = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));

$failures = array();
if ( ! str_contains((string) $result['serialized_blocks'], 'Site Title') ) {
    $failures[] = 'title text missing from blocks';
}
if ( 1 === preg_match('/p\.blocks-engine-synthetic-header-anchor-[0-9a-f]+>a\{[^}]*display:none/', $assetCss) ) {
    $failures[] = 'carrier restates display:none over the source breakpoint';
}

foreach ( $failures as $failure ) {
    fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
}
echo 'Header anchor conditional display: ' . ( 2 - count($failures) ) . ' passed, ' . count($failures) . ' failed' . PHP_EOL;
exit(array() === $failures ? 0 : 1);

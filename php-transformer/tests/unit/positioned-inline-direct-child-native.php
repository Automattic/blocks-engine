<?php
declare(strict_types=1);

/**
 * A positioned inline carrier whose children an author `.parent>child` rule
 * targets converts to native blocks. Selector projection re-targets the rule
 * onto the converted child, so raw HTML preservation is not needed (Google
 * Sites icon buttons: `.xjKiLb{position:relative}.xjKiLb>span{…}`).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = '<!doctype html><html><head><style>.icon{position:relative;top:50%}.icon>span{display:inline-block;position:relative}</style></head><body><main>'
    . '<div role="button" aria-label="Open search"><span class="icon"><span class="glyph" style="top:-12px"><svg viewBox="0 0 24 24"><path d="M3 3h18v18H3z"></path></svg></span></span></div>'
    . '</main></body></html>';
$result = ( new HtmlTransformer() )->transform($html, array())->toArray();
$blocks = (string) $result['serialized_blocks'];
$css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));

$failures = array();
if ( str_contains($blocks, '<!-- wp:html') ) {
    $failures[] = 'carrier is preserved as core/html';
}
preg_match('/class="wp-block-group glyph[^"]*\b(blocks-engine-semantic-[0-9a-f]+-\d+)/', $blocks, $marker);
if ( ! isset($marker[1]) || ! preg_match('/' . preg_quote($marker[1], '/') . '[^{}]*\{[^}]*display:inline-block;position:relative/', $css) ) {
    $failures[] = 'the .icon>span rule does not reach the converted child';
}

foreach ( $failures as $failure ) {
    fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
}
echo 'Positioned inline direct child native: ' . ( 2 - count($failures) ) . ' passed, ' . count($failures) . ' failed' . PHP_EOL;
exit(array() === $failures ? 0 : 1);

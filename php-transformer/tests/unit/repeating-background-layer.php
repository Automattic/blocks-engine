<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    }
};

$fixture = static function (string $declarations): string {
    return '<!doctype html><html><head><meta charset="utf-8"><title>Tile</title><style>body{margin:0}.band{position:relative;padding:80px 24px}.t{position:absolute;inset:0}h2{position:relative;margin:0}</style></head>'
        . '<body><section class="band"><div class="t" style="background-image:url(tile.png);' . $declarations . '"></div><h2>Answer every call</h2></section></body></html>';
};

foreach ( array(
    array( 'background-repeat:repeat', 'repeat', 'auto', null ),
    array( 'background-repeat:repeat;background-size:20px 30px;background-position:left top', 'repeat', '20px 30px', 'left top' ),
    array( 'background-repeat:repeat-x;background-size:contain', 'repeat-x', 'contain', null ),
    array( 'background: url(tile.png) repeat', 'repeat', 'auto', null ),
) as [$declarations, $repeat, $size, $position] ) {
    $result = (new HtmlTransformer())->transform($fixture($declarations))->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $assert(! str_contains($markup, '<!-- wp:image'), $declarations . ': repeating paint must not become an image.');
    preg_match_all('/<!-- wp:group (\{.*?\}) -->/', $markup, $matches);
    $layers = array();
    foreach ( $matches[1] as $json ) {
        $attrs = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if ( isset($attrs['style']['background']['backgroundImage']['url']) ) {
            $layers[] = $attrs;
        }
    }
    $assert(1 === count($layers), $declarations . ': exactly one group owns the background.');
    $attrs = $layers[0] ?? array();
    $background = $attrs['style']['background'] ?? array();
    $assert('tile.png' === ($background['backgroundImage']['url'] ?? null), 'The layer keeps the asset URL.');
    $assert($repeat === ($background['backgroundRepeat'] ?? null), 'The layer keeps the repeat.');
    $assert($size === ($background['backgroundSize'] ?? null), 'The layer uses authored size or explicit CSS initial auto.');
    if ( null !== $position ) {
        $assert($position === ($background['backgroundPosition'] ?? null), 'The layer keeps the authored position.');
    }
    $assert(str_contains((string) ($attrs['className'] ?? ''), 't'), 'The background group itself carries source presentation.');
    $assert(! preg_match('/<!-- wp:group [^\n]* -->\s*<div[^>]*class="[^"]*\bt\b[^>]*>\s*<!-- wp:group/', $markup), 'The empty source wrapper is replaced, not duplicated.');
    $assert(str_contains($markup, 'Answer every call'), 'Sibling heading survives.');
}

$control = (new HtmlTransformer())->transform($fixture('background-repeat:no-repeat;background-size:cover'))->toArray();
$assert(str_contains((string) ($control['serialized_blocks'] ?? ''), '<!-- wp:image'), 'Non-repeating cover paint still takes the image path.');

if ( 0 < $failures ) {
    exit(1);
}
fwrite(STDOUT, "Repeating background layer tests passed.\n");

<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A block-level hero whose sole child fills it (height:100%) and bottom-pins
// its content with a column flex box positions the content on that child's
// behalf: the cover takes the child's alignment and keeps the unpadded hero
// edge to edge. A child that does not fill the hero leaves the default.

$cover = static function (string $fill, string $fillStyle = ''): array {
    $html = '<style>.hero{height:480px;background-image:url(https://example.com/bg.png);background-size:cover}'
        . '.fill{' . $fill . '}.band{background:#2a493b;padding:2rem}</style>'
        . '<section class="hero"><div class="fill"' . ('' === $fillStyle ? '' : ' style="' . $fillStyle . '"') . '><div class="band"><h1>Get in touch</h1></div></div></section>';
    $blocks = (new HtmlTransformer())->transform($html)->toArray()['blocks'] ?? array();
    foreach ( $blocks as $block ) {
        if ( 'core/cover' === ($block['blockName'] ?? null) ) {
            return $block;
        }
    }
    throw new RuntimeException('Hero must convert to a cover.');
};

$expectPinned = static function (array $block, string $label): void {
    $attrs = $block['attrs'] ?? array();
    if ( ! str_starts_with((string) ($attrs['contentPosition'] ?? ''), 'bottom') ) {
        throw new RuntimeException($label . ': contentPosition must start with bottom, got ' . var_export($attrs['contentPosition'] ?? null, true));
    }
    foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
        if ( '0' !== ($attrs['style']['spacing']['padding'][$side] ?? null) ) {
            throw new RuntimeException($label . ': unpadded hero must keep zero ' . $side . ' padding.');
        }
    }
};

$expectPinned($cover('height:100%;display:flex;flex-direction:column;justify-content:flex-end'), 'stylesheet fill');
$expectPinned($cover('', 'height:100%;display:flex;flex-direction:column;justify-content:flex-end'), 'inline fill');
$expectPinned($cover('height:100%;display:flex;align-items:flex-end'), 'row flex fill');
// Bootstrap utilities (`h-100 d-flex flex-column justify-content-end`) declare every value !important.
$expectPinned($cover('height:100%!important;display:flex!important;flex-direction:column!important;justify-content:flex-end!important'), 'important fill');

$unfilled = $cover('display:flex;flex-direction:column;justify-content:flex-end');
if ( isset($unfilled['attrs']['contentPosition']) || isset($unfilled['attrs']['style']['spacing']['padding']) ) {
    throw new RuntimeException('A child that does not fill the hero must keep the default cover position and padding.');
}

echo "Cover filling child alignment contract passed.\n";

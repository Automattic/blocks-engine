<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\StandaloneSvgAsset;

$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 80"><defs><linearGradient id="paint"><stop offset="0" stop-color="red"/><stop offset="1" stop-color="blue"/></linearGradient></defs><rect width="240" height="80" fill="url(#paint)"/></svg>';
$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) { throw new RuntimeException($message); }
};
$assert(StandaloneSvgAsset::inspect($svg) === array('status' => 'supported', 'width' => 240, 'height' => 80), 'Gradient artwork and viewBox dimensions must survive');
$assert(StandaloneSvgAsset::inspect(str_replace('viewBox=', 'width="120" viewBox=', $svg))['height'] === 40, 'One explicit dimension preserves aspect ratio');
foreach (array(
    '<script>alert(1)</script>', '<image href="https://example.org/a.png"/>',
    '<foreignObject><p xmlns="http://www.w3.org/1999/xhtml">HTML</p></foreignObject>',
    '<use href="https://example.org/sprite.svg#mark"/>', '<style>rect{fill:red}</style>',
    '<?xml-stylesheet href="https://example.org/a.css"?>',
) as $extra) {
    $assert('supported' !== StandaloneSvgAsset::inspect(str_replace('</svg>', $extra . '</svg>', $svg))['status'], 'Dependent/active markup must be reported');
}
$assert('supported' !== StandaloneSvgAsset::inspect(str_replace('<rect ', '<rect onload="alert(1)" ', $svg))['status'], 'Event handlers must be rejected');
$assert('supported' !== StandaloneSvgAsset::inspect(str_replace('url(#paint)', 'url(https://example.org/paint)', $svg))['status'], 'External paint must be rejected');
$assert('invalid_svg' === StandaloneSvgAsset::inspect('<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]>' . $svg)['status'], 'Entity declarations must not be resolved');
$assert('invalid_svg' === StandaloneSvgAsset::inspect('<svg>')['status'], 'Malformed XML must be reported');
$assert('unresolved_svg_dimensions' === StandaloneSvgAsset::inspect(str_replace(' viewBox="0 0 240 80"', '', $svg))['status'], 'Dimensionless artwork must be reported');
echo "Standalone SVG asset contract passed.\n";

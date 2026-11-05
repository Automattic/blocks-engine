<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\StandaloneSvgAsset;

$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 80"><defs><linearGradient id="paint"><stop offset="0" stop-color="red"/><stop offset="1" stop-color="blue"/></linearGradient></defs><rect width="240" height="80" fill="url(#paint)"/></svg>';
$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) { throw new RuntimeException($message); }
};
$assert(StandaloneSvgAsset::inspect($svg) === array('status' => 'supported', 'width' => 240, 'height' => 80), 'Gradient artwork and viewBox dimensions must survive');
$focalGradient = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><defs><radialGradient id="g" cx="5" cy="5" r="5" fx="3" fy="3" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#0fbc5c"/><stop offset="1" stop-color="#0cba65"/></radialGradient></defs><path fill="url(#g)" d="M0 0h10v10H0z"/></svg>';
$assert(StandaloneSvgAsset::inspect($focalGradient) === array('status' => 'supported', 'width' => 10, 'height' => 10), 'Radial-gradient focal geometry must remain supported');
$editorSvg = '<?xml version="1.0" encoding="UTF-8"?><svg xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns="http://www.w3.org/2000/svg" xmlns:sodipodi="http://sodipodi.sourceforge.net/DTD/sodipodi-0.dtd" width="120" height="120" viewBox="0 0 120 120"><metadata><rdf:RDF><dc:format>image/svg+xml</dc:format></rdf:RDF></metadata><sodipodi:namedview pagecolor="#ffffff"/><path d="M10 10h100v100H10z" fill="#b33" sodipodi:nodetypes="cccc"/></svg>';
$assert(StandaloneSvgAsset::inspect($editorSvg) === array('status' => 'supported', 'width' => 120, 'height' => 120), 'Inert vector-editor metadata must not affect standalone SVG inspection');
$assert('supported' !== StandaloneSvgAsset::inspect(str_replace('<sodipodi:namedview pagecolor="#ffffff"/>', '<sodipodi:namedview><script xmlns="http://www.w3.org/2000/svg">alert(1)</script></sodipodi:namedview>', $editorSvg))['status'], 'Scripts inside foreign namespace elements must be rejected');
$assert('supported' !== StandaloneSvgAsset::inspect(str_replace('<sodipodi:namedview pagecolor="#ffffff"/>', '<sodipodi:namedview onload="alert(1)"/>', $editorSvg))['status'], 'Event handlers on foreign namespace elements must be rejected');
$assert(StandaloneSvgAsset::inspect(str_replace('viewBox=', 'width="120" viewBox=', $svg))['height'] === 40, 'One explicit dimension preserves aspect ratio');
foreach (array(
    '<script>alert(1)</script>', '<image href="https://example.org/a.png"/>',
    '<foreignObject><p xmlns="http://www.w3.org/1999/xhtml">HTML</p></foreignObject>',
    '<use href="https://example.org/sprite.svg#mark"/>',
    '<?xml-stylesheet href="https://example.org/a.css"?>', '<link href="https://example.org/a.css" rel="stylesheet"/>',
    '<style>@import url(https://example.org/a.css);</style>', '<style>rect{fill:url(https://example.org/p)}</style>',
    '<style>rect{fill:u\\72l(https://example.org/p)}</style>', '<rect width="1" height="1" style="fill:url(https://example.org/p)"/>',
) as $extra) {
    $assert('supported' !== StandaloneSvgAsset::inspect(str_replace('</svg>', $extra . '</svg>', $svg))['status'], 'Dependent/active markup must be reported');
}
// Exporters (e.g. Illustrator) style artwork with the SVG's own CSS, which styles only this image document.
$styled = str_replace(array('<svg ', '<rect '), array('<svg style="enable-background:new 0 0 240 80" ', '<style>.st0{fill:url(#paint)}</style><rect class="st0" '), $svg);
$assert(StandaloneSvgAsset::inspect($styled) === array('status' => 'supported', 'width' => 240, 'height' => 80), 'Self-contained SVG styling must survive');
$assert('supported' !== StandaloneSvgAsset::inspect(str_replace('<rect ', '<rect onload="alert(1)" ', $svg))['status'], 'Event handlers must be rejected');
$assert('supported' !== StandaloneSvgAsset::inspect(str_replace('url(#paint)', 'url(https://example.org/paint)', $svg))['status'], 'External paint must be rejected');
$assert('invalid_svg' === StandaloneSvgAsset::inspect('<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]>' . $svg)['status'], 'Entity declarations must not be resolved');
$assert('invalid_svg' === StandaloneSvgAsset::inspect('<svg>')['status'], 'Malformed XML must be reported');
$assert('unresolved_svg_dimensions' === StandaloneSvgAsset::inspect(str_replace(' viewBox="0 0 240 80"', '', $svg))['status'], 'Dimensionless artwork must be reported');
// Design tools export a photo wrapped in an SVG frame as an embedded data: raster.
$framed = str_replace('</svg>', '<image width="240" height="80" xlink:href="data:image/jpeg;base64,/9j/4AAQSkZJRg=="/></svg>', str_replace('<svg ', '<svg xmlns:xlink="http://www.w3.org/1999/xlink" zoomAndPan="magnify" ', $svg));
$assert(StandaloneSvgAsset::inspect($framed) === array('status' => 'supported', 'width' => 240, 'height' => 80), 'An embedded data: raster is self-contained artwork');
$assert('supported' !== StandaloneSvgAsset::inspect(str_replace('data:image/jpeg;base64,', 'data:image/svg+xml;base64,', $framed))['status'], 'An embedded SVG document must be reported');
echo "Standalone SVG asset contract passed.\n";

<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A Squarespace section divider clips the section's background with a
// bounding-box <clipPath> drawn in an SVG the page stylesheet positions out of
// flow. The section's text stays native blocks and the clip becomes an exact
// CSS shape(), so no SVG companion carries the definition.
$html = '<style>.border{position:absolute;inset:0;background:#f08e80}.section{position:relative}.divider svg{position:absolute;top:0;left:0;width:100%;height:100%}</style><main><section class="section">'
    . '<div class="border" style="clip-path: url(#wave)"><div class="background"></div></div>'
    . '<div class="content"><h2>Our why</h2><p>Body copy.</p></div>'
    . '<div class="divider"><svg class="clip" style="display:block"><clipPath id="wave" clipPathUnits="objectBoundingBox"><path class="clip-shape" d="M0,0 L1,0 L1,0.9 c-0.5,0.1 -0.5,-0.1 -1,0 z"></path></clipPath></svg></div>'
    . '</section></main>';
$result = ( new HtmlTransformer() )->transform($html)->toArray();
$blocks = (string) $result['serialized_blocks'];
$css = implode("\n", array_map(static fn (array $asset): string => (string) ( $asset['content'] ?? '' ), $result['assets'] ?? array()));

if ( str_contains($blocks, 'responsive-media') || ! str_contains($blocks, '<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Our why</h2>') || ! str_contains($blocks, '<p>Body copy.</p>') ) {
    throw new RuntimeException("The divider section lost its native text blocks:\n" . $blocks);
}
if ( str_contains($blocks, 'svg-artwork') || str_contains($blocks, 'clipPath') || str_contains($css, '#wave')
    || 1 !== preg_match('/\.(be-inline-geometry-[a-f0-9]+)\{[^}]*clip-path:shape\(from 0% 0%, line to 100% 0%, line to 100% 90%, curve by -100% 0% with -50% 10% \/ -50% -10%, close\)/', $css, $carrier)
    || ! str_contains($blocks, 'wp-block-group border ' . $carrier[1]) ) {
    throw new RuntimeException("The divider clip did not lower to a native shape on its consumer:\n" . $blocks . "\n" . $css);
}
// An in-flow carrier still occupies its box, so it keeps the SVG definition.
$inFlow = ( new HtmlTransformer() )->transform(str_replace('.divider svg{position:absolute;', '.divider svg{', $html))->toArray();
if ( ! str_contains((string) $inFlow['serialized_blocks'], 'clipPath id=\u0022wave\u0022') ) {
    throw new RuntimeException("An in-flow divider carrier was dropped:\n" . $inFlow['serialized_blocks']);
}
// Only same-document fragments are kept; a network clip source is not fetched.
$external = ( new HtmlTransformer() )->transform('<main><section><div class="b" style="clip-path:url(https://x.example/c.svg#a)"><div></div></div><p>x</p></section></main>')->toArray();
if ( str_contains(json_encode($external) ?: '', 'x.example') ) {
    throw new RuntimeException('A network clip-path source was carried.');
}
echo "Section divider clip native contract passed.\n";

<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A section divider clips the section's background with a <clipPath> drawn in a
// bare SVG elsewhere in the section. The section's text stays native blocks and
// the background keeps the clip, which names a definition the page still holds.
$html = '<style>.border{position:absolute;inset:0;background:#f08e80}.section{position:relative}</style><main><section class="section">'
    . '<div class="border" style="clip-path: url(#wave)"><div class="background"></div></div>'
    . '<div class="content"><h2>Our why</h2><p>Body copy.</p></div>'
    . '<div class="divider"><svg class="clip" style="display:block"><clipPath id="wave" clipPathUnits="objectBoundingBox"><path d="M0,0 L1,0 L1,0.9 C0.5,1 0.5,0.8 0,0.9 z"></path></clipPath></svg></div>'
    . '</section></main>';
$result = ( new HtmlTransformer() )->transform($html)->toArray();
$blocks = (string) $result['serialized_blocks'];
$css = implode("\n", array_map(static fn (array $asset): string => (string) ( $asset['content'] ?? '' ), $result['assets'] ?? array()));

if ( str_contains($blocks, 'responsive-media') || ! str_contains($blocks, '<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Our why</h2>') || ! str_contains($blocks, '<p>Body copy.</p>') ) {
    throw new RuntimeException("The divider section lost its native text blocks:\n" . $blocks);
}
if ( ! str_contains($blocks, 'clipPath id=\u0022wave\u0022') || 1 !== preg_match('/\.(be-inline-geometry-[a-f0-9]+)\{[^}]*clip-path:url\(#wave\)/', $css, $carrier) || ! str_contains($blocks, 'wp-block-group border ' . $carrier[1]) ) {
    throw new RuntimeException("The divider clip lost its definition or its consumer:\n" . $blocks . "\n" . $css);
}
// Only same-document fragments are kept; a network clip source is not fetched.
$external = ( new HtmlTransformer() )->transform('<main><section><div class="b" style="clip-path:url(https://x.example/c.svg#a)"><div></div></div><p>x</p></section></main>')->toArray();
if ( str_contains(json_encode($external) ?: '', 'x.example') ) {
    throw new RuntimeException('A network clip-path source was carried.');
}
echo "Section divider clip native contract passed.\n";

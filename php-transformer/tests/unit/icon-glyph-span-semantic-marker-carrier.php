<?php
declare(strict_types=1);

/**
 * An empty span painted by an icon-font `:before` glyph (a slideshow's
 * previous/next arrow) lowers to a RichText `<mark>` carrier. Author rules
 * naming the span were projected onto its semantic marker, and their authored
 * arms no longer reach the carrier, so the mark must carry that marker. Without
 * it the arrow lost `position:absolute` and sat in flow below the slides,
 * adding its line box to the page height.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Gallery</title><style>'
    . "@font-face{font-family:'ui-icons';src:url(icons.woff) format('woff')}"
    . '.gallery{position:relative}.gallery .posts{height:300px}.gallery .arrow{display:none}'
    . '.controls-arrows .gallery .arrow{position:absolute;top:50%;font-size:14px;line-height:40px;margin-top:-30px;display:inline-block;padding:10px}'
    . '.controls-arrows .gallery .arrow.previous-slide{left:0}.controls-arrows .gallery .arrow.next-slide{right:0}'
    . ".gallery .arrow.previous-slide:before{font-family:'ui-icons';content:\"\\e02c\";display:inline-block}"
    . ".gallery .arrow.next-slide:before{font-family:'ui-icons';content:\"\\e02d\";display:inline-block}"
    . '</style></head><body class="controls-arrows"><div class="gallery"><div class="slides-controls"><div class="posts"><p>Slide</p></div>'
    . '<span class="arrow previous-slide"></span><span class="arrow next-slide"></span>'
    . '</div></div><main><h1>After</h1><p>Text</p></main></body></html>';

$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'website/index.html',
    'files' => array( 'website/index.html' => $html ),
))->toArray();
$markup = (string) $result['serialized_blocks'];
$css = '';
foreach ( $result['assets'] ?? array() as $asset ) {
    if ( str_ends_with((string) ($asset['path'] ?? ''), '.css') ) {
        $css .= (string) ($asset['content'] ?? '') . "\n";
    }
}

foreach ( array( 'previous-slide', 'next-slide' ) as $arrow ) {
    if ( ! preg_match('/<mark class="arrow ' . $arrow . '([^"]*)"/', $markup, $match) ) {
        throw new RuntimeException($arrow . ': expected a RichText glyph carrier: ' . $markup);
    }
    if ( ! preg_match('/\bblocks-engine-semantic-[a-f0-9]+-\d+\b/', $match[1], $marker) ) {
        throw new RuntimeException($arrow . ': glyph carrier must carry its semantic marker: ' . $match[0]);
    }
    // The positioned rule addresses the carrier through that marker.
    if ( ! preg_match('/[^{}]*\.' . preg_quote($marker[0], '/') . '[^{}]*\{[^}]*position:absolute/', $css) ) {
        throw new RuntimeException($arrow . ': positioned author rule must address the carrier marker: ' . $css);
    }
}

echo "Icon glyph span semantic marker carrier passed\n";

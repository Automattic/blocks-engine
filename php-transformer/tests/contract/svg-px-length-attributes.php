<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// An SVG `width`/`height` attribute is a CSS length, so `40px` is as valid as
// `40`. The materialized image must keep that rendered size rather than
// falling back to the (smaller) viewBox size.
$icon = '<svg viewBox="0 0 24 24" fill="currentColor" width="40px" height="40px" class="icon"><path d="M4 8h16V6H4z"></path><path d="M4 13h16v-2H4z"></path><path d="M4 18h16v-2H4z"></path></svg>';
$css = '*{box-sizing:border-box}.icon{display:inline-block;vertical-align:top;padding:6px}.bar{display:flex;justify-content:space-between}.logo{margin:0}';
$sources = array(
    'rich-text icon in a link' => '<!doctype html><html><head><style>' . $css . '</style></head><body><header class="bar"><h3 class="logo">Acme</h3><span><a href="#" aria-label="Open menu">' . $icon . '</a></span></header><main><p>Body.</p></main></body></html>',
    'standalone icon' => '<!doctype html><html><head><style>' . $css . '</style></head><body><main><div>' . $icon . '</div><p>Body.</p></main></body></html>',
);

foreach ( $sources as $label => $source ) {
    $result = (new ArtifactCompiler())->compile(array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => $source ) ))->toArray();
    $markup = (string) $result['serialized_blocks'];
    if ( ! preg_match('/<img\b[^>]*inline-svg-[a-f0-9]{16}\.svg[^>]*>/', $markup, $image) ) {
        throw new RuntimeException($label . ': the SVG must materialize as an image.');
    }
    if ( str_contains($image[0], 'width:24px') || str_contains($image[0], 'height:24px') || str_contains($image[0], 'width="24"') ) {
        throw new RuntimeException($label . ': a px-suffixed SVG size must not fall back to the viewBox size: ' . $image[0]);
    }
    if ( ! preg_match('/width(?::|=")40(?:px)?/', $image[0]) || ! preg_match('/height(?::|=")40(?:px)?/', $image[0]) ) {
        throw new RuntimeException($label . ': the image must keep the 40px source size: ' . $image[0]);
    }
}

echo "OK: px-suffixed SVG width/height attributes keep their rendered size\n";

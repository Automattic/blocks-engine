<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Icon-only social links draw from a sprite file shipped with the site, sized
// by a wrapper and painted through a custom property. Each link keeps its
// icon: the referenced symbol travels with the standalone icon image and its
// paint resolves to the color the source renders.
$sprite = '<svg xmlns="http://www.w3.org/2000/svg"><symbol id="ig" viewBox="0 0 64 64"><path d="M20 20h24v24H20z"/></symbol><symbol id="fb" viewBox="0 0 64 64"><circle cx="32" cy="32" r="12"/></symbol></svg>';
$link = static fn (string $label, string $id): string => '<a href="https://example.com/' . $id . '" class="icon" aria-label="' . $label . '"><div><svg viewBox="0 0 64 64"><use class="mark" xlink:href="/svg/social.svg#' . $id . '"></use></svg></div></a>';
$html = '<!doctype html><html><head><title>Links</title><style>.icons{display:flex;gap:12px;--mark:#c00}.icon{display:inline-block;overflow:hidden;line-height:1.8;position:relative;width:48px;height:48px}.icon>div,.icon svg{position:absolute;inset:0;width:100%;height:100%}.mark{fill:var(--mark,transparent)}</style></head><body><main>'
    . '<nav class="icons">' . $link('Instagram', 'ig') . $link('Facebook', 'fb') . '</nav></main></body></html>';

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'svg/social.svg' => $sprite)))->toArray();
$css = rawurldecode(implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $result['assets'] ?? array())));
foreach ( array( 'ig' => '<path d="M20 20h24v24H20z"', 'fb' => '<circle cx="32" cy="32" r="12"' ) as $id => $shape ) {
    if ( ! preg_match('/navigation-link-icon-[a-f0-9]+[^{]*\{[^}]*<use[^>]*xlink:href="#' . $id . '"[^>]*fill:#c00[^>]*><\/use><defs><symbol id="' . $id . '"[^>]*>' . preg_quote($shape, '/') . '[^<]*<\/[a-z]+><\/symbol><\/defs><\/svg>/', $css) ) {
        throw new RuntimeException("The {$id} link lost its sprite icon or paint:\n" . substr($css, 0, 3000));
    }
}
// The item keeps a text line box (inline-block, inherited line-height) and
// clips overflow: a baseline-aligned icon would sit below it, clipped away.
if ( 2 !== preg_match_all('/navigation-link-icon-[a-f0-9]+[^{]*\{display:inline-block;vertical-align:top;/', $css) ) {
    throw new RuntimeException("Icon links are not top-aligned in their item:\n" . substr($css, 0, 3000));
}
// An icon set enlarged by a uniform scale() transform (here via a custom
// property) keeps that enlargement around the icon's center.
$scaled = ( new HtmlTransformer() )->transform(
    '<style>.w{--s:2;display:inline-block;overflow:hidden;position:relative;width:28px;height:28px}.w>div,.w svg{position:absolute;inset:0;width:100%;height:100%}.w>div{transform:scale(var(--s,1))}</style>'
    . '<nav><a href="https://x.example/a" class="w" aria-label="A"><div><svg viewBox="0 0 64 64"><path d="M20 20h24v24H20z"/></svg></div></a></nav>'
)->toArray();
$scaledCss = implode("\n", array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), $scaled['assets'] ?? array()));
if ( 1 !== preg_match('/navigation-link-icon-[a-f0-9]+[^{]*\{[^}]*background-size:200% 200%/', $scaledCss) ) {
    throw new RuntimeException("Scaled icon artwork lost its scale:\n" . substr($scaledCss, 0, 3000));
}
echo "SVG sprite icon links contract passed.\n";

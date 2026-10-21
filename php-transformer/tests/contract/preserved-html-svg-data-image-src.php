<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;

/**
 * Contract coverage for SVG data images in preserved source HTML (#3238).
 *
 * A slider whose slides a script addresses is kept whole as a runtime-island
 * core/html block. Its aspect-ratio holder is an `<img>` whose only source is
 * a percent-encoded `data:image/svg+xml` placeholder; losing that src collapses
 * the slide to zero height. An SVG loaded as an image renders in secure static
 * mode, so image-loading attributes keep it. Browsing-context and navigation
 * attributes (iframe, object, embed, links) keep rejecting it, and non-image
 * data types stay rejected everywhere.
 */

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$svg      = 'data:image/svg+xml,%3Csvg%20viewBox%3D%220%200%20133%20100%22%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20%2F%3E';
$svg64    = 'data:image/svg+xml;base64,' . base64_encode('<svg viewBox="0 0 4 3" xmlns="http://www.w3.org/2000/svg"/>');
$textHtml = 'data:text/html,%3Cscript%3Ealert(1)%3C%2Fscript%3E';
$png      = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

$html = '<!doctype html><html><head><title>Screenshots</title><style>.p{display:grid;position:relative}.p>.r{grid-area:1/1;width:100%}.p>.w{position:absolute;inset:0}.p img.i{width:100%;height:100%;max-height:100%}</style></head><body><main>'
    . '<div class="carousel-stage">'
    . '<div class="slide" data-slide><div class="p"><img alt="" class="r" src="' . $svg . '"><div class="w"><img class="i" src="media/a.png" alt="Shot"></div></div></div>'
    . '<div class="slide" data-slide>'
    . '<picture><source class="pic-source" srcset="' . $svg64 . ' 1x"><img alt="" class="pic-img" src="' . $svg64 . '"></picture>'
    . '<iframe class="neg-iframe" title="Frame" src="' . $svg . '"></iframe>'
    . '<object class="neg-object" data="' . $svg . '"></object>'
    . '<embed class="neg-embed" src="' . $svg . '">'
    . '<a class="neg-link" href="' . $svg . '">Open</a>'
    . '<img alt="" class="neg-text-html" src="' . $textHtml . '">'
    . '</div>'
    . '</div>'
    . '</main><script>document.querySelectorAll("[data-slide]").forEach(function (slide, index) { slide.hidden = index > 0; });</script></body></html>';

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'media/a.png' => $png)))->toArray();
$page   = (string) (current($result['source_reports']['wordpress_site_plan']['pages'] ?? array())['canonical_block_markup'] ?? '');

$island = preg_match('#<!-- wp:html -->(.*?)<!-- /wp:html -->#s', $page, $match) ? $match[1] : '';
$assert(str_contains($island, 'class="carousel-stage"'), 'The slider is preserved as one runtime-island core/html block: ' . substr($page, 0, 1500));

$tag = static function (string $class) use ($island): string {
    return preg_match('#<[a-z]+\b[^>]*\bclass="' . preg_quote($class, '#') . '"[^>]*>#', $island, $found) ? $found[0] : '';
};

$assert(str_contains($tag('r'), 'src="' . $svg . '"'), 'The ratio holder keeps its percent-encoded data:image/svg+xml src: ' . $tag('r'));
$assert(str_contains($tag('pic-img'), 'src="' . $svg64 . '"'), 'A picture image keeps a base64 data:image/svg+xml src: ' . $tag('pic-img'));
$assert(str_contains($tag('pic-source'), 'srcset="' . $svg64 . ' 1x"'), 'A picture source keeps a data:image/svg+xml srcset: ' . $tag('pic-source'));

foreach (array('neg-iframe' => 'src', 'neg-object' => 'data', 'neg-embed' => 'src', 'neg-link' => 'href', 'neg-text-html' => 'src') as $class => $attribute) {
    $element = $tag($class);
    $assert('' !== $element, "The {$class} element is still preserved: " . $island);
    $assert(! preg_match('/\s' . $attribute . '\s*=/i', $element) && ! str_contains($element, 'data:'), "The {$class} {$attribute} data URL is stripped: {$element}");
}

// The shared helper is element-aware.
$assert(SourceDom::safeFallbackUrl($svg, 'src', 'img'), 'img src accepts an SVG data image.');
$assert(SourceDom::safeFallbackUrl($svg, 'srcset', 'source', true), 'picture source srcset accepts an SVG data image.');
$assert(! SourceDom::safeFallbackUrl($svg, 'src', 'source'), 'A source outside picture keeps rejecting an SVG data image.');
$assert(! SourceDom::safeFallbackUrl($svg, 'src'), 'An element-less src keeps rejecting an SVG data image.');
foreach (array(array('src', 'iframe'), array('data', 'object'), array('src', 'embed'), array('href', 'a'), array('action', 'form'), array('poster', 'video'), array('href', 'img')) as [$attribute, $element]) {
    $assert(! SourceDom::safeFallbackUrl($svg, $attribute, $element), "{$element} {$attribute} keeps rejecting an SVG data image.");
}
$assert(! SourceDom::safeFallbackUrl($textHtml, 'src', 'img'), 'img src keeps rejecting non-image data.');
$assert(! SourceDom::safeFallbackUrl('data:image/svg+xml;base64,%%%', 'src', 'img'), 'A malformed base64 SVG data image is rejected.');
$assert(! SourceDom::safeFallbackUrl('data:image/svg+xml,' . str_repeat('a', SourceDom::MAX_SVG_DATA_IMAGE_URL_BYTES), 'src', 'img'), 'An oversized SVG data image is rejected.');

echo "Preserved HTML SVG data image src contract passed.\n";

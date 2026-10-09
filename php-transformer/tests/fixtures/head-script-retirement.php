<?php
declare(strict_types=1);

$cycle = static fn(array $keys): array => array('coverage' => 'complete', 'restoration' => 'verified', 'initial' => 0, 'frames' => array_map(static fn(string $key): array => array('key' => $key, 'fullImage' => $key), $keys));
$inline = $cycle(array('/full/a.png', '/full/b.png'));
$inline['selector'] = '#pictures';
$inline['viewport'] = array('width' => 1440, 'height' => 900);
$full = $cycle(array('/full/a.png', '/full/b.png'));
$state = array('kind' => 'gallery', 'status' => 'captured', 'gallery' => array('inline' => $inline, 'lightbox' => $full, 'closed' => true, 'selection' => array(0, 1)));
$items = static fn(string $directory): string => '<div><img src="/' . $directory . '/a.png" alt="A"></div><div><img src="/' . $directory . '/b.png" alt="B"></div>';
$helper = 'document.querySelector("meta[data-mode]").setAttribute("content","ready");';
$head = '<meta data-mode content="waiting"><script type="application/json" id="route-data">{"mode":"gallery"}</script><script id="empty"> </script>'
    . '<script data-dla-gallery-runtime>window.captureGallery=true;</script>'
    . '<script id="retained-first">' . $helper . '</script><script src="/script.js"></script><script id="retained-second">' . $helper . '</script>';
$body = '<section id="pictures" data-dla-gallery data-dla-gallery-source="#pictures" data-dla-gallery-capture-width="1440"><h2>Pictures</h2><div class="gallery carousel"><div id="stage" data-dla-gallery-stage data-dla-gallery-initial="0" data-dla-dialog-trigger="panel" aria-controls="panel">' . $items('inline') . '</div><button aria-label="Previous image">Previous</button><button aria-label="Next image">Next</button></div><div id="panel" data-dla-dialog-panel="panel" hidden><div data-dla-gallery-stage>' . $items('full') . '</div></div></section>';
$files = array(
    'website/index.html' => '<html><head>' . $head . '</head><body>' . $body . '</body></html>',
    'website/other.html' => '<html><head><meta data-mode content="waiting"><script id="other-helper">' . $helper . '</script></head><body><h1>Other route</h1></body></html>',
    'website/third.html' => '<html><head><meta data-mode content="waiting"><script type="application/json" id="third-data">{"mode":"third"}</script><script id="third-helper">' . $helper . '</script></head><body><h1>Third route</h1></body></html>',
    'website/script.js' => 'window.realExternalAsset=true;',
    'interaction-states.json' => json_encode(array('schema' => 'data-liberation/captured-interactions/v1', 'pages' => array(array('sourceUrl' => 'https://neutral.test/', 'states' => array($state))))),
    'capture-receipt.json' => json_encode(array('schema' => 'data-liberation/capture-receipt/v1', 'routes' => array(array('url' => 'https://neutral.test/', 'path' => 'website/index.html')))),
);
foreach (array('inline', 'full') as $directory) foreach (array('a', 'b') as $name) {
    $files['website/' . $directory . '/' . $name . '.png'] = array('content_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a3ioAAAAASUVORK5CYII=');
}
return array('entrypoint' => 'website/index.html', 'files' => $files);

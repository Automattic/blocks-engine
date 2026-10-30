<?php
// Five logical source slots, including a repeated photograph, plus a loop clone.
$frames = array();
foreach (array('one', 'two', 'two', 'four', 'five') as $index => $name) {
    $frames[] = array('id' => 'slide-' . $index, 'html' => '<div><img src="https://example.org/' . $name . '.jpg"></div>', 'media' => array(array('asset' => 'https://example.org/' . $name . '.jpg')));
}
$frames[] = array('id' => 'clone', 'clone' => true, 'html' => '<div data-clone="true"></div>', 'media' => $frames[0]['media']);
$component = array('selector' => '#gallery', 'kind' => 'carousel', 'function' => 'slideshow', 'status' => 'captured',
    'slideshow' => array('initial' => 2, 'frames' => $frames, 'navigation' => array(array('mode' => 'arrows'), array('mode' => 'swipe')),
        'views' => array(array('width' => 390, 'framesPerView' => 1), array('width' => 1440, 'framesPerView' => 1))));
$files = array(
    array('path' => 'website/index.html', 'content' => '<html><body><div id="gallery" style="transform:translateX(-319px)">Frozen</div></body></html>'),
    array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.org/', 'path' => 'website/index.html'))))),
    array('path' => 'interaction-states.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/captured-interactions/v2', 'pages' => array(array('sourceUrl' => 'https://example.org/', 'components' => array($component)))))), 
);
$assets = array();
foreach (array('one', 'two', 'four', 'five') as $name) {
    $assets[] = array('id' => 'https://example.org/' . $name . '.jpg', 'retrieval' => 'retrieved', 'portable' => 'included', 'path' => 'website/assets/' . $name . '.jpg');
    $files[] = array('path' => 'website/assets/' . $name . '.jpg', 'content' => 'fixture');
}
$files[] = array('path' => 'asset-evidence.json', 'content' => json_encode(array('assets' => $assets)));
return $files;

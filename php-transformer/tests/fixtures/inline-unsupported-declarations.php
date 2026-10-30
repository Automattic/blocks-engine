<?php
declare(strict_types=1);

// Inline declarations with no block support must render after conversion (#2974).
return array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><title>Inline carrier</title></head><body><main><div id="mixed" style="background: red; backdrop-filter: blur(14px); transform: rotate(2deg)"><p>a</p></div></main></body></html>',
    'nested-header.html' => '<!doctype html><html><head><title>Nested header</title></head><body><main><div><header style="backdrop-filter: blur(14px)"><p>a</p></header><main><p>b</p></main></div></main></body></html>',
));

<?php
declare(strict_types=1);

// Neutral three-route reduction of #3967. The mobile bar stays page-owned,
// while the repeated header is extracted from both document scopes. A real
// source toggle supplies collapse evidence independently of the device copies.
$header = '<header class="top"><button aria-label="Open menu" aria-controls="primary-menu" aria-expanded="false">☰</button><div class="brand"><a href="/">Studio Name</a></div><nav id="primary-menu"><ul><li><a href="/">Home</a></li><li><a href="/about/">About</a></li><li><a href="/visit/">Visit</a></li></ul></nav></header>';
$css = '.is-mobile .brand{display:none}.is-mobile nav{display:none}'
    . '.data-blocks-engine-capture-mobile-document{display:none!important}'
    . '@media(max-width:767px){.data-blocks-engine-capture-desktop-document{display:none!important}.data-blocks-engine-capture-mobile-document{display:contents!important}}';
$files = array();
foreach (array('index.html' => 'Home', 'about/index.html' => 'About', 'visit/index.html' => 'Visit') as $path => $title) {
    $files[$path] = '<!doctype html><html><head><title>' . $title . '</title><style>' . $css . '</style></head><body>'
        . '<div class="data-blocks-engine-capture-desktop-document is-desktop" data-blocks-engine-capture-document-scope="">' . $header . '<main><h1>' . $title . '</h1><p>' . $title . ' desktop content.</p></main></div>'
        . '<div class="data-blocks-engine-capture-mobile-document is-mobile" data-blocks-engine-capture-document-scope="">' . $header . '<div class="mbar"><a href="/">Studio Name</a></div><main><h1>' . $title . '</h1><p>' . $title . ' mobile content.</p></main></div>'
        . '</body></html>';
}
return array('entrypoint' => 'index.html', 'files' => $files);

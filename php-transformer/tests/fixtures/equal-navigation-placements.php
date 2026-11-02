<?php
// Three routes, each containing independently captured desktop/mobile documents.
$files = array();
foreach (array('index.html' => 'Home', 'about.html' => 'About', 'visit.html' => 'Visit') as $path => $title) {
    $documents = '';
    foreach (array('desktop', 'mobile') as $device) {
        $links = '<ul><li><a href="/"> <span class="">Home</span> </a></li><li><a href="/about">About</a></li><li><a href="/visit">Visit</a></li></ul>';
        $documents .= '<div data-blocks-engine-capture-' . $device . '-document><header><p>Example Studio</p><nav class="primary-' . $device . '">' . $links . '</nav></header><main><h1>' . $title . '</h1><details class="off-canvas"><summary>Explore</summary><nav id="drawer-' . $device . '" class="drawer">' . str_replace('<a ', '<a style="color:red" class="drawer-link" ', $links) . '</nav></details><p>Content for ' . $title . '</p></main></div>';
    }
    $files[$path] = '<!doctype html><html><body>' . $documents . '</body></html>';
}
return array('entrypoint' => 'index.html', 'files' => $files);

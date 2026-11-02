<?php
// Neutral three-route reproduction of #3978, with both capture documents.
$files = array();
foreach (array('index', 'about', 'contact') as $route) {
    $html = '<html><head><style>.bar{position:fixed;left:0;right:0;bottom:0;height:60px;background:#333}.bar-spacer{height:60px}</style></head><body>';
    foreach (array('desktop', 'mobile') as $device) {
        $html .= '<div class="data-blocks-engine-capture-' . $device . '-document" data-blocks-engine-capture-device-document="' . $device . '"><div class="page-wrapper"><header><p>Site</p></header><main><h1>' . $route . '</h1><p>Route content</p></main><footer class="site-footer ' . $device . '-footer"><p>Shared footer</p></footer><div class="bar"><a href="mailto:a@example.com">Mail</a></div><div class="bar-spacer"></div><p>Only ' . $route . '</p></div></div>';
    }
    $files[$route . '.html'] = $html . '</body></html>';
}
return array('files' => $files);

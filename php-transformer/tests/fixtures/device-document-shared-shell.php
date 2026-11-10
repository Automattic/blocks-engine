<?php
declare(strict_types=1);

// Two-page reduction of #5016: identical landmarks in distinct device documents.
$css = ':where(.data-blocks-engine-capture-desktop-document) #nav li{display:inline-block;margin:0 8px}:where(.data-blocks-engine-capture-mobile-document) #nav li{display:block}'
    . '@media (max-width:767px){.data-blocks-engine-capture-desktop-document{display:none}}@media (min-width:768px){.data-blocks-engine-capture-mobile-document{display:none}}';
$header = '<div id="header-wrap"><div id="header"><div id="logo"><a href="/index.html">Farm Sanctuary</a></div><div id="nav" class="nav"><ul class="menu"><li><a href="/index.html">Home</a></li><li><a href="/about.html">About</a></li></ul></div></div></div>';
$files = array();
foreach (array('index.html' => 'Home', 'about.html' => 'About') as $path => $title) {
    $content = $header . '<div id="main"><h1>' . $title . '</h1><p>' . $title . ' page body.</p></div><div id="footer"><p>Footer text</p></div>';
    $files[$path] = '<!doctype html><html><head><meta charset="utf-8"><title>' . $title . '</title><style>' . $css . '</style></head><body class="page-index">'
        . '<div class="data-blocks-engine-capture-desktop-document page-index" data-blocks-engine-capture-document-scope="">' . $content . '</div>'
        . '<div class="data-blocks-engine-capture-mobile-document page-index touch" data-blocks-engine-capture-document-scope="">' . $content . '</div></body></html>';
}
return array('entrypoint' => 'index.html', 'files' => $files);

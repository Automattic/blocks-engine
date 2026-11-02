<?php
// Three neutral routes; distinct mobile chrome exercises detached shared parts.
$document = static function (string $title, bool $tall = false): string {
    $inner = '<div class="backdrop"></div><div class="page-body"><header class="site-top"><p>Studio</p></header><main><h1>' . $title . '</h1><p' . ($tall ? ' style="height:900px"' : '') . '>Route content</p></main><footer class="site-footer"><p>Copyright Studio</p></footer></div>';
    return '<!doctype html><html><head><style>html,body{margin:0}p,h1{margin:0}.page-body{min-height:100vh;display:flex;flex-direction:column}main{flex:1}.site-top{height:60px}.site-footer{height:84px}@media (max-width:600px){.data-blocks-engine-capture-desktop-document{display:none}}@media (min-width:601px){.data-blocks-engine-capture-mobile-document{display:none}}</style></head><body><div class="data-blocks-engine-capture-desktop-document" data-blocks-engine-capture-document-scope="">' . $inner . '</div><div class="data-blocks-engine-capture-mobile-document" data-blocks-engine-capture-document-scope="">' . str_replace(array('Studio</p>', 'Copyright Studio'), array('Mobile Studio</p>', 'Mobile Copyright Studio'), $inner) . '</div></body></html>';
};
return array('entrypoint' => 'index.html', 'files' => array('index.html' => $document('Home'), 'about/index.html' => $document('About'), 'visit/index.html' => $document('Visit', true)));

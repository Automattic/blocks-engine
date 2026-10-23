<?php

$css = 'body{margin:0;font-family:sans-serif;color:#0f172a;background:#fff}'
    . '.bg-blue-600{--tw-bg-opacity:1;background-color:rgb(37 99 235/var(--tw-bg-opacity,1))}'
    . '.hover\:bg-blue-700:hover{--tw-bg-opacity:1;background-color:rgb(29 78 216/var(--tw-bg-opacity,1))}'
    . '.text-white{color:#fff}.btn{display:inline-flex;padding:8px 16px;border-radius:6px}';
$page = '<!doctype html><html><head><title>Buttons</title><link rel="stylesheet" href="assets/site.css"></head><body>'
    . '<header><a href="#quote"><button class="btn bg-blue-600 hover:bg-blue-700 text-white">Get a quote</button></a></header>'
    . '<main><section><p>Ready?</p><a href="#quote"><button class="btn bg-blue-600 hover:bg-blue-700 text-white">Start your project</button></a></section></main></body></html>';
return array('entrypoint' => 'index.html', 'files' => array('assets/site.css' => $css, 'index.html' => $page));

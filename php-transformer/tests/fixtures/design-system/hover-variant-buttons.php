<?php
// Buttons with different colours, each with a hover rule (Tailwind-style).
$css = '*,:after,:before{box-sizing:border-box}body{margin:0;font-family:sans-serif;color:#0f172a;background:#fff}'
    . '.bg-blue-600{--tw-bg-opacity:1;background-color:rgb(37 99 235/var(--tw-bg-opacity,1))}'
    . '.hover\:bg-blue-700:hover{--tw-bg-opacity:1;background-color:rgb(29 78 216/var(--tw-bg-opacity,1))}'
    . '.bg-white{--tw-bg-opacity:1;background-color:rgb(255 255 255/var(--tw-bg-opacity,1))}'
    . '.hover\:bg-blue-50:hover{--tw-bg-opacity:1;background-color:rgb(239 246 255/var(--tw-bg-opacity,1))}'
    . '.text-white{color:#fff}.text-blue-700{color:#1d4ed8}.band{background:#2563eb;padding:32px}.btn{display:inline-flex;padding:8px 16px;border-radius:6px;text-decoration:none}';
$page = '<!doctype html><html><head><title>Buttons</title><link rel="stylesheet" href="assets/site.css"></head><body>'
    . '<header><a class="btn bg-blue-600 hover:bg-blue-700 text-white" href="#quote">Get a quote</a></header>'
    . '<main><section class="band"><h1 class="text-white">Websites that work</h1><a class="btn bg-white hover:bg-blue-50 text-blue-700" href="#quote">Request a quote</a></section>'
    . '<section><p>Ready?</p><a class="btn bg-blue-600 hover:bg-blue-700 text-white" href="#quote">Start your project</a></section></main></body></html>';
return array('entrypoint' => 'index.html', 'files' => array('assets/site.css' => $css, 'index.html' => $page));

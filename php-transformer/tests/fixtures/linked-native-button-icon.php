<?php
$css = 'body{margin:0;font-family:sans-serif}.band{background:#2563eb;padding:32px}'
    . '.btn{display:inline-flex;align-items:center;gap:8px;border-radius:6px;padding:24px 28px;font-size:16px}'
    . '.bg-white{background-color:#fff}.text-blue-700{color:#1d4ed8}.hover\:bg-blue-50:hover{background-color:#eff6ff}.icon{width:16px;height:16px;margin-left:8px}';
$icon = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon"><path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path></svg>';
$page = '<!doctype html><html><head><title>Hero</title><link rel="stylesheet" href="assets/site.css"></head><body><main><section class="band">'
    . '<a href="#quote"><button class="btn bg-white text-blue-700 hover:bg-blue-50">Request a quote ' . $icon . '</button></a>'
    . '<a href="#call"><button class="btn bg-white text-blue-700">Call us</button></a>'
    . '</section></main></body></html>';
return array('entrypoint' => 'index.html', 'files' => array('assets/site.css' => $css, 'index.html' => $page));

<?php

$css = '.band{background:#1d4ed8;padding:32px}'
    . '.btn{display:inline-flex;align-items:center;border:1px solid #fff;border-radius:6px;padding:8px 16px;color:#fff}'
    . '.bg-transparent{background-color:transparent}.hover\:bg-white\/10:hover{background-color:#ffffff1a}';
$page = '<!doctype html><html><head><title>Buttons</title><link rel="stylesheet" href="assets/site.css"></head><body>'
    . '<section class="band"><a href="tel:5550100"><button class="btn bg-transparent hover:bg-white/10">Call us</button></a>'
    . '<a class="btn bg-transparent hover:bg-white/10" href="#b">Plain link</a></section></body></html>';
return array('entrypoint' => 'index.html', 'files' => array('assets/site.css' => $css, 'index.html' => $page));

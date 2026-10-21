<?php

$css = '*{box-sizing:border-box}button{border:0;font:inherit}'
    . '.btn{display:inline-flex;align-items:center;gap:8px;border-radius:6px}'
    . '.h-9{height:2.25rem}.py-6{padding-top:1.5rem;padding-bottom:1.5rem}.px-7{padding-left:1.75rem;padding-right:1.75rem}'
    . '.bg-white{background-color:#fff}.text-blue-700{color:#1d4ed8}.hover\:bg-blue-50:hover{background-color:#eff6ff}';
$page = '<!doctype html><html><head><title>Linked controls</title><link rel="stylesheet" href="assets/site.css"></head><body><main><section style="background:#1d4ed8;padding:32px">';
foreach (array('none' => '', 'plain' => '<svg width="16" height="16" viewBox="0 0 24 24"><path d="M5 12h14"/></svg>', 'classed' => '<svg width="16" height="16" viewBox="0 0 24 24" class="icon"><path d="M5 12h14"/></svg>') as $variant => $icon) {
    $page .= '<a href="#' . $variant . '"><button class="btn h-9 bg-white text-blue-700 hover:bg-blue-50 px-7 py-6">Go ' . $icon . '</button></a>';
}
$page .= '</section></main></body></html>';
return array('entrypoint' => 'index.html', 'files' => array('index.html' => $page, 'assets/site.css' => $css));

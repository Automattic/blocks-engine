<?php
/** Identical styled chrome, unrelated route content and stylesheets. */
$header = '<header><a href="index.html">Example Studio</a><a class="cta" href="contact.html">Contact us</a></header>';
$files = array('site.css' => '.cta{background-color:#123456;color:#ffffff;border-radius:8px;padding:12px 24px}.cta:hover{opacity:.8}@media(max-width:700px){.cta{opacity:.9}}');
foreach (array('index', 'about', 'contact') as $index => $slug) {
    $files[$slug . '.html'] = '<!doctype html><html><head><link rel="stylesheet" href="site.css"><link rel="stylesheet" href="' . $slug . '.css"></head><body>'
        . $header . '<main><h1>' . ucfirst($slug) . '</h1><p class="route-copy">Content for ' . $slug . '</p></main></body></html>';
    $files[$slug . '.css'] = '.route-copy{color:rgb(' . (30 + $index) . ',40,50)}';
}
return array('entrypoint' => 'index.html', 'files' => $files);

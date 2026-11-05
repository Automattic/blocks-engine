<?php
// A missing family must not veto the other dominant, preset-backed fields.
$css = 'body{font-family:sans-serif;font-size:16px}.btn{display:inline-flex;padding:7.5px 15px;border-radius:8px;font-size:16px;background-color:#cacaca;color:#5b5b5b;text-decoration:none}.family{font-family:Calibri,sans-serif}.spacing-sample{padding:7.5px 15px}';
$files = array();
foreach (array('index.html', 'a.html', 'b.html') as $route) {
    $files[$route] = '<!doctype html><html><head><style>' . $css . '</style></head><body><p>Root family text</p><p class="spacing-sample">Spacing scale sample</p><a class="btn family" href="/a">One</a><a class="btn family" href="/b">Two</a><a class="btn" href="/c">Three</a></body></html>';
}
return array('entrypoint' => 'index.html', 'files' => $files);

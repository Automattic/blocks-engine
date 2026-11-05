<?php
// Issue #4392: inherited family, variable-backed logical padding and hover colour.
$css = 'body{font-family:Georgia,serif}.btn{--edge:15px;display:inline-flex;padding-block:7.5px;padding-inline:var(--edge);font-size:16px;font-weight:700;border-radius:8px;background-color:#cacaca;color:#5b5b5b;text-decoration:none}.btn:hover{color:#000}';
$files = array();
foreach (array('index.html', 'a.html', 'b.html') as $route) {
    $files[$route] = '<!doctype html><html><head><style>' . $css . '</style></head><body><a class="btn" href="/a">One</a> <a class="btn" href="/b">Two</a> <a class="btn" href="/c">Three</a></body></html>';
}
return array('entrypoint' => 'index.html', 'files' => $files);

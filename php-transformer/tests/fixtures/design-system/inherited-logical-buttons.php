<?php
// Issue #4392 / PR #4422: Jimdo's inherited typography and relative hover colour.
$css = 'body{font-family:Georgia,serif;font-size:16px}@media(max-width:600px){body{font-size:14px}}.btn{--edge:15px;--jw-button-font-size:calc(16px*.9375);display:inline-flex;padding-block:7.5px;padding-inline:var(--edge);font-size:var(--jw-button-font-size);border-radius:8px;background-color:#cacaca;color:#5b5b5b!important;text-decoration:none}.btn:hover{--hover-bg:oklch(from #cacaca calc(l - .04) c h);background-color:var(--hover-bg);color:oklch(from #646464 calc(l + .1) c h)}';
$files = array();
foreach (array('index.html', 'a.html', 'b.html') as $route) {
    $files[$route] = '<!doctype html><html><head><style>' . $css . '</style></head><body><a class="btn" href="/a">One</a> <a class="btn" href="/b">Two</a> <a class="btn" href="/c">Three</a></body></html>';
}
return array('entrypoint' => 'index.html', 'files' => $files);

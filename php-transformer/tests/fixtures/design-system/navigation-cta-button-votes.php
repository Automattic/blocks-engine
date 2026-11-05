<?php
// Repeated header CTAs render as navigation links, not element buttons.
$css = 'body{font-family:Georgia,serif}.btn{display:inline-flex;padding:7.5px 15px;border-radius:8px;font-size:16px;background-color:#cacaca;color:#5b5b5b;text-decoration:none}nav .btn{padding:13px}.control{padding:5px}';
$files = array();
foreach (array('index.html', 'a.html', 'b.html') as $route) {
    $files[$route] = '<!doctype html><html><head><style>' . $css . '</style></head><body><header><nav><ul><li><a href="/">Home</a></li><li><a class="btn" href="/appointment">Maak een afspraak!</a></li></ul></nav></header><main><p>Content buttons</p><a class="btn" href="/a">One</a><a class="btn" href="/b">Two</a><a class="btn" href="/c">Three</a><form><button class="btn control" type="submit">Submit</button><button class="btn control" type="reset">Reset</button></form></main></body></html>';
}
return array('entrypoint' => 'index.html', 'files' => $files);

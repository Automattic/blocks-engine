<?php
// Shared button system across three routes, with an outline and a smaller CTA.
$css = ':root{--orange:#e2622a}.btn{font-family:Oswald,sans-serif;font-size:.92rem;font-weight:600;text-transform:uppercase;letter-spacing:.07em;border-radius:9px;padding:13px 22px;display:inline-block}.btn-primary{background:var(--orange);color:#fff}.btn-ghost{border:1px solid #fff;color:#fff}.hero-cta{font-size:.84rem;padding:9px 16px}';
$files = array();
foreach (array('index.html', 'about.html', 'contact.html') as $route) {
    $body = '<nav><a href="index.html">Home</a><a href="about.html">About</a><a href="contact.html">Contact</a></nav>';
    foreach (array('btn-primary', 'btn-primary', 'btn-primary', 'btn-ghost', 'btn-ghost', 'btn-primary hero-cta') as $kind) $body .= '<a href="#" class="btn ' . $kind . '">Continue</a>';
    $files[$route] = '<!doctype html><html><head><style>' . $css . '</style></head><body>' . $body . '</body></html>';
}
return array('entrypoint' => 'index.html', 'files' => $files);

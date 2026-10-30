<?php
// Reduced fixed bottom CTA bar (blocks-engine#3621): the bar carries its own
// paint and responsive visibility, and wraps one button-like link whose own
// classes supply the control paint.
$css = '.bar{position:fixed;bottom:0;left:0;width:100%;padding:16px;background:rgba(248,245,241,.9)}'
    . '@media (min-width:640px){.bar{display:none}}'
    . '.btn{display:inline-flex;align-items:center;border-radius:6px;background:#3a6e9e;color:#fff;padding:12px 24px}'
    . '.primary{background:#3a6e9e}';
$page = '<!doctype html><html><head><title>Book a call</title><link rel="stylesheet" href="site.css"></head>'
    . '<body><main><div class="bar"><a href="#top" class="btn primary" style="width:100%;justify-content:center">Book a call</a></div></main></body></html>';
return array('entrypoint' => 'index.html', 'files' => array('index.html' => $page, 'site.css' => $css));

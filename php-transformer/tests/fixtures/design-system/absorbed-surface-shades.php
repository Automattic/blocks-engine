<?php
declare(strict_types=1);

// The reduced reproduction of issue #3043: a white card on a `#f6f8fa`
// surface, and two plain surface sections. `#f6f8fa` sits within ΔE 5 of
// `#ffffff`, so the palette folds it into the white `base` token
// (`absorbs: ['#f6f8fa']`). The surfaces must bind to `base` rather than keep
// a literal background that a Global Styles palette change never reaches.
$css = 'body{margin:0}'
    . '.bg-white{background-color:#ffffff}'
    . '.bg-surface{background-color:#f6f8fa;padding:200px 20px}';

return array(
    'entrypoint' => 'index.html',
    'files' => array(
        'assets/site.css' => $css,
        'index.html' => '<!doctype html><html><head><title>Surfaces</title><link rel="stylesheet" href="assets/site.css"></head><body>'
            . '<main><section class="bg-surface"><div class="bg-white"><h3>Card</h3><p>Text.</p></div></section>'
            . '<section class="bg-surface"><h3>Plain</h3><p>Surface text.</p></section>'
            . '<section class="bg-surface"><h3>Plain</h3><p>Surface text.</p></section></main>'
            . '</body></html>',
    ),
);

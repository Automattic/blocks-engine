<?php
declare(strict_types=1);

// Two routes share the late-loaded capture stylesheet, just as a captured
// WordPress site does. Its old palette must not own the new theme's presets.
$css = ':root{--wp--preset--color--accent-2:#F6CFF4;--wp--preset--font-size--large:31px;--wp--preset--spacing--40:19px;--wp--preset--font-family--body:Georgia}'
    . 'body{margin:0;background:#172554;color:#eeeeee;font-family:Arial,sans-serif}'
    . 'h1{color:#ff9900}.intro{color:#22ddaa}.card{background-color:rgb(255,255,255);color:#111111;padding:24px}'
    . '.source-only:hover{outline-color:var(--wp--preset--color--accent-2)}';
$page = static fn(string $title): string => '<!doctype html><html><head><title>' . $title . '</title><link rel="stylesheet" href="assets/capture-global.css"></head><body><main>'
    . '<h1>' . $title . '</h1><p class="intro">An independent community newsletter.</p>'
    . '<p style="color:#111111">Local reporting, every week.</p><div class="card"><p>Latest news from the neighbourhood.</p></div>'
    . '<p><a href="other.html">Read the next edition</a></p></main></body></html>';
return array('entrypoint' => 'index.html', 'files' => array(
    'assets/capture-global.css' => $css,
    'index.html' => $page('Community news'),
    'other.html' => $page('Another edition'),
));

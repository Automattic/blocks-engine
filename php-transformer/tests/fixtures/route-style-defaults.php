<?php
declare(strict_types=1);

// Independent route designs, with a genuinely shared foreground and a
// responsive override that must retain its source cascade in WordPress.
return array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="shared.css"><link rel="stylesheet" href="home.css"></head><body><main><h1>Home</h1><p>Home paragraph</p></main></body></html>',
        'gallery/index.html' => '<!doctype html><html><head><link rel="stylesheet" href="../shared.css"><link rel="stylesheet" href="gallery.css"></head><body><main><h1>Gallery</h1><p>Gallery paragraph</p></main></body></html>',
        'journal/index.html' => '<!doctype html><html><head><link rel="stylesheet" href="../shared.css"><link rel="stylesheet" href="journal.css"></head><body><main><h1>Journal</h1><p>Journal paragraph</p></main></body></html>',
        'shared.css' => 'body{color:#123456}h1{letter-spacing:1px}',
        'home.css' => 'body{font-family:Arial,sans-serif;background-color:#f0f0f0}h1{font-family:Arial,sans-serif;font-weight:300;font-size:40px}@media(max-width:600px){h1{font-size:24px}}',
        'gallery/gallery.css' => 'body{font-family:Verdana,sans-serif;background-color:#333333}h1{font-family:Verdana,sans-serif;font-weight:500;font-size:32px}',
        'journal/journal.css' => 'body{font-family:Georgia,serif;background-color:#cccccc}h1{font-family:Georgia,serif;font-weight:700;font-size:36px}',
    ),
);

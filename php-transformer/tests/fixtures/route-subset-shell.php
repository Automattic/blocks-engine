<?php
// Two pages and two repeated post chrome populations: the post edge variants
// reserve header while the pages still need their common non-landmark header.
// Unique page utility text prevents the page edge itself becoming a variant.
$header = '<div class="widget widget-nav"><img src="logo.svg" alt="Shared brand" width="40" height="40"><nav><ul><li><a href="/">Home</a></li><li><a href="/about.html">About</a></li></ul></nav><a href="/contact.html">Contact us</a></div>';
$files = array();
foreach (array('index', 'about') as $name) {
    $files[$name . '.html'] = '<html><head><title>' . $name . '</title><style>.widget-nav{height:80px;display:flex;align-items:center;gap:24px}main{min-height:200px}</style></head><body><p>' . $name . ' utility</p><div class="page-frame">' . $header . '<main><h1>' . $name . '</h1><p>Page content.</p></main><p>Page end.</p></div></body></html>';
}
foreach (array('first', 'second', 'third', 'fourth') as $name) {
    $files['posts/' . $name . '.html'] = '<html><head><title>' . $name . '</title><meta property="article:published_time" content="2026-01-01T00:00:00Z"><style>.widget-nav{height:80px;display:flex;align-items:center;gap:24px}main{min-height:200px}</style></head><body><div class="post-frame"><div class="post-chrome"><p>' . (in_array($name, array('first', 'second'), true) ? 'Post utility bar' : 'Other post bar') . '</p>' . $header . '</div><main><article><h1>' . $name . '</h1><p>Post content.</p></article></main><p>Post end.</p></div></body></html>';
}
$files['logo.svg'] = '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" fill="blue"/></svg>';
return array('entrypoint' => 'index.html', 'files' => $files);

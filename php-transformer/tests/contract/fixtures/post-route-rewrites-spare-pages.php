<?php
declare(strict_types=1);

$files = array('index.html' => '<main><h1>Home</h1></main>');
foreach (array(
    '/north' => 'page',
    '/north/careers' => 'page',
    '/north/contact' => 'page',
    '/contact' => 'page',
    '/north/first-note' => 'post',
    '/north/second-note' => 'post',
    '/south' => 'page',
    '/south/careers' => 'page',
    '/south/contact' => 'page',
    '/south/third-note' => 'post',
    '/south/first-note' => 'post',
    '/edition-v1' => 'page',
    '/edition-v1/release-v2' => 'post',
) as $route => $type) {
    $files[] = array(
        'path' => trim($route, '/') . '/index.html',
        'content' => '<main><h1>' . basename($route) . '</h1><p>Source content.</p></main>',
        'metadata' => array('route_path' => $route, 'post_type' => $type),
    );
}
return array('entrypoint' => 'index.html', 'files' => $files);

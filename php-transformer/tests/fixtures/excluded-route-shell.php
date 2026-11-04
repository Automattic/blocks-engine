<?php
declare(strict_types=1);

$document = static fn(string $header, string $body): string => '<!doctype html><html><head><title>Field Notes</title></head><body>'
    . '<p>Field journal</p><!--#include virtual="/parts/header-' . str_replace(' ', '-', $header) . '.html" -->'
    . '<main id="content">' . $body . '</main><div class="closing-region"><footer><p>Published by Field Notes</p></footer><aside><p>From the reading shelf</p></aside></div></body></html>';
$headers = array();
foreach (array('Catalog desk', 'Article desk') as $label) $headers['parts/header-' . str_replace(' ', '-', $label) . '.html'] = '<header><p>' . $label . '</p><nav><a href="/">Home</a><a href="/catalog/">Catalog</a></nav></header>';
$files = array('index.html' => $document('Catalog desk', '<h1>Welcome</h1>'));
foreach (array('catalog/index.html', 'catalog/index-query-1.html', 'catalog/index-query-2.html', 'catalog/index-query-3.html') as $path) {
    $files[$path] = $document('Catalog desk', '<h1>Catalog</h1><p>Browse the collection.</p>');
}
for ($i = 1; $i <= 6; ++$i) {
    $files['articles/note-' . $i . '.html'] = $document('Article desk', '<article><h1>Note ' . $i . '</h1><p>A distinct field observation ' . $i . '.</p></article>');
}
return array('entrypoint' => 'index.html', 'files' => $files + $headers);

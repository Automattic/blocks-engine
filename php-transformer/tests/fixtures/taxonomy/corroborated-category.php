<?php
declare(strict_types=1);

return array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<header><p>Shared header</p></header><main><h1>Home</h1></main><footer><p>Shared footer</p></footer>',
    array('path' => 'archives/field-notes.html', 'content' => '<header><p>Shared header</p></header><main><h1>Field Notes</h1><article><h2><a href="/stories/first">First story</a></h2><p>First summary.</p></article><article><h2><a href="/stories/second">Second story</a></h2><p>Second summary.</p></article></main><footer><p>Shared footer</p></footer>', 'metadata' => array('route_path' => '/journal/category/field-notes')),
    array('path' => 'stories/first.html', 'content' => '<header><p>Shared header</p></header><article><h1>First story</h1><p>Full first story.</p><a href="/journal/category/field-notes">Field Notes</a></article><footer><p>Shared footer</p></footer>', 'metadata' => array('post_type' => 'post')),
    array('path' => 'stories/second.html', 'content' => '<header><p>Shared header</p></header><article><h1>Second story</h1><p>Full second story.</p><a href="/journal/category/field-notes">Field Notes</a></article><footer><p>Shared footer</p></footer>', 'metadata' => array('post_type' => 'post')),
));

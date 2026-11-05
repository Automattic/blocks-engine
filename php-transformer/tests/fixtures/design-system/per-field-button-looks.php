<?php
// Equal-weight looks differ only in radius: neither radius owns a majority.
$fixture = require __DIR__ . '/inherited-logical-buttons.php';
foreach ($fixture['files'] as &$html) {
    $html = str_replace('</style>', '.pill{border-radius:999px}</style>', $html);
    $html = str_replace('<a class="btn" href="/b">Two</a> <a class="btn" href="/c">Three</a>', '<a class="btn pill" href="/b">Two</a> <a class="btn" href="/c">Three</a> <a class="btn pill" href="/d">Four</a>', $html);
}
unset($html);
return $fixture;

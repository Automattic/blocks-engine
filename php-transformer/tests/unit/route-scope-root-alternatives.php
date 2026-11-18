<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Route scoping forks a class-led arm into a descendant arm and a root
// alternative, in case the arm's leading compound is the document root
// itself. A compound no root of the site can match only adds theme weight;
// one a captured root carries must keep its alternative.
$failures = 0;
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) { ++$failures; fwrite(STDERR, "FAIL: {$message}\n"); }
};
$files = array(array('path' => 'site.css', 'kind' => 'css', 'content' => '.card img{border:1px solid red}.js .card img{outline:2px solid blue}'));
$files[] = array('path' => 'index.html', 'kind' => 'html', 'content' => '<!doctype html><html class="js"><head><link rel="stylesheet" href="site.css"></head><body><main><div class="card"><h2>A</h2></div></main></body></html>');
$files[] = array('path' => 'other.html', 'kind' => 'html', 'content' => '<!doctype html><html class="js"><head><link rel="stylesheet" href="site.css"></head><body><main><div class="card"><p>B</p><img src="b.png" alt="b" width="10" height="10"></div></main></body></html>');
$result = (new ArtifactCompiler())->compile(array('files' => $files))->toArray();
$css = (string) (array_column($result['assets'], 'content', 'path')['site.css'] ?? '');

$assert(1 === preg_match('/:where\(:root:is\(\.be-r-[0-9a-f]+\)\) \.card :where\(figure\)/', $css), 'fixture scopes the image arm to the route that renders it');
$assert(!str_contains($css, '.card:where(:root'), 'a class no document root carries gets no root alternative');
$assert(1 === preg_match('/\.js:where\(:root:is\(\.be-r-[0-9a-f]+\)\) \.card /', $css), 'a class the captured root carries keeps its root alternative');

if ($failures) exit(1);
echo "Route scoping forks root alternatives only for compounds a document root can match\n";

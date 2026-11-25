<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Route scoping forks a class-led arm into a root alternative only when a
// document root can carry its leading compound. The engine puts role and
// subject classes (`blocks-engine-role-richtext`, `blocks-engine-subject…`)
// on the block carriers it compiles, never on `<html>`: an arm led by them
// gets no root alternative unless a captured root names them. Each such
// alternative matched nothing, yet shipped once per gated arm.
$failures = 0;
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) { ++$failures; fwrite(STDERR, "FAIL: {$message}\n"); }
};
$compile = static function (): string {
    $files = array(array('path' => 'site.css', 'kind' => 'css', 'content' => '.hl{color:#c00}.card .hl{font-weight:700}'));
    $files[] = array('path' => 'index.html', 'kind' => 'html', 'content' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><div class="card"><h2>A</h2></div></main></body></html>');
    $files[] = array('path' => 'other.html', 'kind' => 'html', 'content' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><div class="card"><p>Item <span class="hl">run</span> tail.</p></div></main></body></html>');
    $result = (new ArtifactCompiler())->compile(array('files' => $files))->toArray();
    return (string) (array_column($result['assets'], 'content', 'path')['site.css'] ?? '');
};

$css = $compile();
$assert(1 === preg_match('/:where\(:root:is\(\.be-r-[0-9a-f]+\)\) :is\([^{]*:where\(\.blocks-engine-role-richtext\.blocks-engine-subject\.blocks-engine-subject-class-[0-9a-f]+\)/', $css), 'fixture scopes the RichText subject arm to the route that renders it: ' . $css);
$assert(0 === preg_match('/blocks-engine-subject-class-[0-9a-f]+\)(?::not\([^()]*\))*:where\(:root/', $css), 'a role- and subject-led compound gets no root alternative: ' . $css);

if ($failures) exit(1);
echo "Route scoping forks no root alternative for compounds led by block carrier hooks\n";
